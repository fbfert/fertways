#!/usr/bin/env bash
#
# Sobe uma pilha efêmera e roda o teste de ponta a ponta da tela do Mercado.
#
#   backend  → artisan serve em :8199, contra um SQLite temporário
#   frontend → vite dev em :5199, que já faz proxy de /central para :8199
#
# Nunca toca produção nem o MariaDB: o teste põe ordens no livro e mexe em saldo.
# O SQLite vive em /tmp e morre com o script.
#
#   ./tools/e2e.sh
#
set -euo pipefail

RAIZ="$(cd "$(dirname "$0")/.." && pwd)"
PHP=/usr/bin/php84
export PATH="/usr/local/lib/nodejs/node-v22.12.0-linux-x64/bin:$PATH"

BANCO="$(mktemp -t fertways-e2e-XXXXXX.sqlite)"
PORTA_API=8199
PORTA_WEB=5199

# `env()` do Laravel não sobrescreve variável já exportada, então isto vence o .env.
export DB_CONNECTION=sqlite
export DB_DATABASE="$BANCO"
export APP_ENV=local

pids=()
limpar() {
  for p in "${pids[@]:-}"; do kill "$p" 2>/dev/null || true; done
  rm -f "$BANCO"
}
trap limpar EXIT

echo "==> banco efêmero em $BANCO"
cd "$RAIZ/backend"
$PHP artisan migrate:fresh --seed --force >/dev/null

echo "==> semeando o colono do e2e"
$PHP artisan tinker --execute='
$u = App\Models\User::create([
    "name" => "E2E", "nickname" => "e2e",
    "email" => "e2e@fertways.test",
    "password" => Illuminate\Support\Facades\Hash::make("segredo-forte-123"),
]);
$c = app(App\Domain\Colony\CreateColony::class)->handle($u, "Colônia e2e");

// Perto da Capital: o despacho tem de caber em poucos minutos e em pouca energia.
$c->forceFill(["x" => 48, "y" => 50])->save();
$c->resources()->where("resource_type", "metal_bruto")->update(["amount" => 1000]);
$c->resources()->where("resource_type", "energia")->update(["amount" => 1000]);

// Carga já na doca: sem ela não há o que vender, e esperar uma viagem real levaria minutos.
App\Models\MarketAccount::create([
    "colony_id" => $c->id, "resource_type" => "metal_bruto", "amount" => 500,
]);
echo "colono e2e pronto na colônia {$c->id}\n";
' | tail -1

echo "==> subindo a API em :$PORTA_API"
$PHP artisan serve --port="$PORTA_API" >/tmp/e2e-api.log 2>&1 &
pids+=($!)

echo "==> subindo o front em :$PORTA_WEB"
cd "$RAIZ/frontend"
npm run dev -- --port "$PORTA_WEB" --strictPort >/tmp/e2e-web.log 2>&1 &
pids+=($!)

echo "==> esperando os dois responderem"
for i in $(seq 60); do
  api=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORTA_API/up" || true)
  web=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$PORTA_WEB/" || true)
  [ "$api" = "200" ] && [ "$web" = "200" ] && break
  sleep 0.5
done

if [ "${api:-}" != "200" ] || [ "${web:-}" != "200" ]; then
  echo "a pilha não subiu (api=$api web=$web). Logs:"
  tail -20 /tmp/e2e-api.log /tmp/e2e-web.log
  exit 1
fi

echo "==> rodando o teste"
cd "$RAIZ/frontend"
E2E_URL="http://127.0.0.1:$PORTA_WEB" node e2e/mercado.e2e.mjs
