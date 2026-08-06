#!/usr/bin/env php
<?php

/**
 * Gera o FERTWAYS GDD v40 (D-62, atualizado no D-141, no D-160 e no D-230).
 *
 *     /usr/bin/php84 tools/gdd-v40.php > docs/FERTWAYS_GDD_v40_CONSOLIDADO.html
 *
 * Este arquivo é uma cópia evoluída de `gdd-v39.php` (que fica intocado, como o v38, o v36 e o v35
 * ficaram intocados quando o v39 nasceu — D-141, D-160). Herda tudo o que estava atualizado até o
 * D-159, e acrescenta o que mudou desde então — **D-160 a D-229, setenta decisões**, que são a
 * Alpha 2 inteira.
 *
 * É o maior salto entre versões deste documento, e por um motivo: entre o v39 e o v40 o jogo ganhou
 * **quatro sistemas que não existiam** e reescreveu dois que existiam.
 *
 *  - **População** (`§13`, novo) — teto habitacional pela Estrutura de Sobrevivência, operadores por
 *    construção e por zona, consumo da cesta, crescimento, e a degradação do §6.6. É a mecânica que
 *    mais mudou o jogo, e a que mais exigiu medida antes de ligar (D-167 a D-179, D-184).
 *  - **Pesquisa** (`§14`, novo) — trilhas, vagas do Laboratório e efeitos que mexem no motor
 *    (D-168 a D-172, D-190).
 *  - **Eventos de mundo** (`§15`, novo) — o motor que muda produção e consumo do planeta por
 *    janela de tempo, com preview obrigatório e cancelamento que não apaga o passado (D-185).
 *  - **Guerra federativa** (`§8.9` a `§8.14`) — cerco de colônia, saque, capitulação, tratado de
 *    paz, neutralidade declarada e ranking Elo (D-193 a D-207). ⚠️ Duas contradições do §01 foram
 *    **revogadas na prática, conscientemente** (D-201, D-203).
 *  - **Teto de estoque** (`§3.5`) — o §14 do balanceamento enfim ligável, com o piso pessoal que o
 *    §6.7 exigiu (D-191, D-192, D-199).
 *  - **Upgrade de veículos** (`§5.6`) — o nível que existia sem caminho para subir (D-175, D-180,
 *    D-181).
 *  - **A curva do Marco recalibrada** (`§4.6`): BASE 50 → 15, com âncora medida em campo (D-223).
 *
 * ⚠️ **E uma seção nova de natureza diferente: `§16`, "O que o campo mediu".** Ela publica o que 24
 * dias de produção com 29 colônias ensinaram — e não é enfeite: metade das decisões desta leva saiu
 * de uma medida que contradisse o que estava escrito. Um GDD que só publica a regra e nunca o que
 * aconteceu com ela é a metade que envelhece primeiro.
 *
 * Ficaram de fora, de propósito, as ~25 decisões que só mudam tela e não mecânica — a revisão visual
 * inteira (A2.V1 a A2.V6: D-161, D-162, D-210 a D-222, D-224 a D-228), o backup do deploy (D-208,
 * D-209) e o hardening do login (D-186) —, pelo mesmo critério que a seção 0 já publica. O que elas
 * corrigiram **na regra**, e não no desenho, entrou nas seções correspondentes.
 *
 * ---
 *
 * **Por que isto é um gerador, e não um arquivo escrito à mão.**
 *
 * O v35 era um documento estático, e por isso **envelheceu**: o jogo mudou 59 vezes e o texto não.
 * As tabelas numéricas deste documento não são digitadas — são **lidas do mesmo banco de onde o
 * jogo lê** (`building_specs`, `resource_types`, e agora também `fabrica_veiculos`,
 * `federation_settings`, `silo_capacidades`, `population_settings`, `estoque_settings`), e boa
 * parte tem **testes que provam que batem com o GDD** (`tests/Gdd/GddSpecsTest`,
 * `tests/Gdd/LogisticaSpecsTest`).
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
use App\Domain\Cargos\CargosCivicosSpecs;
use App\Domain\Capital\Patio;
use App\Domain\Colony\KitInicial;
use App\Domain\Colony\Slots;
use App\Domain\Colony\TetoDoTanque;
use App\Domain\Endurance\EfeitosDaEndurance;
use App\Domain\Leilao\ListarLeilao;
use App\Domain\Logistics\MapaFertways;
use App\Domain\Logistics\VeiculoSpecs;
use App\Domain\Market\Deposito;
use App\Domain\Trade\AcordoSpecs;
use App\Domain\Transport\Ministerio;
use App\Domain\Zona\Estruturas;
use App\Domain\Zona\ZonaSlots;
use App\Models\Building;
use App\Models\Colony;
use App\Models\EnduranceItem;
use App\Models\FederationSetting;
use App\Models\FilaSetting;
use App\Models\TransportSetting;
use App\Models\Vehicle;
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
// O kit inicial (D-85) é editável pelo admin desde o D-92 — lido ao vivo, como o resto.
$kitFertMicro = KitInicial::fertMicro();
$kitRecursos = KitInicial::recursos();
$kitFrota = KitInicial::frota();

// A fábrica do Ministério (D-60, generalizada por tipo no D-109) — Caminhão e, desde então, Furgão.
$fabricaVeiculos = [];
foreach (Ministerio::TIPOS as $tipoVeiculo) {
    $fabricaVeiculos[$tipoVeiculo] = Ministerio::config($tipoVeiculo);
}

// O painel da Federação (D-119/D-120) — os dois números do §04 que o operador ajusta sem deploy.
$fedConfig = FederationSetting::singleton();

// O Silo/Depósito Local (D-107/108) — a capacidade por nível é uma grade editável (26 recursos ×
// 10 níveis); mostramos só um recurso representativo, porque a grade inteira não cabe numa tabela
// de leitura, e o padrão de partida é o mesmo número em todos os 26.
$siloExemplo = DB::table('silo_capacidades')->where('resource_type', 'metal_bruto')->orderBy('level')->pluck('capacidade', 'level');

// O catálogo da Loja de Peças da Endurance (D-135) — dinâmico, o admin cria/edita/apaga; contamos
// só quantos itens existem hoje, não listamos cada um (o documento descreve a REGRA do catálogo,
// não o inventário do momento, que muda pelo painel sem deploy).
$enduranceItensCount = DB::table('endurance_items')->count();

/*
 * ── O que a Alpha 2 acrescentou, e que o v40 publica pela primeira vez (D-230) ──
 *
 * Todos lidos do banco, pela mesma razão de sempre: número digitado à mão envelhece sozinho. Os
 * parâmetros de População e de teto de estoque são editáveis pelo operador **sem deploy**, então
 * publicá-los de uma constante seria publicar mentira já na semana seguinte.
 */
$pop = DB::table('population_settings')->find(1);
$estoqueCfg = DB::table('estoque_settings')->find(1);
$tecnologias = DB::table('technologies')->orderBy('trilha')->orderBy('nome')->get();

/* Operadores por construção: a tabela tem 60 linhas (tipo × nível); o documento publica a REGRA
 * (1 por nível de construção produtora, D-176) e uma amostra que prova que ela é o que está no
 * banco — não as 60 linhas, que seriam planilha e não documento. */
$operadoresAmostra = DB::table('building_operator_requirements')
    ->whereIn('building_type', ['fazenda', 'captacao_de_agua', 'gerador_de_atmosfera'])
    ->orderBy('building_type')->orderBy('level')->get();

$zonaOperadores = json_decode($pop->zona_operadores_por_nivel ?? '{}', true) ?: [];

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
<title>FERTWAYS — GDD v40 (consolidado)</title>
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
    <small>Game Design Document · versão 40 · consolidada</small>
    <h1>FERTWAYS</h1>
    <p>
      A base de produção do jogo. Este documento <b>substitui o v35</b> e, ao contrário dele,
      <b>não se contradiz</b>. É a quarta regeneração do v36 (D-62) — as anteriores chegaram ao
      D-101, ao D-140 e ao D-159; esta chega ao <b>D-229</b> e cobre a <b>Alpha 2 inteira</b>:
      <b>População</b>, <b>Pesquisa</b>, <b>Eventos de mundo</b> e a <b>guerra federativa</b> são
      sistemas que não existiam na versão anterior.
    </p>
    <p style="font-size:.9rem;color:rgba(253,240,226,.72)">
      E traz uma seção que nenhuma versão anterior teve: <b>§16, o que o campo mediu</b> — o que 24
      dias de produção com 29 colônias ensinaram, inclusive onde a medida contradisse a regra escrita.
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
  Das <b>159 decisões</b> já mescladas que este projeto registrou até hoje, boa parte existe
  porque o GDD se contradiz ou é omisso — e uma parte crescente (Leilões, o Ranking de Guerras, a
  Federação, a Loja de Peças da Endurance) não tem seção nenhuma no GDD: são sistemas inteiros que
  o documento só cita de passagem, e que teve de ser desenhados do zero. Este documento resolve as
  duas coisas de raiz:
</p>

<ul>
  <li><b>As contradições são resolvidas no texto.</b> Não há tabela de precedência, porque não há
      duas redações concorrentes. Onde o v35 dizia duas coisas, aqui há uma — e uma nota dizendo qual
      foi descartada e por quê.</li>
  <li><b>As lacunas são marcadas como lacunas.</b> Onde o GDD nunca publicou um número, este
      documento <b>não inventa um</b>: escreve <?= lacuna() ?> e segue. É a regra de ouro do projeto
      aplicada ao próprio documento — e faz deste documento, de quebra, a lista de tudo o que ainda
      falta decidir.</li>
  <li><b>O que o jogo entrega é separado do que ele promete.</b> Sete construções o GDD descreve com
      uma frase bonita e nunca quantifica. Um documento que as apresentasse como funcionalidades
      faria um jogador gastar 90 Ligas num prédio inerte.</li>
  <li><b>O que foi desenhado sem base no GDD é marcado como tal, não disfarçado de "regra do
      documento".</b> Leilões, o Ranking de Guerras e boa parte da Endurance são arbitragem
      registrada — a régua continua sendo "não inventar sem avisar", só que agora vale também para
      sistemas inteiros, não só para números soltos.</li>
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

<div class="nota">
  <b>Várias decisões recentes não mudam regra de jogo — mudam só a tela — e por isso não têm
  seção própria neste documento</b>, que descreve mecânica, não interface: o ícone de Sair e a
  ordem da lateral da colônia (D-88); o painel da Indústria Siderúrgica deixar de dizer que
  "produz" o Metal Bruto que na verdade processa (correção de exibição, sem D-nn); o HUD virar
  mobile-first e a navegação virar global, header/barra em toda tela (D-103, D-105); o canal
  Região sair do chat e a aba acender sozinha (D-104); o lote de arte de 68 estruturas (D-107);
  ícones de recurso e cards em Ofertas Globais (D-110). O D-86 (zona em cinco abas, Canteiro que
  pergunta a obra, Histórico novo) é, na maior parte, a mesma história — é UI —, mas o Histórico é
  conteúdo de jogo novo (uma linha do tempo da zona que não existia) e está registrado em
  <span class="d">§8.6</span>. O mesmo vale para o D-109, item 4 (recibo de compra) e o D-113
  (Subsídios é a mesma <code>Tesouro::distribuir()</code> de sempre, só o formulário mudou) —
  ambos citados nas seções que descrevem a regra que eles não mudaram.
  <br><br>
  <b>Nesta revisão, o mesmo critério deixou de fora</b>: o <code>/mapa</code> em tela cheia, com
  pinça de dois dedos e a legenda virando card flutuante (D-154, e as duas correções que ele
  precisou — a roda do mouse muda, D-155, e o <code>viewBox</code> que não enchia a tela larga,
  D-156), o ícone da zona neutra livre na legenda (D-158) e a Lista Mestra de Assets de Estruturas
  (D-143, que é o vínculo entre um arquivo de imagem e uma chave do jogo — arte, não mecânica).
  <b>Nenhuma delas muda uma regra</b>, e o documento não descreve telas.
</div>

<nav class="indice">
  <b>Índice</b>
  <ol>
    <li><a href="#s1">O mundo e a Capital</a></li>
    <li><a href="#s2">A colônia: os 22 slots</a></li>
    <li><a href="#s3">Recursos e economia</a></li>
    <li><a href="#s4">Construções</a></li>
    <li><a href="#s5">Logística e frota</a></li>
    <li><a href="#s6">O Mercado e o comércio — e os Leilões</a></li>
    <li><a href="#s7">Reputação, o Ministério e os Cargos Públicos</a></li>
    <li><a href="#s8">Território e zonas neutras</a></li>
    <li><a href="#s9">A Federação</a></li>
    <li><a href="#s10">A Endurance</a></li>
    <li><a href="#s11">Operação e administração</a></li>
    <li><a href="#s13">População <span class="d">novo no v40</span></a></li>
    <li><a href="#s14">Pesquisa <span class="d">novo no v40</span></a></li>
    <li><a href="#s15">Eventos de mundo <span class="d">novo no v40</span></a></li>
    <li><a href="#s16">O que o campo mediu <span class="d">novo no v40</span></a></li>
    <li><a href="#s12">Tudo o que ainda falta decidir</a></li>
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
  <tr><td>Periferia</td><td>d &gt; <?= MapaFertways::RAIO_ANEL ?> — fundável <b>só na célula que o Dono liberar</b></td><td><?= entregue() ?> <span class="d">D-147</span></td></tr>
</table>

<div class="nota">
  <b>Duas distâncias, e não se deve unificá-las.</b> As <i>faixas</i> do mapa usam a distância
  euclidiana <b>exata</b>. O <i>frete e o tributo</i> usam a distância <b>arredondada half-up</b>
  (§25.6). São coisas diferentes de propósito: uma classifica o território, a outra cobra o
  transporte. <span class="d">D-51</span>
</div>

<h3>1.1.1 Onde se pode fundar — a periferia vira curadoria <span class="d">D-147</span></h3>

<p>
  Do D-51 até aqui, um colono novo podia fundar nos <b>28 slots populáveis</b> do disco de founders
  (regra por fórmula) <b>ou em qualquer célula de periferia livre</b>. A segunda metade acabou: a
  periferia passou a ser curada <b>célula por célula</b> pelo Dono, pelo mapa do painel (§11.6), e a
  lista (<code>founding_cells</code>) <b>nasce vazia</b> — enquanto ninguém marcar a primeira, não
  há periferia fundável nenhuma.
</p>

<table>
  <tr><th>Faixa</th><th>Quem decide</th><th>Como</th></tr>
  <tr><td>Disco de founders</td><td>A fórmula</td><td><b>Intocada</b> — o D-147 não mexeu numa linha de <code>ehFounderPopulavel()</code>, e a ferramenta do admin <b>recusa</b> marcar uma célula do disco, para a lista nunca divergir da regra automática</td></tr>
  <tr><td>Anel livre</td><td>A fórmula</td><td>Não é fundável, como sempre foi</td></tr>
  <tr><td>Periferia</td><td><b>O Dono</b></td><td>Marca e desmarca a célula, sem motivo escrito e sem palavra de confirmação — é reversível com um segundo clique e não mexe em ninguém que já joga (ao contrário de mover uma colônia, §11.6)</td></tr>
</table>

<div class="nota">
  <b>Fundar continua sendo uma cerimônia, não uma invariante.</b> A checagem de onde se pode fundar
  vive no <b>único ponto de entrada de um jogador novo</b> (<code>ColonyController::store()</code>),
  não no primitivo que cria a colônia — mesmo precedente que a <b>realocação</b> já tinha desde o
  D-61: mover uma colônia existente nunca conferiu <code>podeFundar</code>, de propósito. Uma
  colônia pode <i>existir</i> onde não se poderia <i>fundar</i>. <span class="d">D-147</span>
</div>

<h3>1.2 A Capital — quatro áreas e uma praça <span class="d">D-63</span></h3>

<div class="nota grave">
  <b>Isto não está no GDD.</b> O §2.1 trata a Capital como uma <b>lista plana de 20 slots</b>,
  sem geografia nenhuma — nada de "praça", "quadrante" ou pontos cardeais. A planta é
  <b>arbitragem do usuário</b>, e é a terceira vez que uma premissa "isto está no GDD" não
  estava (ver D-60, o caminhão da Central de Transportes).
</div>

<p>
  Desde o D-63, a Capital é uma <b>cena de Phaser</b> — mesmo motor, mesmo clique em hexágono,
  mesma câmera da colônia — organizada em quatro áreas ao redor de uma praça central
  <b>decorativa</b> (não clica, não faz nada; é o marco que dá à Capital cara de cidade, e o
  espaço guardado para quando houver evento, chat público ou monumento).
</p>

<table>
  <tr><th>Área</th><th>O que tem</th><th>Estado</th></tr>
  <tr><td><b>Norte</b></td><td>Governo Central — a grade dos 19 slots institucionais (1–5, 7–8, 9–20; o 9–20 aparece <b>visível e travado</b>, expansão futura)</td><td><?= entregue() ?></td></tr>
  <tr><td><b>Oeste</b></td><td>Os destroços da <b>Endurance</b> — mapa das 8 seções do casco, uma Loja de Peças dinâmica por seção, e as missões narrativas que contam a história da escavação. Ver <span class="d">§10</span></td><td><?= entregue() ?> <span class="d">D-132 a D-140</span></td></tr>
  <tr><td><b>Leste</b></td><td>O <b>slot 6 inteiro</b>: Mercado Central + Pátio Logístico, juntos. Clicar abre o Mercado</td><td><?= entregue() ?> <span class="d">D-65</span></td></tr>
  <tr><td><b>Sul</b></td><td>O futuro <b>Espaçoporto</b> — mostra os 5 planetas do §23 com distância e risco, e diz que ninguém viaja ainda</td><td><?= promessa() ?></td></tr>
  <tr><td><b>Centro</b></td><td>A praça — 1 slot de tamanho, decorativa</td><td><?= entregue() ?></td></tr>
</table>

<p><b>O Norte, slot a slot:</b></p>

<table>
  <tr><th>#</th><th>Instituição</th><th>Função</th><th>Estado</th></tr>
  <tr><td>1</td><td>Administração Pública</td><td>Regras, comunicados, sanções finais</td><td><?= entregue('Painel de admin') ?></td></tr>
  <tr><td>2</td><td>Central de Tributos</td><td>Painel de taxas e o <b>caixa real</b> do Tesouro</td><td><?= entregue() ?> <span class="d">D-57</span></td></tr>
  <tr><td>3</td><td>Central de Pesquisas e Notícias</td><td>Mural de comunicados; Gagarin</td><td><?= entregue() ?></td></tr>
  <tr><td>4</td><td>Secretaria de Finanças</td><td>Preços de referência, intervenção de preço</td><td><?= entregue() ?> <span class="d">D-35</span></td></tr>
  <tr><td>5</td><td>Ministério da Segurança e Guerra</td><td>Conflitos, tratados, auditoria de combate</td><td><?= entregue() ?> <span class="d">D-66</span></td></tr>
  <tr><td>7</td><td>Ministério das Reputações</td><td>Denúncias, conciliação, recursos</td><td><?= entregue() ?></td></tr>
  <tr><td>8</td><td><b>Ministério dos Transportes</b></td><td>Fábrica de caminhões, registro de placas, oficina, e a Garagem do frete público</td><td><?= entregue() ?> <span class="d">D-60, D-76</span></td></tr>
  <tr><td>9</td><td>Embaixada Interplanetária</td><td>—</td><td><?= promessa('Fora do MVP') ?></td></tr>
  <tr><td>10–20</td><td>Expansão controlada</td><td>—</td><td><?= promessa('Fora do MVP') ?></td></tr>
</table>

<div class="nota grave">
  <b>O slot 8 contraria o v35 de propósito.</b> O §2.1 o reservava para o <i>Quartel de Alianças</i>.
  O Ministério dos Transportes (§16) tinha uma seção inteira no GDD e <b>nenhum slot na Capital</b> —
  o documento lhe dava seis atribuições e nenhum endereço. Pusemos ele no 8. <span class="d">D-60</span>
  <br><br>
  <b>O slot 6 não aparece no Norte</b> — ele <i>é</i> o Leste. Uma coisa, um lugar: nada aparece
  duas vezes na tela.
</div>

<!-- ══════════════════════════════════════════════════════════════ 2 -->
<h2 id="s2">2. A colônia: os 22 slots</h2>

<div class="nota grave">
  <b>Isto não existe no v35.</b> Procuramos <code>slot</code>, <code>terreno</code>,
  <code>lote</code>, <code>grade</code>: no v35, "slot" é a <i>colônia vista do mapa do planeta</i>,
  nunca um espaço de construção. <b>O documento não põe teto espacial nenhum.</b> Os 21 slots
  originais são arbitragem nossa <span class="d">D-59</span>; o 22º (o Depósito Local, abaixo) é
  arbitragem posterior, <span class="d">D-106</span>.
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
        ⬡              21
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

<div class="nota">
  <b>Que construção mora em que slot é arbitragem, e mudou três vezes em duas semanas.</b> O v35 não
  tem slots (ver acima), então nenhuma destas posições contraria o documento — elas só precisam ser
  <i>consistentes</i>, e por isso vivem numa constante só (<code>Domain\Colony\Slots</code>), lida
  pelo servidor e pela cena da colônia. O <b>Depósito Local</b> saiu da linha solta do fim (21) para
  o <b>centro exato da colmeia</b> (<span class="d">D-142</span>), foi para o 14
  (<span class="d">D-149</span>) e voltou ao centro no mesmo dia (<span class="d">D-150</span>); o
  <b>Reator de Energia</b> ficou com o <b>slot 6</b> (<span class="d">D-152</span>). A tabela acima é
  lida do código, não digitada — ela nunca fica para trás de uma troca dessas.
  <br><br>
  <b>Trocar slot mexe em colônia que já existe</b>, e o slot de destino quase nunca está vazio (no
  D-152, 18 das 28 colônias tinham construção de jogador no 6). Por isso toda troca é uma
  <i>permuta</i> em três passos, por um valor de passagem — nunca um "mova para lá", que colidiria
  com <code>unique(colony_id, slot)</code> ou sobrescreveria o que estivesse no caminho.
</div>

<h3>2.2 O Depósito Local — o Silo <span class="d">D-105, D-106, D-108</span></h3>

<p>
  O 22º slot: a colmeia ganhou uma linha solta de 1 ao final (o slot 21) — um <b>acréscimo</b>,
  nunca uma inserção, que teria deslocado a numeração de tudo e quebrado toda colônia já erguida.
  A construção em si mora hoje no <b>centro exato da colmeia</b>
  (slot <?= Slots::DEPOSITO_LOCAL['deposito_local'] ?>), o slot mais visível e mais alcançável, por
  ser a que o colono mais abre <span class="d">D-142, D-150</span>.
  Nasce <b>pronto, no nível 1</b>, subsidiado pelo Governo como as cinco essenciais, mas
  <b>indemolível sem ser "essencial"</b>: não entra no auto-subsídio do §24.7 nem ganha o selo de
  essencial, porque nenhuma das duas coisas é verdade para ele.
</p>

<p>
  <b>É por causa dele que os recursos deixam de ficar sempre visíveis na tela.</b> Antes, uma barra
  lateral fixa mostrava o depósito da colônia o tempo todo; desde o D-106, é preciso abrir o
  Depósito Local para ver — mesmo comportamento em desktop e mobile.
</p>

<div class="nota">
  <b>"Silo" é o mesmo Depósito Local, estendido</b> <span class="d">D-108</span>. O usuário pediu
  uma sub-aba de admin para configurar "o que poderá ser saqueado" por nível — perguntei se era uma
  construção nova; não é. O nível máximo subiu de 5 para <b>10</b>, e uma tabela nova
  (<code>silo_capacidades</code>) guarda quanto de CADA recurso cabe protegido, por nível —
  editável pelo painel, sem deploy. <b>Só a regra e o dado existem nesta entrega</b>: não há guerra
  de colônia no jogo hoje (o saque do §8.1 mira só Zona Neutra), então "protegido/exposto" não tem
  nenhuma tela nem consequência ainda — fica pronto para quando existir.
</div>

<table>
  <tr><th class="num">Nível</th><th class="num">Capacidade por recurso</th></tr>
  <?php foreach ($siloExemplo as $nivel => $capacidade): ?>
  <tr><td class="num"><?= $nivel ?></td><td class="num"><?= n($capacidade) ?></td></tr>
  <?php endforeach; ?>
</table>

<p style="margin-top:-6px">
  <?= arbitrado('10.000 em todos os 26 recursos, em todos os 10 níveis, é só o valor de partida') ?>
  — o operador ajusta célula a célula pelo painel (Gestão de Construções → Silo).
</p>

<h3>2.3 Repetíveis e únicas</h3>

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

<div class="nota">
  <b>E o freio subiu: o Reator vai até o nível
  <?= max(array_keys($porTipo['reator_de_energia'] ?? [5 => null])) ?></b> <span class="d">D-157</span>.
  O v35 o publica só <b>até o 5</b> — o mesmo teto que ele dá a todas as 5 essenciais e a mais 11
  construções de progressão, um <i>boilerplate</i> do documento, não uma decisão sobre o Reator. Os
  níveis 6 em diante <b>não são números novos</b>: saem das mesmas duas curvas que o próprio GDD já
  usa (custo <code>half-up(nível 1 × 1,65^(n−1))</code>; tempo e produção
  <code>half-even(base × 1,50^(n−1))</code>), conferidas primeiro reproduzindo exatamente os níveis
  1–5 <i>publicados</i> antes de estender. É o mesmo precedente do Depósito Local, que foi de 5 para
  10 no D-108. <b>O teto do Reator virou arbitragem</b>, e por isso ele saiu da lista de construções
  cujo nível máximo o teste conserta contra o documento — ver a tabela dele em <span class="d">§4.2</span>.
</div>

<h3>2.4 Demolição <span class="d">D-59, D-61</span></h3>

<ul>
  <li><b>O investido não volta.</b> Nada é estornado. O <code>custo_construcao</code> lançado na obra
      continua no ledger — o registro honesto de um gasto que virou pó.</li>
  <li><b>As cinco essenciais são indemolíveis.</b> Derrubar o Gerador de Atmosfera exigiria decidir o
      que acontece a uma colônia sem atmosfera, e o GDD não tem resposta.</li>
  <li><b>Não se demole o que está em obra.</b></li>
  <li><b>O colono tem de escrever a palavra <code>DEMOLIR</code></b> — e a API a exige, não só a tela.</li>
</ul>

<p>O v35 <b>não fala em demolição</b>, nem na palavra nem no conceito. <?= arbitrado('tudo acima') ?></p>

<h3>2.5 O Marco <span class="d">D-75, §03/§05</span></h3>

<p>
  O v35 nomeia os oito marcos e nunca diz como se sobe. A fórmula: <b>XP acumulado = 50 × N²</b>
  — curva quadrática, porque as curvas publicadas do GDD (1,5×/1,65×) são para 5 níveis e
  explodem muito antes do marco 100.
</p>

<table>
  <tr><th>Marco</th><th>Título</th><th class="num">XP</th></tr>
  <tr><td>1</td><td>Sobrevivente</td><td class="num">50</td></tr>
  <tr><td>5</td><td>Colono</td><td class="num">1.250</td></tr>
  <tr><td>10</td><td>Pioneiro</td><td class="num">5.000</td></tr>
  <tr><td>20</td><td>Desbravador</td><td class="num">20.000</td></tr>
  <tr><td>35</td><td>Construtor</td><td class="num">61.250</td></tr>
  <tr><td>50</td><td>Arquiteto</td><td class="num">125.000</td></tr>
  <tr><td>75</td><td>Guardião</td><td class="num">281.250</td></tr>
  <tr><td>100</td><td>Lenda de Fertways</td><td class="num">500.000</td></tr>
</table>

<p>
  Ganha XP: obra concluída (por nível), zona ocupada, combate vencido, Acordo executado, ordem
  executada no Mercado Central — tudo em <code>xp_entries</code>, append-only, valores do
  operador (painel Operação). <b>Dois portões vivos</b>: marco 10 libera Drone nível 2+; marco
  20 libera ocupar zona neutra. <b>O Mercado Central não tem portão</b> — contradição consciente
  com o §05, para que o §03 ("compra o primeiro lote de Ligas com os 50 F$") continue verdadeiro.
  Posse anterior ao gate é sempre preservada; <code>fertways:marco --aplicar</code> credita XP
  retroativo, idempotente.
</p>

<h3>2.6 As Missões <span class="d">D-78, §06</span></h3>

<table>
  <tr><th>Categoria</th><th>Quantas</th><th>Janela</th></tr>
  <tr><td>Tutoria</td><td>5, entregues na fundação</td><td>dias 1–3</td></tr>
  <tr><td>Diária</td><td>3, sorteadas de um pool de 30+</td><td><b>07h → 07h</b> — a régua da semanal, aplicada ao dia inteiro</td></tr>
  <tr><td>Semanal</td><td>1</td><td>quarta 07h → terça 23h59, textual do GDD</td></tr>
  <tr><td>Eventuais <span class="d">D-99</span></td><td>fora do pool — atribuição manual</td><td>sem sorteio automático (evento, sazonal, lançamento)</td></tr>
  <tr><td>Federação <span class="d">D-116</span></td><td>2 por semana, cooperativa — uma linha POR COLÔNIA-MEMBRO, "irmãs" do mesmo objetivo</td><td>mesma janela da semanal</td></tr>
  <tr><td>Narrativa (Endurance) <span class="d">D-140</span></td><td>4 capítulos, encadeados</td><td>sem janela — um capítulo, uma vez, só depois do anterior concluído. Ver <span class="d">§10.5</span></td></tr>
</table>

<p>
  <b>1 rejeição por dia</b> nas diárias, publicado. Pagamento é <b>instantâneo</b> na conclusão —
  sem botão de resgate —, generoso por arbitragem (2× a proposta modesta original). <b>A tutoria
  recompensa e não trava</b> o subsídio: contradição deliberada com o §03 ("mediante conclusão da
  tutoria"), porque o tutorial já é auto-completo na fundação desde antes das Missões existirem.
  Desde o <span class="d">D-102</span>, uma missão concluída também avisa pelo rádio (conta de
  sistema "Missões") o que foi pago — a mesma lacuna que o D-91 já tinha fechado para o Pátio.
</p>

<p>
  <b>O catálogo de ações que um molde pode escutar</b> cresceu com o pedido do usuário
  <span class="d">D-100</span>: além dos atos de sempre (construção, despacho, combate, Mercado,
  Nióbio, chat, frete público, manutenção), agora inclui <b>comprar do Governo no Mercado
  Central</b> (distinto de comprar de outro colono), <b>comprar um veículo novo</b> (do
  Ministério), <b>comprar um veículo usado</b> e <b>vender um veículo usado</b> — este último só
  dispara na entrega, quando o Fert$ de fato chega ao vendedor (§5.5, §6.5). O D-140 acrescentou
  <b>comprar item da Loja de Peças da Endurance</b>, o único gancho novo dedicado à narrativa.
</p>

<div class="nota">
  <b>A categoria Federação é a primeira missão com progresso COMPARTILHADO</b> — não somado por
  colônia, é o MESMO placar espelhado entre todas as irmãs do grupo (<span class="d">§9.4</span>).
  <b>A categoria Narrativa é a primeira com PRÉ-REQUISITO</b> — um capítulo só chega à mão quando o
  anterior está concluído (<code>mission_templates.requer_template_id</code>, auto-referente). São
  as duas únicas extensões estruturais que o motor genérico do D-78 ganhou desde que nasceu.
</div>

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

<h3>3.2.1 O kit inicial <span class="d">D-85, D-92</span></h3>

<p>
  Toda colônia nova recebe <b><?= n($kitFertMicro / Colony::MICRO_POR_FERT) ?> F$</b>, um valor
  fixo por recurso do catálogo, e a frota abaixo. Uma tabela única, substituindo de vez os 50 F$
  do GDD, os raros calculados do "muro de progressão" (D-17) e o kit fixo do D-57.
</p>

<table>
  <tr><th>Recurso</th><th>Classe</th><th class="num">Quantidade no kit</th></tr>
  <?php foreach ($recursos as $r): ?>
  <tr>
    <td><?= e($r->nome) ?></td>
    <td><?= e($r->tax_class) ?></td>
    <td class="num"><?= n($kitRecursos[$r->code] ?? 0) ?></td>
  </tr>
  <?php endforeach; ?>
</table>

<p style="margin-top:10px">
  Frota do kit:
  <?php foreach ($kitFrota as $tipo => $qtd): ?>
    <b><?= n($qtd) ?>× <?= e(humano($tipo)) ?></b><?= $tipo !== array_key_last($kitFrota) ? ', ' : '' ?>
  <?php endforeach; ?>.
</p>

<div class="nota grave">
  <b>O muro de progressão quebra de propósito</b> <span class="d">D-85</span>. Nióbio Alienígena e
  Quartzo Piezoelétrico não são produzíveis no jogo — só o governo vende —, e o kit dá menos do
  que a Torre de Defesa + Quartel (juntas, 5 Nióbio) e a Refinaria Química + Antena de Comunicação
  (juntas, 3 Quartzo) exigem. As duas ficam trancadas para quem acabou de fundar, até negociar com
  o governo. Decisão confirmada, não lacuna.
</div>

<div class="nota">
  <b>Este kit é editável pelo admin, sem deploy</b> <span class="d">D-92</span>, em
  <code>/central/admin</code> → Operação → Kit inicial — Fert$, os 26 recursos e a quantidade de
  cada veículo. Os números acima são os que estão valendo <b>agora</b> (o gerador lê o banco ao
  vivo), mas podem ter mudado desde a última vez que este documento foi gerado. Só vale para quem
  funda DEPOIS de uma mudança — sem backfill, mesma regra desde o D-85.
</div>

<h3>3.3 O ledger — a regra de ouro</h3>

<div class="nota">
  <b>Recurso não nasce sem história.</b> O <code>ledger</code> é <i>append-only</i> e registra a
  origem de cada unidade que existe no planeta. Não é escrúpulo de contador: é a única defesa contra
  um bug — ou um operador — que crie valor em silêncio.
  <br><br>
  É por isso que até a <b>correção administrativa</b> lança <code>ajuste_admin</code>, com motivo
  escrito e o admin que a fez. <span class="d">D-61</span>
</div>

<p>
  <b>O Tesouro ganhou o próprio ledger</b> <span class="d">D-96</span>: até aqui o caixa do
  Governo só guardava o SALDO corrente (<code>treasury_holdings</code>), sem histórico nenhum —
  ao contrário de toda colônia. O <code>treasury_ledger</code> é a mesma regra de ouro, do lado do
  Tesouro: uma tabela nova, sem <code>colony_id</code> (o Tesouro é um só), append-only, visível
  em <code>/central/admin</code> → Economia → Extrato do Governo. O jogador continua vendo só o
  próprio extrato — o Fert$ do card do HUD, aberto num popup <span class="d">D-94</span> — e o
  extrato de toda colônia junto vive só do lado do admin, em Extrato Colonos.
</p>

<h3>3.4 A taxa por hora: o fluxo, e não só o estoque <span class="d">D-153</span></h3>

<p>
  O colono via <b>quanto tem</b> de cada recurso (o card Recursos, dentro do Depósito Local) e nunca
  <b>quanto entra e quanto sai por hora</b>. O card "Recursos por hora" publica os dois lados
  <b>separados</b> por recurso — não o líquido: uma Fazenda que produz 60 de Biomassa e uma
  Destilaria que consome 40 aparecem as duas, no mesmo recurso, em vez de virarem um "+20" que
  esconde metade da colônia.
</p>

<table>
  <tr><th>Regra</th><th>Valor</th></tr>
  <tr><td>Base</td><td>A <b>mesma conta do tick</b> — o método que calcula a taxa nominal de cada construção erguida foi extraído do <code>ColonyTick</code>, não reescrito ao lado dele. Duas contas divergiriam</td></tr>
  <tr><td>Taxa</td><td><b>Nominal</b>: capacidade plena, sem refletir a clampagem por insumo escasso que o tick de verdade aplica <?= arbitrado('mesma leitura que a zona neutra já dava na extração e no refino') ?></td></tr>
  <tr><td>Indústria Siderúrgica</td><td>O tick credita em <b>lotes inteiros</b> de 1.000 Metal Bruto; o card mostra a <b>média suavizada</b> por hora — em colônia pequena, os minerais mais raros arredondam para zero, o que é honesto: a essa taxa eles não rendem uma unidade inteira por hora</td></tr>
  <tr><td>Bônus da Endurance</td><td>Entram na conta — é a mesma taxa que o tick usa (§10.2)</td></tr>
</table>

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

<div class="nota">
  <b>Custo, tempo e nível máximo deixaram de ser fixos no código</b> <span class="d">D-108</span>.
  A tabela acima é a curva BASE do GDD, mas o operador pode sobrepor custo e tempo por
  (construção, nível) numa tabela à parte (<code>building_specs_overrides</code>), sem deploy e
  sem risco de o ajuste se perder no próximo <code>db:seed</code> — o Silo (§2.2) foi o primeiro a
  usar esse mecanismo (nível máximo 5→10), mas ele vale para qualquer uma das <?= count(Building::MVP) ?>.
  Ver <span class="d">§11.3</span>.
</div>

<div class="nota">
  <b>Duas construções passam do nível 5 que o v35 publica</b>: o <b>Depósito Local</b>, até o 10
  (<span class="d">D-108</span>, §2.2), e o <b>Reator de Energia</b>, até o
  <?= max(array_keys($porTipo['reator_de_energia'] ?? [5 => null])) ?>
  (<span class="d">D-157</span>, §2.3). Nos dois casos os níveis novos saem das <b>mesmas curvas</b>
  do documento, e as linhas acima são as do banco — não uma tabela paralela escrita à mão. O teste
  que reconfere <i>todo</i> <code>build_time_seconds</code> contra a curva do GDD, célula por
  célula, cobre também os níveis estendidos: se a extrapolação tivesse um erro de aritmética, ele
  reprovaria.
</div>

<h3>4.3 Manutenção de estruturas <span class="d">D-112</span></h3>

<p>
  Além do consumo de energia do §19.x (que continua exatamente como sempre foi — balanceamento do
  GDD, intocado), o operador pode ligar um consumo <b>extra</b> de qualquer recurso primário ou
  industrial, por hora, por TIPO de construção — não por nível. <?= arbitrado('recurso primário/industrial apenas; os 9 raros ficam de fora de propósito, para não mexer na escassez que já é o mecanismo do §22.4') ?>
</p>

<div class="nota">
  <b>Aditivo, nunca substitui.</b> Enquanto o operador não configurar nada, nenhuma construção
  consome um grama a mais do que consumia antes do D-112 — a tabela nasce vazia. Soma linearmente
  entre cópias da mesma construção (duas Minas, duas Oficinas...), do mesmo jeito que a produção já
  soma. O estoque trava em zero, nunca fica negativo — mesma regra de sempre <span class="d">D-20</span>.
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
    <td><?= entregue() ?> — <?= n($kitFrota['furgao_de_comercio'] ?? 0) ?>× no kit, e fabricado pelo Ministério desde o D-109 <span class="d">D-92, D-109</span></td>
  </tr>
  <tr>
    <td><b>Caminhão de Carga</b></td>
    <td class="num"><?= n(VeiculoSpecs::CAPACIDADE['caminhao_de_carga']) ?> un. (30 m³)</td>
    <td class="num">1,5 slots/min</td>
    <td class="num">3 kW/h por min</td>
    <td><?= entregue() ?> — <?= n($kitFrota['caminhao_de_carga'] ?? 0) ?>× no kit, comprado no Ministério <span class="d">D-92</span></td>
  </tr>
  <tr><td>Drone de Exploração</td><td class="num">não carrega</td><td class="num">8 slots/min</td><td class="num">bateria, não energia</td><td><?= entregue() ?> <span class="d">D-74</span></td></tr>
  <tr><td>Nave de Transporte Planetária</td><td class="num">4.000 un.</td><td class="num">10 slots/min</td><td class="num">Gelo de Metano</td><td><?= promessa('Fora do MVP') ?></td></tr>
  <tr><td>Cargueiro Interplanetário</td><td class="num">—</td><td class="num">—</td><td class="num">—</td><td><?= promessa('Depende do Espaçoporto') ?></td></tr>
</table>

<p>
  Todo veículo tem <b>placa</b> (§16.3), única e permanente — não muda de dono nem de mão. Desde o
  D-101, o colono também pode dar um <b>apelido</b> a cada veículo (opcional, sem filtro de
  conteúdo, mesmo desenho do nome da zona — §8.7): "Furgão de Comércio" continua sendo o que ele
  É; o apelido é só como o dono prefere chamá-lo na tela da Frota.
</p>

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
  veículo é fabricado ou adquirido separadamente"</i>. <b>Neste documento há uma só redação.</b>
  <br><br>
  O piso de 1 existe porque, desde a colmeia original de 21 slots, <b>colônia nova não tem
  Central</b> — e o Furgão do kit precisava caber em algum lugar. A fórmula preserva as duas
  tabelas do v35: a Central dá 1..10
  (§19.5), e o Terminal de Cargas, que "acrescenta duas vagas", dá 3..12 (§17.3).
</div>

<h3>5.5 O Ministério dos Transportes <span class="d">D-60, D-109</span></h3>

<p>
  <b>Fabrica dois veículos hoje</b>: o Caminhão de Carga (GDD §21.3, nível 1 — desde o D-60) e,
  desde o <span class="d">D-109</span>, o <b>Furgão de Comércio</b> também — antes, o Furgão só
  vinha no kit inicial. <?= arbitrado('contraria o §17.2, que atribui a fábrica à Central de Transportes') ?>
</p>

<table>
  <tr><th>Veículo</th><th class="num">Preço</th><th>Custo de fabricação (Tesouro)</th><th class="num">Tempo</th><th class="num">Prateleira</th></tr>
  <?php foreach ($fabricaVeiculos as $tipoVeiculo => $cfg):
      $custoTexto = implode(' · ', array_map(fn ($k, $v) => "{$v} ".humano($k), array_keys($cfg['custo']), $cfg['custo']));
  ?>
  <tr>
    <td><b><?= e(humano($tipoVeiculo)) ?></b></td>
    <td class="num"><?= n($cfg['preco_micro'] / Colony::MICRO_POR_FERT) ?> F$</td>
    <td><?= e($custoTexto) ?></td>
    <td class="num"><?= $cfg['minutos_fabricacao'] ?> min</td>
    <td class="num"><?= $cfg['estoque_alvo'] ?> prontos</td>
  </tr>
  <?php endforeach; ?>
</table>

<p>
  <?= arbitrado('todos os números da tabela — inclusive o Furgão a 40% do Caminhão em cada coluna, arredondado') ?>,
  editáveis pelo painel (Gestão de Construções → Fábrica, <span class="d">§11.3</span>), sem
  deploy, desde o D-109 — antes eram constantes de PHP. <b>Entrega física</b> nos dois: o veículo
  dirige-se sozinho da Capital até a colônia. Placa sequencial global (§16.3):
  <code>FW-00001-C</code> para Caminhão, <code>FW-00001-F</code> para Furgão.
</p>

<div class="nota">
  <b>Se o Tesouro secar, não há veículo novo.</b> O Ministério fabrica com o caixa do governo — a
  redistribuição do §2.1 passa a ter consequência.
  <br><br>
  <b>Um veículo vazio pode agora estacionar numa zona neutra sua</b> <span class="d">D-109</span> —
  um terceiro lugar, ao lado de "em casa" e "no Pátio". Do Pátio ou de casa, ele pode ir para a
  Capital, para casa ou para uma zona sua; de uma zona, <b>só de volta para casa</b> — ir direto de
  uma zona para a Capital ou para outra zona fica de fora de propósito (a distância nunca foi
  calculada em lugar nenhum do jogo; "voltar pra casa" continua sendo a válvula de escape, nenhum
  veículo fica preso).
</div>

<h3>5.6 O serviço logístico público (§07) <span class="d">D-76</span></h3>

<p>
  O v35 cita o frete público como alternativa a ter veículo próprio e <b>nunca publica preço nem
  prazo</b>. Resolvido pela <b>Garagem do Governo</b>: 10 caminhões iniciais, sem dono, que o
  operador expande pelo painel ("Encomendar +1"), <b>só da doca do Mercado Central até a própria
  colônia</b> — zona neutra continua exigindo veículo próprio.
</p>

<table>
  <tr><th>Regra</th><th>Valor</th><th>Origem</th></tr>
  <tr><td>Preço</td><td><b>1 F$ + 0,02 F$/slot</b> de distância</td><td><?= arbitrado('deliberadamente perto de subsídio') ?></td></tr>
  <tr><td>Carga máxima por viagem</td><td>30.000 unidades, <b>somando quantos recursos couberem</b> na mesma viagem <span class="d">D-151</span></td><td><?= arbitrado('') ?></td></tr>
  <tr><td>Tributo</td><td><b>Incide na chegada</b>, como qualquer entrega física</td><td>D-32 — frete não é rota de fuga</td></tr>
  <tr><td>Receita</td><td>Vai ao <b>Tesouro</b></td><td>—</td></tr>
  <tr><td>Desgaste</td><td><b>A frota pública não desgasta</b> — a viagem de frete é isenta do §16.4</td><td><?= arbitrado('') ?></td></tr>
</table>

<div class="nota">
  <b>O teto sempre foi sobre a SOMA, nunca sobre "um recurso só"</b> <span class="d">D-151</span>.
  A carroceria do frete público aceita várias linhas de carga na mesma viagem — o mesmo formato e a
  mesma regra do frete com veículo próprio (§6.0), que já levava vários recursos desde o D-65. Era
  <i>a tela</i> que oferecia um <code>&lt;select&gt;</code> só; o servidor nunca teve essa
  restrição. Dois recursos numa viagem saem num caminhão só, cada um debitado do seu próprio saldo,
  e a soma é que não pode passar de 30.000 — mesmo que cada um sozinho coubesse.
</div>

<!-- ══════════════════════════════════════════════════════════════ 6 -->
<h2 id="s6">6. O Mercado e o comércio</h2>

<h3>6.0 Duas telas, dois donos <span class="d">D-65</span></h3>

<table>
  <tr><th>Tela</th><th>Onde</th><th>Faz</th></tr>
  <tr><td><b>Mercado Local</b></td><td>Construção do colono, tela própria</td><td>Enviar carga ao depósito da Capital, ofertar a outros colonos, ver as ofertas deles</td></tr>
  <tr><td><b>Mercado Central</b></td><td>Governo — Leste da Capital, slot 6</td><td>Ofertar no Mercado Central, ver as ofertas globais, o Pátio e o depósito</td></tr>
</table>

<p>
  Só a <b>entrega ao depósito</b> é alcançável pelas duas telas — de propósito: a colônia nasce
  sem Mercado Local erguido, e o depósito precisa continuar alcançável mesmo assim. Negociar com
  outros colonos exige a construção.
</p>

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

<h3>6.2.1 O Governo vende, na mesma vitrine <span class="d">D-87</span></h3>

<p>
  O Tesouro pode ofertar recursos direto na vitrine do Mercado Central, ao lado dos colonos — a
  aba <b>Mercado</b> de <code>/central/admin</code> → Economia. O número que o admin digita por
  recurso não <i>soma</i> ao que já está anunciado: <b>é quanto deve estar à venda AGORA</b>. Subir
  reserva mais do Tesouro; descer devolve a diferença; zerar cancela a oferta. O admin não pode
  ofertar mais do que o Tesouro de fato tem.
</p>

<p>
  Não existe uma "colônia do Governo" fingida no mapa — a oferta é uma linha comum de
  <code>market_orders</code> com dono nulo, e a vitrine mostra "Governo" no lugar do nome. Uma
  colônia sintética apareceria (por engano) como alvo de guerra e como vizinha no diretório de
  todo mundo; isso foi evitado de propósito.
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

<h3>6.3.1 O Pátio Logístico <span class="d">D-65</span></h3>

<p>
  Todo veículo que entrega no depósito da Capital <b>fica estacionado</b> no Pátio — não volta
  sozinho. Dali ele parte para a própria colônia ou direto para outro colono.
</p>

<table>
  <tr><th>Regra</th><th>Valor</th></tr>
  <tr><td>Cobrança</td><td><b><?= n(Patio::TARIFA_MICRO_HORA / Colony::MICRO_POR_FERT, 3) ?> F$/hora</b>, por veículo, hora cheia, sem limite de vagas — vai ao Tesouro</td></tr>
  <tr><td>Sem Fert$ para pagar</td><td>O veículo é <b>rebocado de graça</b> para casa — nunca fica refém</td></tr>
  <tr><td>Chamar de volta, vazio, por vontade própria</td><td>Paga a energia da distância, como qualquer despacho — mas <b>não</b> exige Confiança Comercial: é reaver o próprio veículo, não usar o Mercado <span class="d">D-91</span></td></tr>
  <tr><td>Aviso da Capital</td><td>Uma mensagem no rádio (chat), de "Capital", ao estacionar e a cada 24h que continuar lá — diz a tarifa e lembra de chamá-lo de volta <span class="d">D-91</span></td></tr>
  <tr><td>Sobra de carga (depósito lotou)</td><td>O veículo volta na hora com o excedente, sem estacionar</td></tr>
  <tr><td>Energia da ida só</td><td><b>Metade</b> da viagem normal — ele não faz a volta</td></tr>
</table>

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

<div class="nota">
  <b>O teto de revenda do Furgão foi fechado</b> <span class="d">D-73</span>, e depois herdou uma
  âncora melhor. Até o D-109, o Furgão não tinha preço de fábrica (o Ministério não o vendia), e o
  risco era real: um Furgão sucateado podia ser anunciado por 5.000 F$, e duas contas do mesmo
  jogador lavarem Fert$ por aí, sem tributo. A correção original: uma <b>âncora de 60 F$</b>,
  parâmetro do operador. Desde o <span class="d">D-109</span>, o Furgão TEM preço de fábrica
  (§5.5) — o teto de revenda passa a ancorar nele, igual ao Caminhão (<code>preço de fábrica ×
  conservação</code>); a âncora antiga fica no schema, sem migration só para tirá-la, mas não é
  mais lida.
</div>

<h3>6.6 Leilões <span class="d">D-129, D-136</span></h3>

<div class="nota grave">
  <b>O GDD não descreve isto.</b> "Leilão" aparece DUAS vezes no documento inteiro, e as duas são a
  mesma frase — "reputação negativa bloqueia acesso a leilões" (§9.4) — citando um mecanismo que o
  próprio texto nunca desenha: sem tabela, sem fórmula, sem prazo, sem incremento de lance, sem
  dizer quem lista o quê. Diferente da Federação e do Ranking de Guerras (que tinham fórmula ou
  estrutura publicada para ancorar a arbitragem), aqui não havia lacuna para preencher — havia um
  mecanismo inteiro para inventar. Perguntei ao usuário antes de comprometer código; a resposta foi
  desenhar do zero.
</div>

<p>
  Um leilão é <b>um lote único, tudo ou nada</b> — sem arremate parcial, diferente de uma ordem do
  Mercado Central. Sai do MESMO depósito que o Mercado Central usa: quem quer leiloar já entregou a
  carga na doca da Capital, a mesma exigência física do §07 — não existe um segundo depósito para
  um mecanismo que é, na essência, mais uma forma de vender na doca.
</p>

<table>
  <tr><th>Regra</th><th>Valor</th></tr>
  <tr><td>Acesso</td><td>A MESMA Confiança Comercial que já fecha o Mercado Central (&lt; 200, §9.4/D-43) — nenhum gate novo</td></tr>
  <tr><td>Duração</td><td><b><?= ListarLeilao::DURACAO_MIN_HORAS ?> a <?= ListarLeilao::DURACAO_MAX_HORAS ?> horas</b>, escolhida por quem anuncia</td></tr>
  <tr><td>Lance mínimo</td><td>Arbitrado por quem anuncia — não há preço-base de leilão no GDD</td></tr>
  <tr><td>Lance</td><td>Escrow NA HORA. Quem é superado recebe de volta NO MESMO INSTANTE, não no fechamento</td></tr>
  <tr><td>Incremento mínimo</td><td>Nenhum — qualquer valor acima do lance vigente (ou do mínimo) vale</td></tr>
  <tr><td>Cancelar</td><td>Só enquanto NINGUÉM deu lance — depois disso, tirar o lote da mesa seria calote do vendedor</td></tr>
  <tr><td>Fechamento sem lance</td><td>O lote volta a quem anunciou, sem tributo — nada foi vendido</td></tr>
  <tr><td>Fechamento com lance</td><td>O lote vai ao arrematante; o vendedor recebe líquido de tributo (mesma alíquota do recurso no Mercado Central)</td></tr>
</table>

<p>
  <?= arbitrado('todos os números acima — duração, ausência de incremento mínimo, a exigência física de já estar na doca') ?>
  Desde o <span class="d">D-136</span>, um leilão também pode vender <b>um item da Loja de Peças da
  Endurance</b> (§10.3) em vez de um recurso — a mesma máquina de escrow/lance/fechamento, só
  trocando o depósito de origem (a posse do item, não o recurso) e zerando o tributo (a Endurance
  não tem alíquota publicada).
</p>

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
  <tr><td>Honra Militar-Diplomática</td><td>Guerra e tratados <?= promessa('a guerra existe (D-66); tratados, não') ?></td></tr>
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
  <b>As duas punições que ficaram inertes desde o D-44 já mordem.</b> O <b>bloqueio de leilões</b>
  passou a valer sozinho quando os Leilões nasceram (§6.6, D-129) — eles usam a MESMA Confiança
  Comercial que já fecha o Mercado Central, sem gate novo. O <b>impedimento por federação</b>
  (conciliador não julga caso de sua própria federação, nem de uma das partes) fechou no
  <span class="d">D-115</span>. Já mordiam desde antes: a <b>restrição comercial</b> (fecha a
  saída de carga por 7 dias) e, desde o D-77, o <b>silêncio</b> — fecha os canais públicos do Chat
  pelo prazo; a privada continua. <span class="d">D-44, D-50, D-77, D-115, D-129</span>
</div>

<h3>7.3 O Chat <span class="d">D-77</span></h3>

<p>O rádio do planeta. Quatro canais hoje:</p>

<table>
  <tr><th>Canal</th><th>Escopo</th><th>Retenção</th></tr>
  <tr><td>Global</td><td>Todo o planeta</td><td>180 dias</td></tr>
  <tr><td>Vizinhança</td><td><b>Um raio, não uma sala.</b> A mensagem carrega a posição de quem falou; cada leitor vê quem está a N slots <span class="d">operador</span> DELE, não de quem falou</td><td>90 dias</td></tr>
  <tr><td>Privada</td><td>1 a 1</td><td><b>Indefinida</b></td></tr>
  <tr><td>Federação <span class="d">D-115</span></td><td>Só entre membros da mesma federação — a aba só aparece pra quem tem uma</td><td>180 dias</td></tr>
</table>

<div class="nota">
  <b>O canal Região saiu</b> <span class="d">D-104</span>. Era 100% um construto calculado
  on-the-fly da posição da colônia (4 quadrantes + Núcleo, arbitragem do D-77) — nenhum outro
  domínio do jogo o usava. As mensagens antigas de Região continuam expurgando sozinhas pelo prazo
  já publicado (180 dias); ninguém escreve lá desde então.
</div>

<div class="nota">
  <b>O silêncio "cala a praça, não a boca".</b> A punição do §9.4 fecha Global e Vizinhança; a
  privada segue funcionando. <b>O canal Federação foi deixado FORA do silêncio</b>, por leitura
  minha, não do GDD: um círculo de aliados está mais para conversa fechada do que para praça
  pública. <?= arbitrado('a isenção do canal Federação à pena de silêncio') ?>
</div>

<h3>7.4 Cargos Públicos <span class="d">D-130</span></h3>

<p>
  Depois do Conciliador (§7.2), o §14.2 nomeia mais quatro cargos cívicos. O texto existe só em
  duas revisões ARQUIVADAS do v35 que se contradizem entre si (v30 exige o checklist inteiro do
  Neutro Registrado; v32 corrige isso, "Neutro Registrado é exclusivo do Conciliador") — seguimos a
  v32, o mesmo precedente que já valia para o próprio Conciliador.
</p>

<table>
  <tr><th>Cargo</th><th>O que faz</th><th>Estado</th></tr>
  <tr><td><b>Repórter</b></td><td>Publica no mesmo mural da Central de Notícias, marcado "boletim" — distinto do comunicado oficial</td><td><?= entregue() ?></td></tr>
  <tr><td><b>Fiscal de Mercado</b></td><td>Sinaliza preço suspeito para a equipe; bônus só paga quando a equipe CONFIRMA, nunca no ato de sinalizar</td><td><?= entregue() ?></td></tr>
  <tr><td><b>Auxiliar de Tesouro</b></td><td>Aponta inconsistência financeira, mesmo desenho do Fiscal</td><td><?= entregue() ?></td></tr>
  <tr><td>Atendente do Espaçoporto</td><td>Ajuda com rotas, taxas e docas do Espaçoporto</td><td><?= promessa('100% dependente do Espaçoporto, que não existe') ?></td></tr>
</table>

<p>
  <b>Salário e bônus reaproveitam os números do Conciliador</b> (<?= n(\App\Domain\Ministry\PunicaoSpecs::SALARIO_DIARIO_MICRO / Colony::MICRO_POR_FERT) ?> F$/dia,
  <?= n(\App\Domain\Ministry\PunicaoSpecs::BONUS_MICRO / Colony::MICRO_POR_FERT) ?> F$/bônus) — é o
  único número que QUALQUER revisão do §14 publica para cargo cívico, e a v32 descreve todos os
  cinco como do mesmo porte. <?= arbitrado('teto semanal de '.n(CargosCivicosSpecs::TETO_SEMANAL_MICRO / Colony::MICRO_POR_FERT).' F$, e nomeação 100% manual do operador — mesmo caminho do Conciliador, sem gate de elegibilidade automático') ?>
</p>

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

<div class="nota">
  <b>Os 4 distritos deixaram de ser a única fonte de zona</b> <span class="d">D-148</span>. O Dono
  cria zona neutra em <b>qualquer célula de periferia</b>, escolhendo o mineral entre os quatro
  acima, pelo mapa do painel (§11.6). As 120 originais <b>não mudaram</b> — a fórmula dos distritos
  continua sendo o que semeia o mapa; só ganhou companhia. Uma zona criada assim é <b>reversível
  enquanto estiver livre</b> (o mesmo clique a remove) e <b>trava assim que tiver dono</b>: remover
  uma zona livre não afeta ninguém que já joga, e uma zona ocupada não é mais a mesma categoria de
  coisa.
  <br><br>
  <b>Consequência que valeu correção:</b> "esta célula é zona neutra?" era uma pergunta de
  <i>fórmula</i> (as 4 faixas fixas) e virou uma pergunta de <b>banco</b>. As duas travas que
  dependiam dela — não se funda em cima de zona (§1.1), e zona não pode virar célula de fundação
  (§1.1.1) — ficariam cegas para toda zona criada fora dos cantos, e a mesma célula poderia ser as
  duas coisas ao mesmo tempo.
</div>

<h3>8.0 A zona é uma colmeia de <?= ZonaSlots::TOTAL ?> slots <span class="d">D-144</span></h3>

<p>
  Até o D-144 a zona era uma <b>planta com áreas fixas</b> — a Muralha no perímetro, a Torre no alto
  — e cada estrutura era uma <i>coluna</i> da própria zona: uma linha, treze níveis, nenhum lugar. Ela
  passou a ser a <b>mesma colmeia da colônia</b>: linhas de
  <?= implode('/', ZonaSlots::LINHAS) ?>, <b><?= ZonaSlots::TOTAL ?> slots</b>, a estrutura tem
  <b>posição</b>, e estrutura não erguida não ocupa slot. O <b>slot <?= ZonaSlots::POSTO_SLOT ?></b>
  (o centro) é fixo do <b>Posto de Comando</b> — o centro pertence ao que é mais essencial, o mesmo
  critério que dá o centro da colônia ao Depósito Local (§2.1).
</p>

<div class="nota">
  <b>Nada no combate dependia de POSIÇÃO</b>, e foi isso que deixou a troca ser segura: o bônus de
  construção (§8.2) sempre leu <i>tipo + nível</i>, nunca onde a estrutura estava. A planta antiga
  era arbitragem nossa — o v35 nunca desenhou uma zona —, então o que mudou foi a semântica de
  lugar, não uma regra do documento.
</div>

<p><b>Crescimento por nível</b> — um mecanismo que a colônia <i>não</i> tem: os 22 slots dela existem
todos desde a fundação, e os da zona se abrem conforme ela sobe.</p>

<table>
  <tr><th class="num">Nível da zona</th><th class="num">Slots livres abertos</th><th>Observação</th></tr>
  <tr><td class="num">1</td><td class="num"><?= count(ZonaSlots::NIVEL1_SLOTS) ?></td><td>O bastante para uma cópia de cada uma das <?= count(Estruturas::CONSTRUIVEIS) ?> estruturas erguíveis de hoje — <b>nenhuma zona já ocupada perdeu nada</b>, em nenhum dos níveis 1–5 em que estivesse</td></tr>
  <tr><td class="num">2 a 10</td><td class="num">+1 por nível</td><td>Fecha em <?= ZonaSlots::TOTAL ?> no nível 10 — o mesmo total da colônia</td></tr>
</table>

<p>
  O <b>nível máximo da zona subiu de 5 para <?= App\Models\NeutralZone::NIVEL_MAXIMO ?></b>, pelo
  mesmo precedente (e a mesma curva 1,65) do Depósito Local no D-108.
  <?= arbitrado('o v35 não publica nem os slots, nem o teto de nível de uma zona') ?>
</p>

<p><b>Repetíveis</b> — só as <?= count(Estruturas::REPETIVEIS) ?> que processam:
  <b><?= e(implode(', ', array_map('humano', Estruturas::REPETIVEIS))) ?></b>, espelhando a mesma
  família de repetíveis da colônia (§2.3). As outras <?= count(Estruturas::TODAS) - count(Estruturas::REPETIVEIS) ?>
  continuam <b>únicas</b>, e não por acaso: as seis que a Sabotagem e a Apreensão miram (§8.9) são
  todas de defesa ou controle, e <b>nenhuma delas é repetível</b> — o ataque continua identificando o
  alvo só pelo tipo, sem a ambiguidade de "qual cópia".
</p>

<h3>8.1 A guerra <span class="d">D-66, D-70</span></h3>

<p>
  <b>Existe por inteiro.</b> Era a maior lacuna do jogo; o D-66 fechou as oito lacunas do §27 de
  uma vez, e o D-70 deu ao defensor as duas mãos que faltavam. Quatro tipos de ataque, todos sobre
  o mesmo motor de <b>rodadas de 10 minutos</b> (§27.5).
</p>

<table>
  <tr><th>Ataque</th><th>Faz</th></tr>
  <tr><td><b>Invasão Direta</b></td><td>Zera a defesa e toma a zona. Vencendo, saqueia <b>50% do exposto</b> (§27.8) — o resto fica, não é destruído (a v3.2 corrige a v3.0)</td></tr>
  <tr><td><b>Cerco</b></td><td>Bloqueia tudo que entra e sai. Após 30 min (3 rodadas) o Depósito para de aceitar — a extração continua e se <b>perde</b>. O defensor tem 48h para <b>romper</b> (mandar Sentinelas) ou <b>render-se</b> (entrega 30% do exposto)</td></tr>
  <tr><td><b>Sabotagem (Infiltrador)</b></td><td>60% de chance por rodada, se não detectado. A Torre de Vigia detecta a <b>15% × nível</b> por rodada (nível 5 = 75%). Sucesso: a estrutura-alvo perde capacidade proporcional ao nível do Infiltrador</td></tr>
  <tr><td><b>Apreensão de Módulos (Predador)</b></td><td>Desliga uma estrutura até resgate. Chance = <b>50% + 10% × (nível do Predador − nível do Abrigo de Robôs)</b>, entre 10% e 90%. Estruturas sob um <b>Bastião</b> são imunes</td></tr>
</table>

<div class="nota">
  <b>"Estoque protegido" é o que cabe no Depósito</b> (§19.6) — arbitrado no D-66. O que excede
  fica exposto na zona, sem proteção nenhuma; é isso que dá sentido a subir o Depósito.
</div>

<h3>8.2 Força ofensiva e defensiva <span class="d">D-66, §27.3</span></h3>

<table>
  <tr><th>Fórmula</th><th>Valor</th></tr>
  <tr><td>Força Ofensiva</td><td>Σ ataque das Sentinelas enviadas, vivas</td></tr>
  <tr><td>Força Defensiva</td><td>Σ defesa das unidades na zona × bônus de construção</td></tr>
  <tr><td>Dano por rodada</td><td>(Força própria / Total) × <b>15%</b> × Força do outro lado, sobre a força <b>inicial</b> do combate <?= arbitrado('não a "atual" — a redação literal do §27.5 não termina') ?></td></tr>
  <tr><td>Combate equilibrado (1000 vs 800)</td><td><b>12 rodadas</b> (120 min) — bate com o exemplo publicado no GDD</td></tr>
  <tr><td>Reforço chegando</td><td>Recongela a força — novo dano constante a partir da chegada</td></tr>
</table>

<p><b>Bônus de construção</b> (aditivo, escala <b>linear</b> pelo nível — um bônus fixo tornaria os níveis 2–5 decorativos):</p>

<table>
  <tr><th>Estrutura</th><th class="num">Bônus no nível 1</th></tr>
  <tr><td>Muralha de Perímetro</td><td class="num">+20%</td></tr>
  <tr><td>Torre de Vigia</td><td class="num">+30%</td></tr>
  <tr><td>Bastião</td><td class="num">+50%</td></tr>
</table>

<p>
  As três juntas dobram a defesa. O <b>Abrigo de Robôs não dá bônus</b> — é onde os sobreviventes
  se recolhem (§27.6) e o que o Predador precisa vencer (§28.10). Defensor genuinamente offline
  ganha <b>+20%</b>, negado se ele saiu do ar <i>depois</i> de saber do ataque.
</p>

<h3>8.3 As unidades <span class="d">§27.1, §27.2</span></h3>

<p>
  <b>Sentinela</b> — única unidade ofensiva, fabricada no Quartel. Defesa <code>100 150 225 338 506</code>,
  Ataque <code>80 120 180 270 405</code> (níveis 1–5, curva 1,65×). Custa Nióbio Alienígena
  (<code>3 5 8 13 22</code>) — e <b>nada no jogo produz Nióbio</b>: o planeta nasce com 20
  unidades (5 por colônia fundadora), e o <b>governo vende</b> a ~3,16 F$/unidade
  (Secretaria de Finanças, preço do operador). Sem isto a Sentinela seria inalcançável.
  <br><br>
  <b>Robô Minerador</b> defende a própria zona onde já trabalha, improvisado (§27.2): defesa
  <b>25% da Sentinela</b>, ataque zero. <b>Infiltrador</b> e <b>Predador</b> têm custo publicado,
  sem Nióbio.
</p>

<h3>8.4 Reforço e ruptura de cerco <span class="d">D-70</span></h3>

<div class="nota">
  <b>A tela já prometia isto antes de existir.</b> O Quartel dizia ao defensor "ainda dá tempo de
  reforçar" e não havia botão nem rota. O motor de combate já contava reforços desde o D-66 (é o
  que faz "reforços tardios podem mudar o resultado" ser verdade) — só faltava mandá-los.
</div>

<p>
  Reforço marcha <b>1,3× mais devagar</b> que civil (§27.4) e só conta ao <b>chegar</b> —
  marchando, não defende nada. <b>Não exige combate em curso</b>: guarnecer em paz é a mesma coisa
  que socorrer sob ataque. <b>Zona cercada não recebe reforço</b> — "nada entra nem sai" alcança a
  tropa; a única saída é <b>romper o cerco</b>, mandando Sentinelas para lutar fora da zona (sem
  Muralha, Torre ou Bastião). Vencendo, o cerco cai; perdendo, as 48h continuam.
</p>

<table>
  <tr><th>Regra</th><th>Valor</th></tr>
  <tr><td>Cooldown do mesmo atacante contra a mesma zona</td><td><b>48h</b> — outros atacantes não esperam</td></tr>
  <tr><td>Proteção de novato</td><td><b>8 dias completos</b> desde a primeira ocupação</td></tr>
</table>

<h3>8.5 O Drone de Exploração <span class="d">D-74</span></h3>

<p>
  Fabricado na <b>Oficina</b> (não no Quartel — o Quartel só guarda e recarrega, §21.4), até o
  nível dela. Velocidade fixa em <b>8 slots/min</b> em todos os níveis; o que sobe é o alcance e a
  bateria.
</p>

<table>
  <tr><th>Nível</th><th class="num">Raio de revelação</th><th class="num">Bateria</th></tr>
  <tr><td>1</td><td class="num">6 slots</td><td class="num">24 h</td></tr>
  <tr><td>2</td><td class="num">9 slots</td><td class="num">36 h</td></tr>
  <tr><td>3</td><td class="num">13 slots</td><td class="num">54 h</td></tr>
  <tr><td>4</td><td class="num">20 slots</td><td class="num">81 h</td></tr>
  <tr><td>5</td><td class="num">30 slots</td><td class="num">122 h</td></tr>
</table>

<p><b>Duas missões</b> (§21.4):</p>
<ul>
  <li><b>Foto</b> — ida e volta; a imagem fica <b>datada</b> e permanente ("vista há 3h") — envelhece honestamente, nunca finge ser atual.</li>
  <li><b>Vigilância</b> — ida simples; transmite ao vivo até a bateria acabar, tira uma última foto e volta sozinho.</li>
</ul>

<div class="nota">
  <b>A névoa do interior alheio nasceu com o Drone.</b> O que qualquer um calcula (posição,
  mineral, dono, nível, status) sempre foi público. O que só se sabe por dentro — guarnição e o
  que está no Depósito — vem <code>null</code> (não zero) para quem não mandou um Drone: zero é
  um fato ("está indefesa"), <code>null</code> é honestidade de não saber. Zona livre não tem o
  que esconder — só quem já foi ocupada guarda segredo.
</div>

<h3>8.6 Upgrade de nível e manutenção territorial <span class="d">D-84</span></h3>

<p>
  Fecha as duas últimas lacunas da Fatia 1 (D-52): teto de zonas por jogador e upgrade de nível —
  e ativa pela primeira vez a manutenção territorial que o §27.12 já previa, e que nunca havia
  cobrado nada de nenhuma zona.
</p>

<ul>
  <li><b>Teto de 5 zonas por jogador</b> <?= arbitrado('o GDD não publica número') ?>.</li>
  <li><b>Upgrade de 1 a 5</b>: sobe a extração (já seguia a curva do §19.1) e a capacidade do
      Depósito. Custo debitado direto da colônia, como a ocupação — não do canteiro.</li>
  <li><b>Manutenção territorial (§27.12) ativada pela primeira vez</b>: custo diário por nível,
      cobrado da colônia. Sem pagar 24h, a Força Defensiva decai 5%/dia; sem pagar 72h, a zona é
      <b>abandonada automaticamente</b> — reset completo, para não abrir lavagem de zona entre
      contas do mesmo jogador.</li>
</ul>

<h3>8.7 A zona em cinco abas, e o Histórico <span class="d">D-86</span></h3>

<p>
  A tela da zona reorganizou em cinco abas — Zona Neutra (identidade, a colmeia do §8.0, upgrade),
  Depósito, Canteiro de obras, Guarnição, Histórico — e o Canteiro passou a perguntar <b>qual
  obra</b> antes de pedir o recurso, mostrando só o que ela precisa. Isso é interface, não regra
  nova.
</p>

<div class="nota">
  <b>Desde o D-144, tudo na zona se identifica por SLOT, não por tipo</b> — erguer, demolir e
  mandar material para o canteiro. "Clique na Muralha" deixou de fazer sentido sozinho no momento em
  que uma estrutura repetível pôde ter duas cópias em dois lugares (§8.0).
</div>

<p>
  O <b>Histórico</b> é conteúdo novo: uma linha do tempo da zona, só para o dono, juntando três
  fontes — o <code>ledger</code> filtrado por <code>zona:{id}:</code> (ocupação, upgrade,
  manutenção, saque), os <code>Combat</code> daquela zona (invasões, cercos, sabotagens) e
  <code>zone_events</code> (posse: ocupação, abandono, conquista) — uma tabela que não existia
  antes, porque o estado da zona sempre foi só o presente, nunca um registro de como se chegou
  nele.
</p>

<h3>8.8 O canteiro de obras ganha teto, e vira saqueável <span class="d">D-122</span></h3>

<p>
  Uma revisão pedida pelo usuário achou o canteiro de obras (<code>zone_materials</code>) como um
  <b>depósito paralelo</b>: sem teto, imune a Invasão e Cerco — ao contrário do Depósito de verdade
  (§8.1). Fechado: o canteiro passou a usar a MESMA capacidade do Depósito, e o que não coube numa
  entrega volta na carroceria, sem tributo — o mesmo padrão do Mercado Central (§6.3). No saque, o
  canteiro entra à parte, <b>sempre 100% exposto</b> (não é minério extraído, é material à espera
  de virar construção) — perde a mesma fração do resto do saque (50% Invasão, 30% Cerco).
</p>

<div class="nota">
  <b>Um segundo achado, mais grave: o abandono vazava obra e upgrade em curso.</b> Reocupar uma
  zona abandonada com OUTRA conta do mesmo jogador herdava a obra que já estava no canteiro — a
  mesma lavagem que o §04 já fecha para veículo (D-73), só que para zonas. Corrigido: abandonar
  apaga canteiro e fila de obras junto com o resto do reset.
</div>

<h3>8.9 Sabotagem e Apreensão passam a valer de verdade <span class="d">D-118</span></h3>

<p>
  Um bug real, achado numa revisão: a Sabotagem e a Apreensão de Módulos (§28.10) marcavam um
  selo na tela e <b>nada mais</b> — o bônus de construção, a detecção da Torre, a resistência do
  Abrigo, a capacidade do Depósito, todos liam o nível cru da estrutura, ignorando se ela estava
  desligada. O resgate automático de 24h que o próprio texto já previa também nunca rodava.
</p>

<table>
  <tr><th>Ataque</th><th>Efeito</th></tr>
  <tr><td>Sabotagem (Infiltrador)</td><td><b>Proporcional</b> ao nível de quem sabotou — nível 5 equivale a 0% de capacidade. Reparo pago pelo dono limpa na hora</td></tr>
  <tr><td>Apreensão (Predador)</td><td><b>Binária</b> — desliga até resgate. Repara sozinha em <b>24h</b>, ou o dono paga para reaver antes</td></tr>
</table>

<p>
  Custo de reparo/resgate: <?= arbitrado('uma fração do custo de CONSTRUÇÃO da estrutura no nível atual, mesmo padrão da manutenção de veículos do Ministério (§5.3), ajustável no painel da Guerra') ?>.
  Estruturas sob um Bastião são imunes só à Apreensão — a Sabotagem não é bloqueada, como o próprio
  GDD distingue.
</p>

<h3>8.10 Demolição de estrutura de zona <span class="d">D-138</span></h3>

<p>
  Uma assimetria real com a colônia (§2.4), nunca decidida — só nunca tinha sido levantada: as 12
  estruturas erguíveis de uma zona (todas menos o Posto de Comando, indemolível) podem ser
  demolidas de volta ao nível 0, com a mesma palavra de confirmação que a colônia já exige. O
  investido não volta — nenhum material do canteiro é devolvido. <b>A manutenção territorial NÃO
  cai</b>: ela é função só do nível da zona (§8.6), nunca leu o nível das 12 estruturas — demolir
  uma delas não move um número que já não dependia dela. Bloqueado sob cerco e com obra em curso na
  mesma estrutura.
</p>

<h3>8.11 O Ranking de Guerras <span class="d">D-128, §27.13</span></h3>

<p>
  O §27.13 publica a fórmula por inteiro, com exemplo numérico — cada sub-ranking vira um
  "percentil" (0–100) dividindo o valor do jogador pelo MÁXIMO do servidor; o Ranking Geral é a
  soma ponderada. Cinco dos seis sub-rankings entraram, todos lendo dado que já existia:
</p>

<table>
  <tr><th>Sub-ranking</th><th class="num">Peso</th><th>Lê de</th></tr>
  <tr><td>Zonas Neutras Conquistadas</td><td class="num">25%</td><td>Conquista por guerra (não ocupação de zona livre)</td></tr>
  <tr><td>Vitórias Totais</td><td class="num">20%</td><td>A mesma régua de "vitória" que já paga XP do Marco</td></tr>
  <tr><td>Tempo de Controle</td><td class="num">20%</td><td>Histórico de posse da zona (§8.7)</td></tr>
  <tr><td>Recursos Saqueados em Fert$</td><td class="num">15%</td><td>Ledger de saque, convertido pelo preço-base do catálogo</td></tr>
  <tr><td>Maior Sequência</td><td class="num">10%</td><td>A mesma régua de vitória, em série</td></tr>
  <tr><td>Guerras Vencidas (Federação)</td><td class="num">10%</td><td><?= promessa('o jogo não tem "guerra de federação" — todo combate é entre DUAS COLÔNIAS') ?></td></tr>
</table>

<p>
  <b>Os cinco pesos publicados somam 90, não 100 — não renormalizamos</b>: é a régua do documento,
  não correção nossa. Tela em <code>GET /war/ranking</code>, dentro do Quartel.
</p>

<!-- ══════════════════════════════════════════════════════════════ 9 -->
<h2 id="s9">9. A Federação</h2>

<div class="nota grave">
  <b>O GDD se contradiz entre duas revisões, das poucas vezes em que a divergência é genuína, não
  um documento incompleto.</b> A v3.0 (§04 "Sistema de Federações") e a v3.2 "regra definitiva"
  (dentro do §07) discordam em dois pontos, e o usuário decidiu os dois — não pela regra de
  precedência automática (§ maior na mesma parte), porque aqui as duas partes descrevem sistemas
  diferentes o bastante para merecerem decisão própria.
</div>

<table>
  <tr><th>Ponto de divergência</th><th>v3.0 diz</th><th>v3.2 "definitiva" diz</th><th>Decisão do usuário</th></tr>
  <tr><td>Desconto de tributo entre aliados</td><td>50% automático</td><td>Sem desconto — só obrigações e defesa</td><td><b>v3.0</b> — 50%, ver §9.5</td></tr>
  <tr><td>Como o fundo recebe contribuição</td><td>Taxa automática, 1–10% da produção diária</td><td>"Armazém/fundo por rota física" — soa como entrega manual</td><td><b>v3.2</b> — entrega física, mesma logística do resto do jogo</td></tr>
</table>

<h3>9.1 O núcleo <span class="d">D-114</span></h3>

<p>
  Fundar, convidar ou pedir entrada, aceitar/recusar, sair, transferir liderança, expulsar, alterar
  cargo, dissolver. <b>Quatro cargos</b> (a v3.2 completa o que a v3.0 só citava dois): Líder,
  Diplomata, Intendente, Membro. <b>Teto de 12 colônias por federação.</b> Uma colônia pertence a
  no máximo uma federação por vez.
</p>

<p>
  O <b>fundo</b> (<code>federation_holdings</code>/<code>federation_ledger</code>, espelho do
  Tesouro — §3.3) recebe contribuição por <b>entrega física</b>: um veículo despachado até a
  Capital, mesma logística de sempre, tributado NORMALMENTE (o desconto do §9.5 vale só entre duas
  colônias, não na entrega ao próprio fundo). Saque é só administrativo, pelos cargos com permissão.
</p>

<div class="nota">
  <b>Se a federação dissolve, o saldo do fundo vai para o Tesouro</b> — não para quem saiu por
  último. Evita o exploit óbvio (expulsar todo mundo e embolsar o fundo sozinho) e segue a
  convenção de sempre: valor não reclamado cai no Tesouro. <?= arbitrado('') ?>
</div>

<h3>9.2 O limite antimonopólio <span class="d">D-119, §04</span></h3>

<p>
  O §04 diz "20% → 10%, dinâmico" e não publica de quê, nem o gatilho da transição. Decisão: mede a
  fatia de <b>todas as zonas neutras ocupadas do jogo</b> que a federação detém; cruzar o teto
  bloqueia a PRÓXIMA ocupação (zonas já detidas não são tocadas); os dois estágios viram um
  <b>teto fixo</b>, ajustável no painel.
</p>

<p><b>Teto hoje: <?= n($fedConfig->teto_ocupacao_zonas_bps / 100) ?>%</b> das 120 zonas do jogo, editável em Federações → sem deploy.</p>

<h3>9.3 Desconto de tributo entre aliados <span class="d">D-120, §04/§07</span></h3>

<p>
  A v3.0 publica o número com todas as letras: <b><?= n($fedConfig->desconto_tributo_aliados_bps / 100) ?>%</b>
  de desconto — mora no painel mesmo assim, pelo mesmo precedente do §9.2. Vale só entre
  <b>DUAS colônias federadas</b> na mesma federação, entrega física entre elas — nunca na
  contribuição ao próprio fundo (§9.1), e nunca quando origem e destino são a MESMA colônia
  (retirada do próprio depósito no Mercado Central não "desconta de si mesma").
</p>

<h3>9.4 Apoio de aliado, missões cooperativas, e o alerta de cerco <span class="d">D-115, D-116</span></h3>

<ul>
  <li><b>Romper cerco (§28.10)</b>: não é mais só o dono da zona — qualquer colônia da MESMA
      federação pode mandar Sentinelas para socorrer. Quem luta, ganha o crédito de XP e missão.</li>
  <li><b>Missões "Federação"</b> (§2.6): 2 por semana, cooperativas — <b>uma linha por
      colônia-membro</b>, "irmãs" do mesmo objetivo, com o MESMO progresso espelhado entre todas.
      Cruzou a meta, todas concluem e todas são pagas.</li>
  <li><b>Central de Comunicação da zona</b> (nível ≥ 1): aliados da mesma federação veem a zona AO
      VIVO, sem gastar vigia de Drone, e recebem um aviso pelo rádio (conta "Federação") assim que
      ela entra em cerco.</li>
  <li><b>Canal de chat "Federação"</b> (§7.3): só entre membros, retenção de 180 dias.</li>
</ul>

<h3>9.5 O impedimento do Ministério, fechado <span class="d">D-115, §26.8</span></h3>

<p>
  O conciliador agora também é impedido de julgar um caso quando ele OU uma das partes é da mesma
  federação do outro — a metade que o §26.8 previa e ficava inerte desde o D-44 (§7.2).
</p>

<!-- ══════════════════════════════════════════════════════════════ 10 -->
<h2 id="s10">10. A Endurance</h2>

<div class="nota grave">
  <b>O GDD só nomeia a Endurance — "fonte de peças históricas e missões narrativas" (§02),
  "patrimônio histórico" (§16.2) — e nunca descreve NENHUMA das duas.</b> Nem preço, nem efeito,
  nem capítulo, nem número. A primeira versão (D-132/D-133: 8 seções × 4 camadas FIXAS, um efeito
  só — desconto de tributo) foi rejeitada pelo próprio usuário ao revisar ("as 4 camadas não se
  diferenciam o bastante", D-134) e refeita do zero no <span class="d">D-135</span>.
</div>

<h3>10.1 O casco: 8 seções, cada uma com destroço próprio <span class="d">D-132</span></h3>

<p>
  A área Oeste da Capital (§1.2) mostra os destroços da nave-mãe em 8 seções clicáveis — Comando,
  Anel Habitacional, Matriz de Comunicação, Baía Criogênica, Módulo Médico, Seção de Acoplagem,
  Silo de Suprimentos, Núcleo de Propulsão. Cada uma abre a Loja de Peças SÓ daquela seção — um
  catálogo dinâmico, não mais 8 grupos numa tela só.
</p>

<h3>10.2 O catálogo dinâmico, e os efeitos que mexem no motor de verdade <span class="d">D-135</span></h3>

<p>
  O admin cria/edita/apaga itens à vontade pelo painel — nada de linha fixa. Cada item tem tipo
  (comum/raro/único), estoque GLOBAL do servidor (não por colônia), preço em Fert$, marco mínimo
  OPCIONAL, e uma lista de efeitos EMPILHÁVEIS — um item pode ter vários. Hoje o catálogo tem
  <b><?= $enduranceItensCount ?></b> item(ns) cadastrado(s).
</p>

<table>
  <tr><th>Tipo de efeito</th><th>Alvo</th><th class="num">Teto agregado</th></tr>
  <?php foreach (EfeitosDaEndurance::TIPOS as $tipoEfeito):
      $exigeAlvo = in_array($tipoEfeito, EfeitosDaEndurance::EXIGE_ALVO, true);
  ?>
  <tr>
    <td><code><?= e($tipoEfeito) ?></code></td>
    <td><?= $exigeAlvo ? 'building_type ou veículo, ou "global"/"todos"' : '— (sem alvo)' ?></td>
    <td class="num"><?= n(EfeitosDaEndurance::tetoBps($tipoEfeito) / 100) ?>%</td>
  </tr>
  <?php endforeach; ?>
</table>

<p>
  Cada efeito soma <code>valor_bps × quantidade possuída</code>, entre TODOS os itens da colônia
  que o têm, capado pelo teto da tabela acima. <b>Produção em construção sem insumo é bônus de
  graça; em construção de conversão (Destilaria, Indústria Siderúrgica, Refinaria Química,
  Oficina) é THROUGHPUT</b> — mais saída, mas também mais insumo consumido, proporcionalmente.
  <?= arbitrado('os 6 tipos de efeito, os tetos por tipo, e a distinção grátis/throughput') ?>
</p>

<h3>10.3 Leilões vendendo item <span class="d">D-136</span></h3>

<p>
  Um item marcado "vendável em leilão" pode ir a leilão (§6.6) como qualquer recurso — mesma
  máquina de escrow/lance/fechamento, tributo zerado (a Endurance não tem alíquota publicada).
</p>

<h3>10.4 O Manual dos Benefícios <span class="d">D-137</span></h3>

<p>
  O painel de admin ganhou uma aba de documentação — formato de linha
  (<code>tipo_efeito:valor_bps</code> ou <code>tipo_efeito:alvo:valor_bps</code>, 100 bps = 1%),
  os alvos válidos por tipo, os tetos, e a distinção grátis/throughput — para o operador arbitrar
  um item novo sem precisar ler código.
</p>

<h3>10.5 As missões narrativas <span class="d">D-140</span></h3>

<p>
  A promessa do §02 que nunca foi cumprida. Quatro capítulos, encadeados por
  <code>requer_template_id</code> — o capítulo N+1 só chega à mão da colônia quando o N está
  concluído, o primeiro encadeamento do motor de Missões (§2.6). Sem ciclo: não sorteia, não
  expira.
</p>

<table>
  <tr><th>#</th><th>Capítulo</th><th>Ação</th></tr>
  <tr><td>1</td><td>O Primeiro Achado</td><td>Comprar 1 item da Loja de Peças (gancho novo)</td></tr>
  <tr><td>2</td><td>O Preço da Escavação</td><td>3 negócios no Mercado Central</td></tr>
  <tr><td>3</td><td>Reconstrução</td><td>2 níveis de construção concluídos</td></tr>
  <tr><td>4</td><td>O Legado da Endurance</td><td>2 despachos</td></tr>
</table>

<p>
  <?= arbitrado('tema, ordem e recompensa dos 4 capítulos — o GDD só dá o rótulo "missões narrativas", nenhum conteúdo') ?>
  Só o capítulo 1 ganhou uma ação dedicada (<code>comprar_item_endurance</code>); os demais
  reaproveitam ações genéricas já existentes, tematizadas como a "escavação" continuando.
</p>

<!-- ══════════════════════════════════════════════════════════════ 11 -->
<h2 id="s11">11. Operação e administração</h2>

<p>
  Fora do escopo do v35, que descreve o <i>jogo</i> e não a <i>ferramenta</i>. Está aqui porque quem
  opera o jogo precisa saber que existe. <span class="d">D-56, D-61</span>
</p>

<h3>11.1 A auditoria</h3>

<div class="nota">
  <b>O painel de administração era o único lugar do sistema onde se podia criar valor sem deixar
  história.</b> Julgar um caso, distribuir 10.000 F$ do Tesouro, disparar um tick: nada disso ficava
  registrado. O <code>ledger</code> auditava a economia; <b>nada auditava a administração</b>.
  <br><br>
  Desde o D-61, <b>todo ato de admin</b> grava quem, quando, o quê, sobre quem, os <b>valores antes e
  depois</b>, o IP e o navegador — mais os <b>logins que falharam</b>. <b>Append-only</b>: nem o admin
  apaga.
</div>

<h3>11.2 Os dois papéis</h3>

<table>
  <tr><th>Papel</th><th>Pode</th></tr>
  <tr><td><b>dono</b></td><td>Tudo. Gere admins e <b>realoca colônias</b>.</td></tr>
  <tr><td><b>operador</b></td><td>Julga casos, publica notícias, distribui o Tesouro; nos jogadores, vê, <b>suspende</b> e <b>corrige estado</b>.</td></tr>
</table>

<p>
  <b>A suspensão</b> barra o acesso, revoga os tokens e congela <b>só o comércio</b> — reusando a
  restrição do §9.4. A colônia <b>continua produzindo</b>: o mundo não para, e nada se perde.
</p>

<h3>11.3 O que o operador arbitra sem deploy</h3>

<p>
  Um número que só existe em código exige um deploy para mudar — e um deploy é risco e demora que
  nem sempre a decisão merece. Cada vez mais desses números viraram linhas de banco, editáveis em
  <code>/central/admin</code>, sem tocar em código:
</p>

<ul>
  <li><b>O Marco</b> — os cinco valores de XP por ato <span class="d">D-75</span>.</li>
  <li><b>O Ministério dos Transportes</b> — a curva de depreciação, o piso de desempenho, o custo
      de manutenção, o teto de revenda, o frete público, e desde o D-109 a <b>Fábrica</b> (preço,
      estoque-alvo, tempo e custo de cada veículo) <span class="d">D-60, D-73, D-76, D-109</span>.</li>
  <li><b>O kit inicial</b> — Fert$, os 26 recursos e a frota de toda colônia nova
      <span class="d">D-92</span>, ver §3.2.1.</li>
  <li><b>O Governo no Mercado Central</b> — o que o Tesouro tem à venda, e por quanto
      <span class="d">D-87</span>, ver §6.2.1.</li>
  <li><b>Gestão de Construções</b> <span class="d">D-108, D-111, D-112</span> — três sub-abas
      novas: <b>Tempo/Custo</b> (sobrepõe a curva do GDD por construção e nível, sem apagar a
      base), <b>Silo</b> (a capacidade por recurso e nível do Depósito Local, §2.2), e
      <b>Manutenção</b> (o consumo extra por hora, por tipo de construção, §4.3). Uma quarta,
      <b>Fila</b>, define quantas obras cabem ao mesmo tempo — na colônia (2 vagas de partida) e
      na zona neutra (1 obra simultânea de partida, antes cravado no código).</li>
  <li><b>Subsídios</b> <span class="d">D-113</span> — o antigo "Enviar Recursos" (um recurso por
      vez) virou dois modos: vários recursos de uma vez para um colono, ou a mesma cesta para
      TODOS os colonos fundados — os dois todo-ou-nada, para nunca entregar pela metade.</li>
  <li><b>A Federação</b> — o teto antimonopólio e o desconto de tributo entre aliados
      <span class="d">D-119, D-120</span>, ver §9.2/§9.3.</li>
</ul>

<h3>11.4 O painel em abas, e o que o jogador ganhou de volta <span class="d">D-93 a D-99</span></h3>

<p>
  Quatro telas do painel — Economia, Transportes, Visão Geral, Missões — separadas em abas: cada
  uma crescia sem parar a cada seção nova, e empurrava tudo para baixo da dobra. Economia ganhou
  <b>Ofertas Globais</b> (o livro do Mercado Central inteiro, todo colono e o Governo, numa lista
  só) e os dois extratos (§3.3). Transportes ganhou busca por Dono e ordenação por cabeçalho na
  Frota do Planeta. Missões ganhou uma visão geral do catálogo — por molde, quantas vezes foi
  sorteado e como terminou.
</p>

<p>
  Do lado do jogador: um formulário de <b>Bugs/Melhorias</b> ao lado do Chat, para reportar bug,
  sugestão ou dúvida — os dados de jogador/colônia/e-mail são anexados pelo servidor, e se o
  Governo responder, o aviso chega pelo rádio, remetente "Capital" (a mesma conta de sistema do
  D-91). Sem tela de acompanhamento: só o envio, e a resposta pelo canal que o jogador já olha.
</p>

<h3>11.5 Federações e Cargos Públicos, do lado do operador <span class="d">D-114, D-130</span></h3>

<p>
  Nova aba <b>Federações</b>: leitura de todas as federações, membros, fundo e extrato, mais uma
  alavanca de emergência — "Dissolver" (exige escrever <code>DISSOLVER</code>, mesma palavra-padrão
  do resto do painel). Sem criar federação nem mover membro pelo admin: o operador intervém no
  extremo, não no meio do fluxo do jogador.
</p>

<p>
  Os três Cargos Públicos novos (§7.4) são nomeados por comando — <code>fertways:cargo-civico</code>,
  o mesmo caminho do Conciliador (<code>fertways:conciliador</code>) — porque o §14.2 exige um
  índice de reputação "alto" e nenhuma revisão do GDD publica o número; inventar um limiar seria
  arbitragem sem base nenhuma. A confirmação de uma sinalização do Fiscal/Auxiliar, que libera o
  bônus, também é por comando (<code>--confirmar-sinalizacao=</code>).
</p>

<h3>11.6 O mapa do painel, e as três ações que vivem nele <span class="d">D-145 a D-148</span></h3>

<p>
  A única visão espacial do painel eram duas tabelas com <code>(x, y)</code> escrito como texto —
  quem investigava um caso de suporte não conseguia <i>ver</i> o planeta. A aba <b>Mapa</b> desenha a
  grade <b><?= MapaFertways::LADO ?>×<?= MapaFertways::LADO ?></b> inteira, com todas as colônias e
  todas as zonas, <b>sem névoa</b>: é ferramenta interna, e o mapa do jogador já não tem névoa
  nenhuma. Clicar numa colônia abre uma ficha rápida em modal (jogador, colônia, zonas ocupadas), com
  atalho para a ficha completa.
</p>

<p>
  Dela partem <b>três ações</b>, e as três são <b>só do Dono</b> — a mesma régua que a realocação já
  tinha, pela mesma razão: mexem na distância, que é o eixo de toda a logística, e afetam o mundo de
  outros jogadores.
</p>

<table>
  <tr><th>Ação</th><th>Como</th><th>Fricção</th></tr>
  <tr>
    <td><b>Mover Colônias</b> <span class="d">D-146</span></td>
    <td>Um clique na colônia, um clique no destino. <b>Uma de cada vez</b> — não há lote</td>
    <td>Confirmação explícita mostrando origem→destino, com a palavra <code>REALOCAR</code>, motivo escrito e auditoria</td>
  </tr>
  <tr>
    <td><b>Liberar Fundação</b> <span class="d">D-147</span></td>
    <td>Um clique liga ou desliga a célula de periferia (§1.1.1)</td>
    <td><b>Nenhuma</b> — não mexe em ninguém que já joga e um segundo clique desfaz. Auditado assim mesmo</td>
  </tr>
  <tr>
    <td><b>Criar Zona Neutra</b> <span class="d">D-148</span></td>
    <td>Um clique, e o Dono escolhe o mineral (§8). Clicar de novo remove, enquanto a zona estiver livre</td>
    <td>Um passo a mais só para o <b>mineral</b> — não por risco, mas porque é uma informação que só o Dono tem</td>
  </tr>
</table>

<div class="nota">
  <b>Um botão que remaneja o planeta inteiro é perigoso demais para viver ao lado do "Disparar
  tick".</b> Existiu um "Realocar founders" que movia <i>todas</i> as colônias de uma vez, e foi
  retirado de propósito em 2026-07-13. "Mover Colônias" não o reabre: continua estritamente
  um-de-cada-vez. <span class="d">D-146</span>
  <br><br>
  <b>Nenhuma das três ganhou domínio novo.</b> Mover reusa a mesma <code>RealocarColonia</code> do
  D-61 por uma terceira porta de entrada — o domínio nunca soube (nem precisa saber) de onde veio o
  pedido. As duas ferramentas novas são espelhos auto-auditados dela, e as três recusam Capital,
  disco de founders, anel livre e célula já ocupada, no <b>servidor</b> — a checagem no navegador
  existe só para responder na hora, nunca como a trava de verdade.
</div>

<!-- ══════════════════════════════════════════════════════════════ 12 -->
<h2 id="s13">13. População</h2>

<p>
  <b>O sistema que mais mudou o jogo entre o v39 e o v40</b>, e o que mais exigiu medida antes de
  ser ligado. A população não é decoração demográfica: ela é <b>mão de obra</b>, e é isso que ela
  restringe. <span class="d">D-167 a D-179, D-184</span>
</p>

<div class="nota">
  <b>População é mão de obra, não bocas a alimentar.</b> A decisão está no D-177 e ela governa todo
  o resto: o consumo per capita é <b>tempero</b> (~3% da produção), e a pressão vem do
  <b>teto habitacional</b> e dos <b>operadores</b>, não da fome. O §7.2 do balanceamento proíbe
  literalmente <i>"virar 'The Sims' dentro de Fertways"</i>. <span class="d">D-177</span>
</div>

<h3>13.1 O teto habitacional</h3>

<p>
  Quem abriga é a <b>Estrutura de Sobrevivência</b> — a construção que o catálogo descrevia como
  <i>"efeito: nenhum"</i> até esta leva. A capacidade é
  <b><?= (int) $pop->capacidade_base ?></b> no nível 1, multiplicada por
  <b><?= n($pop->capacidade_fator_milesimos / 1000, 2) ?>×</b> a cada nível.
</p>

<table>
  <tr><th class="num">Nível</th><th class="num">Colonos abrigados</th></tr>
  <?php
  $capNivel = (float) $pop->capacidade_base;
  for ($i = 1; $i <= 5; $i++):
      if ($i > 1) { $capNivel = $capNivel * $pop->capacidade_fator_milesimos / 1000; }
  ?>
  <tr><td class="num"><?= $i ?></td><td class="num"><?= (int) floor($capNivel) ?></td></tr>
  <?php endfor; ?>
</table>

<div class="nota grave">
  <b>O total pode passar do teto — e isso é regra, não defeito.</b> O grandfathering do §6.7
  concedeu a quem já tinha construções mais colonos do que a Estrutura abriga, porque
  <i>"nenhuma colônia existente pode parar de produzir por uma regra que não existia quando ela foi
  construída"</i>. Acima do teto <b>não entra mais ninguém</b>, e <b>ninguém morre</b>: a população
  simplesmente para de crescer. <span class="d">D-178</span>
</div>

<h3>13.2 Operadores</h3>

<p>
  Toda construção produtora e toda zona neutra exigem <b>operadores</b> — colonos comprometidos, que
  saem do bolo de gente livre da colônia. A regra é <b>1 operador por nível</b> da construção
  produtora, escolhida por <b>legibilidade</b>: <i>"uma Fazenda nível 3 pede 3 operadores"</i> é uma
  frase que se entende; <i>"pede 6"</i> já é planilha. E o §7.4 pede literalmente
  <i>"poucos humanos"</i>. <span class="d">D-176</span>
</p>

<table>
  <tr><th>Construção</th><th class="num">Nível</th><th class="num">Operadores</th></tr>
  <?php foreach ($operadoresAmostra as $o): ?>
  <tr>
    <td><?= e(humano($o->building_type)) ?></td>
    <td class="num"><?= $o->level ?></td>
    <td class="num"><?= $o->operadores ?></td>
  </tr>
  <?php endforeach; ?>
</table>

<p>
  A <b>zona neutra</b> tem tabela própria, mais pesada porque território é compromisso:
</p>

<table>
  <tr><th class="num">Nível da zona</th><th class="num">Operadores exigidos</th></tr>
  <?php foreach ($zonaOperadores as $nivel => $quantos): ?>
  <tr><td class="num"><?= (int) $nivel ?></td><td class="num"><?= (int) $quantos ?></td></tr>
  <?php endforeach; ?>
</table>

<div class="nota">
  <b>Zona desfalcada degrada; não se perde.</b> Se a população cair abaixo do exigido, a zona
  <b>extrai menos</b>, com piso — e <b>continua sendo do dono</b>. O custo de manutenção
  <b>não cai junto</b>, e essa assimetria é deliberada: é o que torna a falta de operadores um
  problema econômico em vez de um "rende menos" neutro. Perder território por escassez seria a
  punição irreversível que o §6.4 proíbe. <span class="d">D-184</span>
</div>

<div class="nota">
  <b>Alocar e devolver são instantâneos.</b> Colono em trânsito seria sistema novo — o GDD não
  publica tempo de deslocamento de pessoas, e inventá-lo duplicaria a logística que já existe para
  carga. A decisão que a fase quer é <i>"quais zonas consigo manter operando"</i>, e ela existe
  inteira sem trânsito. ⚠️ Isto <b>estreita</b> uma entrega, e por isso está escrito.
  <span class="d">D-184</span>
</div>

<h3>13.3 Consumo, crescimento e escassez</h3>

<table>
  <tr><th>Parâmetro</th><th class="num">Valor</th></tr>
  <tr><td>Água por colono/hora</td><td class="num"><?= n($pop->agua_milli_por_colono_hora / 1000, 3) ?></td></tr>
  <tr><td>Oxigênio por colono/hora</td><td class="num"><?= n($pop->oxigenio_milli_por_colono_hora / 1000, 3) ?></td></tr>
  <tr><td>Biomassa por colono/hora</td><td class="num"><?= n($pop->biomassa_milli_por_colono_hora / 1000, 3) ?></td></tr>
  <tr><td>Energia por colono/hora</td><td class="num"><?= n($pop->energia_milli_por_colono_hora / 1000, 3) ?></td></tr>
  <tr><td>Crescimento por hora</td><td class="num"><?= n($pop->crescimento_bps_hora / 100, 2) ?>%</td></tr>
  <tr><td>Suprimento mínimo para crescer</td><td class="num"><?= n($pop->crescimento_min_suprimento_bps / 100, 0) ?>%</td></tr>
  <tr><td>Piso de eficiência na escassez</td><td class="num"><?= n($pop->escassez_eficiencia_bps / 100, 0) ?>%</td></tr>
</table>

<div class="nota grave">
  <b>⚠️ A energia está em ZERO, e a razão vale mais do que o número.</b> Com ela na cesta,
  <b>17 das 29 colônias</b> cairiam para metade da produção de uma vez — e não por escassez, mas por
  <b>dupla contagem</b>: energia é estoque <i>e</i> fluxo, o Reator credita e <b>toda construção já
  debita o consumo operacional</b>. Uma colônia que gasta o que produz fica com estoque zero, e esse
  é o estado <b>normal</b> de quem opera. O §6.7 proibiria mesmo que o desenho fosse desejável.
  <span class="d">D-184</span>
</div>

<p>
  A <b>escassez degrada, não mata</b> (§6.6): a eficiência interpola entre o piso e 100% pela razão
  do recurso <b>mais escasso</b> — o gargalo manda, não a média. Uma colônia nadando em água e sem
  oxigênio teria razão "boa" pela média e continuaria crescendo rumo à asfixia.
</p>

<div class="nota">
  <b>Medido em campo: a penalidade nunca disparou.</b> Um prédio essencial de nível 1 rende
  <b>10,8× o consumo máximo teórico</b> da colônia inteira, e a folga mínima do mundo até a
  penalidade era de <b>40 dias</b>. Arbitrado com o usuário: <b>escassez de população é rede de
  segurança, não pressão econômica</b> — ela existe para a colônia que seca de verdade (guerra,
  cerco, produção destruída). <b>Não reabrir sem decisão nova.</b> <span class="d">D-219</span>
</div>

<h3>13.4 A colônia nasce povoada</h3>

<p>
  Toda colônia nova nasce com <b>gente suficiente para operar o que recebe</b>, pela mesma conta do
  grandfathering do §6.7. Não é generosidade: o crescimento é <b>multiplicativo</b>, e uma colônia
  que nascesse com zero colonos <b>nunca sairia de zero</b> — ficaria para sempre sem poder ocupar
  zona, alocar operador ou erguer o que exige equipe. <span class="d">D-225</span>
</p>

<h2 id="s14">14. Pesquisa</h2>

<p>
  O Laboratório deixa de ser um prédio sem função. A pesquisa é <b>trilha</b>: uma tecnologia abre a
  seguinte, custa recursos e tempo, e o efeito <b>mexe no motor</b> — não é número de vitrine.
  <span class="d">D-168 a D-172, D-190</span>
</p>

<table>
  <tr><th>Tecnologia</th><th>Trilha</th><th class="num">Nível máx.</th><th class="num">Laboratório mín.</th><th class="num">Duração</th></tr>
  <?php foreach ($tecnologias as $t): ?>
  <tr>
    <td><?= e($t->nome) ?></td>
    <td><?= e($t->trilha) ?></td>
    <td class="num"><?= (int) $t->nivel_maximo ?></td>
    <td class="num"><?= (int) $t->laboratorio_minimo ?></td>
    <td class="num"><?= n($t->duracao_segundos / 3600, 1) ?> h</td>
  </tr>
  <?php endforeach; ?>
</table>

<div class="nota">
  <b>A especialização É a trilha de pesquisa</b>, e não um sistema paralelo. O perfil econômico da
  colônia é <b>calculado</b> do que ela pesquisou e produz — o jogador não escolhe um rótulo num
  menu. <span class="d">D-172</span>
</div>

<div class="nota grave">
  <b>⚠️ A vaga do Laboratório é perdida para sempre se a pesquisa não concluir.</b> O
  <code>ColonyTick</code> conclui a pesquisa vencida <b>antes</b> de calcular produção — e por
  quatro dias ele <b>não a chamava</b>: uma pesquisa iniciada nunca terminava, e a colônia perdia a
  vaga sem receber bônus nenhum. Antes da produção, e não depois, senão o bônus recém-conquistado só
  valeria a partir do minuto seguinte. <span class="d">D-190</span>
</div>

<h2 id="s15">15. Eventos de mundo</h2>

<p>
  O planeta pode mudar. Um evento aplica um <b>modificador de produção ou de consumo</b> a todo o
  mundo (ou a um recurso), por uma janela de tempo — e o jogador <b>vê que algo está acontecendo</b>.
  <span class="d">D-185</span>
</p>

<div class="nota grave">
  <b>Um motor que muda a economia sem que ninguém veja é indistinguível de um defeito.</b> O jogador
  veria a produção cair e concluiria que o jogo quebrou — e essa é uma desconfiança que não se
  recupera depois. Por isso o evento anunciado aparece em <b>todas as telas</b>, com nome, mensagem
  e o efeito em número.
</p>

<h3>15.1 Os três graus de visibilidade</h3>

<table>
  <tr><th>Grau</th><th>O que o jogador vê</th></tr>
  <tr><td><b>Anunciado</b></td><td>Nome, mensagem e o efeito exato (<i>"produção −20% em tudo"</i>).</td></tr>
  <tr><td><b>Parcial</b></td><td>Que <b>algo</b> afeta a produção — sem dizer o quê nem quanto.</td></tr>
  <tr><td><b>Secreto</b></td><td>Nada. O servidor o filtra antes de a tela existir.</td></tr>
</table>

<div class="nota">
  O <b>parcial</b> é a única visibilidade que cria <b>mistério em vez de confusão</b>: o jogador sabe
  que há uma causa e vai procurá-la, em vez de achar que os números estão errados.
</div>

<h3>15.2 Criar é ato de governo</h3>

<p>
  Eventos nascem por <b>comando do Dono</b>, não por rota HTTP — mesmo molde da intervenção
  econômica e do conciliador. E há uma razão a mais: um evento <b>secreto</b> que passasse por HTTP
  deixaria rastro no log de acesso do servidor web, que é o lugar mais fácil do mundo de alguém ler
  por acidente.
</p>

<div class="nota">
  <b>Preview obrigatório, e cancelar nunca apaga.</b> O comando <b>sempre</b> mostra o que faria;
  ativar exige dizê-lo em voz alta. E cancelar grava a data: o evento <b>para de valer dali para a
  frente</b> e <b>continua valendo para trás</b>. Apagar a linha faria o "Desde sua última visita"
  dizer que a produção caiu sem motivo.
</div>

<h2 id="s16">16. O que o campo mediu</h2>

<p>
  Nenhuma versão anterior deste documento teve uma seção assim, e ela existe por um motivo:
  <b>metade das decisões desta leva saiu de uma medida que contradisse o que estava escrito</b>. Um
  GDD que só publica a regra e nunca o que aconteceu com ela é a metade que envelhece primeiro.
</p>

<p>
  O que segue foi medido em <b>produção</b>, com <b>29 colônias</b> e <b>24 dias</b> de histórico
  (2026-08-06). <span class="d">D-219, D-223, D-226 a D-229</span>
</p>

<h3>16.1 Sistemas construídos e nunca exercitados</h3>

<table>
  <tr><th>Sistema</th><th class="num">Uso real</th></tr>
  <tr><td>Guerra (combates registrados, desde sempre)</td><td class="num">0</td></tr>
  <tr><td>Eventos de mundo criados</td><td class="num">0</td></tr>
  <tr><td>Zonas neutras ocupadas (de 77)</td><td class="num">1</td></tr>
  <tr><td>Itens da Endurance no catálogo</td><td class="num">1</td></tr>
  <tr><td>Ordens de mercado executadas por <b>humanos</b></td><td class="num">0</td></tr>
</table>

<div class="nota grave">
  <b>⚠️ A guerra federativa inteira — o sistema mais longo já construído neste jogo — nunca teve um
  combate.</b> E a causa não era desenho: eram <b>três portões empilhados</b> na frente do território
  (marco, custo material, população livre), e o primeiro deles pedia <b>3× o total de XP de vida do
  melhor jogador do mundo</b>. <span class="d">D-223</span>
</div>

<h3>16.2 O XP não era lento — tinha parado</h3>

<table>
  <tr><th>Semana</th><th class="num">XP do mundo inteiro</th></tr>
  <tr><td>1ª</td><td class="num">69.100</td></tr>
  <tr><td>2ª</td><td class="num">8.150</td></tr>
  <tr><td>3ª</td><td class="num">2.200</td></tr>
  <tr><td>4ª</td><td class="num">1.000</td></tr>
</table>

<p>
  Queda de <b>98,5%</b>. A causa é estrutural: <b>96% do XP vem de obra concluída</b>, que é fonte de
  <b>largada</b> — a colônia se ergue na primeira semana e a curva de custo engasga o resto. A
  quadrática do Marco sobe; a fonte que a alimenta desce. Foi isto que recalibrou a curva
  (<b>BASE 50 → 15</b>), com âncora medida: pôr o jogador mais avançado do mundo na faixa que o §05
  associa a território. <span class="d">D-223</span>
</p>

<h3>16.3 A economia entre jogadores não tinha sobre o que acontecer</h3>

<p>
  Todas as colônias humanas têm <b>dezenas de milhares</b> de água, oxigênio e biomassa — e é
  justamente isso que ofereciam umas às outras, em lotes de 49 unidades. <b>Vender isso é oferecer
  areia no deserto.</b> Havia vantagem comparativa real e <b>não anunciada</b> (uma colônia com 100×
  mais Ligas que a vizinha), e o bem verdadeiramente escasso — <b>Componentes Eletrônicos</b> — não
  podia ser produzido: a Oficina exige os 8 minerais eletrônicos, e o §4.3 os dá só ao governo.
  <span class="d">D-228</span>
</p>

<div class="nota">
  <b>O que destravou:</b> a Indústria Siderúrgica já dava <b>cinco</b> dos oito minerais, e funciona
  — o gargalo eram os <b>três sem fonte nenhuma</b> (silício, lítio, tântalo), e entre eles só o
  <b>silício</b> trava tudo, porque as <b>três</b> receitas da Oficina o exigem. Ele estava a
  <b>5,54×</b> o preço de referência num livro cuja mediana é 0,84×. Foi recalibrado, e a quantidade
  à venda junto — preço justo com estoque insuficiente deixaria a cadeia travada do mesmo jeito.
  <span class="d">D-229</span>
</div>

<h3>16.4 A lição de método</h3>

<div class="nota grave">
  <b>Três vezes nesta leva uma medida quase virou conclusão errada</b>, e as três foram pegas por
  medir de novo:
  <ul>
    <li><b>taxa nominal tratada como previsão</b> — quase se publicou que colônias secariam a água
      em 4 dias; o dreno era de uma fábrica <b>parada</b>. <span class="d">D-219</span></li>
    <li><b>banco de desenvolvimento lido como produção</b> — quase se publicou que havia colônias com
      população zero. <span class="d">D-225</span></li>
    <li><b>bots contados como jogadores</b> — o "sistema mais usado do jogo" era usado 100% por
      simulados. <span class="d">D-227</span></li>
  </ul>
  <b>Antes de concluir de um número: de onde ele veio, e ele é medida ou capacidade?</b>
</div>


<h2 id="s12">12. Tudo o que ainda falta decidir</h2>

<p>
  <b>Esta é a seção mais útil do documento.</b> Nenhum número aqui foi inventado, e nenhum será até
  que alguém o decida — é a regra de ouro do projeto aplicada ao próprio GDD.
</p>

<p>
  <b>Fechados desde a última revisão</b> (não aparecem mais aqui): a guerra inteira (D-66, D-70),
  o Drone (D-74), o Marco (D-75), as Missões (D-78), o Chat (D-77), a receita de Ligas e
  Compostos (D-83), o serviço logístico público (D-76), o teto de revenda do Furgão (D-73), o
  teto e o upgrade de zona e a manutenção territorial (D-84, §8.6), o kit inicial e a frota que
  ele concede (D-85/D-92, §3.2.1), a zona em abas e o Histórico (D-86, §8.7), o Governo vendendo
  no Mercado Central (D-87, §6.2.1), chamar o veículo de volta do Pátio vazio (D-91, §6.3.1), a
  <b>Federação inteira</b> (D-114 a D-121, §9), o <b>Ranking de Guerras</b> (D-128, §8.11), os
  <b>Leilões</b> (D-129, §6.6), três dos quatro <b>Cargos Públicos</b> restantes (D-130, §7.4), o
  teto do <b>Tanque de Combustível</b> (D-131, §4.3), a <b>Loja de Peças da Endurance</b> por
  inteiro e as <b>missões narrativas</b> (D-132 a D-140, §10), e a demolição de estrutura de zona
  (D-138, §8.10).
</p>

<p>
  <b>Fechados nesta revisão</b> (D-141 a D-159): a <b>zona neutra em colmeia de slots</b>, com
  crescimento por nível e três estruturas repetíveis (D-144, §8.0); <b>onde se pode fundar</b> —
  a periferia curada célula a célula pelo Dono (D-147, §1.1.1); <b>onde pode existir zona neutra</b>
  — fora dos 4 distritos, por decisão do Dono (D-148, §8); o <b>mapa do painel de admin</b> e as
  três ações que vivem nele (D-145 a D-148, §11.6); o <b>Frete do Governo com vários recursos na
  mesma viagem</b> (D-151, §5.6); a <b>taxa nominal por hora</b>, produzida e gasta, separadas
  (D-153, §3.4); e o <b>teto do Reator de Energia</b>, estendido ao nível
  <?= max(array_keys($porTipo['reator_de_energia'] ?? [5 => null])) ?> pelas curvas do próprio GDD
  (D-157, §2.3).
</p>

<p>
  <b>Fechados nesta revisão (D-160 a D-229)</b>, e por isso saíram da tabela abaixo: a
  <b>População</b> por inteiro — teto habitacional, operadores, consumo, crescimento e escassez
  (§13); a <b>árvore de pesquisa</b>, com trilhas, custo, tempo e efeitos que mexem no motor (§14);
  os <b>eventos de mundo</b> (§15); o <b>teto de estoque</b> dos demais recursos, com o piso pessoal
  que o §6.7 exigiu (§3.5); <b>o que o nível do veículo muda</b>, e o caminho para subi-lo (§5.6);
  e a <b>guerra entre federações</b> — declaração, cerco de colônia, saque, capitulação, tratado de
  paz, neutralidade declarada e ranking Elo (§8.9 a §8.14).
</p>

<table>
  <tr><th>Assunto</th><th>O que falta</th><th>O que ele trava</th></tr>
  <tr>
    <td><b>Cargueiro Interplanetário / Espaçoporto</b></td>
    <td>O <b>Espaçoporto</b> e os 5 planetas NPC — nenhuma rota, doca ou taxa existe</td>
    <td>A 5ª atribuição do Ministério dos Transportes, e o cargo de <b>Atendente do Espaçoporto</b> (§7.4, D-130) — 100% dependente disto, fica de fora até o Espaçoporto existir</td>
  </tr>
  <tr>
    <td><b>Os 8 minerais eletrônicos</b></td>
    <td>O §4.3 diz que <b>o jogador não os extrai</b>. A Indústria Siderúrgica dá <b>cinco</b> deles
        (D-82); <b>silício, lítio e tântalo continuam sem fonte nenhuma</b> além da compra ao governo
        — e as <b>três</b> receitas da Oficina exigem silício</td>
    <td>Toda a cadeia industrial passa por um <b>monopólio estatal</b>. O preço e a quantidade foram
        recalibrados (D-229), mas a dependência é estrutural, não de número</td>
  </tr>
</table>

<div class="nota grave">
  <b>⚠️ A lacuna que a §16 acrescenta, e que não é de regra:</b> quatro sistemas estão
  <b>construídos e nunca foram exercitados</b> — guerra (0 combates), eventos (0 criados), Endurance
  (1 item), e o livro do Mercado entre humanos (0 execuções). O GDD não tem buraco ali; <b>o jogo
  tem mais sistema do que tem jogo acontecendo</b>. É a diferença entre "falta decidir" e "falta
  ser jogado", e as duas exigem trabalho de naturezas opostas.
</div>

<div class="nota">
  <b>Dois números que valem revisitar mesmo sem serem lacuna:</b> o <b>salário do conciliador</b>
  é emissão contínua (50 F$/dia), e o kit inicial de um colono é 50 F$ — um conciliador ganha um
  kit inicial por dia sem jogar (D-50). E o piso anti-farming do §26.3 desceu de 500 para
  <b>5 Fert$</b> (D-117) — reduz 100× o custo de entrada de um farm de reputação; se aparecer, é
  o primeiro número a subir de novo.
</div>

<div class="nota">
  <b>Julgamentos do desenvolvedor, registrados para o usuário revisitar</b> (não são lacunas do
  GDD — são leituras minhas onde o texto não decide, sinalizadas em vez de escondidas): o fundo da
  Federação vai para o Tesouro na dissolução, não dividido pelo histórico de contribuição
  (<span class="d">D-114</span>); o canal Federação do Chat é isento da pena de silêncio
  (<span class="d">D-115</span>); uma colônia que sai da federação no meio de uma missão
  cooperativa ainda recebe se o grupo terminar (<span class="d">D-116</span>); e demolir uma
  estrutura de zona não reduz a manutenção territorial, porque ela nunca dependeu do nível de
  nenhuma das 12 estruturas — só do nível da zona (<span class="d">D-138</span>).
</div>

</main>

<footer>
  <div class="env">
    <p>
      <b>FERTWAYS — GDD v40.</b> Substitui o v39 (D-160), que substituiu o v38 (D-141), que
      substituiu o v36 (D-62), que substituiu o v35 — os quatro ficam como registro histórico do
      que se pensava antes. <b>Nenhuma versão é editada depois de publicada</b>: cada uma é um
      gerador novo, e é isso que permite ler o que o jogo era em cada corte.
    </p>
    <p>
      As tabelas numéricas são geradas de <code>building_specs</code>, <code>resource_types</code>,
      <code>fabrica_veiculos</code>, <code>federation_settings</code>, <code>silo_capacidades</code>,
      <code>population_settings</code> e <code>estoque_settings</code> — as mesmas de onde o jogo lê —
      por <code>tools/gdd-v40.php</code>. O documento <b>não pode
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
