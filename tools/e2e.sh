#!/usr/bin/env bash
#
# Sobe uma pilha efêmera e roda os testes de ponta a ponta das telas (Mercado e Acordo de Troca).
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

# ...mas NÃO vence `bootstrap/cache/config.php`. Com a config cacheada, `env()` é ignorada por
# completo e `database.default` continua `mysql`. Foi assim que uma versão anterior deste script
# rodou `migrate:fresh` contra o MariaDB de produção e apagou o banco do jogo. O `phpunit.xml`
# já se protegia disto desde o D-27; este script não. Apontar o cache para um arquivo inexistente
# é o mesmo remédio.
export APP_CONFIG_CACHE="$RAIZ/backend/bootstrap/cache/nao-existe-config.php"

# Cinto e suspensório: pergunta ao próprio Laravel qual conexão ele resolveu, e aborta se não for
# o SQLite temporário. Um teste jamais deve poder destruir dados de produção.
efetiva=$(cd "$RAIZ/backend" && $PHP artisan tinker --execute='echo config("database.default")." ".config("database.connections.sqlite.database");' 2>/dev/null | tail -1)
if [ "$efetiva" != "sqlite $BANCO" ]; then
  echo "ABORTADO: o Laravel resolveu [$efetiva], e não [sqlite $BANCO]." >&2
  echo "Rodar migrate:fresh assim apagaria o banco de produção. Ver docs/decisoes.md D-27." >&2
  exit 1
fi
echo "==> conexão efetiva conferida: $efetiva"

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

// Mais dois furgões. A colônia nasce com um só; o teste do Mercado deixa dois em rota (Capital e
// vizinha), e o do Acordo precisa de um terceiro ocioso para despachar a entrega.
foreach ([1, 2] as $ignorado) {
    $c->vehicles()->create([
        "type" => "furgao_de_comercio", "level" => 1, "status" => "ocioso",
        "capacity" => App\Models\Vehicle::CAPACIDADE["furgao_de_comercio"],
    ]);
}

// Uma vizinha, para o diretório de colônias ter o que listar. A 3 slots de (48,50).
$v = App\Models\User::create([
    "name" => "Vizinha", "nickname" => "vizinha",
    "email" => "vizinha@fertways.test",
    "password" => Illuminate\Support\Facades\Hash::make("segredo-forte-123"),
]);
$cv = app(App\Domain\Colony\CreateColony::class)->handle($v, "Colônia vizinha");
$cv->forceFill(["x" => 45, "y" => 50])->save();

/*
 * Um acordo já proposto **pela vizinha**, para o colono do e2e ter o que aceitar: só a contraparte
 * fecha o aperto de mão (§26.5), então um acordo proposto por ele mesmo não exercitaria o botão.
 *
 * Ele deve 100 de Metal Bruto — tributo de 3%, logo 103 embarcados —, e os dois lados somam
 * 3,95 Fert$: abaixo do piso de 500 do §26.3, o acordo registra mas não move reputação (D-43).
 */
app(App\Domain\Trade\ProporAcordo::class)->handle(
    $cv, $c, ["agua" => 100], ["metal_bruto" => 100], now()->addDays(2),
);

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

echo "==> rodando os testes"
cd "$RAIZ/frontend"

# Nesta ordem, e não noutra: os dois compartilham o mesmo banco efêmero, e o do Mercado deixa dois
# furgões em rota. O do Acordo despacha o terceiro.
E2E_URL="http://127.0.0.1:$PORTA_WEB" node e2e/mercado.e2e.mjs
E2E_URL="http://127.0.0.1:$PORTA_WEB" node e2e/acordos.e2e.mjs
