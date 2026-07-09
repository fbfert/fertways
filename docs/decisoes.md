# Decisões de implementação — divergências e lacunas do GDD

A regra de ouro do projeto é: toda fórmula, custo e regra vem do GDD v3.5, e nada se inventa.
Este arquivo registra os pontos onde o GDD **não** decide, se **contradiz**, ou onde a
implementação **diverge** dele conscientemente. Cada item tem data, fonte e justificativa.

---

## D-01 — O aditivo v3.4 §4 (custos) faz parte do MVP
**Data:** 2026-07-08 · **Status:** decidido

O prompt inicial dizia "não implemente o aditivo v3.4 completo". Mas o v3.4 tem três blocos:
§2 (Missões de Reconhecimento), §3 (15 especializações) e **§4 (recálculo geral de custos, curva 1,65×)**.

O GDD declara (linhas 319, 322, 323) que o v3.4 "assume precedência sobre ... os valores de custo
das seções 20 e 21 da v3.0" e que "nas matérias detalhadas a seguir, o bloco v3.4 é vigente".

Excluir o v3.4 inteiro implicaria construir o MVP sobre custos expressamente revogados.

**Decisão:** fora §2 e §3; **dentro §4.2 e §4.3**, que são a fonte de custo do MVP.

---

## D-02 — Custo é fórmula; tempo de construção não é
**Data:** 2026-07-08 · **Status:** decidido · **Verificado numericamente**

- **Custo** = `half-up(base × 1,65^(nível-1))`. Reproduz 9/9 séries testadas de §4.2, exato.
  Atenção: arredondamento **meio-para-cima**. `round()` de PHP e Python usa half-even e produz
  82 onde a tabela do GDD diz 83 (50 × 1,65 = 82,5).
- **Tempo** **não** deriva de `base × 1,50^(nível-1)`. Gerador de Atmosfera: tabela `4,5,8,12,18`;
  a fórmula daria `4,6,9,14,20`. Reator de Energia (`7,10,16,24,35`) não é reproduzível por
  nenhuma base oculta. O "curva 1,50× preservada" do cabeçalho descreve a origem histórica dos
  números, não um cálculo refazível.

**Decisão:** `building_specs` é semeada **verbatim** das tabelas do GDD. A fórmula de custo existe
apenas como teste de propriedade em `tests/Gdd/`, validando o seed contra o HTML. Nada é calculado
em runtime a partir de curva.

**Consequência:** níveis são limitados (5 na maioria; 10 em Central de Transportes, Depósito de
Zona Neutra e Destilaria). Nunca há extrapolação acima do tabelado.

---

## D-03 — Momento de incidência do tributo de transporte
**Data:** 2026-07-08 · **Status:** decidido pelo usuário · **Contradição interna do GDD**

O GDD diverge, e a tabela de precedência da seção 0 não cobre este item:

| Fonte | Momento | Menções |
|---|---|---|
| §8.2 (exemplo do calote) | "Tributo cobrado **no envio**" | 2 |
| §8.3 | "cobra **na saída**" | 1 |
| §25.2 | "sempre que um veículo **entrega carga**" | 1 |

**Decisão: vale o §25.2 — o tributo incide na ENTREGA.**

Justificativa: §25.2 é a seção declaradamente unificadora ("Comércio Físico — Tributação e
Logística **Unificadas**"), posterior e integradora. O texto pende 3:1 para "envio", então os
exemplos do §8.2 vão parecer inconsistentes para quem ler o GDD — isso é conhecido e aceito.

**Consequências implementadas:**
- `tax_events.economic_event_key` deriva do evento de **chegada** do veículo, não do despacho.
- Viagem cancelada ou veículo perdido antes da entrega **não** gera lançamento tributário.

---

## D-04 — Classe tributária de Metal Bruto e dos minerais eletrônicos
**Data:** 2026-07-08 · **Status:** decidido pelo usuário · **Lacuna do GDD**

§8.3 define alíquotas por classe (primários 3%, secundários 2%, raros 1%), mas as listas de
§18.2 e §22.2 **não classificam** Metal Bruto nem os 8 minerais eletrônicos (Alumínio, Estanho,
Cobre, Silício, Lítio, Tungstênio, Tântalo, Ouro). Ambos são transportáveis no MVP.

**Decisão:** Metal Bruto = **primário (3%)**, por ser extraído bruto e sem processamento, como
Água e Biomassa. Os 8 minerais eletrônicos = **secundários (2%)**, por serem adquiridos já
processados do governo.

Isto é design fora do GDD. Se uma revisão futura do GDD classificar diferente, ela prevalece.

---

## D-05 — O slot principal não tem número fixo de slots de construção
**Data:** 2026-07-08 · **Status:** decidido pelo usuário · **Lacuna do GDD**

O GDD não define quantas posições de construção a colônia do jogador possui. As únicas
ocorrências de "slot" numerado são os **20 slots institucionais da Capital** (§2.1 — governo NPC:
Administração Pública, Central de Tributos, Ministério das Reputações…) e "slots por minuto",
que é velocidade de veículo no mapa.

**Decisão:** uma construção por tipo, sem posição fixa. `buildings` tem `UNIQUE(colony_id, type)`
e **não** tem coluna `slot`. É a opção que menos inventa. Se o GDD definir uma grade depois,
adiciona-se a coluna sem perda de dados.

---

## D-06 — Escopo: o item 2 do prompt estava incompleto
**Data:** 2026-07-08 · **Status:** corrigido

O item 2 do prompt lista 13 construções. O item 3 exige "a cadeia de produção completa
(Metal Bruto → Componentes Eletrônicos, cadeia de Biocombustível)". Mas:

- **Metal Bruto** só sai da **Mina Local** (GDD: "a fonte individual de Metal Bruto no slot principal").
- **Biocombustível** só sai da **Destilaria** (GDD §18.2: "Biomassa processada — taxa 2:1").

Nenhuma das duas constava do item 2. **MVP = 13 + Mina Local + Destilaria + Central de
Transportes = 16 construções.** Tanque de Combustível fica fora até se confirmar se o
Biocombustível exige armazenamento dedicado.

---

## D-07 — Fert$ em micro-unidades, não em centavos
**Data:** 2026-07-08 · **Status:** decidido

Os preços-base de §22.2 têm quatro casas decimais (Energia = `0,0033` Fert$). Centavo não
representa essa precisão.

**Decisão:** dinheiro é `BIGINT` em **micro-Fert$**, onde `1 Fert$ = 1.000.000 µF$`.
Nunca `float`, nunca `decimal` de duas casas. Alíquotas em **basis points** (`300` = 3,00%).

---

## D-08 — DATETIME em vez de TIMESTAMP no domínio do jogo
**Data:** 2026-07-08 · **Status:** decidido · **Descoberto ao testar em MariaDB**

Este servidor roda MariaDB 10.5.29 com `explicit_defaults_for_timestamp = 0` e
`STRICT_TRANS_TABLES`. Nessa configuração:

1. Uma segunda coluna `TIMESTAMP NOT NULL` sem default recebe `'0000-00-00'`, rejeitado pelo
   modo estrito — `colonies` não era criável.
2. A **primeira** coluna `TIMESTAMP` de cada tabela ganha `ON UPDATE CURRENT_TIMESTAMP`
   implícito. Em `ledger`, isso reescreveria o `created_at` a cada UPDATE, quebrando o
   append-only exigido pela seção 0 do GDD.
3. `TIMESTAMP` é 32-bit e satura em **2038-01-19**.

**Decisão:** todo timestamp de domínio usa `DATETIME`, com `useCurrent()` explícito onde é
NOT NULL na inserção. Os `created_at`/`updated_at` do próprio Laravel ficam como estão.

Nota: o SQLite aceitou o schema quebrado sem reclamar. Validar migrations contra MariaDB, sempre.

---

## D-10 — O GDD não publica tempo de construção para 10 das 25 tabelas
**Data:** 2026-07-08 · **Status:** ABERTO, bloqueia MVP itens 3 e 4 · **Lacuna do GDD**

As tabelas de §4.2/§4.3 trazem linha `Tempo (min)` para 15 construções. Não trazem para:

| Sem tempo no GDD | Precisa no MVP? |
|---|---|
| `central_de_transportes` | **sim** (item 4) |
| `destilaria` | **sim** (item 3 — cadeia do Biocombustível) |
| `furgao_de_comercio` | **sim** (item 4) |
| `caminhao_de_carga` | **sim** (item 4) |
| `deposito_de_zona_neutra` | item 6 |
| `drone_de_exploracao`, `robo_minerador`, `infiltrador`, `predador`, `nave_de_transporte_planetaria` | não (v2) |

Procurado também no corpo v3.0 (§19.5, §19.6, §20, §21): não existe. §19.5 dá, para a Central
de Transportes, apenas **caminhões base por nível** (1..10) e **consumo de energia** — nunca tempo.

**Situação atual:** `building_specs.build_time_seconds` é `NULL` para essas dez.
`NULL` significa "o GDD não diz", **não** "instantâneo". Enfileirar uma dessas construções deve
falhar explicitamente até que os tempos sejam definidos. Há teste garantindo que ninguém preencha
esses campos com valor inventado (`test_construcoes_sem_tempo_no_gdd_ficam_nulas_e_nao_zeradas`).

---

## D-11 — Inconsistências menores do GDD, registradas e não resolvidas
**Data:** 2026-07-08 · **Status:** ABERTO, não bloqueia

1. **Base de cálculo do tributo.** §8.3 diz "3% **do volume** enviado". §22.6 exemplifica a venda
   de 1.000 unidades de Água e calcula "Tributo (3%): 0,186 Fert$", que é 3% do **valor em Fert$**
   (6,20), não do volume. §25.2 separa "volume movimentado" (transporte) de "Fert$ movimentado"
   (mercado). O schema comporta ambos (`tax_events.kind` + `base_amount`), mas a semântica do
   `base_amount` no transporte precisa de confirmação.
2. **Quantos recursos raros existem.** A taxonomia narrativa lista **8** raros. A tabela de
   preços-base §22.4 lista **9**, incluindo "Bioenergia Curativa", ausente da primeira lista.
   O catálogo semeado tem os 9 de §22.4.

---

## D-09 — Invariantes que o banco garante, não o código

- `tax_events.economic_event_key` é **UNIQUE**. "Uma incidência por fato econômico/lote"
  (GDD seção 0 e §25.2) é invariante de dados: sem a chave, um retry de request ou dois ticks
  concorrentes tributam o mesmo lote duas vezes.
- `ledger` é **append-only**: não tem `updated_at` nem `deleted_at`. Estorno é lançamento novo
  de sinal contrário. O Model precisa bloquear `update`/`delete`.
- `market_orders` tem colunas de **escrow**. Sem elas o Mercado Central não teria a garantia que
  a seção 0 exige, e seria indistinguível do comércio informal.
- Os quatro índices de reputação são **colunas separadas**, nunca um agregado: o GDD veda
  expressamente compensação cruzada entre eles.
