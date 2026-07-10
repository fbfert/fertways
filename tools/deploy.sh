#!/usr/bin/env bash
#
# Publica o que está commitado em `main` na cópia de deploy.
#
#   trabalho → /home/fertways/apps/fertways        (onde se edita; NÃO é servido)
#   deploy   → /home/fertways/deploy/fertways      (o que o Apache serve)
#   Apache   → public_html/central -> deploy/fertways/backend/public
#
# O deploy é explícito: editar código na árvore de trabalho não publica mais nada.
#
#   ./tools/deploy.sh                # backend + frontend
#   ./tools/deploy.sh --so-backend
#   ./tools/deploy.sh --so-frontend
#
# Rode como root (ele desce para `fertways` sozinho, para não deixar arquivo root-owned
# em storage/ e bootstrap/cache/).
#
set -euo pipefail

RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
DEPLOY=/home/fertways/deploy/fertways
PUBLICO=/home/fertways/public_html
PHP=/usr/bin/php84
FPM=php84-php-fpm.service
COMO=(sudo -u fertways -H)

so_backend=0
so_frontend=0
case "${1:-}" in
  --so-backend)  so_backend=1 ;;
  --so-frontend) so_frontend=1 ;;
  "") ;;
  *) echo "uso: $0 [--so-backend|--so-frontend]" >&2; exit 2 ;;
esac

# ---------------------------------------------------------------- guardas

# O symlink tem mesmo que apontar para a cópia de deploy. Se alguém o devolveu para a árvore de
# trabalho, publicar aqui não teria efeito nenhum e o script mentiria dizendo que publicou.
alvo=$(readlink -f "$PUBLICO/central" || true)
if [ "$alvo" != "$DEPLOY/backend/public" ]; then
  echo "ABORTADO: $PUBLICO/central aponta para [$alvo]," >&2
  echo "e não para [$DEPLOY/backend/public]. Ver docs/deploy.md." >&2
  exit 1
fi

if [ -n "$(git -C "$DEPLOY" status --porcelain)" ]; then
  echo "ABORTADO: a cópia de deploy tem alterações locais. Ela deve ser descartável." >&2
  git -C "$DEPLOY" status --short >&2
  exit 1
fi

# ---------------------------------------------------------------- backend

if [ "$so_frontend" -eq 0 ]; then
  echo "==> backend"

  # `git pull` publica na hora: o Apache serve direto de deploy/backend/public, sem build
  # intermediário. Se o código novo depende de uma migration que ainda não rodou, o intervalo
  # entre os dois passos é uma janela de 500 para quem estiver jogando. `down` fecha a porta.
  #
  # O tick do cron é pulado enquanto a aplicação está em manutenção, e isso é inofensivo: o tick
  # avança o mundo por delta de tempo, então o minuto perdido é recuperado no tick seguinte.
  "${COMO[@]}" "$PHP" "$DEPLOY/backend/artisan" down --retry=15
  restaurar() { "${COMO[@]}" "$PHP" "$DEPLOY/backend/artisan" up || true; }
  trap 'echo "FALHOU — tirando da manutenção" >&2; restaurar' ERR

  "${COMO[@]}" git -C "$DEPLOY" pull --ff-only origin main
  echo "==> deploy agora em $(git -C "$DEPLOY" log --oneline -1)"

  cd "$DEPLOY/backend"
  "${COMO[@]}" "$PHP" /bin/composer install --no-dev --optimize-autoloader --no-interaction

  # A migration antes de tirar da manutenção, nunca depois.
  "${COMO[@]}" "$PHP" artisan migrate --force

  # `config:cache` sim. `route:cache` NÃO: quebra a raiz da API montada em subcaminho (D-26).
  "${COMO[@]}" "$PHP" artisan config:cache

  debug=$("${COMO[@]}" "$PHP" artisan tinker --execute='echo config("app.debug") ? "on" : "off";' 2>/dev/null | tail -1)
  if [ "$debug" != "off" ]; then
    echo "ABORTADO: APP_DEBUG está [$debug] em produção. A página de erro do Laravel publica" >&2
    echo "o .env inteiro, DB_PASSWORD junto. Corrija o .env e rode de novo." >&2
    exit 1
  fi
  echo "==> APP_DEBUG conferido: off"

  # Sem isto o deploy não publica nada. `opcache.revalidate_path=0` faz o opcache resolver o
  # caminho do symlink uma única vez e guardar o destino para sempre: os workers do php-fpm
  # continuam executando a árvore para onde `public_html/central` apontava quando subiram, mesmo
  # depois de o symlink mudar. O reload derruba os workers e o cache junto.
  #
  # O master do php84 é compartilhado com os outros domínios do servidor; `reload` é gracioso.
  systemctl reload "$FPM"

  trap - ERR
  "${COMO[@]}" "$PHP" artisan up

  # O que o Apache está executando é mesmo esta árvore? A fumaça lá embaixo (200/401) não
  # responde: ela passa igual servindo a árvore de trabalho. O opcache do pool responde.
  "${COMO[@]}" curl -s -o /dev/null https://fertways.tars.art.br/central/   # aquece o index.php
  sonda="$DEPLOY/backend/public/__deploy_opcache_check.php"
  "${COMO[@]}" tee "$sonda" >/dev/null <<'PHP'
<?php
$s = opcache_get_status(true)['scripts'] ?? [];
$intrusos = preg_grep('#^/home/fertways/apps/#', array_keys($s));
$idx = '/home/fertways/deploy/fertways/backend/public/index.php';
echo isset($s[$idx]) ? "index=deploy\n" : "index=AUSENTE\n";
echo "intrusos=".count($intrusos)."\n";
foreach (array_slice($intrusos, 0, 5) as $i) echo "  $i\n";
PHP
  veredito=$(curl -s https://fertways.tars.art.br/central/__deploy_opcache_check.php || true)
  rm -f "$sonda"
  if ! grep -q '^index=deploy$' <<<"$veredito" || ! grep -q '^intrusos=0$' <<<"$veredito"; then
    echo "ABORTADO: o Apache não está executando a cópia de deploy." >&2
    echo "$veredito" >&2
    exit 1
  fi
  echo "==> opcache conferido: a produção executa $DEPLOY"
fi

# ---------------------------------------------------------------- frontend

if [ "$so_backend" -eq 0 ]; then
  echo "==> frontend"
  export PATH="/usr/local/lib/nodejs/node-v22.12.0-linux-x64/bin:$PATH"
  cd "$DEPLOY/frontend"
  "${COMO[@]}" env PATH="$PATH" npm ci
  "${COMO[@]}" env PATH="$PATH" npm run build

  # `cp` é alias de `cp -i` para o root: sem -f ele trava num prompt, não copia nada, e sai com
  # status 0. O deploy "dá certo" e o bundle antigo continua no ar.
  /bin/cp -rf dist/. "$PUBLICO/"
  chown -R fertways:fertways "$PUBLICO"

  # O bundle servido é mesmo o que acabou de ser compilado?
  local_js=$(basename "$(ls -t "$DEPLOY/frontend/dist/assets/"index-*.js | head -1)")
  servido=$(curl -s https://fertways.tars.art.br/ | grep -oE 'index-[A-Za-z0-9_-]+\.js' | head -1)
  if [ "$local_js" != "$servido" ]; then
    echo "ABORTADO: o bundle no ar é [$servido], e o recém-compilado é [$local_js]." >&2
    echo "A cópia não pegou. Ver docs/deploy.md." >&2
    exit 1
  fi
  echo "==> bundle conferido no ar: $servido"
fi

# ---------------------------------------------------------------- fumaça

echo "==> fumaça"
front=$(curl -s -o /dev/null -w '%{http_code}' https://fertways.tars.art.br/)
colony=$(curl -s -o /dev/null -w '%{http_code}' https://fertways.tars.art.br/central/colony)
echo "    front  $front   (espera 200)"
echo "    colony $colony   (espera 401)"
[ "$front" = "200" ] && [ "$colony" = "401" ] || { echo "ABORTADO: fumaça falhou." >&2; exit 1; }

echo "==> publicado."
