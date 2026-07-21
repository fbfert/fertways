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

# A arte das construções (D-68). Sem isto, a pilha efêmera desenharia HEXÁGONOS e o e2e passaria em
# verde sobre uma colônia sem arte nenhuma — o falso-verde do D-63, de novo. Os arquivos vivem em
# /home/fertways/media (fora da árvore); este comando só os registra e vincula.
$PHP artisan fertways:importar-imagens --aplicar >/dev/null 2>&1 || true

echo "==> semeando o colono do e2e"
$PHP artisan tinker --execute='
$u = App\Models\User::create([
    "name" => "E2E", "nickname" => "e2e",
    "email" => "e2e@fertways.test",
    "password" => Illuminate\Support\Facades\Hash::make("segredo-forte-123"),
]);
// Slot de founder (0,3): perto da Capital (0,0), a 3 slots — o despacho cabe em poucos minutos
// e em pouca energia. O colono escolhe a célula (D-51); aqui o seeder escolhe por ele.
$c = app(App\Domain\Colony\CreateColony::class)->handle($u, "Colônia e2e", 0, 3);

$c->resources()->where("resource_type", "metal_bruto")->update(["amount" => 3000]);
$c->resources()->where("resource_type", "energia")->update(["amount" => 1000]);
// O bastante para o teste de zonas ocupar uma (Posto + 20 Robôs Mineradores, §07/D-52).
$c->resources()->where("resource_type", "ligas_metalicas")->update(["amount" => 2000]);
$c->resources()->where("resource_type", "componentes_eletronicos")->update(["amount" => 1000]);
$c->update(["fert_micro" => 1000 * 1000000]);

/*
 * Desbravador (D-75): o teste de zonas OCUPA uma zona, e ocupar exige o marco 20 (20.000 XP na
 * curva 50xN2). forceFill porque xp nao e fillable de proposito - so o ConcederXp escreve no jogo.
 */
$c->forceFill(["xp" => 20000])->save();

/*
 * D-59: as duas PORTAS. A colônia nasce só com o miolo (as 5 essenciais), e desde o D-59 a Frota
 * vive dentro da Central de Transportes e os Acordos dentro do Mercado Local. Sem estas duas
 * erguidas, as duas telas não teriam por onde ser abertas — e os testes delas não teriam como
 * clicar em lugar nenhum. Slots 0 e 1: os dois primeiros de fora do miolo (que ocupa 5, 9, 10,
 * 11 e 15). O e2e clica neles por posição; ver `clicarNoSlot` em e2e/comum.mjs.
 */
/*
 * A Central vem no nível 5, e não no 1, por causa do D-60: o nível dela virou o TETO de veículos
 * do colono (máximo(1, nível)). Este colono tem três furgões — no nível 1 ele estaria acima do
 * teto e não poderia comprar caminhão nenhum, e o e2e da compra não teria como existir.
 */
$c->buildings()->create(["type" => "central_de_transportes", "level" => 5, "slot" => 0]);
$c->buildings()->create(["type" => "mercado_local", "level" => 1, "slot" => 1]);

// Carga já na doca: sem ela não há o que vender, e esperar uma viagem real levaria minutos.
App\Models\MarketAccount::create([
    "colony_id" => $c->id, "resource_type" => "metal_bruto", "amount" => 500,
]);

/*
 * Um item da Loja de Peças da Endurance (D-135/D-138), na seção "comando" — a primeira que
 * `EnduranceMapa.tsx` desenha, logo `destrocos[0]` no e2e. Sem isto, o catálogo nasce vazio (o
 * admin é quem cria itens, e o e2e não passa pelo painel), e o fluxo de compra não teria o que
 * clicar — o mesmo esquecimento que a suíte antiga (D-132/D-133, 32 linhas fixas) nunca precisou
 * evitar.
 */
$itemEndurance = App\Models\EnduranceItem::create([
    "item_key" => "reator_de_teste_e2e", "secao" => "comando", "nome" => "Reator de Teste",
    "tipo" => "comum", "quantidade_total" => 10, "quantidade_vendida" => 0,
    "preco_micro" => 10 * 1000000, "marco_minimo" => null, "vendavel_em_leilao" => false,
]);
$itemEndurance->efeitos()->create([
    "tipo_efeito" => "desconto_tributo", "alvo" => null, "valor_bps" => 500,
]);

// Mais dois furgões. A colônia nasce com um só; o teste do Mercado deixa dois em rota (Capital e
// vizinha), e o do Acordo precisa de um terceiro ocioso para despachar a entrega.
foreach ([1, 2] as $ignorado) {
    $furgao = $c->vehicles()->create([
        "type" => "furgao_de_comercio", "level" => 1, "status" => "ocioso",
        "capacity" => App\Models\Vehicle::CAPACIDADE["furgao_de_comercio"],
    ]);
    // §16.3: todo veículo civil é registrado. Estes nascem fora do domínio, então a placa vem à
    // mão — senão a tela do Ministério os mostraria sem registro, que é um estado que o jogo real
    // não produz.
    app(App\Domain\Transport\Placas::class)->registrar($furgao);
}

/*
 * D-60: a prateleira do Ministério dos Transportes. Quem a repõe é o tick, e o e2e não roda tick —
 * então o governo já entra com dois caminhões prontos. É o estado que um colono encontra ao chegar
 * à Capital num servidor que anda.
 */
foreach ([1, 2] as $ignorado) {
    $caminhao = App\Models\Vehicle::create([
        "colony_id" => null, "type" => "caminhao_de_carga", "level" => 1, "status" => "estoque",
        "capacity" => App\Models\Vehicle::CAPACIDADE["caminhao_de_carga"],
    ]);
    app(App\Domain\Transport\Placas::class)->registrar($caminhao);
}

/*
 * D-60, fatia 2: um dos furgões já rodou. Sem desgaste não há o que reparar, e o teste da
 * manutenção não teria em que clicar — o botão nasce desabilitado para veículo no teto. 62% é um
 * número qualquer, escolhido só por ser visivelmente gasto e ainda bem acima do piso de 25%.
 */
$c->vehicles()->where("type", "furgao_de_comercio")->orderBy("id")->first()
  ->forceFill(["conservacao_bps" => 6200, "uso_ativo_seg" => 76 * 3600])->save();

// Uma vizinha, para o diretório de colônias ter o que listar. Em (0,6): a 3 slots de (0,3).
$v = App\Models\User::create([
    "name" => "Vizinha", "nickname" => "vizinha",
    "email" => "vizinha@fertways.test",
    "password" => Illuminate\Support\Facades\Hash::make("segredo-forte-123"),
]);
$cv = app(App\Domain\Colony\CreateColony::class)->handle($v, "Colônia vizinha", 0, 6);

// D-81: uma fala da vizinha no Global, para o e2e do Chat ter um nick alheio em que clicar.
// ChatMessage não tem timestamps automáticos ($timestamps = false) — created_at é à mão.
App\Models\ChatMessage::create([
    "user_id" => $v->id, "channel" => "global", "body" => "Alguém compra Água?", "created_at" => now(),
]);

/*
 * D-58. Uma oferta da **vizinha** na vitrine das Ofertas Globais, e outra no mural entre colonos.
 * São elas que provam o que o D-58 veio consertar: sem uma oferta alheia, o e2e só veria as suas
 * próprias, e a queixa original ("não vejo as ofertas dos outros") continuaria sem teste.
 *
 * Vender exige o lote já no depósito da Capital — daí a conta dela nascer com Água.
 */
App\Models\MarketAccount::create([
    "colony_id" => $cv->id, "resource_type" => "agua", "amount" => 300,
]);
app(App\Domain\Market\ColocarOrdem::class)->handle($cv, "sell", "agua", 300, 10000);

// Oferta aberta, sem contraparte: o primeiro que aceitar leva. Prazo largo, para caber na viagem
// de qualquer colônia (o D-42 é cobrado de quem aceita, não de quem anuncia).
app(App\Domain\Trade\ProporAcordo::class)->handle(
    $cv, null, ["biomassa" => 50], ["metal_bruto" => 50], now()->addDays(3),
);

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

/*
 * Um acordo já quebrado entre os dois: é a evidência mínima que o §26.8 exige para denunciar. Sem
 * ele, a aba de denunciar não tem o que anexar e o botão nunca habilita — que é, aliás, a regra.
 */
$quebrado = app(App\Domain\Trade\ProporAcordo::class)->handle(
    $c, $cv, ["metal_bruto" => 20], ["agua" => 20], now()->addDays(2),
);
$quebrado->forceFill(["status" => "quebrado"])->save();

// O colono do e2e é conciliador: é o cargo que faz aparecer a aba "A julgar" (§9.3).
$u->forceFill(["conciliador_desde" => now()])->save();

// E também Repórter (§14.2, D-130): é o cargo que faz aparecer o formulário de "Publicar matéria"
// na Central de Notícias.
app(App\Domain\Cargos\GerirCargoCivico::class)->nomear($u, App\Domain\Cargos\CargosCivicosSpecs::REPORTER);

/*
 * Duas colônias distantes, e um caso entre elas para o e2e julgar. **Distantes de propósito**: o
 * diretório ordena por distância, e o teste do Acordo propõe para a primeira da lista.
 *
 * O caso é entre elas, e não com a vizinha, por causa do impedimento do §26.8: o e2e tem um Acordo
 * recente com a vizinha, e um conciliador não julga quem negociou com ele nos últimos 30 dias.
 */
$colonias = [];
foreach ([["ré", 10, 10], ["autora", 11, 10]] as [$nick, $x, $y]) {
    $usuario = App\Models\User::create([
        "name" => $nick, "nickname" => $nick,
        "email" => "{$nick}@fertways.test",
        "password" => Illuminate\Support\Facades\Hash::make("segredo-forte-123"),
    ]);
    $colonias[$nick] = app(App\Domain\Colony\CreateColony::class)->handle($usuario, "Colônia {$nick}", $x, $y);
}

$entreElas = app(App\Domain\Trade\ProporAcordo::class)->handle(
    $colonias["autora"], $colonias["ré"], ["metal_bruto" => 30], ["agua" => 30], now()->addDays(2),
);
$entreElas->forceFill(["status" => "quebrado"])->save();

app(App\Domain\Ministry\AbrirDenuncia::class)->handle(
    $colonias["autora"], $colonias["ré"], "calote_reincidente",
    "Aceitou o acordo, deixou o prazo vencer e não mandou nada.",
    "acordo_expirado", $entreElas->id,
);

// Um comunicado no mural da Central de Notícias (slot 3), para o e2e da Capital ter o que ler.
App\Models\News::create([
    "title" => "Servidor aberto", "body" => "Bem-vindos a Fertways, colonos.",
    "kind" => "comunicado", "author" => "Administração Pública", "published_at" => now(),
]);

echo "colono e2e pronto na colônia {$c->id}\n";
' | tail -1

echo "==> subindo a API em :$PORTA_API"
$PHP artisan serve --port="$PORTA_API" >/tmp/e2e-api.log 2>&1 &
pids+=($!)

# ⚠️ **`build` + `preview`, e não `vite dev`** (D-70). O servidor tem 4 GB, e o `dev` guarda o grafo
# de módulos inteiro em memória — com o Phaser dentro. Junto do Chrome do puppeteer, isso estourava
# a RAM e o kernel matava o e2e: `exit 137`, que NÃO é teste reprovado, e é fácil ler como se fosse.
#
# O build é pesado (~360 MB) mas roda **sozinho** e morre; o preview serve o `dist/` estático por uns
# 50 MB e convive com o navegador. De quebra, o e2e passa a exercitar o **bundle que de fato vai ao
# ar**, e não o servidor de desenvolvimento.
echo "==> construindo o bundle"
cd "$RAIZ/frontend"
npm run build >/tmp/e2e-build.log 2>&1 || { echo "o build falhou:"; tail -20 /tmp/e2e-build.log; exit 1; }

echo "==> servindo o front em :$PORTA_WEB"
npm run preview -- --port "$PORTA_WEB" --strictPort >/tmp/e2e-web.log 2>&1 &
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

# Nesta ordem, e não noutra: compartilham o mesmo banco efêmero. O de Mapa e Frota vem primeiro
# porque espera os três furgões ociosos, no pátio; o do Mercado deixa dois em rota, e o do Acordo
# despacha o terceiro. O da Fundação vem por último: funda uma quinta colônia, que mudaria as
# contagens de colônias das telas anteriores.
E2E_URL="http://127.0.0.1:$PORTA_WEB" node e2e/telas.e2e.mjs
# O Chat não mexe em veículo nem em recurso — só em mensagens — e por isso cabe em qualquer ponto
# da ordem. Fica aqui, cedo, por não depender de nada que as telas seguintes ainda vão montar.
E2E_URL="http://127.0.0.1:$PORTA_WEB" node e2e/chat.e2e.mjs
# A barra mobile (reforma mobile-first do HUD). Roda num browser PRÓPRIO a 390×844 — não interfere
# no viewport 1400×900 do resto da suíte — e termina saindo da conta; como cada arquivo faz seu
# próprio login, isso não afeta os que vêm depois.
E2E_URL="http://127.0.0.1:$PORTA_WEB" node e2e/mobile.e2e.mjs
# A Capital SUBIU na ordem no D-60, e não por gosto: a tela do Ministério dos Transportes precisa de
# um veículo **no pátio** para reparar e sucatear, e o botão de manutenção só existe para veículo
# ocioso. Depois do Mercado e do Acordo os três furgões estão em rota, e não haveria em que clicar.
E2E_URL="http://127.0.0.1:$PORTA_WEB" node e2e/capital.e2e.mjs
E2E_URL="http://127.0.0.1:$PORTA_WEB" node e2e/mercado.e2e.mjs
E2E_URL="http://127.0.0.1:$PORTA_WEB" node e2e/acordos.e2e.mjs
E2E_URL="http://127.0.0.1:$PORTA_WEB" node e2e/ministerio.e2e.mjs
# Zonas neutras: ocupa uma zona (só gasta recursos), depois das telas que dependem do estado da
# colônia do e2e e antes da Fundação.
E2E_URL="http://127.0.0.1:$PORTA_WEB" node e2e/zonas.e2e.mjs
# Por último: funda uma quinta colônia pelo seletor do D-51, o que mexeria nas contagens acima.
E2E_URL="http://127.0.0.1:$PORTA_WEB" node e2e/fundacao.e2e.mjs

# As FOTOS (D-68). Não afirmam nada — só deixam as imagens em /tmp para alguém OLHAR.
#
# O e2e prova que CLICA, não que está certo na tela: as cenas de Phaser são canvas, e nenhum teste
# de clique ou de texto as alcança. Foi assim que os sete ministérios da Capital saíram pálidos com
# os sete e2e verdes (D-63). Quando mexer em cena, rode isto e olhe.
if [ "${E2E_FOTOS:-}" = "1" ]; then
  E2E_URL="http://127.0.0.1:$PORTA_WEB" node e2e/foto.mjs || true
fi
