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
# Requisições em paralelo, como a produção faz. Ver o bloco que sobe a API. (D-212)
# ⚠️ TRÊS, e não seis: cada worker é um processo PHP, e esta máquina tem 4 GB SEM SWAP dividida com
# o MariaDB de produção. Três já eliminam a fila (o login dispara dez requisições, mas curtas) sem
# disputar memória com o Chromium — que é quem o kernel mata primeiro (`oom_score_adj:300`).
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-3}"
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

/*
 * A2.9: um item ÚNICO já descoberto, para a biografia do §11.1 ter o que mostrar.
 *
 * ⚠️ Sem isto, "identidade persistente e histórico" ficaria sem cobertura de ponta a ponta — e
 * identidade que ninguém enxerga não é identidade.
 */
$unico = App\Models\EnduranceItem::create([
    "item_key" => "nucleo_da_endurance", "secao" => "comando",
    "nome" => "Núcleo da Endurance", "tipo" => App\Models\EnduranceItem::UNICO,
    "quantidade_total" => 1, "quantidade_vendida" => 1,
    "preco_micro" => 5000000, "vendavel_em_leilao" => true,
    "descricao" => "O coração da nave. Só existe um.",
]);
app(App\Domain\Endurance\Instancias::class)->descobrir($c, $unico);

/*
 * A2.8: um evento de mundo ATIVO, para a faixa ter o que mostrar.
 *
 * ⚠️ Sem isto o motor ficaria sem cobertura de ponta a ponta — e um motor que muda a economia sem
 * que ninguém veja é indistinguível de um defeito.
 */
App\Models\GameEvent::create([
    "slug" => "tempestade_de_poeira",
    "nome" => "Tempestade de poeira",
    "mensagem_publica" => "O céu fechou sobre o setor norte.",
    "comeca_em" => now()->subHour(),
    "termina_em" => now()->addDay(),
    "status" => "ativo",
    "visibilidade" => "anunciado",
    "escopo" => "mundo",
    "modificador" => "producao",
    "efeito_bps" => -2000,
]);

/*
 * A2.6: a população LIGADA no mundo do e2e, e a colônia povoada.
 *
 * Sem isto o painel de operadores da zona não renderiza (`operadores.ativo` é false) e a fase
 * inteira fica sem cobertura de ponta a ponta — que é exatamente como publiquei uma rota sem tela no
 * D-180. O `grandfather` é o mesmo comando que rodou em produção, então o e2e também o exercita.
 *
 * ⚠️ Energia já saiu da cesta de consumo (migration `energia_fora_da_cesta_da_populacao`); sem isso,
 * a colônia do e2e entraria em escassez e a produção que outras suítes conferem cairia pela metade.
 */
DB::table("population_settings")->where("id", 1)->update(["ativo" => true]);

/*
 * ⚠️ Folga habitacional ANTES do grandfathering, e a razão é uma medida.
 *
 * O grandfathering concede o que a colônia precisa mais a folga do §6.7 — mas a folga é limitada
 * pelo teto da Estrutura de Sobrevivência. Com ela no nível 1, a colônia nasce EXATAMENTE no que
 * precisa, com zero colonos livres, e não consegue ocupar zona nenhuma.
 *
 * Isso não é defeito: medido contra a produção, 9 das 29 colônias reais podem ocupar zona nova e as
 * outras precisam subir a habitação primeiro — que é o incentivo que a população deveria criar. Mas
 * a suíte de Zonas existe para testar OCUPAÇÃO, então a colônia dela representa quem já se preparou
 * para expandir.
 */
$c->buildings()->where("type", "estrutura_de_sobrevivencia")->update(["level" => 4]);

Artisan::call("fertways:populacao-grandfather", ["--aplicar" => true]);

/*
 * E a colônia do e2e CRESCEU até o teto habitacional dela.
 *
 * ⚠️ O grandfathering sozinho não basta, e o número é instrutivo: a folga do §6.7 é 20% do que a
 * colônia precisa, e sobre uma colônia pequena isso dá UM colono sobrando — enquanto uma zona nova
 * pede dois. É a mesma razão pela qual apenas 9 das 29 colônias de produção conseguem expandir
 * território hoje.
 *
 * A colônia do e2e representa quem já jogou algum tempo, e população cresce: ela está no teto.
 */
$c->update(["populacao" => app(App\Domain\Populacao\Populacao::class)->capacidade($c->fresh(["buildings"]))]);

/*
 * A2.5: duas federações, para a mesa diplomática ter com quem tratar.
 *
 * ⚠️ CADA COLÔNIA NA SUA, e isso é deliberado. Se as duas ficassem na mesma, o desconto de tributo
 * entre filiadas (D-120, 50%) passaria a incidir nas entregas que outras suítes conferem — e elas
 * reprovariam sem que nada do que afirmam tivesse mudado. Foi exatamente o acoplamento que a suíte
 * do Mercado sofreu quando a da Capital passou a subir o nível de um veículo (D-180).
 *
 * Sem aliança firmada entre as duas: a aliança é o que o e2e vai propor.
 */
foreach ([[$c, "Pacto do Norte", "PN", $u], [$cv, "Liga do Sul", "LS", $v]] as [$colonia, $nome, $tag, $dono]) {
    $f = App\Models\Federation::create(["name" => $nome, "tag" => $tag]);
    $colonia->forceFill([
        "federation_id" => $f->id,
        "federation_role" => App\Models\Federation::LIDER,
    ])->save();

    /*
     * A2.10: o fundo abastecido, para a mesa de guerra ter o que mostrar e o botão poder existir.
     * Sem Fert$ e sem Nióbio, declarar seria recusado e o e2e testaria a recusa em vez da fase.
     */
    $cfg = App\Models\WarSetting::singleton();
    $f->update(["fert_micro" => (int) $cfg->federativa_custo_fert_micro * 5]);
    DB::table("federation_holdings")->insert([
        "federation_id" => $f->id, "resource_type" => "niobio_alienigena",
        "amount" => (int) $cfg->federativa_custo_niobio * 5,
        "created_at" => now(), "updated_at" => now(),
    ]);
}

/*
 * A2.10: as duas federações em guerra, e um Quartel na colônia do e2e.
 *
 * ⚠️ Depois do bloco das federações, e não antes: a guerra precisa das duas existindo. Escrevi-o
 * acima na primeira tentativa e o semeador inteiro quebrou — o e2e reprovou já no primeiro login.
 *
 * Sem isto a seção de colônias inimigas não renderiza, e o cerco de colônia — que REVOGA o §01 —
 * ficaria sem cobertura de ponta a ponta. Foi assim que publiquei rota sem tela no D-180.
 */
$c->buildings()->create(["type" => "quartel", "level" => 2, "slot" => 14]);
App\Models\FederationWar::create([
    "declarante_id" => $c->fresh()->federation_id,
    "alvo_id" => $cv->fresh()->federation_id,
    "comeca_em" => now()->subHour(),
    "termina_em" => now()->addDays(7),
    "status" => "ativa",
]);

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

/*
 * A2.0.3, "Desde sua última visita". O marcador vai para 5 h atrás para que o resumo APAREÇA no
 * primeiro login — é o que `resumo.e2e.mjs` precisa provar.
 *
 * E é por isso que ele roda PRIMEIRO na ordem lá embaixo: ao clicar em "Continuar" o marcador
 * avança para agora, e o piso de uma hora do §5.1 silencia o modal para todas as suítes seguintes.
 * Sem essa ordem, um popup `fixed inset-0` ficaria interceptando os cliques de todo o resto.
 *
 * Uma produção datada dentro da janela, para o resumo ter conteúdo além do saldo da fundação.
 */
$u->forceFill(["resumo_visto_em" => now()->subHours(5)])->save();
App\Models\Ledger::create([
    "colony_id" => $c->id, "type" => "producao", "amount" => 250,
    "resource_type" => "metal_bruto", "created_at" => now()->subHours(2),
]);

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

# ⚠️ **A API do e2e atendia UMA requisição por vez**, e foi a causa raiz da instabilidade da suíte
# (D-212). O próprio `artisan serve` avisava no log e ninguém lia:
#
#     WARN Unable to respect the `PHP_CLI_SERVER_WORKERS` environment variable
#          without the `--no-reload` flag. Only creating a single server.
#
# O jogo dispara **dez requisições** logo após o login (/colony, /queue, /buildings, /buildings/
# catalogo, /images, /eventos, /resumo, /zones/minhas, /chat/pendencias, /avisos). Servidas em fila,
# cada uma esperava a anterior — os tempos no log eram todos ~500 ms, que é a assinatura disso — e a
# suíte reprovava no login, mudando de lugar a cada corrida conforme o tempo caísse de um lado ou do
# outro do prazo. Parecia flake de máquina; era serialização.
#
# `--no-reload` destrava os workers (não há por que vigiar arquivo: o bundle e o código são fixos
# durante a corrida). Seis workers, que é o que a produção serve com php-fpm.
echo "==> subindo a API em :$PORTA_API (com $PHP_CLI_SERVER_WORKERS workers)"
PHP_CLI_SERVER_WORKERS="$PHP_CLI_SERVER_WORKERS" $PHP artisan serve --port="$PORTA_API" --no-reload >/tmp/e2e-api.log 2>&1 &
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

cd "$RAIZ/frontend"

# ⚠️ `E2E_SO_FOTOS=1` sobe a pilha, força os estados e vai direto às fotos, sem rodar suíte nenhuma.
#
# A A2.V é uma fase inteiramente VISUAL, e nela a foto é o instrumento — mas ela só saía depois de
# todas as suítes passarem, e a suíte ainda é instável (D-212: quatro corridas para uma verde, cada
# uma falhando em ponto diferente). Na prática isso tornava "fotografe e olhe" caro justamente na
# fase que mais depende de olhar. Isto NÃO substitui a suíte: é o atalho para conferir desenho.
if [ "${E2E_SO_FOTOS:-}" = "1" ]; then
  echo "==> pulando as suítes (E2E_SO_FOTOS=1): só as fotos"
  E2E_FOTOS=1
else
echo "==> rodando os testes"

# Nesta ordem, e não noutra: compartilham o mesmo banco efêmero. O de Mapa e Frota vem primeiro
# porque espera os três furgões ociosos, no pátio; o do Mercado deixa dois em rota, e o do Acordo
# despacha o terceiro. O da Fundação vem por último: funda uma quinta colônia, que mudaria as
# contagens de colônias das telas anteriores.
# PRIMEIRO, e a ordem é obrigatória: o resumo é um popup `fixed inset-0` que apareceria por cima
# de tudo. Ele se dispensa ao ser fechado (o marcador avança e o piso de uma hora entra em vigor),
# e é isso que deixa o caminho livre para as suítes seguintes.
E2E_URL="http://127.0.0.1:$PORTA_WEB" node e2e/resumo.e2e.mjs
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
fi

# As FOTOS (D-68). Não afirmam nada — só deixam as imagens em /tmp para alguém OLHAR.
#
# O e2e prova que CLICA, não que está certo na tela: as cenas de Phaser são canvas, e nenhum teste
# de clique ou de texto as alcança. Foi assim que os sete ministérios da Capital saíram pálidos com
# os sete e2e verdes (D-63). Quando mexer em cena, rode isto e olhe.
if [ "${E2E_FOTOS:-}" = "1" ]; then
  # ⚠️ Os dois estados da A2.V3 não acontecem sozinhos num mundo recém-semeado, e um selo que
  # ninguém consegue fotografar é um selo que ninguém confere. Forçamos os dois AQUI — depois de
  # todas as suítes e só quando as fotos foram pedidas —, porque isto suja o mundo de propósito:
  # um recurso no teto e uma obra em curso quebrariam testes de mercado e de fila se viessem antes.
  echo "==> forçando os estados da colmeia para a foto (A2.V3)"
  (cd "$RAIZ/backend" && $PHP artisan tinker --execute='
$c = App\Models\Colony::where("name", "Colônia e2e")->firstOrFail();

// TRAVADA: enche a Biomassa até o teto. A Fazenda continua de pé e não rende nada (§14).
$teto = app(App\Domain\Colony\TetoDoEstoque::class);
Illuminate\Support\Facades\DB::table("estoque_settings")->where("id", 1)->update(["ativo" => true]);
$c->resources()->where("resource_type", "biomassa")
  ->update(["amount" => max(1, (int) $teto->capacidade($c, "biomassa"))]);

// MELHORANDO: um relógio numa construção já erguida — o estado que era idêntico a estar parado.
$c->buildings()->where("type", "captacao_de_agua")
  ->update(["upgrade_finish_at" => now()->addHours(3)]);

echo "biomassa no teto, e a Captação de Água subindo de nível\n";
' 2>&1 | tail -2)

  E2E_URL="http://127.0.0.1:$PORTA_WEB" node e2e/foto.mjs || true
fi
