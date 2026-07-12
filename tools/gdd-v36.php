#!/usr/bin/env php
<?php

/**
 * Gera o FERTWAYS GDD v36 (D-62).
 *
 *     /usr/bin/php84 tools/gdd-v36.php > ../../FERTWAYS_GDD_v36_CONSOLIDADO.html
 *
 * ---
 *
 * **Por que isto é um gerador, e não um arquivo escrito à mão.**
 *
 * O v35 era um documento estático, e por isso **envelheceu**: o jogo mudou 59 vezes e o texto não.
 * As tabelas numéricas deste v36 não são digitadas — são **lidas do mesmo banco de onde o jogo lê**
 * (`building_specs`, `resource_types`), e essas tabelas têm **testes que provam que batem com o GDD**
 * (`tests/Gdd/GddSpecsTest`, `tests/Gdd/LogisticaSpecsTest`).
 *
 * Consequência: **o documento não pode divergir do jogo**. Se um número mudar no código, ele muda
 * aqui na próxima geração. Foi a única maneira que encontrei de escrever um GDD que não vira mentira
 * na semana seguinte — que é exatamente o que aconteceu com o v35.
 *
 * A prosa, as regras e as arbitragens são curadas à mão, aqui neste arquivo. Os números, não.
 *
 * **Rode-o com o banco de DEV** (`fertwaysdev`). Ele só lê, mas não há razão para apontar um gerador
 * de documento para a produção.
 */

require __DIR__.'/../backend/vendor/autoload.php';
$app = require __DIR__.'/../backend/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\Building\Funcoes;
use App\Domain\Colony\Slots;
use App\Domain\Logistics\MapaFertways;
use App\Domain\Logistics\VeiculoSpecs;
use App\Domain\Market\Deposito;
use App\Domain\Trade\AcordoSpecs;
use App\Domain\Transport\Ministerio;
use App\Models\Building;
use App\Models\Colony;
use App\Models\TransportSetting;
use Illuminate\Support\Facades\DB;

// ─────────────────────────────────────────────────────────────── helpers de marcação

/** O que o jogo ENTREGA hoje. */
function entregue(string $t = 'Implementado'): string
{
    return '<span class="sel sel-ok">'.e($t).'</span>';
}

/** O que o GDD PROMETE e o jogo ainda não faz. */
function promessa(string $t = 'Promessa'): string
{
    return '<span class="sel sel-prom">'.e($t).'</span>';
}

/** Número que o GDD NUNCA publicou e que ninguém arbitrou. **Não se inventa.** */
function lacuna(string $t = 'Lacuna aberta'): string
{
    return '<span class="sel sel-lac">'.e($t).'</span>';
}

/** Número que o GDD não publica e que o usuário decidiu. */
function arbitrado(string $d): string
{
    return '<span class="sel sel-arb">Arbitrado · '.e($d).'</span>';
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function n(int|float $v, int $casas = 0): string
{
    return number_format($v, $casas, ',', '.');
}

/**
 * O nome de exibição.
 *
 * ⚠️ **A lista vive em `App\Domain\Media\NomesDeExibicao`, e não mais aqui.** Ela passou a ser usada
 * também pelo painel de gestão de imagens (D-68), que lista as construções para o operador escolher a
 * arte de cada uma. Duas cópias divergiriam no dia em que alguém corrigisse só uma — e um GDD que
 * escreve "Refinaria quimica" e um painel que escreve "Refinaria Química" seriam dois jogos.
 *
 * A guarda abaixo continua valendo, e agora protege as duas pontas.
 */
function nomesProprios(): array
{
    return App\Domain\Media\NomesDeExibicao::mapa();
}

function humano(string $slug): string
{
    return nomesProprios()[$slug] ?? ucfirst(str_replace('_', ' ', $slug));
}

// ─────────────────────────────────────────────────────────────── dados, do mesmo banco do jogo

$recursos = DB::table('resource_types')->orderBy('tax_class')->orderBy('nome')->get();
$specs = DB::table('building_specs')->orderBy('building_type')->orderBy('level')->get();
$config = TransportSetting::singleton();

/*
 * Falha ALTO se uma construção nova não tiver nome próprio no mapa acima.
 *
 * Sem esta guarda, o `humano()` cai no fallback e o GDD sai com o nome do prédio escrito errado — sem
 * acento, sem maiúscula — e ninguém percebe até alguém ler. Um documento que erra o nome das coisas
 * que descreve não serve. É melhor o gerador parar.
 */
$faltando = array_diff(Building::MVP, array_keys(nomesProprios()));

if ($faltando !== []) {
    fwrite(STDERR, "ERRO: estas construções não têm nome próprio no mapa `nomesProprios()`:\n  - "
        .implode("\n  - ", $faltando)."\n\n"
        ."O GDD sairia com o nome delas escrito errado (sem acento, do slug). Acrescente-as e rode de novo.\n");
    exit(1);
}

$porTipo = [];
foreach ($specs as $s) {
    $porTipo[$s->building_type][$s->level] = $s;
}

ob_start();
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>FERTWAYS — GDD v36 (consolidado)</title>
<style>
  :root{--rust:#b4450b;--rust-bright:#cd5512;--ember:#eaae65;--sand:#f8e7d6;--sand-light:#fdf0e2;
        --ink:#1e1c17;--ink-soft:#4a4038;--ok:#2f5e1e;--prom:#8a6a00;--lac:#8a2f08;--arb:#1e4a6e;}
  *{box-sizing:border-box}
  body{margin:0;background:var(--sand);color:var(--ink);
       font-family:Inter,"Segoe UI",system-ui,sans-serif;font-size:15px;line-height:1.65}
  header.capa{background:var(--ink);color:var(--sand-light);padding:56px 24px}
  header.capa .env{max-width:900px;margin:0 auto}
  header.capa small{color:var(--ember);letter-spacing:.22em;text-transform:uppercase;font-size:.66rem;font-weight:700}
  header.capa h1{font-size:2.6rem;margin:.2em 0 .1em;letter-spacing:-.01em}
  header.capa p{color:rgba(253,240,226,.75);max-width:640px}
  main{max-width:900px;margin:0 auto;padding:24px}
  h2{color:var(--rust);border-bottom:2px solid rgba(180,69,11,.25);padding-bottom:8px;margin-top:52px}
  h3{margin-top:32px;color:var(--ink)}
  h4{margin-top:22px;color:var(--ink-soft);font-size:1rem}
  code{background:rgba(180,69,11,.09);padding:1px 5px;font-size:.88em}
  table{width:100%;border-collapse:collapse;margin:14px 0;font-size:.86rem}
  th,td{text-align:left;padding:6px 9px;border-bottom:1px solid rgba(180,69,11,.14);vertical-align:top}
  th{color:var(--ink-soft);text-transform:uppercase;letter-spacing:.08em;font-size:.62rem;background:rgba(180,69,11,.05)}
  td.num,th.num{text-align:right;font-variant-numeric:tabular-nums}
  .sel{display:inline-block;padding:1px 8px;border-radius:999px;font-size:.64rem;font-weight:700;
       text-transform:uppercase;letter-spacing:.06em;white-space:nowrap}
  .sel-ok{background:#dff0d8;color:var(--ok)}
  .sel-prom{background:#fbf0cf;color:var(--prom)}
  .sel-lac{background:#f4d9d0;color:var(--lac)}
  .sel-arb{background:#d8e8f4;color:var(--arb)}
  .nota{border-left:3px solid var(--rust);background:var(--sand-light);padding:12px 16px;margin:16px 0}
  .nota.grave{border-color:var(--lac);background:#f9e6e0}
  .nota b{color:var(--rust)}
  .legenda{display:grid;gap:8px;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));margin:18px 0}
  .legenda div{background:var(--sand-light);border:1px solid rgba(180,69,11,.18);padding:10px}
  nav.indice{background:var(--sand-light);border:1px solid rgba(180,69,11,.2);padding:16px 22px;margin:24px 0}
  nav.indice ol{margin:0;padding-left:20px;columns:2;column-gap:32px}
  nav.indice a{color:var(--rust);text-decoration:none}
  nav.indice a:hover{text-decoration:underline}
  footer{background:var(--ink);color:rgba(253,240,226,.6);padding:32px 24px;margin-top:64px;font-size:.8rem}
  footer .env{max-width:900px;margin:0 auto}
  .d{font-size:.7rem;color:var(--ink-soft);font-weight:700}
  @media(max-width:640px){nav.indice ol{columns:1}}
</style>
</head>
<body>

<header class="capa">
  <div class="env">
    <small>Game Design Document · versão 36 · consolidada</small>
    <h1>FERTWAYS</h1>
    <p>
      A base de produção do jogo. Este documento <b>substitui o v35</b> e, ao contrário dele,
      <b>não se contradiz</b>.
    </p>
    <p style="font-size:.85rem;color:rgba(253,240,226,.55);margin-top:20px">
      Gerado em <?= date('d/m/Y') ?> · As tabelas numéricas são lidas do mesmo banco de onde o jogo lê.
    </p>
  </div>
</header>

<main>

<h2 id="s0">0. O que este documento é, e por que ele existe</h2>

<p>
  O <b>v35</b> era uma <b>pilha de versões</b>. A Parte I era a v3.2 "sanitizada"; a Parte II era a
  v3.0 inteira, por baixo; e uma <b>tabela de precedência</b> na seção 0 existia para que o
  <i>leitor</i> resolvesse as contradições que o próprio documento carregava. Ler o v35 corretamente
  exigia uma regra de leitura — nós tivemos de escrevê-la (D-47) —, e mesmo assim ele mentia: o §28.5
  prometia caminhões de graça que a seção 0 já havia revogado, e quem lesse só o §28.5 acreditaria.
</p>

<p>
  Das <b>62 decisões</b> que este projeto registrou até hoje, <b>metade existe porque o GDD se
  contradiz ou é omisso</b>. Este v36 resolve isso de raiz:
</p>

<ul>
  <li><b>As contradições são resolvidas no texto.</b> Não há tabela de precedência, porque não há
      duas redações concorrentes. Onde o v35 dizia duas coisas, aqui há uma — e uma nota dizendo qual
      foi descartada e por quê.</li>
  <li><b>As lacunas são marcadas como lacunas.</b> Onde o GDD nunca publicou um número, este
      documento <b>não inventa um</b>: escreve <?= lacuna() ?> e segue. É a regra de ouro do projeto
      aplicada ao próprio documento — e faz do v36, de quebra, a lista de tudo o que ainda falta
      decidir.</li>
  <li><b>O que o jogo entrega é separado do que ele promete.</b> Sete construções o GDD descreve com
      uma frase bonita e nunca quantifica. Um documento que as apresentasse como funcionalidades
      faria um jogador gastar 90 Ligas num prédio inerte.</li>
</ul>

<div class="nota">
  <b>As tabelas numéricas deste documento não foram digitadas.</b> Elas são lidas de
  <code>building_specs</code> e <code>resource_types</code> — as mesmas tabelas de onde o <i>jogo</i>
  lê —, e essas tabelas têm testes que provam que batem com o GDD
  (<code>tests/Gdd/GddSpecsTest</code>, <code>tests/Gdd/LogisticaSpecsTest</code>). Foi a única
  maneira que encontrei de escrever um GDD que não vira mentira na semana seguinte, que é exatamente
  o que aconteceu com o v35.
</div>

<h3>A legenda, e como ler este documento</h3>

<div class="legenda">
  <div><?= entregue() ?><p style="margin:6px 0 0;font-size:.84rem">Está no jogo, hoje. Você pode jogar isto.</p></div>
  <div><?= promessa() ?><p style="margin:6px 0 0;font-size:.84rem">O GDD descreve. O jogo ainda não faz.</p></div>
  <div><?= lacuna() ?><p style="margin:6px 0 0;font-size:.84rem">O GDD nunca publicou o número. <b>Ninguém o inventou.</b></p></div>
  <div><?= arbitrado('exemplo') ?><p style="margin:6px 0 0;font-size:.84rem">Não é do GDD: foi decidido por nós, e a decisão está registrada.</p></div>
</div>

<p>
  O <b><code>docs/decisoes.md</code></b> continua sendo o diário — o rastro de <i>como</i> se chegou
  a cada regra. Este documento é a <b>fonte única</b>: o que vale. As referências <span class="d">D-nn</span>
  apontam para lá.
</p>

<nav class="indice">
  <b>Índice</b>
  <ol>
    <li><a href="#s1">O mundo e a Capital</a></li>
    <li><a href="#s2">A colônia: os 21 slots</a></li>
    <li><a href="#s3">Recursos e economia</a></li>
    <li><a href="#s4">Construções</a></li>
    <li><a href="#s5">Logística e frota</a></li>
    <li><a href="#s6">O Mercado e o comércio</a></li>
    <li><a href="#s7">Reputação e o Ministério</a></li>
    <li><a href="#s8">Território e zonas neutras</a></li>
    <li><a href="#s9">Operação e administração</a></li>
    <li><a href="#s10">Tudo o que ainda falta decidir</a></li>
  </ol>
</nav>

<!-- ══════════════════════════════════════════════════════════════ 1 -->
<h2 id="s1">1. O mundo e a Capital</h2>

<h3>1.1 O planeta <span class="d">D-51</span></h3>

<p>
  O v35 <b>não definia o mapa</b> — nenhuma grade, nenhuma coordenada, nenhuma regra de distância
  além de "slots de mapa". Tudo aqui é arbitragem nossa, e é a fundação de que toda a logística
  depende.
</p>

<table>
  <tr><th>Elemento</th><th>Regra</th><th>Estado</th></tr>
  <tr><td>Grade</td><td><b><?= MapaFertways::LADO ?> × <?= MapaFertways::LADO ?></b>, coordenadas com sinal, de −<?= MapaFertways::RAIO ?> a +<?= MapaFertways::RAIO ?></td><td><?= entregue() ?></td></tr>
  <tr><td>Capital</td><td>No centro exato: <b>(<?= MapaFertways::CAPITAL_X ?>, <?= MapaFertways::CAPITAL_Y ?>)</b></td><td><?= entregue() ?></td></tr>
  <tr><td>Disco de founders</td><td>d ≤ <?= MapaFertways::RAIO_FOUNDER ?> — 48 células, 28 populáveis</td><td><?= entregue() ?></td></tr>
  <tr><td>Anel livre</td><td><?= MapaFertways::RAIO_FOUNDER ?> &lt; d ≤ <?= MapaFertways::RAIO_ANEL ?></td><td><?= entregue() ?></td></tr>
  <tr><td>Periferia</td><td>d &gt; <?= MapaFertways::RAIO_ANEL ?></td><td><?= entregue() ?></td></tr>
</table>

<div class="nota">
  <b>Duas distâncias, e não se deve unificá-las.</b> As <i>faixas</i> do mapa usam a distância
  euclidiana <b>exata</b>. O <i>frete e o tributo</i> usam a distância <b>arredondada half-up</b>
  (§25.6). São coisas diferentes de propósito: uma classifica o território, a outra cobra o
  transporte. <span class="d">D-51</span>
</div>

<h3>1.2 A Capital — os slots institucionais</h3>

<p>O §2.1 do v35 lista 20 slots. Este é o estado real de cada um:</p>

<table>
  <tr><th>#</th><th>Instituição</th><th>Função</th><th>Estado</th></tr>
  <tr><td>1</td><td>Administração Pública</td><td>Regras, comunicados, sanções finais</td><td><?= entregue('Painel de admin') ?></td></tr>
  <tr><td>2</td><td>Central de Tributos</td><td>Painel de taxas e o <b>caixa real</b> do Tesouro</td><td><?= entregue() ?> <span class="d">D-57</span></td></tr>
  <tr><td>3</td><td>Central de Pesquisas e Notícias</td><td>Mural de comunicados; Gagarin</td><td><?= entregue() ?></td></tr>
  <tr><td>4</td><td>Secretaria de Finanças</td><td>Preços de referência, intervenção de preço</td><td><?= entregue() ?> <span class="d">D-35</span></td></tr>
  <tr><td>5</td><td>Ministério da Segurança e Guerra</td><td>Conflitos, tratados, auditoria de combate</td><td><?= promessa('Fatia 2 do D-52') ?></td></tr>
  <tr><td>6</td><td>Pátio Logístico Público</td><td>Docas públicas — abriga o <b>Mercado Central</b></td><td><?= entregue() ?></td></tr>
  <tr><td>7</td><td>Ministério das Reputações</td><td>Denúncias, conciliação, recursos</td><td><?= entregue() ?></td></tr>
  <tr><td>8</td><td><b>Ministério dos Transportes</b></td><td>Fábrica de caminhões, registro de placas, oficina</td><td><?= entregue() ?> <span class="d">D-60</span></td></tr>
  <tr><td>9</td><td>Embaixada Interplanetária</td><td>—</td><td><?= promessa('Fora do MVP') ?></td></tr>
  <tr><td>10–20</td><td>Expansão controlada</td><td>—</td><td><?= promessa('Fora do MVP') ?></td></tr>
</table>

<div class="nota grave">
  <b>O slot 8 contraria o v35 de propósito.</b> O §2.1 o reservava para o <i>Quartel de Alianças</i>.
  O Ministério dos Transportes (§16) tinha uma seção inteira no GDD e <b>nenhum slot na Capital</b> —
  o documento lhe dava seis atribuições e nenhum endereço. Pusemos ele no 8. <span class="d">D-60</span>
</div>

<!-- ══════════════════════════════════════════════════════════════ 2 -->
<h2 id="s2">2. A colônia: os 21 slots</h2>

<div class="nota grave">
  <b>Isto não existe no v35.</b> Procuramos <code>slot</code>, <code>terreno</code>,
  <code>lote</code>, <code>grade</code>: no v35, "slot" é a <i>colônia vista do mapa do planeta</i>,
  nunca um espaço de construção. <b>O documento não põe teto espacial nenhum.</b> Os 21 slots são
  arbitragem nossa. <span class="d">D-59</span>
</div>

<p>
  A colônia é uma <b>colmeia de <?= Slots::TOTAL ?> slots</b>, em linhas de
  <?= implode('/', Slots::LINHAS) ?>. Toda construção tem <b>posição</b>, e
  <b>construção não erguida não ocupa slot</b> — ela passa a existir quando o colono aponta o buraco.
</p>

<pre style="background:var(--sand-light);padding:16px;border:1px solid rgba(180,69,11,.2);font-size:.9rem;line-height:1.7">
     ⬡ ⬡ ⬡ ⬡           0  1  2  3
    ⬡ ⬡ ⬡ ⬡            4  5  6  7
   ⬡ ⬡ ⬢ ⬡ ⬡           8  9 10 11 12
    ⬡ ⬡ ⬡ ⬡           13 14 15 16
     ⬡ ⬡ ⬡ ⬡          17 18 19 20
</pre>

<h3>2.1 O miolo — as cinco essenciais</h3>

<p>
  Nascem <b>prontas, no nível 1</b>, em posição fixa, na fundação. O nível 1 é lançado no ledger como
  <code>subsidio_governo</code>: emissão do Governo não pode ser invisível na contabilidade.
</p>

<table>
  <tr><th>Construção</th><th class="num">Slot</th><th>Observação</th></tr>
  <?php foreach (Slots::MIOLO as $tipo => $slot): ?>
  <tr><td><?= e(humano($tipo)) ?></td><td class="num"><?= $slot ?></td>
      <td><?= e(Funcoes::de($tipo)['frase']) ?></td></tr>
  <?php endforeach; ?>
</table>

<div class="nota">
  <b>Isto vai além do §24.7, que só subsidiava o custo.</b> O v35 dizia que o Governo custeia as cinco
  essenciais até o nível 3, mas que "o custo aparece normalmente na interface" — o que pressupõe que o
  nível 1 ainda seja <i>construído</i>. Aqui elas <b>nascem prontas</b>. O subsídio segue valendo do
  nível 2 ao 3. <span class="d">D-59, revisa o D-13</span>
</div>

<h3>2.2 Repetíveis e únicas</h3>

<p>
  Quatro construções podem ocupar <b>mais de um slot</b>, cada cópia com o seu nível:
  <b><?= e(implode(', ', array_map('humano', Building::REPETIVEIS))) ?></b>. São as produtoras:
  repetir é especializar a colônia, e produção e consumo de energia somam linearmente.
</p>

<div class="nota">
  <b>O freio não é o número de slots — é o Reator.</b> É o único teto que o v35 publica (§19.8: o
  Reator nível 1 sustenta as essenciais "permitindo 2-3 estruturas adicionais"). As essenciais ficam
  <b>únicas</b> de propósito: fossem repetíveis, o subsídio do §24.7 viraria torneira aberta e a
  enésima Fazenda sairia de graça.
</div>

<h3>2.3 Demolição <span class="d">D-59, D-61</span></h3>

<ul>
  <li><b>O investido não volta.</b> Nada é estornado. O <code>custo_construcao</code> lançado na obra
      continua no ledger — o registro honesto de um gasto que virou pó.</li>
  <li><b>As cinco essenciais são indemolíveis.</b> Derrubar o Gerador de Atmosfera exigiria decidir o
      que acontece a uma colônia sem atmosfera, e o GDD não tem resposta.</li>
  <li><b>Não se demole o que está em obra.</b></li>
  <li><b>O colono tem de escrever a palavra <code>DEMOLIR</code></b> — e a API a exige, não só a tela.</li>
</ul>

<p>O v35 <b>não fala em demolição</b>, nem na palavra nem no conceito. <?= arbitrado('tudo acima') ?></p>

<!-- ══════════════════════════════════════════════════════════════ 3 -->
<h2 id="s3">3. Recursos e economia</h2>

<h3>3.1 O catálogo</h3>

<p>
  <b><?= count($recursos) ?> recursos.</b> A <b>classe tributária</b> define a alíquota do §8.3, que
  incide <b>na entrega física</b> — e é <b>retida no próprio recurso</b>, não cobrada em Fert$
  <span class="d">D-12</span>.
</p>

<table>
  <tr><th>Recurso</th><th>Classe</th><th class="num">Alíquota</th><th class="num">Preço-base (F$)</th><th class="num">Produção máx./h</th></tr>
  <?php foreach ($recursos as $r): ?>
  <tr>
    <td><?= e($r->nome) ?><?= $r->preco_base_derivado ? ' <span class="d">derivado</span>' : '' ?></td>
    <td><?= e($r->tax_class) ?></td>
    <td class="num"><?= n($r->tax_bps / 100, 0) ?>%</td>
    <td class="num"><?= n($r->preco_base_micro / 1_000_000, 4) ?></td>
    <td class="num"><?= $r->producao_max_hora ?: '—' ?></td>
  </tr>
  <?php endforeach; ?>
</table>

<div class="nota grave">
  <b>O v35 publicava TRÊS tabelas de preço que não batiam entre si</b> (§07, §22.2, §24.8). A regra
  que as concilia está no D-33; o Metal Bruto ficou com o <b>derivado</b> (0,0333), e não com o
  0,1830 do §07 — <b>5,5× menos</b>. <span class="d">D-33, D-34</span>
  <br><br>
  Se a economia de mineração parecer fraca quando o jogo abrir, <b>este é o primeiro número a
  revisitar.</b>
</div>

<h3>3.2 Fert$</h3>

<p>
  Vive em <b>micro-unidades</b> (1 F$ = 1.000.000 µF$), não em centavos: a taxa de mercado e o preço
  por unidade produzem frações muito menores que um centavo, e arredondá-las criaria ou destruiria
  valor a cada transação. <span class="d">D-07</span> <?= entregue() ?>
</p>

<p>Todo colono começa com <b><?= n(Colony::SALDO_INICIAL_MICRO / Colony::MICRO_POR_FERT) ?> F$</b>, mais um kit fixo de recursos <span class="d">D-57</span>.</p>

<h3>3.3 O ledger — a regra de ouro</h3>

<div class="nota">
  <b>Recurso não nasce sem história.</b> O <code>ledger</code> é <i>append-only</i> e registra a
  origem de cada unidade que existe no planeta. Não é escrúpulo de contador: é a única defesa contra
  um bug — ou um operador — que crie valor em silêncio.
  <br><br>
  É por isso que até a <b>correção administrativa</b> lança <code>ajuste_admin</code>, com motivo
  escrito e o admin que a fez. <span class="d">D-61</span>
</div>

<!-- ══════════════════════════════════════════════════════════════ 4 -->
<h2 id="s4">4. Construções</h2>

<p>
  <b><?= count(Building::MVP) ?> construções</b> — <?= count(Building::ESSENCIAIS) ?> essenciais e
  <?= count(Building::PROGRESSAO) ?> de progressão. Custo e tempo saem da curva do GDD, e as tabelas
  abaixo são lidas do banco.
</p>

<div class="nota">
  <b>Custo e tempo arredondam de maneiras diferentes, e não é descuido.</b> O custo usa <b>half-up</b>;
  o tempo usa <b>half-even</b> (bancário). As duas são fórmula, mas não a mesma.
  <span class="d">D-02</span>
</div>

<h3>4.1 O que cada construção FAZ</h3>

<p>
  Esta tabela é a razão de ser deste documento. Ela tem <b>duas camadas que não podem ser
  confundidas</b>: o que o GDD <b>promete</b> (transcrito, com o §) e o que o jogo <b>entrega</b>.
</p>

<table>
  <tr><th>Construção</th><th>O GDD promete</th><th>§</th><th>O jogo entrega</th></tr>
  <?php foreach (Building::MVP as $tipo):
      $f = Funcoes::de($tipo);
      $inerte = $f['efeito'] === 'nenhum';
  ?>
  <tr>
    <td><b><?= e(humano($tipo)) ?></b></td>
    <td><?= e($f['frase']) ?></td>
    <td><?= e($f['fonte']) ?></td>
    <td>
      <?= $inerte ? promessa('Só consome energia') : entregue(ucfirst($f['efeito'])) ?>
      <?php if ($f['nota']): ?>
        <div style="font-size:.8rem;color:var(--ink-soft);margin-top:4px"><?= e($f['nota']) ?></div>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
</table>

<div class="nota grave">
  <b>Sete destas construções não fazem nada além de consumir energia.</b> O v35 as descreve com uma
  frase e nunca as quantifica — o Laboratório "pesquisa tecnologia" e não há árvore de pesquisa; a
  Refinaria tem taxa publicada (30/h) e <b>nenhuma receita</b>, então creditá-la criaria recurso do
  nada <span class="d">D-19</span>. Um documento que as listasse como funcionalidades faria um jogador
  gastar 90 Ligas num prédio inerte. <b>Elas estão aqui como promessa, que é o que são.</b>
</div>

<h3>4.2 Custo e tempo, por construção</h3>

<?php foreach (Building::MVP as $tipo):
    $niveis = $porTipo[$tipo] ?? [];
    if (! $niveis) { continue; }
    ksort($niveis);
    $recursosUsados = [];
    foreach ($niveis as $s) {
        foreach (json_decode($s->cost_json, true) ?? [] as $k => $v) { $recursosUsados[$k] = true; }
    }
    $recursosUsados = array_keys($recursosUsados);
?>
<h4><?= e(humano($tipo)) ?> <span class="d">(até o nível <?= max(array_keys($niveis)) ?>)</span></h4>
<table>
  <tr>
    <th class="num">Nível</th>
    <th class="num">Tempo</th>
    <?php foreach ($recursosUsados as $r): ?><th class="num"><?= e(humano($r)) ?></th><?php endforeach; ?>
    <th class="num">Energia/h</th>
  </tr>
  <?php foreach ($niveis as $nivel => $s): $custo = json_decode($s->cost_json, true) ?? []; ?>
  <tr>
    <td class="num"><?= $nivel ?></td>
    <td class="num">
      <?php if ($s->build_time_seconds === null): ?>
        <?= lacuna('sem tempo') ?>
      <?php else: ?>
        <?= n($s->build_time_seconds / 60, 0) ?> min<?= $s->build_time_derivado ? ' <span class="d">derivado</span>' : '' ?>
      <?php endif; ?>
    </td>
    <?php foreach ($recursosUsados as $r): ?>
      <td class="num"><?= isset($custo[$r]) ? n($custo[$r]) : '—' ?></td>
    <?php endforeach; ?>
    <td class="num"><?= $s->energia_consumo_hora ?: '—' ?></td>
  </tr>
  <?php endforeach; ?>
</table>
<?php endforeach; ?>

<div class="nota grave">
  <b>O v35 não publica tempo de construção para 10 das 25 tabelas.</b> Onde ele publica, usamos o
  publicado; onde não, <b>derivamos pela curva</b> e marcamos como <span class="d">derivado</span> —
  para que ninguém confunda o que o documento disse com o que nós deduzimos.
  <span class="d">D-10</span>
</div>

<!-- ══════════════════════════════════════════════════════════════ 5 -->
<h2 id="s5">5. Logística e frota</h2>

<h3>5.1 Os veículos</h3>

<table>
  <tr><th>Veículo</th><th class="num">Capacidade</th><th class="num">Velocidade</th><th class="num">Energia</th><th>Estado</th></tr>
  <tr>
    <td><b>Furgão de Comércio</b></td>
    <td class="num"><?= n(VeiculoSpecs::CAPACIDADE['furgao_de_comercio']) ?> un. (6 m³)</td>
    <td class="num">4 slots/min</td>
    <td class="num">1 kW/h por min</td>
    <td><?= entregue() ?> — vem no kit inicial</td>
  </tr>
  <tr>
    <td><b>Caminhão de Carga</b></td>
    <td class="num"><?= n(VeiculoSpecs::CAPACIDADE['caminhao_de_carga']) ?> un. (30 m³)</td>
    <td class="num">1,5 slots/min</td>
    <td class="num">3 kW/h por min</td>
    <td><?= entregue() ?> — comprado no Ministério</td>
  </tr>
  <tr><td>Drone de Exploração</td><td class="num">não carrega</td><td class="num"><?= lacuna() ?></td><td class="num"><?= lacuna() ?></td><td><?= promessa('Fatia 3 do D-52') ?></td></tr>
  <tr><td>Nave de Transporte Planetária</td><td class="num">4.000 un.</td><td class="num">10 slots/min</td><td class="num">Gelo de Metano</td><td><?= promessa('Fora do MVP') ?></td></tr>
  <tr><td>Cargueiro Interplanetário</td><td class="num">—</td><td class="num">—</td><td class="num">—</td><td><?= promessa('Depende do Espaçoporto') ?></td></tr>
</table>

<h3>5.2 A viagem <span class="d">D-30, D-32</span></h3>

<ul>
  <li>Toda movimentação de recurso <b>exige veículo físico</b> (§25.3).</li>
  <li>A <b>carga sai do estoque no despacho</b>, não na entrega: o recurso está fisicamente no
      veículo. É o que torna o calote "real e visível" (§25.7).</li>
  <li>A <b>energia dos dois trechos</b> é debitada no despacho — assim nenhum veículo parte sem ter
      como voltar. O v35 não diz quando cobrar. <?= arbitrado('cobrar na saída') ?></li>
  <li>O <b>tributo incide na entrega física</b>, uma vez por lote.</li>
</ul>

<div class="nota grave">
  <b>Contradição deliberada com o §07.</b> O §07 proíbe dupla incidência e isentaria o depósito e a
  retirada. Nós arbitramos pelo §25.8, que tributa <b>cada entrega física</b> — então ida e volta ao
  Mercado <b>sem vender</b> custa tributo duas vezes. É o §25.9 aplicado à letra, e está fixado em
  teste. <b>Não "conserte" sem perguntar.</b> <span class="d">D-32</span>
</div>

<h3>5.3 A frota envelhece <span class="d">D-60</span></h3>

<p>
  O §16.4 descreve a depreciação em cinco estágios e <b>não publica um único número</b>. E não é
  descuido: o próprio §16 diz de quem os números são — o <b>Painel do Ministério dos Transportes</b>
  "configura a curva de depreciação", "configura o limite crítico", "configura a perda de vida útil".
  <b>São do operador.</b> Foi isso que permitiu tirar a depreciação da geladeira sem inventar
  constante nenhuma no código.
</p>

<table>
  <tr><th>Parâmetro</th><th class="num">Hoje</th><th>Onde se muda</th></tr>
  <tr><td>Desgaste por hora de <b>uso ativo</b></td><td class="num"><?= n($config->desgaste_bps_por_hora / 100, 1) ?>%</td><td rowspan="4">Painel de admin → Transportes. <b>Sem deploy.</b></td></tr>
  <tr><td>Piso de desempenho</td><td class="num"><?= n($config->piso_desempenho_bps / 100, 0) ?>%</td></tr>
  <tr><td>Manutenção (fração do custo do veículo)</td><td class="num"><?= n($config->manutencao_bps_do_custo / 100, 0) ?>%</td></tr>
  <tr><td>Perda de teto por manutenção</td><td class="num"><?= n($config->perda_de_teto_bps / 100, 0) ?> pontos</td></tr>
</table>

<div class="nota grave">
  <b>O veículo NUNCA trava — e isto contraria o §16.4 de propósito.</b> O documento nomeia um
  "bloqueio operacional" abaixo do limite crítico. Nós transformamos o limite crítico num <b>piso de
  desempenho</b>: uma carcaça a 5% de conservação ainda anda a 25% da velocidade e carrega 25% da
  carga. Assim nenhuma das seis atribuições do painel do §16 se perde, e o colono nunca vê um
  patrimônio de 300 F$ parado à espera de peças. <b>Não "conserte" sem perguntar.</b>
  <span class="d">D-60</span>
</div>

<h3>5.4 O teto de frota <span class="d">D-28, D-60</span></h3>

<p><b>teto = máximo(1, nível da Central de Transportes)</b></p>

<div class="nota grave">
  <b>O §28.5 do v35 prometia caminhões de graça, e estava revogado desde sempre.</b> Ele dizia que "os
  caminhões correspondentes ao nível atual já estão incluídos no upgrade da Central — sem custo
  adicional". A tabela de precedência da seção 0 já dizia o contrário: <i>"libera vagas de frota;
  veículo é fabricado ou adquirido separadamente"</i>. <b>Neste v36 há uma só redação.</b>
  <br><br>
  O piso de 1 existe porque, desde os 21 slots, <b>colônia nova não tem Central</b> — e o Furgão do
  kit precisava caber em algum lugar. A fórmula preserva as duas tabelas do v35: a Central dá 1..10
  (§19.5), e o Terminal de Cargas, que "acrescenta duas vagas", dá 3..12 (§17.3).
</div>

<h3>5.5 O Ministério dos Transportes <span class="d">D-60</span></h3>

<table>
  <tr><th>Regra</th><th>Valor</th><th>Origem</th></tr>
  <tr><td>Fabricar caminhão</td><td><b>Privativo do Ministério</b></td><td><?= arbitrado('contraria o §17.2') ?></td></tr>
  <tr><td>Preço do Caminhão (nível 1)</td><td><b><?= n(Ministerio::precoFert()) ?> F$</b></td><td><?= arbitrado('~9× o valor dos recursos') ?></td></tr>
  <tr><td>Custo de fabricação (sai do Tesouro)</td><td><?php $c = Ministerio::custoFabricacao(); echo e(implode(' · ', array_map(fn ($k, $v) => "{$v} ".humano($k), array_keys($c), $c))); ?></td><td>GDD §21.3</td></tr>
  <tr><td>Tempo de fabricação</td><td><?= Ministerio::MINUTOS_FABRICACAO ?> min</td><td><?= arbitrado('') ?></td></tr>
  <tr><td>Prateleira do governo</td><td><?= Ministerio::ESTOQUE_ALVO ?> prontos, reposta no tick</td><td><?= arbitrado('') ?></td></tr>
  <tr><td>Entrega</td><td><b>Física</b> — o caminhão dirige-se da Capital até a colônia</td><td><?= arbitrado('') ?></td></tr>
  <tr><td>Placa (§16.3)</td><td><code>FW-00001-C</code> — sequencial global, inicial do tipo</td><td>GDD §16.3</td></tr>
</table>

<div class="nota">
  <b>Se o Tesouro secar, não há caminhão.</b> O Ministério fabrica com o caixa do governo — a
  redistribuição do §2.1 passa a ter consequência.
</div>

<!-- ══════════════════════════════════════════════════════════════ 6 -->
<h2 id="s6">6. O Mercado e o comércio</h2>

<h3>6.1 A regra dos dois estoques <span class="d">D-58</span></h3>

<div class="nota">
  <b>O que está na colônia se negocia entre colonos; o que está no depósito da Capital se oferta no
  Mercado Central.</b> É a regra que organiza todo o comércio, e ela decide também a <i>garantia</i>:
  <ul style="margin:8px 0 0">
    <li><b>Entre colonos</b>: promessa, entrega física por veículo, <b>calote possível</b> — e é o
        calote que alimenta o Ministério das Reputações. <span class="d">D-40</span></li>
    <li><b>Mercado Central</b>: <b>escrow</b>, e a execução move recurso de um depósito ao outro,
        <b>sem veículo</b>.</li>
  </ul>
</div>

<h3>6.2 A vitrine <span class="d">D-58</span></h3>

<p>
  A oferta <b>repousa</b> e fica visível até alguém executá-la. O livro antigo <b>casava as ordens no
  ato</b>, e por isso parecia deserto: a oferta que cruzava era consumida antes de qualquer um a ver.
  Trocamos a descoberta de preço pela visibilidade, conscientemente. <?= entregue() ?>
</p>

<h3>6.3 Teto do depósito da Capital</h3>

<table>
  <tr><th>Classe</th><th class="num">Teto</th></tr>
  <?php
  // O teto vive por CLASSE, e a classe vive no catálogo. Perguntamos o teto de um recurso
  // representativo de cada classe, em vez de duplicar a tabela aqui — se ela mudar, isto acompanha.
  $classes = [];
  foreach ($recursos as $r) { $classes[$r->tax_class] ??= $r->code; }
  foreach ($classes as $classe => $exemplo):
  ?>
  <tr><td><?= e(humano($classe)) ?></td><td class="num"><?= n(Deposito::teto($exemplo)) ?></td></tr>
  <?php endforeach; ?>
</table>

<p>
  <?= arbitrado('o v35 não põe teto nenhum') ?> Ocupa espaço o saldo <b>mais</b> o que está preso em
  ofertas — de venda <b>e</b> de compra. Se o depósito encher durante a viagem, entra o que couber e
  <b>o excedente volta na carroceria, sem tributo</b>: o que não entrou não foi entregue.
</p>

<h3>6.4 Acordo de Troca <span class="d">D-40, D-41, D-42, D-43</span></h3>

<table>
  <tr><th>Regra</th><th>Valor</th></tr>
  <tr><td>Escrow</td><td><b>Nenhum.</b> O calote é real e deliberado</td></tr>
  <tr><td>Cumprir é</td><td>Entregar <b>fisicamente</b>, e vale o <b>líquido que chega</b> (já tributado)</td></tr>
  <tr><td>Prazo mínimo</td><td>viagem + <?= intdiv(AcordoSpecs::FOLGA_PRAZO_SEGUNDOS, 3600) ?> h de folga <?= arbitrado('a folga') ?></td></tr>
  <tr><td>Confiança Comercial</td><td>começa em 500 · bloqueia abaixo de 200 · +10 / −50</td></tr>
</table>

<div class="nota">
  Quem embarca 100 <b>entrega 97</b>: o tributo come 3 no caminho. A tela despacha <b>pelo bruto</b>,
  não pelo prometido — o colono não deve descobrir que caloteou por três unidades de tributo.
  <span class="d">D-41</span>
</div>

<h3>6.5 Mercado de usados <span class="d">D-60</span></h3>

<p>
  Veículo se vende a outro colono, <b>com escrow do Ministério</b> — e isto contraria a regra dos dois
  estoques de propósito. A razão: <b>o Ministério é o cartório da placa</b>. O comprador paga, os F$
  ficam retidos, o veículo <b>dirige-se sozinho</b> até ele, e o <b>vendedor só recebe na chegada</b>.
</p>

<div class="nota grave">
  <b>O Furgão não tem teto de revenda</b>, e isso é um risco conhecido e aceito. O teto é
  <code>preço de fábrica × teto de conservação</code>, e o Furgão <b>não tem preço de fábrica</b> (o
  Ministério não o vende). Consequência: um Furgão sucateado pode ser anunciado por 5.000 F$ — e duas
  contas do mesmo jogador podem <b>lavar Fert$</b> por aí, sem tributo. <b>Se o multi-conta virar
  problema, é aqui que ele aparece primeiro</b>, e a cura é dar um teto ao Furgão.
</div>

<!-- ══════════════════════════════════════════════════════════════ 7 -->
<h2 id="s7">7. Reputação e o Ministério</h2>

<h3>7.1 Quatro índices, isolados <span class="d">D-48</span></h3>

<p>
  <b>Não existe "reputação geral".</b> São quatro índices independentes, <b>sem compensação
  cruzada</b> — ser bom comerciante não compra perdão por conduta. Todos nascem em <b>500</b>.
</p>

<table>
  <tr><th>Índice</th><th>O que mede</th></tr>
  <tr><td>Confiança Comercial</td><td>Cumprir ou calotear Acordos de Troca</td></tr>
  <tr><td>Conduta Social</td><td>Comportamento; alimentado pelas denúncias</td></tr>
  <tr><td>Status Cívico</td><td>Cargos e serviço público</td></tr>
  <tr><td>Honra Militar-Diplomática</td><td>Guerra e tratados <?= promessa('inerte até a guerra existir') ?></td></tr>
</table>

<h3>7.2 O rito <span class="d">D-44, D-49</span></h3>

<ol>
  <li><b>Denúncia</b>, com evidência mínima conferida (só o Acordo quebrado entre os dois serve).</li>
  <li><b>Triagem</b>: grave → equipe; simples → conciliador sem impedimento.</li>
  <li><b>48 h</b> para decidir. Venceu, sobe à equipe.</li>
  <li><b>Punição pela tabela fixa</b> — a pena <b>não é escolha do julgador</b>: a tela a publica
      antes do julgamento, e só oferece "procedente" e "improcedente".</li>
  <li><b>Apelação</b> de 48 h. Reversão estorna e suspende o conciliador em 5 reversões.</li>
</ol>

<div class="nota grave">
  <b>O §26.8 nunca publicou a tabela de penas.</b> Ela é nossa. <span class="d">D-49</span>
  <?= arbitrado('a tabela inteira') ?>
</div>

<div class="nota">
  <b>Metade do Ministério está inerte, por decisão.</b> O silêncio precisa de chat; o bloqueio de
  leilões precisa de leilões; o impedimento por federação precisa de federações. <b>Tudo grava com
  índice e prazo, e passa a morder sozinho</b> no dia em que esses sistemas existirem. A única punição
  que morde hoje é a <b>restrição comercial</b>: fecha a saída de carga por 7 dias.
  <span class="d">D-44, D-50</span>
</div>

<!-- ══════════════════════════════════════════════════════════════ 8 -->
<h2 id="s8">8. Território e zonas neutras</h2>

<p><b>120 zonas, em 4 distritos</b>, cada distrito com o seu mineral. <?= entregue() ?> <span class="d">D-52</span></p>

<table>
  <tr><th>Distrito</th><th>Mineral</th></tr>
  <tr><td>Nordeste</td><td>Metal Bruto</td></tr>
  <tr><td>Sudeste</td><td>Água</td></tr>
  <tr><td>Sudoeste</td><td>Oxigênio</td></tr>
  <tr><td>Noroeste</td><td>Biomassa</td></tr>
</table>

<p>
  <b>Ocupação:</b> Posto de Comando (800 Metal Bruto + 300 F$ + 8 h) + 20 Robôs Mineradores + 12 h.
  <b>Extração:</b> 100/h. <?= arbitrado('todos estes números') ?> — o v35 não publica nenhum deles.
</p>

<div class="nota grave">
  <b>A guerra (§27) não existe.</b> É a maior lacuna do jogo, e ela trava duas coisas: o
  <b>"estoque protegido"</b> (o saque de 50% depende de saber o que ele é) e os <b>bônus defensivos</b>
  de Muralha e Torre de Vigia. <?= lacuna('ambos') ?>
  <br><br>
  Enquanto isso: o <b>Quartel</b> não recruta, a <b>Torre de Defesa</b> defende um slot que o §01
  declara <b>inviolável</b> (o v35 se contradiz aqui e nós não resolvemos, porque não há guerra para
  resolver), e a <b>Honra Militar-Diplomática</b> nunca se move.
</div>

<!-- ══════════════════════════════════════════════════════════════ 9 -->
<h2 id="s9">9. Operação e administração</h2>

<p>
  Fora do escopo do v35, que descreve o <i>jogo</i> e não a <i>ferramenta</i>. Está aqui porque quem
  opera o jogo precisa saber que existe. <span class="d">D-56, D-61</span>
</p>

<h3>9.1 A auditoria</h3>

<div class="nota">
  <b>O painel de administração era o único lugar do sistema onde se podia criar valor sem deixar
  história.</b> Julgar um caso, distribuir 10.000 F$ do Tesouro, disparar um tick: nada disso ficava
  registrado. O <code>ledger</code> auditava a economia; <b>nada auditava a administração</b>.
  <br><br>
  Desde o D-61, <b>todo ato de admin</b> grava quem, quando, o quê, sobre quem, os <b>valores antes e
  depois</b>, o IP e o navegador — mais os <b>logins que falharam</b>. <b>Append-only</b>: nem o admin
  apaga.
</div>

<h3>9.2 Os dois papéis</h3>

<table>
  <tr><th>Papel</th><th>Pode</th></tr>
  <tr><td><b>dono</b></td><td>Tudo. Gere admins e <b>realoca colônias</b>.</td></tr>
  <tr><td><b>operador</b></td><td>Julga casos, publica notícias, distribui o Tesouro; nos jogadores, vê, <b>suspende</b> e <b>corrige estado</b>.</td></tr>
</table>

<p>
  <b>A suspensão</b> barra o acesso, revoga os tokens e congela <b>só o comércio</b> — reusando a
  restrição do §9.4. A colônia <b>continua produzindo</b>: o mundo não para, e nada se perde.
</p>

<!-- ══════════════════════════════════════════════════════════════ 10 -->
<h2 id="s10">10. Tudo o que ainda falta decidir</h2>

<p>
  <b>Esta é a seção mais útil do documento.</b> Nenhum número aqui foi inventado, e nenhum será até
  que alguém o decida — é a regra de ouro do projeto aplicada ao próprio GDD.
</p>

<table>
  <tr><th>Assunto</th><th>O que falta</th><th>O que ele trava</th></tr>
  <tr>
    <td><b>Guerra (§27)</b></td>
    <td>O que é <b>"estoque protegido"</b>; os <b>bônus defensivos</b> de Muralha e Torre de Vigia</td>
    <td>Todo o combate. O Quartel, a Torre e a Honra Militar</td>
  </tr>
  <tr>
    <td><b>Drone de Exploração</b></td>
    <td><b>Velocidade</b>, <b>raio de revelação</b>, <b>persistência</b> e <b>onde é fabricado</b>. O custo já está publicado (§4.3 do v3.4)</td>
    <td>A revelação de mapa</td>
  </tr>
  <tr>
    <td><b>Zonas neutras</b></td>
    <td>Custo e tempo das <b>9 estruturas restantes</b>; <b>teto de zonas por jogador</b>; <b>upgrade de zona</b></td>
    <td>A profundidade do território (hoje a zona é sempre nível 1)</td>
  </tr>
  <tr>
    <td><b>Árvore de pesquisa</b></td>
    <td><b>Tudo.</b> O v35 diz "pesquisa tecnológica" e nunca publica tecnologia, custo, tempo ou árvore</td>
    <td>O Laboratório, que hoje só consome energia</td>
  </tr>
  <tr>
    <td><b>Ligas e Compostos</b></td>
    <td>A <b>receita</b>. O v35 publica a taxa (30/h) e nunca os insumos</td>
    <td>A Refinaria Química, inerte. Creditá-la criaria recurso do nada <span class="d">D-19</span></td>
  </tr>
  <tr>
    <td><b>População</b></td>
    <td>Quantos colonos a Estrutura de Sobrevivência abriga, e <b>o que a população faz</b></td>
    <td>A Estrutura, que hoje só consome energia</td>
  </tr>
  <tr>
    <td><b>Marco (§03)</b></td>
    <td>A <b>fórmula</b>. O v35 nomeia os marcos (1 Sobrevivente … 100 Lenda) e nunca diz como se sobe</td>
    <td>A progressão. Congelado em <code>colonizacao_inicial</code> <span class="d">D-38</span></td>
  </tr>
  <tr>
    <td><b>Serviço logístico público (§07)</b></td>
    <td><b>Preço e prazo.</b> O v35 o cita como alternativa ao veículo próprio e nunca o especifica</td>
    <td>Quem não tem veículo não retira do Mercado</td>
  </tr>
  <tr>
    <td><b>Teto de estoque</b></td>
    <td>O v35 fala em "armazenamento" (Captação de Água, Tanque de Combustível) e <b>nunca publica um teto</b></td>
    <td>O Tanque, inerte. Guardar mais não faz diferença</td>
  </tr>
  <tr>
    <td><b>Níveis de veículo</b></td>
    <td><b>O que o nível muda.</b> O v35 publica custo até o nível 5 e nunca diz o que se ganha</td>
    <td>Só o nível 1 é vendido. E as <b>duas tabelas de custo do Caminhão divergem</b> a partir do nível 2 <span class="d">D-37</span></td>
  </tr>
  <tr>
    <td><b>Cargueiro Interplanetário</b></td>
    <td>O <b>Espaçoporto</b> e os 5 planetas NPC</td>
    <td>A 5ª atribuição do Ministério dos Transportes</td>
  </tr>
</table>

<div class="nota">
  <b>Um número que vale revisitar mesmo sem ser lacuna:</b> o <b>salário do conciliador</b> é emissão
  contínua (50 F$/dia), e o kit inicial de um colono é 50 F$. <b>Um conciliador ganha um kit inicial
  por dia sem jogar.</b> Com cinco colônias não importa; quando o jogo abrir, importa.
  <span class="d">D-50</span>
</div>

</main>

<footer>
  <div class="env">
    <p>
      <b>FERTWAYS — GDD v36.</b> Substitui o v35, que fica como registro histórico do que se pensava
      antes.
    </p>
    <p>
      As tabelas numéricas são geradas de <code>building_specs</code> e <code>resource_types</code> —
      as mesmas de onde o jogo lê — por <code>tools/gdd-v36.php</code>. O documento <b>não pode
      divergir do jogo</b>.
    </p>
    <p>
      O rastro de como se chegou a cada regra está em <code>docs/decisoes.md</code>. As referências
      <span class="d">D-nn</span> apontam para lá.
    </p>
  </div>
</footer>

</body>
</html>
<?php
echo ob_get_clean();
