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

## D-02 — Custo usa half-up; tempo usa half-even. Ambos são deriváveis.
**Data:** 2026-07-08 · **Status:** decidido · **CORRIGIDO em 2026-07-08**

> **Retratação.** A primeira versão deste item afirmava que "o tempo não é derivável" e que o
> Reator de Energia "não é reproduzível por nenhuma base oculta". **Ambas as afirmações eram
> falsas.** Elas nasceram de duas falhas de método: eu procurei a base só nas tabelas de §4.2,
> sem ler §20.3–20.5, que a publicam; e testei apenas arredondamento half-up, quando o tempo
> usa half-even. O texto original fica abaixo tachado, para não perder o rastro do erro.

**Custo** = `half-up(base × 1,65^(nível-1))`. Reproduz **25/25** tabelas de §4.2, exato.
Atenção: `round()` de PHP e Python usa half-even por padrão e produz 82 onde o GDD diz 83
(50 × 1,65 = 82,5).

**Tempo** = `half-even(base × 1,50^(nível-1))`, onde `base` é o **tempo-base não inteiro** que
§20.3–20.5 publicam ("Gerador de Atmosfera — tempo base 3,5 min"). Reproduz **13 das 14** tabelas
com base publicada, exato.

A armadilha: ancorar no nível 1 **já arredondado** não funciona. O Gerador exibe 4 min no nível 1,
mas sua base real é 3,5. Por isso `4 × 1,5 = 6` erra o `5` que o GDD publica.

Os dois modos de arredondamento convivem no mesmo documento e não são intercambiáveis:
- Custo, Gerador n2: `50 × 1,65 = 82,5` → GDD **83** (half-up; half-even daria 82).
- Tempo, Reator n2: `7 × 1,5 = 10,5` → GDD **10** (half-even; half-up daria 11).

**Única exceção conhecida:** Tanque de Combustível nível 4, onde `12 × 1,5³ = 40,5` e o GDD
publica **41**, não 40. Provável artefato de planilha. Há teste fixando essa exceção: se o GDD for
corrigido, o teste avisa.

**Decisão:** `building_specs` continua semeada **verbatim** das tabelas. As curvas vivem apenas
como testes de propriedade em `tests/Gdd/`, conferindo o seed contra o documento. Nada é calculado
em runtime a partir de curva — as exceções acima mostram por quê.

~~Tempo não deriva de base × 1,50^(n-1); o Reator não sai de base alguma; a curva 1,50× é
descrição histórica, não cálculo refazível.~~ *(errado — ver retratação acima)*

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

**Preço-base do Metal Bruto (2026-07-08):** nenhuma tabela de §22 o precifica. §24.8 dá a fórmula
para primários e brutos e **lista Metal Bruto entre os aplicáveis**:

> `Preço(r) = Preço(Oxigênio) × (Produção máx/h Oxigênio ÷ Produção máx/h de r)`

Com Oxigênio = 0,0050 Fert$ e 506/h, e Mina Local = 76/h no nível 5 (§19), resulta
**0,0333 Fert$**. A fórmula reproduz exatamente os três primários publicados (Água 0,0062,
Biomassa 0,0083, Energia 0,0033), o que a valida. O valor é **derivado, não publicado**:
`resource_types.preco_base_derivado = true` o marca, e há teste refazendo a conta.

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

**Resolução parcial (2026-07-08):** o autor forneceu os tempos-base de nível 1 das quatro
construções do MVP. Os demais níveis saem de `half-up(base × 1,5^(n-1))`, gravados com
`build_time_derivado = true`:

| Construção | Base (min) | Níveis derivados |
|---|---|---|
| Central de Transportes | 6 | 6, 9, 14, 20, 30, 46, 68, 103, 154, 231 |
| Destilaria | 10 | 10, 15, 23, 34, 51, 76, 114, 171, 256, 384 |
| Furgão de Comércio | 7 | 7, 11, 16, 24, 35 |
| Caminhão de Carga | 14 | 14, 21, 32, 47, 71 |

Seis continuam `NULL` (Depósito de Zona Neutra, Drone, Robô Minerador, Infiltrador, Predador,
Nave de Transporte Planetária). `NULL` significa "o GDD não diz", **não** "instantâneo":
enfileirar uma delas deve falhar explicitamente.

**Sobre a inclinação.** A hipótese de que o GDD gerou os tempos com a curva 1,5 a partir de uma
base **não inteira** foi depois **confirmada**: §20.3–20.5 publicam essas bases. Ver D-02.
As quatro construções acima seguem exatamente a mesma curva e o mesmo arredondamento (half-even)
das construções publicadas. Não há mudança de escala.

Curiosidade: com base 14, o Caminhão de Carga derivado sai idêntico às tabelas publicadas da
Torre de Defesa (que tem base 14 no GDD) e da Mina Local.

Dois testes guardam a fronteira nos dois sentidos: tempo do GDD nunca é marcado como derivado, e
tempo escolhido fora do GDD nunca passa por publicado.

---

## D-11 — Inconsistências menores do GDD
**Data:** 2026-07-08 · **Status:** item 1 RESOLVIDO (era falso positivo), item 2 aberto

1. **Base de cálculo do tributo — não havia contradição.** §8.3 ("3% do volume") e §22.6
   (exemplo cobrando 0,186 Fert$ sobre 1.000 de Água) dão o **mesmo número**, porque
   `valor = volume × preço`: 3% de 1.000 unidades são 30 unidades, e 30 × 0,0062 = 0,186 Fert$.
   O §22.6 apenas expressa em Fert$ o que o §8.3 descreve em unidades. Ver D-12 para a
   pergunta que de fato estava escondida aqui.
2. **Quantos recursos raros existem.** A taxonomia narrativa lista **8** raros. A tabela de
   preços-base §22.4 lista **9**, incluindo "Bioenergia Curativa", ausente da primeira lista.
   O catálogo semeado tem os 9 de §22.4.

---

## D-12 — O tributo é retido em recurso, não cobrado em Fert$
**Data:** 2026-07-08 · **Status:** decidido pelo usuário

Como §8.3 e §22.6 concordam no valor (ver D-11), a questão real é a **unidade retida**.
§8.3 é literal: "3% do volume enviado **vai para o Tesouro**".

**Decisão:** o tributo de transporte é **retido da própria carga**, em unidades do recurso.
Entregar 1.000 de Água faz chegarem 970 ao destino e 30 ao Tesouro.

Justificativa: é a leitura literal do §8.3 e de §25.2 ("volume de recurso movimentado");
nunca falha por saldo insuficiente de Fert$; e dispensa preço-base para tributar.

**Implementação em `tax_events`:**
- `kind = transporte_entrega` → `base_amount`/`tax_amount` em **unidades** do `resource_type`.
- `kind = mercado_venda` → `base_amount`/`tax_amount` em **micro-Fert$**, `resource_type` nulo.

---

## D-13 — Fundação da colônia: o que o GDD dá e o que ele cala
**Data:** 2026-07-08 · **Status:** decidido

**Do GDD, sem interpretação:**
- Saldo inicial de **50 Fert$** ("Todo colono recebe 50 Fert$ ao chegar em Fertways").
- **Um Furgão de Comércio** no kit inicial: "Veículo terrestre leve para rotas locais entre
  slots vizinhos. Parte do kit inicial — **todo colono começa com um**." Capacidade 6.000
  unidades (§25.4). Isto não estava no prompt do MVP e teria passado batido.

**Interpretação assumida — construções começam no nível 0.**
§17.1 ("Construções Essenciais do Slot — Kit Inicial") apenas *descreve* as cinco essenciais;
não diz que vêm construídas. §24.7 diz que o governo "cobre **a construção** e os upgrades
dessas 5 estruturas até o nível 3", o que pressupõe que o nível 1 ainda seja construído, não
concedido. Logo: 16 construções do MVP criadas no nível 0.

**Recursos começam em zero.** §24.7 explica o desenho: "o colono usa o saldo de 50 Fert$ inicial
para comprar o primeiro lote de Ligas Metálicas no Mercado Central antes de ter produção própria".
Dar recursos iniciais quebraria esse loop de onboarding.

**Fundação é transacional.** Cria colônia + 16 construções + 9 recursos + 1 veículo + 1 lançamento.
Falha no meio deixaria colônia sem recursos ou sem veículo — estados que nenhuma outra parte do
jogo sabe consertar. Há teste que força a falha e confere que nada persiste.

**O saldo entra como lançamento no ledger** (`type = saldo_inicial`), não como número mudo na
coluna. `colonies.fert_micro` é projeção do saldo; o ledger é a fonte auditável (seção 0).

---

## D-14 — `storage_cap` do slot principal é NULL
**Data:** 2026-07-08 · **Status:** ABERTO · **Lacuna do GDD**

O GDD define capacidade de armazenamento apenas para o **Depósito de Zona Neutra**
(§19.6: 500 → 19.222 por nível). Para o slot principal, nenhuma tabela.

`resources.storage_cap` é anulável. `NULL` significa "o GDD não define teto", e o tick **não capa**
a produção. Escolher um número seria inventar balanceamento. Quando o teto existir, basta preencher.

---

## D-15 — Custo é debitado no enfileiramento
**Data:** 2026-07-08 · **Status:** decidido (fora do GDD)

O GDD só diz que o subsídio "é registrado no ledger **no momento de concluir**" (§4.1). Não diz
quando o custo próprio é debitado.

**Decisão:** debitar no enfileiramento. Caso contrário o jogador enfileiraria mais do que pode
pagar, e a conclusão falharia — deixando a fila num estado que ninguém sabe resolver. O custo
cotado congela em `quoted_cost_json` no mesmo instante (§4.1).

O lançamento do **subsídio** (`subsidio_governo`) continua na conclusão, como o GDD manda: quem
é subsidiado não paga nada no enfileiramento.

---

## D-16 — A colônia estoca todos os recursos do catálogo
**Data:** 2026-07-08 · **Status:** decidido · **Bug encontrado por teste**

A primeira versão listava à mão nove recursos "da colônia" (primários + industriais). Um teste
de fila revelou que isso torna a **Oficina inconstruível**: ela custa 1 de **Ferro Vermelho**
(raro) já no nível 1, e a colônia não tinha onde guardá-lo.

Qualquer recorte manual quebra alguma cadeia: os 8 minerais eletrônicos são insumo dos
Componentes Eletrônicos, e 8 das 11 construções de progressão exigem raros no nível 1.

**Decisão:** `Resource::daColonia()` devolve todos os códigos de `resource_types`. Uma linha por
recurso, zerada. Sem lista escrita à mão, sem cadeia quebrada.

---

## D-17 — ⚠ Muro de progressão: 8 das 16 construções do MVP exigem recursos raros
**Data:** 2026-07-08 · **Status:** ABERTO, bloqueia o MVP na prática

Custo de nível 1, direto do §4.2:

| Construção | Raro exigido |
|---|---|
| Oficina | Ferro Vermelho ×1 |
| Refinaria Química | Quartzo Piezoelétrico ×1 |
| Laboratório | Resina Orgânica ×2 |
| Antena de Comunicação | Quartzo Piezoelétrico ×2 |
| Torre de Defesa | Nióbio Alienígena ×2 |
| Mercado Local | Resina Orgânica ×1 |
| Quartel | Nióbio Alienígena ×3 |
| Plataforma de Pouso | Gelo de Metano ×3 |

Construíveis sem raros: as 5 essenciais, Mina Local, Destilaria, Central de Transportes.

**Por que isso trava.** O GDD diz que os raros, na Temporada 1, são "obtidos por eventos, zonas
profundas e contratos do governo" — **nenhum desses sistemas está no MVP**. Pior: a Oficina é a
única fonte de **Ligas Metálicas**, exigidas por quase toda construção. Sem 1 unidade de Ferro
Vermelho, o jogador para logo após as cinco essenciais (que são subsidiadas até o nível 3 e por
isso não consomem Ligas).

A saída prevista pelo próprio §24.7 — "usa o saldo de 50 Fert$ para comprar o primeiro lote de
Ligas Metálicas no Mercado Central" — também não fecha: a tabela de progressão do GDD desbloqueia
o **Mercado Central só no nível 5** ("Colono"). Inconsistência interna adicional.

**Resolução (2026-07-08, decisão do autor):** o kit inicial concede recursos raros.

A quantidade **não é digitada**: `CreateColony::concederRarosDoKit()` soma, de `building_specs`,
o custo de raros de nível 1 das 16 construções do MVP. Dá exatamente o bastante para erguer cada
uma **uma vez**. Hoje isso resulta em Ferro Vermelho ×1, Gelo de Metano ×3, Nióbio Alienígena ×5,
Quartzo Piezoelétrico ×3, Resina Orgânica ×3 — mas se o GDD mudar, o kit acompanha sozinho.

Cada concessão vira lançamento `kit_inicial` no ledger, com o recurso nomeado. Isto é decisão de
design, não do GDD: quando existirem eventos, zonas profundas ou contratos do governo, o kit deve
ser reavaliado.

---

## D-18 — Tutoria marcada como concluída na fundação (stub)
**Data:** 2026-07-08 · **Status:** decidido, temporário

A tabela de onboarding do GDD condiciona a subvenção à "conclusão da tutoria" (cinco missões nos
três primeiros dias). O corpo de §24.7, porém, **não menciona tutoria**: diz simplesmente que as
cinco essenciais "são custeadas pelo Governo Central até o nível 3".

As missões estão fora do MVP. Sem destravar, o subsídio nunca vale, o colono tem zero recursos e
não constrói nada — nem o Gerador de Atmosfera do vertical slice.

**Decisão:** `CreateColony` grava `tutorial_completed_at = now()`. A regra continua **viva** no
código (`EnqueueUpgrade` consulta `tutoriaConcluida()`), e há teste que remove a marcação e
confere que o subsídio deixa de valer. Remover o stub quando as missões existirem.

- `tax_events.economic_event_key` é **UNIQUE**. "Uma incidência por fato econômico/lote"
  (GDD seção 0 e §25.2) é invariante de dados: sem a chave, um retry de request ou dois ticks
  concorrentes tributam o mesmo lote duas vezes.
- `ledger` é **append-only**: não tem `updated_at` nem `deleted_at`. Estorno é lançamento novo
  de sinal contrário. O Model precisa bloquear `update`/`delete`.
- `market_orders` tem colunas de **escrow**. Sem elas o Mercado Central não teria a garantia que
  a seção 0 exige, e seria indistinguível do comércio informal.
- Os quatro índices de reputação são **colunas separadas**, nunca um agregado: o GDD veda
  expressamente compensação cruzada entre eles.

---

## D-19 — Ligas Metálicas e Compostos Químicos não têm receita publicada
**Data:** 2026-07-08 · **Status:** ABERTO · **Escopo reduzido em 2026-07-08**

> **Correção.** A primeira versão deste item incluía os **Componentes Eletrônicos**. Estava
> errado: o **§24.5** publica as três receitas de fabricação, e elas foram implementadas.
> D-19 vale agora só para Ligas Metálicas e Compostos Químicos.

§19.3 publica a **taxa** de produção por hora:

| Construção | Saída | Nível 1 | Receita publicada? |
|---|---|---|---|
| Oficina | Componentes Eletrônicos | 15/h | **sim, §24.5** — implementado |
| Oficina | Ligas Metálicas | 40/h | **não** |
| Refinaria Química | Compostos Químicos | 30/h | **não** |

Sobre as Ligas, o GDD diz apenas que "Metal Bruto é extraído, Ligas são transformadas" — sem
proporção. Sobre os Compostos Químicos, nada em lugar algum. Creditar a saída sem debitar entrada
criaria recurso do nada.

**Estado atual:** `ColonyTick::SAIDAS_SEM_RECEITA` bloqueia as duas saídas. O consumo de energia
das construções continua sendo debitado. Há teste que dá minerais e Metal Bruto de sobra e confere
que nenhuma Liga sai — mutação confirma que ele morde.

Falta, para destravar: proporção Metal Bruto → Ligas Metálicas, e a receita dos Compostos Químicos.

---

## D-23 — Componentes Eletrônicos: um recurso, três receitas
**Data:** 2026-07-08 · **Status:** decidido

§24.5 dá três receitas (Básica, Intermediária, Avançada), extraídas do GDD para
`component_recipes`. Todas produzem o **mesmo** recurso `componentes_eletronicos`.

**Por que não são três recursos separados.** As doze construções e unidades que consomem
componentes usam, nas tabelas de custo de §4.2/§4.3, um **único código**: "Componentes
Eletrônicos". Dividir em tiers exigiria reescrever esse `cost_json`, que é semeado verbatim do
GDD — exatamente o que a regra de ouro proíbe. E não haveria como fazê-lo: o §24.5 nomeia destinos
para nove consumidores, mas **Central de Transportes, Tanque de Combustível e Drone de Exploração
ficam sem tier**.

**Como a receita é escolhida.** O parêntese do §24.5 mistura duas coisas: "Receita Básica — 4
minerais (**Oficina nível 1-2**, Furgão, Robô Minerador)" traz uma faixa de nível da Oficina junto
de unidades de destino. A leitura "destino" não se sustenta: a Oficina **não custa componentes nos
níveis 1-2** (conferido em `building_specs`). Logo o parêntese é ambíguo e não define regra.

Decisão: **o jogador escolhe a receita** (`PATCH /api/buildings/{id}/recipe`), padrão `basica`.
A escolha fica em `buildings.recipe`.

**Consequência econômica conhecida:** como as três receitas geram o mesmo bem, a Básica domina —
é a mais barata. §24.8 precifica os três tiers separadamente (Básico 1,2778 · Intermediário
1,5473 · Avançado 2,0877 Fert$), o que só faz sentido se forem bens distintos. Resolver isso exige
o GDD dizer qual tier cada construção consome.

---

## D-24 — Preço dos Componentes Eletrônicos: §24.8 vence §22.2, no valor do Básico
**Data:** 2026-07-08 · **Resolvido:** 2026-07-09 · **Status:** decidido

- §22.2 lista "Componentes Eletrônicos | Secundário | 76/h | **0,0333 Fert$**", calculado com a
  fórmula dos **primários** (`0,0050 × 506 ÷ 76`).
- §24.8, sob o título "**Preços Atualizados**", corrige a fórmula para recursos processados
  (`custo dos insumos × (1 + markup)`) e publica **Básico 1,2778 · Intermediário 1,5473 ·
  Avançado 2,0877 Fert$** — trinta e oito vezes maior.

**Não era bem uma contradição.** §24.8 diz textualmente: "Recursos que passam por processo de
fabricação (Componentes Eletrônicos, Biocombustível, e futuramente outros) usam a fórmula de custo
de insumos + markup. Recursos extraídos diretamente continuam na fórmula de escassez." Ou seja, o
§24.8 **revoga** o §22.2 para os fabricados, e o seed é que tinha ficado no valor velho.

**Decisões (usuário, 2026-07-09):**
1. Vale o **§24.8**. Com 0,0333 o componente valeria menos que a soma dos insumos (0,9127) e
   fabricar seria irracional: o colono ganharia mais vendendo o Metal Bruto cru.
2. Dos três preços, vale o do **Componente Básico: 1,2778 Fert$** (`1.277.800` micro). O estoque é
   fungível — uma coluna por recurso — e não há como saber, depois, qual lote saiu de qual receita
   do §24.5. Rastrear lote por receita quebraria a fungibilidade do estoque, da carga do veículo e
   da conta do Mercado. As receitas melhores seguem valendo a pena pela **eficiência de insumos**,
   não por um preço de venda maior.
3. O valor **não é digitado**: `tools/extract_gdd_specs.py` agora lê a tabela do §24.8 e sobrescreve
   o preço do §22.2, conferindo de passagem o markup de 40% que a seção declara. Regenerar o
   catálogo não reverte mais o preço em silêncio. Fixado em `GddSpecsTest`.

`0,9127 × 1,40 = 1,27778`; o GDD publica quatro casas, daí `1,2778`. O preço **não** é marcado como
derivado: ele está publicado, só que numa seção mais nova que a tabela de preços-base.

**Pendência que isto revelou — ver D-33.** O §24.8 põe o **Biocombustível** na mesma regra, mas não
publica preço nenhum para ele.

---

## D-33 — Três tabelas de preço no GDD, e a regra que as concilia
**Data:** 2026-07-09 · **Status:** decidido

O GDD publica preços em **três** lugares, e eles divergem:

| Recurso | §22.2 (escassez) | §24.8 (custo+markup) | §07 (Mercado Central) |
|---|---|---|---|
| Componentes Eletrônicos | 0,0333 | **1,2778** (Básico) | *ausente* |
| Biocombustível | 0,0166 | *nomeado, sem número* | **0,0345** |
| Metal Bruto | *ausente* | *fórmula de escassez* | 0,1830 |
| Oxigênio, Energia, Água, Biomassa, Ligas, Compostos | — | — | *idênticos ao §22.2* |

**Regra de conciliação (usuário, 2026-07-09):** o **§24.8 decide qual família de fórmula rege cada
recurso**, e o **§07 só fornece número publicado onde o §24.8 não impõe fórmula**.

- **Componentes Eletrônicos** → §24.8 publica direto: 1,2778. Ver D-24.
- **Biocombustível** → §24.8 o chama de processado e revoga a escassez, mas não publica número, e
  o GDD não traz a receita. O §07 publica **0,0345**, maior que o 0,0166 da escassez e portanto
  coerente com "custo de insumos + markup". É o único valor publicado compatível. **Adotado.**
- **Metal Bruto** → §24.8 mantém a escassez para ele, nominalmente ("Aplicável a: Oxigênio, Água,
  Biomassa, Energia, Metal Bruto…"). O 0,1830 do §07 é descartado. Ver D-34.

O extrator agora lê a tabela do §07 e **exige** que ela concorde com o §22.2 em todos os outros
recursos: se uma reedição do GDD mexer em qualquer preço, ele estoura em vez de divergir em
silêncio. Fixado em `GddSpecsTest`.

---

## D-34 — Metal Bruto: o §07 publica 0,1830; ficamos com o derivado 0,0333
**Data:** 2026-07-09 · **Status:** decidido

Durante a arbitragem do D-24 descobriu-se que o §07 traz "Metal Bruto | Industrial estratégico |
**0,1830 Fert$**". O comentário do extrator afirmava que "Metal Bruto não aparece em nenhuma tabela
de preço-base" — isso era **falso**: ele só não aparece nas tabelas de §22. O comentário foi
corrigido.

**Decisão do usuário:** fica o valor **derivado, 0,0333**. O §24.8 lista o Metal Bruto entre os
recursos que seguem a fórmula de escassez, e essa fórmula reproduz Água, Biomassa e Energia
exatamente como o GDD as publica — é a evidência mais forte de que ela é a regra viva. O 0,1830
seria a tabela velha, ou uma classe tributária ("Industrial estratégico") que o §8.3 nem reconhece.

Consequência: o Metal Bruto vale 5,5× menos do que o §07 diz. Se a economia de mineração parecer
fraca quando o Mercado abrir, **este é o primeiro número a revisitar** — e a mudança é de uma linha
no extrator. Fixado em teste para não ser trocado por acidente.

---

## D-20 — Déficit de energia trava o estoque em zero
**Data:** 2026-07-08 · **Status:** decidido (fora do GDD)

Energia é estoque (custo de construção, §4.2) **e** fluxo (consumo operacional por hora, §19.4).
O Reator credita; toda construção debita. O saldo pode ficar negativo — e o GDD **não define** o
que ocorre: não há regra de apagão, de produção reduzida nem de dívida.

**Decisão:** o estoque trava em zero, sem outro efeito. `resources.amount` é unsigned, então dívida
nem seria representável. Quando o GDD definir a penalidade, ela entra aqui.

---

## D-21 — Fuso do MariaDB × fuso do Laravel
**Data:** 2026-07-08 · **Status:** corrigido · **Encontrado ao verificar o tick**

Este servidor roda MariaDB em `-03` (`@@time_zone = SYSTEM`) e o Laravel em `UTC`. Seis colunas
usam `CURRENT_TIMESTAMP` como default, entre elas **`colonies.last_tick_at`**.

Sem intervenção, uma inserção que omitisse essa coluna gravaria hora local — três horas no passado
em relação ao relógio da aplicação. O primeiro tick veria um delta de três horas e creditaria
produção grátis. O mesmo valeria para `ledger.created_at` e `tax_events.created_at`, corrompendo a
auditoria.

**Correção:** `config/database.php` passa `'timezone' => '+00:00'` na conexão mysql. Verificado:
`SELECT NOW()` na sessão do Laravel devolve o mesmo instante que `now()` do PHP, e um insert que
omite `last_tick_at` grava UTC.

---

## D-22 — Aritmética do tick é inteira, com resto carregado
**Data:** 2026-07-08 · **Status:** decidido

O tick roda a cada minuto. Uma produção de 100/h renderia `floor(100 × 60 ÷ 3600) = 1` unidade por
minuto em vez de 1,67 — perda silenciosa e permanente de 40% da economia.

`resources.production_remainder` guarda o resto em unidades de 1/3600. O tick soma
`numerador = taxa × segundos`, credita `numerador div 3600` e carrega `numerador mod 3600`.
Inteiro, exato, sem float.

Dois cuidados aprendidos na prática:
1. **`Carbon::diffInSeconds()` devolve float** no Carbon 3. Um delta de 3600,99 s fazia a Destilaria
   consumir 41 de biomassa onde devia consumir 40. O tick usa `getTimestamp()` e trunca ao segundo.
2. **O delta é fatiado em cada conclusão de upgrade.** Uma colônia parada por dois dias com um
   upgrade concluindo no meio precisa produzir com o nível antigo até a conclusão e com o novo
   depois. Há teste que distingue os dois casos — a primeira versão dele não distinguia, porque
   usava um upgrade de nível 0 para 1, e nível 0 não produz.

---

## D-25 — A API é montada em `/central`, e por isso `apiPrefix` é vazio
**Data:** 2026-07-09 · **Status:** decidido

O jogo é servido em `https://fertways.tars.art.br` e a API em `https://fertways.tars.art.br/central`.
Um domínio só: sem CORS, sem certificado novo, mesma origem para o `fetch` do front.

O Apache monta a aplicação sob `/central` através de um symlink `public_html/central -> backend/public`
e **já remove esse trecho do caminho** antes de entregá-lo ao PHP (o Symfony deduz o ponto de
montagem a partir do `SCRIPT_NAME`). O Laravel enxerga `/login`, não `/central/login`.

**Decisão:** `apiPrefix: ''` no `bootstrap/app.php`. Um `apiPrefix: 'central'` produziria
`/central/central/login`. As rotas passaram de `/api/...` para a raiz; o cliente do front usa
`const BASE = '/central'`, e o proxy do Vite reescreve `/central/*` para a raiz do `artisan serve`,
já que em desenvolvimento não há Apache para fazer essa remoção.

O `<Directory>` que eu havia criado em `/etc/httpd/conf.d/` para liberar o alvo do symlink era
**inerte** e foi removido: o Apache não canonicaliza o link, então serve por
`/home/fertways/public_html/central/` e aplica o `<Directory /home/fertways/public_html>` do
Virtualmin. O `.env` continua inacessível porque vive acima de `public/`, não por causa daquele
bloco. Verificado: `/central/.env` e `/central/../.env` devolvem 404.

---

## D-26 — Sem `route:cache`: ele quebra a raiz de uma aplicação montada em subcaminho
**Data:** 2026-07-09 · **Status:** decidido · **Encontrado por bug em produção**

`GET /central/` devolvia **405**, e `GET /central` devolvia **404**, embora `/central/colony` e
`/central/up` funcionassem. Só acontecia com as rotas em cache.

Causa: o `CompiledRouteCollection` do Laravel remove a barra final do `REQUEST_URI` antes de casar
a rota. Com a aplicação montada em `/central`, o `REQUEST_URI` `/central/` vira `/central` — que é
exatamente o `baseUrl` — e o caminho da rota fica vazio, não casando com a rota `/`.

**Decisão:** não usar `route:cache` neste deploy. São 12 rotas; o ganho é irrelevante perto de um
404 na raiz da API. O `config:cache`, onde está o ganho real, continua ativo.

A raiz `/` do backend deixou de servir a página de boas-vindas do Laravel (que não dizia nada e
revelava a versão do framework) e passou a devolver um índice JSON dos endpoints.

---

## D-27 — A suíte de testes precisa ser imune ao `config:cache` de produção
**Data:** 2026-07-09 · **Status:** corrigido · **Quase causou perda de dados**

Este repositório é implantado na mesma máquina em que se roda a suíte. Lá existe um
`bootstrap/cache/config.php` gerado por `php artisan config:cache`. Um config em cache é lido
**antes** do `.env` e **ignora todo o bloco `<env>` do `phpunit.xml`**.

Uma sonda dentro de um teste mostrou o que a suíte realmente enxergava:

```
app.url          = https://fertways.tars.art.br/central
database.default = mysql
app.env          = production
config cached?   = SIM
```

Ou seja: os testes de Feature, que usam `RefreshDatabase`, estavam apontados para o banco de
**produção** `fertwaysbd`. Nada foi apagado apenas porque `migrate:fresh` se recusa a rodar sob
`APP_ENV=production` sem a flag de força. Isso é sorte, não projeto.

O sintoma visível era outro: 28 testes falhando com 404, porque `$this->get('/')` deriva a URL de
`app.url` e passava a bater em `/central/...`, que não é rota.

**Correção:** o `phpunit.xml` aponta `APP_CONFIG_CACHE`, `APP_ROUTES_CACHE` e `APP_EVENTS_CACHE`
para caminhos inexistentes. `configurationIsCached()` devolve false, a configuração é lida dos
arquivos e o `<env>` volta a valer. Verificado com o config de produção presente no disco:
`database.default=sqlite`, `app.env=testing`, 59 testes verdes.

Fixar `APP_URL` no `phpunit.xml` (primeira tentativa) resolvia só enquanto o cache não existisse.

---

## D-28 — Central de Transportes não entrega caminhões de graça
**Data:** 2026-07-09 · **Status:** decidido pelo próprio GDD

Duas passagens se contradizem:

- **§28.5** ("Regra Definitiva"): "Os caminhões correspondentes ao nível atual **já estão incluídos
  no upgrade da Central — sem custo adicional**."
- **§5** (resumo de Logística e frota): "O upgrade libera capacidade de manter veículos ativos;
  **não entrega caminhões gratuitos**."

Diferente do caso do tributo (D-11), aqui a **tabela de precedência da seção 0 resolve
expressamente**. Ela lista o tema "Central de Transportes — interpretação de caminhões concedidos
pela estrutura" e a decisão vigente é: "**Libera vagas de frota; veículo é fabricado ou adquirido
separadamente.**"

**Decisão: o nível da Central define o número máximo de veículos de carga simultâneos. Nenhum
caminhão é concedido.** A §28.5 está revogada nesta matéria.

---

## D-29 — O GDD não define o mapa; adotamos grade 100×100 com Capital no centro
**Data:** 2026-07-09 · **Status:** decidido pelo usuário (fora do GDD)

O GDD exige que a posição importe — §25.6: "dois colonos vizinhos comerciam rápido e barato; dois
colonos em regiões opostas do planeta pagam muito mais" — e põe o Mercado Central "no núcleo do
mapa" (§25.8). Mas **nunca define coordenadas, tamanho, nem métrica de distância**. A palavra
"slot" aparece como unidade de velocidade (§21.2: "4 slots de mapa por minuto") sem que slot seja
definido geometricamente. A tabela `colonies` sequer tinha coordenadas.

**Decisão do usuário:** grade quadrada 100×100, coordenadas inteiras, Capital em (50,50),
distância euclidiana arredondada meio-para-cima. As distâncias de exemplo do §25.6 (5, 15, 30 e 60
slots) caem em faixas plausíveis nessa grade; os cantos opostos ficam a 140 slots.

A posição de fundação é **sorteada** entre as células livres. Qualquer regra determinística (a
próxima livre, a mais perto da Capital) daria vantagem logística sistemática a quem fundasse
primeiro, o que contraria o pilar "competição justa por design".

---

## D-30 — A viagem é ida e volta; carga sai no despacho, tributo incide na entrega
**Data:** 2026-07-09 · **Status:** decidido

O GDD diz que o veículo "fica indisponível até completar a viagem (ida, ou ida e volta)" (§25.5) e
que "o veículo fica disponível novamente apenas após completar o trajeto". Não diz **quando** a
carga deixa o estoque nem **quando** a energia é cobrada.

**Decisões:**
1. **A carga sai do estoque no despacho**, não na entrega. É o que torna o calote do comércio
   informal "real e visível" (§25.7): se A entrega e B nunca despacha, A perdeu o recurso. Se a
   carga só saísse na entrega, um veículo interceptado devolveria o recurso à origem.
2. **A energia dos dois trechos é debitada no despacho.** Cobrar na chegada permitiria um veículo
   partir sem ter como voltar. Energia é recurso inteiro, e a viagem pode custar fração de kWh:
   arredondamos **para cima**, porque cobrar a menos criaria energia do nada.
3. **O tributo incide no fim da ida**, conforme D-11 (§25.2 vence §8.3). A `economic_event_key` é
   `entrega:{veiculo}:{timestamp de partida}:{recurso}` — deriva do evento de entrega, e o índice
   único de `tax_events` segura retry do cron e crons concorrentes.
4. O tributo é **retido em unidades do próprio recurso** (D-12), truncando: `intdiv(qtd × bps,
   10000)`. Arredondar para cima cobraria acima da alíquota em cargas pequenas.

---

## D-31 — A energia do Caminhão a 5 slots: o GDD errou a própria conta
**Data:** 2026-07-09 · **Status:** exceção fixada em teste

§21.3 publica: Caminhão a 1,5 slots por minuto, 3 kW/h por minuto de viagem. A 5 slots o tempo
exato é 3,333… min, logo a energia exata é **10,0 kWh**. A tabela do §25.6 publica **9,9** — o GDD
multiplicou 3 pelo tempo **já arredondado para exibição** (3,3). Nas outras três distâncias da
tabela (15, 30 e 60) o tempo é redondo e a diferença desaparece.

**Decisão:** o motor calcula pelo tempo exato, em segundos inteiros. O teste
`tests/Gdd/LogisticaSpecsTest.php` fixa a exceção, para que ninguém "conserte" a fórmula tentando
bater com a célula. É o mesmo tratamento dado ao Tanque de Combustível nível 4 (D-02).

**Fora do MVP, documentado:** a depreciação e manutenção de §16.4 (veículo perde velocidade e
capacidade com o uso, e trava abaixo de um limite crítico). O GDD descreve o comportamento mas
**não publica a curva de desgaste, o limite crítico nem o custo de manutenção**. Implementar agora
exigiria inventar valores. Decisão do usuário: fica para v2.

---

## D-32 — A conta no Mercado Central: reserva no despacho, tributo em cada entrega física
**Data:** 2026-07-09 · **Status:** decidido

§25.8 exige que o recurso seja **fisicamente entregue** ao Mercado antes de poder ser vendido, e
que o recurso comprado fique numa conta até o colono "enviar um veículo próprio para retirá-lo e
levá-lo até seu slot". O GDD não diz como essa conta se comporta.

**Decisões:**
1. **A conta é um saldo por colônia e recurso** (`market_accounts`), fora do estoque da colônia:
   não conta para o `storage_cap`, não é produzida nem consumida. Entra por veículo, sai por veículo.
2. **A retirada reserva o saldo no despacho**, não na chegada do veículo ao Mercado. É a mesma
   regra da carga em D-30: sem reservar, dois veículos partiriam prometendo o mesmo saldo e um
   voltaria vazio depois de gastar energia. O veículo viaja vazio na ida; `vehicles.trip_purpose`
   distingue `entrega` de `retirada`, e `cargo_json` numa retirada é a carga **reservada**.
3. **Depósito e retirada são dois fatos tributáveis**, porque são duas entregas físicas. §25.9
   cobra o tributo "uma única vez, no momento da entrega física pelo veículo" — uma vez **por
   entrega**. Consequência econômica: mandar 1.000 de Metal Bruto ao Mercado e trazê-lo de volta
   sem vender custa 59 unidades (30 + 29). Não é bug; é o preço de mudar de ideia. Fixado em teste.

   ⚠️ **Isto contradiz o §07, conscientemente.** O §07 ("Comércio entre colonos e federações")
   diz: *"Proibição de dupla incidência: a mesma unidade de recurso não pode ser tributada pela
   entrega, pela venda e pela retirada"* e *"A retirada física posterior gera custo de
   energia/logística, não novo tributo sobre o mesmo lote"*. A tabela de incidência do §07 vai
   além: o tributo de recurso incide na *"transferência entre proprietários, quando a entrega
   muda a propriedade"*, e **não incide** *"no deslocamento de recursos entre estruturas do mesmo
   dono"* — o que isentaria o depósito também, já que o dono não muda.

   **O usuário arbitrou pelo §25.8 em 2026-07-09**, ciente disso: tributo de recurso em cada
   entrega física, inclusive a retirada. Não "conserte" isto sem perguntar. A escolha foi feita
   depois de ler os dois lados, e é a mesma linha do D-11 (§25.2 vence §8.3).
4. O tributo do depósito incide no **fim da ida**; o da retirada, no **fim da volta** ("tributo na
   chegada", §25.8). As `economic_event_key` são `deposito:…` e `retirada:…`, nunca colidem, e
   preservam o prefixo `entrega:` já gravado para as entregas entre colônias.

**Fora desta fatia:** a **venda** por Fert$. Ela depende de um preço, e §22.2 e §24.8 divergem em
trinta e oito vezes para os Componentes Eletrônicos (**D-24, ainda sem arbitragem**). Depósito e
retirada não dependem de preço nenhum, então foram primeiro. §25.8 garante que a venda, quando
existir, **não gera novo tributo de volume** — a movimentação física já foi tributada.

---

## D-35 — Livro de ofertas do Mercado Central: escrow, taxa por categoria, sem faixa de preço
**Data:** 2026-07-09 · **Status:** decidido

O §06 diz que o preço-base "é **faixa de segurança, não preço obrigatório de compra e venda**" e
que "jogadores podem negociar dentro da faixa". O §07 descreve o fluxo: "O vendedor transporta o
lote até a doca de mercado. Ao chegar, o lote é reservado em escrow e a listagem é criada. O
comprador paga em Fert$; o sistema transfere o crédito líquido ao vendedor e registra a taxa de
mercado." Logo **o Mercado não compra nem vende: ele casa ordens de colonos.**

**Decisões (usuário, 2026-07-09):**
1. **Livro de ofertas com escrow**, não preço fixo. É o que o §07 descreve e o que o critério de
   aceite do MVP (§16) chama de "Mercado em escrow". Vender exige o recurso **já na doca**: sem
   entrega física não há listagem. Comprar reserva Fert$ no ato.
2. **A taxa de fechamento reusa as alíquotas do §8.3** (3% primários, 2% secundários, 1% raros),
   aplicadas ao **valor em Fert$**, não ao volume. O §07 pede "parâmetros públicos por categoria" e
   não publica a tabela; o §8.3 é a única tabela por categoria que existe. Nenhum número inventado.
   Ela recai sobre **o vendedor** — §07: "crédito líquido ao vendedor".
3. **Sem teto e sem piso no MVP.** O §06 fala em "faixa de segurança" e diz que a Secretaria altera
   "teto, piso", mas **não publica a largura da faixa**. Inventar um percentual ou travaria o
   mercado ou não travaria nada. O preço-base é exibido como referência. Quando a Secretaria
   existir, teto e piso entram sem migration: são política, não schema.
4. **O preço de execução é o da ordem em repouso**, não o de quem cruza. Convenção de qualquer
   livro; o GDD não diz outra coisa. Quem cruza pode ganhar preço melhor que o pedido — e a
   diferença escrowada volta ao comprador.
5. **Uma colônia não casa com a própria ordem.** §26.4 trata conta vinculada como fraude; casar
   consigo mesmo é a versão trivial disso e só serviria para simular volume. As duas ordens ficam
   no livro.

`market_orders` **já existia** desde 2026-07-08, órfã, com as colunas de escrow e o status
`parcial`. `tax_events.kind` já previa `mercado_venda`, e a migration já dizia que ali a base é
"valor em micro-Fert$, conforme `kind`". Nenhuma migration nova foi precisa.

**Verificado em produção:** vendedor e comprador começam com 50 Fert$ cada; após um negócio de 100
unidades a 0,05 Fert$, ficam com 54,85 e 45,00. A soma cai de 100 para 99,85 — exatamente a taxa
de 3%. O Mercado não cria Fert$.

**Não implementado, e o GDD pede:** `§07` cita "serviço logístico público" como alternativa ao
veículo próprio na retirada. Não existe. O comprador precisa de Furgão ou Caminhão.

---

## D-36 — O e2e apagou o banco de produção. `config:cache` derrota `env()`.
**Data:** 2026-07-09 · **Status:** corrigido · **Incidente**

`tools/e2e.sh` exportava `DB_CONNECTION=sqlite` e rodava `migrate:fresh --seed`. O Laravel ignorou
a variável e resolveu `mysql fertwaysbd`: existe `bootstrap/cache/config.php` neste deploy, e **com
a config cacheada `env()` não é lida**. O `migrate:fresh` apagou todas as tabelas do jogo em
produção.

O binlog do MariaDB está **desligado** (`log_bin=OFF`), então não há recuperação ponto-a-ponto. O
backup diário mais recente era de 03:00, anterior às contas de teste criadas naquela manhã: elas
se perderam de vez.

**Isto já era sabido.** O D-27 registra exatamente esta armadilha e o `phpunit.xml` se protege dela
apontando `APP_CONFIG_CACHE` para um arquivo inexistente. O script novo não replicou a proteção.

**Correções:**
1. `tools/e2e.sh` exporta `APP_CONFIG_CACHE` para um caminho inexistente, como o `phpunit.xml`.
2. **Guarda antes do `migrate`:** o script pergunta ao próprio Laravel qual conexão ele resolveu
   (`config('database.default')` e o caminho do SQLite) e **aborta** se não for o banco temporário.
   A guarda é testada sabotando o remédio: sem ele, o script morre com código 1 antes de tocar em
   `migrate`, dizendo `resolveu [mysql fertwaysbd]`.

**Lições, para quem escrever a próxima ferramenta:**
- Neste deploy, exportar `DB_*` **não** basta para redirecionar o banco. Sempre exportar também
  `APP_CONFIG_CACHE`, ou apagar o cache.
- Toda ferramenta destrutiva (`migrate:fresh`, `db:wipe`, `truncate`) deve **verificar o alvo** e
  abortar, em vez de confiar que o ambiente foi configurado certo.
- Backup diário às 03:00 e binlog desligado significam **até 24 h de perda**. Se algum dado passar
  a importar, ligar o binlog é o primeiro passo.

---

## D-37 — O diretório de colônias lista todas, sem névoa de guerra.
**Data:** 2026-07-09 · **Status:** implementado · **GDD: omisso** · **Fato CORRIGIDO em 2026-07-11**

`POST /vehicles/{id}/dispatch` aceita `destination_type = colonia` desde a fatia de logística, e
exige a **chave primária** da colônia de destino. Não havia endpoint que revelasse o `id` de
ninguém: o despacho entre colônias era inalcançável pela UI, que só oferecia o Mercado Central.

**O GDD não decide.** Ele nunca usa a palavra "diretório". O §24.2 garante que o slot de um colono
é clicável no mapa e mostra avatar e nickname; o §25.6 exige que a posição no mapa importe. Mas não
há lista, busca, nem regra de descoberta. O D-29 já registrava que o GDD sequer define coordenadas.

**Arbitrado pelo usuário:** `GET /colonies` lista **todas** as colônias, ordenadas da mais próxima
à mais distante, sem névoa de guerra e sem limite de alcance.

**Consequência assumida:** o **Drone de Exploração** (§05, §21) existe no GDD para "revelar mapa ao
redor do slot e zonas neutras". Com o diretório aberto, não lhe restam colônias a revelar — só as
zonas neutras. O GDD nunca publicou **raio nem persistência** de revelação, então honrar a névoa
exigiria inventar as duas coisas. Preferiu-se a decisão explícita à mecânica inventada.
**Não "conserte" isto achando que é esquecimento.** Se um dia a névoa entrar, este é o ponto.

> **Errata (2026-07-11).** Esta decisão dizia "raio, persistência nem **custo** de revelação". O
> custo é o único dos três que **está publicado**: §21.4 traz `50 75 112 169 253` e o §4.3 do
> aditivo v3.4 traz `50 83 136 225 371`, e o próprio GDD resolve qual vale — a curva **1,65×** do
> v3.4. Não era lacuna. O levantamento está no **D-52**, que apontou o erro; aqui fica a correção,
> para quem ler o D-37 sozinho. As lacunas de verdade do Drone continuam **duas** (raio e
> persistência), e a elas o D-52 acrescenta velocidade e onde é fabricado.

## D-38 — `building_levels_sum` é um sinal de porte arbitrado, e **não** é o Marco do GDD.
**Data:** 2026-07-09 · **Status:** implementado · **GDD: define o conceito, não a fórmula**

O diretório publica, de cada colônia, um sinal de porte. O GDD **nomeia marcos** — 1 Sobrevivente,
5 Colono, 10 Pioneiro, 20 Desbravador, 35 Construtor, 50 Arquiteto, 75 Guardião, 100 Lenda de
Fertways (§03, "Marcos de colonização") — mas **nunca publica como o número se calcula**. E
`colonies.milestone` é uma string congelada em `colonizacao_inicial` desde a fundação, que não é
sequer um dos nomes do GDD: nada no código a atualiza.

**Arbitrado pelo usuário:** o campo é a **soma dos níveis das construções** da colônia.

Chama-se `building_levels_sum`, e não `level` nem `milestone`, exatamente para **não** ser
confundido com o Marco. Os limiares 1/5/10/20 do GDD **não se aplicam a ele**. A colônia nasce com
as 16 construções em nível 0, então a soma começa em zero — o valor honesto: ninguém construiu nada.

Quando o Marco for implementado de verdade (o que exige decidir sua fórmula, e atualizá-lo no
tick), será um **campo à parte**, não a renomeação deste.

**Privacidade.** O diretório expõe `id`, `name`, `nickname`, `x`, `y`, `distance` e
`building_levels_sum`. Recursos, saldo e frota do vizinho ficam de fora: o §13 fala em "relatórios
privados" sem enumerar o que é público, e escolher destino não é espionar. As coordenadas entram
por decisão do usuário — o §24.2 já torna o slot alheio clicável no mapa, e uma tela de mapa
precisará delas.

## D-39 — O diretório de deploy é separado do diretório de trabalho.
**Data:** 2026-07-09 · **Status:** implementado · **GDD: não se aplica**

Até aqui `public_html/central` era symlink para `apps/fertways/backend/public`: **salvar um arquivo
era publicá-lo**, no próximo request, sem intervalo. A logística já quebrou a fundação de colônia
por alguns minutos assim — o código novo pedia `colonies.x`/`y` antes de a migration rodar.

**Decidido pelo usuário (2026-07-09), depois de recusar a mesma proposta na sessão anterior:**

```
trabalho → /home/fertways/apps/fertways      (onde se edita; ninguém serve)
deploy   → /home/fertways/deploy/fertways     (clone; é o que o Apache serve)
```

O `origin` do clone é o **repo local**, não o GitHub — escolha do usuário: publicar não passa a
depender de rede nem de credencial. `git -C /home/fertways/deploy/fertways pull` é o deploy.

**O cron foi repontado junto.** Ele chamava o `artisan` da árvore de trabalho; se só o symlink
mudasse, o Apache serviria a cópia e o tick continuaria rodando código não-commitado. Backup do
crontab antigo em `/home/fertways/deploy/.crontab-anterior`, e do alvo antigo do symlink em
`.symlink-anterior` — reverter é uma linha de cada.

**Publicar ainda é instantâneo dentro da cópia de deploy**, porque o Apache serve o PHP direto, sem
build. A separação não elimina a janela entre `git pull` e `migrate`: ela dá **um lugar onde fechar
a porta antes**. Por isso `tools/deploy.sh` envolve pull + composer + migrate em `artisan down` /
`artisan up`, e só então tira a manutenção. O tick pulado durante a manutenção é inofensivo: ele
avança o mundo por delta de tempo, e o minuto perdido volta no tick seguinte.

**O que esta decisão não resolve.** Os dois `.env` apontam para o **mesmo** MariaDB `fertwaysbd`.
Um `migrate:fresh` ou `db:wipe` na árvore de trabalho ainda apaga o banco do jogo — o D-36
continua valendo palavra por palavra, e `bootstrap/cache/config.php` continua derrotando `env()`.
Separar o banco de desenvolvimento do de produção é um segundo trabalho, ainda não feito.

## D-40 — O Acordo de Troca não tem escrow. O calote continua real.
**Data:** 2026-07-09 · **Status:** implementado · **GDD: §26.5, explícito**

O §26.5 é literal: *"Mecanismo opcional que registra evidência objetiva **sem usar bloqueio
automático de recursos (escrow)** — o risco do calote continua real, mas agora há prova."*

A migration `..._000011_create_trade_agreements_table.php` dizia, num comentário, o oposto: *"Com
ele, há garantia."* **O comentário estava errado, não o GDD.** Confirmado pelo usuário em
2026-07-09: "não há garantia nos acordos, o risco de calote deve ser real". O Acordo não protege
ninguém — ele **testemunha**. É o que o distingue do Mercado Central (§07), que tem escrow, e é o
que dá sentido ao índice de Confiança Comercial: sem risco não haveria o que medir.

Corolário: nada é reservado ao propor nem ao aceitar. Quem prometeu pode simplesmente não entregar.

## D-41 — Cumprir é entregar fisicamente, e vale o líquido que chega.
**Data:** 2026-07-09 · **Status:** implementado · **GDD: silente — arbitrado**

O §26.5 fala em "prazo de cumprimento" e nunca define cumprimento. **Arbitrado pelo usuário:**

- **Cumpre quem entrega.** A carga sai por `DespacharVeiculo` e chega por `ConcluirTrechos`; a
  chegada abate a promessa. Nada depende de o outro lado confirmar recebimento — declaração não é
  prova, e o §26.8 diz que "logs do servidor prevalecem" sobre print.
- **Conta o líquido**, não o bruto. `ConcluirTrechos::entregar()` credita ao destino a carga menos
  o tributo de transporte (§25.2, D-12). A promessa é do ponto de vista de quem recebe: prometi
  1.000, você precisa **receber** 1.000. Quem despacha manda mais e arca com o tributo.
- Para que ninguém descubra que caloteou por três unidades de tributo, o acordo **publica o bruto
  necessário** de cada termo: `GET /central/trade/agreements` devolve, junto do prometido, quanto
  é preciso despachar para que o líquido bata.
- **O vínculo é explícito.** `POST /central/vehicles/{id}/dispatch` aceita `trade_agreement_id`, e
  só a carga que o aponta abate aquele acordo. Casar por janela de tempo faria um presente casual
  virar pagamento, e escolheria arbitrariamente entre dois acordos abertos do mesmo par.

**Consequência aceita:** termos em Fert$ ficam de fora. O jogo não move Fert$ entre colônias, só
recursos por veículo. Um acordo só promete recursos do catálogo. Ver as pendências.

## D-42 — O prazo mínimo deriva da viagem, mais 12 h de folga.
**Data:** 2026-07-09 · **Status:** implementado · **GDD: silente — arbitrado**

O §26.5 exige prazo e não publica limites. **Arbitrado pelo usuário:** o prazo proposto tem de ser
pelo menos o tempo de viagem do **veículo mais lento** (Caminhão de Carga, 1,5 slot/min) entre as
duas colônias, **mais 12 horas de folga**.

Deriva da viagem para que não se possa propor um prazo fisicamente impossível de cumprir — a 140
slots o Caminhão leva 93 min, e um prazo de 30 min seria calote fabricado pelo proponente. A folga
de 12 h é generosa de propósito: o colono precisa **entrar no jogo** para despachar, e um calote
acidental sujaria a Confiança Comercial de gente honesta.

Não há prazo máximo. O GDD não pede um, e o acordo que nunca expira simplesmente nunca vira
evidência — o custo recai sobre quem o propôs.

## D-43 — Confiança Comercial: começa em 500, bloqueia abaixo de 200, +10 / −50, com piso de 500 F$.
**Data:** 2026-07-09 · **Status:** implementado · **GDD: nomeia os efeitos, não publica os números**

O §26.2 diz que Confiança Comercial "baixa" bloqueia o Mercado Central, e a coluna nasce em **0**
numa escala de 0 a 1000. Tomado à letra, ninguém jamais entraria no Mercado — que já está no ar.
O GDD não publica valor inicial, limiar, nem quanto vale cumprir. **Arbitrado pelo usuário:**

| O quê | Valor |
|---|---|
| Valor inicial | **500** (neutro, meio da escala) |
| Bloqueio do Mercado Central | abaixo de **200** |
| Acordo cumprido | **+10** |
| Acordo quebrado | **−50** |

O calote custa cinco vezes o que cumprir rende: confiança leva tempo e se perde depressa. Do valor
inicial, seis calotes seguidos fecham o Mercado para o colono.

**Piso anti-farming.** O §26.3 exige 500 Fert$ de valor de mercado (somando os dois lados) para que
uma transação possa ser avaliada. **O usuário estendeu o piso ao Acordo:** um acordo abaixo de 500
Fert$ registra histórico e status, mas **não move o índice**. Sem isso, dois amigos trocariam 1
unidade de minério mil vezes. O §26.1 já lista "avaliação de transações triviais" como abuso.

Uma migration sobe os quatro colonos existentes de 0 para 500. É reescrever reputação de conta já
criada — aceito porque nenhum acordo jamais existiu, então nenhum histórico se perde.

## D-44 — O Ministério das Reputações vai ser construído inteiro, com nomeação manual.
**Data:** 2026-07-09 · **Status:** decidido, não implementado · **GDD: §26.6–26.8**

Avisei ao usuário que o rito depende de chat, federações, missões, terraformação, cargos e leilões
— **nada disso existe** — e que construí-lo agora produz um Ministério parcialmente oco. Ele optou
por construí-lo assim mesmo. Arbitragens que essa escolha exigiu:

- **Conciliador por nomeação manual.** O §26.6 exige "Conduta Social alta + Status Cívico alto", e
  Conduta Social só se move por chat (§26.2). Enquanto não houver chat, o cargo é ligado por
  comando de artisan. A elegibilidade do §26.6 entra quando os índices ganharem substrato.
- **A "equipe" da apelação (§26.7) é o operador do jogo**, fora do jogo. Era a única leitura que
  funciona com 4 colônias. O bônus de +3 Fert$ só se paga depois que a janela de apelação fecha.
- **Suspensão automática em 5 reversões** (o §26.7 diz "limite configurável" e não publica o
  número).
- **As cinco punições do §9.4 são registradas, mesmo as inertes.** Silêncio temporário precisa de
  chat e Bloqueio de leilões precisa de leilões; ficam gravados com prazo e passam a morder sozinhos
  no dia em que esses sistemas existirem. Nenhuma migration futura reescreve histórico.

**Bloqueio conhecido, ainda não arbitrado:** o §9.4 manda deduzir pontos da "reputação geral", que o
§26.2 **aboliu** e o §26.9 proíbe compensar entre índices. E o §26.8 chama de "tabela fixa de
punições por tipo de violação confirmada" um mapa que o GDD **nunca publica**. Os dois têm de ser
arbitrados antes do conciliador julgar qualquer coisa. Não invente.

> **Os dois foram resolvidos em 2026-07-09, antes de uma linha de código.** O primeiro pelo próprio
> GDD, na tabela de precedência da seção 0 — ver **D-48**. O segundo pelo usuário, que arbitrou a
> tabela inteira — ver **D-49**. A regra de leitura que ambos usam está no **D-47**. O Ministério
> está desbloqueado; segue não implementado.

## D-45 — O deploy separado só passa a valer depois de recarregar o php-fpm.
**Data:** 2026-07-09 · **Status:** implementado · **Corrige o D-39**

O D-39 trocou `public_html/central` para apontar da árvore de trabalho para a cópia de deploy, e
não teve efeito nenhum. Com `opcache.revalidate_path=0` — o padrão — o opcache resolve o caminho
que o Apache lhe entrega (`/home/fertways/public_html/central/index.php`, o symlink) uma única vez
e guarda o destino. Os workers do php-fpm, no ar desde antes da troca, seguiram executando a árvore
de trabalho. É a armadilha clássica do deploy por symlink.

O resultado era o oposto do que o D-39 queria: **editar continuava publicando na hora, agora em
silêncio**. O Acordo de Troca entrou em produção no instante em que foi commitado, sem passar pelo
`deploy.sh` — e sem a migration, que só rodou hoje. Chamada autenticada ao Acordo dava 500.

Pior: o **cron do tick roda pelo CLI**, que não tem esse cache, e portanto executava a cópia de
deploy. Web no código novo, tick no código velho, **um banco só**. Na prática o `ExpirarAcordos`
nunca rodava.

Nada disso aparecia na fumaça do `deploy.sh` (`200` no front, `401` em `/colony`): as duas árvores
respondem igual. Agora o script recarrega o pool e **pergunta ao opcache** qual árvore ele está
executando, abortando se encontrar qualquer script compilado sob `/home/fertways/apps/`.

O reload é gracioso, mas o master do php84 é compartilhado com os outros domínios do servidor.

## D-46 — O banco de desenvolvimento é separado do de produção.
**Data:** 2026-07-09 · **Status:** implementado · **Fecha o D-36**

`fertwaysdev` é o banco da árvore de trabalho; `fertwaysbd` continua sendo o de produção, e só a
cópia de deploy aponta para ele. Um `migrate:fresh` em `apps/fertways` já não apaga o jogo.

Junto, apagamos o `backend/bootstrap/cache/config.php` da árvore de trabalho. Era ele o mecanismo
do D-36: **com a config cacheada o Laravel não lê `env()`**, então mudar o `.env` (ou exportar
`DB_CONNECTION=sqlite`) não redirecionava nada. A árvore de trabalho não é servida por ninguém e
não tem por que ter config cacheada. **Não rode `config:cache` em `apps/fertways`.**

As proteções do D-27 continuam necessárias e valem para as ferramentas destrutivas: `phpunit.xml` e
`tools/e2e.sh` exportam `APP_CONFIG_CACHE` para um caminho inexistente e conferem o alvo antes de
migrar. O banco separado é uma segunda trava, não um substituto.

O backup diário de 03:00 continua sendo o único recurso contra perda: o binlog do MariaDB segue
desligado.

## D-47 — Como ler o GDD quando ele se contradiz: a regra de precedência.
**Data:** 2026-07-09 · **Status:** decidido pelo usuário

Ordem, do mais forte ao mais fraco:

1. **A tabela de precedência da seção 0.** Ela resolve doze divergências expressamente, e a coluna
   "decisão vigente" é "o único valor que pode ser implementado". Foi ela que decidiu o D-28.
2. **O parágrafo de número maior, dentro da mesma parte.** Regra do usuário: o mais recente vence.
3. Na ausência das duas, o usuário arbitra e a decisão vem para cá.

**Por que o item 2 precisa da ressalva "dentro da mesma parte".** A premissa "número maior = mais
recente" só vale dentro da Parte II. Ali as seções §24–§28 são complementos reintegrados depois, e
se anunciam assim: "Preços Atualizados" (§24.8), "Regra Definitiva" (§28.5), "Problema
Identificado" (§26.1). Já **entre** as partes a numeração recomeça: a Parte I (v3.2, sanitizada) é
mais nova que a Parte II (v3.0), apesar de ter números menores.

Sem a ressalva, a regra devolveria os caminhões grátis do §28.5 e revogaria o D-28 — que a seção 0
decidiu no sentido oposto. Com ela, o D-28 fica de pé.

**A regra não vira nenhuma decisão antiga do avesso.** Conferido uma a uma: §25.2 vence §8.3
(D-11), §24.8 vence §22.2 (D-24), §25.8/§25.9 vencem o §07 (D-32), §24.8 vence o §07 (D-33, D-34).
Em todas, o parágrafo de número maior já havia ganhado. O único caso em sentido contrário é o
D-28, e ele é regido pelo item 1.

**A regra não preenche lacuna.** Ela decide entre dois textos que se contradizem. Onde o GDD
simplesmente não publica um número, continua valendo a regra de ouro: **pergunte, não invente.**
O §26.8 é o exemplo — ver D-49.

## D-48 — Não existe "reputação geral". São quatro índices isolados.
**Data:** 2026-07-09 · **Status:** decidido pelo próprio GDD · **Fecha o 1º bloqueio do D-44**

O §9.4 manda deduzir "pontos da reputação geral" e o §9.5 afirma que "a reputação em Fertways é
única". O §26.2 divide a reputação em quatro índices e o §26.9 proíbe compensar entre eles.

Não foi preciso desempatar por número (D-47, item 2): **a tabela da seção 0 resolve o tema
expressamente**, na linha "Reputação" — formulação preservada da v3.0: "índice único e efeitos
compensáveis"; decisão vigente: "**quatro índices isolados, sem compensação cruzada**".

Consequência: a "reputação geral" do §9.4 e todo o §9.5 são texto morto. **Toda punição que deduz
pontos tem de nomear qual índice ela atinge**, e o §26.2 já diz qual, pela coluna "o que mede":

| Índice | Move-se por |
|---|---|
| 💰 Confiança Comercial | Acordos de Troca, avaliações de comércio |
| 💬 Conduta Social | chat, denúncias de chat confirmadas |
| 🏛️ Status Cívico | tributos, missões da administração, terraformação |
| ⚔️ Honra Militar/Diplomática | guerras, ataques a aliados, tratados |

São **quatro**, não três. As quatro colunas já existiam em `users` desde a primeira migration — mas
só `confianca_comercial` nascia em 500 (D-43); **as outras três nasciam em zero**, que na escala do
§26.2 é o pior colono possível, não um colono novo. Ninguém percebeu porque nada as movia. A
migration do Ministério as sobe a 500 (D-50).

## D-49 — A tabela fixa de punições do §26.8, que o GDD nunca publicou.
**Data:** 2026-07-09 · **Status:** decidido pelo usuário · **Fecha o 2º bloqueio do D-44**

O §26.8 exige "tabela fixa de punições por tipo de violação confirmada — elimina decisão totalmente
subjetiva" e remete ao §9.4. O §9.4 publica **os cinco nomes das punições e nada mais**: "Pontos
deduzidos da reputação geral. **Configurável**", silêncio "por **X horas/dias**", restrição
comercial "por **X dias**". O painel do Ministério lista "**Configurar** pontos de reputação
perdidos por tipo de punição". O GDD nunca publica os tipos de violação, nem o mapa, nem um número.

Seguir o §26.8 é **construir** essa tabela. Ela não existia para ser seguida. Tudo abaixo é
arbitragem do usuário (2026-07-09), exceto os tipos de violação — cada um vem de um parágrafo.

**Gravidades.** Escala 0–1000 do §26.2. Referência: cumprir um Acordo rende +10, caloteirar custa
−50, e o Mercado fecha abaixo de 200 (D-43).

| Punição | Pontos |
|---|---|
| Advertência | 0 — só registro, como o §9.4 diz |
| Redução — leve | −25 |
| Redução — grave | −100 |
| Redução — gravíssima | −250 |

Uma condenação leve custa menos que um calote; uma gravíssima leva um colono de 500 a 250 num só
caso. O calote automático segue sendo a punição mais barata, e o julgamento humano, a mais cara.

**O mapa.** Gravidade fixa por tipo, sem margem ao conciliador: ele decide se a violação ocorreu, e
a punição sai da tabela. É o que o §26.8 quer dizer com "elimina decisão totalmente subjetiva".

| Violação | Fonte | Índice | Punição | Pontos |
|---|---|---|---|---|
| Calote de Acordo de Troca | §26.5 | Confiança Comercial | automática, sem juízo | −50 |
| Avaliação de 1 estrela injusta | §8.4 | Confiança Comercial | Advertência | 0 |
| Fraude de avaliação / conta vinculada | §26.4 | Confiança Comercial | Redução + histórico mútuo zerado | −250 |
| Sonegar tributo, burlar sistema | §9.5 | Status Cívico | Redução | −250 |
| Abuso em chat confirmado | §26.2 | Conduta Social | Silêncio 24 h + Redução | −25 |
| Reincidência em chat | §26.2 | Conduta Social | Silêncio 24 h + Redução | −100 |
| Atacar aliado registrado | §9.5 | Honra Militar/Diplomática | Redução | −100 |
| Quebra de tratado | §26.2 | Honra Militar/Diplomática | Redução | −100 |
| Calote deliberado reincidente | §26.5 + §9.4 | Confiança Comercial | Restrição comercial 7 d + Redução | −100 |

O §9.5 é texto morto quanto ao índice único (D-48), mas continua sendo a **única** fonte que nomeia
"sonegar tributos" e "atacar aliado registrado" como faltas. É dele que esses dois tipos vêm.

**Durações.** Silêncio temporário: **24 h**. Restrição comercial: **7 dias** — e o 7 não é
arbitrário: o §14.3 exige "não ter tido restrição comercial nos últimos 7 dias" para se candidatar
a cargo, logo 7 dias é a janela que o próprio GDD trata como memória de uma restrição.

**Persona Non Grata.** O §9.4 diz que "reputação negativa bloqueia acesso a leilões", e a escala do
§26.2 não tem negativo. Persona Non Grata passa a ser **Confiança Comercial < 200** — o mesmo
limiar que já fecha o Mercado Central (D-43). Um número, um conceito: quem perdeu a doca perdeu o
leilão.

**Inertes por ora**, como o D-44 já decidiu para as punições: chat, tratados, alianças e leilões não
existem. Os tipos ficam gravados com índice e prazo, e passam a morder sozinhos no dia em que esses
sistemas existirem. Nenhuma migration futura reescreve histórico.

## D-50 — O Ministério das Reputações, e as três lacunas que construí-lo abriu.
**Data:** 2026-07-09 · **Status:** implementado · **GDD: §9.1–9.4, §26.6–26.8**

Denúncia, triagem, conciliador, decisão pela tabela fixa do D-49, punição, apelação e reversão. O
que o §26.8 chama de "regras formais de processo" agora existe em código, com o tick fazendo o que
corre sozinho: reatribuir caso vencido, fechar janela de apelação e pagar a folha.

**§9.3 contradiz o §26.7 sobre a remuneração, e o D-47 resolve.** O §9.3 diz "remunerado em Fert$
**por caso resolvido**"; o §26.7 diz "salário fixo diário — 50 Fert$, **independente do volume de
casos**". Mesma parte do documento, número maior: vale o §26.7. E é o que o próprio §26.7 defende no
título — "Remuneração por Qualidade, não Volume". Pagar por caso resolvido premiaria o conciliador
que despacha depressa, incentivo que o §26.1 lista entre os abusos que a seção 26 veio corrigir.

Três coisas o GDD não fecha, e o usuário arbitrou (2026-07-09):

1. **A janela de apelação é de 48 h.** O §26.7 condiciona o bônus de +3 Fert$ à decisão "não
   revertida em apelação" e nunca diz até quando se apela. Espelhamos o único prazo que o §26.8
   publica: quem julga tem 48 h, quem apela tem 48 h. O bônus cai no primeiro tick depois disso.
2. **"Caso grave" é o que a tabela do D-49 pune com −250.** O §9.2 manda a triagem separar caso
   simples de caso grave e nunca define grave. São `fraude_de_avaliacao` e `sonegacao` — e a §26.4 já
   exigia "revisão manual" para conta vinculada. Conciliador jogador nunca aplica −250.
3. **O salário é emitido pelo Governo**, pelo mesmo caminho do subsídio do §24.7. Vai ao ledger como
   `salario_conciliador` e `bonus_conciliador`, então a inflação que o cargo cria é auditável — o
   §06 exige integridade monetária e um ledger que a comprove. O GDD não modela caixa público; um
   salário pago "dos tributos" teria de inventá-lo.

**Decisões menores, todas forçadas pelo texto:**

- **A evidência mínima do §26.8 é conferida de verdade.** Um acordo qualquer não serve: tem de ser
  entre as duas partes e estar `quebrado`. `print_de_chat` fica gravável no schema e **recusado** até
  haver chat — aceitá-lo seria abrir a porta à denúncia sem prova, que é o que a regra fecha.
  "Log de transação" exige o mesmo anexo: o despacho avulso lança no ledger da origem, sem o destino,
  e não prova relação entre dois colonos. O Acordo é o único registro par-a-par que o servidor tem.
- **O impedimento do §26.8 morde pela metade.** "Membros da própria federação" fica inerte —
  federações não existem. "Transação comercial nos últimos 30 dias" usa o Acordo de Troca, pelo mesmo
  motivo acima. Julgar o próprio caso não é impedimento do §26.8; é absurdo anterior a ele.
- **Prazo vencido não conta reversão.** O §26.7 conta reversão de decisão; deixar as 48 h passarem
  não é decisão. O caso vai a outro conciliador — nunca ao mesmo que já não respondeu — e sobe à
  equipe se não houver outro (§9.3).
- **Decidir depois das 48 h é recusado**, mesmo antes de o tick reatribuir. Entre o vencimento e o
  varrimento há até um minuto, e nele o conciliador lento apagaria o próprio atraso.
- **Reverter estorna, nunca apaga.** A punição fica com `revoked_at`. O §26.8 quer processo
  auditável, e apagar a punição apagaria a prova do erro que alimenta o contador de reversões.
- **A restrição comercial é a única punição de prazo que morde hoje**: ela fecha toda saída de carga
  por 7 dias, para a doca ou para outro colono (§9.4). Silêncio precisa de chat; bloqueio de leilões
  precisa de leilões. Ficam gravados com prazo e passam a morder sozinhos (D-44).
- **A "equipe" não tem rota.** Ela é o operador, por `artisan fertways:equipe` e
  `artisan fertways:conciliador` (D-44). Expor isso na API daria a um colono o poder de suspender
  conciliadores. Ser conciliador **é** ser Neutro Registrado — a seção 0 diz que o status é
  exclusivo do cargo, então não há segunda coluna.
- **Os quatro índices nascem em 500.** Os três que o MVP não movia estavam em zero, que na escala do
  §26.2 é o pior colono possível, não um colono novo. A migration os sobe a 500. Reescrever índice de
  conta criada só foi aceitável porque nada jamais os moveu: nenhum histórico se perdeu.

**Consequência econômica a vigiar:** 50 Fert$/dia por conciliador é emissão contínua, e o kit inicial
de um colono é 50 Fert$. Um conciliador ganha um kit inicial por dia sem jogar. Com quatro colônias
não importa; quando o servidor abrir, é o primeiro número a revisitar.

---

## D-51 — O mapa concêntrico: Capital em (0,0), founders, anel livre, periferia e distritos.
**Data:** 2026-07-10 · **Status:** **implementado e no ar** (Fatias 1 e 2) · **Revoga parte do D-29**

> **Implementação (2026-07-10).** As duas fatias foram ao ar. A produção está no mapa concêntrico,
> com as 4 colônias em slots de founder. O que foi feito:
> - `MapaFertways`: `LADO` 101, `CAPITAL` (0,0), `dentroDoMapa` por `|coord| ≤ 50`, `distanciaExata`,
>   `faixaDe`, `slotsFounder` (48 = 28 populáveis + 20 reservados, ordem canônica determinística) e
>   `podeFundar`. O `distancia()` **half-up do frete continua intacto** — as faixas usam a exata; é
>   a distinção que dá ao disco as suas 48 células.
> - Migration `2026_07_10_160000_mapa_concentrico_coordenadas_com_sinal`: `colonies.x/y` de
>   `tinyint unsigned` para `tinyint` com sinal, deslocando as linhas existentes −50 (com o
>   `unique(x,y)` derrubado durante o UPDATE). **Aplicada em dev (MariaDB), banco vazio** — valida a
>   migration contra o mesmo SGBD da produção.
> - Fundação por escolha: `EscolherPosicao` **apagado**; `CreateColony` e `POST /colony` recebem
>   `x,y` e recusam com erro de domínio (`celula_invalida` / `celula_ocupada`) o que não for slot de
>   founder populável ou periferia livre.
> - `GET /map`: geometria + os 48 slots (reservado/ocupado) + células ocupadas, para o seletor —
>   não exige colônia.
> - Frontend: `Mapa.tsx` remapeado para coords com sinal e Capital no centro; novo `Fundacao.tsx`,
>   o seletor visual (disco de founders ampliado + aba de periferia com clique→célula).
> - Testes: **178 PHP verdes** e os **cinco** e2e verdes (incluindo `fundacao.e2e.mjs` novo).
>
> **Fatia 2 (produção), feita em 2026-07-10.** `deploy.sh` migrou o `fertwaysbd` (deslocou as 4
> colônias −50 e trocou o tipo), e o comando `fertways:realocar-founders --force` — com os quatro
> veículos conferidos ociosos — as levou aos slots (0,1), (0,-1), (-1,1) e (1,-1). Conferido no ar:
> `GET /map` devolve `side 101`, Capital `(0,0)`, 48 slots; `GET /colony` do `publico` devolve
> `(0,-1)`. A migration fora validada antes no `fertwaysdev` (mesmo MariaDB).


O GDD continua sem definir o mapa — o D-29 já registrava isso. O que muda aqui é que o usuário
descreveu a geografia que quer, e ela **contradiz o sorteio do D-29**.

**O que o D-29 dizia:** a posição de fundação é sorteada, porque "qualquer regra determinística
daria vantagem logística sistemática a quem fundasse primeiro".

**O que vale agora:** essa vantagem é o desenho, não um efeito colateral. Quem chega antes escolhe
um slot de *founder*, perto do Mercado Central, e por isso viaja menos e paga menos frete. O
usuário confirmou-o explicitamente: "founders têm privilégio de ficar mais perto do mercado
central, então viajam menos, apenas isso". O sorteio do `EscolherPosicao` morre; o colono
**escolhe** a célula no mapa, inclusive uma periférica, se quiser.

**A grade.** Lado **101**, coordenadas inteiras de −50 a +50 nos dois eixos, Capital em **(0,0)**.
O lado deixou de ser 100 por uma razão geométrica: um lado par não tem célula central, e a Capital
precisa de uma. Com 100 o mapa ficaria torto — 50 células a oeste e 49 a leste —, e a terraformação,
que o usuário quer irradiando do centro para as bordas, sairia desigual. Coordenadas passam a ser
inteiros **com sinal**: `tinyint unsigned` não guarda −50.

**As faixas, por desigualdade sobre a distância euclidiana exata** (não a arredondada do frete):

| Faixa | Regra | Células |
|---|---|---|
| Capital | d = 0 | 1 |
| Founders | 0 < d ≤ 4 | 48 |
| Anel livre | 4 < d ≤ 5 | 32 |
| Periferia | d > 5 | o resto |

A desigualdade exata foi escolhida em vez do arredondamento `floor(d+0,5)` que o frete usa, porque
é ela que preserva o disco de raio 4 com as suas 48 células. Pelo arredondamento, "distância 4"
abrangeria 68 células e o disco deixaria de existir. **Consequência aceita:** "distância 4" quer
dizer uma coisa no mapa e outra na conta do tributo. O anel livre não é fundável nem ocupável; é
respiro entre os founders e a periferia.

**Os 48 founders: 28 populáveis + 20 reservados.** O usuário pediu 30 + 20, que dá 50, e o disco
tem 48. Escolheu preservar os 20 reservados. Eles não têm função ainda — "poderão ser do governo,
das alianças, convidados, npcs, isso no futuro decidiremos" — e ficam em **posições alternadas**.
Alternar exige uma ordem: as 48 células são ordenadas por (distância exata, ângulo em [0, 2π)), e
reservam-se as de índice par entre as 40 primeiras. Sobram 20 reservadas e 28 populáveis. A ordem
é determinística de propósito: dois ambientes que rodem a semeadura têm de produzir o mesmo mapa.

**Os quatro distritos de zona neutra.** Blocos contíguos, "o mais próximo possível das bordas",
um por quadrante: Nordeste, Noroeste, Sudeste e Sudoeste. **30 zonas cada, 120 no total.** 30 = 6×5,
encostado no canto: o distrito Nordeste ocupa x ∈ [45,50], y ∈ [46,50], e os outros três são o seu
espelho. Nenhuma zona neutra fora dos distritos.

**A Mina Governamental fica fora das 120.** O §24.4 publica "2 slots distantes exclusivos do
governo". São dois slots à parte, invulneráveis, e **não** duas das 120 zonas disputáveis. A
posição exata dos dois ainda não foi arbitrada e não bloqueia nada: a Mina Governamental está fora
do escopo atual.

**A terraformação do centro para as bordas é ambientação.** O usuário foi explícito: nenhum efeito
mecânico, o slot periférico já pode ser fundado hoje. Só o visual do mapa mudará, mais adiante.

**As quatro colônias de produção são realocadas para slots de founder.** Elas são, de fato, as
primeiras. Hoje estão em coordenadas sorteadas — `Nova Aurora` a 17 slots da Capital, `publico` a
50, `mapa2` a 40, `teste` a 49. A realocação foi conferida como segura: os quatro veículos estavam
**ociosos**, sem viagem em curso, e mover uma colônia com carga em trânsito deixaria a viagem
apontando para uma distância que já não existe.

---

## D-52 — Zonas neutras e o Drone: o escopo, o gate suspenso e as lacunas que restam.
**Data:** 2026-07-10 · **Status:** escopo decidido; **valores ainda por arbitrar**

Levantamento do GDD feito em 2026-07-10. O **D-37 erra** num ponto de fato: ele afirma que o GDD
"nunca publicou raio, persistência nem custo de revelação" do Drone. O custo **está publicado** —
e em duas versões, resolvidas pelo próprio documento.

**O que o GDD publica sobre o Drone.** §21.4: modo de operação "ida simples ou ida e volta,
configurável por missão"; recarga "automática — armazenado e recarregado no Quartel"; bateria por
nível, em horas, **24 36 54 81 122** (curva 1,50×). §16.1: "revela mapa ao redor do slot e zonas
neutras antes de ocupação", tem placa, é vendável. §16.4: **não deprecia** — só Furgão e Caminhão
depreciam. §05: desbloqueia no Marco 10.

**O custo do Drone, e por que há duas tabelas.** O §21.4 traz `50 75 112 169 253` (curva 1,50×) e
o §4.3 do aditivo v3.4 traz `50 83 136 225 371` (curva 1,65×). Não é lacuna nem contradição
pendente: o bloco `1A` da v3.4 declara que "os custos das seções 20 e 21 são recalculados em
1,65×". **Vence o §4.3**, pela regra do D-47. Vale para Componentes Eletrônicos `50 83 136 225 371`,
Compostos Químicos `15 25 41 67 111` e Metal Bruto `4 7 11 18 30`.

**O gate do Marco fica suspenso.** O §05 tranca zonas neutras no Marco 20 e o Drone no Marco 10, e
`colonies.milestone` está congelado em `colonizacao_inicial` porque o GDD nomeia os marcos e nunca
publica a fórmula. **Decisão do usuário:** construir sem o gate. Zonas neutras e Drone ficam
acessíveis a qualquer colônia. Nada de valor foi inventado — apenas uma trava a menos. Quando o
Marco existir, o gate volta.

**A guerra entra no escopo.** O usuário escolheu zonas neutras completas, com os quatro tipos de
ataque do §27, e não a fatia pacífica.

**Correção de uma leitura errada, para não virar decisão.** Zonas neutras **não** são fonte de
recursos raros. O D-17 lista as fontes de raros da Temporada 1 como "eventos, zonas profundas,
contratos do governo" — zonas *profundas* é o Poço de Perfuração. O §18.4 amarra os oito raros às
oito luas, que são Temporada 2. Quando o §24.4 diz que a zona neutra é "extraída via Robô Minerador,
como os recursos raros", o "como" é o **modo** de extração, não o que se extrai. Construir zonas
neutras não aposenta o kit de raros do D-17.

**A âncora que reduz o trabalho de arbitragem.** O §19.1 publica a curva de tudo — "Produção no
Nível N = Base × 1,5^(N−1)" — e o custo tem a curva 1,65× da v3.4. Ou seja, para quase toda lacuna
o GDD **publica a curva e cala apenas a base**. Arbitrar uma base, não uma tabela.

**Já publicado, não arbitre:** proteção de 8 dias (seção 0); saque de 50% do estoque não protegido
(§10 e a matriz vigente); cerco entrega 30% em 48 h; janela diária de vulnerabilidade de 4 h, com
alteração válida só após 48 h; cooldown de 48 h do mesmo atacante; decaimento de 5% **por dia**
após 24 h de inadimplência, abandono em 72 h (a Parte I corrige a Parte II: "não há queda de 5% por
hora"); Depósito de Zona Neutra, 10 níveis, capacidade `500 … 19.222` (§19.6) e custo em §4.2; Robô
Minerador, ciclo `4 6 9 14 20` h com recarga de 1 h, custo em §4.3, defesa 25% da Sentinela, ataque
zero; quantidade de robôs por zona "20 a 150+" (§16.1); tributo na entrega em zona neutra (§25.2).

**Lacunas — o GDD não publica. Não invente; arbitre com o usuário e registre aqui.**

1. ~~**Base horária da extração** na zona neutra.~~ **Arbitrado pelo usuário em 2026-07-10: 100/h**,
   a âncora do Alumínio governamental ("produção alta"). Numa conversa anterior o usuário inclinara-se
   para 60/h, mas a pergunta fora retirada antes de confirmar; ao retomar, escolheu 100/h. As âncoras
   que estavam em jogo: Mina Local 15/h ("produção modesta"), bônus de Metal Bruto da Mina
   Governamental 60/h, Alumínio governamental 100/h ("produção alta"). É a **base**; a curva por nível
   é a do §19.1 (`Base × 1,5^(N−1)`).
2. ~~**O que cada zona tem no solo.**~~ **Arbitrado em 2026-07-10: especialização por distrito.** Na
   Temporada 1 o jogador não extrai os 8 minerais eletrônicos (governo) nem os raros (Temporada 2),
   então a zona só pode render um **primário** ("depósito mineral no solo"). Um por distrito:
   Nordeste **Metal Bruto**, Sudeste **Água**, Sudoeste **Oxigênio**, Noroeste **Biomassa**.
3. ~~**Os três requisitos de ocupação** do §07.~~ **Arbitrado em 2026-07-10: ocupação pesada.** Para
   tomar uma zona: (a) erguer um **Posto de Comando** — custo/tempo *inventados* (lacuna 7, abaixo);
   (b) guarnecer com **20 Robôs Mineradores** (âncora "20 a 150+"; nível 1 = ponta baixa; custo do
   robô é publicado, §4.3); (c) esperar o **tempo de ocupação** — *inventado:* **12 h**. Só depois a
   zona extrai.
4. **"Estoque protegido".** O saque é de 50% do *não protegido*, e o mecanismo que protege parte do
   estoque nunca é descrito em lugar nenhum. (Só morde na Fatia 2, a guerra.)
5. **Drone: velocidade** (slots/min). Publicadas: Furgão 4, Caminhão 1,5, Nave Planetária 10.
6. **Drone: raio de revelação** e **persistência** do revelado. A palavra "raio" e a palavra
   "névoa" não aparecem no documento.
7. **Custo e tempo** das dez estruturas de zona neutra além do Depósito (Posto de Comando, Abrigo
   de Robôs, Estrutura de Extração, Muralha, Torre de Vigia, Refinaria de Campo, Central de
   Comunicação, Plataforma de Pouso, Estacionamento, Cemitério de Robôs). **Parcial (2026-07-10):**
   só o **Posto de Comando** foi arbitrado, porque a ocupação pesada o exige — nível 1 *inventado* em
   **800 Metal Bruto + 300 Fert$, 8 h**. As outras nove seguem em aberto.
8. **Bônus defensivos** de Muralha e Torre de Vigia (o §27.3 os chama de "valores configuráveis").
9. **Teto de zonas por jogador.** Só o Bastião cita "zonas defendidas simultaneamente 1–3".
10. **Onde o Drone é fabricado.** O Quartel só o armazena e recarrega. E o §05 fala em "drone nível
    2" no Marco 10, sem declarar marco para o nível 1.

**Sequência decidida em 2026-07-10:** Fatia 1 = o núcleo (zonas existem, ocupação, extração);
Fatia 2 = a guerra (§27, os 4 ataques — puxa as lacunas 4 e 8); Fatia 3 = o Drone (lacunas 5, 6, 10).
O mapa (D-51, pré-requisito) já está no ar.

**Fatia 1 — completa e verde em dev (2026-07-10); só falta o deploy.** O laço núcleo fecha: ocupar →
extrair no tick → retirar para casa, com UI. Feito no backend: `ZonasNeutras` (domínio), migration
que estende `neutral_zones` para o mapa do D-51, `NeutralZoneSeeder` (120 zonas), `OcuparZonaNeutra`,
`ExtrairZonasNeutras` (no tick), `retirarDeZona` no `DespacharVeiculo`, o `NeutralZoneController`
(`GET /zones`, `POST /zones/{z}/occupy`, `.../withdraw`), e o `podeFundar` que exclui células de
zona. No frontend: o `Mapa.tsx` desenha as 120 zonas (coloridas por dono), ganhou **zoom (+/−) e
"centralizar na colônia"** (pedido do usuário; necessário porque as zonas são células nos cantos), e
um painel de zona que ocupa e despacha a retirada. **197 testes PHP + 6 e2e verdes** (novo
`zonas.e2e.mjs`). **Falta só o deploy:** rodar a migração e o `NeutralZoneSeeder` no `fertwaysbd` (o
seeder é passo à parte, como a realocação do D-51), e publicar.

**Fatia 1 — spec (aprovada 2026-07-10):**
- **120 zonas** nos 4 distritos do D-51 (NE `x∈[45,50] y∈[46,50]` e espelhos, 30 cada), todas no
  **nível 1**. Upgrade de zona fica para uma fatia posterior.
- **Mineral por distrito** (lacuna 2): NE Metal Bruto, SE Água, SO Oxigênio, NO Biomassa.
- **Ocupação** (lacuna 3, pesada): Posto de Comando (800 Metal Bruto + 300 F$, 8 h) + 20 Robôs
  Mineradores (custo §4.3) + 12 h de ocupação. Só então extrai.
- **Extração:** 100/h do mineral do distrito enquanto ocupada e o Depósito não cheio (§07: lota →
  para). Depósito de Zona Neutra publicado (10 níveis, `500…19.222`).
- **Despacho para casa** reusa a logística, com tributo na entrega (§25.2).
- As células de zona **não são fundáveis** por colônias (o `podeFundar` do D-51 passa a excluí-las).

---

## D-53 — A fila de obras travava para sempre depois da primeira conclusão.
**Data:** 2026-07-10 · **Status:** corrigido · **Bug, não decisão de design**

Relatado pelo usuário como "Evoluir dá Server Error". A mensagem do subsídio do §24.7 aparecia
logo antes, o que sugeria culpa dela. Não era: o subsídio não tem nada com isto, e o nível 3
tampouco.

`build_queue` tem `unique(colony_id, position)` **sobre a tabela inteira**. O `EnqueueUpgrade`
calculava a próxima posição como o máximo **entre os ativos** (`queued` e `building`) mais um.
Concluir um item não apaga a linha: o tick a marca `done` e ela continua guardando a sua posição.
Logo que a fila esvaziava, o máximo entre os ativos voltava a ser nulo, a próxima posição era 1
outra vez, e o insert colidia com o item já concluído na posição 1.

**Toda colônia que já tivesse concluído uma construção respondia HTTP 500 ao enfileirar a seguinte,
para sempre.** Em produção a colônia 4 acumulou 40 falhas antes de o usuário reportar. Os 165
testes não pegaram: nenhum enfileirava depois de deixar o tick concluir.

**Por que não somar sobre a tabela inteira.** `position` é `tinyint`: estouraria em 255 construções.
E a posição não significa nada depois que o item sai da fila.

**A correção.** Item `done` ou `cancelled` passa a ter `position = NULL`. NULL não colide em índice
único, nem no MariaDB nem no SQLite, e o índice continua garantindo o que importa: dois itens ativos
da mesma colônia nunca dividem uma posição. A cronologia do que foi construído não se perde — está
em `enqueued_at` e `finishes_at`.

O `cancelled` do enum nunca foi usado por nenhum código. A migration o trata mesmo assim: se um dia
existir cancelamento, ele já nasce com a posição liberada.

---

## D-54 — As três funções que o backend tinha e nenhuma tela alcançava.
**Data:** 2026-07-10 · **Status:** implementado

Auditoria pedida pelo usuário: "não estou vendo botão para acessar o mapa, assim como outras
funções". Cruzando as rotas da API com as chamadas do frontend, três funções estavam órfãs.

**1. O mapa.** `GET /colonies` existia desde o D-37 e só era usado por dentro do Ministério, para
escolher quem denunciar. Não havia mapa. Agora há tela — botão **Mapa** no HUD —, em SVG: a Capital
em losango, a sua colônia, as vizinhas, e a reta até a que você clicar, com a distância que o frete
cobra (§25.6).

A geometria — `side` e `capital` — passou a vir da API (campos aditivos em `GET /colonies`, junto
com `me`). **Não é detalhe.** A grade vai mudar no D-51 (lado 101, Capital em (0,0), coordenadas com
sinal), e um `100` copiado para dentro do React sobreviveria à mudança desenhando um mapa errado sem
reclamar de nada.

**2. A frota.** `GET /vehicles` existia; despachar só era alcançável de dentro do Mercado e do
Acordo. Um Furgão despachado sumia da vista até voltar. Agora há tela — botão **Frota** —, com
estado, destino nomeado (não a chave primária), trecho de ida ou de volta, carga e contagem
regressiva até a chegada.

A tela só mostra. Despachar continua no Mercado e no Acordo, porque destino, carga e propósito só
fazem sentido dentro daquelas negociações; um "despachar" solto pediria as três coisas no vazio.

**3. A receita da Oficina.** `PATCH /buildings/{id}/recipe` estava no `routes/api.php` desde a fatia
de fabricação e **nenhuma linha do frontend o chamava**. O jogador ficava preso na Básica — que é só
o padrão arbitrado no D-23 — sem saber que o §24.5 lhe dava três receitas.

Faltava também de onde tirar a lista: os códigos válidos moram em `component_recipes`, e digitá-los
no React seria copiar o GDD para fora do banco. Criado o `GET /recipes`, e o catálogo `GET /buildings`
passou a dizer qual receita a Oficina usa (`recipe`, nulo nas demais construções). A escolha aparece
no painel de detalhe da Oficina, com os insumos por unidade de cada receita.

**Cobertura.** Mapa e Frota têm e2e em navegador de verdade (`e2e/telas.e2e.mjs`, que **roda
primeiro** porque espera os três furgões ociosos — o do Mercado deixa dois em rota). A receita não
tem e2e: o painel vive atrás de um clique num hexágono do Phaser, cuja posição depende da ordem dos
anéis, e acertá-lo por coordenada seria um teste que quebra ao primeiro ajuste de layout. A API dela
é coberta em PHP (`ComponentRecipesTest`).

---

## D-55 — A Capital: o hub das instituições, e as três construídas antes da Guerra.
**Data:** 2026-07-10 · **Status:** decidido

O §02 (e o §2.1 da Parte II) descrevem a Capital como 20 slots institucionais **operados pela
equipe** — "'Governo' e 'Administração' são sistemas operados pela equipe, não eleição de jogadores".
Antes da Guerra (slot 5, a Fatia 2 do D-52), o usuário pediu construir a Capital: o **hub** (um
diretório dos slots, botão "Capital" no HUD e clique no losango do mapa) mais **três instituições**
com mecânica real. O hub não substitui os botões do HUD — os slots 6 (Mercado) e 7 (Ministério das
Reputações) reusam as telas de topo; o slot 1 (Administração) é rotulado "operada pela equipe" (são
os comandos `fertways:equipe`/`conciliador`). Quatro arbitragens fecharam o escopo:

**1. Central de Tributos / Tesouro (slot 2): acumula e exibe, não gasta.** O §8.3 publica as
alíquotas (primários 3%, secundários 2%, raros 1%) e diz que o tributo "vai para o Tesouro". Mas o
**D-50 registra que o GDD não modela caixa público** — salários e subsídios são emissão do Governo,
não saem de tributo. Então o Tesouro **contabiliza e mostra**, não financia nada. Como todo tributo
já é gravado em `tax_events` (transporte em unidades, `kind=transporte_entrega`; mercado em Fert$,
`kind=mercado_venda`), o saldo é uma **agregação dessa tabela** — retroativo, e sem tocar no caminho
do tributo. Zero migração para o Tesouro.

**2. Secretaria de Finanças (slot 4): painel + intervenção declarada pelo operador, com prazo.** O
§06 descreve a intervenção ("a Secretaria só altera teto/piso mediante registro público de motivo,
período e impacto", com "prazo de expiração e métrica de saída") mas **nunca publica a largura da
faixa** — por isso o MVP era sem teto/piso (**D-35**). Decisão: **nada de faixa fixa no código**. O
operador (governo/equipe) declara cada intervenção por `artisan fertways:intervencao` — recurso,
teto e/ou piso em Fert$, motivo, prazo. Enquanto vigente (tabela `price_interventions`), o
`ColocarOrdem` rejeita ordens fora da faixa (`preco_fora_da_faixa`). Os números vêm do operador a
cada caso: nada arbitrado no código. Sem intervenção, o Mercado segue livre (D-35). O painel também
mostra os preços-base do §06 (com o `*` dos derivados, D-34) e indicadores **mensuráveis** (Fert$ em
circulação, saldo do Tesouro, nº de colônias). **Sem PIB** — a fórmula é lacuna.

**3. Central de Pesquisas e Notícias (slot 3): mural com boletim manual.** O Telescópio Gagarin
(auto-boletins, §12.1) só ativa com 50 jogadores ou 45 dias, e o **formato do boletim é lacuna**.
Decisão: o mural (`news`) traz comunicados publicados à mão pela equipe (`artisan fertways:noticia`),
como manda a regra "operado pela equipe", e a tela mostra **honestamente** que o Gagarin está inativo
(nº de jogadores vs. 50). Sem inventar conteúdo automático.

**4. Cargos cívicos novos fora de escopo.** Dos cinco cargos do §14.2, só o Conciliador tem salário
publicado (50 Fert$/dia, D-50); Repórter, Auxiliar de Tesouro, Fiscal de Mercado e Atendente do
Espaçoporto têm "salário fixo/dia" **sem número** — lacuna. As instituições funcionam sem quadro de
pessoal; os cargos ficam para quando o usuário arbitrar os salários.

**Cobertura.** `CapitalTest` (11 casos): o Tesouro soma `tax_events`; a intervenção recusa fora da
faixa e passa dentro; expirada não vale; sem intervenção o mercado é livre; os comandos de operador.
O hub e as três telas têm e2e em navegador (`e2e/capital.e2e.mjs`, entre o Ministério e as Zonas —
é só leitura). A intervenção **não** entra no e2e de propósito: semear uma faixa vigente poderia
recusar ordens do `mercado.e2e.mjs`, que negocia recursos; a enforcement é coberta em PHP.

**Fora de escopo, explícito:** gastar o Tesouro; PIB; faixa de preço automática; auto-boletins do
Gagarin; os quatro cargos novos; slots 8–20. A Guerra (slot 5) é a Fatia 2 do D-52.

---

## D-56 — Painel de administração da equipe, com credencial separada.
**Data:** 2026-07-11 · **Status:** decidido

Antes da Guerra, o usuário pediu um backend para administrar tudo. Até aqui a administração eram 6
comandos `artisan` soltos e não havia conceito de equipe no sistema. O GDD prevê um "painel
administrativo" operado pela equipe (§14.4, §28.3). Três decisões do usuário: **painel web**,
**ver + agir**, **credencial separada** das contas de colono.

**Onde e como.** Painel **Blade** servido pelo Laravel que já existe, em
`https://fertways.tars.art.br/central/admin` (a app está montada em `/central` por symlink). **Zero
infra nova** de Apache/DNS/TLS. Estilo em CSS self-contained (o Vite do backend não é buildado no
deploy — `@vite` quebraria; e evita depender de CDN). O painel **não substitui** os comandos artisan:
eles continuam, agora compartilhando o mesmo domínio.

**Auth isolada (credencial separada).** Tabela `admins` + model `App\Models\Admin` (Authenticatable,
**sem** `HasApiTokens`) + provider `admins` + guard `admin` (driver `session`) em `config/auth.php`.
Login por `Auth::guard('admin')->attempt()`. A infra de sessão (driver `database` + tabela
`sessions`) já existia, sem uso, porque o colono autentica por **token**, não sessão. Nenhuma linha
toca a auth de colono. O painel só se cria por CLI: `artisan fertways:admin --criar` (sem
auto-registro — seria uma porta para qualquer um virar admin; à mão em produção, como o primeiro
conciliador do D-44 e o `NeutralZoneSeeder` do D-52).

**O `bootstrap/app.php` era API-only.** Dois comportamentos assumiam "não há tela de login":
`redirectGuestsTo(fn () => null)` (401 limpo em vez de redirecionar) e `shouldRenderJsonWhen(... ! is
'/','up')` (tudo JSON). Ambos passaram a **excluir o path `/admin*`**: o convidado do painel é levado
ao login e as páginas rendem HTML; o resto da API segue JSON puro. O `IndiceDaApiTest` continua verde,
e o índice `/central/` **não lista** as rotas do painel (o filtro `$interna` do `web.php` as exclui).

**Reuso, não shell-out.** O Ministério (julgar/apelar) chama o domínio direto
(`DecidirCaso::pelaEquipe`, `Apelacao::manter/reverter`). A lógica de conciliador, intervenção e
notícia vivia embutida nos Commands → foi **extraída** para `App\Domain\Ministry\GerirConciliador`,
`App\Domain\Finance\DeclararIntervencao` e `App\Domain\News\PublicarNoticia`, que Command e painel
compartilham. Tick e realocação de founders (orquestração com guardas) são invocados **em processo**
por `Artisan::call` — mesmo container, não shell.

**O que o painel faz.** Dashboard: panorama (colônias, Fert$ em circulação, Tesouro, casos na equipe,
ordens, frota, zonas), filas do Ministério (com prazos), conciliadores, intervenções vigentes, mural,
colônias, jogadores (4 índices), fila de obras e zonas ocupadas. Ações: julgar casos da equipe,
manter/reverter apelações, nomear/demitir/reintegrar/suspender conciliador, declarar/revogar
intervenção, publicar/remover comunicado, disparar tick, realocar founders (guardado por confirmação).

**Cobertura.** `AdminPainelTest` (13 casos): a fronteira de auth (convidado redirecionado, colono não
é admin, login certo/errado, logout) e cada ação por rota HTTP com `actingAs($admin,'admin')`. O painel
é server-side (Blade), fora do SPA, então **não** entra no `e2e.sh` do frontend — a cobertura é PHP.

**Fora de escopo:** papéis/permissões finas entre admins (todo admin é pleno); log de auditoria das
ações; 2FA. **Nota de segurança:** convém setar `SESSION_SECURE_COOKIE=true` no `.env` de produção
(o painel carrega credencial por cookie; o site é HTTPS). A Guerra (slot 5) segue sendo a Fatia 2.

---

## D-57 — O Ministério do Tesouro: um caixa real, gastável, e o kit fixo de recursos.
**Data:** 2026-07-11 · **Status:** decidido

O usuário pediu uma reserva do governo com 10 mil de cada recurso, um painel para o admin enviar
parte a jogadores, visível aos colonos na Capital; e um kit fixo de recursos para toda colônia. Os
números **não vêm do GDD** — são decisões de balanceamento do usuário (o kit do GDD é 50 Fert$ +
Furgão + raros). Registrados aqui.

**Unifica o Tesouro do D-55.** O D-55 fez o Tesouro (slot 2) ser só-leitura, derivado de `tax_events`,
que **não gastava** — porque o D-50 dizia que o GDD "não modela caixa público". O usuário agora cria
esse caixa, e isso fica *mais* fiel ao GDD: o §2.1 diz que a Central de Tributos faz "coleta e
**redistribuição** de impostos". Então o Tesouro virou um **caixa real** (`treasury_holdings`): uma
linha por recurso (unidades) + uma de Fert$ (`__fert__`, micro). O tributo cobrado no comércio, que
antes sumia (sink em `ConcluirTrechos` e `ColocarOrdem`), agora **entra** no caixa
(`Tesouro::creditarRecurso/creditarFert`). A vista do colono (`GET /central/treasury`) passou a ler o
saldo do caixa, não mais a agregação de `tax_events` (que segue só como log de auditoria das cobranças).

**Dotação inicial (decisão do usuário):** 10.000 de cada recurso (26) + **1.000.000 Fert$**, no
`TreasurySeeder` (idempotente, `firstOrCreate` — não zera o que o tributo já acumulou). Em produção
roda-se à mão após o deploy, como o `NeutralZoneSeeder`; está também no `DatabaseSeeder` para dev/e2e.

**Distribuição (só o admin move):** `Tesouro::distribuir(colônia, recurso, qtd)` baixa do caixa com
guarda de saldo (`where amount >= qtd`, à prova de corrida), credita o estoque/Fert$ da colônia e
lança `transferencia_tesouro` no ledger. Exposto no painel de admin (seção "Ministério do Tesouro":
saldo + form colônia/recurso/quantidade). Os colonos **só veem** o saldo, na Capital.

**Kit fixo de recursos (decisão do usuário):** 1000 metal bruto, 1000 ligas metálicas, 500 compostos
químicos, 300 biocombustível, 500 componentes eletrônicos — por colônia, **emissão do governo** (não
sai da reserva; lançado como `kit_recursos` no ledger). Idempotente (marca por ledger-ref). Vale para
as colônias existentes (backfill `fertways:kit-recursos --aplicar`) e para toda nova.

**Por que o kit vive na fronteira de fundação (`ColonyController::store`), não no `CreateColony`.**
Pôr o kit na primitiva de domínio muda o estoque-base de toda colônia e quebrou 17 testes que assumem
colônia limpa (produção, mercado, logística). O kit é concedido no endpoint de onboarding — a
primitiva `CreateColony` segue nascendo com estoque limpo, e os testes de domínio ficam intactos. O
único caminho de fundação em produção é esse endpoint.

**Cobertura.** `TesouroTest` (9 casos): dotação e idempotência, crédito pelo tributo, distribuição
(recurso e Fert$) com a guarda de saldo, e o kit. `CapitalTest`: a venda de mercado credita o caixa.
`AdminPainelTest`: a distribuição pelo painel. `ColonyCreationTest`: a fundação concede o kit.

**Nota:** o kit inflaciona bastante o onboarding perto do GDD (50 Fert$). É decisão de balanceamento
do usuário; se um dia parecer demais, é o primeiro número a revisitar. A Guerra segue como Fatia 2.

---

## D-58 — O Mercado Central em quatro abas: vitrine global, mural entre colonos e teto no depósito.
**Data:** 2026-07-11 · **Status:** decidido pelo usuário · **GDD: omisso nos tetos**

O usuário pediu quatro abas no Mercado. Ao levantar o que existia, dois dos quatro pedidos **já
estavam construídos com outros nomes** — e o registro disto importa mais do que o código novo:

- O **"Livro de Ofertas"** já era o Mercado Central que o usuário descreve na aba "Ofertas Globais":
  vende do depósito da Capital, dispensa aceitação e envio, transfere recurso de depósito a depósito
  e move os Fert$. O que ele **não** fazia era **aparecer**.
- O **Acordo de Troca (§26.5, D-40)** já era o "entre colonos": estoque da colônia, entrega física,
  sem escrow, calote possível. Faltava-lhe só ser **público**.

**A queixa "não vejo as ofertas dos outros" não era bug de filtro.** O `GET /market/orders` nunca
filtrou por colônia. Eram quatro causas somadas, e a primeira é a que manda: **o livro casava as
ordens no ato**, então uma oferta que cruzava era executada e nunca repousava para ser vista. Em
produção havia 5 ordens abertas, **todas de compra**, e **todos os depósitos zerados** — como vender
exige ter o recurso já depositado, a coluna "Vendas" estava vazia porque ninguém *podia* vender.
Somavam-se: o livro pedia **um recurso por vez** (abria em `metal_bruto`), a UI expunha **8 dos 26**
recursos, e as linhas **não diziam de quem eram**.

### As decisões do usuário (2026-07-11)

1. **Vitrine, não casamento automático.** A oferta **repousa** e fica visível até alguém a executar
   ou o dono cancelar. `ColocarOrdem` perde o `casar()`; a execução vira ato explícito
   (`ExecutarOrdem`), parcial permitida. O preço deixa de se descobrir sozinho: quem anuncia caro
   espera. Foi escolha consciente — a visibilidade valeu mais que a descoberta de preço.
2. **Teto no depósito da Capital, por classe:** **10.000** por recurso primário, **2.500** por
   secundário, **100** por raro. Os números são **arbitragem do usuário — não são do GDD**. Encaixam
   exatamente no `tax_class` que a tabela `resource_types` já tem (§8.3), então **nenhuma classe nova
   foi criada**: "industrial" = os 4 secundários do §18.2 **mais** os 8 minerais do §18.3.
3. **A regra dos dois estoques**, que o código já seguia e agora é explícita: **o que está na colônia
   se negocia entre colonos; o que está no depósito da Capital se oferta no Mercado Central.**
4. **Ofertas entre colonos: públicas E dirigidas.** O mural aceita oferta aberta (o primeiro que
   aceitar vira contraparte) sem tirar a proposta dirigida de hoje.
5. **Sem reserva de estoque na oferta entre colonos.** O D-40 fica de pé: a oferta é promessa, o
   calote é real e é ele que alimenta o Ministério.
6. **A taxa de fechamento continua no vendedor** (3/2/1% em Fert$, ao Tesouro). O usuário não a
   mencionara; foi confirmada de propósito, para não a apagar por omissão.

### As duas consequências que o assistente arbitrou, e por quê

Não são valores novos — são desdobramentos das regras acima. Ficam registradas porque um leitor
futuro vai tropeçar nelas:

- **A oferta de compra também reserva espaço no teto.** O usuário decidiu que a oferta de venda
  ocupa espaço ("senão o teto vira decoração"). Pela mesma lógica, a de compra vai *receber*
  mercadoria: se não reservasse espaço, a execução falharia na cara do vendedor por culpa do
  comprador. Logo o ocupado é **saldo + escrow de vendas + quantidade das compras abertas**.
- **O excedente que não coube volta no veículo e não paga tributo.** O tributo incide na entrega
  física (§25.8, D-32). O que não entrou no depósito não foi entregue: tributa-se só o que entrou, e
  o resto volta à colônia sem ser tributado de novo na chegada — cobrar seria faturar uma entrega que
  não houve.

### O que fica de fora, de propósito

O `market_orders` **não é migrado nem apagado**: as 5 ordens de compra de produção continuam válidas,
viram ofertas da vitrine e podem ser executadas. Nenhum depósito de produção passa do teto (todos
estão em zero), então o teto não precisa de backfill.

---

## D-59 — Os 21 slots da colônia: construção posicionada, cópias repetíveis e demolição.
**Data:** 2026-07-11 · **Status:** decidido pelo usuário · **GDD: omisso — não tem slot de
construção, não tem demolição**

> **Nota de procedência (2026-07-12).** Esta entrada foi **reconstruída a partir do código**, na
> sessão seguinte: a de 2026-07-11 implementou o D-59 inteiro e o deixou verde, mas nunca o
> registrou aqui. As decisões abaixo estão todas documentadas nos cabeçalhos das classes
> (`Domain/Colony/Slots`, `Domain/Building/{ConstruirEmSlot,Demolir,Funcoes}`) e a numeração dos
> itens 5 e 6 é a que o próprio código cita. **Se algum item não disser o que você decidiu, este
> arquivo é que está errado — corrija-o, não o código.**

**A palavra "slot" no GDD não quer dizer isto.** Procurou-se `slot`, `terreno`, `lote`, `grade`: no
documento, "slot" é a **colônia do jogador vista do mapa do planeta** (ou um dos 20 slots
institucionais da Capital, §2.1), nunca um espaço de construção dentro da colônia. O GDD **não põe
teto espacial nenhum**. Os únicos limites que ele conhece são o **energético** (§19.8: o Reator
nível 1 sustenta as essenciais "permitindo que o jogador construa 2-3 estruturas adicionais") e o da
**fila de obras**. Tudo o que segue é arbitragem do usuário.

Até aqui, a colônia nascia com as **16 construções do MVP já existentes no nível 0**, e "construir"
era só o primeiro upgrade de uma linha que já estava lá. Não havia posição, não havia escolha, e o
`unique(colony_id, type)` proibia a segunda Mina. A colônia era uma lista, não um lugar.

### As decisões do usuário (2026-07-11)

1. **21 slots, numa colmeia de linhas 4/4/5/4/4**, simétrica nos dois eixos. 21 não fecha anel
   hexagonal (os anéis fecham em 1, 7 e 19), então a colmeia é feita de **linhas alternadas** e o
   centro é a célula única — o slot 10. A construção passa a ter **posição**: `buildings` ganha a
   coluna `slot`, e **construção não erguida não ocupa slot** — a linha só nasce quando o colono
   aponta o buraco.
2. **As cinco essenciais nascem prontas, no nível 1, no miolo.** Reator no centro exato (10),
   Gerador e Estrutura ladeando-o (9 e 11), Fazenda e Captação de Água simétricas acima e abaixo
   (5 e 15). **Isto revisa o D-13.** O §24.7 subsidia o *custo* das essenciais até o nível 3 ("o
   custo aparece normalmente na interface"), o que pressupõe que o nível 1 ainda seja *construído* —
   e era o que o D-13 fazia. Nascer pronto vai além do GDD. O subsídio segue valendo do nível 2 ao
   3, e o nível 1 do miolo **é lançado no ledger como `subsidio_governo`**: emissão do Governo não
   pode ser invisível na contabilidade.
3. **Quatro construções podem ser repetidas**, cada cópia com o seu nível: **Mina Local, Oficina,
   Refinaria Química e Destilaria**. Morre o `unique(colony_id, type)`; entra `unique(colony_id,
   slot)` — dois prédios no mesmo buraco, nunca. São as **produtoras**: repetir é especializar a
   colônia (em metal, em química), e produção e consumo de energia somam linearmente entre as
   cópias. O freio é o Reator — que é exatamente o limite que o GDD usa (§19.8).
   - As **essenciais ficam únicas** de propósito: fossem repetíveis, o subsídio do §24.7 viraria
     torneira aberta e a enésima Fazenda sairia de graça.
   - As de **função única** (Antena, Laboratório, Quartel, Plataforma, Mercado Local, Torre, Central
     de Transportes, Tanque) também: duas Antenas não querem dizer nada, e o §28.5 amarra o teto de
     caminhões ao **nível** da Central de Transportes, não à contagem delas.
4. **Demolição** (`DELETE /buildings/{id}`) — o GDD não fala nela, nem na palavra nem no conceito.
   - **O investido não volta.** Demolir é perda: nada é estornado. Por isso não há crédito no
     ledger — o `custo_construcao` lançado na obra continua lá, registro honesto de um gasto que
     virou pó.
   - **As cinco essenciais são indemolíveis.** Derrubar o Gerador de Atmosfera exigiria decidir o
     que acontece a uma colônia sem atmosfera, e o GDD não tem resposta. Não se inventa uma.
   - **Não se demole o que está em obra.** Cancele a obra antes. Assim não nasce a questão do
     estorno de uma obra interrompida no meio.
5. **Cada construção diz o que faz.** Até aqui a tela de detalhe só sabia dizer custo e tempo: o
   colono via o preço da Oficina sem nunca saber para que ela serve. Nasce `Domain/Building/Funcoes`
   com **duas camadas que não podem ser confundidas** — `frase`/`fonte`, o que o GDD **promete**,
   transcrito verbatim com o §; e `nota`, o que o jogo **entrega hoje**, quando é menos que a
   promessa. A segunda existe porque a primeira, sozinha, mentiria: uma tela que dissesse só
   "Laboratório: pesquisa tecnológica" faria o colono gastar 90 Ligas num prédio inerte. **Sete
   construções o GDD descreve e nunca quantifica; duas têm número publicado e mesmo assim não mordem
   no código.** Enquanto o efeito não existir, ele é anunciado como o que é: uma promessa. Os
   **números por nível não entram ali** — saem de `building_specs`, semeada do GDD (D-02).
6. **O prédio é a porta da tela.** A Central de Transportes é por onde se vê a Frota; o Mercado
   Local, por onde se abrem os Acordos de Troca — que é exatamente o que a frase do §17.2 descreve
   ("comércio direto com vizinhos"). O Mercado *Central* é outra coisa: instituição da Capital
   (§2.1), alcançada pelo mapa.

### O Tanque de Combustível entra no MVP

O GDD sempre listou **doze** construções de progressão (§04 e §28.6, listas idênticas), e o **Tanque
de Combustível** (§21.9) era a única fora do MVP — **sem motivo registrado**. Entra agora que há slot
para ela. Custo e tempo já estavam semeados em `building_specs`. **O MVP passa de 16 para 17
construções**: 5 essenciais + 12 de progressão, para **16 slots livres**.

### O backfill, e por que ele promove níveis

`artisan fertways:slots` (simula) / `--aplicar` (migra). Passo à parte do deploy, como o
`fertways:kit-recursos` do D-57. Em cada colônia, nesta ordem:

1. **Põe as cinco essenciais no miolo**; quem estiver no nível 0 é **promovida ao nível 1**, com o
   custo lançado como `subsidio_governo` — o miolo nasce erguido, e quem já existia não pode ficar
   num estado que a fundação não produz mais.
2. **Apaga as construções de nível 0** que ninguém está erguendo: elas eram o desenho antigo (16
   linhas na fundação) e hoje significam "slot vazio". **A que está na fila é preservada** e ganha
   slot — cancelar a obra de alguém seria roubo.
3. **Distribui o que está erguido** pelos slots de fora, preservando o nível.

Idempotente (o subsídio entra por `firstOrCreate` no ledger) e de ordem estável, para que rodar duas
vezes não embaralhe o mapa da colônia de um jogador. A simulação **corre a mesma rotina** e desfaz
tudo com um rollback — não é uma segunda implementação que pode divergir da real.

### O que fica de fora, de propósito

- **Construir e demolir não têm e2e.** O painel está atrás de um clique num hexágono do Phaser, e
  mirá-lo por coordenada quebraria ao primeiro ajuste de layout — a mesma razão pela qual a receita
  da Oficina (D-54) não tem. As rotas são cobertas em PHP.
- **Não há teto de cópias** além dos 16 slots livres e do que o Reator sustenta. É deliberado: o
  limite energético é o único que o GDD publica (§19.8), e ele já morde.

---

## D-60 — O Ministério dos Transportes: fábrica única de caminhões, cartório de placas e oficina.
**Data:** 2026-07-12 · **Status:** decidido pelo usuário · **GDD: publica muito, e o usuário
contraria dois pontos de propósito**

### Primeiro, a premissa que estava errada

O usuário abriu o assunto dizendo que "o GDD fala que a Central de Transportes de cada colono pode
fabricar um caminhão por nível". **Não fala** — ou melhor, é o que diz o §28.5 ("Regra Definitiva":
"os caminhões correspondentes ao nível atual já estão incluídos no upgrade da Central — sem custo
adicional"), e a **tabela de precedência da seção 0 já o revogou**: *"Libera vagas de frota; veículo
é fabricado ou adquirido separadamente."* Isso é o **D-28**, de 2026-07-09, e o **D-47** cita este
caso pelo nome como o exemplo que justifica a ressalva "dentro da mesma parte".

**Então este D-60 não contraria o GDD nesta matéria — ele o completa.** O D-28 disse que o caminhão
é "fabricado ou adquirido separadamente" e nunca construímos o *separadamente*: até hoje **o
Caminhão de Carga é inalcançável no jogo**. Nenhuma parte do código o fabrica, e nenhum colono pode
ter um. O D-60 decide quem o fabrica.

### O que o GDD já publica (e que não se inventa aqui)

A §16 chama-se "Frota e Ministério dos Transportes". O painel dele vem com **seis atribuições
escritas**: registro de placas; curva de depreciação; limite crítico; perda de vida útil e teto de
revenda; frota de Cargueiros Interplanetários; relatórios de volume. O §16.3 publica o **formato da
placa** (`FW-07429-F`) e os campos do registro. O §16.4 descreve a depreciação — **sem publicar um
número sequer**. O §19.5 publica as vagas da Central (1 a 10, uma por nível). O §21.3 publica o custo
do Caminhão.

### As decisões do usuário (2026-07-12)

1. **Fabricar caminhão é privativo do Ministério.** A Central de Transportes do colono não fabrica
   nada. É a mudança que o usuário pediu, e ela cai sobre um GDD que dizia que a Central *produz*
   Caminhões (§17.2, §21.3: "Construída na Central de Transportes") — **contradição deliberada**,
   registrada aqui.
2. **A Central vira a vaga, e a vaga passa a morder.** Teto de veículos simultâneos =
   **máximo(1, nível da Central)**. Hoje esse limite não vale no jogo; passa a valer.
   - O `máximo(1, …)` resolve um problema que o D-59 criou: construção não erguida não existe, então
     **toda colônia nova tem zero Central** — e o kit inicial dá um Furgão. Sem o piso de 1, o
     Furgão do kit nasceria fora da lei.
   - Ele **preserva as duas tabelas do GDD**: a Central dá 1..10 (§19.5) e o Terminal de Cargas, que
     "acrescenta duas vagas em cada nível", dá 3..12 — que é exatamente o que o §17.3 publica.
   - Efeito colateral aceito: erguer a Central no **nível 1 não dá vaga nova**.
3. **O Ministério ocupa o slot 8 da Capital.** O §2.1 reserva os slots **8 e 9** para Quartel de
   Alianças e Embaixada Interplanetária, ambos fora do MVP — então isto é **arbitragem do usuário
   contra o §2.1**, escolhida sobre a alternativa de dividir o slot 6 (Pátio Logístico Público) com o
   Mercado. A Capital passa a ter **8 slots** na tela.
4. **Preço: 300 Fert$** pelo Caminhão **nível 1**. Os recursos dele valem ~33,60 Fert$ a preço de
   referência, então é ~9× — margem gorda e deliberada: é o dreno de Fert$ que dá serventia ao caixa
   do Tesouro (D-57). **Não é do GDD.**
5. **Só o nível 1 é vendido, e o nível do veículo fica dormente.** O GDD nunca diz o que o nível de
   um veículo muda (a capacidade é fixa por tipo: 6 m³ / 30 m³). Vender só o nível 1 não inventa
   nada e não fecha porta nenhuma.
   > **Nota de errata evitada.** O GDD tem **duas tabelas de custo diferentes** para o Caminhão: a
   > §21.3 dá Ligas `90 135 202 304 456` (curva 1,50×) e a §20 dá `90 149 245 404 667` (curva
   > 1,65×) — a mesma armadilha do D-37. **O nível 1 é idêntico nas duas** (90 Ligas, 25
   > Componentes, 16 Metal Bruto), e como só ele é vendido, a divergência **não nos toca**. Fica
   > registrada, não resolvida. Se um dia os níveis 2+ entrarem, é aqui que a briga começa.
6. **O Ministério paga a fabricação com o caixa do Tesouro.** Cada caminhão consome 90 Ligas, 25
   Componentes e 16 Metal Bruto **do Tesouro** (D-57); os 300 Fert$ do colono **entram no Tesouro**.
   Se o Tesouro não tiver os recursos, **não há caminhão** — a redistribuição do §2.1 vira uma
   decisão de governo com consequência.
7. **Fabricação leva 1 hora, e o governo mantém 5 prontos na prateleira**, repondo sozinho no tick.
   Quem compra da prateleira leva na hora; quem a esvaziou, espera.
8. **A entrega é física: o caminhão dirige-se sozinho da Capital até a colônia.** Simétrico ao
   usado. **A viagem de entrega não conta como uso ativo** — o veículo chega com 100%.
9. **O Furgão não é vendido.** Continua vindo só no kit inicial. Quem vender o seu no mercado de
   usados fica sem — e só um usado o traz de volta.
10. **Placas (§16.3): `FW` + 5 dígitos sequenciais + a inicial do tipo** (`F` Furgão, `C` Caminhão).
    É a leitura do único exemplo publicado. Todo veículo civil é registrado ao nascer ou ao mudar de
    dono. Os veículos que **já existem** ganham placa por backfill, na ordem de criação.
11. **Depreciação: 0,5% de conservação por hora de uso ativo**, só para Furgão e Caminhão (o §16.4
    é explícito: "apenas Furgão e Caminhão possuem depreciação ativa"). Uma viagem longa de ida e
    volta (~3 h) custa ~1,5%.
12. **Manutenção: na Central de Transportes do colono, em recursos = 10% do custo do veículo.** Para
    o Caminhão: 9 Ligas, 3 Componentes, 2 Metal Bruto. É uma **fração da tabela publicada**, não uma
    constante nova — acompanha o GDD em vez de apodrecer. Restaura a conservação **até o teto**; e o
    **teto cai 5 pontos a cada manutenção**, então o veículo envelhece de verdade (~14 manutenções).
13. **Sucata: só por vontade do dono, a qualquer momento.** Nada some da frota sem o jogador mandar.
    Sem devolução de recursos.

### A contradição que o usuário escolheu, de olhos abertos

**O veículo desgastado nunca trava.** O §16.4 nomeia um "**Bloqueio operacional** — abaixo de um
limite crítico, o veículo não pode iniciar nova missão sem manutenção", e o painel do §16 manda
"configurar limite crítico de desempenho **que bloqueia novas missões**". O usuário decidiu o
contrário: **velocidade e capacidade caem na proporção do estado, mas o veículo sempre anda.**

O **limite crítico sobrevive com outro sentido**: passa a ser o **piso de desempenho**. Semeado em
**25%** — um caminhão a 5% de conservação ainda anda a 25% da velocidade e carrega 25% da carga.
Assim nenhuma das seis atribuições publicadas do painel se perde, e o colono nunca vê um patrimônio
de 300 Fert$ parado esperando peças. É o mesmo tipo de contradição consciente do **D-32** (o tributo):
**não a "conserte" sem perguntar.**

### As consequências que o assistente arbitrou, e por quê

Não são valores novos — são desdobramentos das regras acima, e ficam registradas porque um leitor
futuro vai tropeçar nelas:

- **Comprar exige vaga livre.** Se o teto da Central já está cheio, a compra é recusada antes de o
  Fert$ sair. Senão o teto viraria decoração — mesma lógica do teto do depósito no D-58.
- **O teto de revenda em Fert$ = 300 × o teto de conservação do veículo.** O §16.4 diz que cada
  manutenção "reduz o teto de valor de revenda" e que o estado "afeta diretamente o preço de venda",
  mas nunca diz em relação a quê. O preço de fábrica é a única âncora que existe.
- **O limite crítico (o piso, 25%) é parâmetro do operador**, semeado — como a curva de depreciação
  e o custo de manutenção. Nada de número chumbado no código: é o padrão do D-35.

### O que fica de fora, de propósito

**O Cargueiro Interplanetário e o seu aluguel** — quinta atribuição do painel — **não entram**.
Dependem do Espaçoporto e dos 5 planetas NPC, que não existem. O GDD é explícito: "não recebe tabela
de fabricação; permanece serviço/aluguel governamental". Entra quando o Espaçoporto entrar.

### Aditivo (2026-07-12) — as três lacunas que apareceram ao construir as fatias 2 e 3

14. **O Furgão não tem teto de revenda.** O teto de revenda é `preço de fábrica × teto de
    conservação`, e o Furgão **não tem preço de fábrica** — o Ministério não o vende (item 9). O
    usuário decidiu **não lhe dar âncora nenhuma**: quem vende Furgão usado pede o que quiser.
    > **O risco, aceito de olhos abertos.** Sem teto, um Furgão sucateado pode ser anunciado por
    > 5.000 Fert$ — e duas contas do mesmo jogador podem usar isso para **lavar Fert$** de uma para a
    > outra sem tributo de transporte, já que a venda de usado paga em Fert$ e não move carga. O
    > Caminhão é imune (tem teto). Se o multi-conta virar problema, **é aqui que ele vai aparecer
    > primeiro**, e a cura é dar um teto ao Furgão.
15. **O mercado de usados é a 6ª aba do Mercado, com escrow do Ministério.** Isto **contraria a regra
    dos dois estoques do D-58** ("o que está na colônia se negocia entre colonos, com calote
    possível"): o veículo está na colônia e mesmo assim tem escrow. A razão é que **o Ministério é o
    cartório** — é ele que emite a placa (§16.3), então é ele que fecha a transferência:
    - o comprador paga e os Fert$ ficam **retidos no Ministério**;
    - o veículo **dirige-se sozinho** até a colônia do comprador (item 4 das decisões);
    - **a placa muda de dono na chegada**, e só então o vendedor recebe.
    Sem calote possível. Um Caminhão de 300 Fert$ não é um lote de minério, e perdê-lo por calote
    seria de outra ordem de grandeza.
16. **Os relatórios de volume vão aos dois:** o painel de admin traz o relatório completo (§16, a 6ª
    atribuição, que é do **operador**); a tela do slot 8 mostra ao colono um **resumo público** —
    quantos veículos existem no planeta, quantos foram sucateados.

### As consequências que o assistente arbitrou nas fatias 2 e 3

- **Sem Central de Transportes não há manutenção.** O usuário decidiu que a manutenção é "na Central
  de Transportes do colono" (item 12) — e uma colônia nova **não tem Central** (D-59), embora tenha o
  Furgão do kit. Logo, ela **não pode manter o próprio Furgão** até erguer uma. Não é armadilha: o
  desgaste é de 0,5%/h de uso **ativo**, o piso é 25% e **nada trava** (item 11), então o Furgão do
  novato leva ~150 h de estrada até encostar no piso e continua andando depois disso. É pressão para
  erguer a Central, não sentença de morte. Se incomodar, a saída é permitir a manutenção sem Central.
- **A viagem do usado não deprecia o veículo até a entrega.** Simétrico à entrega de fábrica (item 8):
  quem comprou não pode receber o veículo mais gasto do que o anúncio dizia. O desgaste volta a correr
  na primeira viagem do novo dono.
- **Comprar usado também exige vaga livre**, pela mesma razão do item da compra nova: um teto que não
  impede nada é decoração.
- **Veículo anunciado não sai em viagem.** Sem esta guarda, o vendedor anunciava e despachava em
  seguida, e o comprador que clicasse em "comprar" levava um erro **por culpa do vendedor** (a compra
  exige o veículo no pátio). O anúncio é um compromisso: ou você o está vendendo, ou o está usando.
- **A sucata arquiva, não apaga** (`SoftDeletes` em `Vehicle`). Duas coisas dependem disso, e as duas
  quebraram antes de eu perceber:
  - **A placa não pode ser reciclada.** O sequencial vem da maior já emitida; se a linha sumisse, o
    máximo cairia junto e o próximo veículo do planeta herdaria a placa do morto.
  - **Os sucateados precisam ser contáveis.** O §16 pede o volume de sucateados "por período" — não
    há como contar por período o que foi apagado, nem como saber quando.
- **O desgaste encolhe a velocidade, e não só a capacidade.** O §16.4 diz "mais lento **e** carrega
  menos", e é o mesmo multiplicador para os dois. Isso obrigou **toda** a máquina de viagem a passar
  pela `Conservacao` em vez do `VeiculoSpecs` cru — inclusive a cotação da tela da Frota, que senão
  prometeria ao colono um tempo que o veículo dele já não faz.

---

## D-61 — O painel de administração: auditoria, CRUD de contas e a palavra que confirma.
**Data:** 2026-07-12 · **Status:** decidido pelo usuário · **GDD: fora do escopo dele**

Nada disto é do GDD. O documento descreve o **jogo**; o painel de administração é **ferramenta de
operação**, e o §9.2 só diz que existe uma "equipe". Tudo aqui é decisão do usuário.

**O buraco que motivou tudo: o painel não deixava rastro nenhum.** Julgar um caso, distribuir o
Tesouro, disparar um tick — tudo acontecia sem registro de quem fez, quando, ou o que mudou. O
`ledger` audita a **economia**; nada auditava a **administração**. Num jogo cuja regra de ouro é que
recurso não nasce sem história, o operador era o único que podia criar valor sem deixar história.

### As decisões do usuário (2026-07-12)

1. **Auditoria de todo ato de admin, com o antes e o depois.** Cada ação grava: **quem** (admin),
   **quando**, **o quê**, **sobre quem/o quê**, os **valores antes e depois**, o **IP** e o
   **navegador**. Mais os **logins de admin, inclusive os que falharam**. **Append-only**, como o
   ledger — nem o admin apaga. É o que permite responder "quem deu 10.000 Fert$ a este colono?".
2. **O painel quebra em seções navegáveis.** A página única já era longa e dobraria com o CRUD e o
   log. Continua Blade por sessão, mesma estética, sem SPA.
3. **CRUD de jogadores**: ficha completa (colônia, recursos, Fert$, construções, frota com placas, os
   4 índices, punições, denúncias, acordos, extrato do ledger), **suspensão**, **correção de estado**
   e **realocação da colônia**. **Não se apaga jogador** — apagar levaria em cascata o ledger, os
   acordos e as denúncias em que ele é parte, quebrando o histórico de **outros** jogadores.
4. **A suspensão barra o acesso e congela só o comércio.** O login é recusado e os tokens são
   revogados na hora; a colônia **continua produzindo** e os veículos em rota **chegam**. Mas nenhuma
   carga sai — e isso **reusa a restrição comercial do §9.4**, que o Ministério das Reputações já
   sabe aplicar, em vez de inventar mecânica nova. Protege os outros jogadores sem congelar o mundo.
   Motivo e prazo (ou "definitivo") são obrigatórios.
5. **Dois papéis de admin: dono e operador.**
   - **Dono**: tudo, inclusive gerir admins e **realocar colônias**.
   - **Operador**: julga casos, publica notícias, distribui o Tesouro, e — nos jogadores — **vê,
     suspende e corrige estado**. Não gere admins e **não realoca**.
   - Duas travas, sempre: **ninguém apaga a si mesmo**, e **não se apaga o último dono** (senão o
     painel fica inacessível para sempre e a única saída seria o `artisan`).
6. **Corrigir estado lança `ajuste_admin` no ledger, sempre.** Fert$ ou recurso que aparece por
   decisão do operador entra no extrato do colono com o motivo escrito e o admin que fez. **A
   auditoria guarda o antes/depois; o ledger guarda o delta.** Dinheiro sem história é exatamente o
   que o ledger existe para impedir — e o admin não é exceção.
7. **Realocar força e recalcula.** Ao contrário do `fertways:realocar-founders`, que **recusa** se
   houver veículo em rota, a realocação do painel **acontece assim mesmo** e **refaz as viagens** a
   partir da nova posição. Exige a palavra **REALOCAR**, escrita.
8. **Demolir exige a palavra DEMOLIR — na tela E na API.** O `DELETE /buildings/{id}` passa a exigir
   `confirmacao: "DEMOLIR"`. Só na tela seria cosmético: qualquer chamada direta à API (ou um bug de
   duplo-clique) demoliria sem digitar nada. A API é a porta de verdade.

### As consequências que o assistente arbitrou, e por quê

- **A realocação não acerta a energia já gasta.** O veículo pagou, no despacho, a energia da viagem
  inteira pela distância **antiga** (D-30). Se a colônia se mudar para longe, a viagem refeita é mais
  cara do que o que ele pagou; se for para perto, mais barata. **Não há acerto**: nem cobrança, nem
  estorno. Cobrar do colono uma energia que ele não escolheu gastar seria puni-lo por um ato do
  operador, e estornar seria dar-lhe lucro pelo mesmo motivo. **O governo come a diferença**, e a
  auditoria registra a realocação inteira.
- **A viagem refeita recomeça do zero.** O trecho em curso é recalculado como se o veículo partisse
  **agora**, da posição nova. Não se tenta preservar a fração já percorrida: ela não existe em lugar
  nenhum do modelo (só há `departs_at` e `arrives_at`), e inventá-la seria fingir uma precisão que o
  jogo não tem.
- **Acordos de Troca abertos ficam com o prazo da distância antiga.** O prazo é um instante gravado
  (D-42), e mudar a colônia de lugar pode torná-lo impossível de cumprir. **O painel avisa antes**, e
  a decisão é do operador — está na auditoria.
- **A suspensão não tira o conciliador do cargo.** São coisas diferentes: o §26.7 tem o seu próprio
  rito de suspensão de conciliador, com reversões e prazo. Um suspenso que seja conciliador
  simplesmente não entra para julgar, e os casos dele vencem as 48 h e sobem à equipe — que é o que o
  §9.2 já manda fazer.

---

## D-62 — O GDD v36: um documento que não se contradiz.
**Data:** 2026-07-12 · **Status:** decidido pelo usuário

O GDD v35 é uma **pilha de versões**: a Parte I é a v3.2 sanitizada, a Parte II é a v3.0, e uma
tabela de precedência na seção 0 existe para o leitor resolver as contradições que o próprio
documento carrega. É por isso que o **D-47** precisou virar regra de leitura, e é por isso que **30
das nossas 59 decisões** existem — o documento se contradiz ou é omisso.

**O v36 resolve as contradições no texto**, em vez de deixar uma tabela para o leitor resolver.
Incorpora as 59 decisões, marca **LACUNA ABERTA** onde o GDD nunca publicou um número (sem inventar
nenhum — é a regra de ouro aplicada ao próprio documento), e separa o que o jogo **entrega** do que
ele **promete**, como o `Domain/Building/Funcoes` já faz nas construções.

- **Formato:** HTML, como o v35. É documento para ler, não para o git.
- **O v35 fica intocado**, como registro histórico do que se pensava antes. O v36 diz na abertura que
  o substitui, e por quê.
- **O `docs/decisoes.md` continua sendo o diário** — o v36 é a fonte única; o diário é o rastro de
  como se chegou nela.
- **Quando o v36 estiver no ar, o D-47 vira história**: não haverá mais precedência a aplicar, porque
  não haverá mais duas redações concorrentes.

---

## D-63 — A Capital vira lugar: quatro áreas, uma praça, e zoom nas cenas.
**Data:** 2026-07-12 · **Status:** decidido pelo usuário · **GDD: a planta não existe nele**

### Primeiro, o que o GDD *não* diz

O usuário abriu o assunto com "lembrando o que está no GDD, a capital é dividida em 4 áreas com uma
praça central". **Não está.** Procurou-se `praça`, `quadrante`, `norte`, `sul`, `leste`, `oeste`,
`4 áreas`: o GDD trata a Capital como uma **lista plana de 20 slots** (§2.1), **sem geografia
nenhuma**. Não há planta, não há centro, não há bairros.

**A planta é invenção do usuário, e é boa** — mas é arbitragem nova, e fica registrada como tal. É a
terceira vez que uma premissa "isto está no GDD" não estava (ver D-60, o caminhão da Central de
Transportes); o padrão vale a pena notar.

### O choque que o desenho tinha, e como o usuário o resolveu

No GDD, o **slot 6 _é_ o Estacionamento de Caminhões** ("20 vagas. Cobrança por hora. Caminhões
aguardam retirada de carga") — que a versão sanitizada rebatiza de **Pátio Logístico Público** ("docas
públicas, entregas de Mercado e operação de cargueiros governamentais"), e é dentro dele que o nosso
Mercado Central mora desde o D-55.

O desenho original punha o **Mercado no Leste** e o **Pátio entre o Leste e o Sul** — dois lugares
para a mesma coisa. **O usuário decidiu: o Leste é o slot 6 inteiro.** Mercado e Pátio são a mesma
área; os caminhões estacionados são desenho, não uma segunda porta.

### As decisões do usuário (2026-07-12)

1. **A Capital deixa de ser um menu e vira uma cena**, em Phaser, como a colônia. Mesmo motor, mesmo
   jeito de clicar num hexágono, mesma câmera.
2. **Quatro áreas e uma praça:**
   - **Norte** — Governo Central: a grade dos slots institucionais.
   - **Oeste** — os destroços da **Endurance**.
   - **Leste** — o **slot 6**: Mercado Central + Pátio Logístico, juntos. Clicar abre o Mercado.
   - **Sul** — o futuro **Espaçoporto**.
   - **Centro** — a **praça**, do tamanho de 1 slot. **Decorativa**: não clica e não faz nada. É o
     marco que organiza as quatro áreas e dá à Capital cara de cidade — e o espaço guardado para
     quando houver evento, chat público ou monumento.
3. **O Norte mostra 19 slots**, não 20: os 1–5, 7–8 e 9–20. **O 6 não aparece lá**, porque ele **é** o
   Leste. Uma coisa, um lugar — nada aparece duas vezes na tela. Os vagos (9–20) são **visíveis e
   travados**: é o que faz a Capital parecer um lugar que vai crescer, e não um menu.
4. **A Endurance e o Espaçoporto abrem, e contam a verdade.** Não são decoração muda nem um "em
   breve" vazio: mostram **o que o GDD publica** e **admitem o que ainda não existe** — o padrão do
   Gagarin, que o D-55 já usava.
5. **O Estacionamento é só visual, por ora.** O GDD publica as **20 vagas** e **nunca o preço da
   hora**. Cobrar exigiria arbitrar um número, e cobrar estacionamento de um jogador que está
   esperando a própria carga é atrito que irrita mais do que ensina. As vagas ficam como
   **lacuna aberta**.
6. **Zoom na colônia e na Capital**, com **o mesmo idioma do mapa do planeta**: roda do mouse, botões
   −/+, e "centralizar". O jogador aprende uma vez e usa nos três lugares. **O zoom não persiste**
   entre aberturas — a tela abre sempre enquadrada.

### A contradição do GDD que o painel da Endurance resolve

O §3 (v3.0) diz que o telescópio **Gagarin "repousa no casco" da Endurance**. A versão sanitizada diz
o contrário: *"O Gagarin **não** repousa sobre seu casco: é um satélite orbital lançado após o
pouso"*. A **tabela de precedência da seção 0** já resolvia isto — *"É satélite orbital do Governo; a
Endurance permanece em solo"* — e o painel conta **a versão certa**. Nenhuma decisão nova: é o D-47
aplicado. <br>
O **v36 já nasce com a versão certa**, e é por isso que ele existe.

### O que fica de fora

- **As missões da Endurance** ("fonte de peças e missões narrativas") não existem. O painel diz isso.
- **As rotas do Espaçoporto** não abriram. O painel mostra os 5 planetas com a distância e o risco que
  o GDD publica, e diz que ninguém viaja ainda.
- **A cobrança do estacionamento** — <b>lacuna aberta</b>.

---

## D-64 — O mapa ganha grade: 15×15 centrado em você, com linhas e coordenadas.
**Data:** 2026-07-11 · **Status:** decidido pelo usuário · **GDD: omisso (a geometria é do D-51)**

### O que havia, e por que não servia

O mapa do D-51 abria no **planeta inteiro**: 101×101 células num SVG de meio milhar de pixels, o que
dá **6 px por célula**. A "grade de fundo" eram **9 linhas decorativas por eixo** — não eram células,
eram décimos do desenho —, e **coordenada nenhuma aparecia na tela**: o X e o Y só existiam como
texto no cabeçalho ("Você em (0, 3)") e nos painéis laterais.

O resultado é que o mapa dizia *onde* o colono estava sem nunca **mostrar**. Ele via um borrão com
pontos, e para achar a própria colônia tinha de caçar o círculo âmbar.

### As decisões do usuário (2026-07-11)

1. **O mapa abre em 15×15, centrado na colônia do jogador** — e o botão da mira devolve esse
   enquadramento a qualquer momento. O **zoom livre continua** por cima disso: dá para afastar até o
   planeta inteiro e aproximar mais. Travar a vista em 15×15 tornaria as **120 zonas neutras dos
   cantos** (±45..50) inalcançáveis sem dezenas de arrastes — elas continuam a exigir o afastamento.
2. **Na borda do planeta, a vista passa da grade em vez de se prender a ela.** Um colono em (50,50)
   fica no **meio da tela**, e o que sobra ao redor é o vazio de fora do mundo — que o retângulo do
   planeta deixa visível. Preso à borda (o comportamento antigo), ele nunca se veria no centro, que é
   justamente o que "foco no slot dele" promete. O que se prende é o **centro da vista**, que não sai
   do planeta: sem isso o mapa poderia sumir da tela.
3. **Os números moram numa calha fora do mapa** — o X em cima, o Y à esquerda, como numa planilha —,
   e **não escorregam com o arraste**. Rótulo dentro de cada célula seriam 225 números brigando com
   os marcadores.
4. **Célula vazia não é alvo de clique.** A grade é leitura: a célula sob o cursor acende e a
   coordenada dela aparece, mas continuam clicáveis só a Capital, as vizinhas e as zonas.
5. **A tela de Fundação ganha a mesma grade e as mesmas réguas**, nas duas abas. Nela não há "seu
   slot" para enquadrar — o colono ainda não fundou —, mas escolher onde morar era clicar às cegas
   num borrão de 5 px por célula.
6. **As faixas do centro passam a ser sombreadas, célula a célula**: o disco de founders (d ≤ 4) e o
   anel livre (4 < d ≤ 5). Sombreia-se a **célula**, e não um círculo por cima dela, porque a faixa
   **é** um conjunto de células — a fronteira é a distância euclidiana **exata** de
   `MapaFertways::faixaDe`, e um círculo desenhado por cima mentiria justo nas células da beirada,
   que são as que importam.

### O que isto obrigou no servidor

`GET /colonies` passou a publicar `raio_founder` e `raio_anel` (aditivo). Pela regra do D-51, a
geometria vem da API e **nunca** de constante no React: um número copiado para o frontend
sobreviveria a uma mudança da grade mentindo. O `GET /map` da Fundação já os publicava.

### O que a foto pegou e o e2e não pegaria

O D-63 já tinha ensinado que **e2e não prova desenho**, e aqui a lição cobrou de novo. Com a suíte
inteira verde, a captura de tela mostrou três defeitos que nenhuma asserção via:

- a calha existia só **em cima e à esquerda**, então o rótulo da **última** coluna e da **última**
  linha (o "50" e o "−50") era centrado na borda de fora do SVG e saía **cortado ao meio**. A calha
  passou a ser simétrica — os números só ocupam dois lados, mas a **folga** precisa dos quatro;
- os botões de zoom, no canto superior direito, **tapavam o número da última coluna**. Desceram para
  baixo da régua;
- o botão de centralizar usava o caractere **⌖ (U+2316), que a fonte do jogo não tem**: o colono via
  o retângulo vazio do glifo que falta. Virou um ícone desenhado. (Este era **anterior** ao D-64 —
  estava no ar desde o D-51 e ninguém tinha olhado.)

**A prova do enquadramento, essa sim, é do e2e — e vem das réguas, não do desenho.** Um SVG que
ninguém lê pode estar em qualquer zoom; os números dizem que células o jogador está vendo. Com o
colono semeado em (0,3), o teste exige que o X vá de −7 a 7 e o Y de −4 a 10: 15 colunas, 15 linhas,
ele no meio.

---

## D-65 — Dois mercados, a carroceria com vários recursos, e o Pátio da Capital.
**Data:** 2026-07-12 · **Status:** decidido pelo usuário · **GDD: o slot 6 existe; o preço da hora, não**

### O que havia

**O Mercado Local não era uma tela.** O botão dele dizia "Abrir os Acordos" e abria a **mesma modal
do Mercado Central**, só que numa aba diferente. Seis abas dividiam o mesmo salão — doca, ofertar
entre colonos, ver ofertas de colonos, ofertar no Mercado Central, ofertas globais, veículos usados
— e havia duas portas para ele. O colono não tinha como saber em que canal estava negociando.

**O veículo levava um recurso por viagem.** Não por regra: o §25.4 mede a capacidade em unidades
**somadas** ("1.000 unidades de qualquer recurso = 1 m³"), e o servidor sempre soube disso —
`cargo_json` é JSON e `array_sum(carga)` é conferido contra a capacidade. Quem insistia em um
recurso era a **tela**, que montava `{ [código]: qtd }` de um `<select>` só.

**O veículo nunca "estava" na Capital.** `colony_id` era dono **e** lugar: ele viajava até lá,
descarregava e voltava vazio, sozinho.

### As decisões do usuário (2026-07-12)

1. **O Mercado Local vira tela própria**, aberta pela construção (o botão passa a ser "Abrir o
   Mercado"). Nele: **enviar carga** ao depósito da Capital e a outros colonos, **ofertar a colonos**
   e **ver as ofertas dos colonos**.
2. **O Mercado Central (Capital) fica só com o que é do governo**: ofertar no Mercado Central,
   ofertas globais — e o **Pátio e depósito**. Ofertar no Central e as ofertas globais **só** se
   veem pela Capital.
3. **Os veículos usados saem do Mercado e vão para o Ministério dos Transportes.** Veículo é assunto
   do Ministério — ele é o cartório da placa (§16.3), e é lá que se compra o novo, se repara e se
   sucateia. O usado ao lado do novo.
4. **A carroceria leva vários recursos até lotar.** Uma lista de linhas (recurso, quantidade) somada
   contra a capacidade **efetiva** — a encolhida pelo desgaste (§16.4), que é a que o servidor cobra.
   Vale para o depósito, para a entrega a colono, para a retirada e para o Acordo (que agora paga a
   promessa inteira numa viagem só, em vez de uma por recurso).
5. **Todo veículo que entrega no depósito FICA estacionado no Pátio da Capital.** Não volta sozinho.
   É de lá que ele sai de novo.
6. **Do Pátio, ele sai para a sua colônia ou direto para outro colono** — e, tendo entregado, **segue
   para casa**.
7. **A retirada de hoje continua existindo** (mandar um veículo de casa buscar, ida e volta): ela
   serve a quem **não** tem veículo estacionado lá. Ter um no Pátio passa a ser a vantagem — metade
   do caminho.
8. **A hora do Pátio: 0,005 Fert$, por veículo, sem limite de vagas.** Vai para o Tesouro. **Sem
   Fert$, o veículo é rebocado para casa**, de graça: ninguém fica devendo, ninguém perde o veículo,
   e ninguém guarda caminhão de graça no meio da Capital.
9. **Se sobrou carga** (o teto do depósito barrou parte dela), o veículo **volta na hora** com a
   sobra: só estaciona quem descarregou tudo.
10. **Cada perna paga a sua energia, no ato do despacho.**

### O que estas decisões obrigaram no motor

**A viagem passou a ter pernas independentes.** Até aqui `distance_slots` era uma só, e a volta era
igual à ida — porque toda viagem saía de casa e voltava para casa. Deixou de ser verdade: um
caminhão do Pátio entrega num colono e **segue para casa**, o que são **três pontos e duas
distâncias**. Nasceu `return_distance_slots`, e com ele a regra que unifica tudo:

> **`return_distance_slots` nulo significa "viagem só de ida": o veículo termina no destino e fica lá.**

É esse nulo — e nenhum outro sinalizador — que faz o depósito estacionar o veículo no Pátio e que
faz o caminhão do Pátio chegar em casa e ficar. O reboque usa o mesmo nulo.

**A energia deixou de ser sempre ida-e-volta (revisão do D-30).** Ela agora é a soma das pernas que
a viagem **vai de fato rodar**, arredondada uma vez só. Levar ao depósito passou a custar **metade**
do que custava (uma perna), porque o veículo não volta. A retirada e a entrega entre colonos
continuam custando duas.

**A volta forçada pela sobra não custa energia.** Ela não foi paga no despacho (a viagem era só de
ida) e não é cobrada depois: quem a causou foi o **teto**, não o colono. Cobrar seria multar o azar.

### O que a tarifa do estacionamento fecha

O GDD publica o slot 6 como "Estacionamento de Caminhões. 20 vagas. **Cobrança por hora**" — e
**nunca publica o preço**. O D-63 tinha deixado isso como lacuna aberta, e é ela que se fecha aqui,
por arbitragem do usuário: **0,005 Fert$/hora**. As **vagas**, essas, ficam **sem limite** — o
usuário decidiu que o Pátio não recusa ninguém, e o número 20 do GDD fica como texto.

Cobram-se **horas cheias**: a fração que ainda não fechou uma hora fica para o próximo tick. Sem
isso, um tick por minuto cobraria sessenta frações arredondadas, e o colono pagaria muito mais que
a tarifa.

### O que ficou de fora, e por quê

- **Levar carga ao depósito continua acessível pela Capital**, e não só pelo Mercado Local. É a única
  ação que aparece nas duas telas — de propósito: a colônia nasce **sem** o Mercado Local, e quem
  ainda não o ergueu ficaria com o depósito inalcançável. Negociar com colonos, esse sim, exige a
  construção: ela é a porta disso.
- **O veículo estacionado não pode ir para uma zona neutra** a partir do Pátio. Ninguém pediu, e a
  zona é uma retirada, que já tem o seu caminho.

---

## D-66 — A guerra (§27 e §28.10): a Fatia 2 do D-52, e o Nióbio que a destravou.
**Data:** 2026-07-12 · **Status:** arbitrado pelo usuário · **GDD: o §27 publica muito; cala seis coisas**

Levantamento do §27 e do §28.10 feito em 2026-07-12, ao retomar. **O D-52 subestimava as duas
pontas:** publicava-se mais do que ele dizia (a máquina de combate inteira está no documento), e
faltava algo que ele não tinha visto — a guerra, como especificada, **nasceria morta**.

### O bloqueio que ninguém tinha visto: o Nióbio

A **Sentinela** é a única unidade com poder de ataque (§27.1) e custa **3 Nióbio Alienígena** no
nível 1. Ao conferir a produção:

- **Nada no jogo produz Nióbio.** Zero construções o têm em `producao_hora_json`.
- O planeta inteiro tem **20 unidades**, todas do kit de fundação: 5 em cada uma de quatro colônias.
  A colônia `teste` tem **zero**.
- E o **Quartel** — onde a Sentinela é produzida — **também custa 3 Nióbio** para erguer.

Logo, cada colônia ergueria **uma** Sentinela, e quem tivesse Quartel, **nenhuma**. Uma Sentinela tem
**80 pontos de ataque**; uma zona guarnecida com os 20 Robôs Mineradores que a ocupação do D-52 exige
tem **500 de defesa** (20 × 25, §27.2). **Atacar seria matematicamente impossível.** É o Caminhão
antes do D-60 outra vez: publicado na tabela, inalcançável no jogo.

**1. O governo vende Nióbio.** O D-17 lista "contratos do governo" como fonte de raros da Temporada 1,
e o **Tesouro tem 10.000** (dotação do D-57). O precedente é o Ministério dos Transportes (D-60): o
governo fabrica, o colono compra, o caixa do Tesouro é a fonte e pode secar.

**O preço: o de referência do §06 (0,3163 Fert$) × 10 ≈ 3,16 Fert$ a unidade.** O múltiplo é
arbitragem do usuário. Ao preço de referência puro o raro seria **enfeite** — 3 Nióbio custariam 95
centavos, e o kit inicial de um colono é 50 Fert$. A ×10 o Nióbio freia sem proibir. **É parâmetro do
operador**, não constante de código: a Secretaria de Finanças (§06) já é o lugar onde preço se
declara, e ele muda sem deploy.

> **A tabela de preços do GDD é estranha e não a consertamos.** O Nióbio, *raro*, vale 0,3163; os
> Componentes Eletrônicos, *secundários*, valem 1,2778 — quatro vezes mais. É a mesma doença do D-34
> (Metal Bruto 5,5× abaixo do §07). Não se toca nela aqui; o ×10 contorna o sintoma onde ele morde.

### As cinco lacunas do §27, arbitradas

**2. "Estoque protegido" = o que cabe no Depósito de Zona Neutra.** A expressão aparece **seis vezes**
no GDD — o saque de 50% da invasão, os 30% do cerco e o alvo do Predador dependem dela — e o mecanismo
**nunca é descrito em lugar nenhum**. Decisão: está protegido o que couber na capacidade do Depósito
no nível construído (§19.6 publica `500 … 19.222`); **o que excede está exposto**. Não inventa número
nenhum, reusa uma construção que já está no catálogo, e dá sentido econômico a subir o Depósito.

> **2-bis. E isso obrigou a revisar a Fatia 1 — senão o saque seria sempre zero.**
>
> A extração da Fatia 1 **parava no teto do Depósito** (`$credito = min($unidades, $espaco)`), então o
> `deposit_amount` nunca o excedia. Com "protegido = o que cabe no Depósito", **nada jamais estaria
> exposto**, e os 50% do §27.8 incidiriam sempre sobre zero. **A guerra não teria espólio nenhum.** A
> colisão só apareceu ao implementar, e o usuário a resolveu em 2026-07-12:
>
> **A extração deixa de parar no teto.** O excedente **empilha ao relento** na zona, e é ele o butim.
> O Depósito passa a dizer o que está *protegido*, não o que *cabe*.
>
> ⚠️ **Contraria o §19.6 de propósito**, que chama aqueles números de "capacidade". É a mesma classe de
> contradição deliberada do tributo (D-32) e do Ministério dos Transportes (D-60). **Não "conserte"
> sem perguntar.**
>
> O efeito no jogo é o que se queria: deixar mineral rendendo na zona vira **risco de verdade**,
> retirá-lo vira hábito, e subir o Depósito vale a pena porque **protege mais**. E o cerco passa a ser
> a única coisa que trava a acumulação — o que casa exatamente com o §28.10 ("a extração continua mas
> não há onde armazenar", e o que se extrai **se perde**).

**3. Os bônus defensivos do §27.3**, que o próprio documento escreve como `+X% / +Y% / +Z%` e chama de
"**valores configuráveis**". São **três** construções, não duas como o D-52 anotava — e nenhuma existe
no catálogo. Arbitrado, **aditivos**: **Muralha de Perímetro +20%, Torre de Vigia +30%, Bastião +50%**
(as três juntas dobram a defesa).

> **"Valores configuráveis" é o mesmo gancho do §16** que destravou a depreciação no D-60: o GDD manda
> alguém declará-los e nunca publica nenhum. Vão para o **painel de admin**, não para o código. É o
> padrão do D-35, pela terceira vez.

> **O bônus escala com o NÍVEL, e o §27.3 não diz isso — é derivação, não número novo.** O documento
> escreve só "+X% / +Y% / +Z%". Mas as três construções têm **cinco níveis** (a curva 1,65× do
> catálogo), e um bônus fixo tornaria os níveis 2 a 5 **decorativos**: pagar-se-iam 8.894 Metal Bruto
> por um Bastião nível 5 que defende igual ao nível 1. Escala **linear**: no nível 1 valem os
> +20/+30/+50 arbitrados (as três juntas dobram a defesa, que foi o que se aprovou), e daí para cima
> crescem proporcionalmente. Os **números** continuam sendo os do operador; só a forma da escala é
> derivada — pelo mesmo princípio do D-52 ("o GDD publica a curva e cala apenas a base").
>
> O **Abrigo de Robôs não dá bônus.** O §27.3 não o lista, e não o inventamos: ele é onde os
> sobreviventes se recolhem (§27.6) e o que o Predador tem de vencer (§28.10).

**4. O custo das quatro construções de defesa** (lacuna 7 do D-52, parte dela). A âncora é o **Posto de
Comando**, que o D-52 já arbitrara em 800 Metal Bruto + 300 Fert$ e 8 h. Decisão: **fortificar custa
menos do que ocupar** — tomar a zona é o gasto grande, defendê-la bem é incremental.

| Construção | Custo (nível 1) | Tempo |
|---|---|---|
| Muralha de Perímetro | 400 Metal Bruto + 100 Ligas | 4 h |
| Torre de Vigia | 300 Metal Bruto + 150 Ligas + 30 Componentes | 6 h |
| Bastião | 1.200 Metal Bruto + 400 Ligas + 100 Componentes | 12 h |
| Abrigo de Robôs | 500 Metal Bruto + 200 Ligas | 6 h |

Só a **base** é arbitrada: a curva **1,65×** da v3.4 gera os níveis 2–5 sozinha, como o D-52 já
observara ("o GDD publica a curva e cala apenas a base").

**5. O "Módulo Operacional" do Predador.** A v3.2 sanitizou a "captura de trabalhadores" da v3.0 para
"apreensão de Módulos Operacionais", e **nunca diz o que é um**. Arbitrado: o Predador **desliga uma
estrutura da zona** (Extração, Depósito, Torre…) até o dono pagar o resgate ou repará-la. **"Não
protegido"** = as estruturas cobertas pelo **Bastião** são imunes. Casa com o texto da v3.2 ("módulo
temporariamente removido, que pode ser rastreado, recuperado e reparado") e **não cria conceito novo**.

**6. As duas chances que o §28.10 manda calcular e não publica.**
- **Torre de Vigia detecta o Infiltrador: 15% × nível, por rodada** (nv1 = 15%, nv5 = 75%). O §28.10
  só diz "proporcional ao nível da Torre".
- **Predador: 50% + 10% × (nível dele − nível do Abrigo de Robôs)**, preso entre **10% e 90%**. O
  §28.10 só diz "compara o nível do Predador ao nível do Abrigo". Empate dá moeda justa; cada nível de
  vantagem vale 10 pontos; nunca há certeza, nos dois sentidos.

Ambas são **parâmetros do operador**, como os bônus.

**7. O Cerco, num jogo sem rotas.** O §28.10 manda "bloquear as rotas externas", e **o jogo não tem
rotas** — a viagem é ponto a ponto por distância (D-30, D-65). Arbitrado: enquanto cercada, **nada
entra nem sai da zona** (nenhum despacho para ela, nenhuma retirada dela), e após **30 minutos** (as 3
rodadas do §28.10) o depósito **para de aceitar a extração**, que continua correndo e **se perde** —
é o que o documento manda ("extração continua mas não há onde armazenar"). O defensor rompe mandando
Sentinelas, ou se rende em 48 h entregando **30% do não protegido**. Reusa o bloqueio de saída de
carga que a restrição comercial do §9.4 já tem.

### Publicado — não arbitre, não invente

A máquina de combate **inteira** está no GDD, e o D-52 não a tinha lido:

- **Sentinela** (§27.1): Defesa `100 150 225 338 506`, Ataque `80 120 180 270 405`. Custo na curva
  1,65×: Ligas `100 165 272 449 741`, Componentes `50 82 136 225 371`, Metal Bruto `20 33 54 90 148`,
  Nióbio `3 5 8 13 22`. Produzida no **Quartel**.
- **Robô Minerador como defensor** (§27.2): defesa = **25% da Sentinela** (`25 38 56 84 126`); ataque
  **zero**. Infiltrador e Predador **já estão no catálogo** com custo publicado, e **sem Nióbio**.
- **Força** (§27.3): Ofensiva = Σ ataque das Sentinelas enviadas. Defensiva = Σ defesa das unidades na
  zona **× bônus de construção**.
- **Combate por rodadas de 10 min** (§27.5). Dano ao defensor por rodada =
  `(Força Ofensiva / Força Total) × 15% × Força Defensiva`, e o simétrico ao atacante. Rodadas
  até um lado zerar. Reforços que chegam **entram no cálculo a partir da rodada seguinte** — é
  deliberado que o combate equilibrado dure ~2 h, para dar tempo de socorrer.

> **8. A fórmula do §27.5, à letra, faz o combate NUNCA terminar — e a arbitragem foi mudar uma
> palavra.** Descoberto ao implementar, em 2026-07-12.
>
> O texto diz "× Força Defensiva **atual**". Se o dano de cada rodada se recalcula sobre a força que
> restou, a força decai **geometricamente** e não chega a zero nunca. Conferido com os números do
> próprio documento: no cenário "Ataque 2.000 vs Defesa 500", que o GDD estima em **~4 rodadas**, a
> defesa ainda tem **92 pontos na rodada 12**. As três "estimativas de duração" do §27.5 **não
> derivam** da fórmula que está impressa ao lado delas.
>
> **Decisão do usuário: o dano sai da força INICIAL**, calculada uma vez, com que os dois lados
> entraram no combate. O dano por rodada passa a ser **constante**, a força cai **linearmente**, e a
> batalha termina sozinha — **sem piso inventado e sem constante nova**.
>
> E há uma confirmação forte de que é a leitura certa: no cenário **equilibrado** do próprio GDD
> (Ataque 1.000 × Defesa 800), a força inicial dá **12 rodadas, 120 minutos** — que é **exatamente** o
> que o §27.5 escreve na coluna "estimativa de duração". A fórmula com a força *atual* dá 19 rodadas
> ali. Quem escreveu a tabela calculou com a força inicial e escreveu "atual" no texto.
>
> ⚠️ Contraria a palavra "atual" do §27.5, de propósito. **Não "conserte" sem perguntar.**
>
> **Reforços continuam entrando** (§27.5): quem chega no meio soma à força, e o dano por rodada é
> **recalculado naquele momento** — a partir da nova força inicial. É o que preserva o desenho que o
> documento declara deliberado ("reforços tardios podem ainda mudar o resultado").
- **Perdas proporcionais** (§27.6): as baixas se distribuem entre as unidades presentes; sobreviventes
  voltam ao Abrigo (defensores) ou ao Quartel (atacantes) **com HP reduzido**; HP zero é **destruição
  permanente**.
- **Marcha de combate 1,3× mais lenta** que a civil (§27.4) — ida e reforço. A distância do mapa é que
  decide quem chega a tempo.
- **Defensor offline genuíno: +20% de defesa** (§27.7). E o anti-exploit é explícito: **quem ficou
  offline depois de saber do ataque não ganha o bônus**.
- **Vitória e saque** (§27.8, corrigido pela v3.2): ao zerar a defesa, o atacante toma a zona e
  saqueia **50% do estoque não protegido**. ⚠️ **Os outros 50% permanecem no depósito.** A v3.0 dizia
  que eram *destruídos*; a tabela de precedência da seção 0 dá ganho à v3.2, que diz "não há destruição
  automática adicional". **Não copie o §27.8 da v3.0 à letra.**
- **Abandono voluntário** (§27.9): o defensor retira as tropas, a zona passa **na hora**, e o saque de
  50% se aplica assim mesmo. A zona abandonada se reconquista pelos 3 requisitos normais, e o novo dono
  **espera o tempo de ocupação** antes de extrair.
- **Cooldown de 48 h** (§27.10): o mesmo jogador não reataca a mesma zona. **Outros podem.**
- **Proteção de novato: 8 dias** (seção 0 e §28.4). O §27.11 diz "20 dias" e depois "a partir do 8º"; a
  seção 0 resolve, pelo D-47.
- **Manutenção territorial** (§27.12): nível 1 custa **50 Biomassa + 30 Energia por dia**. Não pago,
  decai — e aqui o **D-52 já arbitrara**: **5% por dia** (não por hora) e abandono em **72 h**, porque
  a Parte I corrige a Parte II.
- **Sabotagem** (§28.10): o Infiltrador tem **60% de chance base** por rodada, se não detectado. Se
  detectado, cai em combate normal e provavelmente morre. Se passa, a estrutura-alvo **perde capacidade
  proporcional ao nível do Infiltrador** e precisa de reparo.

### Escopo — os quatro ataques

Decisão do usuário: **Invasão Direta, Cerco, Sabotagem (Infiltrador) e Apreensão de Módulos
(Predador)**, de uma vez. Os quatro compartilham a mesma máquina de rodadas de 10 min; é ela que se
constrói uma vez só.

### O que continua em aberto, e não bloqueia

- **As seis outras estruturas de zona** (Estrutura de Extração, Refinaria de Campo, Central de
  Comunicação, Plataforma de Pouso, Estacionamento, Cemitério de Robôs) — lacuna 7 do D-52, ainda
  aberta. Nenhuma é exigida pela guerra.
- **Teto de zonas por jogador** (lacuna 9). Só o Bastião cita "zonas defendidas simultaneamente 1–3", e
  isso não é um teto de posse. Fica aberta.
- **Ranking de guerras** (§27.13) — publicado por inteiro (percentis, pesos), mas **não há sistema de
  ranking** no jogo. Não entra nesta fatia.
- **Federação** — o §28.10 diz que uma federação aliada pode romper um cerco. **Federações não
  existem** (é a mesma inércia do D-44). O cerco se rompe só pelo dono da zona, por ora.

---

## D-67 — A zona vira lugar, e as telas viram telas.
**Data:** 2026-07-13 · **Status:** arbitrado pelo usuário · **GDD: o §17.4 descreve tudo; cala o custo**

Pedido do usuário: o Mapa, a Capital e a Zona Neutra deixam de ser *popups* e viram **telas**. E, ao
levantar o que a tela da zona teria de mostrar, apareceu um buraco meu.

### O buraco: as defesas que eu criei são inalcançáveis

O D-66 pôs no catálogo a **Muralha de Perímetro**, a **Torre de Vigia**, o **Bastião** e o **Abrigo de
Robôs**, e o motor de combate **lê** os níveis deles. Mas **nada no jogo os ergue**: `wall_level`,
`watchtower_level`, `bastion_level` e `shelter_level` nascem em zero e nunca saem de zero. Em produção,
hoje:

- O bônus defensivo do §27.3 é **sempre 0%**. Fortificar não existe.
- A Torre de Vigia **nunca detecta** um Infiltrador (15% × 0). A sabotagem passa sempre.
- O Depósito da zona fica **preso no nível 1**: não há como proteger mais estoque do saque.

É o Caminhão antes do D-60 outra vez — e desta vez fui **eu** que o criei. A tela da zona é onde se
conserta.

### O erro do Bastião, e a decisão de mantê-lo

⚠️ **O GDD não põe o Bastião na zona.** Ele é uma das **15 especializações da colônia**: exige *Torre de
Defesa N3 + Quartel N3* e "dobra o bônus defensivo da Torre de Defesa" (+100%), sem conceder ataque. O
§17.4, que lista as estruturas de zona, **não o menciona**. Foi o §27.3 — "Muralha +X%, Bastião +Y%,
Torre de Vigia +Z%" — que me fez tratá-lo como estrutura de zona no D-66, e eu **inventei um custo** de
zona para ele.

**Decisão do usuário: mantém-se como estrutura de zona.** É contradição deliberada ao §17.4, na mesma
família do tributo (D-32) e do Ministério dos Transportes (D-60). O §27.3 fica coerente consigo mesmo —
as três defesas que ele nomeia existem no lugar onde ele as usa. **Não "conserte" sem perguntar.**

> O Bastião da **colônia** (a especialização) continua existindo no GDD e fica para o dia em que as 15
> especializações entrarem. São duas coisas com o mesmo nome, e o documento é que as confundiu primeiro.

### O que o §17.4 publica, e que ninguém tinha lido

**A lacuna 7 do D-52 nunca foi de função — só de custo.** O §17.4 descreve **todas** as estruturas:

| Estrutura | O que o GDD diz que ela faz |
|---|---|
| Posto de Comando | Primeira da ocupação. Sem ela, não há controle territorial. |
| Depósito (10 níveis) | Armazena. *"Quando lota, a extração para"* — ⚠️ ver abaixo |
| Abrigo de Robôs | Onde as unidades **se recuperam entre turnos** |
| Estrutura de Extração | Varia com o recurso — ⚠️ **e a zona já extrai sem ela** |
| Muralha de Perímetro | Dificulta a Invasão Direta |
| Torre de Vigia | **Avisa com antecedência** da aproximação inimiga; cada nível aumenta o tempo |
| Refinaria de Campo | **Primário vira secundário na zona**, antes do transporte |
| Central de Comunicação | Alertas à **federação** — que não existe |
| Plataforma de Pouso | Pouso da **Nave de Transporte Planetária** — que não existe |
| Estacionamento | 10 vagas para caminhões, como o da Capital |
| Cemitério de Robôs | **Sem função mecânica — apenas visual**, declarado pelo próprio GDD |

> ⚠️ **"Quando lota, a extração para" é a TERCEIRA vez que o GDD diz isso**, e nós já o contrariamos de
> propósito no D-66 (2-bis): se a extração parasse no teto, nada jamais ficaria exposto e **o saque
> seria sempre zero**. A decisão continua de pé. Não a reabra sem reabrir o saque junto.

> ⚠️ **A zona extrai sem Estrutura de Extração.** A Fatia 1 fez a extração correr a partir da ocupação,
> e o §17.4 dá esse papel a uma estrutura que não existe no jogo. **Lacuna aberta e declarada** — não a
> "conserte" travando a extração das zonas que já rendem hoje.

### As arbitragens

**1. As telas.** Mapa, Capital e Zona viram **tela cheia com URL própria** (entra o `react-router`):
`/mapa`, `/capital`, `/zona/:id`. O botão Voltar do navegador passa a funcionar, a página recarrega
onde estava, e um link pode ser mandado a alguém. As demais (Mercado, Frota, Quartel, Ministério)
seguem o mesmo idioma.

**2. A planta da zona: áreas, não colmeia.** Como a Capital do D-63, e não como os 21 slots hex da
colônia (D-59) — uma muralha **deve** estar no perímetro, e uma grade de slots não sabe disso. ⚠️ **A
planta não está no GDD**: é arbitragem do usuário, como as quatro áreas da Capital.

**3. Custo e tempo.** Mantêm-se os do D-66 (Muralha 400 MB + 100 Ligas / 4 h · Torre de Vigia 300 MB +
150 Ligas + 30 Componentes / 6 h · Bastião 1.200 MB + 400 Ligas + 100 Componentes / 12 h · Abrigo 500 MB
+ 200 Ligas / 6 h). O **Depósito não é lacuna** — o §4.2 publica os 10 níveis, e eles já estão no
catálogo. As novas seguem a mesma escala:

| Estrutura | Custo (nível 1) | Tempo |
|---|---|---|
| Refinaria de Campo | 600 Metal Bruto + 250 Ligas + 60 Componentes | 8 h |
| Estacionamento da Zona | 300 Metal Bruto + 100 Ligas | 4 h |
| Cemitério de Robôs | 150 Metal Bruto | 2 h |

**4. A Refinaria de Campo: 2 primários → 1 secundário**, por distrito.

| Distrito | Extrai | A Refinaria transforma em |
|---|---|---|
| Nordeste | Metal Bruto | **Ligas Metálicas** |
| Sudeste | Água | **Compostos Químicos** |
| Sudoeste | Oxigênio | **Compostos Químicos** |
| Noroeste | Biomassa | **Biocombustível** |

**Nenhuma construção do jogo converte** — todas produzem a taxa fixa por hora, sem insumo. Esta é a
primeira, e a taxa **2:1** é do usuário. Ela **não cria matéria do nada**: dobra o valor por unidade
transportada, que é o que o §17.4 promete ("aumentando o valor da carga antes mesmo do transporte"), e
o ganho real é de **volume** — a carroceria leva metade das unidades para o mesmo minério. Os níveis
aumentam quanto ela processa por hora.

**5. A Torre de Vigia avisa 10 min por nível.** Nível 1 vê o ataque 10 min antes de a marcha chegar;
nível 5, 50 min — que, na maioria das distâncias, é ver o exército partir. **A unidade de medida já
existe**: é uma rodada de combate (§27.5). **Parâmetro do operador**, como os demais da guerra.

> A Torre passa a ter **duas** funções, e as duas são do GDD: avisar (§17.4) e **detectar o Infiltrador**
> (§28.10, 15% por nível). Não se confundem.

**6. Entrega física: o canteiro de obras.** A zona ganha um **estoque de material** próprio, separado do
depósito de minério. Despacha-se um veículo com Metal Bruto e Ligas; ao chegar, o material entra no
canteiro. **A obra só começa quando o canteiro tem o custo inteiro**, e a sobra fica lá para a próxima.

> ⚠️ **Isto contradiz a ocupação**, que hoje debita da colônia e ergue o Posto de Comando **sem veículo
> nenhum** (D-52). Decisão do usuário: a ocupação fica como está; as obras **posteriores** exigem
> entrega. A razão é de desenho: a ocupação é o ato de chegar, e as obras são o ato de investir.
>
> **E o cerco passa a impedir fortificar sob sítio** — nada entra nem sai (D-66) —, o que não foi
> planejado e é bom: quem cerca impede o cercado de se defender melhor.

**7. Estacionamento de graça; Cemitério decorativo.** A vaga na **própria** zona não custa hora nenhuma
— a tarifa do Pátio da Capital (D-65) existe porque aquele pátio é **do governo**. O Cemitério não tem
função mecânica: o **próprio GDD** o declara "apenas visual". Ele mostra as unidades destruídas ali, e é
a única construção do jogo que se ergue só por gosto.

### Fora de escopo, e por quê

- **Central de Comunicação da Zona** — só serve à **federação**, que não existe (mesma inércia do D-44).
- **Plataforma de Pouso da Zona** — só serve à **Nave de Transporte Planetária**, que está no catálogo e
  não no jogo. Ela é uma fatia inteira (§17.5): voo, placa, transporte de robôs entre zonas.
- **Estrutura de Extração** — a zona já extrai sem ela. Ver a lacuna declarada acima.

---

## D-68 — A gestão de imagens: o jogo ganha rosto.
**Data:** 2026-07-13 · **Status:** decidido pelo usuário · **Não há GDD sobre isto**

O usuário mandou 44 sprites isométricos (264×264 e 1024×1024, fundo transparente) e pediu uma aba de
administração para geri-los. Três problemas apareceram antes de escrever uma linha:

**1. Os nomes não batem com nada.** A arte veio com nomes de fantasia (`reator-helios`,
`estufa-aurora`, `nucleo-ares`) e o jogo conhece slugs (`reator_de_energia`, `fazenda`,
`gerador_de_atmosfera`). **Nenhuma associação é automática** — e é por isso que o painel existe: quem
decide qual imagem é qual construção é uma pessoa.

**2. A cobertura é parcial.** `colonia-base` tem 5 imagens e a colônia tem 17 construções.

**3. Upload dentro da árvore de deploy QUEBRARIA o deploy.** O `deploy.sh` **aborta** se achar arquivo
não rastreado (lição de 2026-07-11).

### As decisões

**Onde a arte aparece:** nas **cenas E nos painéis**. O hexágono da colônia mostra o prédio (264px) e o
cartão de detalhe mostra a arte grande (1024px). É para isso que ela foi feita.

**Quem não tem imagem continua sendo HEXÁGONO.** A API só devolve o que **tem** imagem; o resto o
frontend nem procura. Nada quebra por falta de arte, e dá para ir preenchendo aos poucos.

**Onde os arquivos moram: `/home/fertways/media/`**, fora do repositório e fora da árvore de deploy,
servidos por um symlink em `public_html/media`. 52 MB de PNG no git seriam para sempre, e cada upload
exigiria um commit — o que derrotaria o "trocar quando quisermos".

> ⚠️ **O symlink precisa ser do `fertways`.** Criado como root, o Apache respondeu **403**: a diretiva
> `SymLinksIfOwnerMatch` exige que o dono do link e o do alvo batam. `chown -h` resolveu.

> ⚠️ **`/media` está excluído do fallback do SPA**, junto com `/central`. Sem isso, uma imagem que não
> existe cairia no `index.html` e o servidor devolveria a **aplicação inteira, com 200**, para cada
> `<img>` quebrada — a pior combinação possível para quem for depurar.

**As nove categorias:** as oito do zip mais `mapas`, que nasce vazia. Fixas: uma categoria criada à mão
e escrita errado vira uma pasta órfã.

**No painel:** enviar imagem, trocar a de uma construção (e desvincular, voltando ao hexágono), e
apagar da biblioteca. **Apagar diz antes quais construções perdem a arte**, e a auditoria registra
quais foram — senão alguém apagaria uma imagem, três prédios ficariam sem arte, e ninguém relacionaria
as duas coisas semanas depois.

**Os vínculos iniciais saíram de OLHAR a arte, não do nome.** `estufa-aurora` são estufas com plantas —
é a Fazenda. `estacao-nereida` são tanques azuis — é a Captação de Água. `nucleo-ares` tem chaminés e
cilindros de gás sob uma cúpula — é o Gerador de Atmosfera. **18 vínculos evidentes; as outras 28
ficam sem vínculo**, porque eu não sei o que são e chutar poria arte errada num prédio.

### O que se aprendeu construindo

> **A lista de nomes de exibição virou UMA só.** Ela vivia dentro do gerador do GDD; o painel precisava
> dela para não mostrar `refinaria_quimica` ao operador. Duas cópias divergiriam no dia em que alguém
> corrigisse só uma — e um GDD que escreve "Refinaria quimica" e um painel que escreve "Refinaria
> Química" seriam dois jogos. Agora vive em `Domain\Media\NomesDeExibicao`, e o gerador a lê.

> **O Vite não servia `/media`, e o e2e teria passado em verde sobre uma colônia de hexágonos.** Em
> produção o Apache serve o symlink; em desenvolvimento não há symlink nenhum. Um plugin no
> `vite.config.ts` serve a mesma pasta. Sem isso, a arte simplesmente não apareceria no e2e — e o verde
> não diria nada. É a classe de falso-verde do D-63.

> **A cena morre e a arte chega depois.** O React em modo estrito monta e desmonta os componentes de
> propósito, e o `ColonyCanvas` destrói o jogo Phaser ao desmontar. A promessa da arte, disparada pela
> cena antiga, resolvia depois e chamava `desenhar()` num objeto já derrubado — `Cannot read properties
> of null (reading 'forEach')`, e a colônia inteira sumia. A guarda é `viva()`.

> **E a lição do D-63, cobrada de novo: fotografe e OLHE.** A primeira versão punha o número do nível no
> centro do hexágono, como sempre esteve. Sobre um hexágono chapado estava certo; sobre um prédio
> isométrico, o dígito caía **em cima da cúpula** e o nome **em cima da base**. Nenhum e2e reclamaria —
> os cliques funcionavam e o texto estava lá. Só se vê tirando uma foto. O `e2e/foto.mjs` existe para
> isso: `E2E_FOTOS=1 ./tools/e2e.sh`.

---

## D-69 — O card vira popup, o colono ganha perfil, e as zonas saem do esconderijo.
**Data:** 2026-07-13 · **Status:** decidido pelo usuário · **Não há GDD sobre isto**

Três pedidos do usuário, e um deles descobriu uma falta grave.

### 1. O detalhe da construção vira POPUP

Era um card fixo na barra direita. Agora abre **por cima da colônia**, com escurecimento, e fecha de
três maneiras: clicando fora, com **Esc**, e no **×**.

⚠️ **É popup, e não tela com URL** — ao contrário de tudo o mais desde o D-67. A razão é o que o card
mostra: o detalhe de uma construção **só faz sentido com a colônia atrás dele**. Uma tela cheia
esconderia justamente o que dá contexto ao card, e abrir o detalhe de um prédio não é navegação — é
olhar mais de perto.

> **E isso trouxe um bug que o e2e pegou na hora.** Atravessar a porta de uma construção (o Mercado
> Local leva ao Mercado, o Quartel à guerra) navegava **sem fechar o popup**. Ao voltar, o colono
> encontrava o escurecimento cobrindo tudo: o HUD, os recursos e o botão de sair ficavam
> **inalcançáveis atrás dele**. O `abrirPorta` agora fecha o card — quem atravessou a porta não
> precisa mais dela aberta.

### 2. O perfil do colono — ele não podia trocar a própria senha

**Descoberta ao implementar:** o colono podia fundar colônia, guerrear, comerciar e ocupar
território — e **não podia mudar nada da própria conta**. Nem o nome, nem o e-mail, nem a senha. A
única saída era pedir a um operador, pelo painel de admin.

Edita: **nome, nickname, e-mail, senha e o nome da colônia.**

**Trocar o e-mail exige a SENHA ATUAL; trocar o nome, não.** A diferença não é capricho: o e-mail é
com o que se **entra** no jogo, e **não há recuperação de conta em Fertways**. Quem pegasse uma sessão
aberta num computador esquecido poderia trocá-lo, trocar a senha, e o dono nunca mais entraria. Um
nome mal escolhido se corrige; uma conta tomada, não.

**Trocar a senha REVOGA as outras sessões** — e isso é o ponto, não um efeito colateral. Se o colono
está trocando porque desconfia que alguém entrou na conta dele, uma senha nova **sem revogar os
tokens não expulsa ninguém**: o token do Sanctum não expira, e o invasor continua dentro com a chave
antiga. É a lição do D-53 (o logout que não revogava), e o que a redefinição do painel de admin já
fazia. A sessão que faz a troca sobrevive: seria absurdo deslogar quem acabou de se proteger.

⚠️ **Os quatro índices de reputação (§26.2) NÃO se editam, e nunca poderão.** São o histórico do
colono no Ministério. Deixar o dono mexer neles seria deixá-lo apagar as próprias condenações.
Aparecem no perfil porque ele tem direito de os ver.

### 3. As zonas neutras na barra lateral

Elas eram **invisíveis**. Para saber que uma zona sua estava **cercada**, ou que tinha 3.000 unidades
**expostas ao saque**, era preciso abrir o mapa, aproximar, achar a célula e clicar. Uma zona que
exige ação urgente não pode estar a quatro cliques de distância.

Cada linha mostra o que decide se o colono precisa largar o que está fazendo: o **exposto** (o único
número da tela que significa "vá agora" — só o que excede o Depósito é saqueável, D-66), o **cerco**
(nada entra nem sai, e a extração se perde) e a **obra** em curso. Clicar abre a zona.

### O que se aprendeu construindo

> **Duas caches para a mesma tabela é o começo de uma divergência.** O cartão de detalhe tinha uma
> cache de arte própria, separada da que a cena usa. Resultado: a cena mostrava o prédio no hexágono
> e o **cartão não mostrava nada** — e não havia como saber por quê sem ler os dois arquivos. Uma
> fonte só (`game/arte.ts`).

> **Um stream que falha sem tratamento nunca ENCERRA a resposta.** O plugin do Vite que serve
> `/media` fazia `createReadStream(...).pipe(res)` sem ouvir `error`. Um erro de leitura deixaria a
> requisição pendurada para sempre, a rede nunca ficaria ociosa, e o `waitForNetworkIdle` do e2e
> estouraria em 30 s — **com uma mensagem que não fala de imagem nenhuma**.

---

## D-70 — O defensor ganha as duas mãos: reforçar e romper o cerco.
**Data:** 2026-07-13 · **Status:** implementado · **GDD: §27.5 e §28.10**

A guerra do D-66 tinha um buraco de um lado só: **o defensor não podia fazer nada.** O motor já
sabia contar reforços — e a tela **já prometia ao defensor** que ele podia mandá-los. Faltavam a
rota e o botão. Pior: o §28.10 dá ao sitiado uma saída (sair a campo com Sentinelas), e ela não
existia. Um cerco era 48 h de espera até a rendição.

### 1. Reforçar (§27.5)

O §27.5 dimensiona o combate em ~2 h com uma justificativa explícita: *"tempo suficiente para o
defensor receber notificação, recrutar reforços e despachá-los"*. **A tropa em marcha não conta** —
só a que chegou. E o dano é congelado na chegada (arbitragem 8 do D-66), então o que faz um reforço
mudar o resultado é o `recongelar()`: a força de defesa é recalculada, e a mesma batalha que
**tomava** a zona passa a ser **repelida**. Há um teste que afirma exatamente isso, com e sem o
socorro.

⚠️ **Os reforços chegam ANTES de a rodada resolver**, e a ordem no tick é deliberada:
`ChegarReforcos` roda antes de `ResolverCombates`. Se fosse ao contrário, a tropa que chegasse no
mesmo minuto da rodada final chegaria **um instante tarde demais** — e o colono teria feito tudo
certo e perdido assim mesmo.

### 2. Romper o cerco (§28.10)

Na ruptura **quem ataca é o dono da zona**: o sitiado sai a campo. Só ele pode rompê-la, uma de cada
vez, e o socorro fraco morre — o cerco continua.

⚠️ **A zona cercada NÃO recebe reforço.** É o que "cercada" significa: nada entra nem sai, nem
tropa. Por isso a ruptura existe — se dava para reforçar por dentro, o cerco não seria cerco. A tela
oferece **um** botão ou **o outro**, nunca os dois.

### 3. Os dez números saem do SQL

Os parâmetros da guerra (§27.3 os declara *"valores configuráveis"*; o §28.10 manda comparar níveis
e **não publica a conta**) só se mudavam por SQL. Agora há aba no painel. Mesmo gancho do D-60: o
que o GDD manda alguém declarar e não publica é **do operador**.

### O que se aprendeu construindo

> **A ruptura nasceu morta, e o teste foi o único a notar.** O `ResolverCombates` tem uma guarda:
> "se a zona já é do atacante, expire o combate" — ela existe para que um segundo exército enviado
> pelo mesmo atacante não reconquiste a própria zona. Só que **na ruptura o atacante é o dono**, e a
> guarda era verdadeira **sempre**: toda força de socorro expirava no instante em que chegava, antes
> da primeira rodada. Romper um cerco simplesmente não funcionaria, **e o jogo não diria por quê**.

> **Um campo fora do `$fillable` mente com cara de sucesso.** `torre_aviso_minutos_por_nivel` (a
> antecedência com que a Torre de Vigia avisa) não estava lá. A leitura funciona sem `fillable`, e
> **até hoje nada o escrevia** — então ninguém tinha notado. O formulário novo diria "atualizado" e
> **descartaria o valor em silêncio**. Um teste afirma que os dez gravam.

> **`exit 137` não é teste reprovado — é o kernel matando por falta de RAM.** O e2e subia `vite dev`
> (que guarda o grafo de módulos inteiro em memória, com o Phaser dentro) **junto** do Chrome, e num
> servidor de 4 GB isso não cabe. Passou a rodar `build` + `preview`: o build é pesado (~360 MB) mas
> roda **sozinho** e morre, e o preview serve estático por uns 50 MB. Os dois picos deixaram de se
> sobrepor — e de brinde o e2e passou a exercitar **o bundle que de fato vai ao ar**.
>
> ⚠️ Isso exigiu duplicar duas coisas na config do Vite: `server.proxy` **não vale** no preview
> (`preview.proxy` é outra chave), e o plugin que serve `/media` só se registrava no
> `configureServer`. Um proxy ausente no preview **não dá erro**: cada chamada de API cai no
> fallback de SPA e volta como o `index.html`, com status 200.

---

## D-71 — A porta do painel: o que a auditoria não via, e o quebre-o-vidro que não quebrava.
**Data:** 2026-07-13 · **Status:** implementado · **Segurança do painel, não do GDD**

A tarefa era "criar um segundo admin dono", porque um só é ponto único de falha. **Ele já existia** —
a pendência estava velha. Mas conferir o caminho de emergência inteiro abriu três buracos, e o
primeiro deles é do tipo que só se descobre olhando.

### 1. A auditoria estava cega para a porta

O `audit_log` de produção tinha **12 linhas e nenhum login** — nem certo, nem errado — enquanto o
dono usava o painel todo dia. A auditoria do D-61 gravava o login **no `AuthController`**, e quem
volta pelo cookie do *"lembrar de mim"* é reautenticado pelo `SessionGuard` a partir do *recaller* e
**nunca passa pelo controller**.

⚠️ **É o pior estado possível para um log: o silêncio dele parecia dizer "ninguém entrou aqui".** Um
log que registra parte das entradas é mais perigoso do que um que não registra nenhuma, porque quem
o lê acredita nele.

Agora quem audita são os **eventos do `Auth`** (`Login` e `Failed`), que é o único ponto por onde os
dois caminhos passam — o guard. E as entradas passaram a ser **quatro fatos distintos**, porque são
distintos para quem investiga: `login.ok` (digitou a senha), **`login.lembrado`** (um navegador que
já tinha a chave voltou), `login.falhou` e `login.bloqueado`.

> **O ouvinte é registrado À MÃO**, e não pela descoberta automática de `app/Listeners`. Um ouvinte
> que deixa de se registrar **não dá erro — ele simplesmente não grava nada**, que é exatamente o bug
> que este decisório fecha. A auditoria não pode depender de mágica silenciosa.

### 2. A porta aceitava tentativas ilimitadas

`POST /admin/login` **não tinha throttle nenhum**. É a mesma porta que realoca colônia e distribui o
Tesouro. Agora: **5 por minuto por e-mail+IP**, e **20 por minuto por IP**.

⚠️ **As duas chaves são necessárias.** Só o e-mail, e bastaria variar o e-mail a cada tentativa para
nunca esbarrar no limite. E **o IP entra na chave da conta de propósito**: se ela fosse só o e-mail,
qualquer um do outro lado do mundo trancaria o dono para fora martelando o e-mail dele — **a defesa
viraria a arma**. Há um teste que afirma que a senha certa, vinda de outro IP, entra mesmo com o
balde do atacante cheio.

### 3. O quebre-o-vidro não quebrava vidro nenhum

O `fertways:admin` é o *break-glass*: o painel gere admins, mas **só se houver um dono capaz de
entrar nele**. Perdidas as senhas dos donos, é o que resta. E ele era de **antes** de os papéis
existirem (D-61):

- **`--criar` nunca escrevia o papel**, e o default da coluna é `operador`. **Não havia como criar
  nem promover um dono pela CLI.** A única saída real era SQL cru.
- **`--remover` chamava `delete()` direto no modelo, por fora do `Domain\Admin\Contas`.** A trava que
  impede apagar o último dono — "o painel ficaria inacessível para sempre" — estava escrita, testada,
  e **a CLI passava ao largo dela**.
- `--listar` não mostrava papel nem se a conta estava desativada.

Agora tudo passa pelo `Contas`, cada ato deixa linha na auditoria, e o `--listar` **avisa quando há
um dono só** — que era, literalmente, a pendência que abriu esta sessão.

### O que se aprendeu construindo

> **Uma trava só protege o caminho que a consulta.** O `Contas` foi escrito no D-61 para que ninguém
> jamais apagasse o último dono, e o teste dele passava. Só que a trava mora no domínio, e a CLI
> falava direto com o `Model` — **a porta dos fundos não sabia da fechadura da frente**. Não adianta
> a regra estar certa se existe um segundo caminho até o mesmo estrago.

> **O verde do `artisan test` não prova o throttle.** Os testes rodam com cache em memória; a
> produção usa `CACHE_STORE=database`. Se as tabelas `cache`/`cache_locks` não existissem lá, o
> `RateLimiter` explodiria **na porta do painel** e trancaria todo mundo para fora — com a suíte
> verde. Conferidas antes do deploy, e o freio foi exercitado contra o MariaDB de dev. É o D-27
> outra vez, por outro caminho.

---

## D-72 — As 28 imagens, enfim olhadas: 12 evidentes, 7 escolhas, 9 sem lar.
**Data:** 2026-07-13 · **Status:** os 12 evidentes decididos pelo usuário; os 7 ambíguos aguardam ele · **Não há GDD sobre isto**

O D-68 vinculou 18 imagens e deixou 28 sem vínculo com a nota "eu não sei o que são, e chutar poria
arte errada num prédio". Neste decisório elas foram **olhadas uma a uma** — na tela, com a proposta
ao lado — e o número 28 se abriu em três grupos que não têm nada a ver um com o outro:

**12 evidentes, aplicadas** (o usuário mandou publicar só as com certeza). O critério continuou o do
D-68 — a imagem tem de provar, cruzada com **o que a construção FAZ no jogo**: a `forja-titan` é uma
fundição com metal derretido, e a Oficina é quem produz as Ligas; a `torre-trafego-zenite` é uma
torre de radares, e ver o ataque chegando é o ofício da Torre de Vigia (o aviso do D-70); o
`forum-concordia` tem a balança da justiça no centro — é o Ministério das Reputações. Quatro
ministérios da Capital, sete construções de colônia e uma da zona.

⚠️ **Duas cruzam de categoria, e isso é informação, não erro:** a `extratora-rubicon` veio na pasta
de zonas mas é uma sonda de perfuração (Mina Local), e a `doca-meridiana` veio em mercado-e-comércio
mas é o pátio de docas da Central de Transportes. **A pasta do artista não manda no jogo.**

**7 têm duas leituras** e ficam para o operador escolher no painel, vendo a miniatura: `torre-axiom`
(área Norte ou Slot 1), `aquifero-talassa` (Refinaria Química ou Destilaria), `bastiao-vanguarda`
(Quartel ou Torre de Defesa), `estufa-lumen` (Laboratório ou trocar a Fazenda), `centro-cerco-kraken`
(Abrigo de Robôs ou Torre de Defesa), `terminal-aduaneiro-vetor` (Estacionamento da Zona ou esperar o
Espaçoporto), `camara-escrow-prisma` (guardar ou trocar a área Leste).

**9 não têm lar no jogo:** as oito seções da Endurance (a área Oeste já mostra o casco inteiro; as
seções individuais não são vinculáveis a nada) e o `cargueiro-zenith` — que é o Cargueiro
Interplanetário de um Espaçoporto que não existe. A arte espera o navio, não o contrário.

**E aplicar tudo não fecha o assunto:** mesmo com as recomendações dos 7, sobrariam ~10 entidades
sem NENHUMA imagem candidata (Muralha, Depósito, Refinaria de Campo e Cemitério da zona; Infiltrador
e Predador; e o que as escolhas não levarem). É lista de encomenda ao artista, não de vínculo.

### O que se aprendeu construindo

> **Um vínculo com chave errada não daria erro nenhum.** O comando criaria a linha, diria
> "vinculada" — e nenhuma cena jamais perguntaria por aquela chave: a arte sumiria no banco e o
> hexágono continuaria na tela. Um teste novo percorre a tabela `EVIDENTES` e exige que cada chave
> exista em `Vinculaveis::todas()`. É o silêncio do `$fillable` do D-70, noutro lugar.

---

## D-73 — O Furgão ganha âncora: a lavagem de Fert$ pelo mercado de usados fecha.
**Data:** 2026-07-13 · **Status:** decidido pelo usuário (revisão do aditivo 14 do D-52) · **GDD §16.4**

O aditivo 14 tinha deixado o Furgão **sem teto de revenda de propósito**: o teto é `preço de fábrica
× conservação`, e o Furgão não tem preço de fábrica — o Ministério não o vende. O risco ficou
registrado com nome e endereço: *"duas contas do mesmo jogador podem usar isso para lavar Fert$"*,
um Furgão sucateado anunciado por 5.000 Fert$ movendo dinheiro limpo pelo escrow, sem carga e sem
tributo. *"Se o multi-conta virar problema, é aqui que ele vai aparecer primeiro, e a cura é dar um
teto ao Furgão."* Era o único item da lista de frentes que era uma **falha sangrando**, não uma
ausência — e o usuário reviu a arbitragem.

**A âncora: 60 Fert$, parâmetro do operador.** A proporção é a da capacidade — o Furgão carrega
6.000, 1/5 do Caminhão de 300 Fert$. **Referência, não preço de venda:** o Ministério continua não
vendendo Furgão; o número existe só para o teto se calcular dele. Vive em `transport_settings`, no
painel dos Transportes (o padrão da casa: D-60, D-66), e muda sem deploy.

**A imposição já existia e não custou nada:** o `anunciar()` do mercado de usados barra preço acima
do teto desde o D-60 — só que `tetoDeRevendaMicro()` devolvia `null` para o Furgão. A mudança real
é uma linha num `match`. Produção tinha **zero anúncios abertos** no deploy: ninguém ficou com
anúncio órfão acima do teto novo.

⚠️ **O painel recusa referência zero, e o `min:1` é regra, não pedantismo.** Um zero digitado faria
teto 0 e **recusaria todo anúncio de Furgão** — pareceria "voltar ao sem-teto do aditivo 14" e seria
o contrário: o Furgão sumiria do mercado de usados. Tirar o veículo do mercado é decisão para se
tomar de frente, não por um dedo escorregado.

### O que se aprendeu construindo

> **Um teste que prende uma arbitragem morre com ela — e é assim que se sabe que ela mudou.**
> `test_o_furgao_nao_tem_teto_de_revenda` afirmava o aditivo 14 com o comentário "risco aceito de
> olhos abertos". Virou `test_o_furgao_agora_tem_teto_e_ele_e_do_operador`, e o cenário exato da
> lavagem (a carcaça por 5.000) virou o teste de que ela está fechada.

> **A lição do `WarSetting`, aplicada ANTES de doer:** o `TransportSetting::singleton()` tinha o
> mesmo `firstOrCreate([])` cru que no D-70 fez a primeira leitura devolver `null` em tudo. Com a
> coluna nova, a primeira chamada num banco recém-criado leria um Furgão sem âncora. Corrigido no
> mesmo padrão — relê depois de criar — junto com a coluna, e não depois do primeiro susto.

---

## D-74 — O Drone ganha ofício: a névoa entra no interior das zonas alheias.
**Data:** 2026-07-13 · **Status:** arbitrado pelo usuário (4 lacunas do D-52) · **GDD §16.1, §21.4, §4.3 v3.4**

O Drone existia no GDD para "revelar mapa ao redor do slot e zonas neutras" (§16.1) — e o D-37 abriu
o diretório **sem névoa**, deixando-o sem o que revelar. O D-37 anotou: *"se um dia a névoa entrar,
este é o ponto"*. Ela entrou aqui, pelo menor lado possível, e foi a **primeira arbitragem** desta
leva:

### 1. A névoa (arbitragem 1): só o INTERIOR de zona alheia

O mapa continua mostrando toda zona — posição, mineral, dono, nível, status (e os deriváveis do
nível: capacidade de depósito, extração/h — escondê-los seria teatro, qualquer um os calcula). O que
virou segredo: **a guarnição e o depósito de zona que não é sua**. Vêm `null`, e null não é zero:
zero é um fato ("está indefesa"); null é a honestidade de não saber.

**Zona livre não tem interior a esconder** — guarnição 0, depósito 0, vê-se tudo. O "revela zonas
neutras ANTES de ocupação" do §16.1 sai de graça: o segredo só nasce quando alguém toma a zona.

⚠️ **Isto muda a guerra:** até o D-74, um atacante via a guarnição de qualquer zona de graça. Agora
atacar às cegas continua permitido — só ficou imprudente. O Drone é o olheiro que o §27 merecia.

### 2. O que o GDD publica, e ninguém arbitrou

Bateria **24 36 54 81 122 h** por nível (§21.4); custo **50/15/4 → 371/111/30** (Componentes/
Compostos/Metal, §4.3 do v3.4 — a curva 1,65× vence, regra do D-47); os **dois modos** ("ida simples
ou ida e volta, configurável por missão"); recarga "automática, no Quartel"; tem placa e não
deprecia (§16).

### 3. As outras três arbitragens

- **Velocidade: 8 slots/min** — o dobro do Furgão (4), abaixo da Nave (10). Fixa por nível: o nível
  compra bateria e raio, não velocidade.
- **Raio de revelação: 6 slots × 1,5 por nível** (a curva do §19.1, para baixo): 6, 9, 13, 20, 30.
  A missão mira uma ZONA, e o raio revela as vizinhas.
- **Persistência: os dois modos do §21.4, sem número inventado.** Ida e volta = **foto datada** — o
  que se viu fica para sempre, com hora ("vista há 3 h"); informação que envelhece é informação
  honesta. Ida simples = **vigilância**: o Drone fica sobrevoando, transmite AO VIVO, e **a bateria
  publicada É a persistência** — acabou, fotografa uma última vez (vigilância que termina vira foto,
  não esquecimento) e volta sozinho.
- **Fábrica: a Oficina** — o §21.4 diz que o Quartel só ARMAZENA e recarrega, e o custo é em
  recursos, o que é fabricação, não compra. Nível da Oficina = teto do nível do Drone (o desenho do
  Quartel/D-66 e da Central/D-60). A tela do Quartel é o hangar; a missão parte do mapa.

### As leituras registradas (ninguém as decidiu além de mim; estão aqui para serem revistas)

- **A recarga é instantânea ao voltar.** O §21.4 diz "automática" e não publica taxa. A bateria só
  existe como duração da vigília; não inventei um relógio que o documento não tem.
- **A missão não debita energia da colônia**: bateria própria é o combustível dele (o Furgão e o
  Caminhão pagam kWh porque o §21.2/§21.3 os cobra; o §21.4 não cobra o Drone).
- **O Drone está FORA do mercado de usados**, apesar do "vendável" do §16.1: sem preço de fábrica
  nem referência, ele seria a reabertura da lavagem que o D-73 fechou. Quando ganhar âncora, entra.

### O que se aprendeu construindo

> **A missão não ganhou tabela própria, e o `status` ENUM não foi tocado.** As colunas de viagem do
> veículo já contam a história (`leg` ida → vigia → volta, `trip_purpose` = o modo, `destination_id`
> = a zona), e `vehicles.status` é ENUM no MariaDB — `em_rota` serve ao voo inteiro. Uma segunda
> máquina de viagem seria o segundo lugar onde ela quebra. Só o `ConcluirTrechos` precisou aprender
> a IGNORAR drones: sem o filtro, a chegada de um cairia no fluxo de entrega de carga.

> **`exit 137` de novo — e desta vez a cura foi a dieta do Chrome.** O e2e morreu de OOM duas vezes
> na mesma tarde (4 GB, sem swap, MariaDB de produção na mesma máquina). `--js-flags=
> --max-old-space-size=256`, `--renderer-process-limit=2` e `--disable-gpu` no puppeteer bastaram.
> ⚠️ **O servidor continua sem swap nenhum**: num pico, o OOM killer escolhe uma vítima — e a maior
> da máquina é o `mariadbd`. Um swapfile de 2 GB é a proteção barata; fica anotado como recomendação.

> **Um e2e que corre atrás de um recibo lê o número velho.** A tela mostra "comprado!" ANTES de
> recarregar os contadores (o `agir` põe o recibo e então refaz o fetch). O teste lia a prateleira
> nesse vão e via 2 onde já era 1 — no servidor, provado por teste PHP, o número estava certo. O
> e2e agora ESPERA o contador mudar (`waitForFunction`) em vez de lê-lo no impulso. E dois testes da
> Capital ainda afirmavam o Furgão SEM teto (o aditivo 14 morto no D-73) — o e2e não tinha rodado no
> D-73, e a dívida apareceu no D-74.

---

## D-75 — O Marco sai do congelador: XP por atos, e os primeiros gates do §05.
**Data:** 2026-07-13 · **Status:** arbitrado pelo usuário (4 decisões) · **GDD §03, §05, §06**

O GDD nomeia os oito marcos (1 Sobrevivente … 100 Lenda de Fertways), publica os desbloqueios de
cada um (§03 e §05), manda as missões pagarem "XP" (§06) — e **nunca publica a fórmula**. O
`colonies.milestone` estava congelado desde o D-38, e os gates do §05, suspensos desde o D-52
("quando o Marco existir, o gate volta"). Ele existe agora. As quatro arbitragens:

**1. XP por atos, em ledger próprio** (`xp_entries`, append-only — XP não nasce sem história, a
mesma regra do ledger de recursos). As fontes vivas: **obra concluída** (por nível; a fundação vale
os 5 das essenciais), **zona ocupada**, **combate vencido** (conquista, defesa que segura, cerco
rompido — sabotagem detectada não conta: rotina da Torre não é batalha), **acordo executado** (os
dois lados) e **execução no Mercado Central** (os dois lados). Quando as missões do §06 nascerem,
pagam por aqui.

⚠️ **Acordo e Mercado herdam o piso do D-43** (500 Fert$): abaixo dele, nada de XP — senão duas
contas fariam volume de mentira a 1 unidade por vez. O anti-farm da reputação e o do Marco são o
mesmo, de propósito.

**2. A curva: 50 × N² de XP acumulado** — marco 5 = 1.250, 10 = 5.000, 20 = 20.000, 100 = 500.000.
Quadrática porque as curvas publicadas do GDD (1,5×/1,65×) são para 5 níveis e explodem em 100. O
começo anda rápido (retenção, o título do §05); a Lenda é projeto de temporada. **A curva é
constante de código, não painel**: mudá-la reescala o marco de todo mundo — arbitragem, não
balanceamento.

**3. Posse preservada + XP retroativo.** O gate barra a **aquisição nova**, nunca o que já se tem:
zona ocupada antes do D-75 continua do dono; ocupar OUTRA exige marco 20. E `fertways:marco
--aplicar` credita o retroativo lido do histórico (níveis de pé, zonas, vitórias em `combats`,
acordos executados, vendas em `tax_events`) — quem jogou muito acorda no marco certo. Idempotente
por reescrita (linhas `retro:*`), e **desconta o que o ledger vivo já pagou**.

**4. Os valores por ato são do operador**, no painel (aba Operação): obra 100, zona 500, combate
400, acordo 150, mercado 50. Zero desliga a fonte. Mudanças valem para atos novos — o ledger nunca
é reescrito.

### Os gates vivos, e os que ficam esperando

- **Marco 10 (Pioneiro):** fabricar **Drone nível 2+**. Pela precedência (§05 > §03 na mesma
  parte), o Marco 10 destrava o drone *nível 2* — o nível 1 nunca teve gate, e o D-74 não
  contradisse nada.
- **Marco 20 (Desbravador):** **ocupar zona neutra.**
- ⚠️ **O Mercado Central NÃO tem gate, e isso contraria o §05 de propósito** (que o põe no marco 5).
  O §03 promete ao recém-chegado "a compra do primeiro lote de Ligas Metálicas no Mercado Central
  antes de existir produção própria" — os 50 Fert$ iniciais existem para isso. Contradição
  consciente: **não a "conserte"**.
- Cargueiro, mineração profunda, federação, voto, peças de reputação: ganham gate quando os
  sistemas existirem.

`colonies.milestone` (o varchar do D-38) **fica intocado, dormindo** — o Marco deriva de
`colonies.xp` pela curva. O Perfil mostra número, título e o XP até o próximo; o payload da colônia
também. O Diário do Colono (§03) continua não existindo; quando existir, os marcos são entradas.

### O que se aprendeu construindo

> **O `$fillable` mordeu o próprio autor no mesmo dia.** `xp` não é fillable no `Colony` — de
> propósito, só o `ConcederXp` escreve — e o meu próprio teste fez `update(['xp' => 20000])` e viu
> o valor ser descartado em silêncio. A mensagem de erro do gate ("faltam 19.500") foi o que
> entregou. É a terceira vez que este silêncio aparece (D-70, D-73); a defesa continua a mesma:
> testes que afirmam o efeito, não o gesto.

> **O dobro-pagamento do retroativo apareceu num teste antes de aparecer em produção.** Colônia
> fundada DEPOIS do D-75 já tem os 5 níveis no ledger vivo — e o retro, que conta níveis de pé, os
> contaria de novo. O retro agora desconta, ação por ação, o que o ledger vivo já pagou. Aproximado
> se o operador mudar valores no meio; aproximar para MENOS é o lado certo do erro.

### Aditivo (2026-07-13, mesma noite) — o painel enxerga o que o dia criou

Auditoria do usuário: "essas modificações refletem no admin já?" Três buracos, fechados:

- **A placa do Drone nasceria `FW-…-X`** — o X é o fallback de "tipo desconhecido" no mapa de
  iniciais, e placa é para sempre. Nenhum drone existia em produção ainda; agora é **D**, antes do
  primeiro. ⚠️ A lição: **um fallback silencioso num registro permanente é uma armadilha armada** —
  o teste agora afirma o sufixo.
- **A aba Guerra ganhou "Drones de Exploração"**: o Drone é veículo, não `unit`, e a guerra de
  informação do D-74 era invisível ao operador — quem sobrevoa o quê, e quantas fotos já se tiraram.
- **Jogadores ganhou a coluna Marco** (e a ficha, o XP com o rumo ao próximo): o operador via tudo
  de um colono menos o marco dele.

---

## D-76 — O serviço logístico público (§07): a Garagem do Governo.
**Data:** 2026-07-13 · **Status:** arbitrado pelo usuário · **GDD §07 (uma frase operativa)**

O §07 publica UMA frase sobre o assunto — "o comprador agenda retirada com veículo próprio **ou
paga serviço logístico público**; a carga continua na doca até a retirada" — e mais duas âncoras:
a retirada não gera novo imposto, e "serviços públicos" estão na lista de sumidouros de Fert$.
Preço, prazo, capacidade, veículo: nada. As arbitragens do usuário:

**1. A Garagem do Governo — frota REAL, não fantasma.** 10 caminhões iniciais (o `GaragemSeeder`,
rodado à mão em produção como o NeutralZoneSeeder), expansíveis pelo painel ("Encomendar +1")
conforme a demanda. Um caminhão de garagem é um `vehicles` com dono nulo, `status` ocioso e `local`
capital — **distinto da prateleira de VENDA** (`status` estoque): vender jamais esvazia a garagem,
e a linha de montagem (`fabricando`) não engorda a frota. Caminhão ocupado = frete recusado, e é
essa escassez que impede o preço amável de aposentar a frota própria.

**2. O preço: 1 F$ + 0,02 F$/slot** (bandeirada + distância até a colônia), por viagem de até
30.000 unidades — quase subsídio, de propósito: o governo empurra o comércio. Os dois números são
do OPERADOR (painel dos Transportes) e a receita vai ao **Tesouro**, como as taxas.

**3. Escopo: só a doca do Mercado Central** → a própria colônia. Zona neutra continua exigindo
veículo próprio — é o que o §07 publica, e território conquistado tem logística por conta do dono.

### As regras que ninguém precisou arbitrar (e por quê)

- ⚠️ **A entrega paga tributo na chegada.** O §07 diz que a retirada não gera novo imposto — mas
  essa contradição JÁ FOI arbitrada no D-32 (tributo em toda entrega física, inclusive a retirada
  com veículo próprio), e um frete isento seria **rota de fuga**: bastaria "fretar" em vez de
  buscar. Mesma regra nos dois caminhos, fixada em teste.
- **O frete não desgasta a frota pública** (`trip_purpose = frete` entra nas viagens isentas do
  §16.4): sem isso, os dez caminhões exigiriam uma manutenção estatal que ninguém opera e morreriam
  aos poucos, em silêncio. Leitura consciente; o §16.4 fala da frota do colono.
- **O caminhão trava primeiro, o Fert$ depois, a carga por último** — tudo numa transação: a recusa
  de qualquer passo desfaz os anteriores, e dois fretes disputando o último caminhão são
  serializados pelo lock (o segundo ouve "garagem vazia").
- **A máquina de tributo não foi duplicada:** o frete é um `trip_purpose` que o próprio
  `ConcluirTrechos` conhece — o ramo entra ANTES do `$origem`, porque caminhão do governo não tem
  colônia de origem. Reuso do `entregar()` blindado contra dupla incidência.

Com o D-76, **todas as frentes do GDD que dependiam de arbitragem estão fechadas**. O que resta no
RETOMAR são escolhas de operação (as 7 imagens ambíguas) e encomenda de arte.

---

## D-77 — O rádio do planeta: o Sistema de Mensagens do §10.
**Data:** 2026-07-13 · **Status:** arbitrado pelo usuário · **GDD §10 (capítulo inteiro), §03, §08, seção 15**

O §10 publica quase tudo: os 5 canais (Global, Região, Federação, Privada, Vizinhança), a moderação
(filtro de termos nos públicos; federação e privadas só por denúncia; silêncio temporário
configurável que afeta a Conduta Social), o armazenamento (privadas acessíveis ao dono, histórico
como evidência de denúncia) e a retenção (global/região 180 dias, vizinhança 90, privadas
indefinido, "todo acesso interno a mensagens reportadas é registrado"). As quatro arbitragens:

**1. Polling, não Reverb.** O GDD nomeia Redis + Laravel Reverb; o servidor tem 4 GB divididos com
o MariaDB de produção, e um daemon de websocket é memória que o jogo não tem. A tela consulta
`?after=<id>` a cada 5 s **enquanto aberta** — fechada, o chat não custa um request. Contradição
consciente com a stack sugerida; o Reverb entra quando o servidor crescer.

**2. As 5 regiões = 4 quadrantes + Núcleo.** O §10.1 publica "5 canais regionais" e cala o mapa. A
geografia que o planeta já tem soma exatamente 5: cada quadrante herda o nome do distrito que
contém (os distritos moram nos cantos, D-52), e o Núcleo é o disco central — raio 10 da Capital,
onde vivem os founders. A região é da POSIÇÃO da colônia: realocou, mudou de sala.

**3. O filtro BLOQUEIA o envio e avisa.** Nada de asteriscos (mensagem censurada pela metade ainda
comunica) nem publicar-e-sinalizar (filtro que não barra é termômetro). A reincidência fica contada
(`chat_filter_hits`) para o moderador ver padrão. A lista de termos é do operador (aba Chat) — e o
§03 promete que o nickname passa "pelo mesmo filtro": agora passa, na criação e na troca.

**4. Silêncio só por pena humana.** A máquina conta; a pessoa pune. É a MESMA pena `silencio` do
§9.4 que o Ministério aplica desde o D-44 — inerte até hoje ("o silêncio precisa de chat") — mais
um botão na ficha do jogador. **Cala a praça, não a boca**: os públicos fecham, a privada continua
(§10.2 é textual: "remove acesso aos chats públicos").

### O que mais ficou fixado

- **A vizinhança é um RAIO, não uma sala** (§10.1: "escopo limitado por distância"): a mensagem
  carrega a posição da colônia do autor NO ENVIO, e cada leitor vê o que foi dito a até N slots da
  colônia DELE (N do operador, padrão 10). Realocar não reescreve onde a voz soou.
- **Bloqueio é não ouvir, não é calar**: quem eu bloqueio some da MINHA tela em todos os canais e
  não me manda privada — mas o resto do planeta continua ouvindo-o (MVP social, seção 15).
- **Espiar privada é possível e IMPOSSÍVEL de fazer em silêncio**: o formulário do painel registra
  a auditoria (`chat.acesso_privado`, com motivo) ANTES de abrir a conversa — a página sem os
  parâmetros do redirect não mostra privada nenhuma. É o §10.3 por construção.
- **A retenção publicada roda no tick** (180/90 dias; privadas ficam). ⚠️ Quando a denúncia do
  Ministério aprender a anexar mensagem como evidência, a purga terá de desviar de evidência de
  caso vivo (+90 dias) — hoje não há vínculo; registrado.
- **A notificação ao denunciado** ("é notificado que o histórico pode ser consultado", §10.3)
  espera um sistema de notificações que não existe. Registrado.
- **O canal de Federação não nasceu**: federações não existem (D-44). A coluna já o comporta.

### Aditivo (2026-07-13, antes do deploy) — o chat que AVISA

Auditoria do usuário: "foi feito alguma forma do colono saber que chegou mensagem privada, e alerta
de quando ele é citado?" Não tinha sido — o D-77 nasceu mudo, e um chat onde ninguém sabe que foi
chamado é meio chat. Fechado antes do primeiro deploy:

- **Não-lidas**: `chat_reads` guarda até onde cada um leu cada conversa (coluna `peer_id` INTEIRA,
  de propósito: um contexto textual exigiria concatenar em SQL, e o CONCAT do MariaDB não é o do
  SQLite dos testes). O não-lido é DERIVADO, nunca flag por mensagem; ler move a marca, e a marca
  **só anda para a frente** — o polling que relê página velha não reacende o selo.
- **Citações**: `@nickname` num canal público registra menção (até 5 por mensagem — mais é
  megafone). O citado ganha o selo e vê a mensagem destacada; **abrir o canal apaga**. Quem o
  citado bloqueou não gera menção: bloquear é não ouvir, inclusive quando chamam o seu nome.
- **O selo no HUD**: o botão Chat mostra privadas não lidas + menções, com um poll de 30 s que
  roda mesmo com o painel fechado — duas contagens indexadas, nada mais. O "fechado não custa um
  request" do polling virou "custa dois números a cada 30 s", e é o preço de saber que chamaram.

### O que se aprendeu construindo

> **A migration do aviso QUEBROU o deploy — e o dev já tinha quebrado igual, em silêncio.** Na
> `chat_mentions`, o `seen_at` anulável vinha antes do `created_at`: no MariaDB o default automático
> do primeiro TIMESTAMP já estava gasto, e o segundo, sem default explícito, vira `0000-00-00` — que
> o sql_mode proíbe (`1067`). O SQLite dos testes engole. **Pior:** o migrate do dev falhou HORAS
> antes, e a saída estava cortada com `tail -1` — o verde que eu vi era só a suíte SQLite. É
> literalmente a lição gravada na memória do projeto ("o verde do artisan test não prova DDL"), e
> ela mordeu mesmo assim. Regra nova: **migrate no dev sempre com a saída inteira e conferência das
> tabelas** — e o DDL parcial (MariaDB não tem DDL transacional) deixa tabela órfã para trás:
> `chat_reads` ficou criada com a migration não registrada, nas duas pontas.

> **O silêncio do D-44 ligou sem uma linha nova no Ministério.** A pena já era gravada com tipo e
> prazo (`Punishment`, escopo `vigente()`) — só nunca houve porta em que bater. O chat perguntou
> "há silêncio vigente?" e três anos de arquitetura respondida em uma consulta. Guardar estado
> completo de sistemas futuros (D-44: "passa a morder sozinho no dia em que existirem") pagou.

---

## D-78 — As Missões do §06: o jogo que chama de volta.
**Data:** 2026-07-13 · **Status:** arbitrado pelo usuário · **GDD §06, §03**

O §06 publica os ciclos e cala os valores. As três arbitragens (2026-07-13):

**1. Escopo: Tutoria + Diárias + Semanais.** As 5 da tutoria (dias 1–3, entregues na fundação), as
3 diárias do pool de 30+ (o catálogo nasceu com 33) com a **1 rejeição publicada**, e a semanal na
janela textual (qua 07h → ter 23h59). As missões de guerra viraram tipos do pool; Narrativa,
Federação, Evento e Conquistas esperam os seus sistemas.

**2. Recompensas GENEROSAS** (2× a proposta modesta): diária ~6 F$ OU ~300 XP OU recursos (cada
molde paga UMA classe — é o "Fert$, recursos ou XP" do §06); semanal ~40 F$ + 1.000 XP; tutoria
~30 F$ + recursos no total. ⚠️ **Recompensa de missão é EMISSÃO** (o §06 a lista entre as entradas
de Fert$, como o salário do conciliador) — se o Fert$ inflar, o torniquete é o catálogo: o painel
(Operação) desliga moldes sem deploy, e o seeder reajusta valores por chave (`updateOrCreate`).

**3. A tutoria RECOMPENSA e não trava.** O §03 diz "subsídio mediante conclusão da tutoria"; o
usuário manteve o subsídio automático — **contradição consciente, não a "conserte"**. O stub do
D-18 morreu como stub e renasceu como decisão: o comentário no `CreateColony` agora cita esta.

### O desenho

- **A mão nasce LAZY**: as diárias são sorteadas no primeiro pedido da janela (quem não abre a tela
  não ganha linha no banco), sem repetir molde na mesma janela; a rejeição repõe do pool. O "dia de
  missão" corre de **07h a 07h** — a régua veio da semanal publicada (leitura registrada).
- **Concluir PAGA na hora**, sem botão de resgate: Fert$/recursos com ledger (`recompensa_missao`),
  XP pelo ledger do Marco com o valor DO MOLDE (`ConcederXp::direto` — o catálogo manda, não a
  tabela de atos do D-75).
- **13 ações escutadas** pelos mesmos ganchos do XP mais oito novos: obra, zona, combate, acordo,
  mercado (com o MESMO piso anti-farm do D-43), ordem colocada, despacho (no `emRota`, o ponto
  único por onde toda viagem nasce), fabricar unidade, missão de drone, nióbio, chat público,
  frete público e manutenção.
- **Tela própria** (botão Missões no HUD): barras de progresso, prêmio, "trocar" na diária.

### Aditivo (2026-07-14, antes do deploy) — o catálogo vira CRUD

O painel só ligava/desligava moldes. O usuário pediu: *"quero que essas missões sejam
gerenciáveis no backend"*. Ganhou **aba própria** (Missões, ao lado de Chat e Guerra) — o
formulário de criar/editar é grande demais para um card da Operação.

- **`App\Domain\Missoes\Acoes::TODAS`**: a lista canônica das 13 ações que os ganchos do jogo de
  fato disparam. É o que o `<select acao>` do formulário oferece — **não dá para digitar uma ação
  errada**, só escolher uma que existe. Um teste percorre o catálogo do seeder contra esta lista;
  se algum molde usar uma ação fora dela, o teste denuncia a missão impossível (0/N para sempre,
  em silêncio) antes de chegar a produção — a mesma classe de buraco do vínculo de imagem com
  chave errada (D-72).
- **O recurso da recompensa é conferido contra `resource_types` real.** Sem isto, `liga_metalicas`
  (sem o "s") criaria uma missão que paga um recurso inexistente — `Progresso::pagar()` faria um
  `increment` que não erra e não entrega nada, silenciosamente.
- ⚠️ **Editar `ação`/`meta` só vale para o PRÓXIMO sorteio; editar o PRÊMIO vale também para quem
  já tem a missão na mão.** A razão é o desenho: `mission_assignments` copia `acao`/`meta` do
  molde no instante do sorteio (mudar o molde depois não pode fazer o progresso de uma colônia
  pular de meta no meio do dia), mas a recompensa é lida do molde AO VIVO em `Progresso::pagar()`
  — de propósito, porque é o torniquete contra a inflação do §06 (D-78, item 2), e um torniquete
  que só aperta amanhã não serve para hoje.
- **Apagar só é permitido para um molde NUNCA sorteado** (`mission_assignments` vazio) — a FK é
  `cascadeOnDelete`, e apagar um molde com histórico destruiria o rastro de uma recompensa que já
  saiu do Tesouro. Para um molde usado, o painel só oferece desligar.

### O que se aprendeu construindo

> **O gancho funcionou "bem demais" no primeiro teste**: o despacho do cenário completou também a
> missão de tutoria da colônia recém-fundada, e a asserção de valores exatos quebrou. Não era bug —
> era o sistema inteiro ligado. Testes de valor exato agora limpam a mão de missões antes; e ficou
> a prova viva de que fundar uma colônia já entrega a tutoria funcionando.

## D-79 — As três últimas do §17.4 saem da geladeira, mesmo sem função.
**Data:** 2026-07-14 · **Status:** arbitrado pelo usuário · **GDD: §17.4 descreve tudo; cala o custo**

O D-67 tinha deixado **Estrutura de Extração**, **Central de Comunicação** e **Plataforma de Pouso**
(da zona) FORA de escopo — nenhuma tinha função possível, porque o que o GDD promete para elas
depende de um sistema que não existe:

| Estrutura | O que o GDD promete (§17.4) | Por que ficou de fora no D-67 |
|---|---|---|
| Estrutura de Extração | *"Varia conforme o tipo de recurso da zona: perfuratriz para minerais, escavadeira para cristais."* | A zona já extrai sem ela desde a Fatia 1 (D-52) — travar a extração a uma ferramenta agora puniria as zonas que já rendem |
| Central de Comunicação | *"Permite que membros da federação vejam o status da zona em tempo real e recebam alertas de ataque mesmo sem abrir o slot principal."* | Só serve à Federação, que não existe (mesma inércia do D-44) |
| Plataforma de Pouso (zona) | *"Permite o pouso de Naves de Transporte Planetária para retirada direta de robôs e mercadorias da zona, sem depender de via terrestre hostil."* | Só serve à Nave de Transporte Planetária, que é uma fatia inteira (§17.5) e não existe no jogo |

**O usuário reabriu a decisão de propósito.** Ao retomar a sessão, ele escolheu custear as três mesmo
assim — **inertes**, exatamente como o Cemitério de Robôs já era: ergue-se por gosto, sem função
mecânica, até o dia em que o sistema dependente (Federação, Nave de Transporte Planetária) exista. Não
é lacuna que se fechou por função — é a decisão de que o colono pode gastar recurso em algo que hoje
não faz nada, se quiser.

⚠️ **Colisão de nome.** A "Plataforma de Pouso" já existe como construção do slot principal da colônia
(hangar, com custo publicado no §4.2/v3.4: 120 Ligas + 60 Componentes + 25 Energia + 3 Gelo de Metano
no nível 1), já em `building_specs.json` com o slug `plataforma_de_pouso`. A da zona é uma entidade
diferente — mesmo nome no GDD, coisas distintas no jogo. Ganhou o slug `plataforma_de_pouso_da_zona`,
no mesmo padrão de `estacionamento_da_zona`.

### Custo e tempo — mesma curva do D-52/D-66/D-67

Nenhum número vem do GDD (ele não publica nada para as três). A base de cada uma foi arbitrada por
analogia às zonas já custeadas — mais barata que as estruturas funcionais (Refinaria, Estacionamento),
mais cara que o Cemitério puro (que não tem sequer Ligas), porque estas três têm ao menos um gesto de
função futura, ainda que hoje inertes. Os níveis 2–5 saem sozinhos da curva 1,65× de custo (half-up) e
1,50× de tempo (half-even, em minutos) — a mesma fórmula do D-52 (`docs/decisoes.md` linha 33-45),
calculada direto da base, sem compor arredondamento de nível em nível:

| Estrutura | Custo (nível 1) | Tempo |
|---|---|---|
| Estrutura de Extração | 250 Metal Bruto + 80 Ligas | 3 h |
| Central de Comunicação | 200 Metal Bruto + 100 Ligas + 40 Componentes | 5 h |
| Plataforma de Pouso (zona) | 350 Metal Bruto + 150 Ligas + 30 Componentes | 6 h |

Todas continuam **inertes** — `Estruturas::TABELA[...]['inerte'] = true`, como o Cemitério. `Estruturas::
AUSENTES` fica **vazio**: não sobra nada do §17.4 marcado como buraco. As 12 estruturas de zona (Posto de
Comando + Depósito + as 4 de defesa do D-66 + as 6 do D-67/D-79) têm custo e função declarados — função
real ou "nenhuma, de propósito e por decisão do usuário", nunca silêncio.

### O que mudou no código

- `Estruturas.php`: as três saem de `AUSENTES` e entram em `TABELA`, `COLUNA` e `CONSTRUIVEIS`.
- Migration `2026_07_14_120000_as_tres_ultimas_estruturas_da_zona.php`: três colunas em `neutral_zones`
  (`extraction_level`, `communication_level`, `landing_pad_level`), idempotente, ensaiada nos dois
  sentidos no `fertwaysdev` (MariaDB) antes de publicar — a lição do D-59.
- ⚠️ **A mesma pegadinha do model, pela terceira vez** (o próprio `NeutralZone.php` já se avisava
  disso): as três colunas novas tinham de entrar em `$fillable`, `$attributes` (o default em PHP, não
  só no banco — `create()` não relê o que o banco aplicou) e `$casts`. Esquecer qualquer um dos três
  faz o nível nascer `null` e o `ConcluirObrasDaZona` gravar `max(null, 1)` — que o PHP aceita e
  produz um resultado errado em silêncio. O teste novo (`test_as_tres_ultimas_estruturas_se_erguem_e_
  sao_inertes`) pegou isso na hora.
- `building_specs.json`: três blocos novos, 5 níveis cada.
- `NomesDeExibicao::mapa()`: os três nomes, para o painel de vínculo de imagem e o gerador do GDD.
- Frontend (`Zona.tsx`): três áreas novas na planta SVG, na faixa de cima que estava vazia entre as
  duas torres. A seção "O que ainda não existe" passa a se esconder quando `ausentes` está vazio — sem
  isso, a tela mentiria dizendo que falta algo que não falta mais.
- `tools/gdd-v36.php`: a linha da seção 10 que dizia "9 estruturas restantes" já estava desatualizada
  desde o D-67 (era estático, não gerado) — corrigida para refletir que as 12 têm custo e tempo, e que
  só o teto de zonas por jogador e o upgrade de zona continuam em aberto.

## D-80 — A zona ganha nome, e três queixas de UX viram tela melhor.
**Data:** 2026-07-14 · **Status:** arbitrado pelo usuário · **GDD: não fala de nada disto — é UI**

Ao usar a Zona Neutra pela primeira vez, o usuário trouxe quatro queixas concretas. Nenhuma delas
mexe em regra de jogo — são todas de clareza da tela:

1. **A mensagem de despacho de material não dizia qual veículo, com o quê, nem quando chegava** —
   só "Veículo a caminho da zona com o material." Numa colônia com dois ou três Furgões, isso não
   dizia nada. `POST /zones/{id}/material` passou a devolver `type` e `plate` do veículo (já existia
   `arrives_at`); a mensagem agora lê "Furgão de Comércio FW-00003-F a caminho da zona com 400 Metal
   Bruto — chega 14/07 16:20."
2. **"Já há uma obra em curso" aparecia sem dizer QUAL** — a informação já existia (seção Canteiro,
   mais abaixo na mesma tela), mas não no ponto onde a dúvida nasce, que é o botão desabilitado. Uma
   linha (`{obra.nome} nível {N} — pronta {data}`) foi acrescentada logo abaixo do botão.
3. ~~"Já há uma obra em curso" sem nada em obra~~ — **investigado e não é bug**: em produção havia
   mesmo uma obra real (Estacionamento da Zona, zona 1, iniciada 12:18 UTC, 4h de prazo) — só não
   estava visível de onde o usuário olhava. É o item 2 acima, não um defeito do domínio.
4. **Nome da zona, como já se nomeia a colônia.** Mesma regra: texto livre, opcional, até 120
   caracteres, sem exigir ser único, editável a qualquer momento. `PATCH /zones/{id}/name`; vazio
   volta a mostrar as coordenadas. Público, como o nome da colônia — aparece no mapa, no popup da
   zona e na barra lateral "Minhas zonas". Migration `nome_da_zona_neutra`, coluna `name` nullable.

Todos os quatro itens: testes PHP (4 novos) e e2e (o de renomear) verdes. Migrations ensaiadas nos
dois sentidos no `fertwaysdev` (MariaDB).

## D-81 — O nick vira porta: privada do Global, informações da privada, e a lupa busca.
**Data:** 2026-07-14 · **Status:** arbitrado pelo usuário · **GDD: não fala de nada disto — é UI**

Pedido do usuário: poder clicar no nick de quem fala no Chat. Duas ações, e cada uma no lugar certo:

1. **No canal público** (Global, Região, Vizinhança), clicar no nick de outro colono abre uma
   privada com ele — troca a aba para "Privadas" e já entra na conversa. O próprio nick continua
   texto puro, sem botão: não faz sentido mandar privada pra si mesmo.
2. **Na privada**, clicar no nick do outro abre um **popup de informações**: colônia, posição,
   porte e as zonas neutras que ele ocupa. Mesma régua de privacidade do diretório de colônias
   (D-37): nada de recursos, saldo, frota ou reputação — isso nunca é exposto a terceiros em lugar
   nenhum do jogo, e o card não abre exceção. As zonas trazem só o que `GET /zones` já publica
   (posição, distrito, mineral, nível) — guarnição e depósito continuam atrás da névoa do Drone
   (D-74) para qualquer olhar de fora, inclusive este.
3. **A lupa**, ao lado da aba "Privadas": abre uma busca por nickname, **dentro da aba Privadas**,
   acima da lista de conversas. Busca entre colonos com colônia fundada — o mesmo diretório do
   Mapa, sem endpoint novo, só filtrado no cliente por nickname. Clicar num resultado abre o mesmo
   popup de informações do item 2, não uma conversa nova.

### O que mudou no código

- `PlayerController::info()` novo, `GET /players/{user}/info`: nickname, colônia (nome, posição,
  distância, porte) e zonas — tudo já público em algum canto do jogo hoje.
- `ColonyController::index()` ganha `user_id` por linha — chave do card, que é por USER, e não por
  colônia. Teste de privacidade do diretório (`nao_vaza_recursos_saldo_nem_frota_do_vizinho`)
  atualizado para incluir a chave nova na lista esperada — continua garantindo que nada sensível
  vaza.
- `InfoJogador.tsx`: o popup, reaproveitando o `Popup` do D-69 (mesma razão: olhar de relance, sem
  sair de onde se estava — do Chat ou do Mapa).
- `Chat.tsx`: o estado da conversa aberta e da busca subiu para o componente `Chat` — um clique
  numa mensagem PÚBLICA precisa abrir a privada, e `Canal` é irmão de `Privadas`, não filho.
- Sem migration: nenhuma coluna nova, só leitura do que já existe.
- e2e novo (`chat.e2e.mjs`), oitavo arquivo da suíte — cobre as duas portas do nick e a lupa,
  inclusive a checagem de que guarnição/depósito/saldo/reputação não aparecem no popup. Precisou de
  uma fala semeada da vizinha no Global (`tools/e2e.sh`) — e `ChatMessage` não tem timestamps
  automáticos (`$timestamps = false`), então o `created_at` do seed é à mão; esquecê-lo derruba o
  resto do seeder em cascata (fica registrado na Verificação rápida também).

## D-82 — A Indústria Siderúrgica: construção nova, não está no GDD.
**Data:** 2026-07-15 · **Status:** arbitrado pelo usuário · **GDD: não fala dela — é adição pura**

Pedido do usuário: uma construção nova, na colônia e na zona neutra, que processa Metal Bruto em
Ligas Metálicas e nos cinco minerais eletrônicos — a cada 1000 Metal Bruto: 350 Ligas, 35 Alumínio,
30 Cobre, 20 Estanho, 4 Ouro, 1 Tungstênio. Taxa de processamento no nível 1 igual à Mina Local
nível 1; custo 25% maior que a Mina, em cada nível.

### A contradição, e a decisão de propósito

O §4.3 do GDD é explícito: Alumínio, Cobre, Estanho, Ouro e Tungstênio são "minerais eletrônicos
(governo inicial)" — oito frentes de mineração **governamentais**, uma por mineral, com oferta
pública no Mercado, e "**na Temporada 1, jogadores não extraem esses minerais**". A Indústria
Siderúrgica faz exatamente isso. **O usuário confirmou: quebra essa regra de propósito** — é a
mesma família de arbitragem do tributo (D-32) e do Ministério dos Transportes (D-60): o texto diz
uma coisa, o jogo faz outra, e a divergência fica registrada, não escondida.

### As arbitragens de mecânica (nenhuma no GDD — tudo decisão)

1. **Só lotes inteiros de 1000.** A receita tem seis saídas simultâneas; um lote fracionado
   deixaria alguma delas sem unidade pra creditar (a taxa do nível 1, 15 Metal Bruto/h, dá só
   0,015 Tungstênio/h — em qualquer tick isolado, sempre zero). A solução: o excedente que não
   fecha lote fica guardado — `colonies.siderurgica_lote_remainder` na colônia (mesmo padrão de
   `production_remainder`, mas rastreando progresso rumo ao próximo lote, não fração de recurso);
   o relógio próprio da zona (`last_industry_at`, avança só pelo tempo que o gasto consumiu) na
   zona, mesmo padrão da `RefinarNaZona` (D-67).
2. **Repetível, como a Mina** (D-59): mais de uma cópia, na colônia ou na zona, soma a taxa de
   processamento.
3. **Só zonas de Metal Bruto (Nordeste).** Nas outras (Água, Oxigênio, Biomassa), fica inerte —
   não há Metal Bruto para processar ali. Buildable em qualquer zona, como as três do D-79; a
   inércia vem da receita, não de uma trava nova por distrito.
4. **Convive com a Refinaria de Campo, disputando o MESMO depósito.** As duas leem e escrevem
   `deposit_amount`, cada uma com o seu relógio; quem chegar primeiro no tick leva. O
   `TickColonies` roda a Refinaria antes, de propósito — a ordem em que aparecem ali é a ordem em
   que competem.
5. **Ligas Metálicas vai para `refined_amount`** — o MESMO pote da Refinaria de Campo, porque é o
   mesmo recurso. Os cinco minerais precisaram de armazenamento novo: nasce `zone_minerals`
   (zone_id, resource_type, amount), mesmo padrão de `zone_materials` (o canteiro, D-67).
6. **Mesmo Depósito, mesma capacidade, mesmo saque** (decisão do usuário): os minerais contam no
   MESMO teto de `capacidadeDeposito()` que tudo o mais na zona, e ficam expostos ao mesmo saque de
   guerra. `Protegido::estoqueTotal()`/`saqueDetalhado()` passam a somar `zone_minerals` também —
   a repartição do butim agora é proporcional a QUALQUER número de potes (bruto, refinado, cada
   mineral), do mais valioso ao menos, cada um absorvendo o arredondamento do que vem depois.

### O custo — derivado da Mina, mas pela regra da CURVA, não por multiplicação nível a nível

⚠️ **Achado ao rodar os testes:** `GddSpecsTest` exige que o custo de TODA construção siga
`half-up(base_do_nível_1 × 1,65^(nível−1))` — um invariante estrutural do projeto, não específico
de nenhuma tabela do GDD. Calcular "o custo de cada nível da Mina × 1,25" independentemente
(arredondando cada nível à parte) **não** reproduz essa curva a partir de uma base única — os dois
métodos divergem em até 1 unidade em alguns níveis (Compostos Químicos nível 3: 34 pela primeira
conta, 35 pela curva). A correção: só a BASE do nível 1 é `half-up(Mina_nível1 × 1,25)`; os níveis
2–5 saem da curva padrão a partir dessa base — como toda outra construção do jogo. Tempo de
construção segue à parte (não está em `build_time_bases.json`, então o teste de curva de tempo não
a alcança): é `half-up(tempo_da_Mina × 1,25)` nível a nível, direto — todos os cinco valores
saíram exatos, sem arredondamento fracionário.

| Nível | Taxa (Metal Bruto/h) | Custo | Tempo |
|---|---|---|---|
| 1 | 15 | 38 Ligas + 13 Compostos | 17,5 min |
| 2 | 22 | 63 Ligas + 21 Compostos | 26,25 min |
| 3 | 34 | 103 Ligas + 35 Compostos | 40 min |
| 4 | 51 | 171 Ligas + 58 Compostos | 58,75 min |
| 5 | 76 | 282 Ligas + 96 Compostos | 88,75 min |

### O que mudou no código

- `App\Domain\Production\Siderurgica`: as constantes da receita (insumo, base, saídas), namespace
  compartilhado entre a colônia e a zona — uma fonte só.
- Colônia: migration `industria_siderurgica_na_colonia` (`colonies.siderurgica_lote_remainder`);
  `ColonyTick::processarSiderurgica()`; `industria_siderurgica` entra em `Building::PROGRESSAO` e
  `Building::REPETIVEIS`.
- Zona: migration `industria_siderurgica_na_zona` (`neutral_zones.industry_level`,
  `last_industry_at`; tabela `zone_minerals`); `App\Domain\Zona\ProcessarSiderurgicaNaZona`; entra
  em `Estruturas::COLUNA`/`CONSTRUIVEIS`/`TABELA` (com `'gdd' => 'Não está no GDD...'`, seguindo o
  mesmo padrão honesto do D-79/D-81).
- `DespacharVeiculo::retirarDeZona()` aceita os cinco minerais como recurso retirável quando a zona
  tem Indústria Siderúrgica; `ZoneController::show()` publica `deposito.minerais`.
- `ResolverCombates::saquear()` credita os minerais saqueados na colônia atacante e debita de
  `zone_minerals` — mesmo padrão do bruto/refinado.
- Frontend: nova área na planta da Zona; o Depósito lista os minerais presentes; formulário de
  retirada generalizado para QUALQUER recurso disponível no Depósito (bruto, refinado, minerais) —
  antes só existia para o refinado ficar preso, sem forma de retirá-lo pela tela; agora os três
  saem juntos. `entregarMaterial`/`retirarDeZona` passam a devolver tipo e placa do veículo, mesma
  razão do D-80.

Migrations ensaiadas nos dois sentidos no `fertwaysdev` (MariaDB) antes de publicar. e2e estendido
(`zonas.e2e.mjs`) para a área nova.

## D-83 — A receita de Ligas e Compostos: uma vira Siderúrgica, a outra ganha receita.
**Data:** 2026-07-15 · **Status:** arbitrado pelo usuário · **GDD: §17.2/§19.3 publicam a taxa, nunca a receita**

Pedido do usuário: fechar a lacuna 5 do D-52 (`Domain\Building\Funcoes` já a documentava desde o
D-59) — "o GDD publica a taxa (30/h) e nunca a receita" de Ligas Metálicas (Oficina) e Compostos
Químicos (Refinaria Química), inertes desde o D-19 para não creditar recurso do nada.

### Ligas Metálicas: não ganhou receita — perdeu a fonte

O GDD diz "Metal Bruto é extraído, Ligas são transformadas" (§4.3) e nunca a proporção. Em vez de
arbitrar uma receita nova pela Oficina, a decisão foi **abandonar essa fonte**: Ligas Metálicas só
nascem da **Indústria Siderúrgica** (D-82), que já converte Metal Bruto numa proporção real. Duas
fontes de "Metal Bruto vira Ligas" com regras diferentes seria confuso, não redundante.
`ligas_metalicas` saiu de `production.json` da Oficina (nunca era lido de qualquer forma — o laço
de `ColonyTick` só extrai `componentes_eletronicos` de lá). A Oficina continua fabricando
Componentes Eletrônicos pelas três receitas do §24.5, normalmente.

### Compostos Químicos: receita nova, e a taxa publicada foi abandonada junto

O GDD diz "produz Compostos Químicos a partir de minerais e água" (§17.2) — os dois juntos, ao
contrário da zona, onde Água OU Oxigênio bastam sozinhos (por distrito, D-67). Arbitragem do
usuário: **1 Metal Bruto + 10 Água + 5 Biomassa + 6 Energia → 1 Composto Químico**, pesos
relativos 5:50:25:30 simplificados para 1:10:5:6.

⚠️ **A taxa publicada (30/h no nível 1, §19.3) foi testada contra essa proporção e não coube.**
30 Compostos/h × 10 Água cada = 300 Água/h — a Captação de Água nível 1 produz 80/h. A Refinaria
nunca chegaria perto da capacidade nominal; ficaria "faminta" por design, não por falta de
investimento do jogador. **A saída, confirmada pelo usuário: reduzir a taxa junto, mantendo a
proporção da receita.** Nova taxa: **2 → 3 → 5 → 7 → 10** Compostos/h (nível 1 a 5) — era
30 → 45 → 68 → 101 → 152. No nível 1, consome 2 Metal Bruto + 20 Água + 10 Biomassa + 12 Energia
por hora, com folga confortável contra os 15/80/60/~88 dos essenciais do mesmo nível.

`resource_types.compostos_quimicos.producao_max_hora` continua em 152 — é o número que o GDD
publicou, e fica como registro histórico do documento, como o v35 inteiro fica. Nada em runtime lê
esse campo para `compostos_quimicos` (só a fórmula de preço derivado de Metal Bruto o usa, e
Compostos tem preço fixo — `preco_base_derivado: false`). Não precisou mudar.

### O que mudou no código

- `production.json`: `oficina` perde `ligas_metalicas`; `refinaria_quimica.compostos_quimicos`
  vira `2,3,5,7,10`.
- `ColonyTick::RECEITA_COMPOSTOS` nova; novo branch para `refinaria_quimica` no laço de produção,
  reaproveitando o `converter()` que já existe (mesma função da Destilaria). A Refinaria roda
  **antes** da Indústria Siderúrgica na ordem do laço — as duas disputam o mesmo Metal Bruto da
  colônia, e o consumo da Refinaria é pequeno perto do da Siderúrgica.
- `SAIDAS_SEM_RECEITA` removida — era um filtro defensivo que, com o catálogo atual, nunca
  disparava (nenhuma construção em `PRODUCAO_SEM_INSUMO` jamais produziu Ligas ou Compostos).
- `Domain\Building\Funcoes`: fichas da Oficina e da Refinaria Química atualizadas com o novo
  `efeito: 'converte'` e a explicação de cada mudança.
- Sem migration — nenhuma coluna nova, só dado de seed (`production.json`, reseedado via
  `BuildingSpecSeeder`) e lógica de domínio.

## D-84 — Teto de zonas, upgrade de nível e manutenção territorial: as três lacunas do D-52 que sobraram.
**Data:** 2026-07-15 · **Status:** arbitrado pelo usuário · **GDD: §07, §16.1, §27.12 — números ausentes ou contraditórios**

O D-52 (Fatia 1) tinha deixado dois itens abertos de propósito ("upgrade de zona fica para uma
fatia posterior") e um terceiro nunca chegou a ser posto em código: a manutenção territorial do
§27.12, que nunca cobrou nada de nenhuma zona, nem a de nível 1. As três, arbitradas juntas porque
uma dá sentido à outra — upgrade sem manutenção seria pagar uma vez por um nível alto de graça
para sempre.

### O teto de zonas por jogador

O GDD não publica número nenhum — só o Bastião cita "zonas defendidas simultaneamente 1–3", e o
D-52 já havia registrado que isso não é um teto de posse (lacuna 9, D-66, D-79). **Arbitrado: 5
zonas por colônia.** Verificado em `OcuparZonaNeutra`, sob a mesma trava da colônia que já debita
os recursos — uma corrida rara entre duas ocupações simultâneas poderia, em tese, deixar uma
colônia com 6; não vale a complexidade de uma trava global só para fechar essa fresta.

### O upgrade de zona

`neutral_zones.level` nasceu preso em 1 (D-52). Sobe agora, de 1 a 5, por uma ação nova
(`SubirNivelDaZona`, `POST /zones/{id}/upgrade`) que **debita direto da colônia, como a ocupação —
não do canteiro** que `ConstruirNaZona` (D-67) usa para as outras estruturas. A diferença é
deliberada: "a ocupação é o ato de chegar, as obras são o ato de investir" (D-67) — e o upgrade não
é uma estrutura entre outras, é o Posto de Comando crescendo, o mesmo ato da ocupação, mais tarde.
O nível só sobe de fato no tick seguinte (`ConcluirUpgradeDaZona`), no mesmo relógio que
`ConcluirObrasDaZona` já usa para as estruturas — custo pago na hora, efeito no tick.

As curvas, todas derivadas de números que já existiam, nenhuma inventada do zero:

- **Custo** (Metal Bruto e Fert$): curva **1,65×** sobre a base do Posto de Comando (800 MB + 300
  F$, D-52) — a mesma convenção de Muralha/Torre/Bastião/Drone (D-66/D-74), não a 1,5× do §19.1,
  que é para produção e capacidade, não para custo. Nível 2: 1.320 MB + 495 F$. Nível 5: 5.930 MB +
  2.224 F$.
- **Guarnição-alvo**: `round(20 × 1,65^(N−1))` = 20/33/54/90/148. Âncora no próprio §16.1: "quantidade
  necessária varia por nível da zona (20 a 150+)" — 148 cai dentro do "150+" sem forçar a mão, e é
  a mesma curva do custo, não um número novo. O upgrade compra a diferença de Robôs Mineradores na
  hora, como a ocupação compra os 20 iniciais — não existe uma ação separada de "recrutar".
- **Tempo**: curva **1,5×** sobre as 8h do Posto — a proporção que o próprio catálogo já usa para
  `build_time` (observada em Muralha/Torre/Bastião, não publicada em §19.1 por nome). Nível 2: 12h.
  Nível 5: 41h.
- **Extração e capacidade do Depósito**: já escalavam pela curva do §19.1 desde sempre
  (`extracaoPorHora()`); só faltava alguém escrever um nível diferente de 1 no banco. Nível 2:
  150/h (era 100).

### A manutenção territorial (§27.12)

Nunca implementada — nem para zona de nível 1. O custo diário é o publicado, verbatim: nível 1, 50
Biomassa + 30 Energia; níveis 2–3, +20 Ligas; níveis 4–5, 200/120/50 + 10 Componentes. Cobrada da
colônia (não do Depósito da zona — são recursos diferentes), no tick, uma vez por zona por dia
(`CobrarManutencaoTerritorial`).

O decaimento e o abandono **não seguem o texto cru do §27.12** ("5%/hora, abandono em 48h") — o
D-52 já havia corrigido esses dois números pela precedência da seção 0 (a Parte I corrige a Parte
II): **5% de Pontos de Defesa por DIA de atraso, depois de 24h de carência; abandono automático em
72h.** A penalidade incide na Força Defensiva (`Forcas::defensiva()`), sobre a base de unidades,
antes do bônus de construção — a muralha não fica mais fraca por falta de pagamento, os robôs é que
lutam pior.

**O abandono não tem precedente no código para reaproveitar.** Conquista por guerra sempre
TRANSFERE a zona (`ResolverCombates::vitoriaDoAtacante`), nunca a esvazia — nada no jogo hoje
zera `owner_colony_id`. Decisão nova: abandono é reset completo ao estado de zona nunca ocupada —
todos os níveis a zero (Posto, Depósito, Muralha, Torre, Bastião, Refinaria, Siderúrgica, as três
inertes), guarnição apagada, dono nulo. **Não um "congelamento" com os níveis preservados**: do
contrário, abandonar de propósito uma zona nível 5 para outra conta do mesmo jogador ocupar de
graça viraria a lavagem que o D-73 já fechou para o Furgão, só que para zonas. O relógio da
manutenção é do DONO, não da zona: uma conquista (guerra) ou reocupação (depois de abandono) sempre
nasce com 24h de trégua antes da primeira cobrança — a inadimplência do derrotado não é herança
para quem acabou de chegar.

### O que mudou no código

- Migration `2026_07_15_180000_teto_upgrade_e_manutencao_de_zona`: `neutral_zones` ganha
  `level_target`, `level_upgrade_finishes_at`, `maintenance_next_due_at`,
  `maintenance_unpaid_since`. Quem já tinha zona ganha 24h de trégua no backfill, em vez de cobrança
  retroativa ou inadimplência no instante do deploy.
- `NeutralZone`: `TETO_ZONAS_POR_COLONIA`, `custoDeUpgrade()`, `guarnicaoAlvo()`,
  `horasDeUpgrade()`, `custoDeManutencao()`, `penalidadeManutencaoBps()`, `deveSerAbandonada()`.
- Domínio novo: `Zona\SubirNivelDaZona` (pede o upgrade), `Zona\ConcluirUpgradeDaZona` (fecha no
  tick, padrão `ConcluirObrasDaZona`), `Zona\CobrarManutencaoTerritorial` (cobra, marca
  inadimplência, abandona).
- `OcuparZonaNeutra`: teto de 5 zonas antes de debitar; arma o relógio da manutenção com 24h de
  trégua a partir de `productive_at`.
- `ResolverCombates::vitoriaDoAtacante`: zera o relógio da manutenção para o novo dono.
- `Guerra\Forcas::defensiva()`: aplica a penalidade de manutenção à base, antes do bônus de
  construção.
- `TickColonies`: dois passos novos — manutenção (logo depois de expirar proteções, antes da
  extração e do combate: uma zona abandonada neste minuto não pode render nem ser defendida) e
  conclusão de upgrade (junto das obras de estrutura).
- `Ledger::TIPOS`: `custo_upgrade_zona`, `manutencao_territorial`.
- `NeutralZoneController`: `POST /zones/{id}/upgrade`; `GET /zones` publica `upgrade` e
  `manutencao` só para o dono — o EFEITO da manutenção em atraso (defesa reduzida) é real para
  qualquer atacante, mesmo sem ver o extrato.
- Frontend (`Mapa.tsx`, `PainelZona`): nível da zona, botão de upgrade com custo/guarnição do
  próximo nível, aviso de manutenção em atraso com a penalidade e o prazo de abandono.
- `tests/Feature/UpgradeDeZonaTest.php`: teto, custo/guarnição/relógio do upgrade, conclusão no
  tick, cobrança bem e mal-sucedida, decaimento, abandono aos 72h, integração com `Forcas`.
- Validado: 562 testes (SQLite e MariaDB efêmero em container), round-trip de migrations limpo,
  lint, build, e2e completo (8 arquivos, 252 asserções) — todos verdes antes do deploy.

## D-88 — Sair vira ícone com confirmação, a lateral troca de ordem, e o card da zona ganha corpo.
**Data:** 2026-07-15 · **Status:** arbitrado pelo usuário · **Ajustes pontuais de UX, não do GDD**

Três pedidos pontuais do usuário sobre a tela da colônia:

1. **O botão Sair** morava sozinho no canto inferior esquerdo, longe de onde o colono já olha
   para se ver (o card do saldo/perfil, canto superior direito). Virou ícone ao lado do perfil.
   Confirmado com o usuário: como ícone é mais fácil de clicar sem querer do que o antigo botão
   de texto, pede confirmação — o mesmo toggle "Sim/Não" que `Transportes.tsx` já usa para
   sucatear um veículo, não um `window.confirm()` nativo (que não existe em lugar nenhum do
   projeto) nem o gate "escreva a palavra" da demolição (reservado para o que não se desfaz —
   sair é reversível, basta entrar de novo).
2. **A ordem da lateral** — Zonas Neutras vinha antes da Fila de Construção. Confirmado:
   inverter. A Fila é o que o colono acabou de mexer; as Zonas (que só aparecem quando ele tem
   alguma) empurravam a Fila para baixo da dobra em quem tinha várias.
3. **O card de cada zona, na lateral, ganhou corpo.** Antes mostrava só nome/posição/mineral,
   cercada, "estabelecendo", depósito/capacidade, exposto e a obra em curso. Confirmado com o
   usuário (escolheu as quatro opções oferecidas): agora também mostra **nível da zona e upgrade
   em andamento** (D-84), **guarnição e pontos de defesa**, **manutenção territorial em atraso**
   (D-84) e **o que já chegou ao canteiro** de obras.

### A dependência do D-84

Nível, upgrade e manutenção só existem porque o D-84 (teto/upgrade/manutenção de zona) já foi
implementado — este commit está empilhado sobre aquele branch, e só pode ser mesclado depois
dele (ou junto).

### O que mudou no código

- `ZoneController::minhas()` ganha `level`, `upgrade` (nulo se não há upgrade em curso),
  `guarnicao` (robôs/sentinelas/defesa) e `manutencao` (inadimplente_desde/penalidade), além do
  `canteiro` (o que já chegou de veículo) — os mesmos dados que `show()` já publicava para a
  tela cheia da zona, resumidos para a lateral.
- `App.tsx`: o ícone de Sair (SVG de porta com seta, mesmo estilo do ícone de perfil), estado
  `confirmandoSaida`, e a troca de ordem na `<div>` da lateral. O botão de texto antigo, no
  rodapé, foi removido — não duplicado.
- `MinhasZonas.tsx`: as quatro linhas novas no card, condicionais (upgrade só aparece se houver
  um em curso; manutenção só se estiver inadimplente; canteiro só se tiver algo).
- `frontend/e2e/mercado.e2e.mjs`: o teste de logout clica `[data-sair]` e depois
  `[data-confirmar-sair]`, em vez de um botão de texto "Sair" que não existe mais.
- `tests/Feature/PerfilTest.php`: `test_a_lista_das_minhas_zonas` estendido com guarnição e
  canteiro na zona de teste, e asserções para os quatro campos novos do payload.
- Validado: 562 testes de backend, lint, build e e2e completo do frontend.

## D-87 — O Governo vende no Mercado Central, e a Economia do admin vira sub-abas.
**Data:** 2026-07-15 · **Status:** arbitrado pelo usuário · **Decisão de produto, não do GDD**

Pedido do usuário: separar a aba Economia do painel de admin (que empilhava Finanças,
Ministério do Tesouro e Enviar Recursos numa página só) em sub-abas, e criar uma sub-aba
**Mercado** nova, onde o Governo lista recursos do Tesouro à venda no Mercado Central — a mesma
vitrine que os colonos já usam.

### A lacuna que isso expôs: `market_orders.colony_id` era obrigatório

O Mercado Central só sabia lidar com colônias de verdade — `colony_id` era `NOT NULL`, com FK
`cascadeOnDelete`, e `ColocarOrdem`/`ExecutarOrdem` exigiam um `Colony` real. Não havia como o
Governo ser vendedor sem inventar uma colônia falsa.

**Resolução, confirmada com o usuário: `colony_id` nulo é o Governo.** Mesmo padrão que
`vehicles.colony_id` nulo já usa para a frota pública (D-60) — a oferta é uma linha real na
mesma tabela, na mesma vitrine, sem precisar esconder uma colônia sintética do mapa, do
diretório e da guerra (o risco real de uma "colônia Governo" de verdade: ela apareceria como
alvo atacável e como vizinha no diretório de todo mundo).

`tax_events.colony_id` também virou nullable — é ele que segura a proteção contra execução
dupla (`economic_event_key`), então a venda do Governo continua passando pelo mesmo gate.

### A semântica do formulário: "isto é o que deve estar à venda agora"

Confirmado com o usuário: o número que o admin digita por recurso **não soma** ao que já está
anunciado — ele **define o total disponível neste instante**. Subir o número reserva mais do
Tesouro; descer devolve a diferença; zerar cancela a oferta. Isso significa reconciliar, não
criar: `OfertarComoGoverno::definir()` encontra a oferta aberta do recurso (se houver), calcula
o delta contra o valor novo, e ajusta o Tesouro e a `MarketOrder` juntos, na mesma transação.

**O admin não pode anunciar mais do que o Tesouro tem agora** (confirmado) — mesma regra de
qualquer venda no jogo: só se oferta o que já está na doca. Recusa e diz quanto há disponível.

### O dinheiro, quando o Governo vende

`ExecutarOrdem::fechar()` sempre separou o líquido (para o vendedor) da taxa (para o Tesouro).
Quando o vendedor É o Tesouro, separar não faz sentido — as duas partes terminam no mesmo lugar.
A correção: quando `colony_id` da oferta é nulo, credita-se o `$valor` inteiro de uma vez, sem
lançamento de ledger (não há colônia) e sem XP para "quem vendeu" — só o comprador ganha XP e
progresso de missão, como em qualquer execução.

### O card de alerta

Confirmado com o usuário: mostra **qualquer recurso sem oferta ativa** — não só os que já
venderam tudo. É lista de "o que falta preencher", não só "o que falta repor". Só aparece na
visão geral quando há algo a fazer; card vazio "está tudo bem" não ocupa espaço.

### O que mudou no código

- Migration `2026_07_15_210000_market_orders_colony_id_nulo_para_governo`: `market_orders` e
  `tax_events` ganham `colony_id` nullable.
- `Tesouro`: `debitar()`/`creditar()` públicos, genéricos (o `ajustar()` privado por trás de
  `distribuir`/`creditarRecurso`/`creditarFert` continua intocado).
- `Domain\Market\OfertarComoGoverno` (novo): `definir()` reconcilia a oferta contra o alvo;
  `ofertas()` lista o que já está anunciado, para o formulário pré-preencher.
- `ExecutarOrdem::fechar()`/`comprarDaOferta()`: aceitam vendedor nulo (Governo) — crédito
  direto ao Tesouro, sem ledger nem XP do lado do vendedor.
- `MarketController::livro()`: `colonia` mostra "Governo" para oferta sem dono; `e_governo` novo
  no payload.
- `PainelController::economia()`: sub-abas por query string (`?aba=`), mesmo padrão que
  `admin.imagens` já usava; `dashboard()` ganha o card de alerta.
- `AcoesController::mercadoGoverno()` + rota `POST /admin/mercado/governo`: salva os 26 recursos
  de uma vez, cada um sua própria transação — o que não falhar fica salvo mesmo se outro falhar.
- `economia.blade.php` reescrita em quatro sub-abas; `dashboard.blade.php` ganha o card.
- Frontend: `OfertaGlobal.colony_id` passa a admitir `null`, e ganha `e_governo` — o texto
  "Governo" já aparecia de graça pelo fallback que a tela já tinha (`oferta.colonia`), sem
  precisar de nenhuma mudança visual.
- `tests/Feature/MercadoDoGovernoTest.php` (8 casos): reconciliação (subir/descer/zerar),
  trava no saldo do Tesouro, execução por um colono, o endpoint do admin, e a vitrine mostrando
  "Governo".
- Validado: 560 testes (SQLite e MariaDB 10.5 efêmero em container local), round-trip de
  migrations limpo nos dois, lint, build, e2e completo.

## D-91 — Chamar o veículo de volta do Pátio, vazio, e a Capital avisa pelo rádio.
**Data:** 2026-07-16 · **Status:** arbitrado pelo usuário · **Ajuste de UX sobre o D-65, não do GDD**

Dois problemas do mesmo lugar, o Pátio Logístico da Capital (D-65): um veículo que descarregasse
lá **não tinha como voltar vazio** — só saía do Pátio levando carga de verdade, ou esperando o
reboque automático (`Patio::rebocar`) quando a colônia não tinha Fert$ para a hora. E ninguém
avisava que ele estava lá: a tarifa (0,005 Fert$/h) só aparecia para quem abrisse a tela do
Mercado por conta própria — um veículo podia ficar parado por dias, comendo Fert$ hora a hora, em
silêncio.

### Chamar de volta, vazio

Confirmado com o usuário: a volta vazia **cobra como um despacho normal** — a energia da
distância, a mesma que qualquer viagem já paga — mas **não exige Confiança Comercial** (o limiar
de 200/1000 que fecha o resto do Pátio, D-65). O raciocínio: resgatar o próprio veículo não é
"usar o Mercado", é reaver um bem seu — a mesma lógica que já isenta o reboque automático de
qualquer trava. Só esta combinação exata (vazio, para a PRÓPRIA colônia) ganha a isenção; carga
de verdade, mesmo indo para casa, continua sendo Mercado como sempre foi, e vazio para OUTRO
colono continua sendo recusado (`carga_vazia` — não faz sentido nenhum).

### O aviso da Capital

Pedido do usuário: um aviso no chat, remetente "Capital", quando o veículo estaciona e a cada 24h
que continuar lá. O chat (D-77) nunca teve um remetente que não fosse jogador — `user_id` é
`NOT NULL`, e mandar mensagem sempre exigiu passar por silêncio, filtro de termos e bloqueio,
regras pensadas para conter jogador, não para um aviso do próprio jogo.

**Confirmado com o usuário: uma conta de sistema de verdade**, não uma mudança de schema. Uma
migration reserva o nickname "Capital" (checado antes: nenhum jogador o tinha) — reservar por
migration, não sob demanda em código, fecha a janela em que um jogador de carne e osso poderia
tomá-lo primeiro. `EnviarMensagem::sistema()` grava a mensagem privada direto, pulando as três
checagens de jogador — nenhuma delas protege alguém de um aviso do sistema.

A conta "Capital" não é um jogador: `PainelController::jogadores()` a exclui da lista de
colonos do admin (ela não tem colônia, e nada ali deveria poder suspendê-la ou editá-la como se
fosse gente).

### O que mudou no código

- Migration `2026_07_16_120000_aviso_do_patio_e_conta_capital`: `vehicles.patio_aviso_enviado_em`
  (nullable) e a conta `capital@fertways.sistema`, nickname "Capital", sem colônia.
- `Domain\Chat\ContaSistema::capital()`: resolve a conta reservada pela migration.
- `EnviarMensagem::sistema()`: grava uma mensagem privada pulando silêncio/filtro/bloqueio — só
  para o próprio jogo usar, nunca para um jogador.
- `Domain\Capital\AvisoDoPatio`: `estacionou()` (chamado por `ConcluirTrechos::estacionar()`) e
  `lembrarSeDevido()` (chamado por `Patio::handle()`, só manda depois de 24h do último aviso, e
  nunca para quem acabou de ser rebocado).
- `DespacharVeiculo::doPatio()`: `$vazioParaCasa` pula `AcessoAoMercado::exigir()` e
  `validarCarga()` só quando `$carga === []` e o destino é a própria colônia.
- `VehicleController::despachar()`: `cargo` vira `present` em vez de `required`+`min:1` — quem
  decide se vazio é válido é o domínio, com o contexto de origem que a validação HTTP não tem.
- `emRota()`: `cargo_json` grava `null` em vez de `[]` quando a carga é vazia — um array vazio
  chegava truísta em JS e a tela desenhava um " · " sem nada depois.
- `Mercado.tsx` (`LinhaVeiculo`): botão "Chamar de volta (vazio)" para todo veículo ocioso no
  Pátio, chamando `api.enviarAColonia(v.id, colonia.id, {})`.
- `PainelController::jogadores()`: exclui a conta "Capital" da lista de jogadores do admin.
- `tests/Feature/PatioDaCapitalTest.php` (+3 casos) e `tests/Feature/AvisoDoPatioTest.php`
  (novo, 5 casos): a volta vazia sem Confiança, a trava intacta para carga de verdade, vazio
  recusado para outro colono, o aviso ao estacionar, o lembrete de 24h, sem lembrete antes disso,
  e sem lembrete para quem foi rebocado.
- Validado: 579 testes (SQLite e MariaDB 10.5 efêmero em container local), round-trip de
  migrations limpo nos dois, lint, build. E2e completo, mas só como regressão — o e2e não roda o
  tick (`tools/e2e.sh` é explícito sobre isto), então nenhum veículo chega a estacionar de
  verdade numa corrida de e2e; o comportamento novo só é observável pelos testes de domínio.

## D-86 — A zona vira cinco abas, o Canteiro pergunta a obra antes do recurso, e nasce o Histórico.
**Data:** 2026-07-15 · **Status:** arbitrado pelo usuário · **Ajustes pontuais de UX, não do GDD**

Pedido do usuário: reorganizar a tela da zona (uma coluna só, de cima a baixo) em abas — **Zona
Neutra** (identidade, planta, upgrade de nível), **Depósito**, **Canteiro de obras**, **Guarnição**
e **Histórico** (novo) —, consertar o Canteiro ("não está dando para entender a mecânica ali"), e
deixar o nome do dono de uma colônia ou zona no mapa abrir o chat privado com ele.

### O chat a partir do mapa

Confirmado com o usuário: clicar no nome abre a **ficha do jogador** (`InfoJogador`, já existente,
D-81) — e é DENTRO dela que mora o botão "Conversar" novo, não um atalho direto. A ficha e o Chat
vivem em lugares diferentes da árvore (o Mapa é uma rota própria, `/mapa`; o Chat só existe dentro
da rota `/`, como um estado local de `App.tsx`) — não havia como um "abrir" o outro. A ponte:
`Mapa` ganhou `aoAbrirChatPrivado`, que `App.tsx` liga a um novo estado `conversaAlvo`, navegando
de volta para `/` e abrindo o Chat já com a privada certa. Do lado da zona, o dono só tinha
`colony.id`/`colony.name` publicados (`NeutralZoneController::index`) — sem `user_id` não há como
abrir a ficha de ninguém; publicá-lo não vaza nada novo (o diretório de colônias já publica
`user_id` de toda vizinha desde o D-37).

### O Canteiro: pergunta a obra antes do recurso

O diagnóstico, confirmado com o usuário antes de mexer: o formulário sempre usava o primeiro
veículo ocioso da frota **sem dizer qual**, oferecia sempre os mesmos **três recursos fixos**
(Metal Bruto, Ligas, Componentes) mesmo quando a obra pedia outra coisa, e os campos não tinham
limite nenhum (ao contrário do formulário de Retirar, que já tinha). Um colono erguendo a Antena de
Comunicação (que pede Componentes + Quartzo) via um formulário que só oferecia enviar Metal Bruto,
Ligas e Componentes — Quartzo nunca aparecia.

**A correção não foi só consertar os três problemas — foi inverter a pergunta.** Agora o formulário
pergunta **"para qual obra?"** primeiro (um `<select>` com as construções erguíveis que têm
próximo nível), e só depois de escolhida mostra os recursos que ELA precisa, com "falta N de M"
já calculado contra o que já está no canteiro, pré-preenchido, e limitado pela capacidade efetiva
do veículo escolhido. Com mais de um veículo ocioso, agora dá para escolher qual — antes era
sempre o primeiro da frota, em silêncio. O estado de "obra selecionada" é compartilhado com a
planta da aba Zona Neutra (o mesmo clique que abre o painel de uma estrutura lá já pré-seleciona
ela aqui) — clicar na Muralha na planta e ir direto ao Canteiro já vem com a Muralha escolhida.

### O Depósito: já estava pronto para recursos futuros

O usuário explicou por que pediu "estocar todos os tipos de recursos": zonas neutras vão, em
breve, produzir mais do que um ou dois recursos. Ao investigar, `ZoneController::show` e o
formulário de Retirar **já eram genéricos** — constroem a lista do que há no Depósito (bruto +
refinado + os minerais da Siderúrgica) dinamicamente, sem nenhum nome de recurso hardcoded.
**Não precisou mudar nada na mecânica** — só documentar isso e reorganizar em aba própria: quando
uma zona passar a produzir um sexto ou sétimo recurso, ele aparece sozinho, sem tocar em código.

### A Guarnição ganhou o Reforço

Confirmado com o usuário: a aba não só reorganiza os números que já existiam (Robôs, Sentinelas,
Defesa) — também traz para dentro dela a ação de **reforçar a zona** (`Domain\Guerra\Reforcar`,
D-70), que até aqui só existia atrelada a um combate ativo específico, no Quartel. O próprio
domínio já dizia "reforçar não exige combate em curso" — a tela é que nunca tinha oferecido o
caminho geral. Agora dá para guarnecer uma zona seguindo em paz, sem esperar ser atacado.

### O Histórico: três fontes, uma linha do tempo

Não existia nada — nem endpoint, nem tabela. Confirmado com o usuário: três categorias
(**financeiro**, **guerra**, **posse**), só o dono vê (mesma régua do D-74/D-84: o interior da
zona é dela).

- **financeiro**: `Ledger` cujo `ref` começa em `zona:{id}:` — cobre ocupação, upgrade de nível,
  manutenção territorial, saque/cerco. **Não cobre** o material entregue ao canteiro
  (`custo_obra_zona`): esse ledger é indexado pela viagem do veículo, não pela zona, e não valeria
  a pena um JOIN só para isto.
- **guerra**: `Combat` desta zona — invasões, cercos, sabotagens, apreensões.
- **posse**: tabela nova, `zone_events` (`App\Models\ZoneEvent`) — ocupação, abandono por
  manutenção não paga, conquista por guerra. **Sem precedente no código**: nenhum evento discreto
  de "isto aconteceu com a zona" existia antes — o estado da zona sempre foi só o presente, nunca
  um histórico. `colony_id` é nullable de propósito: um abandono não tem colônia nenhuma no fim
  (a linha registra QUEM perdeu, lida antes do `update()` zerar `owner_colony_id`).

### O que mudou no código

- Migration `2026_07_15_200000_create_zone_events_table`: `zone_events` (`zone_id`, `type`,
  `colony_id` nullable, `meta` json, `created_at`).
- `App\Models\ZoneEvent` (novo). Hooks: `OcuparZonaNeutra` (ocupada),
  `ResolverCombates::vitoriaDoAtacante` (conquistada, com `combat_id` e o dono anterior em
  `meta`), `CobrarManutencaoTerritorial::abandonar` (abandonada).
- `ZoneController::historico()` + `GET /zones/{id}/historico` — mescla as três fontes, 403 para
  quem não é dono.
- `ZoneController::show()` ganhou `level`, `upgrade` e `manutencao` (D-84), que só existiam em
  `NeutralZoneController::index` até aqui.
- `NeutralZoneController::index`: `owner` ganhou `user_id`.
- Frontend: `Zona.tsx` reescrito em cinco abas; `EnviarMaterial` (o formulário do Canteiro,
  pergunta a obra); `ReforcarZona`; as três `Linha*` do Histórico. `InfoJogador` ganhou o botão
  "Conversar" (só aparece se o chamador passar `aoConversar`). `Chat` ganhou `conversaInicial` +
  `aoConsumirConversaInicial`. `Mapa` ganhou `aoAbrirChatPrivado`, e o nome do dono (colônia ou
  zona) virou botão que abre a ficha do jogador.
- e2e: `zonas.e2e.mjs` cobre as cinco abas, o seletor de obra do Canteiro, e o Histórico mostrando
  a ocupação recém-feita; `chat.e2e.mjs` cobre o fluxo novo — Mapa → ficha do jogador → Conversar
  → Chat já na privada certa.
- Validado: suíte de backend inteira, lint, build, e2e completo.

## D-85 — O kit inicial vira uma tabela só: 100 Fert$ e um valor fixo para os 26 recursos.
**Data:** 2026-07-15 · **Status:** arbitrado pelo usuário · **Decisão de balanceamento, não do GDD**

Pedido do usuário: substituir o que a colônia recebe ao fundar por uma tabela única, com um valor
por recurso do catálogo inteiro. Antes disto, a fundação juntava recursos de **três fontes
separadas**, cada uma com sua própria história:

1. **50 Fert$** — o único número do próprio GDD ("todo colono recebe 50 Fert$ ao chegar").
2. **Raros calculados** (D-17, "muro de progressão"): `CreateColony::concederRarosDoKit()` somava,
   de `building_specs`, o custo de raro de nível 1 de cada construção de progressão — dava
   exatamente o bastante para erguer cada uma **uma vez** (Ferro Vermelho ×1, Gelo de Metano ×3,
   Nióbio Alienígena ×5, Quartzo Piezoelétrico ×3, Resina Orgânica ×3).
3. **Kit fixo separado** (D-57, `KitInicialDeRecursos`, na fronteira do `ColonyController::store`,
   não dentro de `CreateColony` — de propósito, "para a primitiva de domínio nascer com estoque
   limpo"): 1000 Metal Bruto, 1000 Ligas Metálicas, 500 Compostos Químicos, 300 Biocombustível,
   500 Componentes Eletrônicos, emissão do governo, sem debitar do Tesouro.

**Resolução: as três morrem, e a `Domain\Colony\KitInicial` (D-85) vira a única fonte.** 100 Fert$
(dobrou) e um número fixo para cada um dos 26 recursos do catálogo — a tabela completa está no
arquivo. `CreateColony` grava os valores direto nas linhas de `resources` na fundação (não mais em
duas visitas separadas), e cada recurso concedido vira um lançamento `kit_inicial` auditável (os
zerados do kit não geram linha — não houve concessão para auditar).

### O "muro de progressão" quebra de propósito, confirmado com o usuário

A nova tabela dá **0 Nióbio Alienígena** e **2 Quartzo Piezoelétrico**. Nenhum dos dois é
produzível no jogo — só o governo vende (Nióbio pelo `POST /war/niobio`; Quartzo não tem fonte
nenhuma no MVP). A Torre de Defesa + Quartel juntas exigem **5** Nióbio no nível 1; a Refinaria
Química + Antena de Comunicação juntas exigem **3** Quartzo. Com a tabela nova, **uma colônia
recém-fundada não consegue erguer nenhuma das duas sem antes comprar do governo** — quebra
exatamente a garantia que o D-17 existia para dar.

Perguntado diretamente, o usuário confirmou: **é proposital.** Defesa militar (Torre + Quartel) e
uma das duas construções de comunicação ficam trancadas até o colono negociar. **Não "conserte"
sem perguntar** — é a mesma família de decisão consciente do tributo (D-32) e do Ministério dos
Transportes (D-60).

### Outras confirmações do usuário

- **Só colônias novas.** Quem já tem colônia hoje não é tocado — sem backfill, ao contrário do
  D-57 (que tinha `artisan fertways:kit-recursos --aplicar` para as existentes). Se um dia quiser
  nivelar quem já joga, é uma decisão nova, não uma consequência automática desta.
- **O Furgão de Comércio nasce igual.** A tabela é só de Fert$ e recursos; o veículo do kit inicial
  não muda.

### O que mudou no código

- `Domain\Colony\KitInicial` (novo): a tabela única, `RECURSOS` (26 entradas).
- `Colony::SALDO_INICIAL_MICRO`: 50.000.000 → 100.000.000 micro-Fert$.
- `CreateColony`: grava o kit direto na criação das linhas de `resources`; `concederRarosDoKit()`
  morreu, virou `lancarKitInicial()` (só audita no ledger o que a tabela já gravou).
- `Domain\Colony\KitInicialDeRecursos` (D-57) e `app/Console/Commands/KitRecursos.php`:
  **deletados**. `ColonyController::store` não chama mais nada depois de `CreateColony::handle()`.
- `Ledger::TIPOS`: `kit_recursos` fica na lista, comentado como descontinuado — é ledger,
  append-only, e colônias fundadas antes do D-85 têm lançamentos de verdade com esse tipo.
- Testes: `ColonyCreationTest` e `TesouroTest` reescritos para a tabela nova (as duas provas do
  kit fixo do D-57 foram removidas — testavam uma classe que não existe mais). Nove arquivos de
  teste que mediam produção/logística por delta ganharam um reset explícito de estoque no helper
  de criação de colônia (`$colony->resources()->update(['amount' => 0])`), porque agora a colônia
  nasce com recursos e essas contas eram sobre a produção, não sobre o kit.
- Frontend: `Fundacao.tsx` (o texto "Você chega com 50 Fert$..." → 100 Fert$ + kit de recursos).
- Validado: suíte inteira sem os 40 testes que a mudança de fato invalidou (agora corrigidos),
  lint e build do frontend, e2e completo.

## D-92 — O kit inicial vira tela de admin: nenhum número mais preso em código.
**Data:** 2026-07-16 · **Status:** arbitrado pelo usuário · **Extensão do D-85, ainda balanceamento, não GDD**

Pedido do usuário: uma tela em `/central/admin` (aba Operação) para arbitrar o kit inicial —
Fert$, os 26 recursos e a frota — sem precisar editar `Domain\Colony\KitInicial` em código.
`const RECURSOS` (PHP) vira `kit_inicial_recursos` (uma linha por recurso, banco), e o Fert$/frota
viram `kit_inicial_settings` (linha única, mesmo padrão de `transport_settings`/
`marco_settings`). Nenhum número muda neste commit — é a mesma tabela do D-85, só que editável.

### A frota entra no kit pela primeira vez

Antes, `CreateColony` erguia um Furgão de Comércio hardcoded — nem o D-85 tocou nisso ("o veículo
do kit inicial não muda", registrado então). Confirmado com o usuário agora: só a **quantidade**
por tipo de veículo é arbitrável (não nível, não capacidade — esses continuam os padrões do
catálogo, nível 1). `KitInicial::frota()` devolve `{furgao_de_comercio: N, caminhao_de_carga: M}`,
e `CreateColony` cria exatamente essa combinação, registrando a placa de cada um (§16.3, D-60)
como sempre fez. Hoje: 1 Furgão, 0 Caminhões — igual ao que já existia.

### Sem backfill, de novo

Confirmado com o usuário: editar o kit pelo painel só vale para quem funda DEPOIS de salvar.
Mesma regra que o D-85 já tinha fixado — sem comando de aplicar retroativamente, ao contrário do
que o D-57 (morto) costumava oferecer.

### O muro de progressão: avisa, não trava

O D-85 zerou Nióbio Alienígena e deixou só 2 Quartzo Piezoelétrico de propósito, para trancar
Torre de Defesa + Quartel (juntas, 5 Nióbio) e Refinaria Química + Antena de Comunicação (juntas,
3 Quartzo) até o colono negociar com o governo. Um admin usando a tela nova, sem saber disso,
podia reabrir o muro sem querer. Confirmado com o usuário: a tela **avisa, ao lado do campo**
("X+ reabre..."), mas **não bloqueia** — o admin decide de olhos abertos, a mesma filosofia do
D-32/D-60 (arbitragem consciente não se "conserta" com uma trava). `KitInicial::
MURO_NIOBIO_REABRE_EM`/`MURO_QUARTZO_REABRE_EM` são os limiares do aviso, documentados, não uma
validação — o formulário aceita qualquer valor ≥ 0.

### O que mudou no código

- Migration `2026_07_16_130000_kit_inicial_editavel_pelo_admin`: `kit_inicial_recursos`
  (`resource_type` chave, `amount`) semeada com os 26 valores que `KitInicial::RECURSOS` tinha;
  `kit_inicial_settings` (linha única: `fert_micro`, `furgoes`, `caminhoes`), com os mesmos
  defaults de sempre.
- `App\Models\KitInicialSetting` (novo): singleton, mesmo padrão de `TransportSetting`.
- `Domain\Colony\KitInicial`: `const RECURSOS` morreu; `recursos()`, `fertMicro()`, `frota()` leem
  do banco agora. `MURO_NIOBIO_REABRE_EM`/`MURO_QUARTZO_REABRE_EM` (constantes, só para o aviso).
- `CreateColony`: lê `KitInicial::recursos()`/`fertMicro()`/`frota()` em vez das constantes;
  a criação do Furgão vira um laço sobre `KitInicial::frota()` — qualquer combinação de
  tipo/quantidade, não só "sempre um Furgão".
- `PainelController::operacao()`: publica o kit atual (recursos com nome/classe, settings, tipos
  de veículo, os dois limiares do muro) para a view.
- `AcoesController::kitInicial()` + rota `POST /admin/operacao/kit-inicial`: valida e salva os
  três blocos numa chamada só, audita (`Auditoria::registrar`, via `tentar()`).
- `resources/views/admin/operacao.blade.php`: card novo, tabela dos 26 recursos (aviso inline nas
  duas linhas do muro) + Fert$ + quantidade por tipo de veículo.
- `tests/Feature/KitInicialAdminTest.php` (novo, 6 casos): a tela mostra o kit e o aviso, salvar
  muda o que a fundação de fato concede (inclusive frota), colônia já fundada não é tocada,
  recurso desconhecido no payload não vira linha, quantidade negativa é recusada, o singleton
  nasce com os defaults do D-85. `ColonyCreationTest`/`TesouroTest` atualizados para
  `KitInicial::recursos()` (não mais a const).
- Validado: 588 testes (SQLite e MariaDB 10.5 efêmero em container local), round-trip de
  migrations limpo nos dois, lint, build, e2e completo.

## D-93 — Seis ajustes pontuais no HUD e no Mapa.
**Data:** 2026-07-16 · **Status:** arbitrado pelo usuário · **Ajustes de UX, não do GDD**

Pedido do usuário, seis itens: Sair do mesmo tamanho do Perfil; um card do Marco ao lado do
Fert$; um atalho para a Capital ao lado do Mapa; a busca do Chat casando nome de colônia, não só
nickname; "Conversar" direto no painel da colônia do Mapa; e o formulário de carga do Mercado
abrindo com três linhas em vez de uma.

### O bug que o pedido não citou, mas o e2e achou

O botão "Conversar" novo, no painel da colônia do Mapa (`PainelColonia`), usou `data-conversar`
— a MESMA marca que `InfoJogador.tsx` já usa no botão homônimo dentro da ficha do jogador. Os
dois convivem na tela ao mesmo tempo (o painel da colônia fica atrás da ficha aberta), então um
seletor `[data-conversar]` virou ambíguo. O e2e (`chat.e2e.mjs`) reproduziu isso de forma
determinística — falhou igual em duas corridas seguidas, antes da correção. Renomeado o novo
para `data-conversar-direto`.

### O que mudou no código

- `App.tsx`: o wrapper do dropdown de confirmação de Sair ganha `flex` (o `align-items: stretch`
  do flexbox, que já valia para o wrapper, não alcançava o botão dentro dele sem isso); card do
  Marco (`colonia.marco`, já vinha na resposta) entre o botão Perfil e o card de Fert$; botão
  "Capital" ao lado do "Mapa", navegando para `/capital` — antes só se chegava lá pelo losango
  dentro do Mapa.
- `Chat.tsx`: o filtro de busca (dentro de Privadas) passa a casar `nickname` OU `name` (nome da
  colônia) — a lista de resultados já mostrava os dois, mas só filtrava por nickname.
- `Mapa.tsx`: `PainelColonia` ganha a prop opcional `aoConversar`, com o mesmo botão que
  `InfoJogador` já tem, mas `data-conversar-direto` (ver acima).
- `Mercado.tsx`: `FormularioDeCarga` (usado nos 5 formulários de carga do Mercado — Despachar do
  Pátio, Buscar, Levar ao depósito etc.) nasce com 3 linhas de recurso, não 1. Linhas vazias já
  eram ignoradas na montagem da carga — nada mudou na validação.
- Validado: `npx tsc -b`/lint/build limpos, e2e completo (8 arquivos) — vermelho nas duas
  primeiras corridas pelo bug do `data-conversar` acima, verde na terceira.

## D-94 — O extrato bancário: só Fert$, aberto pelo card do HUD.
**Data:** 2026-07-16 · **Status:** arbitrado pelo usuário · **Feature nova, não do GDD**

Pedido do usuário: clicar no valor ou na palavra "Fert$" do card do HUD abre um extrato das
transações do jogador. Não existia nenhuma tela de extrato para o colono — só o admin tinha essa
visão, na ficha do jogador (`PainelController::jogador()`).

**Escopo confirmado com o usuário: só Fert$, não o ledger inteiro da colônia.** O ledger também
tem uma linha por unidade de recurso movimentada (produção, kit inicial, obras, comércio) — uma
lista bem mais longa e menos "bancária". O filtro é `resource_type IS NULL`, a mesma convenção
que todo lançamento em Fert$ já usa desde sempre (`saldo_inicial`, `estacionamento`,
`venda_mercado`/`compra_mercado`, etc.) — nenhuma coluna nova, nenhuma migration.

### O que mudou no código

- `ProfileController::extrato()` + rota `GET /profile/extrato`: pagina (30 por página, mais
  recente primeiro) o ledger da colônia filtrado por `resource_type IS NULL`, convertendo
  `amount` de micro-Fert$ para Fert$ antes de responder — a mesma conversão que `ColonyController`
  já faz para `colonia.fert`.
- Frontend: `Extrato.tsx` (novo) — popup com a lista, paginação, e um mapa de rótulos humanos por
  tipo de lançamento (`venda_mercado` → "Venda no Mercado"), com fallback para o slug
  humanizado quando um tipo não está no mapa. `App.tsx`: o valor/palavra "Fert$" do card vira
  botão que abre o popup — o resto do card (nome da colônia) continua sem clique, de propósito.
- `tests/Feature/PerfilTest.php` (+4 casos): só Fert$ entra (recurso fica de fora), a conversão
  micro→Fert$, a paginação (mais novo primeiro), e a exigência de colônia.
- `telas.e2e.mjs`: o fluxo completo — clicar no Fert$, ver o saldo inicial traduzido e positivo,
  fechar.
- Validado: 592 testes de backend, `npx tsc -b`/lint/build limpos, e2e completo.

## D-95 — Bugs/Melhorias: o jogador manda, o Governo lê e responde pelo rádio.
**Data:** 2026-07-16 · **Status:** arbitrado pelo usuário · **Feature nova, não do GDD**

Pedido do usuário: um formulário ao lado do Chat para o jogador mandar bugs, sugestões de
melhoria ou dúvidas — com os dados do jogador/colônia/e-mail anexados automaticamente —, e uma
aba nova em `/central/admin` para o Governo ler, responder e marcar como feito. Um card na Visão
Geral avisa quando há mensagem nova.

### Como o jogador fica sabendo da resposta

Confirmado com o usuário: **só pelo rádio** (chat), remetente "Capital" — a mesma conta de
sistema que o D-91 já usa para avisar sobre o Pátio. Nada de tela "minhas mensagens": o jogador
manda, recebe a confirmação de envio na hora, e se o Governo responder, o aviso chega pelo canal
que ele já olha. Isso reusa `EnviarMensagem::sistema()` e `ContaSistema::capital()` sem mudar
nenhum dos dois — a "Capital" já era genérica o bastante para um segundo motivo de falar com o
jogador.

### O instantâneo, não o vínculo ao vivo

`email`/`colony_name`/`nickname` são gravados no momento do envio, não um `join` com
`users`/`colonies` — a mesma razão pela qual `AuditEntry` guarda o "antes": um colono pode trocar
o e-mail ou o nome da colônia depois de escrever, e o ticket tem de continuar dizendo o que era
verdade quando ele mandou. `user_id`/`colony_id` também ficam, para o admin navegar até a ficha
de verdade e para o aviso saber para quem mandar.

### Três estados independentes, não uma máquina de estados

Confirmado pela forma como o usuário pediu ("permita marcar como lida/não lida, responder e uma
opção de registrar como FEITO"): `lida_at`, `respondida_at` e `feito_at` são três timestamps
nulos independentes, não uma progressão linear. Dá para marcar como feito sem ter respondido
(um bug que o admin já sabia e corrigiu sem precisar avisar o jogador específico que mandou), ou
responder sem nunca marcar como feito (uma dúvida, que não tem "feito" nenhum a fazer).

### O que mudou no código

- Migration `2026_07_16_140000_create_feedbacks_table`: tabela `feedback` (singular — `Feedback`
  é um dos substantivos que o Eloquent NÃO pluraliza; a migration original tentou `feedbacks` e
  todos os testes falharam com "no such table" até o nome baterem).
- `App\Models\Feedback` (novo): `TIPOS` (bug/melhoria/duvida/outro), `lida()`/`respondida()`/`feita()`.
- `FeedbackController::store()` + `POST /feedback`: valida e cria, anexando usuário/colônia/e-mail
  do request autenticado — nada disso vem do formulário.
- `PainelController::feedback()`: lista com os mesmos filtros que `noticias()` já usa (busca,
  estado, tipo) — é a mesma forma de problema, uma fila que só cresce.
- `AcoesController::feedbackLida()/feedbackResponder()/feedbackFeito()`: os três alternam ou
  gravam; `feedbackResponder()` é quem chama `EnviarMensagem::sistema()` e marca lida junto —
  seria estranho responder algo que a tela ainda mostra como não lido.
- `resumo()`/`dashboard.blade.php`: `feedback_nao_lido`, um card que só aparece quando há alguma
  mensagem não lida — mesmo princípio do card do Mercado do Governo (D-87), ao lado.
- `admin/feedback.blade.php` (novo) + aba "Bugs/Melhorias" no menu do admin.
- Frontend: `BugsMelhorias.tsx` (novo) — painel flutuante ao lado do Chat no cabeçalho, mesma
  posição fixa que Chat/Missões já usam (mutuamente exclusivos); formulário tipo/assunto/mensagem,
  confirmação de envio, sem histórico de mensagens anteriores (decisão do usuário, ver acima).
- `tests/Feature/FeedbackTest.php` (novo, 9 casos): o jogador manda e os dados batem, validação
  de tipo/tamanho, filtros da lista, os três estados alternando, e o teste que mais importa —
  responder grava, marca lida E manda o aviso pelo rádio com o remetente certo.
- Validado: 597 testes (SQLite e MariaDB 10.5 efêmero em container local), round-trip de
  migrations limpo nos dois, lint, build, e2e completo (inclui o fluxo de envio em `telas.e2e.mjs`).

## D-96 — Economia: Ofertas Globais, Extrato do Governo e Extrato Colonos.
**Data:** 2026-07-16 · **Status:** arbitrado pelo usuário · **Feature nova, não do GDD**

Pedido do usuário: em `/central/admin` → Economia, três abas novas — Ofertas Globais (o livro do
Mercado Central inteiro, todo colono e o Governo, com paginação/filtro/busca), Extrato do Governo
(log das operações financeiras do Governo em Fert$ e recursos) e Extrato Colonos (o mesmo log,
mas de todos os jogadores juntos). "Siga as suas recomendações" — sem perguntas.

### O Tesouro não tinha histórico. Uma tabela nova, não `ledger.colony_id` nullable.

Investigação: `Tesouro` (`app/Domain/Treasury/Tesouro.php`) só guardava o SALDO corrente em
`treasury_holdings` — nenhuma das suas seis operações que mexem no caixa (`distribuir`,
`creditarFert`, `creditarRecurso`, `gastar`, `debitar`, `creditar`) escrevia um lançamento do
lado do Tesouro. `distribuir()` já lançava no `ledger` da colônia DESTINO, mas nunca do lado de
quem mandou.

Duas formas de resolver: (a) imitar o D-87 (que tornou `market_orders.colony_id` nullable para
representar "o Governo") e fazer `ledger.colony_id` aceitar null; (b) uma tabela nova,
`treasury_ledger`, sem dimensão de colônia nenhuma — o Tesouro é um singleton, exatamente como
`treasury_holdings` e `kit_inicial_settings` já são.

**Escolhida (b).** `ledger.colony_id` é `NOT NULL` numa tabela "regra de ouro" (append-only,
`cascadeOnDelete`) já em produção com histórico real — alterar essa constraint ao vivo carrega um
risco de migração que o problema não pede, e o Laravel nem tem `doctrine/dbal` instalado para um
`->nullable()->change()` sem dor. Uma tabela nova isola o risco a zero: se algo saísse errado, é
uma tabela vazia nova, não um `ALTER` numa tabela viva.

### O que mudou no código

- Migration `2026_07_16_150000_create_treasury_ledger_table`: `id`, `type`
  (credito/debito/distribuicao), `amount` (assinado), `resource_type` nullable (null = Fert$,
  mesma convenção do `ledger`), `ref`, `created_at` — sem `updated_at`, sem FK.
- `App\Models\TreasuryLedger` (novo): mesmo guarda append-only do `Ledger` (bloqueia
  `update`/`delete` no `booted()`).
- `Tesouro.php`: cada método que move o caixa (`creditarRecurso`, `creditarFert`, `gastar`,
  `debitar`, `creditar`, e o lado do Tesouro em `distribuir`) ganha um `?string $ref = null`
  opcional e grava no `treasury_ledger` via um novo `lancarTesouro()` privado — espelho do
  `lancar()` que já existia, mas do lado de quem tem o caixa. Todo call site (venda no Mercado,
  tributo de mercado e de transporte, tarifa do Pátio, fabricação/venda de caminhão, frete
  público, compra de Nióbio, oferta do Governo) passa um `$ref` com o contexto — 12 pontos de
  chamada tocados, nenhum quebrado (parâmetro é opcional, comportamento anterior sobrevive).
- `PainelController::economia()`: três `if ($aba === ...)` novos, mesmo padrão `?aba=` das outras
  telas do admin — Ofertas Globais lê `MarketOrder::query()` sem o filtro `aberta/parcial` que a
  aba Mercado usa (aqui é o livro inteiro), Extrato do Governo lê `TreasuryLedger`, Extrato
  Colonos lê `Ledger::query()->with('colony')` — precisou de um `Ledger::colony(): BelongsTo`
  novo, que a classe nunca tinha (só o `colony_id` cru).
- `economia.blade.php`: três seções novas, filtros por texto/tipo/recurso/data, paginação
  (`->paginate(30)->withQueryString()`), mesma convenção visual das abas existentes.
- `tests/Feature/AdminEconomiaExtratosTest.php` (novo, 6 casos).
- Validado: 607 testes de backend (SQLite), migração testada num MariaDB 11 efêmero em container
  local (up + rollback limpos), e2e completo.

## D-97 — Transportes: 3 sub-abas, busca por Dono e ordenação na Frota do Planeta.
**Data:** 2026-07-16 · **Status:** arbitrado pelo usuário · **Feature nova, não do GDD**

Pedido do usuário: em `/central/main` (leia-se `/central/admin`, mesma tela) → Transportes,
separar em abas — Ministério dos Transportes, Garagem do Governo, Frota do Planeta — e na Frota
do Planeta, buscar por Dono e ordenar clicando no cabeçalho da tabela (Placa, Tipo, Dono,
Situação, Conservação, Teto, Manut., Uso).

### A busca e a ordenação por Dono exigem `JOIN`, não `with()`

`Vehicle belongsTo Colony`, mas o "dono" só existe do lado de `colonies.name` — um `orderBy` num
relacionamento Eloquent não alcança SQL nenhum (`with()` resolve depois, em memória, tarde demais
para paginar). `PainelController::transportes()` faz `leftJoin('colonies', ...)` (`leftJoin`, não
`join`: veículo sem dono — a Frota Governamental — tem de continuar aparecendo) e usa
`select('vehicles.*')` para não colidir colunas homônimas entre as duas tabelas.

### A ordenação por cabeçalho é opt-in, não substitui o padrão

Sem `?sort=`, a lista continua ordenada como sempre foi — placa, sem placa por último. Cada
cabeçalho de coluna é um link que, clicado, ordena por aquela coluna; clicado de novo, inverte a
direção. O mapa `coluna slug → coluna SQL` é uma whitelist (`$ordenaveis`), não a string do
usuário direto num `orderBy` — nunca houve intenção de expor SQL injection por querystring, mas a
whitelist também documenta exatamente o que é ordenável.

### O que mudou no código

- `PainelController::transportes()`: `?aba=` com `ministerio`/`garagem`/`frota`, cada um só busca
  os dados que a própria aba precisa (a config do Ministério não carrega a frota inteira, e
  vice-versa). A Frota ganha `?dono=`, `?sort=`, `?dir=`.
- `transportes.blade.php`: nav de abas (mesmo padrão de `economia.blade.php`), cabeçalhos da
  Frota viram links (`$ordenarPor()`), com seta ▲/▼ indicando a coluna e direção atuais.
- `tests/Feature/AdminPainel2Test.php`: os testes de frota que assumiam a página antiga (sem
  `aba`) foram ajustados para `?aba=frota`; +3 casos novos (as três abas existem, busca por dono,
  ordenação por cabeçalho).
- Validado: 604 testes de backend. Sem mudança de schema — sem round-trip de migration.

## D-98 — Visão Geral: separa em abas.
**Data:** 2026-07-16 · **Status:** arbitrado pelo usuário · **Feature nova, não do GDD**

Pedido do usuário: em `/central/main` → Visão Geral, separar por abas também — sem indicar o
agrupamento. Escolha própria: Panorama (os números de topo + os dois cards de alerta — feedback
não lido do D-95, recurso sem oferta do Governo do D-87 — porque é o primeiro lugar que se olha),
Últimos atos, Colônias, Logística (fila de obras + zonas ocupadas).

A página crescia sem parar a cada seção nova que entrava (D-87, D-95...) e empurrava tudo para
baixo da dobra — o mesmo problema que já tinha motivado o D-61 a quebrar o painel inteiro em
telas por seção. Os últimos atos, sem mais o limite de 8 pensado para caber ao lado de tudo mais
numa página só, sobem para 20 — agora tem aba própria, com espaço de sobra.

### O que mudou no código

- `PainelController::dashboard()`: `?aba=` com `panorama`/`atos`/`colonias`/`logistica`, cada
  aba só monta os dados que usa.
- `dashboard.blade.php`: nav de abas; os dois cards de alerta continuam condicionais (só aparecem
  quando há algo a fazer), mas agora só dentro do Panorama.
- `tests/Feature/AdminDashboardAbasTest.php` (novo, 3 casos).
- Validado: 605 testes de backend. Sem mudança de schema.

## D-99 — Missões: 3 sub-abas, categoria Eventuais e visão geral do catálogo.
**Data:** 2026-07-16 · **Status:** arbitrado pelo usuário · **Feature nova, não do GDD**

Pedido do usuário: em `/central/main` → Missões, separar em abas — Missões Catálogo (com uma
visão geral "com o máximo de informações possíveis"), Criar um Molde (com a categoria Eventuais
nova) e O Baralho (este, em sub-abas por categoria).

### A categoria vivia solta em três lugares — agora é uma só

`categoria` de missão não tinha enum nenhum no banco (`string(10)`, sem constraint) e a lista de
valores válidos existia em três lugares independentes: o array `$nomeCategoria` de
`missoes.blade.php`, o `Rule::in(['tutoria', 'diaria', 'semanal'])` de
`AcoesController::validarMissao()`, e nenhum terceiro lugar formal — mas era fácil editar um sem
lembrar do outro. `MissionTemplate::CATEGORIAS` (novo, `slug => rótulo`) é agora a única fonte;
os outros dois passam a ler dali. "Eventuais" (9 caracteres, cabe no `string(10)` sem migration)
é para o que não tem ciclo — evento, sazonal, lançamento — e não entra no sorteio automático
(`Atribuir::tutoria()`/`garantir()` continuam só olhando tutoria/diaria/semanal); um molde
eventual existe para atribuição manual/futura, não para o pool diário.

### A visão geral do catálogo: o que "sorteada: N×" não dizia

Antes, o baralho só mostrava quantas vezes um molde tinha sido sorteado — não como aquilo tinha
terminado. Missões Catálogo (aba nova) agrupa `MissionAssignment` por `template_id` e `status`,
e soma à parte quantas atribuições `ativa` ainda estão dentro do prazo (`scopeAtiva`) contra
quantas já venceram sem ninguém ter voltado a olhar — a diferença entre um molde que funciona
(concluída na maioria) e um que é ignorado.

### O que mudou no código

- `App\Models\MissionTemplate::CATEGORIAS` (novo const).
- `AcoesController::validarMissao()`: `Rule::in(array_keys(MissionTemplate::CATEGORIAS))`.
- `PainelController::missoes()`: `?aba=` com `catalogo`/`criar`/`baralho`; `baralho` ganha
  `?cat=` para a sub-aba de categoria; `catalogo` monta os agregados por status.
- `missoes.blade.php`: nav de abas, sub-nav dentro de O Baralho, tabela nova em Missões Catálogo.
- `tests/Feature/MissoesAdminTest.php`: +4 casos (as três abas existem, "eventuais" é categoria
  válida, o baralho separa por categoria, a visão geral aparece).
- Validado: 611 testes de backend. Sem mudança de schema.

### O item 4 (Chat: link de mensagem privada no card de busca) não precisou de código

Investigação: `Chat.tsx` e `Mapa.tsx` já passavam `aoConversar` para `InfoJogador`, que já
renderiza o botão de conversa direta sempre que `info` e `aoConversar` existem — o pedido já
estava implementado (o link de "Conversar" no popup de busca do Chat já existia). Nenhuma
alteração de código foi necessária; confirmado por leitura direta, não por suposição.

### O batch inteiro

Os cinco itens vieram numa instrução só, com uma ordem explícita: **construir tudo sem
perguntas, publicar tudo só ao final**. Cada item ganhou seu próprio branch/PR (D-96 a D-99,
mais o item 4 sem branch — não havia o que mudar), mas o merge dos quatro branches, o deploy
(`sudo ./tools/deploy.sh`) e este mesmo registro só aconteceram depois que os cinco estavam
prontos — a publicação incremental de D-93/94/95 (a leva anterior) foi a exceção, não a regra
daqui em diante quando o pedido disser isso explicitamente.

## D-100 — Quatro ações novas no catálogo de missões: comprar do Governo, veículo novo e usado.
**Data:** 2026-07-16 · **Status:** arbitrado pelo usuário · **Feature nova, não do GDD**

Pedido do usuário: mais gatilhos para "Ação escutada" em Criar um Molde — "Comprar recursos do
Governo no Mercado Central", "Compre um veículo Novo", "Compre um veículo Usado" e "Venda seu
primeiro veículo".

Cada ação ganhou um gancho real no domínio, não só a entrada no catálogo — sem isto seria a
mesma "missão impossível" que o D-72 já tinha documentado (um molde cuja ação nenhum código
dispara, 0/N para sempre, em silêncio):

- `compra_governo_mercado`: em `ExecutarOrdem`, só quando o vendedor é o Governo (`colony_id`
  nulo), com o mesmo piso de 500 F$ que já protege XP/reputação contra execuções minúsculas.
- `compra_veiculo_novo`: em `ComprarCaminhao`, na compra da prateleira do Ministério.
- `compra_veiculo_usado`: em `MercadoDeUsados::comprar`, no ato da compra.
- `venda_veiculo_usado`: em `MercadoDeUsados::concluirEntrega` — só na ENTREGA, que é quando o
  Fert$ de fato chega ao vendedor (o escrow fica retido até lá).

`App\Domain\Missoes\Acoes::TODAS` ganha as quatro entradas. Validado: `tests/Feature/
MissoesNovasAcoesTest.php` (6 casos), suíte completa (623 testes), sem mudança de schema.

## D-101 — A Frota mostra a placa e permite apelidar o veículo.
**Data:** 2026-07-16 · **Status:** arbitrado pelo usuário · **Feature nova, não do GDD**

Pedido do usuário: em `/frota`, mostrar a placa de cada veículo e permitir dar um nome a ele. A
placa já existia no banco desde o D-60, mas `GET /vehicles` nunca a devolvia — a tela só mostrava
tipo e nível.

Apelido novo: coluna nullable `vehicles.nickname` (migration
`2026_07_16_170000_apelido_do_veiculo`), rota `PATCH /vehicles/{id}/nickname` (confere o dono, 60
caracteres, sem filtro de conteúdo — mesmo desenho do nome da zona, D-67). No frontend, mesmo
padrão de UX que a zona já usa: o campo edita livre, e o "Salvar" só aparece quando o texto muda
do que está gravado; vazio remove o apelido e volta a mostrar só o tipo. Validado:
`tests/Feature/FrotaEnvelheceTest.php` (+6 casos), migração testada num MariaDB efêmero
(up + rollback), e2e completo (8 arquivos, 3ª tentativa limpa — as duas primeiras caíram por
contenção de memória do servidor compartilhado, confirmado pelo `free -h` e não por bug).

## D-102 — O rádio avisa quando uma missão é concluída, e o que ela pagou.
**Data:** 2026-07-17 · **Status:** arbitrado pelo usuário · **Feature nova, não do GDD**

Pedido do usuário: hoje uma missão conclui e paga em silêncio (§06; D-78) — o colono só descobre
se abrir a tela de Missões por conta própria. Mesma lacuna que o D-91 fechou para o Pátio.

Nova conta de sistema "Missões" (`ContaSistema::missoes()`, migration
`2026_07_16_160000_conta_sistema_missoes`), mesmo desenho da "Capital" do D-91: uma conta de
verdade, sem colônia e sem senha utilizável, reservada por migration para fechar a janela em que
um jogador tomaria o nickname primeiro. `Progresso::pagar()` (o mesmo método que credita Fert$,
recurso e XP na conclusão) manda, ao final, uma mensagem privada com o título da missão e o que
foi pago — Fert$, recursos e XP, só o que houver. Excluída de `/estatisticas` (`colonos`) e do
painel de jogadores do admin, junto com a "Capital". Validado:
`tests/Feature/MissoesAvisoRadioTest.php` (3 casos) e a suíte completa (635 testes); sem migração
de schema, só um INSERT de dado — dispensado o round-trip em MariaDB (a "mentira do SQLite" é
sobre DDL, e aqui não há DDL nenhum).

## D-103 — O HUD do jogo vira mobile-first, sem tocar o desktop.
**Data:** 2026-07-17 · **Status:** arbitrado pelo usuário · **Feature nova, não do GDD**

Pedido do usuário: reformatar o visual do jogo para mobile-first. O jogo inteiro era desktop-first
(Tailwind padrão, responsividade esparsa, nenhum padrão de navegação mobile em lugar nenhum do
código) — decidido com o usuário fazer isto tela por tela, e não num "big bang": desktop tem de
continuar idêntico o tempo todo, e o HUD/navegação da colônia (rota `/`) vem primeiro, por ser a
casca em que toda tela do jogo vive. Três PRs sequenciais, cada um validado e no ar antes do
próximo (#28, #29, #30):

1. **O painel flutuante vira mobile-first.** `Chat.tsx`, `Missoes.tsx` e `BugsMelhorias.tsx`
   compartilhavam a mesma janela fixa em pixels (`fixed right-4 bottom-4 w-[340-360px]`) —
   inviável numa tela de 360-430px. Extraído para `painelFlutuante.ts`: base mobile ocupa quase a
   tela inteira (`inset-4`); a partir de `sm:` volta a ser exatamente a janela de canto de sempre.
2. **A navegação em si.** O `<header>` de `App.tsx` — seis botões e dois cartões, absolutamente
   posicionado, pensado pra tela larga — vira `hidden md:flex`, sem nenhuma classe alterada nele.
   `MobileNav.tsx` (novo, `md:hidden`) dá em troca: faixa superior (marca + Fert$) e barra
   inferior fixa de 5 ícones. As duas barras laterais (`Recursos`, `FilaDeObras`+`MinhasZonas`)
   ganham `hidden md:block` — sem isto, sobrepunham a tela toda no mobile assim que o header parou
   de as empurrar visualmente.
3. **Um lugar de volta para Recursos/Fila/Zonas.** `ColoniaSheet.tsx`: um sheet com duas abas
   (Recursos / Obras e zonas), reaproveitando os três componentes das barras laterais do desktop
   sem alteração interna. Na barra mobile, o ícone "Missões" virou "Colônia" (um hexágono, o
   motivo do deck) e Missões entrou no menu "Mais" — no mobile, Recursos/Fila competem por espaço
   e são consultados com mais frequência do que a tela de Missões é aberta.

Achado no meio do trabalho, não previsto no plano original: o `Hud.tsx` do código NÃO é a
navegação — é o conjunto de painéis de construção (`Recursos`, `FilaDeObras`, `Detalhe`,
`SlotVazio`). A navegação de verdade vivia solta dentro de `App.tsx`. E as duas barras laterais
foram escondidas no PR 2 (não no PR 3, como o plano original previa) — a checagem visual mostrou
que, sem isso, elas sobrepunham a colônia inteira no intervalo entre os dois deploys.

Validado a cada PR: `tsc`/`lint`/`build` limpos, checagem visual manual (backend efêmero +
Puppeteer, 1400×900 e 390×844, capturas antes de cada PR), e2e completo — `abrirNavegador()`
(`e2e/comum.mjs`) ganhou um parâmetro de viewport, e `e2e/mobile.e2e.mjs` (novo) roda a suíte
inteira a 390×844. Fora de escopo por ora: toque no canvas do jogo, e qualquer tela alcançada por
rota própria (Mapa, Frota, Capital, Zona...) — cada uma é uma fase futura própria.

## D-104 — O canal Região/Núcleo sai do chat; a aba acende sozinha; construir/evoluir fecham na hora.
**Data:** 2026-07-17 · **Status:** arbitrado pelo usuário · **Feature nova, não do GDD**

Três pedidos do usuário, todos em cima do rádio do planeta e do fluxo de construir/evoluir:

1. **O canal Região sai do chat** — ficam só Global, Vizinhança e Privadas. "Região" (§10.1: 4
   quadrantes + Núcleo, arbitragem do D-77) era 100% um construto do chat, calculado on-the-fly a
   partir de `colony->x/y` (`Regiao::de()`) — não é coluna de banco, não é usado por nenhum outro
   domínio. Removido de `EnviarMensagem::PUBLICOS`, `LerMensagens`, `ChatController::canais()`;
   `Regiao.php` apagado (ficava morto). `PurgarMensagens.php` **não muda** — continua expurgando
   `regiao:*` antigas pelo prazo já publicado (180 dias); as mensagens antigas envelhecem sozinhas,
   sem precisar de limpeza manual.

2. **A aba do Chat acende sozinha.** O selo agregado do botão Chat (`privadas_nao_lidas + mencoes`,
   D-77) continua igual, mas agora `GET /chat/pendencias` também devolve `mencoes_por_canal`
   (`Avisos::pendencias()`, agrupando `chat_mentions` — que já grava o canal real desde sempre —
   por `channel`). Cada aba (Global, Vizinhança, Privadas) ganha um pontinho quando tem novidade.
   Latência aceita de até 30s (o ritmo do poll do HUD): abrir a aba limpa a menção no servidor na
   hora, o pontinho local só acompanha no próximo poll — mesma arbitragem de sempre (polling, não
   websocket, pelo servidor de 4 GB).

3. **Construir/Evoluir fecham o popup na hora do clique** (sucesso ou falha); se a ação falhar
   (recurso insuficiente, fila cheia etc.), o erro aparece num popup novo, por cima — não mais
   inline dentro do card que já fechou. `App.tsx`: `erro` virou `erroConstrucao`;
   `evoluir`/`erguer` chamam `setSelecionada(null)`/`setSlotVazio(null)` **antes** do
   `try`/`await`, não depois. `demolir` fica de fora do fechamento imediato de propósito — é a
   confirmação digitada "DEMOLIR", um fluxo deliberadamente diferente — mas passa a ter erro
   visível pela primeira vez: `Detalhe` nunca mostrava `erro` no ramo de demolição (só no de
   evoluir), então uma demolição que falhasse não avisava nada. Achado no meio do trabalho, corrigido
   de graça pelo mesmo popup novo.

Validado: `ChatTest.php` (+2 casos, -1 removido), suíte completa (636 testes, sem migração — nada
disto mexeu em schema), `tsc`/`lint`/`build` limpos, e2e completo (9/9 verde), checagem visual
manual (backend efêmero + Puppeteer): o pontinho de Vizinhança acende de verdade com uma menção
semeada, e o popup "Não foi possível" apareceu com um erro real de fila cheia depois de fechar o
card de construção.

## D-105 — A navegação vira global: header/barra em todo o site, o botão Colônia substitui o × de cada tela.
**Data:** 2026-07-17 · **Status:** arbitrado pelo usuário · **Feature nova, não do GDD**

Pedido do usuário: o header desktop e a barra inferior mobile — que só existiam na rota `/` (a
colônia) — passam a acompanhar toda tela do jogo (Mapa, Capital, Frota, Perfil, Quartel, Zona,
Ministério, Mercado). Cada uma dessas 8 telas tinha o próprio `×`/voltar; todos saíram, e um botão
**Colônia** novo (entre a marca e Mapa no header; primeiro ícone da barra mobile) é agora o único
caminho de volta. O destaque, antes sempre fixo em "Mapa", passa a seguir a rota atual.

`App.tsx`: o `<header>` (agora extraído para `Header.tsx`, com `useLocation()` próprio para o
destaque) e o `<MobileNav>` saem de dentro do bloco `jogo` (que só rodava nas rotas `/`/`*`) e
passam a envolver `<Routes>` inteiro — junto com os popups que os botões deles abrem
(Chat/Missões/Bugs-Melhorias/Extrato/erro de construção), que também deixam de existir só dentro
da colônia. As 8 telas perderam o prop `aoFechar` e o botão `×`; a navegação **interna** de cada
uma (sub-abas da Capital, do Ministério, do Mercado) ficou intacta — só o fechamento no NÍVEL DA
TELA sumiu. No mobile, o ícone "Colônia" da barra — que abria um sheet de Recursos/Fila/Zonas —
passa a navegar para `/`, como o botão do desktop; o que sobrava do sheet (Fila de obras + Zonas,
já que Recursos sai de vez, ver PR seguinte) virou um item novo dentro de "Mais"
(`ObrasEZonasSheet.tsx`; `ColoniaSheet.tsx` foi apagado).

### Dois bugs achados no caminho, nenhum previsto no plano

1. **Os controles de zoom (`ControlesDeZoom.tsx`, `top-3 right-3`) ficaram atrás do header.** O
   header ganhou `z-[25]` (para ficar acima das 8 telas, que são `z-20`) — mas isso também o pôs
   acima dos controles de zoom (`z-10`), que caem na mesma região da colônia. Resolvido subindo o
   zoom para `z-[26]`.
2. **`aria-label="Capital"` duplicado.** `MobileNav.tsx` já usava `aria-label={rotulo}` no ícone de
   cada destino da barra — inofensivo enquanto a barra só existia em `/`, porque a página nunca
   tinha outro elemento com esse aria-label ao mesmo tempo. Virando global, o ícone "Capital" da
   barra (escondido em telas largas, `md:hidden`, caixa zero) passou a coexistir com o losango
   `aria-label="Capital"` do `Mapa.tsx` — e `[aria-label="Capital"]` pega o primeiro do DOM, não o
   visível. Determinístico, não era flake (100% reproduzido com um script à parte, isolado de
   contenção de memória): o e2e clicava sempre no ícone escondido. Corrigido trocando o
   `aria-label` da barra para "Ir para {rótulo}" — não colide, e é mais descritivo pra leitor de
   tela de quebra.

Validado: `php artisan test` (sem mudança de backend nesta parte, só frontend), `tsc`/`lint`/`build`
limpos, e2e completo (9/9 verde — inclusive `capital.e2e.mjs`, `ministerio.e2e.mjs`,
`mercado.e2e.mjs`, que dependem do losango da Capital), checagem visual manual (backend efêmero +
Puppeteer, desktop 1400×900 e mobile 390×844) em `/`, `/mapa` e `/capital`: header/barra presentes
nas três, destaque seguindo a rota, sem `×` nas telas.

## D-106 — O Depósito Local: 22º slot, e os recursos deixam de ficar sempre visíveis.
**Data:** 2026-07-17 · **Status:** arbitrado pelo usuário · **Feature nova, não do GDD**

Pedido do usuário, item 6 do mesmo lote do D-105: um slot novo (o 21, zero-indexado) em **toda**
colônia — a existente, via backfill, e a futura, na fundação — com uma construção nova, o
**Depósito Local**, dentro. A partir daqui, ver os recursos deixa de ser algo sempre visível na
tela: é preciso abrir o Depósito, tanto no desktop quanto no mobile. Perguntei se a construção
seria uma fixture livre (sem custo, sem evolução) ou uma construção normal; o usuário escolheu
**normal, com custo e caminho de evolução**.

`Slots.php`: `LINHAS = [4, 4, 5, 4, 4, 1]` (a 6ª linha, sozinha, só no final — nenhum slot 0-20
muda de posição), `TOTAL = 22`, `DEPOSITO_LOCAL = ['deposito_local' => 21]`. `livres()` e
`exigirEscolhivel()` excluem esse slot do mesmo jeito que já excluíam o miolo — reservado, não
escolhível pelo colono.

**Custo e tempo: mesma curva da Captação de Água.** O Depósito não está no GDD — mesma família do
D-82 (Indústria Siderúrgica), uma construção nova pega numa já existente com uma regra explícita,
não em números soltos. A Captação de Água é outra essencial, mesma "camada" de infraestrutura
básica; é a proposta default deste PR, e como é só dado semeado (`building_specs.json`), é trivial
ajustar depois se o usuário quiser outra curva.

**Nasce erguido no nível 1, subsidiado pelo Governo — como as cinco essenciais (D-59), mas
separado delas.** `CreateColony.php` cria o Depósito junto do miolo, mesmo padrão de subsídio no
ledger. Mas `Building::ehIndemolivel()` é um método **novo**, deliberadamente separado de
`ESSENCIAIS`: o Depósito não pode ser demolido (como as cinco), mas não é "essencial" no sentido do
GDD — não entra no auto-subsídio ao nível 3 do §24.7, nem ganha o selo "essencial" na UI, porque
nenhuma das duas coisas é verdade para ele.

**Backfill nas colônias que já existem: estendeu o `fertways:slots` já existente**
(`SlotsDaColonia.php`), não um comando novo — o laço "garante que a construção do slot fixo existe
no nível 1, senão cria/promove + subsidia" já fazia exatamente isto para o miolo; bastou passar
`[...Slots::MIOLO, ...Slots::DEPOSITO_LOCAL]`. Mesma disciplina de sempre: idempotente,
`--aplicar`/simulação via rollback.

**`Funcoes.php` ganhou um `efeito` novo: `'mostra'`.** O vocabulário existente (`produz`/
`converte`/`porta`/`nenhum`) não tinha um valor para "não produz nada, só expõe o que já existe" —
usar `'nenhum'` faria o catálogo de slot vazio rotular o Depósito como "efeito ainda não
implementado", o que é falso: o efeito dele é mostrar os recursos.

**Frontend: os recursos saem da barra lateral e entram dentro do popup do Depósito.** `App.tsx`
perdeu de vez o `<Recursos>` fixo (que só existia no desktop); `Hud.tsx`/`Detalhe` ganhou o prop
`colonia` e, quando `spec.type === 'deposito_local'`, renderiza `<Recursos colonia={colonia} />`
dentro do popup — mesmo padrão que `spec.type === 'oficina'` já usava pra `<ReceitaDaOficina>`.
Funciona idêntico em desktop e mobile, porque o clique no hexágono já era o mesmo caminho nos dois.

### O bug achado no caminho: o zoom empurrava a linha do topo pra fora da tela

`ColonyScene.ts`'s `colmeia()` calcula o centro vertical (`meioY`) a partir do número de linhas, e
o zoom (`transformar()`) ancora nesse centro. Recalcular `meioY` contando a 6ª linha nova fazia o
centro descer — e a linha do TOPO (onde mora a Central de Transportes, de todo colono) se deslocar
na tela a cada zoom, mesmo sem ter mudado de posição real nenhuma. `meioY` passou a ser calculado
sempre com as 5 linhas originais (`Math.min(linhas.length, 5)`), nunca com `linhas.length` inteiro
— os 21 slots de sempre não mudam nem um pixel de posição ou zoom; a linha nova só pendura por
baixo deles, sem puxar ninguém.

Validado: `php artisan test` (638 testes — 2 casos novos: o slot do Depósito não é escolhível pelo
colono, e o Depósito não se demole; mais os 3 arquivos com contagem de slot/miolo atualizada de
21/5 para 22/6), sem migração (nenhuma mudança de schema), `tsc`/`lint`/`build` limpos, e2e completo
(9/9 verde — inclusive o zoom-e-clique de `telas.e2e.mjs`, que é exatamente o caminho que o bug do
topo quebraria), checagem visual manual (backend efêmero + Puppeteer, desktop 1400×900 e mobile
390×844): o hexágono 22 aparece na colmeia, abrir o Depósito mostra a lista completa de recursos
nos dois formatos, e o botão "Colônia" da barra mobile navega — não abre mais o sheet antigo.

### Dois problemas achados depois do deploy, nenhum pego pelo e2e nem pela checagem visual

1. **`building_specs` não chega em produção sozinho.** `tools/deploy.sh` roda `migrate --force`,
   nunca `db:seed` — correto, porque reseedar sem cuidado reescreveria dado de partida por cima de
   colônias que já jogaram. Mas a curva de custo/tempo do Depósito Local só existe via
   `BuildingSpecSeeder` (lida de `building_specs.json`), nunca via migration — nenhuma migration
   deste PR mexe em schema. O backfill (`fertways:slots --aplicar`) criou a construção
   `deposito_local` nas 26 colônias reais, mas a tabela `building_specs` da produção nunca ganhou as
   linhas de custo dela — e todo `GET /buildings` (que resolve o catálogo INTEIRO, não só o que a
   colônia já ergueu) começou a estourar `Construção desconhecida: deposito_local`, um 500 que
   derrubava o carregamento do HUD inteiro. Sem schema novo para avisar "rode uma migration", o
   passo ficou fácil de esquecer — corrigido rodando
   `php artisan db:seed --class=BuildingSpecSeeder --force` manualmente (é um `upsert` por
   `building_type`+`level`, seguro de rodar de novo, não mexe em mais nada).
2. **`<Recursos>` ainda vestia a roupa da barra lateral antiga.** `w-64` (256px, fixo),
   `max-h-[calc(100vh-13rem)]` e `overflow-y-auto` faziam sentido quando era um painel flutuante
   sobre a colônia; dentro do popup do Depósito (`max-w-md`, 448px, que já rola a própria altura),
   sobrou uma faixa estreita à esquerda em vez de ocupar a largura toda — achado pelo usuário
   depois do deploy. `Recursos` virou um `<div>` sem essas classes; `Bloco`/`Linha` já usavam
   `w-full`/`justify-between`, então passaram a esticar sozinhos.

Validado (o segundo, o primeiro é operação de banco, não código): `tsc`/`lint`/`build` limpos, e2e
completo (9/9 verde de novo), checagem visual manual (backend efêmero + Puppeteer): a lista de
recursos ocupa a largura inteira do popup, alinhada com o texto acima dela.

---

## D-107 — O lote canônico (`structures.zip`): 68 estruturas, 15 vínculos novos, e o buraco da zona neutra do D-72 quase fecha.
**Data:** 2026-07-17 · **Status:** os evidentes aplicados pelo usuário · **Não há GDD sobre isto**

O usuário mandou um segundo lote de arte, `structures.zip`, com um manifesto (`LISTA_MESTRA_ASSETS_ESTRUTURAS.md`) listando 68 estruturas em 9 pastas. **Ao contrário do D-68/D-72, os nomes de arquivo já são canônicos** (`reator-energia.png`, `deposito-local.png`), não fantasia (`reator-helios.png`) — mas "canônico" não é "vinculável": o mapeamento saiu de cruzar o manifesto com `Vinculaveis::todas()` lido do código (`php artisan tinker --execute='dd(array_keys(...))'`), não de aceitar o texto do manifesto sozinho. **Nenhuma associação é automática** continua valendo (D-68).

### Os números

**136 imagens registradas** (68 estruturas × mestre 1024px + cópia pequena). Das 68:

- **15 ganharam vínculo NOVO** — chaves que não tinham NENHUMA arte antes: `capital:slot:1`
  (Administração Pública — primeiro vínculo do slot 1), `refinaria_quimica`, `laboratorio`,
  `torre_de_defesa`, `quartel`, `destilaria` (progressão da colônia), e **9 da zona neutra**:
  `estrutura_de_extracao`, `deposito_de_zona_neutra`, `abrigo_de_robos`, `muralha_de_perimetro`,
  `refinaria_de_campo`, `central_de_comunicacao`, `plataforma_de_pouso_da_zona`,
  `estacionamento_da_zona`, `cemiterio_de_robos`. **Estas 9 fecham quase todo o buraco que o D-72
  apontou** ("sobrariam ~10 entidades sem NENHUMA imagem candidata: Muralha, Depósito, Refinaria de
  Campo e Cemitério da zona...") — a Muralha, o Depósito, a Refinaria de Campo e o Cemitério
  ganharam arte, e mais cinco que nem chegaram a ser citadas por nome no D-72.

- **28 batem com uma chave EVIDENTE, mas a chave já tinha arte do D-68/D-72.** O comando nunca troca
  um vínculo que já existe (`! ImageBinding::where('entity_key', ...)->exists()`) — de propósito,
  para uma reaplicação nunca derrubar uma escolha manual do painel. Estas 28 ficam registradas na
  biblioteca, prontas para o operador trocar pela miniatura se preferir a arte nova (as 5 essenciais
  da colônia, 6 dos 8 slots nomeados da Capital, 6 dos 7 veículos/unidades, `oficina`,
  `antena_de_comunicacao`, `mercado_local`, `plataforma_de_pouso`, `central_de_transportes`,
  `mina_local`, `tanque_de_combustivel`, `bastiao`, `posto_de_comando`, `torre_de_vigia`, as duas
  áreas nomeadas da Capital — Sul e Oeste).

- **25 ficam SEM vínculo — o jogo não tem onde pendurá-las hoje**, e nenhuma foi forçada:
  - **Sem chave nenhuma no catálogo:** `deposito-local` (é o Depósito Local do D-105/D-106, 22º
    slot — nasce fora de `Building::MVP` e fora de `Vinculaveis`, apesar do nome bater igualzinho
    com o slug do jogo — **conferido, não chutado**: `deposito_local` NÃO está em
    `Vinculaveis::todas()`); `cargueiro-interplanetario` (mesmo caso do `cargueiro-zenith` do D-72:
    espera um Espaçoporto que ainda não é feature); as 8 seções da Endurance (a área Oeste já
    mostra o casco inteiro); `terminal-aduaneiro` e `torre-trafego-orbital` (Espaçoporto, mesma
    razão); `camara-escrow`, `doca-mercado`, `mercado-central` (Mercado e Comércio não tem
    catálogo próprio — e `mercado-central` é ambíguo entre `capital:area:leste` e `mercado_local`,
    os dois já ocupados); 7 de "Especializações da Colônia" que não são `building_type` nenhum
    (Estufa Bioluminescente, Aquífero Profundo, Torre Geotérmica, Complexo Metalúrgico, Terminal de
    Cargas, Observatório, Salão de Negociações — `Complexo Metalúrgico` chegou a ser cotado contra
    `industria_siderurgica`, mas o nome não bate o bastante para confiar).
  - **Ambíguo demais para decidir sozinho:** `patio-logistico` (o slot 6 não existe como slot
    próprio — é metade da área Leste, "Mercado Central + Pátio Logístico, juntos", D-63/D-65, já
    ocupada por `mercado-aurora` desde o D-72); `fortim-defesa` e `centro-cerco` (o manifesto os
    trata como estruturas à parte, mas o jogo só tem `bastiao` e `abrigo_de_robos` para esse tipo de
    coisa, e as duas já foram reivindicadas por "Bastião" e "Abrigo de Robôs Mineradores" — chutar
    qual seria a arte de qual poria a estrutura errada num prédio).

### O que se aprendeu construindo

> **Um nome idêntico ao slug do jogo não é garantia de chave.** A instrução chegou com
> `deposito-local → deposito_local` como exemplo de "batida evidente". A leitura do código mostrou
> o contrário: `deposito_local` existe no jogo (D-105/D-106), mas fora de `Building::MVP` e fora de
> `Vinculaveis` — nunca passou pelo catálogo que o painel de imagens entende. Só a leitura do código
> pegou isso; o nome sozinho teria enganado.

> **Duas leves de arte podem colidir pelo nome, sem avisar.** Seis arquivos de "Destroços da
> Endurance" deste lote (`anel-habitacional-endurance.png` e mais cinco) têm o MESMO nome de
> arquivo que imagens já na biblioteca desde o D-72 — mas o CONTEÚDO é diferente (`md5sum`
> confirmou). `cp -n` (sem sobrescrever) evitou destruir a arte antiga silenciosamente, mas também
> deixou a arte nova de fora — nenhuma das duas tem vínculo de qualquer forma (as seções da
> Endurance continuam sem lar), então o custo do não-vínculo foi baixo, mas a decisão de qual das
> duas manter fica em aberto para o usuário.

> **O guarda contra reaplicação também esconde a "vitória".** Rodar o comando de novo com um lote
> novo nunca troca uma arte já vinculada — então 28 das 68 estruturas deste lote entraram na
> biblioteca sem qualquer efeito visível na colônia. Isso é a defesa certa (nenhum vínculo manual
> do painel se perde num `--aplicar` de rotina), mas significa que "vinculada" no relatório do
> comando não quer dizer "está na tela agora" — quer dizer "criou um `ImageBinding` novo", e é
> preciso separar as duas coisas ao reportar para quem pediu.

---

## D-108 — Gestão de Construções: tempo, Silo e custo deixam de ser fixos no GDD.
**Data:** 2026-07-17 · **Status:** arbitrado pelo usuário · **Feature nova, não do GDD**

Pedido do usuário: uma aba nova no Admin, **Gestão de Construções**, com três sub-abas — Tempo de
Construções, Gestão do Silo, e Custo de Construção e Evolução — para tirar do código números que
hoje só mudam com deploy.

**O Silo é o Depósito Local (D-105/106), estendido** — mesmo slot 21, mesmo botão. Perguntei ao
usuário duas coisas antes de desenhar: se "Silo" era uma construção nova ou o Depósito Local
estendido (é o Depósito Local — nível máximo passa de 5 para 10, o range que o usuário deu); e se
"poderão ser saqueados" (os recursos que excedem a capacidade) significava construir o saque de
colônia agora, ou só a regra/dado desta vez. **Só a regra/dado**: a guerra hoje (`Atacar`/
`Combat`/`Unit`) mira exclusivamente Zona Neutra — `combats.zone_id` é obrigatório, e não há
nenhum caminho de código que ataque uma colônia. Estender isso é, por si, um trabalho do tamanho
do sistema de guerra que já existe; fica para uma entrega separada, já combinada.

### O desenho

1. **`building_specs_overrides`** (migration nova, tabela vazia): o ajuste do admin em tempo/custo
   por `(building_type, level)`. `BuildingSpecs::para()` aplica o override por cima da base do GDD
   quando existir. **O motivo de ser uma tabela separada, não editar `building_specs` direto**: o
   `BuildingSpecSeeder` roda `upsert` incondicional a cada `db:seed` — e isso acontece de verdade,
   foi preciso rodar manualmente ao publicar o Depósito Local (D-106). Editar a base seria perder o
   ajuste no primeiro reseed, calado. Mesmo problema que o kit inicial já resolveu (D-92), mesma
   solução: uma tabela que só o admin toca. `nivelMaximo()` continua lendo só a base — quantos
   níveis uma construção TEM é fato estrutural, não algo que o admin adiciona por aqui.

2. **`silo_capacidades`** (migration nova, com o dado de partida gravado nela mesma — mesmo molde
   do `kit_inicial_recursos`, D-92): quanto de cada recurso cabe protegido, por nível do Silo.
   Padrão pedido pelo usuário: nível 1 a 10, 10.000 de cada um dos 26 recursos, igual em todos os
   níveis — 260 linhas, ele ajusta pelo próprio painel.

3. **`Silo` (domínio novo)**: `nivel()`/`capacidade()`/`protegido()`/`exposto()`, molde de
   `Protegido` (D-66) — a mesma pergunta que a guerra de Zona Neutra já resolve, só que por
   RECURSO (a zona tem um Depósito único somando tudo; a colônia já é uma linha por recurso).
   Calculado **sob demanda**, não gravado em `resources.storage_cap` (que fecha o D-14, mas
   continua `NULL` de propósito) — gravar exigiria recalcular toda vez que o Depósito Local evolui;
   sob demanda é sempre certo. **Não conectado a nenhuma tela ou endpoint nesta entrega** — só a
   regra e o dado, prontos para quando o saque de colônia existir.

4. **Depósito Local vai a nível 10.** `building_specs.json`: níveis 6-10 acrescentados com a MESMA
   fórmula que todo o resto do jogo já segue (custo ×1,65/nível half-up, tempo ×1,5/nível
   half-even, a partir da base do nível 1 — 4,9 min, a mesma da Captação de Água) — não é número
   inventado, `tests/Gdd/GddSpecsTest.php` valida os níveis novos de graça, sem mudar o teste.

5. **A aba** (`resources/views/admin/construcoes.blade.php`) segue os padrões já estabelecidos:
   sub-abas por `?aba=` (mesmo de `missoes.blade.php`), lista com "Editar" por linha revelando os
   níveis (mesmo de `missoes.blade.php`/baralho), grade de números para o Silo (mesmo de
   `operacao.blade.php`/kit inicial), textarea `recurso:quantidade` para custo (mesmo parser de
   `recompensa_recursos`). O nível 1 das seis que já nascem prontas (5 essenciais + Depósito
   Local — `Building::NASCE_NO_NIVEL_UM`, constante nova) fica de fora da lista, e o servidor
   recusa um POST forjado pra ele — validação não é só esconder campo na tela.

### Fora de escopo, de propósito

- A mecânica de saque em si (unidades, UI de ataque, defesa, notificação) — decisão já tomada com
  o usuário, entrega separada.
- Qualquer UI pro jogador ver "protegido/exposto" — o domínio `Silo` fica pronto, sem tela ainda.
- Renomear `deposito_local` pra `silo` no banco ou no jogo — o nome "Silo" vive só no título da
  sub-aba do admin; é trivial renomear depois, é só rótulo.

Validado: `php artisan test` completo (649 testes — 11 novos, o mais importante prova que rodar
`BuildingSpecSeeder` de novo depois de um ajuste do admin NÃO apaga o ajuste, o cerne do desenho),
`GddSpecsTest` segue verde sem alteração (valida os níveis novos do Depósito Local de graça),
round-trip de migração em MariaDB efêmero (`migrate:fresh` + `migrate:rollback`, duas tabelas
novas), checagem visual manual (backend efêmero + Puppeteer, sessão de admin): as três sub-abas
abrem, salvar um ajuste de tempo, um de custo e uma célula do Silo persiste depois de recarregar a
página.

**Passo obrigatório depois do deploy**: rodar `php artisan db:seed --class=BuildingSpecSeeder
--force` em produção — os níveis 6-10 do Depósito Local só existem de verdade depois disso (mesma
lição do D-106, desta vez feito de propósito, não esquecido).

## D-109 — O Furgão ganha fábrica, a Fábrica ganha admin, o despacho vazio ganha zona neutra, e a compra ganha um recibo.
**Data:** 2026-07-18 · **Status:** arbitrado pelo usuário · **Feature nova, não do GDD**

Pedido do usuário, quatro itens:

1. O Ministério dos Transportes passa a fabricar **Furgão de Comércio** também (antes, só
   Caminhão de Carga) — vendido a **150 Fert$**; custo de fabricação e tempo = **40% do
   Caminhão**; prateleira inicial de **5**.
2. Uma aba nova **Fábrica** em `/central/admin/transportes`: configurar o estoque-alvo, pedir
   fabricação avulsa, o preço de venda e o custo em recursos — por tipo.
3. Em Mercado Central → Pátio e Depósito: despachar um veículo VAZIO — do Pátio, para casa ou
   para uma zona neutra sua; de casa, para a Capital ou para uma zona neutra sua. Substitui o
   "Chamar de volta" (D-91), que só cobria pátio→casa.
4. Em Ofertas Globais, ao comprar, um modal confirma a compra e que o recurso está no depósito da
   Capital.

Perguntei o que acontece quando um veículo vazio chega a uma zona neutra — hoje não existia
"estacionado numa zona", as viagens à zona eram sempre ida-e-volta automática. Resposta: **fica
estacionado lá de verdade**, um terceiro lugar (`Vehicle::NA_ZONA = 'zona'`), ao lado de casa e do
Pátio.

### 1 e 2 — A fábrica generaliza por tipo, e vira admin-editável

`Ministerio::PRECO_MICRO`/`ESTOQUE_ALVO`/`MINUTOS_FABRICACAO`/`custoFabricacao()` (constantes de
PHP) viram `Ministerio::config(tipo)`, lendo `fabrica_veiculos` — tabela nova, semeada uma vez na
própria migration (mesmo molde do kit inicial, D-92; do Silo, D-107/108), nunca tocada por
Seeder. Duas linhas de partida: Caminhão (os números que já existiam, só migram de PHP para
banco) e Furgão (150 Fert$, 5 na prateleira, 24 min, custo 36 Ligas/10 Componentes/6 Metal Bruto —
40% do Caminhão, arredondado, minha proposta default).

**Achado no caminho, e por que não reaproveitei um número que já existia**:
`VeiculoCustos::NIVEL_1['furgao_de_comercio']` (40 Ligas/10 Componentes/7 Metal Bruto) já existe —
mas é o custo-base do GDD (§21.2), usado só por `Manutencao.php` para calcular o custo de
MANUTENÇÃO do Furgão (10% dessa tabela), valendo desde sempre para todo Furgão do kit inicial.
Tocar essa tabela mudaria a manutenção de toda a frota existente, e ninguém pediu isso — o custo de
FABRICAÇÃO do governo é um número novo e separado (40% do Caminhão), só usado pela fábrica.

`FabricarCaminhoes.php` virou **`FabricarVeiculos.php`** (o nome antigo mentiria); `ComprarCaminhao.php`
virou **`ComprarVeiculo.php`** — os dois generalizados por tipo, mesma lógica de sempre (Tesouro
paga o custo; sem saldo, sem veículo, sem erro, sem fila).

**Efeito colateral corrigido, não pedido mas descoberto no caminho**: `Conservacao::tetoDeRevendaMicro()`
usava, para o Furgão, uma referência do operador (`furgao_preco_referencia_micro`, D-73) — porque
até aqui o Ministério não o vendia, não havia preço de fábrica. Agora há. O teto do Furgão passa a
ancorar no preço de fábrica dele, igual ao Caminhão; a referência antiga fica no schema (não vale
uma migration só para tirá-la) mas não é mais lida.

Admin: aba **Fábrica** nova em `transportes.blade.php` (`?aba=fabrica`, mesmo padrão `?aba=` de
`missoes.blade.php`/`construcoes.blade.php`), um cartão por tipo — preço, estoque-alvo, minutos,
custo (textarea `recurso:quantidade`, mesmo parser de sempre) — e um botão de encomenda avulsa
(empurrão pontual fora do tick, não muda o estoque-alvo).

### 3 — O despacho vazio ganha um terceiro lugar: a zona neutra

`Vehicle::NA_ZONA = 'zona'` — `local` é `string(10)`, não enum, então o valor novo não pediu
migração de schema. Estacionado lá, `destination_type`/`destination_id` **não se limpam** — a
mesma dupla de colunas que guardava "para onde vai" passa a dizer "em qual zona está"
(`ConcluirTrechos::terminarViagem()`).

`DespacharVeiculo::reposicionarVazio()` (novo, ao lado de `handle()`/`retirarDeZona()`/
`entregarMaterialNaZona()`): do Pátio, casa ou zona sua; de casa, Capital ou zona sua; de uma
zona sua, **só de volta para casa** — reaproveita a mesma distância zona↔colônia que o ciclo de
ida-e-volta já usa para a perna de volta, não é conta nova. Ir de uma zona para a Capital ou para
outra zona fica de fora **de propósito**: ninguém pediu, e essa distância nunca foi calculada em
lugar nenhum do jogo — sem isso, "voltar pra casa" é sempre a válvula de escape, nenhum veículo
fica preso.

`VehicleController::despachar` passou a rotear: carga vazia vai para `reposicionarVazio()`, carga
de verdade continua em `handle()` — o mesmo endpoint de sempre, `POST /vehicles/{v}/dispatch`.

Frontend (`Mercado.tsx`): o botão único "Chamar de volta" virou um seletor de destino
(`SeletorDeDestino`), reaproveitado nas três listas do Pátio e Depósito — "No Pátio", "Em casa", e
uma seção nova "Numa zona neutra" (senão um veículo lá estacionado ficaria invisível na tela). As
opções vêm de `api.minhasZonas()`, que já existia.

### 4 — O recibo da compra

Puramente frontend: `Popup.tsx` (já genérico, usado em outros 4 lugares) confirma a compra em
Ofertas Globais, só do lado de quem COMPRA (quem vende já vê o Fert$ entrar, sem mistério).

## Fora de escopo, de propósito

- Despachar de uma zona neutra para a Capital ou para outra zona.
- Nível 2+ de fabricação de qualquer um dos dois veículos (D-60 já flagrou as duas curvas
  divergentes do GDD para o Caminhão nível 2+ — sem decisão tomada).
- Remover a coluna `furgao_preco_referencia_micro` do schema — fica inerte, sem migration.

Validado: `php artisan test` completo (665 testes — 12 novos em `DespachoVazioTest`, mais ajustes
em `MinisterioDosTransportesTest`/`MissoesNovasAcoesTest`/`FrotaEnvelheceTest` para o novo shape
por tipo), round-trip de migração em MariaDB efêmero (`migrate:fresh` + `migrate:rollback`, uma
tabela nova), `tsc`/`lint`/`build` limpos, e2e completo (9/9 verde — `capital.e2e.mjs` precisou de
um ajuste: o teto de revenda do Furgão usado passou de 60 para 150 Fert$, reflexo direto do preço
de fábrica novo), checagem visual manual (backend efêmero + Puppeteer): a aba Fábrica do admin com
os dois tipos, salvar um ajuste refletindo na hora na tela do jogador, comprar um Furgão, e o
seletor de destino no Pátio e Depósito com "Capital" e a zona sua listadas.

## D-110 — Ícones de recurso, cards em Ofertas Globais, e a contagem sobreposta no mobile.
**Data:** 2026-07-18 · **Status:** arbitrado pelo usuário · **Feature nova, não do GDD**

Pedido do usuário, três itens de UI:

1. Ícones diferentes ao lado de COMPRA/VENDE, e um ícone por recurso — reaproveitado no depósito
   central e no depósito da colônia, além de Ofertas Globais.
2. Em Ofertas Globais, as ofertas viram cards lado a lado no desktop; no mobile continuam em lista.
3. No mobile, o tempo até uma construção ficar pronta aparece sobreposto à imagem dela, na colmeia.

### 1 — Selos de recurso, não desenhos

Não existia nenhum sistema de ícone pra recurso no jogo — a arte de construção (D-68) é ilustração
grande, carregada em runtime pro canvas do Phaser, o formato errado pra 26 selos pequenos dentro de
listas de DOM. Desenhar 26 ícones à mão também não caberia nesta entrega. Em vez disso: um selo
colorido pequeno com uma sigla — 8 dos 12 industriais usam o **símbolo químico real do elemento**
(Al, Cu, Sn, Li, Au, Si, Ta, W), a leitura mais rápida que existe pra quem já viu uma tabela
periódica. A cor do selo é a **classe** do recurso (primário/industrial/raro, §8.3 — a mesma classe
que já rege tributo e teto do depósito), só com as cores que já existem no jogo (rust/ember/ink),
sem inventar paleta nova.

`IconeRecurso.tsx` (novo): o selo, mais `IconeCompra`/`IconeVende` — duas setas SVG inline, molde de
`MobileNav.tsx` (`currentColor`, sem cor própria — o rótulo ao lado já é neutro de propósito, ver o
comentário original de `LinhaDaVitrine`, e colorir a seta de verde/vermelho sugeriria uma ação que
nem sempre é a de quem lê). Aplicado em `Hud.tsx` (`Linha`, o depósito da colônia), `Mercado.tsx`
(o depósito da Capital, e `LinhaDaVitrine` em Ofertas Globais).

### 2 — Cards em grade, sem markup duplicado

`OfertasGlobais`: o container passou de `space-y-2` (lista vertical sempre) para
`grid gap-2 md:grid-cols-2 lg:grid-cols-3` — o MESMO idioma que todo o resto do jogo já usa pra
"cards no desktop, lista no mobile" (`PatioEDeposito`, `Acordos.tsx`, as páginas do site): um grid
sem colunas explícitas colapsa sozinho a uma coluna em telas estreitas, sem precisar de duas árvores
de JSX. `LinhaDaVitrine` ganhou `h-full flex flex-col justify-between` pra cards da mesma fileira
ficarem com a mesma altura.

### 3 — A contagem no mobile: achado no caminho, um dado que faltava expor

`Spec` (o tipo que descreve cada hexágono) não carregava `finishes_at` — só `ItemDaFila` (a fila da
barra lateral) tinha isso, indexado por TIPO de construção, não por slot. Correlacionar por tipo
seria frágil (duas Minas em obra ao mesmo tempo confundiriam qual é qual). A solução certa estava
mais perto: `buildings.upgrade_finish_at` já existe desde sempre (`EnqueueUpgrade.php` grava lá
quando o item começa a construir de verdade, não quando só entra na fila) — só faltava
`BuildingController::specs()` expô-lo. Uma linha nova (`'finishes_at' => $b->upgrade_finish_at?->...`)
resolve por SLOT, sem ambiguidade nenhuma, e sem tocar em `ConcluirTrechos`/`EnqueueUpgrade`.

`ColonyCanvas.tsx`: um `<span>` absolutamente posicionado sobre cada hexágono em obra, `md:hidden`
(só mobile — no desktop a barra lateral já mostra o relógio, duplicar poluiria), `pointer-events-none`
(o clique continua sendo do botão invisível por baixo dela), reaproveitando `relogio()`/
`segundosRestantes()` que já existem em `recursos.ts`. Não precisou de um intervalo novo: o `tique`
que já roda em `App.tsx` a cada segundo já refaz esta conta de graça.

Validado: `php artisan test` completo (665, sem regressão — a mudança em `BuildingController.php` é
só um campo novo, aditivo), `tsc`/`lint`/`build` limpos, e2e completo (9/9 verde), checagem visual
manual (backend efêmero + Puppeteer, desktop 1400×900 e mobile 390×844): selos nos dois depósitos e
em Ofertas Globais, cards lado a lado no desktop e lista no mobile, e a contagem "15:40" sobreposta
ao hexágono da Oficina em obra no mobile.

## D-111 — A fila vira admin-editável: a da colônia, e a da zona neutra, que ganha teto de verdade.
**Data:** 2026-07-18 · **Status:** arbitrado pelo usuário · **Feature nova, não do GDD**

Pedido do usuário: "A Colônia tem sua fila de construção, mas cada zona neutra tem sua fila também,
não compartilham a mesma fila" — e uma sub-aba **Fila** em Gestão de Construções pra definir quantos
itens cabem em cada uma.

**A colônia já tinha fila** (D-13) — a mudança é só tirar o número de dentro do PHP.
`BuildQueue::vagasDe()` tinha 2 (conta nova, 5 primeiros dias) / 1 (conta padrão) cravados no
código, com o comentário explícito "persistir o limite criaria um estado que envelhece errado" — o
que continua verdade **pra regra dos 5 dias**, não pro NÚMERO de vagas. `FilaSetting` (nova,
singleton, molde de `TransportSetting`) guarda os dois números; o prazo de 5 dias continua fixo,
ninguém pediu pra mexer nele.

**A zona neutra não tinha fila — tinha um canteiro só.** `NeutralZone::obraEmCurso()` era uma
EXISTÊNCIA booleana ("alguma obra em curso?"), não uma contagem contra um teto — o próprio código
já dizia isso: "a zona não é a colônia, que tem fila: aqui é um canteiro só" (D-67). Virou uma
contagem de verdade: `ConstruirNaZona::handle()` agora compara `$zona->obras()->count()` contra
`FilaSetting::singleton()->zona_vagas` (padrão 1 — o comportamento de sempre; subir o número é o
que muda algo). Diferente da fila da colônia, a zona **não tem "queued esperando o antecessor"**: o
material só entra no canteiro por entrega física (D-67), então cada obra com material pronto começa
na hora, com o seu próprio relógio — o teto aqui é "quantas em curso ao mesmo tempo", não uma fila
sequencial. `obraEmCurso()` (o booleano) fica intocado — ainda é usado, ainda significa a mesma
coisa, só não é mais o que barra a segunda obra.

Admin: sub-aba **Fila** (`/central/admin/construcoes?aba=fila`), dois cartões — Colônia (as duas
vagas, com a nota do prazo fixo de 5 dias) e Zona neutra (o teto de obras simultâneas) — dois
formulários, uma ação só (`construcoesFila`), cada formulário carrega os campos do outro num
`<input type=hidden>` pra não zerar o que não está editando.

Validado: `php artisan test` completo (672 — 7 novos: 6 em `FilaAdminTest`, mais 1 em
`ZonaLugarTest` provando que subir `zona_vagas` deixa duas obras em curso ao mesmo tempo, cada uma
com o seu relógio, e a terceira ainda estoura o teto), os testes que já existiam
(`BuildQueueTest`/`ZonaLugarTest`/`SlotsDaColoniaTest`) continuam verdes SEM alteração — os
`default()` da migration reproduzem o 2/1/1 de sempre. Round-trip de migração em MariaDB efêmero,
`tsc`/`lint`/`build` (sem mudança de frontend nesta entrega), e2e completo (9/9 verde), checagem
visual manual (backend efêmero + Puppeteer): os dois cartões da aba Fila, salvando cada um
independente do outro.

## D-112 — Manutenção de estruturas: consumo extra de recursos por hora, por construção, admin-editável.
**Data:** 2026-07-18 · **Status:** arbitrado pelo usuário · **Feature nova, não do GDD**

Pedido do usuário: uma sub-aba **Manutenção** em Gestão de Construções, definindo "quanto de
energia, luz, oxigênio, etc.. (acho que podemos deixar para escolher entre todos recursos
primários e industriais aqui), consome por hora" por construção.

**Aditiva, nunca substitui** `energia_consumo_hora` (GDD, §19.x). O consumo de energia continua
exatamente como sempre foi calculado — é balanceamento comprovado, e ninguém pediu para mexer nele.
`manutencao_estruturas` (tabela nova, vazia por padrão, mesmo molde de `building_specs_overrides` —
D-107/108: nunca tocada por Seeder) é um consumo A MAIS, por cima, ligado por escolha do operador.
Enquanto vazia, nenhuma construção consome nada além do que já consumia — os testes que já
existiam (toda a suíte de `TickColoniesTest`) continuam verdes sem alteração.

**Por TIPO de construção, não por tipo+nível** — diferente do custo/tempo
(`building_specs_overrides`, que é por `(tipo, nível)`). O usuário não pediu granularidade por
nível, e uma grade de 38 construções × até 10 níveis × 17 recursos não caberia numa tela
administrável. Uma taxa só por tipo é a extensão natural do pedido ("quanto consome por hora"),
soma linearmente entre cópias da mesma construção (Mina Local, Oficina, Refinaria, Destilaria,
Indústria Siderúrgica — as repetíveis, D-59) do mesmo jeito que a produção já soma.

**Só primário e industrial** — o usuário deixou em aberto ("acho que podemos") e a decisão foi
excluir os raros: são 9 recursos de minério puro, cuja escassez já é o próprio mecanismo de jogo
(§22.4); um consumo passivo neles mudaria esse balanço sem que o usuário tivesse pedido
especificamente. `ResourceType.tax_class` (`primario`/`secundario`/`raro`, já existente desde a
criação do catálogo) faz a checagem — sem tabela nova.

`ColonyTick::produzir()`: um novo `manutencaoPorTipo()`, batched numa query só pros tipos de
construção presentes na colônia (mesmo padrão de `erguidasComSpec()`, evitando N+1 dentro do loop
principal), soma um `$consumosExtras` por recurso, e no fim é subtraído de `$taxas` do mesmo jeito
que `$taxas['energia'] -= $consumoEnergia` já fazia — só que agora para QUALQUER recurso, não só
energia. `acumular()` já travava o estoque em zero (D-20) para qualquer recurso, não só energia —
zero mudança ali, o comportamento generaliza de graça.

Admin: sub-aba **Manutenção** (`/central/admin/construcoes?aba=manutencao`), agrupada nas mesmas
quatro categorias que Tempo/Custo já usam (as cinco essenciais, progressão, zona neutra, veículos e
unidades — a definição foi extraída para `definicaoDeGrupos()`, reaproveitada pelas três abas). Uma
textarea `recurso:quantidade` por construção, mesmo formato usado em Custo e nas recompensas de
missão — salvar substitui o conjunto inteiro daquele tipo (`DB::transaction` com delete + insert),
então apagar uma linha remove aquele consumo.

Validado: `php artisan test` completo (685 — 13 novos: 6 em `ManutencaoEstruturasTest` provando o
efeito no tick — aditivo sobre energia, soma entre cópias da mesma construção, nunca fica negativo,
não afeta tipo não configurado —, mais 7 em `ManutencaoAdminTest` cobrindo o CRUD, a substituição
total ao salvar, a rejeição de raro e de tipo inexistente). Round-trip de migração em MariaDB
efêmero (fresh + rollback + migrate), suíte completa também verde contra MariaDB. `tsc`/`lint`/
`build` não se aplicam (sem mudança de frontend nesta entrega). E2E completo (9/9 verde). Checagem
visual manual (backend efêmero SQLite + Puppeteer): a aba Manutenção lista as 38 construções nas
quatro categorias, o editor abre com a textarea e a lista de recursos aceitos, salvar
`energia:5`/`agua:2` no Laboratório e a contagem "2" aparece na lista ao recarregar.

## D-113 — "Enviar Recursos" vira "Subsídios": vários recursos de uma vez, e um modo pra todos os colonos.
**Data:** 2026-07-18 · **Status:** arbitrado pelo usuário · **Feature nova, não do GDD**

Pedido do usuário: em `central/admin/economia?aba=enviar`, trocar ENVIAR RECURSOS por SUBSÍDIOS, com
dois modos — mandar pra um colono (escolhe a colônia, abre a lista inteira do catálogo para marcar
quanto de cada) ou mandar para todos os colonos (escolhe os recursos e quanto de cada, aplicado a
todo mundo).

**O antigo formulário mandava um recurso por vez** — `AcoesController::distribuir()`, `recurso` +
`quantidade` singulares, POST por recurso. Trocado por dois novos: `subsidioColono()` (a lista
inteira do catálogo + Fert$ num formulário só, mesmo padrão `qtd[{{code}}]` que a aba Mercado já
usa para a vitrine do Governo) e `subsidioTodos()` (a mesma cesta, para cada colônia fundada). O
antigo `admin.tesouro.distribuir` foi removido — não ficou como alternativa paralela, porque o
pedido foi trocar o fluxo, não somar um novo ao lado do velho. `Tesouro::distribuir()` (o método de
domínio, uma transação por recurso-colônia) continua exatamente como estava — é a peça que os dois
novos endpoints chamam em loop.

**Todo-ou-nada nos dois modos, por razões diferentes:**
- **Um colono, vários recursos:** os `Tesouro::distribuir()` (cada um já transacional) vivem dentro
  de UMA transação externa. Sem isto, se o Tesouro tivesse Ligas mas não Água, o colono receberia só
  metade do que o operador mandou — uma entrega parcial e silenciosa que o operador não pediu.
- **Todos os colonos, mesma cesta:** antes de tocar em qualquer colônia, `Tesouro::comporta()`
  confere o custo AGREGADO (quantidade × nº de colônias). Sem essa conferência prévia, a entrega
  pararia no meio da lista — algumas colônias receberiam o subsídio, outras não, por causa da ORDEM
  em que `Colony::orderBy('id')` as devolve, o que seria arbitrário e injusto entre colonos.
  `comporta()` já existia (D-60, usado pela fábrica de veículos) — reaproveitado, não inventado.

**Recurso fora do catálogo nos dois modos: ignorado, não rejeita a requisição inteira** — mesma
cautela de `construcoesSilo`/`fabricaConfig` contra um `<input>` forjado; um nome de recurso que não
bate com nada do catálogo simplesmente não gera entrega nenhuma, sem atrapalhar os outros que vieram
certos no mesmo POST.

Admin: sub-aba **Subsídios** (`/central/admin/economia?aba=subsidios` — o slug também mudou, de
`enviar` para `subsidios`), dois cartões alternados por rádio (mesmo padrão de mostrar/esconder já
usado em Gestão de Construções), cada um com a tabela do catálogo inteiro (Fert$ + todos os
recursos) e um campo de quantidade por linha.

Validado: `php artisan test` completo (695 — 10 novos em `SubsidiosAdminTest`: multi-recurso pra um
colono, Fert$ junto com recurso, todo-ou-nada quando um recurso não cabe, recurso fora do catálogo
ignorado, sem colônias fundadas recusado, sem saldo agregado recusado antes de tocar em qualquer
colônia, autenticação exigida, a aba mostra os dois modos — mais os três testes que já existiam e
apontavam para o antigo `admin.tesouro.distribuir`, adaptados para o novo `subsidio_colono`), suíte
completa também verde contra MariaDB efêmero (sem migração nesta entrega — nenhuma tabela nova).
`tsc`/`lint`/`build` não se aplicam (sem mudança de frontend). E2E completo (9/9 verde). Checagem
visual manual (backend efêmero SQLite + Puppeteer): os dois modos alternam por rádio, enviar
50 Água + 20 Ligas Metálicas a uma colônia confirma na mensagem, enviar 10 Água "a todos" confirma
contando as colônias fundadas.

---

## D-114 — Federação (§04/§07): o núcleo — cargos, convite/pedido, fundo por entrega física.
**Data:** 2026-07-19 · **Status:** arbitrado pelo usuário, Fatia 1 · **GDD com contradição interna**

Depois de fechar o lote de transportes/recursos (D-109 a D-113), perguntei ao usuário qual frente
do GDD ainda em aberto atacar — as opções eram Federação, Leilões e Ranking de Guerras (§27.13),
todas sem sistema nenhum no jogo hoje. Ele escolheu **Federação**.

### O GDD se contradiz, e a divergência é genuína — duas arbitragens do usuário

O documento tem uma tabela v3.0 (§04 "Sistema de Federações") e uma revisão v3.2 marcada "regra
definitiva" (dentro de §07 "Comércio entre colonos e federações") que discordam em dois pontos:

1. **Desconto de tributo entre aliados.** v3.0: "50% de desconto nos tributos entre aliadas". v3.2:
   alianças **não** concedem desconto automático — tratados criam obrigações e janelas de defesa,
   não descontos. O índice de status do documento aponta pra v3.2 ("tributação única prevalece").
   **O usuário escolheu a v3.0**: 50% de desconto entre aliados. Fica registrado para uma fatia
   futura — a Fatia 1 não mexe em tributação nenhuma de entrega; a contribuição ao fundo é
   tributada NORMALMENTE (100%), deixando o terreno pronto para o desconto entrar depois sem
   redesenhar a viagem.
2. **Como o fundo recebe contribuição.** v3.0: uma taxa automática (1–10% da produção diária,
   padrão 3%) descontada sozinha. v3.2: "armazém/fundo por rota física e livro-razão" — soa como
   entrega manual por veículo, igual a tudo que a logística do jogo já faz. **O usuário escolheu a
   v3.2**: entrega física por veículo. Reaproveita `DespacharVeiculo`/`ConcluirTrechos` inteiros —
   zero mecânica nova de "desconto automático de produção", que não existe em nenhum outro lugar do
   jogo.

Os quatro cargos (v3.2: Líder, Diplomata, Intendente, Membro — a v3.0 só citava dois) e o teto de
12 colônias não têm conflito entre as versões — v3.2 só completa o que v3.0 omitiu. O limite
antimonopólio ("20% → 10%", v3.0) e a fórmula do fundo em % da produção (agora superada pela
entrega física) ficam **fora da Fatia 1** — vagos demais para arbitrar sem mais contexto do
usuário.

### Escopo da Fatia 1 — só o núcleo

Fundar/entrar/sair, os quatro cargos, o fundo (entrada por veículo, saída por saque administrativo)
e o Quartel de Alianças (Capital, slot 9 — antes `reservado`, sem função nenhuma). **Fora, para
fatias seguintes**: o canal de chat `federacao` (a coluna já aceita o valor desde o D-77, só falta
o `case` em `EnviarMensagem`/`LerMensagens`), o apoio de aliado ao romper um cerco (§28.10 —
`RomperCerco` só aceita o dono da zona hoje), a categoria de missão "Federação" (não existe nem
como valor de enum), a metade que falta do impedimento do conciliador em `Triagem::impedido()`
(só a parte de acordo comercial está implementada), e o payload da Central de Comunicação da zona
(construção erguível e inerte desde o D-79, esperando exatamente isto).

### Modelo de dados

`colonies` ganha `federation_id`/`federation_role` — posse DIRETA, sem pivô, mesmo padrão de
`neutral_zones.owner_colony_id` (uma colônia pertence a no máximo uma federação). `federations`
nunca é apagada (`disbanded_at`, mesmo padrão de `Admin.desativado_em`) — uma dissolvida vira
histórico consultável. `federation_holdings`/`federation_ledger` espelham
`treasury_holdings`/`treasury_ledger` (D-57/D-96), mas não-singleton (N federações, não um Tesouro
só). `federation_invites` cobre convite E pedido na mesma tabela (`kind` distingue), sem unique de
schema — a checagem de duplicata vive em código sob `lockForUpdate()`, como o resto do domínio.

### O domínio (`App\Domain\Federacao\`)

Nove classes: `CriarFederacao`, `EnviarConviteOuPedido` (convidar/pedir), `ResponderConviteOuPedido`
(aceitar/recusar/cancelar), `SairDaFederacao`, `TransferirLideranca`, `ExpulsarMembro`,
`AlterarCargo`, `SacarDoFundo`, `DissolverFederacao`. A contribuição ao fundo **não ganhou classe
nova** — estende `DespacharVeiculo::resolverDestino()` (novo ramo `federacao`, coordenadas fixas da
Capital como `mercado_central`, `destination_id` **nunca** vem do cliente, sempre resolvido da
federação da própria colônia) e `ConcluirTrechos::concluirIda()` (novo `depositarNaFederacao()`,
tributa normalmente, credita `federation_holdings`, lança em `federation_ledger`, estaciona no
Pátio ao final — a mesma regra "só de ida" do Mercado, D-65).

⚠️ **Julgamento do desenvolvedor, sinalizado para o usuário revisitar**: quando a federação
dissolve (último membro saiu, ou o admin força em emergência), o saldo do fundo vai para o
**Tesouro**, não para quem saiu por último. Evita o exploit óbvio (expulsar todo mundo e sair por
último para embolsar o fundo sozinho) e segue a convenção do resto do jogo — valor não reclamado
sempre cai no Tesouro. Uma divisão proporcional ao histórico de contribuição de cada colônia seria
mais "justa" e é bem mais complexa; fica fora da Fatia 1.

### Dois bugs de verdade, achados escrevendo os testes

1. **Checagem de permissão no objeto errado.** `EnviarConviteOuPedido::convidar()` e
   `ResponderConviteOuPedido::exigirPermissao()` checavam `federation_role`/`podeConvidarParaFederacao()`
   no `Colony` que o controller passou, em vez de reler sob `lockForUpdate()` como
   `TransferirLideranca`/`ExpulsarMembro`/`AlterarCargo` já faziam. Isso nunca morde em produção
   (um request HTTP resolve o usuário do zero, uma vez, e usa na hora), mas os testes que simulam
   várias chamadas em sequência com o mesmo objeto PHP expuseram a inconsistência — e ela é real o
   bastante para valer a correção: as cinco classes agora relêem os dois lados sob lock, sempre.
2. **`FIELD()` é MySQL/MariaDB, não existe em SQLite.** `PainelController::federacoes()` e
   `FederationController::show()` ordenavam membros por cargo com `orderByRaw("field(...)")` —
   quebrava a suíte inteira (SQLite) sem jamais aparecer num teste que rodasse contra produção
   (MariaDB), a mesma classe de armadilha do D-59 ("SQLite mente"), agora em SQL de ordenação, não
   em DDL de migration. Corrigido ordenando em PHP (`sortBy` sobre `Federation::CARGOS`).

### Admin

Nova aba **Federações** (`/central/admin/federacoes`): leitura (todas as federações, membros,
fundo, extrato) + uma alavanca de emergência, "Dissolver" (exige escrever `DISSOLVER`, mesmo padrão
de `REALOCAR`/`DEMOLIR`). Sem criar federação nem mover membro pelo admin — nenhum sistema
comparável (Acordo de Troca, Guerra) tem isso; o operador intervém no extremo, não no meio do fluxo
do jogador. A dissolução de emergência precisou de um ajuste que a saída normal não expunha:
`DissolverFederacao` agora desliga **todos** os membros que ainda restarem (o caminho normal já
chega com zero, porque quem sai por último se desliga antes de chamar; o admin pode dissolver com
vários ativos).

Validado: `php artisan test` completo (728 — 33 novos: `FederacaoNucleoTest` 19, `FederacaoFundoTest`
9, `FederacaoAdminTest` 5), suíte completa também verde contra MariaDB efêmero (fresh + rollback +
migrate para a migration nova), `tsc`/`lint`/`build` limpos, e2e completo (9/9 verde — nenhuma
mudança de contrato em rotas existentes, só aditivo). Checagem visual manual (backend efêmero +
Puppeteer, dois colonos em contextos de navegador isolados): fundar, convidar, o segundo colono
aceitando e virando Membro, um despacho de verdade para o Quartel de Alianças, e a tela do
operador em `/admin/federacoes` com a lista, o detalhe e o formulário de dissolução.

---

## D-115 — Federação, Fatia 2: canal de chat, apoio de aliado no cerco, e a outra metade do impedimento.
**Data:** 2026-07-19 · **Status:** arbitrado pelo usuário · **Três pontas fechadas, duas adiadas de propósito**

O D-114 (Fatia 1) deixou cinco pontas para depois. Perguntei ao usuário qual atacar; ele escolheu
as quatro que eu ofereci (esqueci de oferecer a quinta — a metade que falta do impedimento do
conciliador — como opção separada, mas ela entrou de qualquer jeito por fazer parte do mesmo
levantamento). Pesquisando a fundo, achei que duas das cinco (categoria de missão "Federação"
cooperativa, e o alerta em tempo real da Central de Comunicação da zona) exigem mecanismos que
**não existem em lugar nenhum do jogo hoje** — progresso de missão compartilhado entre colônias
(toda `mission_assignments.colony_id` é uma colônia só), e broadcast de mensagem para vários
jogadores de uma vez (`EnviarMensagem::sistema()` é sempre 1-para-1). Perguntei ao usuário se
quaisquer as cinco de uma vez ou só as três que já tinham um padrão pronto para estender; ele
escolheu **só as três rápidas**. Missões cooperativas e o alerta da zona ficam para uma sessão
própria, com mais espaço de design — não são um "não", são um "ainda não".

### 1. O canal de chat "federacao"

A coluna `chat_messages.channel` já aceitava qualquer valor desde a migration original do chat —
só faltava saber DE QUAL federação. Nova coluna `federation_id` (nullable, sem FK — dado descritivo
congelado, como `x`/`y` da vizinhança), gravada no ENVIO, não recalculada na leitura: um colono que
sai da federação depois não apaga o histórico para quem ficou, e um que entra depois não herda o de
antes. Mesmo raciocínio da vizinhança (`x`/`y` congelados, filtro por raio na leitura), só que por
pertencimento em vez de posição.

⚠️ **Julgamento do desenvolvedor, não do GDD, sinalizado para o usuário revisitar**: o canal fica
FORA de `EnviarMensagem::PUBLICOS`, isento tanto do filtro de termos quanto da pena de silêncio. O
GDD só é explícito sobre o filtro ("federação e privadas não têm filtro automático", §10.2) — a
isenção de silêncio foi minha extensão da mesma lógica: um círculo de aliados está mais para
conversa fechada do que para praça pública, e "cala a praça, não a boca" (o espírito do §9.4)
sugere que a punição não deveria alcançar aqui. Se o usuário achar errado, é uma linha
(`PUBLICOS` ganha `'federacao'`).

Retenção: 180 dias, como `global` — não há prazo publicado especificamente para federação, e ela é
um canal persistente e pequeno (até 12 colônias), mais perto de praça duradoura que de vizinhança
(cujo prazo curto é sobre volume). A aba só aparece no Chat pra quem tem federação (uma busca leve
e independente ao abrir o painel, mesmo padrão que `Mercado.tsx` já usa pro diretório de colônias).

### 2. Apoio de federação aliada ao romper um cerco (§28.10)

`RomperCerco::handle()` tinha um guard único: só o sitiado rompe. Virou: o sitiado, OU qualquer
colônia da MESMA federação dele. Verificado ponta a ponta antes de escrever a linha: as unidades
exigidas já eram as de CASA de quem chama (não do dono da zona), "uma ruptura por vez" já era por
ZONA e não por colônia, e o crédito de XP/missão em `ResolverCombates::cercoRompido()` já ia para
`attacker_colony_id`, que é quem chama a ação — então nada mais no motor de combate precisou mudar.
Quem manda o socorro, luta; quem luta, ganha o crédito — dono ou aliado.

### 3. Ministério das Reputações — a outra metade do impedimento (§26.8)

`Triagem::impedido()` já cobria "transação comercial nos últimos 30 dias"; a metade "membros da
mesma federação" estava documentada como inerte desde sempre. Uma checagem a mais no início do
método (mesma federação do conciliador e de uma das partes → impedido), sem classe nova, sem rota
nova — a lacuna que o próprio comentário do código já previa.

Validado: `php artisan test` completo (739 — 11 novos: 7 em `ChatFederacaoTest`, 2 em `DefesaTest`
— aliado rompe e recebe o crédito, federação diferente continua de fora —, 2 em
`MinisterioDasReputacoesTest`), suíte completa também verde contra MariaDB efêmero (fresh +
rollback + migrate para a migration do `chat_messages.federation_id`), `tsc`/`lint`/`build`
limpos, e2e completo (9/9 verde — uma rodada teve uma falha isolada e não relacionada em
`capital.e2e.mjs`, na vitrine de usados, que uma segunda rodada confirmou como intermitência
pré-existente, não regressão desta entrega). Checagem visual manual (backend efêmero + Puppeteer,
dois colonos da mesma federação em contextos isolados): a aba Federação aparece entre Vizinhança e
Privadas, uma mensagem enviada por um colono aparece para o outro no mesmo canal.

## D-116 — Federação, Fatia 3: missões cooperativas e o alerta de cerco da Central de Comunicação.
**Data:** 2026-07-19 · **Status:** arbitrado pelo usuário · **As duas pontas que o D-115 adiou de propósito**

O D-115 deixou duas pontas por exigirem mecanismo novo, não extensão de um padrão pronto:
progresso de missão compartilhado entre colônias, e broadcast de mensagem para vários jogadores de
uma vez. Perguntado se seguia com as duas agora, o usuário disse "sim, siga". O GDD não dá números
nem fórmula para "missão cooperativa" ou "alerta de zona" — o desenho inteiro é estrutural.

### 1. Missões "Federação" — 2 por semana, "irmãs", não um progresso compartilhado

`mission_assignments.colony_id` é `NOT NULL` com FK, numa tabela viva — torná-la nullable para
permitir uma linha compartilhada por várias colônias seria o desenho "óbvio", mas um risco de
migration que o pedido não exige (mesma família de cautela do D-59, "SQLite mente"). Em vez disso:
**uma linha POR COLÔNIA-MEMBRO**, todas marcadas com o mesmo `federation_id` novo (nullable, FK
`federations`, `cascadeOnDelete()` — a missão compartilhada é objetivo ativo, não registro
histórico como `federation_ledger`) — "irmãs" do mesmo objetivo, sorteadas juntas por
`Atribuir::garantirFederacao()`.

`Progresso::registrar()` aprendeu a espelhar: depois de processar a própria linha de quem agiu, se
ela tem `federation_id`, todas as irmãs (`WHERE federation_id = X AND template_id = Y AND status =
'ativa'`) recebem o MESMO progresso — não somado por irmã, o feito é um só e o placar é do grupo.
Cruzou a meta, todas concluem e `pagar()`/`avisar()` rodam para CADA UMA sem nenhuma mudança
nelas: cada irmã já sabia pagar a própria colônia.

Quem entra na federação no meio da semana ganha a própria linha com o progresso JÁ ANDADO copiado
de uma irmã existente — não começa do zero por ter chegado depois. Se o template já foi decidido
(concluído ou expirado) antes de a colônia pedir, ela simplesmente perde aquela missão da semana —
sem linha "concluída sem pagamento" fantasma.

⚠️ **Julgamento do desenvolvedor, sinalizado para o usuário revisitar**: uma colônia que SAIU da
federação depois de a missão ter sido atribuída, mas antes de terminar, ainda recebe se o resto do
grupo terminar — a linha já existe, independente da federação atual. Simplicidade sobre precisão:
reconferir a federação a cada `registrar()` custaria uma consulta a mais por ação do jogo inteiro,
para um caso raro.

### 2. Central de Comunicação — visão ao vivo do aliado, e o alerta de cerco

Duas metades do mesmo trecho do GDD, cada uma um encaixe pequeno em cima do que já existia:

**Visão ao vivo** — `Avistamentos::de()` ganhou um branch novo: se a zona é de um aliado da MESMA
federação e a dona tem `communication_level >= 1`, devolve `intel: 'federacao'` (ao vivo, sem
gastar vigia de Drone) — um quinto valor ao lado de `dona`/`livre`/`ao_vivo`/`foto`/`nenhuma`. Bug
real pego durante o teste, não só de SQLite-vs-MariaDB desta vez: `NeutralZoneController::index()`
eager-carrega `owner:id,name,user_id` (colunas restritas, sem `federation_id`) — o branch novo
usava `$zona->owner` direto e sempre lia `federation_id: null`, caindo silenciosamente em
`'nenhuma'`. Corrigido buscando a colônia dona fresca (`Colony::find`) dentro do próprio
`Avistamentos`, em vez de depender do eager-load restrito de quem chama.

**O alerta** — nova conta de sistema `ContaSistema::federacao()` (migration
`2026_07_19_120000_conta_sistema_federacao`, mesmo molde da "Missões") e `App\Domain\Zona\
AvisoDeAtaque` (mesmo formato de `AvisoDoPatio.php`): quando `communication_level >= 1` e a zona
tem dono federado, avisa TODOS os membros da federação, um a um, via `EnviarMensagem::sistema()`.
Disparado em `ResolverCombates::chegar()`, no mesmo instante que já marca `sieged_at` — o ponto
único onde "o cerco começou a morder" já existe no motor.

### O round-trip contra o MariaDB, de novo

A migration de `mission_assignments.federation_id` expôs duas lições que o SQLite não ensina:

1. **`dropConstrainedForeignId` sozinho não basta** para desfazer um `foreignId()->constrained()` +
   `index()` combinados: soltar a coluna sem soltar a FK primeiro esbarra em "needed in a foreign
   key constraint" (o MariaDB recusa apagar o índice que ainda sustenta a FK).
2. **Soltar a FK e a coluna NÃO leva o índice composto junto** — o MariaDB só o ENCOLHE (tira a
   coluna removida da composição), mantendo o NOME antigo de 3 colunas num índice que passa a ter
   2. Um `up()` futuro que tente recriar o índice esbarra em "Duplicate key name". A ordem certa:
   soltar a FK → soltar o índice PELO NOME, com a coluna ainda viva → só então soltar a coluna.

Nenhuma das duas quebra o `migrate:fresh` nem o `php artisan test` (SQLite): só aparecem num
`rollback` de verdade contra o MariaDB. Ensaiado duas vezes seguidas (fresh → rollback → migrate →
rollback → migrate) antes de fechar a fatia.

Validado: `php artisan test` completo (752 — 13 novos: 7 em `MissoesFederacaoTest`, 3 em
`DroneTest` — aliado vê ao vivo pela Central, sem ela nada muda, federação diferente continua de
fora —, 3 em `DefesaTest` — o alerta avisa todos os membros, sem Central ninguém é avisado, sem
federação idem), MariaDB efêmero (fresh + rollback + migrate, duas vezes, para as duas migrations
novas), `tsc`/`lint`/`build` limpos, e2e completo (9/9 verde). Checagem visual manual (backend
efêmero + Puppeteer, duas colônias da mesma federação em contextos isolados): a missão "Federação"
aparece com o MESMO progresso nas telas das duas colônias (espelhamento confirmado), e o aviso de
cerco chega em Privadas, remetido pela conta "Federação", assim que a zona é sitiada.

---

## D-117 — O piso anti-farming do §26.3 desce de 500 F$ para 5 F$.
**Data:** 2026-07-19 · **Status:** decidido pelo usuário (revisão do D-43) · **GDD §26.3**

O usuário topou com um Acordo real valendo 0,78 Fert$ somando os dois lados — bem abaixo do piso de
500 F$ do D-43, então ficou registrado no histórico sem mover a Confiança Comercial, exatamente como
o código documenta. Pediu para descer o piso para **5 F$**.

**Uma linha:** `AcordoSpecs::PISO_REPUTACAO_MICRO` (usada tanto pelo Acordo de Troca quanto pelo
Mercado Central — `ExecutarOrdem`, D-75 — é o MESMO piso para os dois canais de comércio). Nenhuma
migration: é uma constante de código, não uma linha de configuração do operador.

⚠️ **A leitura oposta do mesmo número, para não perder de vista**: o piso existe para impedir que
duas contas façam volume de mentira em microtransações e "farmem" Confiança Comercial ou XP do
Marco de graça. Descer de 500 para 5 F$ **encolhe 100× o custo de entrada do farm** — 1 unidade de
um recurso barato já bastava para furar o piso antigo mirando alto; agora quase qualquer transação
com conteúdo real cruza os 5 F$. Isso não é um bug: é o trade-off que o usuário escolheu, entre
"piso alto demais barra negócio pequeno de verdade" (o caso que ele bateu) e "piso baixo demais
abre o farm". Se o farm aparecer, é este número o primeiro a revisitar — na direção contrária.

Validado: `php artisan test` completo (752, mesma contagem — nenhum teste novo, só comentários e o
nome de `test_acordo_abaixo_do_piso_de_500_fert_nao_move_reputacao` atualizados para o piso novo; o
cenário em si, 1 unidade de cada lado, já ficava abaixo tanto de 500 quanto de 5 F$, então continua
provando a mesma coisa).

> **Correção tardia (2026-07-19, achada validando o D-118):** `Acordos.tsx` tinha o texto "abaixo do
> piso de 500" **hardcoded** — a mesma frase que o usuário colou de volta para pedir esta mudança,
> ainda com o número velho na tela, porque o commit original só tocou o backend. O e2e do Acordo só
> checa o valor do acordo (`Vale 3,95 Fert$...`), não o número do piso em si, então não pegou.
> Corrigido para "5 Fert$ (D-117)".

---

## D-118 — O "Módulo Operacional" ganha as duas metades que faltavam (§28.10).
**Data:** 2026-07-19 · **Status:** revisão pedida pelo usuário, arbitrada com ele em três perguntas

Pedido do usuário: revisar as Zonas Neutras ocupadas e propor melhorias. O levantamento (research
agent, sete eixos: mecânica central, as 13 estruturas, defesa/guerra, frontend vs. API, pendências
já catalogadas, lacunas do GDD ainda não vistas, alavancas do admin) achou um bug real maior que
todos os outros: **a Sabotagem e a Apreensão de Módulos não faziam NADA além de acender um badge**.

### O bug: um verbo inteiro do combate sem efeito nenhum

`ResolverCombates::desligarModulo()` gravava `modules_offline`, e **nada além do próprio ataque e da
UI lia esse campo**. O bônus de construção (`Forcas::bonusDeConstrucao`), a detecção da Torre contra
o Infiltrador, a resistência do Abrigo contra o Predador, a capacidade do Depósito — todos liam o
nível cru da estrutura, ignorando se ela estava "desligada". Pior: o próprio código já previa um
resgate automático (`Combat::RESGATE_HORAS = 24`, `$combate->prazo_at`) que **nunca era lido por
ninguém** — a Apreensão nunca reparava sozinha, como o comentário do D-66 já dizia que deveria.

Um segundo bug, menor, tornava tudo isso pior: `Atacar::conferirAlvoDeEstrutura()` usava as chaves
`'deposito'`/`'muralha'`, enquanto `Estruturas::COLUNA` (a fonte que a UI e o resto do domínio leem)
usa `'deposito_de_zona_neutra'`/`'muralha_de_perimetro'` — mesmo que a leitura existisse, essas duas
estruturas nunca bateriam a chave certa.

### O desenho, com o usuário decidindo os três pontos que o GDD deixa em aberto

O GDD distingue as duas ações e nunca publica o mecanismo delas:

- **Sabotagem (Infiltrador)**: "a estrutura-alvo perde capacidade **proporcional** ao nível do
  Infiltrador" — não desliga por inteiro.
- **Apreensão (Predador)**: "desliga uma estrutura **até resgate**" — binária, e o texto já promete
  as 24h de auto-reparo que o código nunca cumpria. "Estruturas sob um Bastião são imunes" — só
  nesta linha da tabela, não na da Sabotagem.

Perguntado, o usuário escolheu: **(1)** a Apreensão repara sozinha em 24h **e** o dono pode pagar
para reaver antes (as duas portas, não só uma); **(2)** implementar a proporcionalidade real da
Sabotagem, com reparo ativo — não o mesmo binário da Apreensão por simplicidade; **(3)** o custo do
reparo/resgate é uma fração do custo de CONSTRUÇÃO da estrutura, mesmo padrão da manutenção de
veículos do Ministério dos Transportes (D-60), não um número novo inventado.

### O modelo: duas colunas, duas semânticas que não se confundem

`neutral_zones` ganha `modules_offline_expira_em` (mapa `estrutura → quando a Apreensão expira`) e
`structures_saboted` (mapa `estrutura → nível de quem sabotou`) — `modules_offline` continua exatamente
como era, sem mudar de forma. `NeutralZone::fracaoEfetiva(string $chave): int` (bps, 10.000 = cheia,
0 = fora) é o ÚNICO ponto de leitura: 0 se apreendida, `10.000 − nível×2.000` se sabotada (nível 5 de
5 equivale a 0 — tão inerte quanto uma Apreensão), 10.000 caso contrário. Uma estrutura nunca está
nas duas ao mesmo tempo — a Apreensão já a zera.

Todo ponto de consumo passou a multiplicar por ela: `Forcas::bonusDeConstrucao` (Muralha/Torre/
Bastião), a detecção da Torre contra o Infiltrador, a resistência do Abrigo contra o Predador
(nível efetivo, não o cru), e `NeutralZone::capacidadeDeposito()` (um Depósito apreendido protege
menos — mais exposto ao saque, coerente com o D-66).

`ExpirarApreensoes` (nova, roda no tick, depois do combate) lê `modules_offline_expira_em` vencido e
restaura sozinha — é o "passado o prazo, ele repara normalmente" que o D-66 já tinha escrito e nunca
implementado. `RepararModulo` (nova) é a porta paga: 10% (`WarSetting::reparo_bps_do_custo`, painel
da Guerra) do custo de construção no nível atual, debitado da colônia, limpa a Sabotagem OU resgata
a Apreensão antes da hora.

`Atacar::conferirAlvoDeEstrutura()` corrigido para as chaves canônicas de `Estruturas::COLUNA`, e
ganhou a checagem de imunidade do Bastião — só para `apreensao`, exatamente como o GDD escreve
(confirmado por teste: a Sabotagem NÃO é bloqueada pelo Bastião).

### Dois bugs a mais, achados corrigindo o primeiro

1. **`Estruturas::TABELA['central_de_comunicacao']` mentia.** Ainda dizia "Nada. Só serve à
   Federação, que não existe" — desatualizado desde o D-116 (19/07, mais cedo no mesmo dia), que já
   tinha ativado a visão ao vivo do aliado e o alerta de cerco. `inerte` virou `false`, o texto
   passou a descrever o que ela faz de verdade — para os aliados, não para o dono. `docs/RETOMAR.md`
   também dizia "Federações não existem" na lista de frentes em aberto; corrigido.
2. **`Acordos.tsx` (D-117) tinha "piso de 500" hardcoded** — ver a correção tardia registrada no
   D-117 acima. Achado validando o e2e deste D-118, não relacionado à guerra, mas na mesma sessão.

### Validado

`php artisan test` completo (767 — 15 novos em `ReparoDeModulosTest`, cobrindo cada ponto de consumo
da `fracaoEfetiva` isoladamente, a imunidade do Bastião nos dois sentidos, o tick de expiração e as
quatro recusas do `RepararModulo`; mais ajustes em `DefesaTest`, `GuerraTest` e `ZonaLugarTest` para
as chaves corrigidas e o novo décimo-primeiro parâmetro da Guerra). MariaDB efêmero (fresh + rollback
+ migrate, duas vezes) para a migration nova (duas colunas em `neutral_zones`, uma em `war_settings`).
`tsc`/`lint`/`build` limpos. e2e completo, 9/9 verde — achei e corrigi de propósito duas quebras
reais nele: o texto da Central de Comunicação (a asserção esperava a descrição velha) e uma falha
transitória e não relacionada no Acordo de Troca, confirmada como instabilidade pré-existente ao
rodar de novo (mesma classe de intermitência que o Mercado já tinha, RETOMAR.md).

---

## D-119 — O limite antimonopólio da Federação (§04): 20% de TODAS as zonas do jogo, teto do operador.
**Data:** 2026-07-19 · **Status:** arbitrado com o usuário em três perguntas · **GDD §04**

O D-114 tinha deixado o limite antimonopólio de fora da Fatia 1 por ser "vago demais para arbitrar
sem mais contexto": o §04 escreve "Limite antimonopólio dinâmico: 20% → 10%" e não diz **de quê**,
nem **o que dispara** a transição entre os dois estágios. A v3.2 "regra definitiva" (§07) é ainda
mais vaga — "limites de concentração, volume entre contas e estoque de recursos estratégicos são
monitorados... medidas são sistêmicas e auditáveis" — três eixos, nenhum mecanismo.

Perguntado, o usuário decidiu os três pontos que faltavam:

1. **O que medir**: a fatia de **todas as zonas neutras OCUPADAS do jogo** (120 no total — 4
   distritos de 30) que uma federação detém. É o único eixo dos três que já tem sistema pronto pra
   medir (território, D-84); volume-entre-contas e estoque de minerais estratégicos ficam de fora —
   o segundo é vigilância antifraude, o terceiro está inerte por o governo ainda monopolizar os 8
   minerais eletrônicos no lançamento (mesma inércia do D-17).
2. **O que acontece ao cruzar**: bloqueia a PRÓXIMA ocupação de zona por qualquer colônia da
   federação. Zonas que ela já tem não são tocadas — nada de "confiscar" ou forçar abandono.
3. **20% → 10% vira um teto FIXO**, não os dois estágios: o GDD não publica o gatilho da transição
   (tempo de servidor? número de federações? fase da temporada?), e inventá-lo seria a mesma
   arbitragem vaga que o D-114 já tinha evitado. **2000 bps (20%) por padrão** — o mais frouxo dos
   dois números do documento, para não morder o jogo pequeno de hoje — e ajustável sem deploy pelo
   painel, para o operador apertar rumo aos 10% conforme o servidor crescer.

### Onde mora, e por que "antes" e não "depois"

`FederationSetting` — tabela singleton nova (`federation_settings`), mesmo molde de
`TransportSetting`/`WarSetting` (D-60/D-66): defaults no banco, `singleton()` relê depois de criar.
Painel: aba **Federações**, que até aqui era "sistema 100% jogador-a-jogador, o operador só
observa" — deixou de ser: o §04 delega este número a ele, então ele mora lá.

`OcuparZonaNeutra::handle()` ganhou um guard novo, logo depois do teto de 5 zonas por colônia
(D-84) — mesmo lugar, mesmo padrão. ⚠️ **A checagem é do estado ANTES da ocupação, não depois**: se
checasse "depois de somar esta zona, a federação passaria do teto?", a primeiríssima zona ocupada
por QUALQUER federação do jogo inteiro sempre daria 100% de um total de 1 e travaria o próprio
nascimento do sistema. Checando antes, o guard bloqueia a zona que levaria a federação a **crescer
além** do teto que ela já tinha alcançado — não a que a levou até lá.

### Validado

`php artisan test` completo (773 — 6 novos em `LimiteAntimonopolioTest`: o caso de zero zonas no
jogo não trava nada, o bloqueio no teto padrão, uma colônia solo nunca esbarra nisso por mais que a
federação domine, o painel grava o número E o guard passa a lê-lo — mesmo cenário do bloqueio, só
com o teto mais frouxo —, e as duas pontas do formulário do painel). MariaDB efêmero (fresh +
rollback + migrate, duas vezes) para a migration nova (`federation_settings`). `tsc`/`lint`/`build`
limpos (sem mudança de frontend — o teto só existe no painel de admin e no domínio). e2e completo,
9/9 verde.

---

## D-120 — O desconto de tributo entre aliados (§04/§07): 50%, do operador, só entre DUAS colônias.
**Data:** 2026-07-19 · **Status:** arbitrado com o usuário em duas perguntas · **GDD §04/§07, v3.0**

A última ponta que o D-114 tinha deixado de fora da Fatia 1 de propósito: "a contribuição ao fundo é
tributada NORMALMENTE (100%), deixando o terreno pronto para o desconto entrar depois sem
redesenhar a viagem". O próprio comentário que ficou em `ConcluirTrechos::concluirIda()`, no bloco
do destino `federacao`, usava a contribuição ao fundo como o exemplo do "terreno pronto" — uma
ambiguidade real sobre se o desconto valeria só no comércio entre colonos (§07) ou também na
entrega ao próprio fundo (§04). Perguntado, o usuário decidiu:

1. **Só comércio entre DUAS colônias aliadas** (§07). A contribuição ao fundo da própria federação
   (§04) continua tributada cheia — `FederacaoFundoTest` não muda uma linha. Faz sentido lido
   assim: o fundo não é uma troca entre duas partes, é a colônia alimentando o próprio caixa
   coletivo — "entre aliadas" pressupõe duas colônias, não uma colônia e o grupo que ela integra.
2. **O número (50%, que a v3.0 publica com todas as letras) mora no painel**, não em constante de
   código — mesma tabela `federation_settings` que o D-119 já tinha criado para o outro número do
   §04 (o teto antimonopólio). Diferente do D-119, aqui o GDD não delega o número — ele o publica.
   Mesmo assim vira parâmetro: o precedente mais próximo (o mesmo domínio, a poucos commits) já
   tinha decidido que qualquer coisa que valha a pena rebalancear sem deploy vira painel.

### Onde entra, e o cuidado de não descontar quem entrega para SI MESMO

`ConcluirTrechos::entregar(Colony $origem, Colony $destino, ...)` já recebe as duas colônias — é o
único lugar que precisava mudar. Novo helper privado `aliquota()`: se `$origem->id !== $destino->id`
e as duas têm o MESMO `federation_id` não nulo, aplica o desconto; senão, alíquota cheia.

⚠️ **A checagem de identidade (`$origem->id !== $destino->id`) não é redundante.** `entregar()`
também é chamada com origem e destino **iguais** em dois casos: o frete público do governo (D-76,
o caminhão entrega e a colônia É a própria origem e destino) e a retirada do Mercado Central
(§25.8, a colônia retirando o que ela mesma depositou). Sem essa checagem, uma colônia federada
"descontaria de si mesma" ao retirar o próprio estoque — não é comércio entre aliados, é a colônia
falando com ela mesma. Coberto por teste (`retirada_do_mercado_nao_ganha_desconto_mesmo_sendo_
federada`).

`depositarNoMercado()` e `depositarNaFederacao()` calculam o tributo delas mesmas, **fora** de
`entregar()` — não foram tocadas, e é por isso que o desconto não vaza para lá.

### Validado

`php artisan test` completo (777 — 4 novos em `DescontoDeTributoEntreAliadosTest`: entrega entre
aliados paga metade, federações diferentes pagam cheio, retirada do Mercado não desconta mesmo
sendo federada, e o painel ajusta o número de verdade — testado com 100% de desconto, isentando a
entrega por inteiro). MariaDB efêmero (fresh + rollback + migrate, duas vezes) para a coluna nova
em `federation_settings`. `tsc`/`lint`/`build` limpos (sem mudança de frontend — o desconto é
transparente na tela, o colono só vê o líquido que já chegou maior). e2e completo, 9/9 verde.

---

## D-121 — Sair da federação pede confirmação; as 9 ações sociais deixam de ser silenciosas.
**Data:** 2026-07-19 · **Status:** pedido direto do usuário, três partes

Pedido do usuário, três partes: (1) "Sair da federação" passa a exigir digitar SAIR; (2) o jogador
recebe mensagens no chat, vindas da conta "Federação", para convite, saída e outros alertas; (3)
uma aba de chat "Federação" nova, só entre os membros da própria federação.

**A parte 3 já existia — não foi construída de novo.** O canal de chat `federacao` (coluna
`chat_messages.federation_id`, `EnviarMensagem`/`LerMensagens`) e a aba "Federação" no Chat
(`frontend/src/ui/Chat.tsx`, condicional a `temFederacao`) são do **D-115**, publicados em
19/07 mais cedo no mesmo dia. Conferido ponta a ponta antes de mexer em qualquer coisa — front e
back, funcional. Se o pedido era outra coisa (ex.: o comportamento de filtro/silêncio do canal,
que o D-115 isentou de propósito e marcou como "julgamento do desenvolvedor, revisitável"), fica
para o usuário esclarecer; nenhum código foi tocado aqui.

### 1. Sair exige "SAIR" — mesmo padrão do Demolir (D-59)

`SairDaFederacao::PALAVRA = 'SAIR'`, checada no **controller** (`FederationController::sair()`),
não só na tela — "uma confirmação só em React protege contra o dedo escorregando, e nada mais"
(o mesmo raciocínio que já valia para `Demolir`). O frontend replica o padrão exato do botão de
demolir: clique revela um campo de texto, o botão de confirmar fica `disabled` até o texto bater
exatamente, "Cancelar" limpa tudo.

### 2. As 9 ações da Federação eram TODAS silenciosas — 6 ganham aviso

Levantamento antes de mexer: nenhuma das 9 classes de `App\Domain\Federacao\*` avisava quem quer
que fosse — nem convite, nem entrada, nem saída, nem expulsão, nem cargo, nem liderança, nem
dissolução. O único aviso relacionado à Federação em todo o jogo era o de cerco (`AvisoDeAtaque`,
D-116, fora de `Domain\Federacao`). Um membro só descobria que tinha sido expulso, por exemplo,
ao tropeçar num erro "sem_federacao" tentando usar alguma coisa.

Seis eventos ganharam aviso, pela conta de sistema `ContaSistema::federacao()` (já existia,
D-116) via `EnviarMensagem::sistema()`:

- **Convite recebido** (`EnviarConviteOuPedido::convidar()`) — avisa a colônia convidada.
- **Entrada aceita** (`ResponderConviteOuPedido::aceitar()`) — avisa quem JÁ estava na federação
  (capturados antes de o novo membro entrar), não o que entrou — ele já sabe, foi ele quem clicou.
- **Saída** (`SairDaFederacao`) — avisa quem ficou.
- **Expulsão** (`ExpulsarMembro`) — avisa o expulso.
- **Cargo alterado** (`AlterarCargo`) — avisa o próprio membro afetado.
- **Liderança transferida** (`TransferirLideranca`) — avisa o novo Líder.
- **Dissolução** (`DissolverFederacao`) — avisa os membros ainda ativos, capturados **antes** de
  zerar `federation_id` de todos. ⚠️ No caminho NORMAL (o último membro sai e a federação dissolve
  sozinha), essa lista sempre vem vazia — quem saiu por último já foi avisado por `SairDaFederacao`
  antes de chegar aqui. É a dissolução de EMERGÊNCIA pelo admin, com gente ainda ativa, que
  realmente dispara o aviso — coberto por teste específico.

⚠️ **Três ficaram de fora, por escopo, sinalizado para o usuário revisitar:**

- `SacarDoFundo` — já é visível no extrato do fundo; um aviso a mais seria redundante.
- `CriarFederacao` — a colônia está sozinha ao fundar; não há quem avisar.
- `EnviarConviteOuPedido::pedir()` (pedido de entrada, o inverso do convite) — avisaria vários
  Líderes/Diplomatas de uma vez, não uma pessoa só; escopo maior do que "convite, saída e outros
  alertas" parecia pedir. Fica para uma revisão se o usuário quiser.

### Validado

`php artisan test` completo (785 — 7 novos em `AvisosSociaisDaFederacaoTest`, um por evento, mais
o teste de fronteira da confirmação errada em `FederacaoNucleoTest`; os testes de `/federation/
leave` existentes em `FederacaoNucleoTest`/`FederacaoFundoTest` atualizados para mandar
`confirmacao: SAIR`). Sem migration — nenhuma coluna nova, só comportamento. `tsc`/`lint`/`build`
limpos. e2e completo, 9/9 verde (uma rodada teve uma falha isolada no Chat, num fluxo de busca que
este trabalho não toca; duas rodadas seguintes confirmaram verde — instabilidade pré-existente,
mesma classe da intermitência já documentada no Mercado).

---

## D-122 — O canteiro da zona ganha teto e vira saqueável; abandono para de vazar obra e upgrade.
**Data:** 2026-07-19 · **Status:** revisão pedida pelo usuário nas Zonas Neutras/construções/envio

Pedido do usuário: revisar zonas neutras, construções e o mecanismo de envio de recursos pra zona
neutra. Duas pesquisas em paralelo (ciclo de vida da zona + canteiro; construções da colônia e da
zona) acharam onze pontos; o usuário escolheu os quatro mais graves — todos no canteiro de obras
(`zone_materials`) e no ciclo de abandono/upgrade da zona.

### 1. Abandono não limpava o canteiro nem a fila de obras — a lavagem que o D-84 dizia ter fechado

`CobrarManutencaoTerritorial::abandonar()` já resetava TUDO — nível, guarnição, todas as
estruturas — menos duas tabelas: `zone_materials` e `zone_build_queue`. Uma obra em curso no
momento do abandono sobrevivia, e quando o prazo vencesse, `ConcluirObrasDaZona` erguia a
estrutura pra quem quer que fosse o dono NAQUELE momento — inclusive uma segunda conta do mesmo
jogador que reocupasse de propósito. É a exploração exata que o comentário do próprio método já
citava por nome ("a lavagem que o D-73 já fechou para o Furgão, só que para zonas"), só que nunca
tinha sido de fato fechada para essa dupla de tabelas. Corrigido: as duas são apagadas no
abandono, junto com o resto.

### 2. O canteiro era um depósito paralelo — sem teto, imune a Invasão/Cerco/Predador

Ao contrário do Depósito de verdade (capacidade crescente pelo §19.6, e é sobre ele que a guerra
calcula o que está exposto), `zone_materials` nunca tinha limite nem entrava em `estoqueTotal()`.
Duas mudanças:

- **Teto**: reaproveita `capacidadeDeposito()` — a mesma conta que já existe, não um número novo.
  O que não coube na entrega volta na carroceria, mesmo padrão do Mercado Central (D-58).
- **Saque**: o canteiro entra em `Protegido::saqueDetalhado()` numa conta À PARTE — **sempre
  100% exposto**, nunca protegido. Não é o que o Depósito protege (não é minério extraído, é
  material importado à espera de virar construção), e por isso não compete pela capacidade do
  Depósito nem pelo `estoqueTotal()`: perde a MESMA fração `$bps` do resto do saque (50% na
  Invasão, 30% no Cerco), sempre. `ResolverCombates::saquear()` credita o atacante e decrementa
  `ZoneMaterial` do mesmo jeito que já fazia para bruto/refinado/minerais.

⚠️ **Julgamento do desenvolvedor, sinalizado para o usuário revisitar**: a conquista por guerra
(`vitoriaDoAtacante`) continua transferindo o que sobrar do canteiro pro invasor, sem tocar nele —
consistente com o precedente já existente de que o vencedor herda a obra em curso ("ele pagou com
sangue", `ConcluirObrasDaZona.php`), não uma lacuna nova. Só o abandono (item 1) e o saque de
Invasão/Cerco (item 2) foram fechados nesta revisão.

### 3. Depósito de Zona Neutra: `build_time_seconds` NULL nos 10 níveis — construía instantâneo

`BuildingSpecSeeder` já tinha a proteção certa: uma construção sem tempo publicado fica NULL, e
`BuildingSpecs::para()` recusa enfileirar (`tempo_indefinido`) em vez de deixar `(int) null` virar
`0` e a obra concluir no ato. Só que **essa proteção nunca alcançava o canteiro da zona**:
`ConstruirNaZona` sempre leu `building_specs` direto via `DB::table`, sem passar por
`BuildingSpecs::para()` — e o Depósito de Zona Neutra é a ÚNICA das 13 estruturas de zona sem
tempo definido (as outras, incluindo as 3 fechadas no D-79, sempre tiveram um número). Não era uma
decisão — o próprio docblock do seeder já listava o Depósito junto de Central de
Transportes/Destilaria como "o GDD não publica", mas só essas duas (e os veículos) tinham ganhado
um tempo-base em `build_times_base.json`; o Depósito ficou pra trás.

Corrigido nos dois lados: um tempo-base entrou no `build_times_base.json` (120 min no nível 1,
mesma curva 1,5× dos demais — chega a ~77h no nível 10, dentro da faixa das outras estruturas de
zona), e `ConstruirNaZona` ganhou a MESMA trava que `BuildingSpecs::para()` já dava à colônia, caso
outra estrutura de zona um dia fique sem tempo por engano.

### 4. Upgrade pago perdido no abandono — sem estorno, mas agora auditável

Se a manutenção vence no meio de um upgrade de nível em curso, o abandono cancela o upgrade — mas
o Metal Bruto/Fert$ já debitados no pedido (`SubirNivelDaZona`) não eram estornados, **e não
ficava rastro nenhum de que isso tinha acontecido**. Decisão: **sem estorno continua sendo o
certo** — o reset do abandono é deliberado (D-84), e reembolsar contrariaria o "reset completo, não
congelamento" que a própria decisão defende. O que faltava era só a auditoria: `abandonar()` agora
grava um `ZoneEvent` (`upgrade_perdido_no_abandono`) com o nível-alvo e o custo perdido
(recalculado por `NeutralZone::custoDeUpgrade()`, a mesma fórmula do pedido original — nada
armazenado a mais), coerente com "todo Fert$/recurso tem história" que o resto do jogo já segue.

### O que ficou de fora desta rodada (achados 5–11 da revisão, não escolhidos)

Estacionamento da Zona sem teto aplicado, `ConstruirNaZona` não lendo `building_specs_overrides`,
ausência de demolição/downgrade de estrutura de zona, dois docblocks desatualizados em
`Funcoes.php` (Quartel e Central de Transportes, que já fazem o que o texto diz que "ainda não"),
`manutencao.custo_diario`/`proximo_vencimento` nunca mostrados na tela, e o teto de obras
simultâneas (`zona_vagas`) não refletido no frontend. Nenhum foi tocado nesta fatia.

### Validado

`php artisan test` completo (792 — 7 novos em `RevisaoDoCanteiroTest`, um por comportamento: a
lavagem de zona fechada de ponta a ponta [encher canteiro → abandonar → reocupar com OUTRA conta →
confirmar que a obra fantasma não conclui], o `ZoneEvent` do upgrade perdido com o custo certo, o
teto do canteiro com sobra voltando no veículo, o canteiro saqueado numa invasão de verdade
[força bruta determinística], e o Depósito não concluindo mais na hora. Um teste existente
[`BuildQueueTest::test_construcao_sem_tempo_no_gdd_e_bloqueada`] usava o Depósito de Zona Neutra
como o exemplo de "sem tempo publicado" — atualizado para o Drone de Exploração, que continua
genuinamente sem tempo). Sem migration — só dados de seeder (`build_times_base.json`) e
comportamento; **passo à mão no deploy**: `artisan db:seed --class=BuildingSpecSeeder --force`
no banco de produção, mesma classe de esquecimento que já mordeu o D-67 (RETOMAR.md, "o deploy.sh
NÃO roda seeders"). `tsc`/`lint`/`build` limpos (sem mudança de frontend). e2e completo, 9/9 verde.

---

## D-123 — Os cinco achados menores da mesma revisão: 5 a 9, sem parar para perguntar.
**Data:** 2026-07-19 · **Status:** pedido direto do usuário — "siga sem me fazer perguntas"

Continuação do D-122: os cinco achados restantes da revisão de Zonas Neutras/construções/envio,
resolvidos com julgamento próprio (o usuário pediu explicitamente para não parar e perguntar).

**5. Estacionamento da Zona prometia 10 vagas e zero era aplicado.** Nenhuma rotina de despacho
contava veículos nem checava `parking_level`. Decisão: em vez de implementar um limite ao vivo
(risco real de travar zonas já ativas em produção, sem nenhuma fila de verdade que justificasse a
mudança agora), a estrutura passa a se declarar honestamente **inerte** — `inerte => true`, texto
corrigido — mesmo tratamento do Cemitério. Implementar o limite de verdade fica para quando alguém
desenhar o que "fila de retirada" deveria significar num jogo sem fila de verdade nenhuma.

**6. `ConstruirNaZona` nunca lia `building_specs_overrides`** — um ajuste do admin no painel de
Gestão de Construções (D-107/D-108) não tinha efeito nenhum nas 12 estruturas de zona. Trocada a
leitura crua de `building_specs` por `BuildingSpecs::para()`, o MESMO caminho que
`EnqueueUpgrade` já usa do lado da colônia — inclusive o padrão de checar `nivelMaximo()` antes,
pra preservar o código de erro `nivel_maximo` que já era o contrato da API. Provado por teste: um
override de custo/tempo em `muralha_de_perimetro` agora vale na zona.

**7. Não existe demolição nem downgrade de estrutura de zona — e isso nunca foi decidido, só nunca
foi levantado.** Diferente das outras contradições do jogo (tributo do D-32, frota que nunca trava
do D-60), não havia nenhum D-N discutindo a ausência. Registrado agora como o que é: uma lacuna
real, sinalizada no docblock de `Estruturas`, com as perguntas de design que uma implementação
abriria (devolve material? reduz manutenção na hora? undo de saque de guerra?) — **não
implementada nesta fatia**, porque essas perguntas são do usuário, não do desenvolvedor.

**8. Dois docblocks de `Funcoes.php` mentiam** — Quartel dizia "nenhuma unidade é recrutada aqui
ainda" (falso desde o D-66, `FabricarUnidade` recruta as quatro unidades no Quartel) e Central de
Transportes dizia que o teto de frota "ainda não vale no jogo" (falso desde o D-60,
`Domain\Transport\Vagas` já o aplica). As duas notas foram escritas antes das decisões que as
tornaram obsoletas, e nunca revisitadas. Corrigidas para descrever o código real; `quartel` também
teve o `efeito` corrigido de `nenhum` para `converte` (consome recurso, credita unidade).

**9. `manutencao.custo_diario`/`proximo_vencimento` — a API sempre devolveu, a tela nunca lia.** O
colono só descobria o custo da manutenção territorial depois de já estar inadimplente. `Zona.tsx`
ganhou um bloco calmo (visível quando NÃO há atraso) mostrando o custo por recurso e o próximo
vencimento; o aviso vermelho de atraso, que já existia, continua exatamente como estava.

### Validado

`php artisan test` completo (793 — nenhum teste novo além do que o item 6 provou dentro de
`RevisaoDoCanteiroTest`; a suíte inteira permaneceu verde porque nenhum teste existente afirmava o
texto antigo de Estacionamento/Quartel/Central de Transportes). Sem migration. `tsc`/`lint`/`build`
limpos. e2e completo, 9/9 verde.

---

## D-124 — O teto do canteiro do D-122 quebrou a entrega de material em produção. Corrigido.
**Data:** 2026-07-19 · **Status:** correção urgente, achada pelo usuário em produção · GDD §17.4/D-66

O usuário reportou "Despachar Material para Zona Neutra" quebrado. Investigação: o item 2 do
D-122 tinha dado ao canteiro um teto de REJEIÇÃO na entrega (`capacidadeDeposito()`, sobra volta
na carroceria, mesmo padrão do Mercado Central D-58). Conferido direto em produção: a zona 1
("Primeira Ocupação") já tinha **1.350** unidades no canteiro — herança de quando não havia teto
nenhum, muito antes deste D-122 — contra uma capacidade de 500. `capacidade - ocupado` dava
**negativo**, e `max(0, negativo)` trava em zero: **toda entrega nova, de qualquer zona nessa
situação, era 100% rejeitada e voltava inteira na carroceria**, sem aviso nenhum na tela — parecia
simplesmente não fazer nada.

**O D-66 já tinha passado por este exato dilema, do lado da extração, e escolheu o caminho
oposto.** O comentário dele está lá, e o D-122 não o releu: *"a extração deixa de parar no
teto... o excedente empilha ao relento"* — porque um teto de REJEIÇÃO transforma em zero
justamente o que devia virar risco. `saqueDetalhado()` já tinha ficado pronto pra isso no mesmo
D-122 (o canteiro é 100% exposto, sempre). Sobrava só desfazer a metade errada: o teto de entrada.

Corrigido: `ConcluirTrechos` volta a aceitar a entrega inteira no canteiro, sem checar
capacidade nenhuma — exatamente como sempre foi, antes do D-122. O que fica de pé do D-122: o
canteiro continua saqueável (Invasão/Cerco/Predador), e os itens 1/3/4 (abandono limpa canteiro e
fila, Depósito de Zona Neutra com tempo real, upgrade perdido auditável) não foram tocados —
nenhum deles tinha essa classe de problema.

⚠️ **Lição registrada**: antes de dar um teto de rejeição a qualquer acumulador do jogo, confira
se já existe precedente para o MESMO dilema — e se o jogo já está em produção com dado real que
o novo teto invalidaria. `estoqueTotal()`/extração já tinham resolvido exatamente este problema
uma vez; o D-122 reinventou a resposta errada sem perceber que a pergunta já tinha sido feita.

### Validado

`php artisan test` completo (793 — dois testes do D-122 reescritos: um agora reproduz o cenário
real de produção, 1.350 já acumulado + 300 novos = 1.650, nada volta na carroceria). Sem
migration. `tsc`/`lint`/`build` limpos. e2e completo, 9/9 verde (uma rodada teve a mesma falha
isolada e já documentada no Chat, não relacionada; segunda rodada confirmou verde).

---

## D-125 — "Despachar Material" ficava desabilitado sem motivo; a ficha da zona só mostrava a
## primeira obra da fila, mesmo com vagas sobrando (achado #10 do D-122/D-123, adiado até aqui).
**Data:** 2026-07-19 · **Status:** dois fixes no mesmo par de telas · GDD §17.4 · D-111

**1. O botão "Despachar material" nascia desabilitado mesmo com os campos aparentemente
preenchidos.** Cada `<input>` de quantidade mostrava um valor padrão calculado na hora
(`envio[r] ?? Math.min(falta, capacidade do veículo)`) — parecia pronto para enviar — mas esse
padrão nunca era escrito de volta no estado `envio`, que começava `{}`. Como o `total` (e,
portanto, o `disabled` do botão) somava só `Object.values(envio)`, ele ficava em 0 até o jogador
editar À MÃO cada campo, mesmo os que já mostravam o número "certo" na tela — um desencontro
clássico de componente controlado entre o que aparece e o que o estado realmente guarda.
Corrigido com um único helper `efetivo(r)`, usado ao mesmo tempo pelo `value` do input, pelo
cálculo do `total`/`disabled` e pela montagem da carga do despacho — as três lugares que antes
duplicavam (e podiam divergir de) a mesma fórmula de fallback agora leem o mesmo lugar.

**2. A ficha da zona só lia `obras()->first()`.** Desde o D-111, `FilaSetting::zona_vagas` é
configurável pelo operador e pode liberar mais de uma obra simultânea por zona — `ConstruirNaZona`
já aceitava a segunda desde então, mas a tela nunca soube: lia só a primeira obra, e o botão
"Construir" desabilitava assim que UMA obra qualquer existisse, mesmo sobrando vaga. Corrigido
em par: `ZoneController::show()` devolve `obras` (a fila inteira, ordenada) e `obras_vagas` (o
teto do operador), em vez do singular `obra`. `Zona.tsx` ganhou um bloco no topo da aba "Zona"
listando a fila (`N/vagas`), a mesma lista na aba Canteiro, e o botão "Construir" passou a
desabilitar por `filaCheia` (`obras.length >= obras_vagas`) em vez de "existe qualquer obra" —
com o texto do botão distinguindo "já há uma obra em curso" (quando o teto é 1, a frase de
sempre) de "fila cheia (N/vagas)" (quando o operador liberou mais de uma). O endpoint singular
`obra` de `minhas()` (resumo da barra lateral) e o de `construir()` (confirmação de UMA obra
recém-criada) ficaram como estavam — nenhum dos dois precisa da fila inteira.

### Validado

`php artisan test` completo (794 — um teste novo, `test_a_ficha_da_zona_lista_a_fila_inteira_de_obras`,
afirma `obras`/`obras_vagas` com duas obras simultâneas e `zona_vagas = 2`). Sem migration.
`tsc`/`lint`/`build` limpos. e2e completo, 9/9 verde.

---

## D-126 — A fila de obras também faltava no painel do Mapa, não só na ficha da zona.
**Data:** 2026-07-19 · **Status:** extensão do D-125, pedida pelo usuário logo depois de publicado
· GDD §17.4 · D-111, D-125

O D-125 pôs `obras`/`obras_vagas` na ficha inteira da zona (`GET /zones/{id}`) — mas o painel
compacto que abre ao clicar num marcador do Mapa, sem entrar na tela cheia, usa um endpoint
DIFERENTE (`GET /zones`, `NeutralZoneController::index()`, a lista das 120) que nunca teve esses
campos. Quem só espiava o mapa não via nada em obra — nem sabia que havia uma fila, nem quanto
faltava.

Mesmo par de campos, mesmo formato, mesmo segredo do D-74 (só o dono vê — para os outros, `obras`
e `obras_vagas` vêm `null`, igual a `upgrade`/`manutencao` já faziam). `Mapa.tsx` ganhou um bloco
"Fila de obras (N/vagas)" no painel do marcador, entre o upgrade de nível e a manutenção
territorial — mesmo texto e mesma régua visual do bloco que o D-125 pôs na ficha inteira.

### Validado

`php artisan test` completo (795 — um teste novo, `test_o_painel_do_mapa_tambem_lista_a_fila_de_obras_so_para_o_dono`,
afirma `obras`/`obras_vagas` preenchidos para o dono e `null` para qualquer outra colônia). Sem
migration. `tsc`/`lint`/`build` limpos. e2e completo, 9/9 verde.

---

## D-127 — O Histórico da zona mostrava "Fert$: -300000000" em vez de "-300".
**Data:** 2026-07-19 · **Status:** correção, achada pelo usuário em produção · D-86

O usuário reportou o número errado no Histórico da zona. Causa: o Fert$ do Posto de Comando
(ocupação, `OcuparZonaNeutra::debitarFert()`) e do upgrade de nível (`SubirNivelDaZona`) é
debitado no `Ledger` em **micro** — 1 Fert$ = 1.000.000, a mesma escala de `colonies.fert_micro`,
o mesmo padrão que qualquer lançamento de Fert$ no jogo já usa (`ProfileController::extrato()` já
converte assim para o extrato financeiro da colônia). `ZoneController::historico()` devolvia
`$l->amount` cru — certo para os lançamentos de recurso (`metal_bruto`, `ligas_metalicas`, já em
unidade real), errado para os de Fert$ (`resource_type` nulo), que ninguém tinha convertido de
volta. `Zona.tsx` só exibia o número que a API mandava — a tela nunca teve a culpa.

Corrigido: `quantidade` divide por 1.000.000 quando `resource_type` é nulo, exatamente a mesma
régua do `extrato()`. Os lançamentos de recurso continuam intocados.

### Validado

`php artisan test` completo (796 — um teste novo, `test_o_custo_em_fert_vem_convertido_de_micro`,
afirma -300 no Histórico para um débito de -300.000.000 no Ledger). Sem migration.
`tsc`/`lint`/`build` limpos. e2e completo, 9/9 verde.

---

## D-128 — O Ranking de Guerras (§27.13): a frente escolhida sozinho entre as que sobravam do GDD.
**Data:** 2026-07-19 · **Status:** implementado, cinco dos seis sub-rankings publicados · GDD §27.13

Com a Federação fechada (D-114 a D-127), sobravam duas frentes do GDD sem sistema algum: Leilões e
o Ranking de Guerras. Escolhi o Ranking — GDD com a fórmula publicada por inteiro, exemplo
numérico incluído, e cinco dos seis dados já existem no jogo sem precisar de tabela nova (Leilões
exigiria um mecanismo inteiro do zero: lances, prazo, fechamento).

### A fórmula é do documento, ao pé da letra — inclusive o nome "percentil" que não é bem um

Cada sub-ranking vira "percentil" (0–100) dividindo o valor do jogador pelo MÁXIMO do servidor —
não é um rank estatístico de verdade (não ordena por posição), é a escala linear que o próprio GDD
chama assim, com exemplo: "5 vitórias, máximo 200 → percentil = 2,5; contribuição = 20% × 2,5 =
0,5". O Ranking Geral é a soma ponderada dos cinco percentis. Testado contra esse exato exemplo
(`test_percentil_segue_o_exemplo_publicado_no_gdd`).

### O que cada sub-ranking lê, sem tabela nova nenhuma

- **Zonas Neutras Conquistadas (25%)** — conta os `ZoneEvent` do tipo `conquistada` (D-86):
  ocupar zona LIVRE não conta, só tomar de outra colônia por guerra.
- **Vitórias Totais (20%) e Maior Sequência (10%)** — seguem a MESMA régua que o jogo já usa para
  "vitória", o `combate_vencido` do Marco (D-75, `ConcederXp`): invasão vencida pelo atacante,
  invasão repelida pelo defensor, cerco rompido por quem socorreu. Cerco por prazo (`rendido`,
  saque de 30% mas a zona não muda de dono) e sabotagem/apreensão NUNCA disparam `combate_vencido`
  no motor — ficam de fora por não inventarmos uma segunda definição de vitória.
- **Tempo de Controle (20%)** — reconstruído do histórico de posse (`ZoneEvent`, D-86): soma os
  intervalos entre "começou a controlar" e "parou" (conquistada por outro, abandonada, ou agora —
  se ainda é dono), zona por zona.
- **Recursos Saqueados em Fert$ (15%)** — soma o `Ledger` tipo `saque_de_guerra`, convertido pelo
  preço do catálogo (`resource_types.preco_base_micro`, D-34).

### O que ficou de fora, sinalizado para o usuário revisitar

**"Guerras Vencidas (Federação)" (10%, o sexto sub-ranking) não entrou.** O próprio GDD diz que é
"só no ranking de federações" — mas o jogo não tem o conceito de "guerra da federação": todo
combate é sempre entre DUAS COLÔNIAS. Preenchê-lo exigiria inventar uma mecânica nova (guerra
declarada entre federações — o GDD não descreve isso em lugar nenhum) ou uma leitura arbitrária
(somar as vitórias dos membros por federação, duplicando o sub-ranking 2 sem base clara no texto).
As duas são arbitragem nova. **Os cinco pesos publicados somam 90, não 100 — não renormalizamos**:
é a régua do documento, não correção nossa. Também não construí um ranking SEPARADO por federação
(só o de colônia) — mesma pausa: exigiria decidir como agregar cada sub-ranking por federação
(soma? média por membro?), pergunta que o GDD não responde.

### A tela

`GET /war/ranking`, novo, dentro do Quartel (`WarController::ranking`, `RankingDeGuerras`, sem
migration — os quatro dados já existiam). O Quartel ganhou a seção "Ranking de Guerras": tabela
ordenada pelo Geral, a própria colônia destacada (`mine`, mesmo padrão do Mapa, D-74).

### Validado

`php artisan test` completo (805 — 9 testes novos em `RankingDeGuerrasTest`: conquista vs. mera
ocupação, a régua do `combate_vencido` excluindo `rendido`/sabotagem/apreensão, sequência que
quebra na derrota, tempo de controle com posse em curso e com abandono, conversão de saque pelo
catálogo, o exemplo numérico do próprio GDD, e o endpoint marcando `mine`). Sem migration.
`tsc`/`lint`/`build` limpos. Sem e2e dedicado — a tela do Quartel/guerra nunca teve suíte própria;
rodei a suíte completa (9/9) como checagem de regressão.

---

## D-129 — Leilões: a única frente que sobrava do GDD não tem seção nenhuma. Desenhei do zero.

**Data:** 2026-07-20 · **Status:** implementado, desenho nosso · GDD: nenhuma seção — só duas
citações, como alvo de uma punição inerte (§9.4/D-49/D-50)

Depois do D-128, sobrava só Leilões. Fui procurar a seção no GDD (`FERTWAYS_GDD_v36_CONSOLIDADO.html`)
e não existe: "leilão" aparece DUAS vezes no documento inteiro, e as duas são a mesma frase —
"reputação negativa bloqueia acesso a leilões" — citada como o alvo de uma punição que o D-49/D-50
já registrou como inerte. Sem tabela, sem fórmula, sem prazo, sem incremento de lance, sem dizer
quem lista o quê. Diferente da Federação (que tinha canais de chat, cargos e tratados desenhados no
texto) e do Ranking de Guerras (fórmula publicada por inteiro), aqui não havia lacuna para arbitrar
— havia mecanismo inteiro para inventar.

Perguntei ao usuário antes de comprometer código a isso. A resposta: **desenhar do zero**, com o
julgamento registrado aqui, como fiz no D-116 e no D-128.

### O desenho: em cima do Mercado Central, não ao lado dele

Um leilão é **um lote único, tudo ou nada** — sem arremate parcial, diferente de `market_orders`.
Ele sai do MESMO depósito que o Mercado Central usa (`market_accounts`, §25.8): quem quer leiloar
já entregou a carga na doca da Capital, a mesma exigência física do §07. Não inventei um segundo
depósito para um mecanismo que é, na essência, mais uma forma de vender na doca.

- **Acesso**: a MESMA `AcessoAoMercado::exigir()` que já fecha o Mercado Central — o próprio
  docblock daquela classe já cita leilões: "§26.2: Confiança Comercial baixa bloqueia o acesso a
  leilões, Mercado Central e ao cargo de Fiscal de Mercado." Não precisei escrever gate nenhum.
- **Anunciar** (`ListarLeilao`): escrowa o lote do depósito, como uma venda comum. Duração
  arbitrada entre 1 e 72 horas — o GDD não dá pista nenhuma de prazo; usei a mesma ordem de
  grandeza do cerco (48h) e do Acordo de Troca, sem exceder três dias.
  Lance mínimo é arbitrado pelo próprio anunciante — não há preço-base de leilão no GDD.
- **Lance** (`DarLance`): escrowa o Fert$ NA HORA, como a compra no Mercado Central. Quem é
  superado recebe de volta NO MESMO INSTANTE, não no fechamento — ninguém fica com Fert$ preso
  torcendo contra o próprio lance. Sem incremento mínimo formal: qualquer valor acima do lance
  vigente (ou do mínimo, se ainda não há lance) vale.
- **Cancelar** (`CancelarLeilao`): só enquanto NINGUÉM deu lance. Uma vez que alguém comprometeu
  Fert$ contando com o prazo publicado, tirar o lote da mesa seria calote do lado do vendedor —
  a mesma leitura que o §26.5 já faz do calote no Acordo de Troca, só que aqui a regra IMPEDE o
  cancelamento em vez de puni-lo depois.
- **Fechamento** (`FecharLeiloes`, chamado pelo tick, no mesmo ponto do `ExpirarAcordos`): sem
  lance, devolve o lote a quem anunciou, sem tributo — nada foi vendido. Com lance, transfere o
  lote ao arrematante e credita o vendedor líquido de tributo, pela MESMA `resource_types.tax_bps`
  do Mercado Central (`tax_events.kind = 'leilao_venda'`, novo). Sem corrida com o calote: o lance
  já estava em escrow desde que foi dado, então fechar é só liquidação.
- **XP e missão**: reaproveitei a trilha `mercado_executado` (Marco, D-75) para os dois lados do
  arremate — o GDD não publica um ato "leilão vencido", e inventar uma trilha nova (que exigiria
  coluna nova em `milestone_settings`) para um mecanismo que ELE MESMO não desenhou pareceu ir
  longe demais. Um leilão fechado é, para o Marco, mais um negócio fechado no Mercado.
- **Sem desconto de tributo entre aliados (D-120)**: o desconto só existe no caminho de entrega
  por transporte, nunca no de venda no Mercado Central — segui a MESMA ausência aqui, por ser o
  precedente mais próximo (leilão é Governo/Capital-mediado, como o Mercado Central, não comércio
  direto entre colonos).

### O que mudou no schema

- `auctions` (nova): `colony_id`, `resource_type`, `qty`, `lance_minimo_micro`,
  `lance_atual_micro`/`lance_colony_id` (só o lance VIGENTE — cada lance superado já foi devolvido
  em `estorno` no instante em que perdeu, então não há tabela de histórico de lances: o ledger é o
  histórico), `status` (`aberto`/`arrematado`/`sem_lance`/`cancelado`), `deadline_at`.
- `tax_events.kind` saiu do enum e virou `string(30)` — mesmo motivo que tirou `ledger.type` do
  enum no D-58: acrescentar um `kind` (`leilao_venda`) não deveria exigir ALTER de enum, caro no
  MariaDB e mal suportado no SQLite dos testes.
- `Ledger::TIPOS` ganhou três: `escrow_leilao`, `venda_leilao`, `compra_leilao`.

### A tela

Nova aba "Leilões" no Mercado Central (`Mercado.tsx`, ao lado de "Ofertas globais") — o mesmo
critério do D-49 já tratava leilões como parceiro do Mercado Central, não do comércio informal
entre colonos. Anuncia, vê os leilões abertos com contagem regressiva, dá lance, e "Seus leilões"
mostra o histórico (qualquer status) de quem anunciou ou dá o lance vigente.

### Validado

13 testes novos (`LeiloesTest`) + suíte completa: 818 passando. `tsc`/`lint`/`build` limpos.
Estendi `mercado.e2e.mjs` (a única suíte que já tinha o Mercado Central de pé) com o fluxo que dá
para provar com uma colônia só logada — anunciar, ver o escrow sair do depósito, cancelar sem
lance e ver o lote voltar; o lance de OUTRA colônia e o fechamento por prazo já estão cobertos no
Feature test do backend. Rodei a suíte isolada (`mercado.e2e.mjs`) duas vezes: a primeira falhou no
lançamento do Chromium (`TargetCloseError`) sob pressão de memória do servidor de 4 GB — nada a ver
com o código, e o `e2e.sh` cheio já tinha mostrado o mesmo tipo de falha aleatória em telas que este
D-129 nunca tocou (Chat, mobile). A segunda passou nos 8 checks de Leilões; um check NÃO relacionado
("a carroceria soma os dois recursos", D-65, código que este D-129 não tocou) flakou uma vez — o
próprio arquivo de teste já documenta corrida de tempo conhecida nesta suíte.

---

## D-130 — Cargos Públicos (§14.2): os 4 que sobravam depois do Conciliador. 3 implementados, 1 fora.

**Data:** 2026-07-20 · **Status:** implementado — Repórter, Fiscal de Mercado, Auxiliar de Tesouro ·
GDD: §14.2 (só existe nas revisões arquivadas do v35; a v36 não tem seção de cargos)

Depois de Leilões (D-129), a próxima frente escolhida foi os Cargos Públicos. Diferente de Leilões,
aqui o GDD tem texto — só que **contraditório entre duas revisões arquivadas do mesmo documento**,
e nenhuma delas chegou a ser promovida à v36 consolidada.

### A contradição de versão, e por que ela não trava a implementação

`FERTWAYS_GDD_v35_MESTRE_UNIFICADO.html` guarda revisões arquivadas lado a lado. Duas tratam de
Cargos Públicos e se contradizem:

- **v30** ("14. Cargos Públicos Neutros"): os 5 cargos exigem status de **Neutro Registrado** e o
  checklist inteiro do §14.3 (7 critérios: sem avaliação baixa em 7 dias, sem restrição comercial
  em 7 dias, não bloqueado de leilões, 35% de contribuição à terraformação, 10 missões diárias
  concluídas, entre outros).
- **v32** ("09. Cargos cívicos e governança") **corrige isso explicitamente** — o changelog da v35
  registra a mudança: *"Cargos cívicos: Neutro Registrado é exclusivo do Conciliador; demais
  contratos têm limites e não aplicam sanções/dados privados."* Os outros 4 cargos viram
  "contratos cívicos limitados": um índice de reputação "alto" cada, remuneração moderada com teto
  semanal, sem o checklist inteiro do §14.3.

Esta não é uma contradição entre duas seções do MESMO documento (onde a regra de precedência já
usada em outras decisões mandaria olhar o § maior, dentro da mesma parte) — é entre duas REVISÕES
arquivadas, e uma delas já tem o próprio changelog do documento dizendo que corrige a outra. Segui
a v32, pelo mesmo
motivo que o D-50 já seguiu: o Conciliador foi implementado citando exatamente essa frase do
changelog ("Neutro Registrado é exclusivo do Conciliador"). Reabrir essa leitura agora, para os 4
cargos que faltavam, seria contradizer uma decisão já tomada sem fato novo.

### O que o §14.2 publica sobre CADA cargo — e o que fica de fora

| Cargo | O que faz (§14.2) | Índice exigido (v32, §9) |
|---|---|---|
| Repórter | Escreve resumos/notícias de eventos do servidor, do Gagarin, da comunidade | Conduta Social |
| Fiscal de Mercado | Monitora preços suspeitos, sinaliza manipulação à Secretaria de Finanças | Confiança Comercial |
| Auxiliar de Tesouro | Aponta inconsistências financeiras, monitora arrecadação | Status Cívico |
| Atendente do Espaçoporto | Ajuda com rotas, taxas, docas e estacionamento do Espaçoporto | Conduta Social |

**Nenhum dos dois números do §14.2 é publicado** — nem em v30 nem em v32: "salário fixo/dia" e
"bônus por X" aparecem sem valor nas duas revisões, para os 4 cargos novos (só o Conciliador tem
os dois números, no §26.7). Em vez de inventar um valor solto, reusei os do Conciliador
(`PunicaoSpecs::SALARIO_DIARIO_MICRO` = 50 Fert$/dia, `::BONUS_MICRO` = 3 Fert$) — é o único
número que o GDD publica para cargo cívico, e a v32 descreve os 5 cargos como do mesmo porte
("remuneração moderada", "nenhum cargo concede Fert$ suficiente para distorcer a economia").

**O teto semanal da v32 também não tem número** ("remuneração moderada, com teto semanal"; sem
valor). Arbitrei 400 Fert$/semana — um pouco acima do salário-base de 7 dias (350), com espaço para
uma matéria ou sinalização confirmada a mais na semana, sem deixar bônus empilhar sem limite. Sem
coluna nova para "quanto já ganhou": o teto lê o ledger dos últimos 7 dias
(`App\Domain\Cargos\TetoSemanal`), mesma filosofia do D-129 de não duplicar o que o ledger já prova.

**Atendente do Espaçoporto FICA DE FORA.** É 100% dependente do Espaçoporto (§17.5/§21.10/§23), que
não existe — nenhuma rota, doca, taxa ou fila para "atender". Diferente de Leilões (D-49 a D-129),
que pelo menos tinha o Mercado Central por baixo para ancorar enquanto esperava, aqui não há
NADA: nem uma tela estática para o cargo mediar. O painel da Capital já "conta a verdade" sozinho
("as rotas do Espaçoporto não abriram... ninguém viaja ainda", D-52). Nomear alguém para um cargo
sem função nenhuma seria emissão pura — pagar 50 Fert$/dia por nada, pior que o "conciliador ganha
kit inicial por dia sem jogar" que o próprio D-50 já sinalizou como risco a vigiar. Quando o
Espaçoporto existir, este cargo entra do mesmo jeito que Leilões entrou: com o sistema que o
sustenta já de pé.

### Sem gate de elegibilidade automático — mesmo motivo do Conciliador

O §14.2/v32 exige um índice "alto" por cargo, e nenhuma revisão publica o número. O Conciliador já
resolveu isso ficando 100% na mão do operador (`fertways:conciliador`, D-44: "enquanto não houver
substrato para a elegibilidade, o cargo é ligado à mão pelo operador"). Segui o mesmo caminho para
os 3 novos — `fertways:cargo-civico` nomeia sem checar índice nenhum. Inventar um limiar numérico
("alto" = 600? 700?) seria arbitragem sem base textual nenhuma, diferente do que já existe (D-43
define 200 como limiar de BLOQUEIO, não de elegibilidade "alta" — os dois conceitos não são o
mesmo número por acidente).

### O que cada cargo FAZ de verdade

- **Repórter**: publica no MESMO mural da Central de Notícias que o operador já usa (`News`,
  D-55), com `kind = 'boletim'` em vez de `'comunicado'` — a distinção que o schema já reservava
  desde o D-55 sem nada nunca ter usado o segundo valor. Sem fila de aprovação: a v32 pede
  "conteúdo aprovado", mas nenhuma outra escrita de jogador no jogo (Chat, evidência de denúncia)
  passa por moderação — construir uma só para isto seria inventar um mecanismo à parte.
- **Fiscal de Mercado e Auxiliar de Tesouro**: sinalizam texto livre para a equipe
  (`CivicFlag` — mesmo molde de uma denúncia do Ministério, sem punição do outro lado). O bônus só
  paga quando a equipe CONFIRMA (`fertways:cargo-civico --confirmar-sinalizacao=`), nunca no ato
  de sinalizar — senão qualquer texto renderia Fert$ de graça. Não implementei a parte que o
  §14.2 descreve como "monitora painéis agregados" automaticamente (detecção de anomalia/cartel
  de preço) — isso exigiria uma heurística que o GDD não especifica em número nenhum, e seria
  inventar um SEGUNDO mecanismo (o de detecção) em cima de um cargo já sem número publicado.

### Schema

- `civic_posts` (nova, separada de `users`): não toquei nas colunas do Conciliador — evita risco
  em dado de produção, e generaliza só os 3 cargos novos. `kind` é string, não enum (mesmo motivo
  do D-58/D-129: não travar em ALTER se um cargo novo entrar).
- `civic_flags` (nova): a sinalização do Fiscal/Auxiliar.
- `Ledger::TIPOS` ganha `salario_cargo_civico`, `bonus_cargo_civico`.
- Nenhuma migration em `news` — `kind = 'boletim'` já existia no enum desde o D-55.

### A tela

Perfil ganha a seção "Cargos Públicos": salário, e o formulário de sinalização para quem é Fiscal
ou Auxiliar. A Central de Notícias ganha "Publicar matéria" para quem é Repórter, e as matérias
aparecem marcadas "boletim" no mural, distintas dos comunicados oficiais.

### Validado

12 testes novos (`CargosCivicosTest`) cobrindo acumular cargos, pagamento diário com corte por
tick duplicado, suspensão/reintegração, demissão revogando o ato do Repórter, o boletim saindo com
o `kind` certo, o bônus só pagando na confirmação (nunca no ato de sinalizar), e o teto semanal
barrando bônus além de 400 Fert$. Suíte completa: 830 passando (1 teste pré-existente do mural de
Notícias precisou de ajuste mecânico — `CapitalController::news()` passou a exigir `Request` para
saber quem pergunta — sem mudar o que ele prova). `tsc`/`lint`/`build` limpos. Estendi
`capital.e2e.mjs` (já visitava a Central de Notícias) com o fluxo do Repórter publicando de
verdade — o `tools/e2e.sh` agora nomeia o colono do e2e Repórter, do mesmo jeito que já o nomeia
conciliador. Rodado isolado, verde ponta a ponta. O formulário de sinalização do Fiscal/Auxiliar
(em Perfil.tsx, tela sem nenhuma suíte e2e própria) não ganhou e2e dedicado — coberto pelos testes
de Feature do domínio inteiro (sinalizar, confirmar, teto semanal) e pela build/tsc limpos.

---

## D-131 — O Tanque de Combustível trava produção no teto (§21.9); a Captação de Água não ganhou.

**Data:** 2026-07-20 · **Status:** implementado, só o Tanque · GDD §21.9

O usuário escolheu esta pendência específica de uma lista que eu tinha levantado. Diferente das
frentes anteriores (Federação, Ranking de Guerras, Leilões, Cargos Públicos — sistemas inteiros
faltando), esta é uma lacuna pontual dentro de um sistema que já existe: produção e estoque.

### O que o §21.9 publica, e o que não publica

O Tanque de Combustível tem capacidade publicada por nível, duas vezes redundante no v35:
**200 / 300 / 450 / 675 / 1.012** (níveis 1 a 5, curva 1,50×). A Captação de Água **não tem
nenhum número de capacidade em lugar nenhum do GDD** — só a produção por hora (§4.2, já
implementada). A própria seção 10 do v36 erra ao tratar as duas como igualmente sem número; a
tabela por-construção da mesma v36 acerta: só o Tanque tem capacidade publicada.

**Por isso só o Tanque ganhou teto.** Inventar uma curva de capacidade para a Captação de Água
seria arbitrar um número que nenhuma revisão do GDD sequer tenta publicar — diferente do teto
semanal do D-130, que pelo menos tinha "com teto semanal" como conceito no texto para ancorar a
arbitragem. Aqui não há nem o conceito.

### Duas perguntas que exigiram decisão do usuário, não arbitragem solo

**1. Que recurso o Tanque guarda.** "Armazena Gelo de Metano refinado" não bate com nenhum
`resource_type` do catálogo: Gelo de Metano é o minério bruto (raro, insumo de construção), sem
forma "refinada" própria nem processo de refino no jogo. Biocombustível é o recurso que o jogo já
produz por refino (Destilaria, §18.2: 2 Biomassa + 3 Energia → 1 Biocombustível) e cuja semântica
de "combustível" já existe no nome. Arbitrei o Tanque capando Biocombustível — sem perguntar,
porque a única alternativa (Gelo de Metano) não tem processo de refino nenhum para "travar", então
não havia ambiguidade real de comportamento, só de rótulo.

**2. O que acontece ao bater o teto — esta sim perguntei.** O jogo já tem DOIS padrões que se
contradizem: o Depósito da Capital BLOQUEIA a entrega que não cabe (D-58); o Depósito de Zona
Neutra tinha um teto que travava a extração, e isso foi revertido de propósito em 2026-07-12
porque zerava o saque de guerra — hoje ele só marca "exposto", nunca bloqueia produção nenhuma
(`Protegido.php`). Implementar o Tanque como bloqueio duro arriscava reintroduzir exatamente o
tipo de trava que já causou problema uma vez, num contexto estruturalmente parecido (capacidade de
construção que cresce por nível). O usuário escolheu **travar a produção no teto** — a leitura
literal de "guardar mais não faz diferença" — ciente do risco. Não há saque de colônia hoje (o
saque do D-66 só mira Zona Neutra), então o motivo que forçou a reversão de 2026-07-12 não se
aplica aqui.

### Como trava

A Destilaria (`ColonyTick::converter()`) ganhou um teto de saída opcional: quando o Biocombustível
já ocupa todo o espaço do Tanque, a conversão **para por completo** — sem gastar Biomassa/Energia
à toa, sem descartar excedente. Com espaço parcial, converte só o que cabe (arredondado pelo
insumo disponível, como já fazia). Sem Tanque erguido, capacidade é 0: a Destilaria nunca produz
nada — coerente com "não há onde guardar". As outras duas conversões da mesma função
(Refinaria→Compostos Químicos, Oficina→Componentes) não ganharam teto: nenhuma das duas tem
capacidade publicada em seção nenhuma.

`App\Domain\Colony\TetoDoTanque` é a única fonte da curva — sem tabela nova no banco, é uma
constante por nível, do mesmo jeito que `Deposito::TETOS` (Mercado Central) já faz por classe de
recurso.

### A tela

O HUD já lista os 26 recursos (`Hud.tsx`); a linha de Biocombustível agora mostra "X / Y" quando
há Tanque erguido, em vermelho quando cheio — para o jogador nunca descobrir o teto por tentativa e
erro, mesma exigência que `Deposito::exigirEspaco` já cumpre no Mercado Central. A curva está
duplicada no frontend (só para exibição; quem trava é o backend) — mesmo risco de duplicação que
outras curvas de exibição já assumem neste código quando o valor é uma constante pequena e
estática, não uma regra de negócio.

### Validado

5 testes novos em `TickColoniesTest` (curva por nível batendo com o GDD, trava parcial, trava
total sem gastar insumo, ausência de Tanque zerando a produção) + 2 testes pré-existentes de
Destilaria ajustados para erguer um Tanque com espaço de sobra, já que a conversão agora exige um
onde guardar. Suíte completa: 834 passando. `tsc`/`lint`/`build` limpos. Sem e2e novo — mudança é
backend + uma linha do HUD já coberta por type-check; a suíte de Mapa/Frota (`telas.e2e.mjs`), que
abre o HUD, já rodou verde nesta sessão antes desta mudança.

---

## D-132 — Os Destroços da Endurance ganham mapa próprio e a Loja de Peças (§05): 8 seções × 4 camadas.

**Data:** 2026-07-20 · **Status:** implementado · GDD: §02 (narrativa), §05 (curva do Marco), §9.4
(cita "leilões da Endurance")

Pedido do usuário: ler as artes das 8 seções do casco e o GDD, conversar sobre uma "loja de
artefatos" por seção antes de implementar, e — depois de eu relatar o que o GDD publica e o que
não publica — desenhar a tela e o sistema. Registrado em duas rodadas de pesquisa e um plano
aprovado antes de qualquer código (ver histórico da conversa; não repito aqui o que já está no
plano).

### O que o GDD publica, e o que não

`Endurance.tsx` (área Oeste da Capital) era só texto, e admitia: "nem as peças nem as missões
foram construídas". O §05 liga peças históricas ao Marco — já implementado (`Curva.php`,
`ExigirMarco.php`, que já tinha o comentário "os demais desbloqueios do §05 ganham gate no dia em
que os sistemas existirem"):

| Marco | Título | Desbloqueio |
|---|---|---|
| 10 | Pioneiro | Peças comuns da Endurance |
| 35 | Construtor | Peças de reputação nível 1 |
| 75 | Guardião | Peças de reputação nível 2 |
| 100 | Lenda de Fertways | Leilões de peças únicas |

O §9.4 ("reputação negativa bloqueia acesso a leilões da Endurance") mostra que o D-129 (Leilões)
é, no vocabulário do próprio GDD, o mecanismo de "peças únicas" — mas os dois sistemas não se
encaixam de graça: `Auction` só vende `resource_type` fungível, não item de catálogo único.
**Não integrei com o D-129 nesta rodada** — a camada "única" fica na mesma loja, com escassez real
(só uma colônia no servidor pode ter cada peça única, checado sob lock na compra, não por
constraint de banco). Uma integração de verdade com Leilões é extensão futura, sinalizada aqui,
não construída.

Nem o que uma peça FAZ, nem o preço, nem se são 8 ou 32 itens — nada disso o GDD publica. Perguntei
ao usuário as três coisas antes de desenhar:

1. **O que a peça faz**: dá um bônus real (não só cosmético).
2. **Como se adquire**: compra com Fert$, liberada pelo Marco.
3. **Escopo**: as 8 seções × as 4 camadas do §05 — 32 itens.

### Os números — todos arbitrados, nenhum do GDD

- **Preço** (Fert$): comum 20 · reputação 1 60 · reputação 2 150 · única 500.
- **Bônus**: desconto de tributo, não bônus de produção — reaproveita o MESMO ponto de extensão do
  desconto de aliados (D-120: `intdiv($bpsCheio * (10_000 - $desconto), 10_000)`), em vez de abrir
  uma segunda frente na fórmula de produção do `ColonyTick`, que atravessa 26 recursos e é código
  bem mais sensível. Por peça: comum 1% · reputação 1 2,5% · reputação 2 5% · única 10% — somado
  entre todas as peças possuídas, com **teto agregado de 30%** (`EnduranceSpecs::TETO_DESCONTO_BPS`),
  para uma coleção completa nunca chegar perto de zerar o tributo do raro (que já é só 1% cheio).
  Aplicado nos dois pontos onde o jogo já calcula tributo — `ConcluirTrechos::aliquota()` (entrega
  por transporte) e `ExecutarOrdem::fechar()` (venda no Mercado Central) — depois do desconto de
  aliados, nunca antes. `FecharLeiloes` (D-129) não ganhou o desconto nesta rodada, para não mexer
  em três sistemas de tributo na mesma entrega.
- O catálogo (32 peças) vive em código (`App\Domain\Endurance\EnduranceSpecs`), não em tabela —
  mesmo argumento já usado para `PunicaoSpecs::VIOLACOES`: é dado de design arbitrado, não conteúdo
  administrável.

### A tela — por que não é Phaser

A Capital já ensinou essa lição uma vez: cenas de Phaser são canvas, sem DOM, e o e2e não clica em
pixel — foi por isso que os sete ministérios "saíram pálidos" no D-63, e por isso que `Zona.tsx` já
é SVG à mão, não Phaser. Para os destroços fui mais direto ainda: como as 8 artes já são imagens
reais (não vetores), a tela é **DOM puro** — `<img>` de verdade, posicionadas por CSS, dentro de um
contêiner com a MESMA câmera que Colônia e Capital já usam (`useVista()`, `ControlesDeZoom` —
zero código de zoom novo). Testável por e2e de verdade, sem inventar um terceiro motor de
renderização.

**Rota própria** (`/capital/endurance`), não mais um `sub` local dentro do modal da Capital — o
padrão dominante do app já é rota de verdade (`/mapa`, `/zona/:id`, `/mercado/:contexto`); só a
Capital ainda trocava painel por `useState`, sem sobreviver a um recarregamento. Os atalhos à
esquerda (Governo Central, Mercado Central, Espaçoporto) não têm precedente no app — hoje não existe
um jeito de pular entre áreas da Capital sem voltar à praça — então a barra é local desta tela, não
um padrão novo do Header global. O Espaçoporto continua sendo um `sub` da Capital (fora de escopo
mudar isso agora): o atalho navega para `/capital` levando um pedido no estado da rota
(`location.state.abrirSub`), que `Capital.tsx` lê num `useEffect` — sem promovê-lo a rota própria.

### Validado

11 testes novos (`LojaDaEnduranceTest`): o catálogo tem 32 peças, o marco trava a compra, Fert$
debita e credita o Tesouro certo, ninguém compra a mesma peça duas vezes, peça única esgota depois
da primeira compra (segunda colônia recusada) mas colônias diferentes podem ter a mesma camada em
seções diferentes, Fert$ insuficiente é recusado, o desconto soma e respeita o teto de 30%, e o
desconto reduz o tributo nos dois pontos onde ele se calcula (transporte e Mercado Central).
Suíte completa: 845 passando — sem regressão em `ConcluirTrechos`/`ExecutarOrdem`, que já tinham
cobertura extensa antes desta mudança. `tsc`/`lint`/`build` limpos. Estendi `capital.e2e.mjs` (já
visitava a Capital): abre a Endurance, confere os 8 destroços e as 8 seções da loja, compra uma
peça de verdade (o colono do e2e nasce no marco 20, acima do marco 10 exigido) e confere os dois
atalhos de navegação mais delicados (Mercado Central direto, e Espaçoporto via estado de rota).
Rodado isolado, verde ponta a ponta.

---

## D-133 — A Loja de Peças da Endurance ganha CRUD no painel: preço, marco e efeito, sem tocar código.

**Data:** 2026-07-20 · **Status:** implementado

Pedido direto do usuário: um CRUD em `/central/admin` para definir preço, marco, imagem e o efeito
de cada uma das 32 peças do D-132 — que até aqui viviam hardcoded em `EnduranceSpecs.php`, com o
próprio comentário da classe dizendo "mude aqui, não escondido". Agora "aqui" é o painel.

### O catálogo saiu do código, virou tabela

`endurance_piece_specs` (nova) guarda as 32 linhas; a migration que a cria já as **semeia** com os
valores que já estavam em produção — ninguém perde estado. `EnduranceSpecs::catalogo()` continua
sendo a única porta de leitura, com a MESMA assinatura pública: `ComprarPeca`, `DescontoDeEndurance`
e `EnduranceController` não mudaram uma linha, só a fonte por baixo trocou de constante PHP para
consulta ao banco.

### A imagem não entrou no CRUD — e não é omissão

O pedido citava "a imagem" entre os quatro campos. Mas a imagem de uma peça da Endurance não é uma
propriedade DELA: é da SEÇÃO do casco (D-132, `Vinculaveis::SECOES_DA_ENDURANCE`) — as 4 camadas
de "Anel Habitacional" compartilham a mesma arte, porque só existem 8 imagens (uma por seção), não
32. Duplicar um seletor de imagem em cada uma das 32 linhas deixaria o operador escolher "uma
imagem para a peça comum do Anel Habitacional" sem avisar que isso silenciosamente mudaria a arte
das outras três camadas da mesma seção também — surpresa ruim. A tela nova mostra a miniatura atual
de cada seção (lida de `image_bindings`, sem duplicar o mecanismo) e linka direto para
`/admin/imagens?cat=destrocos-da-endurance`, que já resolve isso desde o D-132.

### O padrão clonado — "grade salva de uma vez", não 32 forms soltos

O painel já tinha dois moldes de CRUD: singleton (um form, N campos escalares — `WarSetting` etc.)
e lista com form-por-linha (`MissionTemplate`, `admin.missoes`). Nenhum dos dois serve bem para "32
linhas com identidade própria, editadas juntas": clonei um terceiro que já existe — **Gestão de
Construções → Silo** (`AcoesController::construcoesSilo`), uma grade só, um `<form>` só, um POST só,
`updateOrInsert`/`update` por linha, guardado contra `peca_key` forjado (só grava o que já existe
no catálogo). Mesma auditoria automática do resto do painel (`$this->tentar()`).

### Validado

7 testes novos (`EnduranceAdminTest`): a migration semeia os 32 valores certos, a tela lista
agrupada por seção, o admin edita uma peça e ela reflete de verdade na compra
(`ComprarPeca`/`DescontoDeEndurance`, sem precisar reiniciar nada — é o mesmo banco), chave forjada
no POST não cria linha, e as duas rotas exigem admin autenticado. Suíte completa: 852 passando —
`LojaDaEnduranceTest` (D-132) segue verde sem alteração nenhuma, confirmando que a troca de fonte
(código → banco) foi transparente para quem compra. Sem mudança de frontend — o painel admin é
Blade puro, sem build.

---

## D-134 — Pendência: as 4 camadas da Loja de Peças da Endurance não se diferenciam o bastante.

**Data:** 2026-07-20 · **Status:** pendente de revisão — não é reversão, é sinalização

O usuário revisou o D-132/D-133 e não gostou: perguntou "qual a diferença entre comprar um item
comum ou de reputações?", e a resposta honesta é **pouca**. Hoje as 4 camadas (comum, reputação I,
reputação II, única) são a MESMA mecânica (desconto de tributo) em 4 magnitudes crescentes — marco,
preço e desconto sobem juntos, sem nenhuma diferença qualitativa entre elas. O nome "reputação" é
herdado literalmente do vocabulário do §05 do GDD ("peças de reputação nível 1/2"), mas a mecânica
não olha pros quatro índices do Ministério (Confiança Comercial etc.) — só pro Marco. A única
diferença estrutural de verdade é a camada `unica`, que tem estoque global de 1; as outras três são
ilimitadas.

**Isto fica registrado como pendência a revisar, não como algo a desfazer agora.** O usuário disse
"vamos ter que revisar isso depois" — não pediu reversão nem correção imediata. O sistema continua
em produção como está (D-132/D-133); isto é a marca do lugar exato onde a próxima sessão deve
retomar a conversa, antes de tocar em código.

**Direções possíveis para a revisão** (não decididas, só levantadas para não começar do zero):
- Ligar "reputação" à mecânica de verdade: exigir também um piso num dos quatro índices do §26.2
  (ex.: Confiança Comercial ≥ X), não só o Marco — faria o nome dizer a verdade.
  - Efeitos DIFERENTES por camada, não só maiores — ex.: a camada comum dá desconto de tributo (o
  que já existe), reputação dá outra coisa (bônus de produção? redução de tempo de construção?),
  única dá algo realmente exclusivo (cosmético + funcional). Evita que colecionar as 32 seja só
  "comprar a mesma coisa 4x mais cara".
- Repensar se "camada" devia ser eixo de PREÇO ou eixo de RARIDADE/tipo de efeito — hoje confunde
  os dois.

Nenhuma dessas é compromisso: são só o que ficou anotado nesta conversa para a próxima não recomeçar
a leitura do zero.

---

## D-135 — A Loja de Peças da Endurance é refeita do zero: catálogo dinâmico, efeitos que mexem no jogo de verdade.

**Data:** 2026-07-20 · **Status:** implementado (Fase 1 — a Fase 2, Leilões, fica para depois)

Resposta ao D-134: o usuário pediu para refazer por completo, sem mais perguntas depois da primeira
rodada. Pedido literal: um CRUD por seção (as 8 abas do casco), campos por item — id, tipo
(comum/raro/único), quantidade, custo, vendável em Leilão, benefícios (produção, drones, veículos —
"seja criativo"), descrição, imagem — e que cada aba do CRUD vire uma loja diferente no jogo, uma
por destroço da Endurance. Quatro perguntas fechadas antes de desenhar: **efeitos empilhados por
item** (não um só), **marco opcional por item** (não mais obrigatório), **Leilões integrados de
verdade nesta fase** (não só uma marcação — mas ver adiante: virou Fase 2 por gestão de risco, não
por recusa), **imagem continua por seção** (D-133, sem upload por item). Uma quinta pergunta, técnica,
depois de eu pesquisar o motor: bônus de produção em construção que CONVERTE insumo (Siderúrgica,
Destilaria, Refinaria Química, Oficina) devia ser throughput (mais saída E mais consumo) ou saída de
graça? Resposta: **throughput** — mais simples, um único ponto de inserção para as 9 construções.

### O catálogo virou dinâmico de verdade — não é mais "editar 32 linhas fixas"

`endurance_piece_specs`/`colony_endurance_pieces` (D-132/D-133) são **substituídas**, não
estendidas: `endurance_items` (o catálogo — `item_key`, `secao`, `tipo`, `quantidade_total`,
`quantidade_vendida`, `preco_micro`, `marco_minimo` NULÁVEL, `vendavel_em_leilao`, `descricao`),
`endurance_item_effects` (N efeitos por item — `tipo_efeito`, `alvo` nulável, `valor_bps`) e
`colony_endurance_items` (posse, agora com `quantidade` — uma colônia pode ter mais de uma unidade
do mesmo item). O admin cria, edita e apaga item livremente; não existe mais "32 peças fixas que só
se editam".

**Decisões que tomei sozinho, sem parar para perguntar (a instrução desta rodada foi "não me faça
mais perguntas, siga suas sugestões"):**
- **"Item Id"** virou `item_key`, uma chave única digitada no CRUD (não um UUID nem um enum fixo).
- **A quantidade é estoque GLOBAL do servidor**, não por colônia — um item "único" tem
  `quantidade_total=1` (o código força isto no `tipo=unico`, não confia em o admin digitar certo,
  mesmo espírito do `ativa=true` automático de `missaoCriar`); um item comum pode ter estoque de
  milhares, e QUALQUER colônia pode comprar mais de uma unidade dele enquanto houver estoque — os
  efeitos empilham por unidade possuída.
- **A única compra real que existia** (`colony_endurance_pieces`, "Maior Colônia" →
  `baia_criogenica:comum`) **não foi migrada** — o formato antigo (`secao:camada`) não mapeia para
  `item_key` dinâmico. Perda aceita e documentada na própria migration, não bug.

### Os efeitos: vocabulário FECHADO, cada um ligado a um ponto real do motor

`EfeitosDaEndurance` (substitui `DescontoDeEndurance`) tem 6 `tipo_efeito` possíveis — o admin
**combina** efeitos existentes pelo CRUD, não inventa mecânica nova digitando texto livre:

| `tipo_efeito` | `alvo` | Onde liga | Teto agregado por colônia |
|---|---|---|---|
| `desconto_tributo` | (nenhum) | `ConcluirTrechos::aliquota()`, `ExecutarOrdem::fechar()` | 30% (mesmo teto do D-132/D-133) |
| `producao_bonus` | `building_type` ou `global` | `ColonyTick::produzir()` | 50% |
| `velocidade_veiculo` | tipo de veículo ou `todos` | `Conservacao::segundosDoTrecho()` | 50% |
| `capacidade_veiculo` | idem | `Conservacao::capacidadeEfetiva()` | 50% |
| `drone_raio` | (nenhum) | `Avistamentos` (raio de vigia) | 100% |
| `drone_bateria` | (nenhum) | `ConcluirMissoes` (duração da vigilância) | 100% |

Cada efeito soma `valor_bps × quantidade possuída`, entre TODOS os itens da colônia que o têm, capado
no teto do tipo — arbitragem nova, registrada aqui (só o 30% de tributo herda do D-132/D-133; os
outros cinco tetos são inéditos). `EfeitosDaEndurance::bonusDeProducaoPorAlvo()` faz UMA query batched
por tick (não uma por construção erguida) — mesmo espírito anti-N+1 de `manutencaoPorTipo()`.

### A porta que o D-132 fechou de propósito, e que este D-135 reabre

`EnduranceSpecs.php` (D-132) tinha o comentário explícito: o bônus reaproveita o ponto de extensão
do tributo "em vez de abrir uma segunda frente na fórmula de produção do `ColonyTick`, que atravessa
26 recursos". Aquilo era uma decisão deliberada de NÃO tocar produção. Este D-135 reabre essa porta
a pedido explícito do usuário ("um item único pode dar bônus de 20% na indústria siderúrgica, ou na
mina, ou nas fazendas") — registrado aqui para deixar claro que é decisão nova por cima da antiga,
não descuido.

**Produção grátis vs. throughput — a distinção que a pesquisa técnica revelou.** Mina Local, Fazenda,
Captação de Água, Gerador de Atmosfera e Reator de Energia produzem do nada (sem insumo): o bônus aí
é saída extra de graça. Destilaria, Indústria Siderúrgica, Refinaria Química e Oficina CONVERTEM um
insumo — um bônus ali multiplica a TAXA DE ENTRADA (o que `converter()`/`processarSiderurgica()`
consomem), não a saída isolada: mais bônus processa mais Metal Bruto/Biomassa/etc. por hora, e por
isso também CONSOME mais insumo por hora. Escolha explícita do usuário para o v1: mais simples (um
único ponto de inserção comum às 9 construções) do que a alternativa (saída de graça também nas
construções de conversão, que exigiria mexer na lógica de lote/batch em vez da taxa de entrada).

### O painel — três ações de verdade, não mais um POST de grade

`PainelController::endurance(?secao=)` troca o padrão "uma tela só com 8 grupos" pelo padrão de abas
já usado em `admin.construcoes` (`?aba=`) e `admin.missoes`. `AcoesController` ganha
`enduranceItemCriar`/`enduranceItemEditar`/`enduranceItemApagar` — `criar` e `editar` validam o
formulário E o texto dos efeitos (uma linha por efeito, `tipo_efeito:valor_bps` ou
`tipo_efeito:alvo:valor_bps` — mesmo padrão `chave:valor` por linha que `recompensa_recursos` já usa
em `missaoCriar`, para não inventar um segundo jeito de digitar lista no mesmo painel); `editar`
SUBSTITUI os efeitos por completo (apaga e recria — o volume por item é baixo, não vale a pena fazer
diff); `apagar` trava se alguma colônia já possui o item (mesma cautela do `missaoApagar`, que trava
se a missão já foi sorteada) — e `editar` trava se `quantidade_total` cair abaixo do que já foi
vendido. A validação de domínio (formato de efeito, tipo/alvo desconhecidos) mora DENTRO do
`$this->tentar()` de cada ação — ficou de fora na primeira versão do código e um teste pegou: uma
`DomainRuleException` lançada antes de entrar no `tentar()` escapava sem virar mensagem de erro
amigável, e quebrava a tela com um 500.

**Achado da validação do Laravel, registrado para não repetir o erro:** um campo `nullable`+`integer`
(o Marco) que chega **vazio** (`''`) de um `<input>` HTML não vira `null` sozinho — a regra
`nullable` só pula a validação de `integer`/`min`/`max`, mas o valor guardado em `$request->validate()`
continua sendo a string vazia. Sem tratar isto explicitamente, "marco em branco" salvaria `0` no
banco, não "sem exigência". `validarEnduranceItem()` converte `''` para `null` manualmente.

### A imagem — mesma decisão do D-133, não repensada

Continua por SEÇÃO, não por item — 8 imagens, não uma por item. O pedido original citava "imagem:
permita o upload aqui", mas a resposta do usuário à pergunta específica ("não, manter a imagem da
seção") confirmou que isto não mudou.

### Leilões (D-129) — virou Fase 2, não recusa

O usuário pediu integração de verdade com os Leilões nesta rodada. Fica para uma entrega separada,
depois desta estar em produção e verificada: mexer em `ListarLeilao`/`CancelarLeilao`/`FecharLeiloes`
(sistema já em produção, com usuários podendo ter leilões de recurso em andamento) é mais arriscado
misturado com a reconstrução inteira do catálogo/posse. `vendavel_em_leilao` já existe no schema
desta fase — o campo está pronto, só a mecânica de anunciar/arrematar item ainda não liga nele.

### Frontend — o mapa continua, a loja virou uma tela por seção

`EnduranceMapa.tsx` (D-132) muda pouco: `aoAbrirLoja` agora recebe QUAL seção foi clicada, em vez de
só abrir "a loja" genérica. `LojaDaEndurance.tsx` é reescrita: mostra os itens de UMA seção (não mais
8 grupos de 4 camadas fixas) — tipo, estoque (`vendido/total`), preço, marco (se houver), os efeitos
em texto legível, se é vendável em Leilão, e o botão de compra. `client.ts`: `PecaDaEndurance`/
`api.endurance()`/`comprarPecaDaEndurance()` saem; entram `ItemDaEndurance`/`EfeitoDoItemDaEndurance`/
`api.enduranceSecao()`/`api.enduranceEfeitos()`/`api.comprarItemDaEndurance()`.

### Validado

30 testes novos substituem os 18 antigos (`LojaDaEnduranceTest` D-132 e `EnduranceAdminTest` D-133
foram apagados, não deixados quebrados ao lado): `EnduranceItemsTest` (19) cobre a compra (marco
opcional, estoque global entre colônias, item único esgota, Fert$ debita/credita/Ledger), os efeitos
empilhando por quantidade possuída e capando no teto certo, o tributo nos dois pontos de sempre
(transporte e Mercado Central), produção grátis numa construção sem insumo vs. throughput numa de
conversão (Siderúrgica: 2 lotes em vez de 1 com o bônus), bônus de capacidade e velocidade de
veículo, bônus de raio e bateria de Drone (revela zona fora do raio base; vigilância dura além da
bateria base), e as duas rotas player-facing. `EnduranceAdminTest` (11) cobre as 8 abas, criar item
com efeitos empilhados, `tipo=unico` forçando quantidade 1, `item_key` duplicada recusada, efeito
com tipo desconhecido ou sem alvo exigido recusado, editar substituindo efeitos, recusa de baixar
estoque abaixo do vendido, apagar bloqueado com posse, e autenticação exigida. Suíte completa: 864
passando, sem regressão em `ConcluirTrechos`/`ExecutarOrdem`/`Conservacao`/`ConcluirMissoes`/
`Avistamentos`/`ColonyTick` — todos tinham cobertura extensa antes desta mudança tocar neles.
`tsc`/`lint`/`build` do frontend limpos.

---

## D-136 — Fase 2 do D-135: os Leilões (D-129) vendem item da Endurance, não só recurso.

**Data:** 2026-07-20 · **Status:** implementado

A parte que o D-135 deixou para depois, de propósito — "menor risco fazer depois de a Fase 1 provar
que o catálogo/posse novos estão corretos, do que misturar as duas mudanças na mesma entrega". Fase
1 no ar e verificada (deploy, fumaça, migration exercitada no MariaDB de verdade); esta entrega
estende o D-129 sem tocar no que já funcionava para recurso.

### O lote virou OU-OU, não uma segunda tabela de leilão

`auctions.resource_type` tinha FK obrigatória para `resource_types.code` — não cabia um `item_key`
da Endurance ali. Em vez de duplicar toda a máquina de lance/fechamento do D-129 numa tabela nova
só para item, a migration torna `resource_type` e `qty` nuláveis e acrescenta `endurance_item_id`
(FK nulável para `endurance_items`). Um leilão é `resource_type` OU `endurance_item_id`, nunca os
dois — `Auction::ehItem()` é o único jeito certo de perguntar qual é qual (checado em código, não
em constraint: o SQLite dos testes não tem um jeito direto de expressar "exatamente uma de duas
colunas nulas preenchida", e a aplicação já teria de confiar no código de qualquer forma).

### A posse do item é o novo "depósito da Capital"

`ListarLeilao::handle()` (recurso) não mudou uma linha — ganhou uma irmã, `handleItem()`, que
decrementa `colony_endurance_items.quantidade` em vez de `market_accounts.amount` (mesma trava
`where('quantidade', '>=', $qtd)->decrement(...)`, mesmo formato de erro). Duas exigências que o
recurso não tinha: o item precisa estar `vendavel_em_leilao=true` (o admin decide isso por item no
CRUD do D-135 — a Fase 1 já modelava o campo, só ninguém o lia ainda) e a quantidade continua
vinculada ao dono depois do leilão fechar sem lance (a linha de posse nunca é apagada pelo
`decrement`, só zera — o mesmo motivo pelo qual o `apagar` do admin continua bloqueado mesmo com um
leilão em andamento: a linha de posse "existe" o tempo todo, só a quantidade viaja).

`CancelarLeilao` e `FecharLeiloes` ganham um branch por `Auction::ehItem()` nos MESMOS pontos onde
já mexiam em `market_accounts` — devolução ao cancelar, devolução sem lance, crédito ao arrematante.
`FecharLeiloes` unificou os dois casos de crédito (devolver ao vendedor / entregar ao arrematante)
num único método privado (`creditarLote()`) — os dois já faziam exatamente a mesma coisa
(`insertOrIgnore` + `increment`), só que um pouco divergentes por acidente (o caminho "sem lance" da
versão anterior pulava o `insertOrIgnore`, sem motivo — o vendedor sempre tem a linha, mas não
custava nada estar simétrico). `DarLance` não mudou nada: já era agnóstico ao que está sendo
leiloado (só mexe em `colonies.fert_micro`).

### O tributo do item é zero — arbitragem nova, não omissão

`EnduranceItem` não tem `tax_bps` (o preço já é arbitragem do admin no CRUD do D-135; empilhar uma
segunda arbitragem — a alíquota — em cima da primeira não teria base nenhuma para se ancorar). Em
vez de inventar um número, o fechamento de leilão de item grava `tax_bps=0`/`tax_amount=0` em
`tax_events` — o FATO fica registrado (inclusive para a chave única de idempotência que já protegia
o Mercado Central contra o tick rodar duas vezes o mesmo fechamento), só que o vendedor recebe o
lance inteiro, sem desconto, e nada vai para o Tesouro. `tax_events.resource_type` fica `null` para
item (a FK exige um código de `resource_types`, que um item não é — a coluna já era nulável desde a
criação da tabela, D-58).

### O painel de anunciar ganhou uma segunda origem, sem espionar seção por seção

Não havia endpoint nenhum que respondesse "quais itens da Endurance esta colônia possui, vendáveis
em Leilão, de QUALQUER seção" — `/endurance/secoes/{secao}` (D-135) é por seção só, e o formulário
de anunciar não tem como saber quais das 8 o jogador já visitou. `EnduranceController::
meusItensVendaveis()` resolve isto com uma query só (`colony_endurance_items` com `quantidade > 0`,
filtrado por `item.vendavel_em_leilao`). O formulário do Mercado (`Leiloes` em `Mercado.tsx`) ganhou
uma aba Recurso/Item — a mesma tela, dois catálogos diferentes por baixo. `CardDeLeilao` e "seus
leilões" leem `item_key`/`item_nome` quando presentes; o ícone do lote vira uma sigla neutra (não
reaproveita `IconeRecurso`, que colore por classe primário/industrial/raro — um item da Endurance
não é nenhuma das três, e forçar a cor mentiria sobre a classe).

### Validado

6 testes novos (`LeilaoDeItemTest`), espelhando o `LeiloesTest` do D-129: anunciar exige
`vendavel_em_leilao` e posse suficiente, anunciar reserva a posse em escrow (ledger
`escrow_leilao` com `resource_type` nulo), cancelar sem lance devolve a posse, o tick fecha sem
lance devolvendo a posse, e o tick fecha arrematado transferindo a posse ao vencedor com tributo
ZERO (o vendedor recebe o lance inteiro, o Tesouro não recebe nada, `tax_events` grava
`tax_bps=0`/`resource_type=null`) — mais XP dos dois lados pela mesma trilha `mercado_executado`
que qualquer negócio fechado já concede. Suíte completa: 870 passando — `LeiloesTest` (D-129) segue
verde sem alteração nenhuma, confirmando que o branch por `ehItem()` não mudou o caminho de recurso
em nada. `tsc`/`lint`/`build` do frontend limpos.

## D-137 — O painel da Loja de Peças da Endurance ganha uma aba de Manual dos Benefícios.

O campo `efeitos` do CRUD (D-135) é texto livre — `tipo_efeito:valor_bps` ou
`tipo_efeito:alvo:valor_bps` — e o formulário só cabia uma linha de ajuda curta. Um admin
arbitrando um item novo precisava saber de cor os 6 `tipo_efeito`, quais exigem `alvo`, quais
`building_type`/veículo são alvos válidos, e o que cada um realmente faz no motor (grátis vs.
throughput, por exemplo) — informação que só existia espalhada pelo código
(`EfeitosDaEndurance.php`, `ColonyTick::produzir()`) ou nesta entrada de decisões.

**Resolvido com uma aba nova, não um tooltip.** `/central/admin/endurance` ganhou uma aba
`Manual`, ANTES das 8 seções do casco — e virou a aba **inicial**: `PainelController::endurance()`
agora trata `secao=manual` (e qualquer `secao` ausente/desconhecida) como esse manual, não mais
"primeira seção do array" (`array_key_first`). A view (`admin/endurance-manual.blade.php`,
incluída por `admin/endurance.blade.php` dentro de um `@if($secao === 'manual') ... @else ...
@endif`, para não corromper a pilha de `@section` do Blade com um `return` no meio do template)
documenta:

- o formato de linha e a regra `100 bps = 1%`;
- a tabela dos 6 tipos com o teto agregado por tipo (os mesmos de `EfeitosDaEndurance::TETO_BPS`,
  sem duplicar o número por engano — copiados direto da constante ao escrever a view);
- quais `alvo` são válidos por tipo: para `producao_bonus`, as 9 construções que produzem de fato
  (as únicas 9 que `ColonyTick::produzir()` lê `$bonusPara($tipo)`) mais `global`, separadas em
  "sem insumo → bônus de graça" (Mina Local, Fazenda, Captação de Água, Gerador de Atmosfera,
  Reator de Energia) e "de conversão → throughput" (Destilaria, Indústria Siderúrgica, Refinaria
  Química, Oficina) — a MESMA distinção que já existia só em comentário de código
  (D-135: "reabrir uma porta fechada de propósito"); para `velocidade_veiculo`/
  `capacidade_veiculo`, `furgao_de_comercio`/`caminhao_de_carga`/`todos`;
  `desconto_tributo`/`drone_raio`/`drone_bateria` não têm alvo;
- como o bônus empilha (soma `valor_bps × quantidade` entre TODOS os itens da colônia, teto
  cortando o excedente sem erro) com um exemplo numérico (3 unidades de +20% somam 60% cru, mas o
  teto de 50% corta o efetivo em 50%);
- uma tabela de 8 exemplos completos, um por tipo (mais um repetido para `producao_bonus` com
  alvo `global` e outro para `throughput`), cada um com a linha exata e o efeito em português.

Escolhi aba (não modal/tooltip) porque o admin já navega por aba nesta tela (D-132/D-133) — zero
JS novo, mesma convenção. Verificado com um teste de renderização ad-hoc (GET `/admin/endurance`
sem `secao` mostra o manual por padrão; GET com `?secao=anel_habitacional` continua mostrando o
catálogo normalmente) e descartado depois de confirmar — não é uma regra de negócio nova, é
documentação, então não ganhou um teste permanente na suíte. Suíte completa (870 testes)
inalterada, sem regressão em `EnduranceAdminTest`.

---

## D-138 — A zona neutra ganha demolição de estrutura: a assimetria com a colônia, fechada.

**Data:** 2026-07-20 · **Status:** implementado, decisão própria · GDD: nenhuma seção (mesmo caso
do D-59/D-129) · Fecha o achado 7 do D-122/D-123

Depois do D-137, fui atrás do próximo item do GDD sem base já arbitrada pendente. A revisão de
zonas de 2026-07-19 (D-122/D-123) tinha achado onze pontos e escolhido seis para fechar; o achado 7
ficou de fora, registrado como o que era — **uma lacuna real, nunca decidida nem discutida**, não
uma contradição deliberada como o tributo (D-32) ou a frota que nunca trava (D-60):

> "Não existe demolição nem downgrade de estrutura de zona (...). Implementá-la abriria perguntas
> de design que esta revisão não respondeu (demolir devolve material? reduz o custo de manutenção
> na hora? undo de saque de guerra?) — não implementar sem antes decidir essas perguntas com o
> usuário." (`Domain\Zona\Estruturas`, docblock, D-122/D-123)

O usuário já tinha dado a instrução padrão ("siga por todas as fases, não me faça mais perguntas,
siga suas sugestões") — inclusive reiterada nesta sessão. As três perguntas ficam respondidas aqui,
com o MESMO julgamento que a colônia já usa para o problema idêntico (`Domain\Building\Demolir`,
D-59), não uma regra nova:

1. **O investido não volta.** Nenhum material do canteiro é devolvido, nenhum Fert$ — a mesma
   perda seca que `Demolir::handle()` já aplica à colônia (D-59: "o custo já foi lançado, e a
   construção vira pó").
2. **A manutenção NÃO cai — e essa resposta exigiu ler o código antes de prometer.** Fui conferir
   `NeutralZone::custoDeManutencao()` antes de escrever a resposta óbvia ("reduz, é claro"): ela é
   função só de `level` — o Posto de Comando, por `SubirNivelDaZona` — e **nunca leu** Muralha,
   Torre, Bastião nem nenhuma das outras 12 colunas de `Estruturas::COLUNA`. Demolir uma delas não
   move um número que já não dependia dela. Não é um efeito que este trabalho decide não ter; é um
   efeito que a manutenção nunca teve, para nenhuma estrutura, mesmo antes de demolição existir —
   e documentar isso errado seria pior do que não demolir nada.
3. **Não desfaz saque de guerra.** O `Ledger`/`ZoneEvent` de um saque já sofrido não muda: demolir
   é sobre o AGORA da estrutura, e nada aqui apaga ou reescreve um evento passado — a mesma régua
   de "todo Fert$/recurso tem história" que o item 4 do D-122 já defendia para o upgrade perdido no
   abandono.

**Duas guardas vêm de fora dessas três perguntas, por analogia direta com o que já existe, não por
arbitragem nova:** o Posto de Comando é indemolível (nasce com a ocupação, D-52 — mesmo motivo que
torna as cinco essenciais da colônia indemolíveis: sem ele não há controle territorial sobre a
zona), e não se demole sob cerco nem com uma obra em curso sobre a mesma estrutura — o espelho
exato de "não se constrói/investe sob sítio" (`ConstruirNaZona`/`SubirNivelDaZona`) e "não se
demole o que está em obra" (`Demolir`, colônia).

### O desenho

`Domain\Zona\DemolirEstruturaDaZona` — zera a coluna (`Estruturas::COLUNA[$estrutura]`) de volta a
0, em vez de apagar uma linha: a zona é uma tabela de NÍVEIS, não um catálogo de slots como a
colônia, então "demolir" aqui é o mesmo estado de quem nunca ergueu nada ali, só que sem `delete()`
— não há linha para apagar. Grava um `ZoneEvent` (`estrutura_demolida`, com `meta.nivel_perdido`)
para o Histórico da zona já mostrar, mesma prática do D-122 item 4.

`ZoneController::demolir()` — nova rota `DELETE /zones/{zone}/build/{structure}`, ao lado de
`POST /zones/{zone}/build` (`ConstruirNaZona`). Exige a MESMA palavra `Demolir::PALAVRA` que a
colônia já exige (D-61) — reaproveitada da classe da colônia, não duplicada, porque é a mesma
guarda pelo mesmo motivo: uma confirmação que vivesse só no React protegeria contra o dedo
escorregando e contra mais nada.

`Zona.tsx` ganha o mesmo padrão visual que `Hud.tsx` já usa para a colônia (botão discreto →
confirmação por escrito → "Demolir mesmo assim"), com um aviso extra específico da zona: a
manutenção não muda (para não deixar o colono achar que está "economizando" ao demolir).

### Validado

11 testes novos (`DemolirEstruturaDaZonaTest`): zera o nível, grava o `ZoneEvent` com o nível
perdido, não devolve material do canteiro, a manutenção de fato não muda (comparação antes/depois),
Posto de Comando indemolível, recusa demolir o que nunca foi erguido, recusa com obra em curso na
MESMA estrutura, recusa sob cerco, recusa dono errado, a rota HTTP exige a palavra (3 tentativas
erradas + 1 certa), e a obra seguinte após demolir começa do nível 1 — não do nível anterior. Suíte
completa: **881 passando** (870 + 11), sem regressão. `tsc`/`lint`/`build` do frontend limpos. e2e
completo, **9/9 verde** (`zonas.e2e.mjs` incluso, sem regressão nas 12 estruturas já cobertas).

---

## D-139 — O e2e da Endurance estava quebrado desde o D-135, e ninguém tinha notado.

**Data:** 2026-07-20 · **Status:** correção, achada verificando o D-138 · GDD: nenhuma (bug de teste)

Rodando o e2e completo para validar o D-138 (que não toca a Endurance em nada), `capital.e2e.mjs`
deu vermelho na seção da Endurance — não por causa da zona: `capital.e2e.mjs` ainda testava a
Loja de Peças no formato do D-132/D-133 (8 seções numa tela só, texto "Você tem esta peça"), e o
D-135 (2026-07-20, mais cedo nesta mesma sessão) **reescreveu a tela inteira** para mostrar UMA
seção por vez (`LojaDaEndurance.tsx`) sem nunca reexecutar este e2e — a entrada de decisões do
D-135 registra "870 testes PHP" e "`tsc`/`lint`/`build` limpos", mas **não** menciona e2e, então
não foi uma alegação falsa, só uma verificação que nunca aconteceu.

**Um segundo problema, descoberto ao consertar o primeiro**: o catálogo dinâmico do D-135 nasce
VAZIO num banco novo (o admin é quem cria itens pelo painel; a v1 tinha 32 linhas fixas sempre
presentes via migration). Sem nenhum item semeado, a tela da Endurance no e2e não teria o que
mostrar nem comprar — `tools/e2e.sh` ganhou um item de teste (`reator_de_teste_e2e`, seção
`comando`, comum, 10 Fert$, um efeito de `desconto_tributo`) para o fluxo de compra continuar
exercitável.

`capital.e2e.mjs` corrigido: confere que `destrocos[0]` (seção "comando") abre SÓ a própria seção
(`data-secao-loja="comando"`, não mais `.length === 8`), que o item semeado aparece no catálogo, e
que comprá-lo mostra "Você tem 1" (o texto novo de `item.possuo`, não mais "Você tem esta peça").

### Validado

e2e completo, **9/9 verde**, incluindo a Endurance corrigida. Nenhuma mudança de backend. Achado e
corrigido dentro do mesmo ciclo de verificação do D-138 — não abriu PR separado.

---

## D-140 — As missões narrativas da Endurance existem: 4 capítulos, encadeados pela primeira vez.

**Data:** 2026-07-20 · **Status:** implementado, decisão própria · GDD: §02/§16.2 (rótulo, sem
mecânica) · Fecha a exclusão deliberada de "Narrativa" do D-78

Depois do D-138/D-139, fui atrás do próximo item. `Endurance.tsx` (D-132) tinha uma nota que nunca
deixou de ser verdade até hoje: **"As missões narrativas continuam sem existir; esta tela não
finge o contrário."** O GDD nomeia a Endurance "fonte de... missões narrativas" em três lugares
(§02, §16.2, e a tabela do §11 do v35) — e nunca descreve UMA missão, um capítulo, um gatilho ou
uma recompensa. É a mesma vagueza que os Leilões tinham (D-129): um rótulo, zero mecânica. O D-78
já tinha reconhecido isso e excluído "Narrativa" do escopo do motor genérico de propósito
("espera o seu sistema").

Diferente dos Leilões, aqui não perguntei antes de comprometer código — a instrução do usuário
nesta sessão ("não me faça mais perguntas, siga suas sugestões... investigue e coloque em
prática") já supera o precedente do D-129. A arbitragem fica registrada aqui, como sempre.

### O que faltava no motor genérico, e só isso

O motor de Missões (D-78: `mission_templates` + `mission_assignments` + `Atribuir` + `Progresso`)
já resolve sorteio, progresso, pagamento e aviso — genérico o bastante para qualquer categoria
nova sem tocar `Progresso::pagar()`. O que faltava era só **encadeamento**: nenhuma categoria
existente depende de outra ter sido concluída antes de aparecer. Uma coluna resolve —
`mission_templates.requer_template_id` (nullable, auto-referente) — e `Atribuir::garantirNarrativa()`
(nova, chamada lazy por `MissoesController::index()`, o mesmo padrão de `garantirFederacao()`): por
capítulo, na ordem do catálogo, entrega se ainda não foi entregue E (não tem pré-requisito OU o
pré-requisito está `concluida`). Sem ciclo — `expires_at` fica nulo, um capítulo não vence.

### Os 4 capítulos — 100% arbitragem, tema e números

O GDD não dá pista de conteúdo nenhuma, então a história e os valores são meus, documentados para
não passar por dado do documento:

1. **"O Primeiro Achado"** — comprar 1 item da Loja de Peças (`comprar_item_endurance`, gancho
   NOVO em `ComprarItem::handle()` — o único capítulo que precisou de ação dedicada, para prender
   a narrativa a um ato de verdade na própria Endurance). 10 F$ + 150 XP.
2. **"O Preço da Escavação"** — 3 negócios no Mercado Central (`mercado_executado`, ação já
   existente, tematizada como "financiar a expedição"). 15 F$ + 300 XP.
3. **"Reconstrução"** — 2 níveis de construção concluídos (`obra_concluida`, tematizado como
   "integrar o que foi recuperado"). 20 F$ + 400 XP + 500 Metal Bruto.
4. **"O Legado da Endurance"** — 2 despachos (`despacho`, o fechamento, "o legado viaja pelo
   planeta"). 50 F$ + 1000 XP + 100 Componentes Eletrônicos.

**Por que só o capítulo 1 ganhou gancho novo, e os outros reaproveitam ações genéricas**: inventar
uma ação nova por capítulo exigiria 4 pontos de instrumentação novos no motor do jogo, para uma
categoria que é, na essência, cosmética/narrativa — custo que a "recompensa itens
cosméticos/narrativos e contratos" do §11 (v35) não pede. O mesmo julgamento econômico que já guiou
D-129 (não inventar mais do que o necessário) e D-135 (vocabulário fechado de efeitos).

**Por que os capítulos não entregam um item da Endurance como prêmio** (o §11 v35 sugere "itens...
narrativos"): `Progresso::pagar()` só sabe pagar Fert$/recursos fungíveis/XP — dar um `EnduranceItem`
de graça exigiria um tipo de recompensa novo no motor genérico, usado por UMA categoria só. Mais
barato e igualmente coerente: a narrativa paga em Fert$/recursos, e a Loja de Peças (D-135) continua
sendo a única via de aquisição de item — a mesma separação que já existe entre "ganhar dinheiro" e
"gastar dinheiro" em todo o resto do jogo.

### Dois retoques fora do backend

- **Admin** (`missoes.blade.php`/`PainelController::missoes()`): um seletor "Requer" nos formulários
  de criar/editar, populado só com capítulos de narrativa (`capitulosNarrativos`) — a mesma
  filosofia CRUD-primeiro da Loja de Peças (D-135): o operador escreve capítulo novo sem deploy.
  **Achei a MESMA armadilha do D-135 antes de ela morder**: `nullable` não converte `''` em `null`
  na validação — sem a conversão explícita, "sem pré-requisito" salvaria `0` na FK e quebraria a
  constraint. Corrigido (e testado) antes de existir em produção, não depois.
- **Frontend** (`Missoes.tsx`): achei, ao revisar a tela ANTES de rodar qualquer teste, que
  `grupos`/`NOME_CATEGORIA` eram uma lista fechada de 4 categorias (`tutoria`/`diaria`/`semanal`/
  `federacao`) — sem `narrativa`, o capítulo chegaria pela API e **sumiria em silêncio** na tela,
  a mesma classe de bug do vínculo com chave errada (D-72). Corrigido antes de gerar qualquer
  teste que pudesse mascarar a lacuna.

### Validado

8 testes novos (`MissoesNarrativaTest`): o seeder encadeia os 4 na ordem certa, só o 1º chega de
saída, concluir o 1º libera o 2º (e só no PEDIDO SEGUINTE — a mesma preguiça lazy do resto do
motor), não pula capítulo, a cadeia inteira conclui e paga o último, um capítulo entregue nunca
repete, narrativa não expira, a rota `/missions` devolve o capítulo ativo. 3 testes novos em
`MissoesAdminTest` (criar com pré-requisito, `''` vira `null`, id inexistente recusado). Suíte
completa: **892 passando** (889 + 3 admin). `tsc`/`lint`/`build` do frontend limpos.

**e2e: inconclusivo, por infraestrutura, não por código.** Três tentativas de `tools/e2e.sh` nesta
sessão — a mesma classe de falha do servidor de 4 GB documentada em memória
(`servidor-4gb-nao-sobrecarregar.md`, "uma carga pesada por vez; `exit 137` é OOM, não teste
reprovado"): Chrome derruba com `Protocol error: Target closed` em pontos **sempre diferentes e
sempre alheios** a este trabalho (HUD, Mapa — nunca Missões nem Endurance). Na 3ª tentativa,
`telas.e2e.mjs` e `chat.e2e.mjs` passaram inteiros e verdes antes de o Chrome cair ao ABRIR
`mobile.e2e.mjs` — nem chegou a rodar a suíte que checa o painel de Missões. A MESMA infraestrutura
já tinha passado 9/9 minutos antes, para o D-138/D-139 (que não mexeu em Missões/Endurance,
mas passa pelas mesmas telas de base). Decisão: **enviar mesmo assim**, apoiado em 892 testes PHP
+ `tsc`/`lint`/`build` limpos — a mesma leitura já registrada no D-122 ("`exit 137` não é teste
reprovado"). Sinalizado aqui para o usuário, não escondido, e não é motivo para reverter: se um
próximo e2e completo achar algo real na tela de Missões/Endurance, é dali que se conserta.

---

## D-141 — O GDD v38: a segunda regeneração do v36, D-101 a D-140.

**Data:** 2026-07-21 · **Status:** pedido direto do usuário · **Documento, não código de jogo**

Pedido do usuário: "vamos criar a versão 38 do GDD com todas as atualizações que foram feitas".
O v36 (D-62) é um GERADOR (`tools/gdd-v36.php`), não um documento escrito à mão — as tabelas
numéricas vêm ao vivo de `building_specs`/`resource_types`, a prosa é curada à mão no próprio
arquivo. A última regeneração tinha ido até o D-101 (commit `06226f9`, 2026-07-16); desde então, 39
decisões (D-102 a D-140) nunca entraram no documento — entre elas duas frentes inteiras que o v36
nem sabia que existiam: **Federação** e a **Loja de Peças da Endurance** por completo.

### O método: copiar, não regravar por cima

`tools/gdd-v38.php` é uma cópia evoluída de `gdd-v36.php` — o v36 fica intocado, exatamente como o
v35 ficou intocado quando o v36 nasceu (D-62: "o v35 fica como registro histórico do que se
pensava antes"). Cada geração nova do documento é um arquivo novo, nunca uma edição destrutiva do
gerador anterior — o rastro de como o próprio GDD evoluiu fica no git, do mesmo jeito que o rastro
de como o jogo evoluiu fica em `docs/decisoes.md`.

### O que mudou na estrutura, não só no conteúdo

O v36 tinha 10 seções (0 a 10, terminando em "o que falta decidir"). O v38 tem 12: duas seções
NOVAS de nível 1 — **§9 A Federação** e **§10 A Endurance** — inseridas antes de "Operação e
administração" (que virou §11) e "O que falta decidir" (que virou §12). As duas mereceram seção
própria, não uma nota dentro de outra, pelo mesmo critério que já vale para Guerra (§8) e Mercado
(§6): são sistemas inteiros, com o próprio vocabulário e as próprias tabelas, não um apêndice de
outra coisa. Leilões entrou como §6.6 (dentro do Mercado, não seção própria) — é literalmente "em
cima do Mercado Central", pela própria definição de arquitetura do D-129.

### Live-data: quatro fontes novas

Além de `building_specs`/`resource_types`, o v38 lê ao vivo de mais quatro lugares para nunca
digitar um número que o jogo já guarda: `fabrica_veiculos` (preço/custo/tempo de Caminhão e
Furgão, D-109), `federation_settings` (o teto antimonopólio e o desconto entre aliados, D-119/
D-120), `silo_capacidades` (a capacidade do Depósito Local por nível, D-108) e uma contagem de
`endurance_items` (quantos itens o catálogo dinâmico tem hoje, D-135). Achei DUAS armadilhas
rodando o gerador contra o banco de dev antes de publicar:

- `Ministerio::precoFert()`/`::custoFabricacao()`/`::MINUTOS_FABRICACAO`/`::ESTOQUE_ALVO` — as
  constantes que o v36 lia direto — **não existem mais**: o D-109 generalizou a fábrica por tipo
  (`Ministerio::config($tipo)`, uma linha por veículo em `fabrica_veiculos`). A tabela §5.5 do
  gerador antigo quebraria com um erro de método inexistente se eu só tivesse copiado — reescrita
  para iterar `Ministerio::TIPOS` (Caminhão + Furgão) e usar `config()`.
- **O banco de dev (`fertwaysdev`) estava com 7 migrations pendentes** — Leilões, Cargos Cívicos e
  as duas fases da reconstrução da Endurance nunca tinham rodado ali, só em produção (via
  `deploy.sh`) e nos testes (SQLite efêmero). O gerador quebrou na primeira tentativa
  (`endurance_items` não existe). `php artisan migrate --force` contra o dev antes de gerar —
  mesma disciplina que o próprio gerador já pede no seu docblock ("rode-o com o banco de DEV").

### O que ficou de fora, e por quê

Não reescrevi cada seção do zero — as que não mudaram (Marco, Chat na sua forma nova, Acordo de
Troca, os dois estoques do Mercado) ficaram como o v36 já as descrevia, só renumeradas onde a
inserção das duas seções novas empurrou os números. Duas mudanças pequenas de conteúdo, achadas
relendo o documento antigo contra o código atual, não pedidas explicitamente mas necessárias para
o documento continuar verdadeiro:

- O canal **Região** saiu do Chat (D-104) — a tabela de canais do v36 ainda o listava.
- A âncora de revenda do Furgão usada (D-73) ficou obsoleta desde que ele ganhou preço de fábrica
  (D-109) — a nota do v36 dizia "ele não tem preço de fábrica", que deixou de ser verdade.

### Validado

`/usr/bin/php84 -l` sem erro de sintaxe; rodado contra o dev (`fertwaysdev`, já migrado em
`--force`) sem warning nem exceção; um script de balanceamento de tags (`div`/`table`/`tr`/`td`/
`th`/`p`/`h2`/`h3`/`ul`/`ol`/`li`/`b`/`code`/`span`) confere abertura=fechamento em todas as 14
tags checadas — sem isso, um `</div>` a mais ou a menos no meio de ~450 linhas novas de HTML
passaria despercebido até alguém abrir o navegador. `tests/Gdd/*` (35 testes) seguem verdes, sem
alteração — nenhum código de jogo mudou, só um gerador de documento novo. Sem migration, sem
deploy: `docs/FERTWAYS_GDD_v38_CONSOLIDADO.html` é o artefato gerado, commitado como o v36 já era
(commit `06226f9`).

**Complemento (mesmo dia): a landing page ainda linkava o v36.** `LandingNav.tsx` aponta
`/gdd.html` — um arquivo ESTÁTICO em `frontend/public/gdd.html` (copiado para `dist/` no build,
fora do alcance de `tools/gdd-v38.php`, que só escreve em `docs/`). Gerar o v38 não bastava: o
site continuaria servindo o v36 até alguém copiar o arquivo por cima. `/bin/cp -f
docs/FERTWAYS_GDD_v38_CONSOLIDADO.html frontend/public/gdd.html` — `cp` sem `-f` esbarrou no alias
`cp -i` do root (a mesma armadilha já documentada em `docs/RETOMAR.md`, "Frontend — o bundle") e
não copiou nada, em silêncio; conferido por `diff` antes de seguir. `dist/gdd.html` confirmado
idêntico ao v38 depois do `npm run build`. `tsc`/`lint`/`build` limpos.

---

## D-142 — O Reator de Energia e o Depósito Local trocam de slot: o Depósito vai para o centro.

**Data:** 2026-07-21 · **Status:** pedido direto do usuário · **Dado de colônia, toda existente e futura**

Pedido do usuário: trocar a posição do slot 21 pelo 10 — em toda colônia que já existe e em toda
que vai nascer. O slot 10 é o centro exato da colmeia (`Domain\Colony\Slots`, D-59); o 21 é a
linha solta do final, onde o Depósito Local nasceu (D-105/D-106). Antes: Reator de Energia no 10,
Depósito Local no 21. Depois do pedido: os dois trocam de lugar — o Depósito assume o centro, o
Reator vai para a borda. Nenhuma outra das cinco essenciais muda (Gerador, Estrutura, Fazenda e
Captação de Água continuam exatamente onde estavam).

### Código: só dois números trocam de lugar

`Slots::MIOLO['reator_de_energia']` (10 → 21) e `Slots::DEPOSITO_LOCAL['deposito_local']` (21 →
10). Nenhum outro lugar do jogo referencia slot por número fixo fora dessas duas constantes —
`CreateColony` (fundação), a cena do front (`ColonyScene.ts`, que só desenha o que a linha
`buildings.slot` de cada colônia manda) e o resto do domínio leem sempre a constante, nunca um
literal. Toda colônia FUTURA já nasce certa, sem trabalho extra.

### As colônias que já existem: uma migration, não um passo manual

Diferente do backfill do D-106 (`fertways:slots --aplicar`, um comando à parte que o operador tem
de lembrar de rodar — e que **não seria seguro aqui**: ele atualiza o Reator para o slot 21 ANTES
de mover o Depósito, e uma colônia que já tem os dois construídos bateria de frente no
`unique(colony_id, slot)` no meio do caminho, com o Depósito ainda ocupando o 21), esta troca virou
uma migration de dado (`2026_07_21_100000_troca_slot_do_reator_com_o_deposito_local`), que roda
sozinha no `deploy.sh` — sem depender de ninguém lembrar de um passo extra, a mesma lição que o
D-106 já tinha deixado ("o passo ficou fácil de esquecer").

**Troca em três passos, não em dois**: o Reator vai primeiro para um slot de passagem (255, dentro
do teto do `unsignedTinyInteger` e fora da faixa 0–21 em uso) — só então o Depósito ocupa o 10
livre, e só então o Reator entra no 21, agora livre. Duas atualizações diretas bateriam na
constraint composta; a de passagem não.

### Validado contra o cenário real, não só o feliz

Rodei a migration contra o banco de dev (`fertwaysdev`) **depois de recriar de propósito o estado
de colisão real**: as 5 colônias de dev nunca tinham passado pelo backfill do D-106 (não tinham
`deposito_local` nenhum) — inseri manualmente uma linha de Depósito no slot 21 para cada uma,
reproduzindo o formato exato da produção (que já passou pelo backfill), antes de rodar a migration.
Sem isso, o teste teria corrido no caminho fácil (slot 21 vazio) e nunca teria provado que a troca
de três passos evita a colisão que o `unique(colony_id, slot)` proíbe.

- `up()`: as 5 colônias terminaram com Reator no 21 e Depósito no 10 — conferido por `SELECT` direto.
- `down()`: revertido para o estado original (Reator 10, Depósito 21) — mesma verificação.
- `up()` de novo, para deixar o dev no estado final que a produção também vai ter.

Testes atualizados: `SlotsDaColoniaTest` (as duas asserções que citavam os números fixos, mais um
teste de backfill que assumia o Depósito no 21), `ColonyCreationTest` (comentário). Textos de
erro/documentação corrigidos onde citavam o número antigo: `Demolir.php` (a mensagem que o colono
lê se tentar demolir o Depósito), `Building.php`, `Funcoes.php` (o catálogo do slot vazio),
`CreateColony.php`, `SlotsDaColonia.php`. Suíte completa: **892 passando**, sem alteração de
contagem — só os dois valores trocaram dentro dos testes existentes. `docs/FERTWAYS_GDD_v38_CONSOLIDADO.html`
regenerado (a tabela do miolo, §2.1, lê `Slots::MIOLO` direto do código) e recopiado para
`frontend/public/gdd.html`, para o documento publicado não ficar desatualizado no mesmo dia em que
foi gerado.

---

## D-143 — A "Lista Mestra de Assets de Estruturas": um manifesto novo, cinco vínculos genuínos.

**Data:** 2026-07-21 · **Status:** pedido direto do usuário · **Preparação de código — sem arquivo ainda**

O usuário colou um manifesto novo (markdown, ~70 estruturas em 10 grupos) e pediu para o
`/central/admin/imagens` ficar pronto para recebê-las. Antes de mexer em código, cruzei o
manifesto inteiro contra `ImportarImagens::EVIDENTES` (o mapa "nome de arquivo → chave do jogo",
D-68/D-72/D-107) — não linha a linha por confiança, por *script*: **~50 dos ~70 nomes já batiam,
letra por letra**, com entradas que o lote do `structures.zip` (D-107) já tinha registrado. Se os
arquivos chegarem com o MESMO nome de antes, não precisam de entrada nova — o `unique(category,
filename)` já os reconhece, e um vínculo que já existe nunca é sobrescrito (trava de sempre).

### O que era genuinamente novo, dos ~70

**Cinco entradas, e só essas, mereceram código:**

1. **Quatro artes DEDICADAS às 4 áreas da Capital** (`governo-central-norte`,
   `mercado-central-leste`, `espacoporto-sul`, `destrocos-endurance-oeste`) — até aqui, cada área
   usava emprestada a arte de UM slot/seção dela (o Oeste usava `casco-endurance`, por exemplo).
   `capital:area:norte` **nunca tinha tido nenhum candidato, em nenhum lote** — é o primeiro
   vínculo evidente que esta chave já viu. As outras três entram como candidatas A MAIS (mesmo
   tratamento que `casco-principal-endurance` já teve no D-107, sem tirar a arte que já está lá).
2. **`deposito-local` → `deposito_local`, um gap achado, não trazido pelo manifesto.** Investigando
   por que esse nome não batia, achei que `Vinculaveis::porCategoria()` **já inclui** `deposito_local`
   como coisa vinculável (desde que a classe existe) — mas `EVIDENTES` nunca foi atualizado para
   acompanhar: o comentário do D-107 ainda dizia "não tem chave", uma alegação que parou de ser
   verdade em algum commit anterior a este, sem ninguém notar. A imagem (`deposito-local.png`) já
   estava na biblioteca desde o D-107, só sem vínculo — fechado agora.

**`secao-comando-endurance` está no manifesto e fica de fora, de propósito** — mesma leitura que o
D-107 já tinha dado ao nome idêntico: é variante visual do casco, não uma 9ª seção.

**O resto continua sem lar, pelos MESMOS motivos já registrados** (D-72/D-107, não repetidos
aqui): `patio-logistico` (a área Leste já é Mercado+Pátio); as 7 "Especializações da Colônia" que
não são `building_type`; `cargueiro-interplanetario`/`torre-trafego-orbital`/`terminal-aduaneiro`
(Espaçoporto não existe como feature); `mercado-central`/`doca-mercado`/`camara-escrow` (Mercado e
Comércio não tem catálogo de itens vinculáveis); `fortim-defesa`/`centro-cerco` (o jogo só tem
`bastiao` e `abrigo_de_robos`, os dois já reivindicados).

### O painel em si não precisou de nenhuma mudança

`PainelController::imagens()` já lê `Biblioteca::CATEGORIAS`/`Vinculaveis::porCategoria()`/
`::todas()` inteiramente ao vivo — nenhuma categoria, nenhum grupo, nenhuma contagem é hardcoded
na view ou no controller. As duas categorias de pasta que o manifesto usa (`Mercado e Comércio`,
`Espaçoporto`) já existiam em `Biblioteca::CATEGORIAS` desde sempre. Atualizar `EVIDENTES` é
suficiente: o painel "recebe" o vínculo novo assim que o código publica.

### Achado ao investigar, não corrigido aqui: uma imagem já esperando

`governo-central-norte-u4yh0m.png` (categoria `capital`) já está na biblioteca — um upload manual
avulso pelo painel, ANTES deste pedido, com o sufixo aleatório de sempre (`Biblioteca::enviar()`).
Como o nome não é exato, o importador em lote nunca o teria pego mesmo com a entrada nova — mas
ele já pode ser vinculado a "Capital — Governo Central (Norte)" **agora mesmo, pelo painel**, sem
esperar o resto do lote chegar. Sinalizado para o usuário, não vinculado por mim: é uma escolha
visual, e o painel existe exatamente para isso.

### Ainda falta: os arquivos de verdade

Este PR é só a preparação do código — **nenhuma imagem nova chegou ao servidor**. Quando o
restante do lote for colocado em `/home/fertways/media/<categoria>/` (fora do git, como sempre —
D-68), o passo de sempre fecha: `php84 artisan fertways:importar-imagens --aplicar` em produção.

### Validado

`test_todo_vinculo_evidente_aponta_para_algo_que_existe` (guarda que já existia, D-72) confere as
5 entradas novas contra `Vinculaveis::todas()` de graça — nenhum teste novo precisou nascer.
Suíte completa: **892 passando** (5181 assertions, +5 da mesma guarda cobrindo mais entradas).
Sem migration, sem mudança de frontend.

---

## D-144 — A Zona Neutra vira colmeia de slots: crescimento por nível e três repetíveis

**Data:** 2026-07-21 · **Status:** pedido direto do usuário ("mecanismos de crescimento e visual
como o da colônia") · **Arbitrado, em conjunto com o usuário**

O pedido tinha duas metades, e a primeira pergunta era se elas puxavam pra caminhos diferentes.
`Zona.tsx` já registrava, desde o D-67/D-86, uma decisão deliberada de manter a zona em **SVG, não
Phaser** — as cenas de Phaser da colônia e da Capital não são testáveis por e2e (é canvas: sem DOM,
sem `click` por seletor), e essa lacuna já tinha custado cobertura real duas vezes (D-54, D-63).
"Visual como o da colônia" podia significar reabrir aquilo. Perguntei antes de mexer.

### As quatro decisões, na ordem em que foram fechadas com o usuário

1. **Tecnologia**: SVG continua. Só a linguagem visual muda (paleta, composição hexagonal) — a
   decisão de testabilidade do D-67 não é reaberta.
2. **Layout**: a "planta com áreas fixas" (Muralha no perímetro, Torre no alto) vira **colmeia de
   slots livres**, como a colônia. Antes de aceitar, confirmei que nada no motor de combate
   dependia de POSIÇÃO — `Forcas::bonusDeConstrucao()` já lia só tipo+nível — então a mudança não
   quebra regra nenhuma, só a semântica de lugar, que sempre foi arbitragem (D-67, nunca esteve no
   GDD).
3. **Crescimento**: repetíveis (mirror de `Building::REPETIVEIS`, D-59) + mais slots por nível
   (mecanismo NOVO — a colônia não tem isto: seus 22 slots existem todos desde a fundação).
4. **Escopo**: implementar já, não só desenhar.

### A geometria é literalmente a da colônia

`Domain\Zona\ZonaSlots::LINHAS = [4, 4, 5, 4, 4, 1]`, `TOTAL = 22` — os mesmos números de
`Domain\Colony\Slots`. O slot 10 (centro da colmeia) é fixo para o Posto de Comando, pelo mesmo
motivo que o centro da colônia é fixo para o Depósito Local desde o D-142: o centro pertence à
construção mais essencial/mais aberta. É o "visual como o da colônia" cumprido ao pé da letra — a
mesma matemática de hexágonos, a mesma proporção — só que desenhada em SVG (`ColmeiaDaZona` em
`Zona.tsx`, um port de `game/ColonyScene.ts:colmeia()` sem `vista`/zoom, que a zona não precisa).

### Crescimento por nível — sem regressão nas 120 zonas já em produção

O nível 1 já desbloqueia 12 dos 21 slots livres (`ZonaSlots::NIVEL1_SLOTS`) — o bastante para as 12
`Estruturas::CONSTRUIVEIS` de hoje, uma cada, **não importa em que nível 1–5 uma zona estivesse**.
Do nível 2 ao 10 desbloqueia +1 slot por nível (`ORDEM_DESBLOQUEIO`), fechando em 22 no nível 10 —
mesmo total da colônia. `NeutralZone::NIVEL_MAXIMO` sobe de 5 para 10, mesmo precedente do Depósito
da colônia (D-108, também 5→10, mesma curva `1.65`).

### Repetíveis: só as três que PROCESSAM

`Estruturas::REPETIVEIS = ['refinaria_de_campo', 'industria_siderurgica', 'estrutura_de_extracao']`
— mirror de `Building::REPETIVEIS`. As outras 9 continuam únicas. A escolha não foi arbitrária:
conferi contra `Domain\Guerra\Atacar::ALVOS_ATACAVEIS` (as seis estruturas que sabotagem/apreensão
miram, D-118) e nenhuma das três repetíveis está lá — sabotagem e apreensão continuam identificando
o alvo só pelo TIPO, sem ambiguidade de qual cópia, porque as únicas repetíveis são justamente as
que a guerra nunca mirou. Não foi sorte: as seis atacáveis (Depósito, Muralha, Torre, Bastião,
Abrigo, Posto) são todas de defesa/controle — coisas que fazem sentido únicas — e as produtoras
sempre foram a família natural de repetível (mesma lógica do D-59 para a colônia).

### A migration: coluna vira linha, com backfill determinístico

Até aqui `neutral_zones` tinha 13 colunas de nível, uma por tipo (`Domain\Zona\Estruturas::COLUNA`).
Nasce `zone_structures` (mirror de `buildings`: `neutral_zone_id`, `slot`, `type`, `level`,
`unique(neutral_zone_id, slot)`). O backfill migra cada estrutura já erguida (coluna > 0) para um
slot fixo dentro do conjunto desbloqueado-desde-o-nível-1, numa bijeção com a ordem antiga de
`COLUNA`: nenhuma zona existente pode ficar com estrutura sem lugar, não importa o nível em que
estivesse. `Estruturas::COLUNA` morreu — quem lia `$zona->{coluna}` agora lê
`NeutralZone::nivelDe(tipo)` (soma entre cópias, para as repetíveis; a única linha, para o resto).
`Estruturas::TODAS` substitui `array_keys(COLUNA)` nos poucos lugares que só precisavam da lista de
tipos (validação de `RepararModulo`, o painel de admin, `Vinculaveis`).

`ConstruirNaZona` ganhou uma trava nova, achada escrevendo o teste de duas obras simultâneas
(`FilaSetting::zona_vagas > 1`, D-111): um slot pode estar vazio em `zone_structures` e AINDA
ASSIM já ter obra em curso — a estrutura só vira linha quando a obra CONCLUI. Sem checar
`zone_build_queue.slot` também, duas obras concorrentes no mesmo slot vazio nasceriam sem conflito,
e a segunda a terminar sobrescreveria o tipo da primeira.

### Frontend: `Zona.tsx` — seleção por SLOT, não por tipo

A antiga seleção (`sel: string`, o tipo clicado na planta) virou `sel: number` (o slot clicado na
colmeia) — necessário porque um tipo repetível pode ter mais de uma cópia, em slots diferentes, e
"clique na Muralha" deixou de fazer sentido sozinho. Um slot ocupado abre o painel de sempre
(evoluir/reparar/demolir); um slot vazio e desbloqueado abre um catálogo (`<select>`) do que se
pode erguer ali; um slot trancado só diz que está trancado. `EnviarMaterial` (aba Canteiro) segue o
slot escolhido, não mais um `<select>` de tipo — é o slot que identifica a obra sem ambiguidade
agora. `api.construirNaZona`/`demolirEstruturaDaZona` passaram a exigir `slot`; a rota HTTP de
demolir foi de `DELETE /zones/{zone}/build/{structure}` para `.../build/{slot}`.

### Validado

Suíte completa: **891 passando** (5170 assertions) — a única falha, `MissoesFederacaoTest`, não
toca `NeutralZone`/`Estruturas`/`ZoneStructure` em nenhuma linha e já falhava antes desta mudança;
fora de escopo, não investigada aqui. Testes de zona atualizados com um trait novo,
`Tests\Concerns\ErgueEstruturasDaZona`, que faz as fábricas de zona de teste continuarem aceitando
as chaves antigas (`'wall_level' => 3`) e roteá-las para `zone_structures` por baixo — a maioria dos
arquivos de teste não precisou mudar além da fábrica. e2e (`frontend/e2e/zonas.e2e.mjs`) reescrito
para a colmeia (22 slots, estados posto/erguida/vazio/trancado, catálogo por `<select>`) e verde de
ponta a ponta contra a stack real (`tools/e2e.sh`), migration incluída.

## D-145 — O painel admin ganha uma aba Mapa: o planeta 101×101 inteiro, sem névoa

**Data:** 2026-07-22 · **Status:** pedido direto do usuário ("uma aba nova que será o MAPA... vamos
precisar do zoom in/out") · **Arbitrado, em conjunto com o usuário**

A única visão espacial que o painel tinha eram duas tabelas de texto (`admin.dashboard?aba=colonias`
e `?aba=logistica`) com `(x, y)` como string — quem investigava um caso de suporte não conseguia
"ver" o planeta. O pedido era literal: uma aba nova que desenhe a grade 101×101
(`MapaFertways::LADO`) inteira, navegável com zoom.

### O que foi decidido com o usuário

Três perguntas, três respostas, antes de escrever qualquer código:

1. **Visibilidade**: tudo de uma vez, sem névoa — é ferramenta interna, e o próprio mapa do jogador
   já não usa névoa nenhuma (D-37).
2. **Ações**: só leitura/diagnóstico por enquanto. Nenhuma ação (realocar colônia, editar zona) parte
   do mapa — quem quiser agir usa as telas que já existem. A única exceção é navegação pura: clicar
   numa colônia abre a ficha do jogador (`admin.jogador`) numa aba nova, porque é o atalho que quem
   investiga um caso de suporte vai querer.
3. **Reuso**: a exploração revelou que a pergunta era, na prática, moot — o painel admin é **Blade
   server-rendered puro** (D-61: "sem SPA"), sem runtime React nem bundler nenhum. `Mapa.tsx` não
   podia ser importado de jeito nenhum; a única forma de ter zoom/pan ali é um `<script>` vanilla
   novo, o **primeiro** desta tela desde que ela existe.

### A geometria: célula = 1 unidade de SVG, sem a camada de `geometria.ts`

O mapa do jogador usa um `viewBox` de 1000 unidades (`LADO_SVG`) com uma calha fracionária pras
réguas, porque precisa de zoom decimal fino e réguas que não escorregam com o arraste (D-64). O
admin não precisa dessa precisão: o `viewBox` do SVG é **diretamente em unidades de célula** (0 a
101), com a mesma fórmula de centro de `projecaoDoPlaneta` (`px = x + 50,5`, `py = 50,5 − y`), só
que 1 unidade de SVG = 1 célula. Isso elimina a calha inteira e a maior parte da matemática de
`geometria.ts`/`Grade.tsx` — não havia por que portar a solução de um problema (precisão sub-pixel)
que aqui não existe.

Consequência prática: **zoom e arraste são só `viewBox.setAttribute`** — todo o desenho (grade,
disco de founders, Capital, as 120 zonas, as colônias) é renderizado **uma vez, no servidor**, via
`@foreach` do Blade, com os dados de `Colony`/`NeutralZone` já carregados. Não há JS reconstruindo
nada a partir de JSON; o `<script>` só lê e escreve o `viewBox`, ancora o zoom no cursor (roda do
mouse) ou no centro (botões +/−), e arrasta por delta de pixel — o mesmo princípio de `Mapa.tsx`,
sem a sofisticação de "a vista nunca sai da borda" (D-64), porque aqui não existe "minha colônia"
pra manter centralizada: um clamp simples em `[0, 101−largura]` basta. O traço da grade (204 linhas,
sempre todas desenhadas — sem sparsificação, porque não há texto por linha aqui) usa
`vector-effect="non-scaling-stroke"` pra ficar fino em qualquer zoom, sem o fator `k` que
`Grade.tsx` calcula à mão.

O disco de founders e o anel livre (D-51) viram dois círculos translúcidos (raio `RAIO_FOUNDER`/
`RAIO_ANEL`, de `MapaFertways`) em vez da sombra célula-a-célula que `Faixas` (`Grade.tsx`) desenha
pro jogador — lá a fronteira exata importa pra decidir onde fundar; aqui é só panorama. Os 48 slots
de founder reservado-vs-populável (`MapaFertways::slotsFounder()`) ficaram de fora desta primeira
versão — mais uma camada de marcadores sem uso claro ainda; fácil de acrescentar se pedirem.

### Validado

Novo `AdminMapaTest.php` (rota bloqueada pro colono, dados corretos chegando à view) e a suíte
inteira: **894 passando** (5185 assertions). Verificação em navegador de verdade: stack efêmera em
SQLite (mesmo padrão de segurança do `tools/e2e.sh` — nunca toca produção/dev), Puppeteer confirmando
login, zoom in/out ancorado corretamente (viewBox encolhe e recentra), arraste (viewBox desloca na
direção certa), e os tooltips de zona/colônia com os dados certos.

### D-145 (complemento) — a ficha rápida em modal

Clicar numa colônia ia direto pra `admin.jogador` (aba nova). Virou um modal na hora: um
`<template>` por colônia (jogador — nickname/nome/e-mail/suspenso; colônia — nome/posição/Fert$;
zonas neutras ocupadas — distrito/mineral/depósito) já renderizado no carregamento da página, sem
requisição nenhuma no clique. O link "Ver ficha completa →" continua levando pra tela cheia (aba
nova) pra quem precisar do resto (construções, frota, denúncias, ledger) — o modal é atalho, não
substituto.

## D-146 — "Mover Colônias": realocar por dois cliques no mapa, mesma trava de sempre

**Data:** 2026-07-22 · **Status:** pedido direto do usuário ("um botão Mover Colônias... clicar na
colônia a ser movida e depois no destino") · **Arbitrado, em conjunto com o usuário**

O mapa (D-145) era só leitura. Este pedido é a primeira ação de verdade nele — mas a ação, **por
baixo**, já existia e já era madura: `App\Domain\Admin\RealocarColonia` (D-61), até aqui só acionável
pela ficha do jogador ou por uma tela avulsa em Operação. Perguntei antes de desenhar, porque havia
uma decisão anterior do próprio usuário em jogo.

### A decisão de 2026-07-13 que isto NÃO reabre

Existiu um botão "Realocar founders" que movia **todas** as colônias de uma vez; foi retirado de
propósito (comentário em `routes/web.php:153-162`) — "realocar é ato pontual... um botão que
remaneja o planeta inteiro é perigoso demais para viver ao lado do Disparar tick". "Mover Colônias"
continua sendo estritamente um-de-cada-vez: um clique de origem, um de destino, um formulário, uma
auditoria. Não há lote, não há "mover todas as colônias de um distrito" — o próprio pedido do usuário
já era singular ("a colônia... o destino"), e a decisão antiga confirma que é para continuar assim.

### As três perguntas respondidas com o usuário

1. **Destino inválido**: mostra erro e deixa escolher outro, sem sair do modo — o admin não perde a
   origem já escolhida por ter clicado errado uma vez.
2. **Confirmação**: um passo explícito, mostrando origem→destino, antes de gravar.
3. **Permissão**: só o Dono — a mesma restrição que a realocação já tem nos outros dois lugares
   (`admin.jogador.realocar`, `admin.realocar.manual`), e pela mesma razão do comentário de
   `ExigirDono`: muda a distância — o eixo de toda a logística — e afeta o mundo de outros
   jogadores.

### Backend: uma terceira porta, zero mudança no domínio

`AcoesController::realocarManual()` já fazia exatamente isto (`colony_id`/`x`/`y`/`motivo`/
`confirmacao === 'REALOCAR'`, chama `RealocarColonia::handle()`), só que redireciona pra
`admin.operacao` no sucesso. Em vez de parametrizar o redirecionamento com um campo do request (abrir
um redirect confiado no cliente por uma economia de 15 linhas), segui o padrão que o próprio arquivo
já usa — `realocarColonia` pra rota por usuário, `realocarManual` pra rota por id, cada um só um
método fino que muda a porta de entrada — e escrevi um terceiro, `realocarPeloMapa`, redirecionando
pra `admin.mapa`. `RealocarColonia`, `NeutralZone`, `Colony` e os testes de realocação já existentes
não mudaram uma linha: o domínio nunca soube (nem precisa saber) de onde veio o pedido.

### Frontend: uma máquina de estados de dois cliques, sem view nem endpoint novo

`data-x`/`data-y`/`data-nome` novos em cada círculo de colônia (além do `data-abrir-colonia` que já
existia) dão ao JS a posição/nome crus, sem reconverter nada a partir do SVG. Fora do modo, clicar
numa colônia continua abrindo a ficha rápida (D-145) — comportamento intocado. Ligado o modo: o
primeiro clique numa colônia é a origem (realce visual); um clique nela de novo cancela a escolha,
sem sair do modo; um clique em QUALQUER outro lugar do SVG — vazio, zona, ou em cima de outra
colônia — é o destino, calculado pela mesma matemática que já lia a célula sob o cursor. Os dois
casos óbvios (Capital; mesma posição) e o caso "célula ocupada por outra colônia" (que o próprio
clique nela já resolve, sem round-trip) são checados no cliente só para feedback imediato — a
validação de verdade continua sendo `RealocarColonia::handle()`, no servidor. Destino válido abre o
MESMO modal do D-145 (uma segunda "vista" dentro dele, `#mapa-form-mover`, alternando com a ficha
rápida — só uma visível de cada vez, pra um não apagar o outro), com um formulário de verdade
(`@csrf`, POST, recarrega a página) que exige a mesma palavra `REALOCAR` de sempre. O resumo
"mover X de (a,b) para (c,d)" é montado por `createElement`/`textContent`, não concatenação de
string — nome de colônia é dado de jogador. Cancelar fecha o modal sem perder a origem (volta a
"aguardando destino"); só o botão "Mover Colônias" de novo sai do modo de verdade.

### Validado

Cinco casos novos em `AdminMapaTest.php` (botão só aparece pro Dono; Dono move e a colônia muda de
posição; operador toma 403 e a colônia não se move; palavra de confirmação errada recusa; destino
ocupado por outra colônia recusa) — suíte inteira: **899 passando** (5197 assertions). Verificação em
navegador de verdade (stack efêmera em SQLite, nunca toca produção/dev): liguei o modo, escolhi a
origem, cliquei a Capital (erro inline, sem abrir modal), cliquei uma célula livre (modal com o
resumo e os três campos ocultos certos), confirmei com a palavra, e vi a colônia na nova posição
após o reload, com o flash de sucesso.

## D-147 — A periferia deixa de ser "qualquer lugar": só a célula que o admin liberar

**Data:** 2026-07-22 · **Status:** pedido direto do usuário ("bloquear essa liberdade... criar um
botão que... marcar onde poderão ser as novas colônias") · **Arbitrado, em conjunto com o usuário**

Desde o D-51, um jogador novo podia fundar em quase qualquer célula: os 28 slots populáveis do
disco de founders (regra por distância e fórmula, D-51) **ou qualquer célula de periferia livre**
— "o colono escolhe a célula, inclusive uma periférica, se quiser". O usuário decidiu fechar a
segunda metade: a periferia passa a ser curada célula por célula pelo admin, do mesmo jeito que o
disco de founders já era curado — só que por marcação manual em vez de fórmula.

### As três decisões, respondidas antes de desenhar

1. **Estado inicial: vazio.** Nenhuma célula de periferia é fundável até o admin marcar a
   primeira — sem isso, esquecer de marcar deixaria o jogo com uma sombra do comportamento antigo,
   e o pedido era justamente acabar com ele.
2. **Só a periferia muda.** O disco de founders (D-51: 48 células, 28 populáveis, 20 reservadas por
   fórmula) continua exatamente como sempre foi — `ehFounderPopulavel()` não mudou uma linha, e a
   ferramenta nova de marcar REJEITA uma tentativa de marcar uma célula do disco, pra a lista do
   admin nunca poder divergir da regra automática que já vale pra ele.
3. **Capital e zona neutra continuam travas rígidas.** A ferramenta de marcar nem chega a oferecer
   essas células — quem tenta recebe erro, o mesmo erro que `podeFundar` sempre devolveu.

### `podeFundar()` ganha um parâmetro, mas não sai do lugar — e não aprende a consultar banco

`MapaFertways::podeFundar(x, y, $periferiaLiberada)` continua **pura**: quem chama já decide se a
célula está na lista (`FoundingCell`, tabela nova, chave `(x,y)`, mirror de `NeutralZone` — sem
geometria computada, porque aqui a "geometria" É a decisão do admin, não uma fórmula). O terceiro
argumento não tem default de propósito: cada um dos três call sites (produção + dois arquivos de
teste) tem de decidir explicitamente, não esquecer.

### O tropeço que quase virou 80 arquivos de teste editados — e a correção arquitetural que resolveu

A primeira versão pôs a checagem de `FoundingCell` dentro de `CreateColony::handle()`, no mesmo
lugar onde `podeFundar` sempre viveu. Rodar a suíte revelou o tamanho do problema: **~80 arquivos
de teste** chamam `CreateColony::handle()` **diretamente**, bypassando o HTTP, só para ter "uma
colônia em algum lugar" pra testar outra coisa — quase todos em células de periferia arbitrárias
(`(20,20)`, `(30,30)`...) que nunca precisariam ser liberadas, porque nenhum desses testes é sobre
elegibilidade de fundação.

A saída não foi editar 80 arquivos: foi perceber que `podeFundar` **já tinha um precedente** de não
valer para toda criação/movimentação de colônia — `RealocarColonia` (D-61) nunca confere
`podeFundar` pra mover uma colônia existente, de propósito (documentado no próprio arquivo: é uma
regra sobre a CERIMÔNIA de fundação, não uma invariante permanente de onde uma colônia pode
existir). Segui o mesmo precedente: a checagem de legitimidade saiu de `CreateColony::handle()`
(que virou um primitivo — "crie a colônia, já validei que o lugar é legítimo" — usado tanto pelo
jogador quanto por ferramentas internas) e foi para `ColonyController::store()`, o único ponto de
entrada real de um jogador novo. `grep` confirmou: nenhum outro código de produção chama
`CreateColony::class` além deste controller. Resultado: os ~80 arquivos de teste não mudaram uma
linha, e a suíte inteira continuou verde.

### A ferramenta do admin: alterna na hora, sem confirmação

`Domain\Admin\AlternarCelulaDeFundacao` (mirror auto-auditado de `RealocarColonia`/`Suspender`) liga
ou desliga uma célula — `fundacao.liberar`/`fundacao.trancar` no log. Ao contrário de mover uma
colônia, isto não mexe em ninguém que já joga e é reversível com um segundo clique: por isso, ao
contrário do D-146, não exige motivo nem palavra de confirmação. Só o Dono, mesma régua do D-146.

No mapa admin (`admin/mapa.blade.php`), um segundo botão, **Liberar Fundação**, ao lado de Mover
Colônias — os dois modos são mutuamente exclusivos (`modo: null | 'mover' | 'fundacao'`,
generalizando o booleano `modoMover` que o D-146 tinha introduzido). Clicar em qualquer lugar do
SVG no modo Fundação calcula a célula e dispara um `fetch` (não formulário — o admin pode marcar
dezenas de células numa sessão, e recarregar a página a cada clique perderia zoom e posição);
sucesso cria ou remove um `<circle>` daquela célula na hora, sem reload. O `<meta name="csrf-token">`
novo em `admin/layout.blade.php` é o único jeito de mandar o token sem recarregar.

### O jogador: a aba Periferia deixa de aceitar "em qualquer lugar"

`GET /map` ganha `periferia_liberada`. `Fundacao.tsx`/`MapaPeriferia` desenha um marcador
(`var(--color-ember)`) por célula liberada — mesmo padrão visual dos `founder_slots` na outra aba —
e só aceita clique nelas; o resto da periferia virou chão sem convite. Com a lista vazia (o estado
inicial, de propósito), a aba mostra um aviso em vez de um planeta mudo sem explicação.

### Validado

Suíte inteira: **905 passando** (5230 assertions), incluindo `AdminFundacaoTest.php` novo (Dono
liga/desliga a mesma célula; operador e colono barrados; Capital/anel/founder/zona-neutra recusados
com o código certo; a aba mostra a célula já liberada) e os testes atualizados de `podeFundar`
(`LogisticaSpecsTest`, `ZonasNeutrasTest`, `ColonyCreationTest` — a mesma célula testada liberada E
não-liberada, prova de que a trava funciona nos dois sentidos). `tools/e2e.sh` completo, verde
(a fundação por disco de founders, intocada, continua funcionando de ponta a ponta). Verificação
dedicada em navegador real, em duas partes: o admin marcando uma célula e tentando marcar a Capital
(stack Blade, sem Vite — o painel não precisa dele); e um jogador novo, contra o bundle de verdade
(`npm run build` + `npm run preview`, D-70), vendo só a célula liberada, tentando clicar fora dela
sem efeito, e fundando com sucesso na célula certa.

## D-148 — "Criar Zona Neutra": os 4 distritos deixam de ser a única fonte de zona

**Data:** 2026-07-23 · **Status:** pedido direto do usuário ("uma função com um botão para que o
dono possa definir onde ficam as zonas neutras ocupáveis"), no mesmo formato do D-147 · **Arbitrado,
em conjunto com o usuário**

Até aqui as 120 zonas neutras eram inteiramente fixas: 4 distritos de 30 células nos cantos do mapa
(`ZonasNeutras::DISTRITOS`, D-51/D-52), cada um com um mineral próprio por quadrante. O usuário
pediu pra "repensar" isso — não só abrir mais zonas, mas tirar dos 4 cantos o monopólio de onde uma
zona pode existir. O Dôno agora cria zona em qualquer célula de periferia, escolhendo o mineral.

### O que ficou combinado

1. **As 120 originais — incluindo a única já ocupada em produção, com 5 estruturas — ficam
   intocadas.** Nenhuma migration mexeu numa linha de `neutral_zones` existente.
2. **A regra dos 4 cantos deixa de ser a ÚNICA fonte de zona nova**, não é revogada — o disco de
   founders e a lógica de `NeutralZoneSeeder` continuam existindo do jeito de sempre; só ganham
   companhia.
3. **Zona criada pelo Dôno é reversível** enquanto estiver livre: clicar de nova na mesma célula
   remove. Zona com dono trava — a fricção que existe pras ações difíceis de desfazer (D-61) não é
   necessária aqui PORQUE remover uma zona livre não afeta ninguém que já joga; uma zona OCUPADA já
   não é mais essa mesma categoria de coisa, e por isso a remoção dela é recusada, não facilitada.

### O achado que mudou o desenho: "é zona neutra?" virou pergunta de banco

`ZonasNeutras::ehZonaNeutra()` sempre foi uma função pura — verdade só pelas 4 faixas fixas, sem
tocar banco. Ela é usada em dois lugares de produção: `MapaFertways::podeFundar()` (zona não é
fundável) e `Domain\Admin\AlternarCelulaDeFundacao` (D-147: zona não pode virar célula de fundação).
**Os dois ficariam cegos pra uma zona criada pelo Dôno fora dos 4 cantos** — sem correção, um
jogador poderia fundar em cima dela, ou o Dôno poderia liberá-la pra fundação por engano, com a
mesma célula sendo as duas coisas ao mesmo tempo.

A correção: as duas checagens trocaram a função pura por uma consulta real a `neutral_zones`
(`NeutralZone::where('x',$x)->where('y',$y)->exists()`), que responde certo pros 120 originais E
pros novos, uniformemente. `ZonasNeutras::DISTRITOS`/`ehZonaNeutra()`/`todas()` não morreram —
continuam sendo a fórmula determinística que `NeutralZoneSeeder` usa pra semear os 120 originais, e
os testes que já provam essa fórmula (`ZonasNeutrasTest`) continuam válidos exatamente como estavam.
Só deixaram de ser a autoridade em tempo de execução.

Consequência direta: `MapaFertways::podeFundar()` foi de 3 para 4 argumentos —
`podeFundar(x, y, $periferiaLiberada, $ehZonaNeutra)`, sem default nos dois últimos, mesmo espírito
do D-147 (cada call site decide explicitamente, não esquece). Só 3 call sites pra atualizar:
`ColonyController::store()` (produção) e os dois arquivos de teste já tocados no D-147.

### Sem migration nova

`NeutralZone` já tinha todas as colunas que uma zona nova precisa. Criar é só mais uma linha,
seguindo exatamente o que `NeutralZoneSeeder` já fazia pros 120 originais (incluindo a linha de
`deposito_de_zona_neutra` em `zone_structures`, no slot `ZonaSlots::NIVEL1_SLOTS[0]`). Toda FK que
aponta pra `neutral_zones.id` já era `cascadeOnDelete()` — remover uma zona livre é seguro no nível
de banco; a trava real (não remover zona com dono) é inteiramente da aplicação
(`Domain\Admin\AlternarZonaNeutra`).

**Rótulo de distrito pra zona fora dos 4 cantos fixos**: `ZonasNeutras::quadranteDe(x, y)` — divide
por SINAL de x/y, reaproveitando as MESMAS 4 palavras que a exibição (front e admin) já conhece.
`distritoDe()` (que responde `null` fora dos blocos fixos, usado por quem precisa saber
especificamente "é um dos 120 originais?") não mudou uma linha — `quadranteDe()` é função nova, só
pra rotular zona nova de um jeito que já faz sentido em toda tela existente.

### `Domain\Admin\AlternarZonaNeutra` — mirror do D-147, com um passo a mais

Mesmas guardas estruturais (dentro do mapa, não é Capital, só periferia — o disco de founders é
território de colono, não de zona). Cria exige um mineral da whitelist (`ZonasNeutras::MINERAIS`) e
recusa colidir com uma `FoundingCell` liberada ou com uma `Colony` já fundada — uma célula não pode
ser fundação E zona ao mesmo tempo, nem zona em cima de colônia. Remove recusa se
`owner_colony_id !== null` (`zona_ocupada`). Audita `zona_neutra.criar`/`zona_neutra.remover`, mesmo
padrão auto-auditado de `RealocarColonia`/`AlternarCelulaDeFundacao`.

É a única das três ações do mapa (Mover, Liberar Fundação, Criar Zona) que precisa de um passo a
mais antes de confirmar — não por causa de risco, mas porque falta uma informação real que só o
Dôno tem (o mineral). O modal do D-145/D-146 ganhou uma TERCEIRA vista (`#mapa-form-zona`), ao lado
da ficha rápida e da confirmação de mover — só uma visível de cada vez. Criar continua sendo
`fetch`, não formulário: o Dôno pode repetir a ação várias vezes seguidas, e recarregar a página a
cada zona perderia zoom e posição.

No mapa admin, zona nova aparece automaticamente — nenhuma view nova precisou ser escrita, porque
`PainelController::mapa()` já passava TODAS as `NeutralZone` (sem filtro geométrico) pro mesmo
`@foreach` que desenha as 120 originais. Só os `<rect>` de zona ganharam `data-x`/`data-y`/
`data-zona-ocupada` (mesmo padrão dos círculos de colônia do D-146), pra o modo "Criar Zona Neutra"
remover sem recalcular nada a partir do clique, e recusar no cliente uma remoção óbvia sem
round-trip.

### Validado

Suíte inteira: **916 passando** (5280 assertions), incluindo `AdminZonaNeutraTest.php` novo (cria
com os 4 minerais; cria e remove a mesma célula, round-trip; zona com dono não remove; operador e
colono barrados; recusa Capital/anel/founder/fora-do-mapa/mineral-inválido; recusa colidir com
`FoundingCell` ou `Colony`; audita os dois atos) e um novo teste em `ColonyCreationTest.php` provando
que fundar é recusado numa zona ADMIN-CRIADA fora dos 4 distritos — a garantia de que a checagem
virou consulta real, não só a fórmula antiga que teria deixado passar. Verificação em navegador de
verdade (SQLite efêmero + Puppeteer, nunca produção/dev): criar uma zona com mineral escolhido, ver
o marcador aparecer sem reload; tentar criar na Capital (erro, sem abrir o seletor); clicar de novo
na mesma célula pra remover, e ver o marcador sumir.

### D-147 (complemento) — zoom e arraste na aba Periferia

Pedido do usuário: com só as células liberadas (D-147) marcadas num planeta de 101×101, achar uma
delas no enquadramento fixo de sempre virou mirar um alvo de poucos pixels. `MapaPeriferia` ganhou
o mesmo par zoom/`Vista` que `Mapa.tsx` já usa (roda do mouse ancorada no cursor, arrastar, +/− e
"ver tudo") — autocontido dentro do componente, porque nenhuma outra tela da Fundação precisa disso.
Abre exatamente como antes (planeta inteiro, escala 1); só passou a dar pra aproximar.

## D-149 — O Depósito Local troca do centro (10) para o slot 14

**Data:** 2026-07-23 · **Status:** pedido direto do usuário ("troque a posição do slot 10 pelo 14
em todas as colônias e nas futuras colônias"), sem motivo publicado · **Arbitrado**

Segunda troca de slot do Depósito Local em duas semanas — o D-142 já tinha movido ele da linha
solta do final (21) pro centro exato da colmeia (10), trocando de lugar com o Reator de Energia.
Este pedido move o Depósito de novo, do centro (10) pro slot 14 — o centro volta a ser um slot
comum, escolhível pelo colono como outro qualquer.

### Por que isto não foi tão simples quanto parecia

O D-142 trocava dois NOMES fixos (Reator ↔ Depósito), os dois sempre presentes, os dois com tipo
conhecido de antemão — a migration só precisava casar `type = X AND slot = Y`. Este pedido é
assimétrico: só um lado tem nome (o Depósito, sempre no 10); o outro lado, o slot 14, é um slot
comum desde sempre — qualquer coisa pode estar lá, ou nada. Conferi antes de escrever a migration:
em produção, **15 das 28 colônias já tinham construção de jogador no 14** (minas, laboratório, uma
oficina). Uma migration que só movesse o Depósito pro 14 sem primeiro tirar o que já estava lá
teria colidido com `unique(colony_id, slot)` — ou pior, sobrescrito sem trocar, se a ordem das
instruções desse margem a isso.

A correção: a migration troca os DOIS lados sempre, por um valor de passagem (255, como o D-142 já
fazia) — move o Depósito pro 255, move o que estiver no 14 (seja lá o que for, ou nada) pro 10,
move o Depósito do 255 pro 14. Funciona igual para os dois casos (14 vazio ou ocupado) sem
precisar de lógica condicional: `WHERE slot = 14` sem filtro de tipo pega "o que houver ali".
Testada `up`/`down`/`up` contra o MariaDB de dev nos dois cenários — inclusive inserindo uma
construção de teste no 14 pra reproduzir o caso real de produção antes de rodar contra ela de
verdade.

`App\Domain\Colony\Slots::DEPOSITO_LOCAL['deposito_local']` mudou de `10` para `14` — é a única
mudança de código; `CreateColony` e `ColonyScene.ts` (front) sempre leram a constante, nunca um
número solto, então nenhum dos dois precisou mudar (mesmo padrão do D-142). O centro da colmeia
(10) saiu de `Slots::reservados()` e virou um slot comum.

### Validado

`SlotsDaColoniaTest.php` tinha uma asserção com o número 10 solto (`assertSame(10, ...)`, duas
ocorrências) — corrigidas pra usar a constante ou o valor novo (14), e uma nova asserção prova que
o 10 saiu de `reservados()`. Suíte inteira: **916 passando** (5281 assertions). `tools/e2e.sh`
rodado três vezes — as duas falhas que apareceram em rodadas isoladas (Chat, Capital) não se
repetiram de forma consistente entre rodadas e desapareceram sem nenhuma mudança de código,
confirmando serem instabilidade de tempo/carga do ambiente compartilhado, não regressão desta
troca.

## D-150 — O Depósito Local volta pro centro (10): desfaz o D-149

**Data:** 2026-07-23 · **Status:** pedido direto do usuário, minutos depois do D-149 ir ao ar ·
**Arbitrado**

"Trocar o slot 14 com o 10" é a MESMA operação que "trocar o slot 10 com o 14" — trocar A com B e
trocar B com A descrevem a mesma troca. Como o D-149 já tinha feito exatamente essa troca, pedir
de novo logo em seguida era ambíguo: ou o usuário queria desfazer (voltar ao estado do D-142), ou
repetiu o pedido sem perceber que já estava feito. Perguntei antes de tocar em dado de produção
pela segunda vez seguida — a resposta foi desfazer.

### Como desfazer sem reescrever história

O `tools/deploy.sh` só roda migration pra frente (`migrate --force`); nunca `migrate:rollback` em
produção. Desfazer o D-149 por isso não foi rodar o `down()` da migration dele — foi escrever uma
migration NOVA cujo `up()` faz a troca no sentido inverso, mesmo padrão que o D-142→D-149 já
tinha estabelecido (cada decisão é uma migration própria; nenhuma é apagada ou reescrita depois).
`Slots::DEPOSITO_LOCAL['deposito_local']` voltou a `10` no código.

A migration nova reaproveita a MESMA função de troca genérica em 3 passos do D-149 (Depósito → 255
→ slot novo; o que estiver no slot antigo → o slot que o Depósito deixou), só invertendo de que
lado cada um começa — testada de novo nos dois cenários (slot 10 vazio e slot 10 ocupado por
construção de jogador, que é exatamente o que o D-149 tinha posto lá em 15 das 28 colônias) contra
o MariaDB de dev antes de publicar.

### Validado

`SlotsDaColoniaTest.php` voltou a esperar `10`; suíte inteira: **916 passando** (5282 assertions).

## D-151 — O Frete do Governo aceita mais de um recurso na mesma viagem

**Data:** 2026-07-23 · **Status:** pedido direto do usuário ("permita que o jogador escolha mais
que apenas um recurso, até lotar os 30.000 unidades") · **Arbitrado**

Pedido sobre a tela do Mercado Central: o Frete do Governo (§07, D-76) só deixava escolher UM
recurso por viagem — um `<select>` e uma quantidade. Mas o servidor **nunca teve essa restrição**:
`FretePublico::despachar()` já recebe `array<string,int>` (recurso ⇒ quantidade) desde que existe,
soma tudo (`array_sum`) e confere contra a capacidade efetiva do caminhão — o mesmo formato e a
mesma regra que o frete com veículo PRÓPRIO (`FormularioDeCarga`, D-65) já usa há tempo, com várias
linhas de carga na mesma carroceria. Só esta tela específica é que nunca ofereceu a segunda linha.

### Mudança inteira no front — o backend já sabia fazer isto

Troquei o par de estado `recurso`/`qtd` (um `<select>` + um `<input>`) por `linhas: Linha[]`, o
mesmo tipo e o mesmo padrão de soma que `FormularioDeCarga` já usa (rascunho sem recurso ou sem
quantidade fica de fora em vez de virar erro; "+ outro recurso" acrescenta linha; total somado
contra `conta.frete.capacidade`, com o excedente e o "você não tem tudo isso" avisados antes do
clique). Rota, validação (`cargo.*` já era genérico) e `FretePublico::despachar()` não mudaram uma
linha — a checagem no `MarketController`/`FretePublico.php` de que `array_sum($carga) <= 30.000`
sempre foi sobre a SOMA, nunca sobre "um recurso só".

Nenhum e2e cobria esta seção (`grep` por "frete"/"Fretar" em `frontend/e2e/*.mjs` não achou nada) —
verificação em navegador de verdade, à parte: colônia com Metal Bruto e Água no depósito da
Capital, duas linhas preenchidas (1.000 + 500), total "1.500 / 30.000" exibido corretamente, botão
Fretar habilita, um clique despacha os DOIS recursos numa viagem só (um caminhão sai da Garagem,
não dois).

### Validado

Dois testes novos em `FretePublicoTest.php`: uma viagem leva dois recursos, cada um debitado do
seu próprio saldo, um caminhão só; e a SOMA dos dois é que não pode passar de 30.000 (mesmo que
cada um sozinho coubesse). Suíte inteira: **918 passando** (5288 assertions).

## D-152 — O Reator de Energia vai pro slot 6

**Data:** 2026-07-23 · **Status:** pedido direto do usuário ("quero que o reator de energia fique
no slot 6 sempre"), sem motivo publicado · **Arbitrado**

Terceira troca de slot em pouco mais de uma hora (depois do D-149/D-150, Depósito Local). O Reator,
que o D-142 tinha posto na linha solta do final (21), vai pro slot 6 — linha de cima, ao lado da
Fazenda. Mesmo tratamento das trocas anteriores: `Slots::MIOLO['reator_de_energia']` mudou de `21`
para `6` no código, e uma migration nova moveu o dado de toda colônia já fundada.

Conferi antes de escrever a migration: em produção, **18 das 28 colônias já tinham construção de
jogador no slot 6** (minas, oficinas, laboratório, torre de defesa, refinaria química, até um
Mercado Local) — o 6 é um slot comum desde sempre, sem nome fixo, como o 14 já tinha sido pro
D-149. A migration troca os dois lados sempre, pelo mesmo valor de passagem (255) das trocas
anteriores — testada `up`/`down`/`up` contra o MariaDB de dev, com casos reais de slot 6 ocupado E
vazio (o próprio banco de dev já tinha as duas situações, sem precisar inserir dado de teste).

### Validado

`SlotsDaColoniaTest.php` atualizado pro slot 6; suíte inteira: **918 passando** (5289 assertions).

## D-153 — Card "Recursos por hora" na colônia: produzido e gasto, separados, na taxa nominal

**Data:** 2026-07-23 · **Status:** pedido direto do usuário ("um card nas colônias com Recursos
Gastos/Produzidos na colônia por hora") · **Arbitrado**

O jogador via o ESTOQUE de cada recurso (o card `Recursos`, dentro do Depósito Local), mas não o
FLUXO. Decidido com o usuário: produzido e gasto aparecem separados por recurso (não só o líquido),
e a taxa é NOMINAL — capacidade plena, sem tentar refletir a clampagem por insumo escasso que o
tick de verdade aplica (mesma leitura que `NeutralZone::extracaoPorHora()`/`refinoPorHora()` já dão
pra zona neutra).

### Sem duplicar a conta do tick

`ColonyTick::produzir()` já calculava, por dentro, a taxa nominal de toda construção erguida — só
que liquidada (produzido menos consumido, direto no `$taxas` que alimenta o estoque) e presa num
método privado. Extraí essa parte (sem leitura/escrita de estoque) pra um método novo e público,
`ColonyTick::taxasNominais()` — extract-method comportamento-preservado: `produzir()` chama o
mesmo método e segue fazendo o netting de sempre por cima, sem mudar uma linha do resultado. A
suíte inteira, rodada depois do refactor, prova isso: zero asserção quebrada.

A classe nova, `Domain\Production\TaxasDeProducao`, injeta `ColonyTick` e EXPANDE cada peça
agregada (Destilaria, Refinaria Química, Indústria Siderúrgica, Oficina) na dupla produzido/
consumido por recurso que o tick nunca precisou separar. A Siderúrgica é o caso interessante: o
tick de verdade só credita em lotes inteiros de 1.000 Metal Bruto (`siderurgica_lote_remainder`);
aqui a taxa é a MÉDIA suavizada (`taxa ÷ 1.000 × porLote`, arredondada) — os minerais mais raros
somem do card em colônias pequenas (arredondam pra zero), o que é honesto: a essa taxa eles não
rendem 1 unidade inteira por hora mesmo.

Exposto no `GET /colony` já existente (`taxas_hora`), sem endpoint novo: o front já busca a colônia
a cada 5s, o que já mantém o card fresco.

### Validado

Novo `TaxasDeProducaoTest.php` (6 testes): as 5 essenciais, duas Minas somando (D-59), Destilaria
mostrando o MESMO recurso com os dois lados (Fazenda produz Biomassa, Destilaria consome — no
mesmo card), Siderúrgica com o arredondamento dos minerais raros, Oficina pela receita escolhida, e
o bônus de produção da Endurance batendo com o número que `EnduranceItemsTest` já prova pelo tick
de verdade (18 = 15 × 1,2). Suíte inteira: **924 passando** (5312 assertions). Verificação em
navegador de verdade (SQLite efêmero, nunca produção/dev): card aparece na barra lateral da
colônia, com Oxigênio +100, Água +80, Biomassa +60, Energia +150/−62 — os mesmos números do §19.8.

## D-154 — `/mapa` em tela cheia, com pinça de dois dedos, e a legenda virando card

**Data:** 2026-07-23 · **Status:** pedido direto do usuário ("quero que ele abra da mesma forma
que abre a colônia... zoom com o mouse ou os dedos no mobile") · **Arbitrado**

O `/mapa` abria numa caixa limitada (`max-w-5xl`, grade de duas colunas), com um cabeçalho de
página e um parágrafo de instrução por extenso. Pedido: tela cheia, como a colônia — e pinça de
dois dedos pra zoom no mobile. Achado antes de mexer: **nem a colônia tinha pinça** — só roda do
mouse (ancorada no centro) e arraste de um dedo, em `game/vista.ts` (`useVista()`, compartilhado
por `ColonyCanvas`/`CapitalCanvas`). Decidido com o usuário: a pinça vai pras três telas (mapa,
colônia, Capital — mesmo idioma de zoom, D-63); o mapa vira tela cheia com cards soltos por cima,
como a colônia; a seleção de uma colônia/zona SUBSTITUI o card da legenda; e no mobile o card
flutua por cima do mapa em qualquer largura — não é uma gaveta que só abre ao toque.

### A pinça: duas contas, cada uma no seu sistema de coordenadas

`vista.ts` e `Mapa.tsx` já tinham zoom com filosofias DIFERENTES antes desta mudança (a roda do
mouse da colônia ancora no centro da tela; a do mapa ancora no cursor) — não dava pra só religar um
código genérico nos dois; cada pinça teve que nascer no idioma que a própria tela já falava.

- `vista.ts`: um `Map<pointerId, {x,y}>` substitui o `arrastando: {x,y}|null` de antes. Com 2
  ponteiros, a pinça ancora no CENTRO (mesmo que a roda do mouse já fazia ali) — o fator de escala
  vem da razão de distância entre o frame ATUAL e o ANTERIOR (nunca o início do gesto), e o
  deslocamento do ponto médio entre os dois mesmos frames soma direto em `dx`/`dy`.
- `Mapa.tsx`: a conta de "âncora de tela + fator → novo canto do viewBox", que já morava dentro do
  handler da roda do mouse, saiu pra uma função (`zoomAncoradoEm`), reaproveitada pelos dois
  gestos; a pinça chama ela com o ponto médio dos dois dedos. Continua usando listeners de
  `window`, não `setPointerCapture` — capturar o ponteiro desviaria o `click` de uma zona/colônia
  pro SVG, quebrando a seleção por clique.

Em ambos, comparar sempre com o frame ANTERIOR (nunca com o início do gesto) significa que soltar
um dos dois dedos no meio de uma pinça retoma o arraste do dedo que sobrou sem salto nenhum — o
ponto que ele já estava tocando continua sendo o mesmo ponto do mapa.

### Um bug de z-index que só apareceu ao testar em navegador de verdade

O card e os botões de zoom novos (`top-3 right-3 z-[26]`, mesma posição que `ControlesDeZoom` já
usa na colônia) nasceram escondidos atrás do header global (`z-[25]`) — verificação em navegador
pegou isto, os testes de tipo/lint não tinham como. Causa: o `<div>` raiz do Mapa usava `fixed
inset-0`, e `position:fixed` cria um contexto de empilhamento PRÓPRIO mesmo sem z-index nenhum —
prendendo o `z-[26]` dos botões lá dentro, incapaz de escapar pra vencer o header (a comparação que
decide a pintura final é entre o contêiner do Mapa, sem z, e o header, não entre o botão e o
header). A correção: trocar `fixed inset-0` por `relative h-screen w-screen`, as MESMAS classes que
o wrapper da colônia já usa em `App.tsx` — `position:relative` sem z-index não cria contexto
nenhum, e o `z-[26]` do botão passa a competir direto contra o `z-[25]` do header, como devia.

### Testes a atualizar (e2e, não a suíte PHPUnit)

Quatro arquivos (`telas`, `zonas`, `mobile`, `chat`) checavam que o Mapa abriu procurando o texto
"Fertways" — o `<h2>` que este card remove. Trocado por `/Grade \d+×\d+/`, que continua existindo
(só mudou de lugar) e prova mais (dados reais da API, não só um título estático).

### Validado

`npx tsc --noEmit` e `npm run lint` limpos. `tools/e2e.sh` completo, duas vezes (antes e depois do
fix de z-index): **332 verificações, zero falhas**, incluindo o zoom da colônia (`telas.e2e.mjs`,
que prova que o alvo de clique cresce junto com o hexágono — a pinça não quebrou o alinhamento
canvas/DOM que o D-63 já exigia) e o zoom da Capital (`capital.e2e.mjs`). Verificação em navegador
de verdade (SQLite efêmero, nunca produção/dev, Puppeteer com dois `PointerEvent` sintéticos de
`pointerId` distintos simulando a pinça, já que não há gesto de pinça nativo no Puppeteer): tela
cheia confirmada (a caixa do SVG bate exatamente com o viewport), cabeçalho e parágrafo antigos
sumidos, status+legenda dentro de um card, a pinça sintética encolhe o `viewBox` (zoom in de
verdade), e clicar numa vizinha troca o card da legenda pela ficha dela.

## D-155 — A roda do mouse do `/mapa` (D-154) tinha ficado muda — corrigido

**Data:** 2026-07-23 · **Status:** regressão relatada pelo usuário direto em produção ("o zoom in
e out não está ocorrendo pelo mouse") · **Correção**

O D-154 tinha quebrado a roda do mouse no `/mapa` — os botões +/−/⌖ continuavam funcionando, só a
roda ficava muda. Verificação em navegador (não só `tsc`/lint/e2e, que não pegam isto) achou a
causa: o `useEffect` que liga `svg.addEventListener('wheel', ...)` dependia de `[dir,
zoomAncoradoEm]`, mas `svgRef.current` só existe depois que **dir E vista** são verdadeiros — e
`vista` só nasce num efeito SEPARADO, um render depois de `dir` carregar. No render em que `dir`
vira não-nulo, `vista` ainda é nulo, o `<svg>` ainda não existe, o efeito da roda bate no `if
(!svg) return` e nunca mais dispara (não muda de novo quando só `vista`, e não `dir`, muda).

Corrigido com **ref callback** em vez de depender de acertar a sequência de renders:
`anexarSvgRef` (substitui o `svgRef` de `RefObject` por uma função) atualiza `svgRef.current` E um
`useState` (`svgPronto`) toda vez que o nó do `<svg>` monta ou desmonta — os efeitos que precisam
dele passam a depender de `svgPronto`, que muda exatamente no momento certo, sem importar em qual
render isso acontece. Mesmo padrão resolveria qualquer efeito futuro que precise do ref do SVG.

### Validado

`npx tsc --noEmit`/lint limpos. `tools/e2e.sh` completo: **332 verificações, zero falhas**.
Verificação em navegador de verdade (SQLite efêmero, nunca produção/dev): um `WheelEvent` sintético
despachado no `<svg>` chama `preventDefault()` (prova que o listener rodou, não só que existe) e o
`viewBox` encolhe (zoom in de verdade) — o mesmo teste tinha voltado `false` antes da correção.

## D-156 — `/mapa` de verdade preenche a tela: o `viewBox` vira retangular

**Data:** 2026-07-23 · **Status:** pedido do usuário ("o mapa deveria ser mostrado em toda tela"),
segunda metade do que o D-154 tinha deixado incompleta · **Arbitrado**

O D-154 fez o `<svg>` do mapa ocupar o viewport inteiro, mas o DESENHO continuava preso num
`viewBox` quadrado — numa tela larga (a maioria dos monitores), o `preserveAspectRatio` padrão
centralizava o quadrado e sobravam barras vazias `bg-sand` nas duas laterais. Tecnicamente o SVG já
enchia a tela; visualmente, o mapa não.

### A janela vira retangular, na proporção real do contêiner

`geometria.ts`'s `Caixa` era `{x0, y0, lado}` — sempre quadrada, usada pelas duas telas que
desenham o planeta (`Mapa` e `Fundacao`, que continua quadrada, dentro de uma caixa limitada).
Virou `{x0, y0, largura, altura}`; `Fundacao.tsx` só passou a escrever `largura`/`altura` iguais
nos seus dois pontos de construção — comportamento idêntico, zero mudança visual lá (confirmado
pelo `fundacao.e2e.mjs`, que continua verde).

`Mapa.tsx` mede o próprio `<svg>` com um `ResizeObserver` (mesmo padrão que `ColonyCanvas` já usa
pro canvas do Phaser) e computa `largura`/`altura` a partir da MENOR dimensão — que continua
valendo `LADO_SVG / escala` (o de sempre: no zoom de abertura, exatamente `JANELA_PADRAO` = 15
células, D-64) — e da MAIOR, esticada pela proporção real da tela: mais colunas numa tela larga,
mais linhas numa tela alta, nunca célula esticada numa elipse. Uma função só
(`dimensoesDaJanela`), reaproveitada pelo `viewBox` (dentro de `Desenho`) E pela conversão de
pixel-de-tela-em-unidade-do-jogo do zoom (roda/pinça) e do arraste — a mesma razão de sempre pra
extrair: duas contas divergem.

**A pegadinha:** a calha das réguas (`viewBoxComReguas`) soma o MESMO valor absoluto aos dois
eixos — e somar um número igual a duas dimensões diferentes muda a razão entre elas. Sem
compensar isso, o `viewBox` final (janela + calha) ficava com uma proporção ligeiramente diferente
da tela de verdade, e o letterboxing voltava, só que menor. `dimensoesDaJanela` resolve a conta de
trás pra frente: a proporção que tem que bater com a tela é a do viewBox JÁ COM a calha, não a da
janela sozinha.

### Testes a atualizar (e2e)

`telas.e2e.mjs` tinha uma asserção hardcoded ("a régua de X numera 15 colunas, de -7 a 7") que
media exatamente o comportamento antigo (janela sempre quadrada). O viewport do e2e é 1400×900
(`comum.mjs`, mais largo que alto) — com a janela retangular, a régua de X passa a mostrar mais de
15 colunas de verdade (achou 25, de -12 a 12), enquanto a de Y (o eixo que não muda) continua
exatamente 15, de -4 a 10. Trocada a asserção de X por uma que prova a propriedade nova (mais de 15
colunas, simétrica em torno de você) sem prender um número exato que já nasce amarrado ao viewport
do teste.

### Validado

`npx tsc --noEmit`/lint limpos. `tools/e2e.sh` completo: **332 verificações, zero falhas** —
incluindo `fundacao.e2e.mjs` (prova que a generalização da `Caixa` não mudou nada na tela que
continua quadrada). Verificação em navegador de verdade (SQLite efêmero, nunca produção/dev), duas
proporções de tela: 1920×1080 (paisagem) e 420×900 (retrato) — nos dois casos a proporção do
`viewBox` bate EXATAMENTE com a da tela (diferença < 0,01), inclusive depois de um zoom pela roda
do mouse (a proporção não deriva no meio do gesto); screenshot confirma zero barra vazia nas
laterais em qualquer dos dois casos.

## D-157 — O Reator de Energia vai até o nível 15

**Data:** 2026-07-23 · **Status:** pedido direto do usuário ("modificar a construção REATOR DE
ENERGIA para que ele chegue ao nivel 15... proporcional ao que está valendo agora e com
crescimento previsto no GDD") · **Arbitrado**

O GDD publica o Reator só "até o nível 5" (§4.2/§19, o mesmo teto de todas as 5 essenciais e mais
11 construções de progressão — um boilerplate do documento, não uma decisão específica do Reator).
Pedido do usuário: estender at o nível 15, com custo/produção/tempo **proporcionais ao que já
vale**, seguindo o crescimento que o próprio GDD já usa nos níveis existentes — não números
inventados.

### A mesma fórmula, só que mais longe — precedente direto: D-108 (Depósito Local até o 10)

Todo o jogo já deriva os níveis 2-5 (e 2-10, pras 3 construções que o GDD publica até lá) de DUAS
curvas fixas, comprovadas por `tests/Gdd/GddSpecsTest.php` reproduzindo os números do documento
antes de extrapolar:

- **Custo**: `half-UP(custo_nível_1 × 1,65^(n-1))`, por recurso — 50×1,65=82,5, e o GDD publica 83,
  não 82.
- **Tempo e produção**: `half-EVEN(base × 1,50^(n-1))` — 7×1,5=10,5, e o GDD publica 10 min pro
  Reator nível 2, não 11. A base do Reator (§20.3-20.5) é 7,0 min, exata, não a versão já
  arredondada do nível 1.

Reproduzi os níveis 1-5 já publicados com estas duas fórmulas antes de estender — bateram exatos
nos cinco, confirmando que a base e o arredondamento estão certos — e apliquei a MESMA conta aos
níveis 6-15, por recurso e para a energia produzida:

| Nível | Água | Ligas | Compostos | Oxigênio | Biomassa | Energia/h | Tempo |
|---|---|---|---|---|---|---|---|
| 6 | 183 | 489 | 122 | 98 | 61 | 1.139 | 53 min |
| 7 | 303 | 807 | 202 | 161 | 101 | 1.709 | 80 min |
| 8 | 499 | 1.332 | 333 | 266 | 166 | 2.563 | 120 min |
| 9 | 824 | 2.198 | 549 | 440 | 275 | 3.844 | 179 min |
| 10 | 1.360 | 3.626 | 906 | 725 | 453 | 5.767 | 269 min |
| 11 | 2.244 | 5.983 | 1.496 | 1.197 | 748 | 8.650 | 404 min |
| 12 | 3.702 | 9.872 | 2.468 | 1.974 | 1.234 | 12.975 | 605 min |
| 13 | 6.108 | 16.288 | 4.072 | 3.258 | 2.036 | 19.462 | 908 min |
| 14 | 10.078 | 26.875 | 6.719 | 5.375 | 3.359 | 29.193 | 1.362 min |
| 15 | 16.629 | 44.344 | 11.086 | 8.869 | 5.543 | 43.789 | 2.044 min |

`building_specs.json`/`production.json` são a fonte (dados, não código) — o `BuildingSpecSeeder`
já é 100% dirigido por eles, sem número de nível hardcoded em lugar nenhum do domínio
(`BuildingSpecs::nivelMaximo()` é só `MAX(level)` sobre o que existir na tabela); acrescentar as 10
linhas novas bastou.

### `reator_de_energia` sai da lista "bate com o GDD"

`GddSpecsTest::test_niveis_maximos_batem_com_o_gdd` tem esse nome porque só lista construções cujo
teto ainda é o que o documento diz. Removido de lá (mesmo tratamento que o Depósito Local, D-105,
sempre teve — nunca esteve nessa lista) — o teto do Reator virou arbitragem, não fato do GDD. Já
`test_tempo_publicado_sai_da_base_do_gdd_com_arredondamento_bancario` — que reconfere TODO
`build_time_seconds` contra a curva, célula por célula — passou a validar os 10 níveis novos
também: eles batem com a MESMA curva (é assim que foram calculados), então o teste continua
verdadeiro e ganha uma rede de segurança de graça contra erro de aritmética na extensão. Contagem
hardcoded `69` → `79` (14 tabelas × 5 níveis, menos 1 exceção, mais os 10 níveis novos do Reator).

### Validado

Dois testes novos em `BuildQueueTest.php` (o 14→15 é aceito; o 15→16 recusa com `nivel_maximo` —
prova que o TETO mudou, não só o dado) e um em `TickColoniesTest.php` (Reator nível 15 sozinho
produz exatos 43.789 energia/h, o topo da curva). Suíte inteira: **927 passando** (5425
assertions). `tools/e2e.sh` completo: **332 verificações, zero falhas**.

### ⚠️ Produção precisa de reseed depois do deploy

Mesma lição do D-106/D-108, repetida: os níveis novos só existem depois de `php artisan db:seed
--class=BuildingSpecSeeder --force` rodar contra o banco de produção — o deploy sozinho não
resemeia `building_specs`.

## D-158 — A legenda do `/mapa` ganha o ícone da zona neutra livre

**Data:** 2026-07-23 · **Status:** pedido direto do usuário ("falta o icone da zona neutra
disponível para ocupar") · **Correção**

`Legenda()` juntava "Vizinhas / zonas livres" numa única linha, com um swatch REDONDO só — mas no
mapa de verdade uma vizinha é um `<circle>` e uma zona é um `<rect>` (`corDaZona()`), da mesma cor
(`--color-ink-soft`) só quando a zona está livre. Quem via um quadrado escuro no mapa e procurava
o ícone dele na legenda só achava um círculo — o quadrado nunca tinha um ícone próprio.

Mesmo problema, mesma causa, num segundo lugar: "Você / suas zonas" também juntava o círculo da
sua colônia com o quadrado das suas zonas (cor ember) numa linha só. Corrigido nos dois: cada linha
agora tem a FORMA de verdade do marcador que representa — Capital (losango), Você (círculo), Suas
zonas (quadrado), Vizinhas (círculo), Zona neutra livre (quadrado, novo), Zonas de outros
(quadrado), Disco de founders e Anel livre (sem mudança, já eram quadrados de fundo).

### Validado

`npx tsc --noEmit`/lint limpos. `tools/e2e.sh` completo: **332 verificações, zero falhas** (nenhum
e2e dependia do texto antigo das duas linhas combinadas). Verificação em navegador de verdade
(SQLite efêmero, nunca produção/dev): as 8 linhas da legenda renderizam, cada uma com o ícone
certo — conferido por screenshot.

## D-159 — O GDD v38 é regenerado: pega o Reator até o 15 (D-157) e o slot 6 (D-152) de graça

**Data:** 2026-07-23 · **Status:** pedido direto do usuário ("Atualize o arquivo GDD, depois
atualize o https://fertways.tars.art.br/gdd.html") · **Manutenção**

`docs/FERTWAYS_GDD_v38_CONSOLIDADO.html` não é escrito à mão — é gerado por `tools/gdd-v38.php`,
que lê as tabelas numéricas direto do banco de DEV (`fertwaysdev`, o mesmo que `building_specs`) e
só deixa a prosa curada à mão no próprio gerador. "Atualizar o arquivo GDD" não é editar HTML, é:

1. Resemear `fertwaysdev` com o `BuildingSpecSeeder` atual (tinha os 15 níveis do Reator só em
   produção e no SQLite efêmero dos testes — o dev ainda estava no nível 5, D-157 nunca tinha sido
   propagado pra lá).
2. Rodar `php84 tools/gdd-v38.php > docs/FERTWAYS_GDD_v38_CONSOLIDADO.html` de novo.

O `<h4>` de cada construção já monta o "(até o nível N)" dinamicamente
(`max(array_keys($niveis))`) — a tabela do Reator saiu com "até o nível 15" e as dez linhas novas
sozinha, sem eu tocar em HTML nenhum. **Bônus não pedido**: a tabela de slots das essenciais
TAMBÉM é lida do banco, e a última regeneração era de 21/07 — dois dias antes do D-152 (Reator do
slot 21 pro 6). A regeneração corrigiu os dois atrasos de uma vez só, exatamente o que o gerador
promete no próprio comentário: "o documento não pode divergir do jogo".

`frontend/public/gdd.html` é uma cópia idêntica do canônico (é o que o Vite publica em `/gdd.html`
— confirmado por `diff`, os dois nasceram do mesmo `cp`); as duas foram atualizadas juntas.

### Validado

`diff` do antes/depois: só 4 mudanças semânticas (a data de geração, o slot do Reator 21→6, o
título da tabela 5→15, e as 10 linhas novas com os mesmos números do D-157) — nada mais mudou.
Deploy publica `dist/gdd.html` (cópia de `public/gdd.html` pelo Vite) em `public_html/gdd.html`
via `cp -rf dist/. "$PUBLICO/"`, já parte do `deploy.sh` de sempre — sem passo extra.

## D-160 — O GDD v39: a terceira regeneração do v36, D-141 a D-159

**Data:** 2026-07-30 · **Status:** pedido direto do usuário ("Crie uma nova versão do GDD e
atualize o GDD da landing page") · **Documento, não código de jogo**

O D-159 tinha **regenerado** o v38 (mesmo gerador, banco resemeado) — isso pega número, não regra.
Este pedido é o outro: uma **versão nova**, que é o que o D-141 fez quando o v36 ficou 39 decisões
atrás do jogo. Desde o D-140 (o corte do v38) acumularam-se **19 decisões**, e entre elas duas
mudanças de regra fundas que nenhuma regeneração traria sozinha: a **zona neutra virou colmeia de
slots** (D-144) e a **periferia deixou de ser fundável em qualquer lugar** (D-147).

### O método, igual ao do D-141: copiar, não regravar por cima

`tools/gdd-v39.php` é cópia evoluída de `gdd-v38.php`, que **fica intocado** — como o v36 ficou
quando o v38 nasceu, e o v35 quando o v36 nasceu. Cada geração é um arquivo novo; o rastro de como
o próprio GDD evoluiu fica no git.

### O que entrou, e onde

| Decisão | Onde, no v39 |
|---|---|
| D-144 — a zona vira colmeia de 22 slots, crescimento por nível, 3 repetíveis, nível máx. 5→10 | **§8.0, novo** (mais uma nota em §8.7: tudo na zona se identifica por slot, não por tipo) |
| D-147 — a periferia só é fundável na célula que o Dono liberar | **§1.1.1, novo**, e a linha "Periferia" da tabela do §1.1 |
| D-148 — o Dono cria zona neutra fora dos 4 distritos, escolhendo o mineral | nota nova na abertura do §8 (inclui o achado: "é zona neutra?" virou pergunta de banco) |
| D-145/D-146/D-147/D-148 — o mapa do painel e as três ações que vivem nele | **§11.6, novo** |
| D-151 — o Frete do Governo leva vários recursos na mesma viagem | §5.6 (linha da tabela + nota: o teto sempre foi sobre a SOMA) |
| D-153 — card "Recursos por hora", nominal, produzido e gasto separados | **§3.4, novo** |
| D-157 — o Reator até o nível 15, pelas duas curvas do próprio GDD | §2.3 e §4.2 (a tabela já vinha do banco desde o D-159) |
| D-142/D-149/D-150/D-152 — as três trocas de slot do miolo | nota nova no §2.1 (e o §2.2, que ainda dizia que o Depósito morava na linha solta do fim) |

**Ficaram de fora, de propósito**, pelo critério que a seção 0 já publica (o documento descreve
mecânica, não tela): `/mapa` em tela cheia com pinça e legenda em card (D-154, D-155, D-156), o
ícone da zona neutra livre na legenda (D-158) e a Lista Mestra de Assets de Estruturas (D-143). Os
três estão **nomeados** na nota da seção 0, para que ficar de fora seja uma decisão registrada e
não um esquecimento.

### Duas frases do v38 que tinham virado mentira

Achadas relendo o documento antigo contra o código atual, não pedidas:

- **§2.2** dizia que o Depósito Local era "o 22º slot, sozinho na última linha da colmeia" — desde o
  D-142/D-150 ele mora no **centro** (10); o 21 é slot comum. O acréscimo da linha solta continua
  sendo verdade sobre a *colmeia*, não sobre a *construção*, e o texto passou a separar as duas
  coisas (lendo `Slots::DEPOSITO_LOCAL`, não um número digitado).
- **§8.7** descrevia a primeira aba da zona como "identidade, **planta**, upgrade" — a planta com
  áreas fixas morreu no D-144.

### Validado

`/usr/bin/php84 -l` limpo; gerador rodado contra o dev (`fertwaysdev`, migrations em dia —
conferido antes, é a armadilha do D-141) **sem um warning sequer**. Balanceamento de tags conferido
por script em **21 tags** (`div`, `table`, `tr`, `td`, `th`, `p`, `h2`, `h3`, `h4`, `ul`, `ol`,
`li`, `b`, `code`, `span`, `i`, `pre`, `nav`, `header`, `footer`, `main`): abertura = fechamento em
todas. `diff` do v38 contra o v39 conferido linha a linha — **256 linhas de diferença, e as 14
removidas são exatamente as 14 que eu quis reescrever** (título, capa, contagem de decisões,
periferia, §2.2, carga do frete, §8.7 e rodapé). Sem mudança de código de jogo: nenhuma migration,
nenhum teste tocado, nada a resemear.

`frontend/public/gdd.html` (o arquivo estático que o Vite publica em `/gdd.html`, fora do alcance
do gerador, que só escreve em `docs/`) recebeu o v39 por `/bin/cp -f` — com o `-f` e o caminho
absoluto de propósito: o alias `cp -i` do root já engoliu uma cópia em silêncio antes (D-141).
Conferido por `diff`: as duas cópias são idênticas.

---

## D-161 — O design system da Alpha 2 (A2.V1): a cor deixa de ser escolhida a olho

**Data:** 2026-07-31 · **Status:** primeira entrega da Alpha 2, fase A2.V1 do
`docs/alpha2/ROADMAP_ALPHA2.md` · **Frontend, mais duas ferramentas**

A A2.V1 vem antes de tudo por dependência dura, e não por gosto: a tela "Desde sua última visita"
é a primeira tela nova da Alpha 2 e vai fixar o padrão visual na marra. Se os tokens viessem
depois, ela e o painel de população nasceriam fora do sistema e teriam de ser refeitos.

### O que o confronto com o código real achou

Boa parte do diagnóstico contrariou a expectativa. **A disciplina de cor já estava completa**:
zero cores cruas do Tailwind, zero hex arbitrário, 2.336 usos dos sete tokens da marca em 50
componentes. E dos 58 `outline-none`, 55 já traziam substituto de foco. Não havia resgate a fazer.

O que faltava era outra coisa, e mais funda:

1. **A paleta não sabia dizer "deu errado".** 120 menções a *erro* no código e nenhuma cor de erro
   — tudo era `rust` em opacidades variadas, a mesma cor da marca.
2. **`.botao` nunca existiu.** Dezoito elementos em sete telas escrevem `className="botao …"` desde
   o D-66/D-67/D-69. A classe não foi removida por acidente: o `git log -S` mostra que **nunca foi
   escrita**. Esses dezoito botões renderizam há semanas com o cinza padrão do navegador.
3. **A fonte nunca foi carregada.** `font-family: 'Archivo'` está declarado sem `@font-face`, sem
   link e sem arquivo local. Todo mundo cai em `system-ui` — os títulos condensados do deck não
   existem no produto.
4. Seis opacidades diferentes (`/10` a `/40`) para desenhar a mesma linha fina, e onze lugares com
   `text-[9px]`, `text-[10px]` e `text-[0.6rem]` para o mesmo rótulo minúsculo.

### As cores de estado saíram do deck, não da cabeça

O `docs/design-tokens.md` promete que nenhuma cor foi escolhida a olho. Inventar um verde e um
vermelho teria quebrado essa promessa em silêncio, então `tools/amostra_estados.py` foi ao mesmo
lugar de onde vieram as sete originais: os PNGs de `/home/fertways/pitch`. Saíram `sucesso`
(`#245448`), `perigo` (`#78180C`) e `info` (`#243C48`) — escuras de propósito, porque a superfície
do jogo é clara e cor de estado só vira texto legível se for mais escura que o fundo.

### Duas armadilhas que só a medição mostrou

`tools/valida_contraste.py` mede todos os pares e **falha com status 1**. Ele achou duas coisas que
nenhuma inspeção visual pegaria:

- **`ember` não pode ser texto.** Sobre areia dá **1,62:1**. Mas como *fundo*, com letra `ink`, dá
  8,71:1. Por isso o estado `aviso` é o único que sempre pinta o fundo — não é inconsistência, é a
  única forma que passa.
- **O vermelho do deck fica a 14° de matiz do `rust`.** A paleta é quente por identidade; não há
  vermelho frio nela. Num relance, ou para quem tem deficiência de visão de cor, *apagar para
  sempre* e *confirmar* são o mesmo botão.

> **Regra que fica: destrutivo nunca se anuncia só por cor.** O `Botao` de variante `perigo`
> desenha um triângulo antes do rótulo, e o `Erro` escreve a palavra "Erro". O glifo não é enfeite:
> é o segundo canal, o que carrega o aviso quando a cor falha.

Registro de uma margem apertada: **`rust` sobre `sand` passa por 4,58:1**, folga de 0,08 sobre o
mínimo da WCAG. Não é número que se defenda de memória — é por isso que o validador existe.

### O que foi entregue

- `frontend/src/index.css` — cores de estado, hairline em duas espessuras (eram seis), `--text-micro`,
  foco visível por padrão em `:focus-visible`, `prefers-reduced-motion`, e a definição de `.botao`.
- `frontend/src/ui/sistema/` — `Botao`, `Cartao`, `Selo`, `Carregando`/`Vazio`/`Erro`. **O par de
  cores não é parâmetro de nenhum deles**: quem usa escolhe a intenção, o componente escolhe fundo e
  texto juntos, dentro do que foi medido.
- Os onze tamanhos arbitrários viraram `text-micro`; não há mais `text-[…]` em `.tsx`.
- Os três `outline-none` sem substituto (o mesmo input do Chat, repetido) ganharam foco visível.

### O que a A2.V1 NÃO fez, de propósito

Os 171 `<button>` de 26 arquivos **não foram migrados** para o `Botao`. O roadmap diz que a V1
constrói o sistema e que V2 a V6 o aplicam, coladas em cada fase; migrar tudo agora seria um diff
gigante sem tela nova para provar que o sistema serve. Pelo mesmo motivo `.botao` virou classe CSS
em vez de componente: conserta os dezoito hoje sem antecipar o diff da migração.

**Carregar a fonte Archivo ficou em aberto** — auto-hospedar acrescenta peso a um bundle que já dá
1,9 MB; CDN acrescenta dependência externa e um RTT. É decisão do usuário, não omissão.

### ⚠️ Um vermelho no e2e que NÃO era regressão

A primeira rodada do e2e depois das mudanças ficou vermelha no Chat: *o botão "Conversar" existe no
popup aberto pela busca*. A segunda rodada, **com o código idêntico**, ficou verde — 9 suítes, 332
asserções, o mesmo da linha de base.

A causa é o teste, não o jogo: o botão só existe depois que `InfoJogador` recebe a resposta de
`api.jogador()`, e a linha usava `page.$` — uma olhada só, logo depois de um `assentar()` de 300 ms
fixos. Passou a usar `waitForSelector` com 8 s. **A asserção não foi enfraquecida**: se o botão não
existir mesmo, o tempo se esgota e reprova. O que mudou é parar de confundir *ainda não chegou* com
*não existe*.

Vale como lição de método: o impulso era atribuir o vermelho à mudança recém-feita. A atribuição
só ficou honesta depois de rodar de novo sem mexer em nada.

---

## D-162 — A fonte Archivo é auto-hospedada, não vem de CDN

**Data:** 2026-07-31 · **Status:** fecha a pendência que o D-161 deixou em aberto · **Frontend**

O D-161 achou que `font-family: 'Archivo'` estava declarado desde sempre **sem `@font-face`, sem
link e sem arquivo local**. Todo mundo caía em `system-ui`: os títulos condensados de peso alto do
deck simplesmente não existiam no produto. O D-161 não resolveu de propósito, porque as duas saídas
têm custo e a escolha é do usuário.

**Escolhido: auto-hospedar,** via `@fontsource-variable/archivo` (OFL-1.1, que permite).

O argumento do peso — que fui eu mesmo quem levantou — não sobreviveu ao número real: o subconjunto
`latin` pesa **34 KB** num bundle de 1,9 MB, ou 1,8%. A CDN cobraria noutra moeda, e mais cara: uma
requisição a terceiro em toda sessão, um RTT a mais para jogadores que estão no mesmo país do
servidor, e uma dependência externa que pode ser bloqueada ou sair do ar.

Dois detalhes do pacote que fazem a conta fechar: ele traz **`unicode-range` por subconjunto**,
então `latin-ext` (32 KB) e vietnamita (13 KB) só descem se algum nickname os exigir; e usa
`font-display: swap`, então o texto nunca fica invisível esperando a fonte chegar.

### ⚠️ A armadilha do nome

A família registrada pelo fontsource é **`'Archivo Variable'`** — com o espaço e o "Variable".
`'Archivo'` sozinho **não casa** com ela. Errar isso não quebra nada de forma visível: a cascata cai
no fallback e a tela continua parecendo funcionar, só que com a fonte errada. É exatamente o defeito
que sobreviveu semanas antes do D-161, e por isso o token traz as duas — `'Archivo Variable'`
primeiro, `'Archivo'` atrás, para quem a tenha instalada no sistema.

Conferido no CSS compilado: 3 `@font-face`, 3 arquivos woff2 e 3 `unicode-range`, com o mesmo nome
de família nos dois lados. E2E com 9 suítes verdes e 332 asserções — a métrica nova da fonte não
mexeu em nenhum teste sensível a posição.

---

## D-163 — Telemetria de gameplay (A2.0.1): a medida deriva do ledger, não o duplica

**Data:** 2026-07-31 · **Status:** fase A2.0 do `docs/alpha2/ROADMAP_ALPHA2.md`, primeira fatia
· **Backend**

A fase A2.0 existe para parar de avaliar o jogo pelo "funciona/não funciona". Mas a decisão que dá
forma a tudo é anterior ao roadmap: **o ledger já existe, já é append-only e já tem 48 tipos** que
cobrem todo fato econômico. Instrumentar produção, compra, tributo ou subsídio de novo escreveria a
mesma verdade duas vezes — e duas verdades divergem. "Quanto de Fert$ foi emitido" passaria a ter
duas respostas, e a segunda seria a errada.

Então a telemetria **deriva** do ledger o que ele enxerga, e só instrumenta o que ele não vê:
sessão, navegação, abandono de onboarding, falta de insumo e falta de energia.

### Duas camadas, e a razão é aritmética

- **`telemetry_events`** — o evento discreto (login, upgrade concluído, ocupação de zona). Retenção
  de **90 dias**.
- **`telemetry_daily`** — o fluxo contínuo (produção, consumo, saldo) como retrato por dia e por
  colônia. **Não expira**: já é o agregado.

A separação não é economia de espaço, é a diferença entre uma tabela governável e uma inútil. O
tick roda **a cada minuto**; um evento de produção por recurso por colônia por tick daria mais de
1.400 linhas por colônia por dia sem responder nada que o retrato diário não responda melhor.

### O achado que deu trabalho: o ledger não tem sinal

Conferido no banco: **191 lançamentos, nenhum negativo.** O `amount` é sempre absoluto e a direção
está no **tipo**. Agregar produção e consumo, portanto, exige arbitrar tipo a tipo — e errar aqui
não produz erro visível, produz um gráfico plausível e falso.

`App\Domain\Telemetria\DirecaoDoLedger` faz essa arbitragem, com três baldes: 18 tipos de entrada,
17 de saída, e **8 indefinidos**.

### ⚠️ Os 8 indefinidos estão esperando arbitragem do usuário

`deposito_mercado`, `retirada_mercado`, `escrow_mercado`, `escrow_leilao`, `compra_mercado`,
`transferencia`, `ajuste_admin`, `estorno`.

O que eles têm em comum é **não serem criação nem destruição de valor — são mudança de lugar**. O
escrow tira do depósito e prende na ordem, sem nada se produzir; a `transferencia` é saída de um
lado e entrada do outro; o `estorno` tem o sinal do lançamento que desfaz; o `ajuste_admin` é o
único delta com sinal de verdade do jogo (D-61).

Contá-los como produção infla a economia com dinheiro que só andou de bolso; como consumo, faz o
mesmo ao contrário. **Ficam fora da conta**, e o comando relata quantos ficaram — para o buraco ser
visível em vez de silencioso. Pela regra de ouro da casa, isto é lacuna do desenho e se pergunta ao
usuário; não se inventa.

### As invariantes que os testes guardam

- **Nenhum tipo de ledger pode ficar sem classificação.** `classificar()` lança em tipo
  desconhecido em vez de devolver um default, e o teste exige que todo tipo de `Ledger::TIPOS`
  esteja num dos três baldes. Um `default => neutro` faria todo tipo novo entrar mudo, e o sintoma
  seria um número que encolhe sem explicação meses depois.
- **Medir nunca derruba a jogada.** `RegistrarEvento` engole qualquer falha e devolve `null`. É a
  única classe do domínio autorizada a isso, e a autorização vem de não ser regra de jogo: um evento
  perdido é um buraco num gráfico; uma exceção propagada seria uma viagem perdida.
- **Append-only**, como o ledger. Medida que se corrige depois de registrada é opinião. A trava fica
  no modelo. ⚠️ Ela **não** pega `->delete()` em massa do query builder, que não dispara evento de
  modelo — limitação do Eloquent, anotada no código para ninguém confiar demais nela.
- **Agregação idempotente**: chave única (colônia, dia, recurso) mais `upsert`. Sem isso, uma
  execução repetida por engano dobraria a produção de um dia inteiro.

### Bot não é origem

`origin` é `humano` ou `sistema`, e nada mais. Bots são externos e jogam em staging (GDD ALPHA 2
§14); a distinção vem do ambiente. Uma coluna `bot` aqui só criaria a tentação de rodá-los em
produção — e o ledger é append-only, então a contaminação seria permanente, justamente nas métricas
que a simulação existe para produzir.

O que precisa ser separado é o que o **operador** faz do que o jogador faz: um DAU que conta o admin
é um DAU mentiroso.

### O agendamento, que é onde este tipo de coisa morre

`routes/console.php` ganhou as duas linhas. Sem elas a estrutura fica inerte e a falha é silenciosa
— o mesmo esquecimento dos seeders de produção (D-52, D-57, D-60). Agrega às 00h10, varre às 03h; a
ordem deixa escrito que o agregado do dia existe antes de o evento ser descartado.

### Verificação

945 testes verdes (927 da base + 18 novos). ⚠️ E o que o SQLite **não** provaria: a migration foi
exercitada num MariaDB descartável, onde `json` virou `longtext` com `CHECK (json_valid(...))` —
o 10.5 não tem tipo JSON nativo. O `upsert` foi rodado **duas vezes** contra o MariaDB para provar
a idempotência lá, e não só no SQLite, que usa sintaxe diferente. O banco foi derrubado depois.

### O que falta na A2.0

A2.0.2 (painel de métricas) e A2.0.3 ("Desde sua última visita"). A coluna `users.resumo_visto_em`,
de que a janela do §5.1 depende, já entrou nesta migration.

---

## D-164 — "Desde sua última visita" (A2.0.3), e a correção de um erro do D-163

**Data:** 2026-07-31 · **Status:** fase A2.0 do `docs/alpha2/ROADMAP_ALPHA2.md`, segunda fatia
· **Backend e frontend**

A primeira tela nova da Alpha 2, e a razão de a A2.V1 (D-161) ter vindo antes de tudo: ela fixa o
padrão visual na marra. Nasceu inteira sobre o design system — `Botao` e `Selo`.

### ⚠️ Primeiro, o erro do D-163

O D-163 afirmou que **o ledger guarda valor absoluto e a direção está no tipo**. Está errado.

Eu tinha conferido no banco de desenvolvimento que nenhum dos 191 lançamentos era negativo, e
generalizei. O banco de dev só não tinha negativos porque **só tinha entradas** — subsídio, kit
inicial, saldo inicial. O código escreve saída como negativo em **dezenove lugares**
(`'amount' => -$qtd`, em `EnqueueUpgrade`, `ComprarVeiculo`, `CobrarManutencaoTerritorial`…).

O sinal já estava no dado. A tabela `ENTRADA`/`SAIDA` do `DirecaoDoLedger` reconstruía, por palpite,
uma informação que o ledger carrega com precisão — e um tipo mal classificado somaria no balde
errado sem nenhum sintoma. Pior: **o teste passava porque eu mesmo inseria `custo_construcao` com
valor positivo**, um mundo que a produção nunca produz.

Corrigido: **a direção vem do sinal**. `DirecaoDoLedger` passou a ter um único trabalho — decidir o
que **não conta** (`NAO_CONTA`: escrow, transferência entre colônias, compra casada, estorno e
`ajuste_admin`), porque isso o sinal não resolve: são mudança de lugar ou correção, não criação nem
destruição de valor. Com isso os "8 indefinidos" que o D-163 deixou pendentes ficam **decididos**,
e não pendurados: eles não entram no fluxo, por natureza.

### A janela (GDD ALPHA 2 §5.1)

Por **resumo visto**, não por sessão — e não é preciosismo: o jogo não tem sessão, e quem fecha a
aba nunca encerra uma. O marcador é `users.resumo_visto_em` (criado no D-163) e avança **ao
fechar**, não ao abrir.

Três regras, três testes:

- **Primeira visita não mostra nada**, e planta o marcador. Mostrar apresentaria a fundação da
  própria colônia como "novidade desde sua última visita" para quem acabou de chegar.
- **Piso de uma hora**: quem recarrega a página não leva um modal a cada visita.
- **Abrir não consome a janela.** `GET` monta e não marca; só o `POST /resumo/visto` move. Se o GET
  movesse, abrir e fechar sem ler apagaria para sempre o que aconteceu enquanto o jogador estava
  fora. É a invariante mais importante do conjunto.

### Um cast que quase escapou, e por que quase

`users.resumo_visto_em` não tinha cast `datetime`. Os testes passavam porque `actingAs($u)` guarda a
instância em memória, onde o atributo ainda era Carbon vindo do `forceFill`. **Em produção o usuário
chega do banco pelo Sanctum, como string, e o `->copy()->addMinutes()` do piso estouraria.** É
exatamente a armadilha que o comentário do `suspenso_ate` já registrava, repetida. Há agora um teste
que carrega o usuário do banco só para fechar essa porta.

### De onde vêm os números

Do ledger e do `build_queue`. Nenhum evento de telemetria novo foi criado, pelo mesmo princípio do
D-163. A conclusão de obra sai do `build_queue` (`status = 'done'`, ninguém apaga essas linhas)
porque o tick **zera `upgrade_finish_at`** ao concluir — a construção não guarda quando subiu.

### Decisões de produto na tela

- **Nada na tela enquanto carrega, e nada se falhar.** É a única tela que o colono não pediu: ela se
  convida. Um modal de "carregando" piscaria a cada carga de página, inclusive para quem está dentro
  do piso e não veria resumo nenhum.
- **Fechar é instantâneo**: a marcação é disparada e não esperada. Se falhar, o resumo reaparece na
  próxima visita — melhor do que um botão pendurado num POST.
- **"Nada aconteceu" é dito**, não escondido. Quem passou dois dias fora com a colônia parada
  precisa ver que não produziu nada.

### ⚠️ E um defeito no próprio arranjo de testes

`relatar()` em `e2e/comum.mjs` **devolvia** 0/1 e ninguém usava o retorno: o node saía 0 mesmo
imprimindo "E2E VERMELHO". Sob `set -e`, uma suíte reprovada **não reprovava o script** — ele
seguia e terminava anunciando sucesso. Foi assim que uma execução vermelha me devolveu status 0
hoje. Agora `process.exit(verde ? 0 : 1)`.

Um teste que falha em silêncio é pior do que teste nenhum: teste nenhum ao menos não dá a impressão
de cobertura.

### Verificação

960 testes verdes (14 novos do resumo). E2E com **10 suítes** e 340 asserções — a nova
`resumo.e2e.mjs` roda **primeiro**, de propósito: o resumo é um popup `fixed inset-0` que
interceptaria os cliques de todas as outras se ficasse aberto; ao ser fechado, o piso de uma hora o
silencia pelo resto da execução.

---

## D-165 — Painel de métricas (A2.0.2): zero e "ninguém mediu" não podem ter a mesma cara

**Data:** 2026-07-31 · **Status:** fase A2.0 do `docs/alpha2/ROADMAP_ALPHA2.md`, fatia final
· **Backend, aba nova no painel do operador**

A A2.0.2 pede quinze indicadores. **Nem todos têm de onde sair hoje** — o funil de onboarding quer
`onboarding_abandonado`, os gargalos de cadeia querem `falta_de_insumo`, e ninguém os emitia.

A tentação era preencher com zero. Seria o pior desenho possível: **zero e "ninguém mediu" são a
mesma imagem na tela e coisas opostas na realidade.** Um operador olharia o funil zerado e concluiria
que ninguém abandona o onboarding — quando a verdade é que ninguém sabe.

Então o painel **abre pela lista de lacunas**, antes de qualquer número: o indicador, o evento que
falta e onde ele nasceria. E a lista é **derivada** do que a tabela realmente contém, não uma
constante que alguém teria de lembrar de editar — quando um evento passa a ser emitido, a linha some
sozinha. Há teste para isso.

### O que foi instrumentado agora, fechando três lacunas

- **`colonia_fundada`** em `CreateColony`, dentro da transação: uma fundação que falhe no meio não
  pode deixar evento de colônia que não existe.
- **`falta_de_insumo`** em `EnqueueUpgrade` — a parede mais comum do jogo.
- **`falta_de_energia`** em `DespacharVeiculo`, com tipo próprio: falta de insumo é cadeia produtiva
  incompleta; falta de energia é a colônia inteira parando. Um tipo só esconderia a diferença.

O ledger não vê nada disso por definição: ele registra o que **aconteceu**, e estas são o registro
do que **não** aconteceu. É a métrica mais valiosa da fase — é onde o jogo trava sem avisar ninguém.

### O viés vai junto do número, não numa nota de rodapé

A duração mediana de sessão sai de pares login→logout, e **quem fecha a aba nunca emite logout**.
Por isso ela vem acompanhada da **cobertura**: a fração de logins que teve logout correspondente.
Uma mediana de 20 min com 15% de cobertura não quer dizer "a sessão típica dura 20 minutos"; quer
dizer "das poucas sessões que alguém encerrou de propósito, metade durou menos que isso".

Pela mesma razão, login sobre login **não** vira duração: a sessão anterior nunca foi encerrada, e
contá-la até o login seguinte inventaria um número que ninguém observou.

E o retrato diário vazio traz bandeira própria: não é "a economia parou", é "o agregador ainda não
rodou".

### Concentração de riqueza: fatia do topo, não Gini

"Os 10% mais ricos têm 60% do Fert$" diz mais, a quem vai decidir balanceamento, do que "Gini 0,48".
O Gini entra se e quando alguém precisar comparar séries.

### ⚠️ E a lição mais cara do dia: o SQLite não valida NOME DE COLUNA

Escrevi `whereNotNull('colony_id')` numa tabela cuja coluna é `owner_colony_id`. **Os treze testes
passaram em verde.** O MariaDB reclamou na primeira consulta.

A causa é uma armadilha do SQLite: **identificador entre aspas duplas que não casa com coluna
nenhuma é reinterpretado como literal de texto.** Então `WHERE "colony_id" IS NOT NULL` vira
`WHERE 'colony_id' IS NOT NULL` — sempre verdadeiro. Provado num `sqlite::memory:` cru: contou 2 de
2 linhas que deveriam dar 0. O MariaDB responde `1054 Unknown column`.

Isso **amplia a regra da casa**. Ela era "o verde do `artisan test` não prova DDL". Passa a ser: o
verde do `artisan test` também não prova **nome de coluna em consulta**. Um erro de digitação vira
filtro que não filtra, e o número sai plausível e errado — que é o pior tipo de erro num painel de
métricas, porque é o tipo que alguém usa para decidir balanceamento.

### Verificação

973 testes verdes (13 novos), **incluindo dois que renderizam a página de verdade** — uma view Blade
quebra em runtime, e nenhum teste dos indicadores pegaria isso. E os indicadores foram exercitados
contra o **MariaDB de dev**, por leitura, que é o que achou o nome de coluna errado.

---

## D-166 — Onboarding produtivo (A2.1): a fase era menor do que o plano supunha

**Data:** 2026-07-31 · **Status:** fase A2.1 do `docs/alpha2/ROADMAP_ALPHA2.md` · **Backend**

O roadmap previa duas adaptações no motor de Missões. O confronto com o código mudou o tamanho das
duas — para menos, e por bons motivos.

### A premissa "missão é recusável" não se sustenta

O plano dizia: *"missão hoje é recusável; a fase obrigatória mínima não pode ser"*. **Não existe
mecanismo de recusa no código** — nada de botão "abandonar", nada de status `recusada`. O que
tornava a tutoria dispensável era outra coisa: ela **expirava em 3 dias** e nada acontecia se o
colono simplesmente a ignorasse.

### E o encadeamento obrigatório já existia

A categoria `narrativa` (D-140) já é encadeada por `requer_template_id`, entrega um capítulo por vez
e **não expira** (`expires_at` nulo). É exatamente a forma que o onboarding pede.

Então não houve mecanismo novo a construir: `garantirNarrativa()` virou
`garantirEncadeada($colony, $categoria)` e a tutoria passou a usá-la. Sobrou **uma coluna** —
`mission_templates.obrigatoria` —, porque o encadeamento diz a ordem, não a obrigação.

### A regra que decide o que é obrigatório

**Só pode ser obrigatória a etapa que um jogador sozinho conclui.**

O próprio roadmap exige que "o tutorial não dependa de uma oferta real de outro jogador" — e num
servidor recém-aberto, ou às quatro da manhã, não há outro jogador. Travar o onboarding numa compra
que precisa de contraparte prenderia o colono numa porta que não depende dele.

Por isso obrigatórias são só as duas primeiras, que ensinam o coração do jogo em solidão: erguer e
esperar o tick (`tut_primeira_obra`), despachar e descobrir que o planeta é físico
(`tut_primeiro_despacho`). `tut_primeiro_lote` exige contraparte no Mercado — fica como sugestão,
nunca como trava.

### A tutoria não expira mais

Duas razões: uma etapa obrigatória que some sozinha em 3 dias é uma contradição; e expirar o meio de
uma sequência deixaria o colono **encalhado**, porque o degrau seguinte só nasce quando o anterior
conclui — um degrau expirado tranca a escada inteira.

### O grandfathering, e por que ele não paga

`fertways:onboarding-grandfather --aplicar` marca as etapas como concluídas nas colônias que já
existiam, **sem pagar recompensa** (GDD ALPHA 2 §4.3). Sem ele, o motor entregaria a
`tut_primeira_obra` a quem já ergueu cinquenta níveis, ela concluiria no primeiro tick, e a
recompensa cairia no ledger — corretamente registrada, o que é o pior do problema, porque o ledger é
append-only e não se desfaz.

As linhas entram direto como `concluida`, **sem passar pelo `Progresso`**, que é justamente quem
paga. É a diferença entre "marcar como visto" e "cumprir".

### ⚠️ Dois bugs que só os testes acharam, os dois silenciosos

- **`<` contra `now()` num `created_at` de precisão de SEGUNDO.** A colônia criada no mesmo segundo
  do corte ficava de fora. Em produção quase nunca apareceria — as colônias são velhas —, e é
  exatamente por isso que teria sobrevivido.
- **Projeção sem `id`.** `->get(['colony_id', 'template_id', 'status'])` faz `getKey()` devolver
  **null**, e o `whereIn('id', [null])` da promoção não atingia linha nenhuma. Falha muda: o comando
  relatava sucesso e não promovia nada.

O segundo mascarou o primeiro por um tempo, e um terceiro teste passava em verde **porque nada
acontecia** — o de "não paga recompensa" naturalmente passa quando o comando não faz nada. Verde por
inação é o pior tipo de verde.

### Um teste antigo mudou de regra, e não foi apagado

`MissoesTest::test_a_fundacao_entrega_as_cinco_da_tutoria_com_tres_dias_de_prazo` afirmava o
comportamento anterior. Virou `test_a_fundacao_entrega_so_o_primeiro_degrau_da_tutoria`, com o
porquê escrito no docblock. O "5" do §06 não se perdeu — continua sendo cinco etapas, e
`test_o_pool_publicado_existe` guarda isso; o que mudou é **como** elas chegam.

### ⚠️ Achado à parte: `tutorial_completed_at` mente

O campo é escrito **dentro do `CreateColony`** — ou seja, significa "já fundou colônia", não
"completou a tutoria". E `tutoriaConcluida()` é a trava do subsídio em `BuildingController`, o que
torna essa condição **sempre verdadeira** para quem tem colônia.

Não mexi: alterar isso mudaria a economia do subsídio, que é decisão de jogo e não de refatoração.
Fica registrado porque o próximo que ler aquele `if` vai supor outra coisa.

### ⚠️ Passos à mão na publicação

O `deploy.sh` **não roda seeders** (mesma armadilha do D-52, D-57 e D-60). Depois de publicar:

1. `php84 artisan db:seed --class=MissionTemplateSeeder --force` — sem isto a cadeia e a marca de
   obrigatoriedade não existem em produção, e a tutoria continua plana.
2. `php84 artisan fertways:onboarding-grandfather --aplicar` — **nesta ordem**, e antes de qualquer
   jogador entrar. Rodar depois significa que alguém já pegou a recompensa.

### O que a A2.1 NÃO entregou, e por quê

O roadmap lista uma sequência de **14 passos**. Os cinco primeiros (Energia, Oxigênio, Água,
Biomassa, Produção/h e consumo/h) são etapas de **compreensão**, não de ação: o motor não tem verbo
para "entendeu energia", e inventar um significaria uma missão que se conclui sozinha ao abrir uma
tela. Esses pertencem ao trabalho de HUD da A2.V2, onde o roadmap já os coloca ("alertas de
produção").

Os passos de fila, processamento e depósito precisariam de verbos novos em `Acoes::TODAS`
(`obra_enfileirada`, `receita_produzida`, `deposito_feito`), cada um com instrumentação no domínio
correspondente. É trabalho real, delimitado, e fica anotado — não foi feito porque acrescentaria
recompensas novas, e recompensa é emissão de Fert$: número que se arbitra, não se inventa.

### Verificação

984 testes verdes (11 novos). Migration e comandos exercitados num **MariaDB descartável**: a coluna
nasce `tinyint(1) default 0`, o seeder encadeia 1→2→3→4→5 e o grandfather roda limpo. Banco derrubado.

---

## D-167 — População (A2.2): o modelo entra desligado, e o simulador nasce junto

**Data:** 2026-07-31 · **Status:** fase A2.2 + primeira entrega da trilha A2.S · **Backend**

Dá enfim função à Estrutura de Sobrevivência, que o `Funcoes::CATALOGO` descreve há meses como
`'efeito' => 'nenhum'`, com a nota honesta de que *"o GDD não diz quantos colonos ela abriga, nem o
que a população faz"*. O **quanto** continua sendo arbitragem; o **que ela faz** deixa de ser nada.

### ⚠️ Entra DESLIGADA, e a razão é a regra 9 do roadmap

`population_settings.ativo` nasce `false`. O mundo não tem reset, e **todos** os parâmetros de
população estão PENDENTE no `BALANCEAMENTO.md` §7.1 — nenhum saiu de simulação. Ligar consumo e
crescimento com número de palpite mexeria na economia de um jogo que está no ar, com colônias reais,
e o ledger é append-only: o estrago ficaria registrado para sempre.

O critério de saída da fase é categórico: *"nenhum parâmetro populacional sai de HIPÓTESE sem uma
rodada registrada do simulador da trilha A2.S"*. A ordem, portanto, é esta — o modelo existe, o
simulador o exercita num mundo descartável, os números se arbitram com evidência, e só então a chave
vira. **Virar a chave é decisão do usuário, não minha.**

### O modelo (A2.2.1): só o total é guardado

Os cinco estados que a fase pede — total, capacidade, alocada em construções, alocada em zonas,
disponível — mas **apenas o total** vira coluna. As alocações são derivadas do que a colônia de fato
tem erguido e ocupado: um contador paralelo criaria duas verdades sobre a mesma coisa, e a segunda
dessincroniza na primeira demolição que alguém esquecer de descontar.

**"Disponível" pode ser negativo, de propósito.** É o estado de quem foi grandfatherizado com folga
curta ou perdeu população por escassez. Zerar o negativo esconderia exatamente o que o jogo precisa
mostrar.

### Sustento e crescimento (A2.2.2/A2.2.3): degrada, não mata

Faltando insumo, a população **não morre**: a eficiência cai até um piso enquanto durar a falta. É a
mesma escolha do §6.6 para zona abaixo dos operadores exigidos. Num jogo persistente sem reset,
matar colono de quem passou o fim de semana fora não é dificuldade, é hostilidade.

Duas regras que valem registro:

- **O gargalo manda, não a média.** A razão de suprimento é a do recurso mais escasso. Média
  esconderia o caso que interessa: nadando em água e sem oxigênio, a colônia teria razão "boa" e
  cresceria rumo à asfixia.
- **O teto trava, não derrama** — mesma regra que a A2.7 fixou para estoque.

### Mão de obra (A2.2.4): tabela esparsa, e o requisito é do nível atual

`building_operator_requirements` é esparsa: construção sem linha não exige ninguém. O requisito se
afirma, nunca se herda de um default.

E é o do **nível atual**, não a soma da escada: uma Fazenda 5 pede a equipe de uma Fazenda 5, não a
de cinco fazendas. Somar faria o requisito explodir com o progresso e tornaria a expansão impossível
por acidente aritmético.

Fica **fora de `building_specs`** porque aquela tabela é gerada do GDD (`tools/gdd-v39.php`), e
requisito de operador é arbitragem — como o teto do Depósito da Capital (D-58), que por isso mesmo
vive no domínio e não no catálogo.

### ⚠️ O simulador achou um defeito de modelo na primeira rodada

A curva saiu **perfeitamente horizontal por 60 dias**: a população não crescia nunca. Com 5 colonos
a 0,5%/h, um passo de uma hora dá 5,025, o `floor` devolve 5, e fica preso em 5 para sempre. Não é
imprecisão — é travamento total.

Corrigido com acumulador de resto fracionário (`colonies.populacao_resto_milli`), o mesmo idioma de
`siderurgica_lote_remainder`, que a casa já usa para o lote da Indústria. Quando a casa já tem
solução para o problema, inventar outra só cria duas formas de errar.

**É exatamente para isto que a trilha existe**: o defeito morreu num mundo descartável, e não numa
reclamação de jogador seis semanas depois.

### A trilha A2.S, e como cada uma das suas quatro regras é cumprida

1. **Reusa o domínio.** Chama `Ciclo::avancar()` e `Populacao::capacidade()` — as mesmas classes que
   o tick chamará. Um simulador que reescreve a fórmula diverge do jogo na primeira mudança e passa
   a mentir com aparência de autoridade.
2. **Parâmetros da mesma fonte.** Lê `population_settings`, nunca uma cópia digitada.
3. **Não deixa rastro.** Roda dentro de uma transação com `rollBack()` garantido no `finally`. Há
   teste que conta colônias e usuários antes e depois.
4. **Saída legível**: curva por dia, ponto de saturação, gargalo, e os parâmetros que a produziram.

E ele **se recusa a inventar a métrica-chave**: o percentual de população comprometida (§7.3) sai
como *"ainda não mensurável"* em vez de "0%", porque `building_operator_requirements` está vazia.
Zero e ausência de dado são a mesma imagem na tela e coisas opostas na realidade — a mesma regra do
painel de métricas (D-165).

### A rodada 1, registrada no BALANCEAMENTO

Teto atingido no dia 15, primeiro gargalo em **biomassa** no dia 19, eficiência estabilizando em
**73,2%** com três essenciais em falta permanente. Pelo §7.3 isso é a faixa de *frustração*, não a de
*decisão estratégica*: ou a produção de essenciais sobe, ou o consumo per capita cai.

**Nenhum número foi promovido.** Continuam todos PENDENTE, e a arbitragem é do usuário.

### O que a A2.2 ainda não entregou

A A2.2.5 (equipe vinculada à zona) tem a conta pronta em `Populacao::alocadaEmZonas()` mas **não é
cobrada** na ocupação — cobrar exigiria números arbitrados. E a A2.2.6 (grandfathering) tem a conta
em `necessariaParaOQueJaTem()`, com a folga do §6.7, mas **não há comando de migração ainda**: ele
só faz sentido depois de os requisitos de operador existirem, senão concederia zero a todo mundo.

### Verificação

998 testes verdes (14 novos). Migration exercitada num **MariaDB descartável** — a linha de
parâmetros nasce com `ativo = 0` e o `JSON_EXTRACT` do mapa de zonas responde corretamente sobre o
`longtext` com `CHECK (json_valid(...))` que o 10.5 usa no lugar de JSON nativo. Banco derrubado.

---

## D-168 — Pesquisa (A2.3): a estrutura entra, os números não

**Data:** 2026-07-31 · **Status:** fase A2.3 do `docs/alpha2/ROADMAP_ALPHA2.md` · **Backend**

Dá função real ao Laboratório, que o `Funcoes::CATALOGO` descreve como `'efeito' => 'nenhum'` com a
nota: *"o GDD diz duas palavras e nunca publica árvore de pesquisa, tecnologias, custo nem tempo"*.

Ou seja: **cada número desta fase seria invenção**. Por isso vale a mesma disciplina do D-167 —
`research_settings.ativo` nasce `false`, a árvore existe e é exercitável, e a promoção dos números é
arbitragem do usuário com evidência da trilha A2.S.

### Decisões que dão forma ao modelo

**Sem "Pontos de Pesquisa".** §8.2 é explícito: pesquisa consome recursos que já existem. Uma moeda
paralela criaria uma segunda economia para balancear, e a fase existe para dar escolha, não para
dobrar o trabalho.

**O Observatório fica fora, e o mecanismo de vagas nasce extensível.** Ele não existe no jogo, e
criá-lo exige decisão de slot, arte e especificação próprias (§7.2). O paralelismo sai do nível do
Laboratório — mas `Vagas::fontes()` devolve um **mapa de contribuições**, não um número. Acrescentar
o Observatório depois é acrescentar uma linha, não refazer o modelo. Há teste que guarda a forma.

**O teto de vagas não é decoração.** Sem ele, um Laboratório alto pesquisaria tudo em paralelo e a
árvore deixaria de ser escolha — viraria fila de espera. Vaga infinita mata o ponto da fase mais
depressa do que qualquer custo errado.

### Os efeitos reusam o vocabulário do `EfeitosDaEndurance`

Mesmas chaves (`producao_bonus`, `desconto_tributo`, `velocidade_veiculo`…), mesmos alvos, **mesmos
tetos agregados**. Um vocabulário paralelo seria o erro fácil e caro: duas fontes de bônus para a
mesma coisa, com regras diferentes, e o teto de uma sem conhecer a outra — um colono com peça da
Endurance e tecnologia pesquisada estouraria qualquer limite, e o sintoma apareceria como "produção
estranha" meses depois.

⚠️ **Ressalva anotada no código:** o teto é aplicado **por fonte**. A soma das duas ainda pode passar
do limite individual; um teto conjunto exigiria somá-las antes de limitar, no consumidor. Está
escrito porque é o tipo de coisa que se descobre tarde.

### Três regras que impedem burla

- **O nível sobe na conclusão, não no início.** Se subisse ao iniciar, valeria a pena começar tudo e
  nunca terminar nada.
- **Pesquisa em andamento não dá efeito nenhum.** Parece óbvio, e a implementação ingênua (somar o
  que a colônia tem em `colony_technologies`) faria exatamente o contrário.
- **O efeito é o do nível atual**, não a soma da escada: nível 3 dá 3× o valor por nível, não
  1+2+3. Mesma regra do requisito de operador na A2.2.

### O catálogo: uma tecnologia por trilha, e o porquê

Oito trilhas, oito tecnologias. Cadastrar quarenta seria inventar quarenta conjuntos de números, e o
§8.3 diz o que decide se a árvore presta: *"se a maioria dos jogadores pesquisar a mesma sequência,
a árvore falhou"*. Isso se responde com simulação e escolha, não com volume. O que entra é o
primeiro degrau de cada trilha — bastante para a bifurcação existir, pouco para ninguém confundir
com desenho fechado.

### O tipo novo de ledger, e a trava que funcionou

`custo_pesquisa` entrou em `Ledger::TIPOS`. O teste do D-163 — *todo tipo do ledger tem direção
declarada* — **cobrou a classificação em `DirecaoDoLedger` na mesma hora**. Era exatamente para isso
que ele existia: um tipo novo não entra mudo na telemetria.

### Verificação

1016 testes verdes (18 novos). Migration e seeder exercitados num **MariaDB descartável**: a FK
auto-referente da árvore nasce com `ON DELETE SET NULL`, os dois campos json viram `longtext` com
`CHECK (json_valid(...))`, e `research_settings.ativo` nasce `0`. Banco derrubado.

### O que a A2.3 ainda não tem

Não há **API nem tela** — nenhuma rota de jogador foi criada, e o motor só é alcançável pelo
domínio. Nem há aba de painel para o operador cadastrar tecnologia. É deliberado: com `ativo = false`
e números de palpite, uma tela seria convite a mexer no que não está decidido. Entram quando os
números entrarem.

O critério de saída da fase — *"dois jogadores com tempo semelhante podem desenvolver colônias
significativamente diferentes"* — **não pode ser declarado cumprido**: depende de a árvore ter
tamanho e de os números terem sido arbitrados. A estrutura permite; o conteúdo ainda não prova.

---

## D-169 — A trilha A2.S estendida à pesquisa, e a árvore reprova no próprio critério

**Data:** 2026-07-31 · **Status:** segunda entrega da trilha A2.S (A2.3) · **Backend, ferramenta**

`fertways:simular-pesquisa` responde à pergunta que o §8.3 usa como critério de fracasso: *"se a
maioria dos jogadores pesquisar a mesma sequência, a árvore falhou"*.

### Como ele responde, sem inventar número

Monta cinco **arquétipos de colônia** — perfis de construção diferentes — e faz cada um escolher
gulosamente a tecnologia de melhor retorno. Os insumos da conta são reais:

- benefício de `building_specs.producao_hora_json` × `resource_types.preco_base_micro` × o bps da
  tecnologia;
- custo do `custo_json`, convertido pelo mesmo preço base;
- e **a disponibilidade sai do `Pesquisar` de verdade** — ele tenta iniciar dentro de um savepoint e
  desfaz. Regra 1 da trilha: reusa o domínio, não o reimplementa. Reescrever as portas aqui faria a
  cópia divergir do jogo na primeira mudança.

### ⚠️ O resultado: a árvore falha

**Os cinco arquétipos escolheram a mesma sequência** — `tec_biosfera_1 → tec_territorio_1 →
tec_energia_1`. Primeira escolha idêntica em 5/5, uma sequência distinta de cinco.

E o comando diz **por quê**, porque veredito sem causa é difícil de agir: a escolha não está sendo
decidida pelo que a tecnologia faz, e sim por **qual recurso ela pede**.
`componentes_eletronicos` custa 1.277.800 micro contra 8.300 da biomassa — **154 vezes mais**.
Qualquer tecnologia que os exija sai do páreo antes de o efeito entrar na conta. A razão entre a mais
barata e a mais cara é de **34×**.

### Um erro meu que o simulador expôs na primeira rodada

`tec_ciencia_1` e `tec_defesa_1` dão `producao_bonus` ao **Laboratório** e à **Torre de Defesa**, que
não produzem recurso nenhum. O bônus é matematicamente zero. É defeito do catálogo que eu semeei no
D-168, e não do balanceamento — e apareceu porque a ferramenta calcula retorno de verdade em vez de
confiar na aparência da tabela.

### A trava que este comando exigiu

Ele **liga a pesquisa** dentro do mundo descartável, porque precisa exercitar o `Pesquisar` real. Se
o rollback falhasse, a produção acordaria com a pesquisa **aberta** e números de palpite valendo — o
pior estrago possível desta fase. Há teste específico para isso, e não só para "não sobrou colônia".

### O que este comando NÃO mede, dito no próprio relatório

Só `producao_bonus`. Desconto de tributo, velocidade e capacidade de veículo dependem de volume de
comércio e de logística, que o recorte não modela — e chutar um volume seria inventar justamente o
número que decide a resposta. As trilhas de Comércio e Logística saem como *"não medível"*, e o
relatório diz que **não é por serem ruins**.

Duas coisas mais são modelo, não verdade do jogo, e estão escritas no docblock: **os arquétipos são
invenção minha**, e **a função de valor é uma tese sobre o jogador** (ele maximiza retorno por Fert$,
penalizado pelo tempo). Um jogador real pode pesquisar defesa por medo, não por payback.

### O que fica para arbitragem

Nenhum número foi promovido. As saídas, e a escolha é do usuário:

1. **achatar a dispersão de custo** — enquanto a razão for 34×, há uma ordem de preço, não uma
   escolha;
2. **dar efeitos mensuráveis a Ciência e Defesa**, hoje inertes por construção;
3. **modelar comércio e logística** para aquelas trilhas entrarem no páreo.

### Verificação

1018 testes verdes (2 novos). Rodada registrada em `BALANCEAMENTO.md` §8.1, com a tabela de custo e
retorno. Conferido por leitura que produção e dev seguem com `research_settings.ativo = 0` depois de
várias execuções manuais — o rollback aguenta.

---

## D-170 — As três correções da árvore de pesquisa, e o que o achatamento de custo provou

**Data:** 2026-07-31 · **Status:** rodada 3 da trilha A2.S · **Backend**

Aplicadas as três recomendações que o D-169 deixou na mesa. A primeira resolveu o problema sozinha.

### 1. Custos achatados: a dominância caiu

Todas as oito tecnologias passaram a custar **~10 Fert$** pelo preço base. Dispersão de **34× para
1,12×**.

| | antes | depois |
|---|---|---|
| Primeira escolha idêntica | 5/5 (100%) | **3/5 (60%)** |
| Sequências distintas | 1 de 5 | **4 de 5** |

E cada arquétipo passou a especializar-se no que a sua própria colônia produz: a agrícola escolhe
Biosfera, a mineradora escolhe Território, a energética escolhe Energia.

**O ponto que importa: os efeitos não mudaram entre as duas rodadas.** Enquanto a razão de preço era
de 34×, ela decidia tudo antes de o efeito entrar na conta. Era o custo que estava jogando o jogo.

### 2. Os dois efeitos inertes, corrigidos

O D-169 achou que Ciência e Defesa davam `producao_bonus` a prédios que **não produzem recurso
nenhum** — bônus matematicamente zero, duas trilhas inertes por construção.

- **Ciência** passou a `duracao_pesquisa`: encurta as pesquisas seguintes. É o efeito natural da
  trilha — ela não produz recurso, produz conhecimento mais rápido. Tem consumidor de verdade: o
  `Pesquisar` aplica **no início**, e não na conclusão, porque prazo prometido não pode encurtar no
  meio do caminho, ainda que a surpresa fosse boa. Teto baixo (4000 bps): pesquisa quase instantânea
  destruiria o custo de oportunidade que a fase existe para criar.
- **Defesa** passou a `defesa_bonus`, **declarado e sem consumidor**. Fortalecer a Torre vive no
  motor de combate (§27), superfície grande que não pertence a esta fase. Fica inerte de propósito,
  com o precedente do D-67/D-79 — seis estruturas de zona que "erguem-se, custam, não fazem nada até
  o sistema de que dependem existir". A alternativa seria dar à Defesa um efeito que ela não tem só
  para o número não ficar feio: mentira com aparência de funcionalidade.

O vocabulário novo vive em `Domain\Pesquisa\Efeitos`, e **não** no `EfeitosDaEndurance` — a Endurance
não tem por que saber de duração de pesquisa. `Efeitos::tetoBps()` resolve os dois vocabulários, para
o consumidor não precisar saber de qual deles veio o tipo.

### 3. ⚠️ Achado novo: a trilha de Indústria está 14× pior

Com o custo achatado, o retorno ficou legível — e um número saltou:

| tecnologia | melhor retorno |
|---|---|
| `tec_energia_1` | 188 h |
| `tec_biosfera_1` / `tec_territorio_1` | 204 h |
| **`tec_industria_1`** | **2.815 h** |

A causa é a base, não o bônus: a **Refinaria Química produz 7 compostos_quimicos/h no nível 4**,
contra 150 energia/h do Reator. Percentual sobre base pequena é ganho pequeno. **Nenhum arquétipo
escolhe Indústria — nem o industrial.**

**Não corrigi**, e a razão é a regra de ouro: consertar isso é subir o bps da tecnologia (arbitragem)
ou mexer na produção da Refinaria (**número do GDD**). Fica para o usuário.

### O relatório passou a distinguir TRÊS ausências

Confundi-las seria o erro que a fase inteira tenta evitar:

- **"sem consumidor"** — o efeito não faz nada no jogo hoje. É defeito a corrigir.
- **"sem volume modelado"** — o efeito faz, mas o recorte não sabe medir. É limitação da ferramenta.
- **"outra unidade"** — o caso da Ciência, cujo benefício é tempo e não Fert$/hora. Compará-los na
  mesma coluna produziria um número plausível e errado.

### O que continua de fora

Comércio e Logística. Modelá-las exige um volume de comércio e de logística por hora, e **chutar esse
volume seria inventar exatamente o número que decide a resposta**. Entram quando houver telemetria
real de comércio — a A2.0 já a coleta, faltam dias de jogo — ou quando o usuário arbitrar um volume
de referência.

### Verificação

1018 testes verdes. Rodada 3 registrada em `BALANCEAMENTO.md` §8.1 com as duas tabelas. O teste do
vocabulário passou a usar `Efeitos::conhecido()`, que resolve os dois conjuntos — sem isso ele
reprovaria as duas chaves novas, e reprovar seria o comportamento certo se elas não tivessem sido
declaradas.

---

## D-171 — A trilha de Indústria apontava para o pior produtor do jogo

**Data:** 2026-07-31 · **Status:** rodada 4 da trilha A2.S · **Backend, catálogo**

O D-170 achou que `tec_industria_1` tinha retorno de 2.815 h contra ~200 h das outras, e deixou o
conserto para arbitragem. Feito — e a arbitragem certa não era a que eu tinha imaginado.

### A causa não era o bônus, era o alvo

Medi o valor **bruto** de produção de cada prédio no nível 4 (produção/hora × preço base do recurso).
O jogo se revela bem equilibrado: **sete produtores entre 1,67 e 2,35 Fert$/h**. Dois fora da curva:

- **Oficina: 65,17 Fert$/h** — 38× o grupo;
- **Refinaria Química: 0,12 Fert$/h** — 14× abaixo.

A `tec_industria_1` apontava para a Refinaria: o **pior produtor do jogo inteiro**. Percentual sobre
base pequena é ganho pequeno, e nenhum bps razoável consertaria — seriam precisos ~4200 bps (42%)
contra os 3% das demais tecnologias, o que seria compensar o sintoma e esconder a causa.

Reapontada para a **Indústria Siderúrgica**: 1,70 Fert$/h, no meio exato do grupo, e tematicamente a
mesma trilha.

### A árvore passa no §8.3

| | rodada 2 | rodada 3 | rodada 4 |
|---|---|---|---|
| Primeira escolha idêntica | 5/5 (100%) | 3/5 (60%) | **2/5 (40%)** |
| Sequências distintas | 1 de 5 | 4 de 5 | **5 de 5** |

Cada perfil escolhe uma sequência própria, e as quatro tecnologias mensuráveis agrupam-se entre
**188 e 204 h** — parelhas o bastante para o perfil da colônia decidir, e não o preço.

O critério de saída da A2.3 — *"dois jogadores com tempo semelhante podem desenvolver colônias
significativamente diferentes por escolhas tecnológicas"* — passa a ter **evidência a favor**, na
parte da árvore que é mensurável.

### ⚠️ Dois avisos sobre a medição, que não podem virar folclore

1. **É valor BRUTO.** Prédios que transformam consomem insumos que a conta ignora. Os 65,17 Fert$/h
   da Oficina são receita, não lucro; e os 0,12 da Refinaria são ainda piores do que parecem, porque
   ela também consome.
2. **A Oficina está 38× acima do grupo.** Pode estar certo — componentes valem 1,28 Fert$ e
   fabricá-los custa insumo — ou pode ser desequilíbrio real da economia. **Não investiguei**: está
   fora da A2.3 e envolve números do GDD. Fica anotado para quem for olhar a economia de perto.

### Verificação

1018 testes verdes. Rodada 4 registrada em `BALANCEAMENTO.md` §8.1, com a tabela de valor por prédio
e a evolução das quatro rodadas.

---

## D-172 — Especialização (A2.4): o perfil é calculado e exibido — e o critério de saída NÃO passa

**Data:** 2026-07-31 · **Status:** fase A2.4 + terceira entrega da trilha A2.S · **Backend e frontend**

### ⚠️ Primeiro: uma correção do D-171

O D-171 reapontou a tecnologia de Indústria da Refinaria para a **Indústria Siderúrgica**, e
justificou dizendo que ela produz **1,70 Fert$/h, "no meio exato do grupo"**.

**Aquele número estava errado.** Ele é a taxa de **entrada**, não de saída. O comentário do
`ColonyTick` diz com todas as letras: *"o JSON reaproveita a chave `metal_bruto` da Mina, mas aqui é
o que ela PROCESSA por hora, não o que produz (D-82)"*. Eu li como produção.

A saída real está em `Siderurgica::SAIDAS`: a cada 1000 de Metal Bruto, **350 Ligas Metálicas e
cinco minerais eletrônicos**. Medida a preço base, no nível 4:

    saída bruta: 0,378 Fert$/h  ·  insumo: 1,698 Fert$/h  ·  LÍQUIDO: −1,321 Fert$/h

**A Siderúrgica destrói valor a preço base.** O valor dela não é aritmético, é de **soberania**: é o
único caminho de uma colônia obter minerais eletrônicos sem comprar do Governo.

**A decisão do D-171 continua de pé, por outro motivo.** O bônus de produção na Siderúrgica aumenta a
taxa de processamento — logo, aumenta ligas *e* minerais. É efeito real e estrategicamente valioso.
Mas a justificativa publicada estava errada, e fica corrigida aqui.

### E uma afirmação falsa que quase publiquei

Escrevi, no primeiro rascunho do simulador de especialização, que **"nenhum prédio do jogo produz
mineral eletrônico"**. É mentira, e pela mesma raiz: a Siderúrgica produz cinco dos oito, e some da
contagem quando se lê `producao_hora_json` ingenuamente.

A lição, que vale além destas duas fases: **`building_specs.producao_hora_json` não significa a mesma
coisa para todo prédio.** Para a Mina é produção; para a Siderúrgica é consumo. A verdade dos que
convertem mora no domínio (`Siderurgica::SAIDAS`, as receitas, os ramos por tipo do `ColonyTick`), e
não na tabela gerada do GDD.

### O que a A2.4 entregou

**A auditoria que a fase pedia tem resposta curta**: a especialização "já existente" são as cinco
construções **repetíveis**. `Building::REPETIVEIS` já dizia, desde o D-59, que "repetir é estratégia
econômica (especializar a colônia em metal, em química) e não truque". O mecanismo existia,
decidia economia, e **nunca tinha sido lido nem mostrado a ninguém**.

**O perfil é calculado, nunca declarado** (§8.1). `Domain\Especializacao\Perfil` deriva vocação,
força e dependências do que a colônia construiu e pesquisou. **Só há rota GET, e nunca haverá POST** —
há teste que verifica isso, porque um endpoint de escrita seria a segunda camada que o §8.1 existe
para impedir, com respec, custo de troca e a troca oportunista na véspera de cada evento.

**E é exibido**, que é a contrapartida obrigatória daquela regra. `VocacaoDaColonia.tsx`, na tela de
Perfil, mostra os dois lados: o que a colônia produz **e do que passa a depender**. Sem a segunda
lista, a tela diria ao colono que ele é bom em metal sem lhe dizer que, por isso, precisa de alguém
que faça biomassa.

**A vocação sai do VALOR, não da quantidade.** O Reator faz 506 energia/h e a Mina 51 metal/h — uma
colônia não é "energética" por produzir muitos números.

### ⚠️ O critério de saída NÃO passa

`fertways:simular-especializacao` verifica o critério reescrito: *"para cada especialização, ao menos
uma cadeia de que ela depende não é suprível por produção própria"*.

| especialização | vocação | força | depende (ESTRUTURAL) |
|---|---|---|---|
| metalúrgica | metal_bruto | 72% | **—** |
| química | agua | 44% | **—** |
| eletrônica | componentes_eletronicos | 99% | silicio |
| energética | energia | 45% | **—** |
| agrícola | biomassa | 60% | **—** |

**1 de 5.** Só a eletrônica depende de algo que nenhum prédio produz (silício). As outras quatro se
bastam: tudo o que consomem, poderiam produzir gastando slots.

O relatório distingue **dependência estrutural** (o que nenhum nível de investimento resolve) de
**dependência escolhida** (o que se poderia produzir e se preferiu comprar). Só a primeira cumpre o
critério, e por isso **a fase não pode ser declarada concluída**.

O que faltaria, e é arbitragem do usuário: ou as cadeias essenciais deixam de ser todas
auto-supríveis — o que provavelmente passa pela escassez de slots ou pela população da A2.2 —, ou o
critério de saída precisa ser revisto.

### Verificação

1029 testes verdes (11 novos). Nenhum número foi promovido; a pesquisa segue desligada.

---

## D-173 — As duas métricas mais valiosas da fase A2.0 gravavam no vazio

**Data:** 2026-07-31 · **Status:** correção do D-165, achada em produção · **Backend**

### O sintoma, e como ele apareceu

Ao verificar o deploy da A2.4, o `laravel.log` de produção mostrou uma exceção às 22:55:04 —
`DomainRuleException: Falta energia para esta viagem`. Regra de jogo, não falha de sistema, e já
conhecida como recorrente.

Fui conferir se a telemetria a tinha registrado. **`telemetry_events` não tinha a linha** — e o log
não tinha nenhum aviso `telemetria:`, o que provava que a chamada acontecera e o INSERT sumira
depois. Não era falha do registrador; era o registro desaparecendo.

### A causa

`falta_de_energia` e `falta_de_insumo` são registrados **logo antes de um `throw`**, e esse throw
está **dentro de um `DB::transaction`** — `DespacharVeiculo::debitarEstoque()` (transação na linha
106) e `EnqueueUpgrade::debitarRecursos()` (transação na linha 38). A exceção sobe, a transação
reverte, e leva o evento junto.

**As duas métricas que o D-165 chamou de "a mais valiosa da fase — é onde o jogo trava sem avisar
ninguém" nunca registraram nada.** Sem erro, sem aviso, sem sintoma. O painel de métricas as
listava como lacuna resolvida, e não estavam.

### A correção, e dois erros meus no caminho

**Primeira tentativa: detectar transação automaticamente** (`DB::transactionLevel() > 0`). Não
serve — o `RefreshDatabase` envolve cada teste numa transação, e o detector diria "estou em
transação" o tempo todo, adiando tudo. Quem sabe que está num caminho de falha é o **chamador**, não
o framework. Virou `adiar: true`, explícito nos dois sites, com comentário dizendo por quê.

**Segunda tentativa: buffer em estado estático.** O teste passava sozinho e reprovava na suíte —
estático sobrevive entre testes do mesmo processo, e um teste passou a depender do que o anterior
deixou. **Passar sozinho e falhar em conjunto é o pior modo de falhar**, porque convida a culpar o
teste. Virou estado de instância com `RegistrarEvento` registrado como **singleton**: o contêiner é
recriado a cada teste e o buffer morre junto, sem ninguém precisar lembrar de limpá-lo.

### O que fica guardado

Teste de regressão que registra um evento dentro de uma transação que reverte e exige que ele
sobreviva. Ele documenta a origem — foi achado em produção, não em teste — para ninguém o "limpar"
por parecer artificial.

### A lição que passa das duas fases

**Instrumentar o caminho de falha exige cuidado que o caminho de sucesso não exige.** O evento de
sucesso é escrito e comitado junto com o fato que descreve; o evento de falha é escrito e revertido
junto com a falha que descreve. São simétricos no código e opostos no efeito.

E: **um buraco de telemetria não tem sintoma.** Zero eventos parece "ninguém bateu na parede", que é
exatamente a leitura que o D-165 tentou impedir no painel — e que a própria telemetria produziu por
outro caminho.

### Verificação

1031 testes verdes (2 novos).

---

## D-174 — Federação (A2.5, primeira fatia): o teto antimonopólio deixa de bloquear sem avisar

**Data:** 2026-07-31 · **Status:** fase A2.5, itens 5 e 6 do trabalho · **Backend e frontend**

### A auditoria primeiro, porque metade da fase já estava feita

Os itens 1 a 4 do trabalho da A2.5 **já existiam** (D-114 a D-121):

- **12 membros**: `Federation::MAX_COLONIAS = 12`, com o comentário certo — *"regra de jogo, não
  parâmetro que o operador configure"*. Nada a revisar, e **nada a migrar**, ao contrário do que o
  roadmap supunha.
- **Papéis, fundo, extrato, missões cooperativas, território**: nove serviços de domínio já cobrem
  cargo, convite, pedido, saque, expulsão e transferência de liderança.

⚠️ E um achado de vocabulário: **"Diplomata" é um cargo, não um sistema.** O item 7 do trabalho
("preparar interface diplomática") pede algo que **não existe em nenhuma forma** — tratado, aliança
ou guerra entre federações. É sistema novo, com decisões de desenho próprias, e **não entrou nesta
fatia**.

### O defeito real: uma proteção que ninguém vê chegando

O limite antimonopólio territorial existe desde o D-119 e funciona — `OcuparZonaNeutra` recusa
quando a federação já tem 20% de todas as zonas do jogo. Mas ele **bloqueia sem avisar**: o colono
descobre o teto no instante em que bate nele, **depois de já ter levado tropa e material até a
zona**.

O roadmap nomeia isso com precisão ao pedir *"proteções antimonopólio **observáveis**"*. A proteção
já era; o que faltava era poder vê-la chegando.

### "Quantas ainda cabem" é o número que importa, e não é regra de três

Percentual sozinho não ajuda a decidir: saber que se está em 17% não diz se vale mandar uma
expedição. **"Cabem mais 2"** diz.

E essa conta tem uma sutileza: **cada zona que a federação ocupa também aumenta o total de zonas
ocupadas do jogo** — o denominador cresce junto. Uma regra de três simples daria um número errado, e
errado **para menos**, o que faria a tela assustar sem motivo. Por isso o cálculo é iterativo,
usando a mesma expressão do domínio.

### ⚠️ Uma conta só, em dois lugares, e um teste amarrando

`Concentracao` usa **exatamente** a expressão de `OcuparZonaNeutra::conferirTetoDaFederacao()`,
inclusive o `intdiv`. Duas contas para o mesmo limite divergiriam no primeiro ajuste, e a tela
passaria a dizer "você pode" enquanto o domínio diz "você não pode" — **o pior tipo de discordância,
porque a tela é quem o jogador acredita**.

Há teste que chama o método privado do domínio por reflexão e exige que os dois concordem. Ele
chama a regra real de propósito: comparar com uma cópia da fórmula não provaria nada.

### Um erro meu no teste, que vale registrar

A primeira versão criava duas federações no mesmo teste — uma no teto e outra folgada — e falhava.
**As duas vivem no mesmo mundo**: as 21 zonas da segunda entravam no denominador e tiravam a
primeira do teto. O cenário é que estava errado, não o código. Separado em dois testes, cada um com
o seu mundo.

### Verificação

1041 testes verdes (10 novos).

### O que a A2.5 ainda não tem

- **Interface diplomática** (item 7): sistema novo, sem nada no jogo hoje. Tratado, aliança e guerra
  entre federações precisam de decisão de desenho antes de código.
- **Objetivos federativos** (item 4) existem como missões cooperativas (D-120), mas não foram
  revisados contra o que a fase quer dizer por "objetivo".
- O **critério de saída** — *"uma Federação organizada oferece capacidade estratégica que um conjunto
  de jogadores independentes não possui"* — **não pode ser declarado cumprido** com esta fatia: ela
  torna uma proteção legível, o que é correção de usabilidade, não capacidade estratégica nova.

---

## D-175 — Upgrade de veículo (A2.7): o nível existia sem caminho para subir

**Data:** 2026-07-31 · **Status:** fase A2.7, itens 1, 2, 3 e 5 do trabalho · **Backend**

`vehicles.level` existe no banco desde sempre e **nunca teve rota para subir**. O roadmap diz
exatamente isso — *"o nível existe sem caminho para subir. É isso que esta fase fecha."*

### Um eixo, com contrapartida

Capacidade sobe **e manutenção sobe junto**, e a manutenção sobe mais (2000 bps por nível contra
1500 de capacidade). É o que transforma o upgrade em **escolha econômica** em vez de aumento
nominal — que é, literalmente, o critério de saída da fase. Um veículo grande parado custa caro;
quem roda pouco não deve querer subir.

Há teste que exige `manutencao_bps > capacidade_bps`: se um dia alguém inverter os dois, a fase
perde o sentido em silêncio.

### ⚠️ Velocidade não entra, e há teste guardando

Velocidade é **traço do tipo** — é o que diferencia Furgão de Caminhão. Se o nível acelerasse, a
**distância** encolheria a cada upgrade, e distância é pilar declarado do jogo ("logística sem
teleporte").

O teste observa a velocidade pelo **tempo do trecho**, que é como o jogo a usa: dez slots levam o
mesmo tempo antes e depois do upgrade. Ele existe porque é o tipo de coisa que alguém acrescenta
depois achando que melhora.

### Decisões menores que valem registro

- **A capacidade é reescrita a partir da base do tipo**, não incrementada sobre a atual. Incrementar
  acumularia erro de arredondamento a cada nível e — pior — ficaria errado para sempre se alguém
  ajustasse o parâmetro depois: a coluna guardaria o resultado de uma curva que não existe mais.
- **O custo multiplica pelo nível alvo.** Sem isso, o último nível sairia pelo preço do primeiro.
- **Só veículo no pátio.** Um veículo em rota tem carga calculada com a capacidade atual; mudá-la no
  meio da viagem faria a carga não caber no próprio veículo que a transporta.
- **Os parâmetros foram para `transport_settings`**, e não para tabela nova: a casa já tem uma linha
  única de parâmetros de transporte, e espalhar o balanceamento em dois lugares faria quem for
  ajustar ter de lembrar de olhar os dois.

### A trava do D-163 cobrou de novo

`upgrade_veiculo` entrou em `Ledger::TIPOS`, e o teste *"todo tipo do ledger tem direção declarada"*
exigiu a classificação em `DirecaoDoLedger` na mesma hora. Segunda vez que ela pega um tipo novo —
está fazendo exatamente o trabalho para o qual foi escrita.

### ⚠️ O que a A2.7 ainda não tem

- **O teto de estoque que trava** (item 4). A classe `Silo` existe desde o D-107 e **ninguém a usa** —
  o próprio docblock admite: *"isto é só a regra e o dado"*. Fazer o teto travar a produção é
  mudança de comportamento num mundo vivo, e merece tratamento próprio.
- **A simulação de impacto econômico** (item 6). Todos os números acima são HIPÓTESE.

Portanto o critério de saída — *"upgrade apresenta escolha econômica mensurável"* — tem a
**estrutura** para ser verdade, mas **mensurável** exige a rodada do simulador que ainda não houve.

### Verificação

1053 testes verdes (12 novos). Migration exercitada num MariaDB descartável.

---

## D-176 — O primeiro parâmetro de população escolhido com evidência

**Data:** 2026-07-31 · **Status:** rodada 5 da trilha A2.S, item A2.2.4 · **Backend**

### O que estava travando a arbitragem

As rodadas 1 a 4 do simulador não conseguiam medir a **métrica-chave do §7.3** — o percentual de
população comprometida em operação. `building_operator_requirements` estava vazia, então o comando
dizia honestamente *"não mensurável"* em vez de imprimir 0%.

Era um impasse circular: o número não podia ser escolhido sem medida, e a medida não existia sem o
número.

### O que destravou

Duas coisas no simulador:

- **sobreposição de parâmetros por rodada**, gravada **dentro** da transação que é revertida. É o
  que permite comparar seis configurações sem que nenhuma deixe rastro — comparar mexendo no
  `population_settings` de verdade deixaria o banco num estado que ninguém escolheu;
- **um mundo com prédios produtores**, porque requisito de operador precisa de algo em que incidir.

### A varredura, e a escolha

| operadores/nível | capacidade base | §7.3 comprometida | faixa |
|---|---|---|---|
| **1** | **10** | **52%** | **decisão estratégica** |
| 1 | 20 | 26% | população quase irrelevante |
| 2 | 10 | 104% | déficit — nem opera o que construiu |
| 2 | 20 | 52% | decisão estratégica |
| 3 | 10 | 156% | frustração |
| 3 | 20 | 78% | apertada |

Duas caem na faixa certa e são **a mesma razão em escalas diferentes**. A escolha entre elas foi de
**legibilidade**: *"uma Fazenda nível 3 pede 3 operadores"* é uma frase que se entende; *"pede 6"*, já
é planilha. E o §7.4 pede literalmente "poucos humanos operam muitos robôs".

**Adotado: 1 operador por nível de construção produtora**, semeado por
`BuildingOperatorRequirementSeeder`. Esparso de propósito — só quem produz exige alguém; a Antena não
pede ninguém.

### ⚠️ O que esta rodada NÃO decidiu, e é importante não confundir

- **Consumo per capita e taxa de crescimento não foram varridos.** Nesta configuração, **nenhum
  essencial faltou em 60 dias**: a pressão populacional vem do teto habitacional e dos operadores,
  não da fome. Isso é provavelmente o desenho certo — fome por omissão seria hostil —, mas é
  consequência da produção que escolhi para a rodada, **não uma propriedade do modelo**.
- **Um perfil de colônia só.** Uma colônia sem Reator, ou com dez Minas, daria outro número.
- **`population_settings.ativo` continua `false`.** Uma rodada de simulação é evidência, não campo.
  Virar a chave num mundo sem reset é decisão do usuário, e continua na mesa dele.

### O que mudou de verdade

Antes havia um balde vazio. Agora há **um número com uma razão escrita atrás dele**, e uma
ferramenta que permite contestá-lo em trinta segundos: rodar o simulador com outros valores e ver a
faixa mudar.

### Verificação

1053 testes verdes. Rodada 5 registrada em `BALANCEAMENTO.md` §7.1 com a tabela das seis
configurações e o critério da escolha.

---

## D-177 — População é mão de obra, não bocas a alimentar

**Data:** 2026-07-31 · **Status:** rodadas 6 e 7 da trilha A2.S · **Backend, decisão de desenho**

Pedido para responder eu mesmo às duas perguntas em aberto. Declarei a posição de desenho **antes**
de medir, para não escolher números e chamar de resposta.

### As duas perguntas, reformuladas

*"Quanto tempo uma colônia abandonada aguenta?"* — com a produção rodando, ela não definha. A
pergunta útil por trás é **quanto da produção de essenciais a população come**: se come 90%, não
sobra para construir; se come 5%, é enfeite.

*"Quão rápido se recupera de uma escassez?"* — se for horas, escassez não tem consequência; se for
semanas, um erro estraga um mês, e num jogo sem reset isso é hostil.

### ⚠️ A tensão que a medição revelou

Os dois alvos **brigam**. Subir o teto habitacional faz o consumo importar, mas **dilui os
operadores** — o requisito é fixo por construção, então mais gente dá fração comprometida menor.

Só uma combinação atinge ambos: capacidade 40 + 4 operadores por nível + consumo triplicado, que dá
31% de consumo e 52% de comprometimento. E custa uma **Fazenda nível 3 exigindo 12 operadores**,
numa colônia de 108 pessoas.

### A decisão, e as três razões

**Recusada.** População no FERTWAYS é **restrição de mão de obra**, não economia de comida:

1. O §7.4 diz literalmente *"poucos humanos operam muitos robôs"*. Doze operadores para uma fazenda
   não é poucos humanos.
2. **Consumo per capita duplicaria o que a energia já faz.** Toda construção já consome energia por
   hora; um segundo dreno per capita sobre os mesmos essenciais faz dois sistemas com o mesmo
   trabalho, e o jogador não consegue dizer qual o está apertando.
3. O §7.2 proíbe *"virar 'The Sims' dentro de Fertways"*. População como bocas é essa direção;
   população como restrição de trabalho é a de estratégia. E a **métrica que o próprio documento
   chama de chave (§7.3) é comprometimento** — que é trabalho, não comida.

Consumo per capita **fica onde está**: ~3% da produção. Tempero que aparece no ledger e não decide
nada. É escolha, não omissão.

### Crescimento: 50 → 70 bps/hora

Repovoar de metade do teto até o teto passa de 5,6 para **4,0 dias**.

Escolhido 70 e não 100 (2,8 dias) porque é **o valor mais lento da faixa aceitável**, e a assimetria
importa: rápido demais torna a escassez inconsequente, e **falha invisível é pior do que falha
reclamada**. Jogador reclama de recuperação lenta — e aí se ajusta o parâmetro, que é para isso que
a tabela existe. Ninguém reclama de um mecanismo que deixou de significar alguma coisa; ele só
apodrece.

### A ferramenta discordou de mim, e eu não a fiz concordar

Com o consumo em 3%, o simulador marcava *"população quase de graça — enfeite"* — a heurística
reprovando a decisão. **Não mudei a heurística para aprovar.** Troquei o rótulo por *"tempero, não
economia — decisão do D-177"*: o número continua sendo medido e mostrado, e quem quiser reverter a
decisão vê exatamente o que está mudando. Fazer a ferramenta concordar comigo seria transformá-la em
espelho.

### A configuração de referência

    ativo=false · capacidade_base=10 · crescimento=70 bps/h
    consumo 100/120/80/60 milésimos · 1 operador por nível produtor

    §7.3 comprometida: 52%  ·  recuperação: 4,0 dias  ·  consumo: 3% da produção

⚠️ Tudo continua **HIPÓTESE** e `population_settings.ativo` continua **false**. Virar a chave num
mundo sem reset é decisão do usuário.

### Verificação

1053 testes verdes. Rodadas 6 e 7 registradas em `BALANCEAMENTO.md` §7.1, com a tabela da tensão
entre os dois alvos e a tabela de recuperação.

---

## D-178 — A chave da população era decorativa, e agora liga alguma coisa

**Data:** 2026-07-31 · **Status:** A2.2 concluída (2.2.6 incluída) · **Backend**

Pedido para ligar a população. Fui conferir antes, e achei dois impedimentos que teriam feito da
ativação uma mentira ou um estrago.

### ⚠️ Impedimento 1: a chave não estava ligada em nada

`population_settings.ativo` não era lido por **ninguém** — `ColonyTick` não sabia que população
existia. Virá-la seria um **no-op**, e eu teria dito que a população estava no ar enquanto nada
mudava. O D-167 chamou o modelo de "inerte", e ele era inerte de verdade: nem o interruptor estava
conectado.

### ⚠️ Impedimento 2: população zero nunca cresce

As 29 colônias de produção têm `populacao = 0`, e `Ciclo::avancar()` devolve cedo quando o total é
zero — **por construção**: não há de quem nascer ninguém. Ligar a chave sem povoar deixaria 29
colônias em déficit permanente, sem caminho de saída. Não é hipótese: é o que aconteceria.

O grandfathering da A2.2.6 estava previsto e nunca fora implementado. Eu mesmo anotei isso no D-167,
e a anotação salvou a operação.

### A conferência que o §7.1 exige, feita antes

O `BALANCEAMENTO.md` §7.1 avisa que uma `capacidade_base` baixa demais faria veteranas nascerem
**acima do próprio teto habitacional**. Conferido por leitura contra a produção: as 29 colônias têm
Estrutura de Sobrevivência erguida e **nenhuma fica acima**. O `min()` está no comando de qualquer
forma, porque o aviso volta a valer se alguém baixar o parâmetro depois.

### O grandfathering (§6.7)

Cada colônia recebe população para operar **tudo o que já construiu**, mais a folga de 20%, limitada
pelo teto habitacional. Duas decisões pequenas com razão:

- **Piso de 1.** Quem não exige operador nenhum ainda recebe um colono: zero nunca cresceria, e a
  colônia ficaria congelada para sempre — punida por não ter construído nada, que é o oposto do que
  a regra quer.
- **Não reescreve quem já tem.** O comando é repetível sem estrago.

### ⚠️ Ativação em DUAS etapas, e a segunda não é esta

**O que passa a acontecer:** a população cresce até o teto e consome água, oxigênio, biomassa e
energia — cerca de **3% da produção**, por decisão do D-177.

**O que NÃO passa a acontecer:** a penalidade de eficiência por escassez **não é aplicada**, e nada é
bloqueado por falta de operadores. Essas são as travas da A2.6.

Ligar as duas coisas juntas seria mudar dois comportamentos de uma vez num mundo com colônias reais,
**sem forma de saber qual causou o quê** se algo estranhar. Primeiro a população passa a existir,
crescer e aparecer nos números; depois, com semanas de dados reais, ela passa a restringir. Os
parâmetros saíram de simulação, e **simulação não é o mundo**.

### Detalhes de implementação que valem registro

- O consumo entra **depois** da produção, e **uma vez por tick**. Depois porque os colonos comem o
  que a colônia acabou de produzir; uma vez só porque o delta é fatiado em cada conclusão de obra, e
  cobrar por fatia cobraria o mesmo período mais de uma vez.
- O débito usa `where amount >= x` no UPDATE, como o resto do domínio: a coluna é unsigned, e saldo
  negativo seria erro de banco em vez de bug silencioso. O que não couber simplesmente não é
  consumido — **ninguém morre de fome** (§6.6: degrada, não se perde).

### ⚠️ Um vermelho no e2e que não era regressão — de novo

A suíte da Capital reprovou em *"No element found for selector: [data-cancelar-anuncio]"*. Rodei de
novo **sem mexer em nada**: verde, 10 suítes, 340 asserções. Era corrida do teste.

A causa é a mesma família do D-164: **`page.click` do Puppeteer não espera**. A asserção logo acima
usa `esperarTexto`, que insiste por 8 s; o clique seguinte olhava uma vez e desistia. Uma renderização
um pouco mais lenta reprovava a suíte com o jogo perfeito.

Antes de rodar de novo, confirmei que `ColonyTick` só é construído pelo contêiner — eu havia mexido
no construtor dele, e essa era a hipótese de regressão que precisava ser descartada primeiro.

⚠️ **E há um achado sistêmico**: existem **mais de cinquenta** `page.click('[data-…]')` crus
espalhados pelas suítes (16 na Capital, 18 na mobile, 8 no chat). Todos são a mesma bomba-relógio;
a maioria só não estoura porque o elemento já está lá. Corrigi o que falhou. Um helper `clicar()`
que espere antes de clicar resolveria a família inteira, e fica anotado — não foi feito agora
porque tocar em cinquenta pontos no mesmo dia da ativação da população misturaria dois riscos.

### ⚠️ Impedimento 3: a minha própria conferência do §7.1 não valia nada

Escrevi acima que *"nenhuma das 29 fica acima do teto"*. **Estava errado.** Rodei aquela conferência
antes de semear `building_operator_requirements` em produção — a tabela estava vazia, então
`necessariaParaOQueJaTem()` devolvia zero para todo mundo, e a resposta "zero acima do teto" era
aritmética sobre o nada. Foi o ensaio a seco do comando que pegou, depois de semear:

**21 das 29 precisam de mais do que cabe no próprio teto.** A colônia 28 tem teto 10 e precisa de 34.

A causa não é capacidade mal calibrada: **20 das 29 têm Estrutura de Sobrevivência nível 1**, porque
até hoje não havia razão nenhuma para subi-la. O nível 1 não foi escolha delas.

### E por isso o `min()` do grandfathering foi embora

| concessão | consequência |
|---|---|
| limitada ao teto | 21 colônias em déficit por prédios erguidos **antes da regra** — o que o §6.7 proíbe em uma frase |
| o que a colônia precisa | operam tudo o que ergueram, e **ficam sem crescer** até subir a habitação |

`Ciclo::avancar()` já sustentava a segunda sem remendo nenhum: `$total < $capacidade` governa **só o
crescimento**. Acima do teto ninguém morre e ninguém é expulso — o teto trava o crescimento. A
colônia opera o que construiu e ganha um motivo concreto para subir um prédio que, até hoje, não
tinha nenhum.

A folga do §6.7 continua limitada pelo teto: ela é conforto, e conforto não justifica empurrar
ninguém para cima do limite.

⚠️ E ao inverter o teste do teto apareceu um bug meu: `necessariaParaOQueJaTem()` **já embute a
folga**, e eu estava aplicando os 20% duas vezes. O piso passou a ser o requisito cru.

### Verificação

1062 testes verdes (9 novos), entre eles o par que impede a chave de voltar a ser decorativa
(desligada, o tick não toca na população; ligada, cresce e consome) e o par do teto: a concessão
passa do teto quando precisa, e acima dele a população não cresce **nem morre**. E2E com 10 suítes e
340 asserções.

## D-179 — a população está no ar

`population_settings.ativo = 1` em produção, em 2026-08-01, depois do grandfathering. É a primeira
mecânica da Alpha 2 a tocar o mundo real.

### O estado do mundo no minuto zero

| | |
|---|---|
| colônias povoadas | 29 de 29 |
| colonos concedidos | 535 |
| abaixo do que precisam para operar (violaria o §6.7) | **0** |
| acima do teto habitacional (operam tudo, não crescem) | 18 |
| **crescendo de fato** | **5** |

Cinco de 29 crescendo não é acidente nem falha: **22 estão no teto**, porque 20 delas têm Estrutura
de Sobrevivência nível 1 — nunca houve razão para subi-la. É exatamente o sinal que a mecânica
deveria emitir no primeiro dia: *o prédio que você ignorou agora vale alguma coisa.*

### O consumo, contra o estoque real

| recurso | mundo/hora | estoque | autonomia |
|---|---|---|---|
| água | 53,5 | 1.013.204 | 789 dias |
| oxigênio | 64,2 | 2.534.474 | 1.645 dias |
| biomassa | 42,8 | 1.398.384 | 1.361 dias |
| energia | 32,1 | 1.034.578 | 1.343 dias |

⚠️ **17 colônias não têm energia nenhuma**, e o gargalo do `Ciclo` é o recurso mais escasso — elas
não crescem. Conferido antes de virar a chave: **só 2 estão travadas exclusivamente por isso**; as
outras 15 já estavam no teto. O efeito novo é de duas colônias, e a saída é óbvia e legível.

### A verificação, num tick de verdade

Noventa e cinco segundos depois: `populacao_resto_milli` saiu de 0 e as **5** colônias acumulando
crescimento são **exatamente** as 5 que têm energia e estão abaixo do teto. O acumulador de
milésimos é o que impede a curva horizontal que a rodada 1 do simulador expôs; vê-lo mexer em
produção é a prova de que `popular()` rodou.

Nenhum colono inteiro nasceu ainda, e não deveria: a 70 bps/h, a maior colônia em crescimento leva
~3 h para o primeiro. Fumaça verde, front 200 e `central/colony` 401.

### O que continua desligado, e por quê

A penalidade de eficiência por escassez e o bloqueio por falta de operadores **não** entraram — são
a A2.6. Mudar dois comportamentos de uma vez num mundo com colônias reais tira a única coisa que
torna o primeiro dia interpretável: saber qual mudança causou o quê.

## D-180 — a rota de upgrade existia sem tela, e a A2.7 ainda não fechou

Fui seguir a A2.7 e encontrei, na fase que eu mesmo tinha entregado, **o defeito que a fase existe
para consertar**.

### ⚠️ Publiquei uma rota que nenhum jogador alcança

`POST /central/transport/vehicles/{id}/upgrade` estava no ar, testada, com domínio, ledger e
parâmetros versionados. E `client.ts` **não a chamava**. O jogador não tinha como subir o nível de
veículo nenhum.

O roadmap descreve a A2.7 assim: *"`vehicles.level` já existe no banco, mas não há rota de upgrade:
o nível existe sem caminho para subir."* Eu troquei um caminho faltando por outro — o nível passou a
ter rota, e a rota passou a não ter porta. 1065 testes verdes não viram, porque **nenhum deles olha
a tela**.

Agora há um teste que olha: a listagem de `/transport` tem de trazer o bloco de upgrade, e o e2e tem
de encontrar `[data-melhorar]`.

### Os dois lados na mesma linha, porque é o critério de saída

O critério é *"escolha econômica mensurável, e não apenas aumento nominal de nível"*. Uma escolha só
é mensurável com os dois lados à vista, então a tela mostra juntos:

> Carrega **6.000 → 6.900**, e a manutenção passa de **100%** para **120%** do normal.

Mostrar só o ganho transformaria o upgrade em botão que ninguém teria motivo para não apertar — que
é exatamente o que a §13 proíbe ao vedar melhorar tudo de graça. Há teste exigindo que a manutenção
suba de verdade, e não apenas que o campo exista.

### ⚠️ E um acoplamento latente entre suítes, que o meu teste revelou

O e2e do Mercado cravava `15 / 6.000`. As suítes rodam todas no **mesmo mundo semeado**
(`migrate:fresh --seed` acontece uma vez só), e a Capital roda logo antes do Mercado. No instante em
que a Capital passou a subir o nível de um veículo, a capacidade virou 6.900 e o Mercado reprovou
**sem que nada do que ele afirma tivesse mudado**.

O que aquele teste afirma é que a carroceria **soma** dois recursos: 10 + 5 = 15. A capacidade do
veículo tinha entrado na regex por acidente. O denominador saiu.

### O que a A2.7 ainda deve

| item | estado |
|---|---|
| 1–3, 5 · rota, custo, capacidade↑/manutenção↑, parâmetros | feito |
| **4 · teto de estoque que trava** | **não feito** |
| **6 · rodada do simulador** | **não feito** |

⚠️ O item 4 não é fiação, é balanceamento — e medi antes de propor número. `silo_capacidades` é
**plana: 10.000 em todos os dez níveis, para todo recurso**. O nível do Depósito Local não faz nada.
E o `Silo` **não é teto de estoque**: ele decide o que fica protegido de saque, e o próprio docblock
dele diz isso. Conflar os dois inventaria uma regra que ninguém decidiu.

Pior: o mundo guarda ~35 mil de água por colônia, **3,5× acima** dos 10.000. Ligar hoje um teto que
trava pararia a produção de praticamente todas as 29. Segue o mesmo caminho da população — nasce
dormente, atrás de chave, e a rodada do simulador (item 6) calibra a curva antes de qualquer
ativação.

A §14 já diagnosticava tudo isto: *"Hoje só o Tanque de Combustível tem teto real; o Silo protege de
saque, mas não limita."*

### Verificação

1065 testes verdes (3 novos, todos sobre a tela poder oferecer o upgrade) e 10 suítes e2e verdes.

## D-181 — a A2.7 fecha: o teto que trava, e a prova de que o upgrade é escolha

Faltavam os itens 4 e 6, e nenhum dos dois era fiação.

### O teto de estoque, e por que NÃO reusei o `Silo`

O `Silo` responde *"quanto está protegido de saque"*; o teto responde *"quanto cabe"*. Mesmo prédio,
duas perguntas, e dois números que precisam se mover em separado. Conflá-las inventaria uma regra que
ninguém decidiu. (De quebra, `silo_capacidades` é **plana** — 10.000 em todos os dez níveis —, então
o nível do Depósito Local hoje não altera nada; isso é assunto da proteção, e fica para quando o
saque de colônia existir.)

Tabela nova, `estoque_settings`, com a chave-mestra da casa.

### ⚠️ O teto não pôde entrar no `acumular()`, e a razão é a §14

O caminho óbvio seria limitar o crédito no ponto onde o estoque cresce. Para a **extração** isso está
certo — não há insumo, e travar o ganho é literalmente "a produção para". Para as **conversões**
estaria errado: o insumo já teria sido consumido, e a saída seria descartada. Isso é **derramar**,
exatamente o que a §14 proíbe.

Então cada caminho trava antes de consumir:

| caminho | como trava |
|---|---|
| extração | o ganho é limitado ao espaço livre |
| conversões | pelo `$tetoSaida` que a Destilaria já usava desde o D-131 |
| **Siderúrgica** | **o lote inteiro**, pela saída mais apertada |

O lote da Siderúrgica tem **seis saídas simultâneas**. Creditar cinco e descartar a sexta seria
derramar, então o teto trava o lote por inteiro — e o progresso volta ao acumulador em vez de sumir,
porque o Metal Bruto já foi debitado e cobrá-lo por nada seria pior que o problema.

### ⚠️ Um teste meu passava porque nada acontecia

O teste do lote da Siderúrgica passou de primeira. Desconfiei — foi assim que o grandfathering me
enganou no D-178 — e escrevi o **controle**: o mesmo cenário com o teto desligado. **O controle
reprovou.** A Siderúrgica nível 3 processa 34 Metal Bruto/h contra um lote de 1.000, e em 24 h não
fecha lote nenhum. Eu estava medindo o silêncio dela.

Com três dias, o par passa a significar alguma coisa.

### As duas rodadas do simulador (item 6)

Registradas no `BALANCEAMENTO.md`. A posição de desenho do teto foi **declarada antes de medir** —
*um dia no nível 1, uma semana no nível 10* — e a curva a cumpre nas duas pontas: 20 h e 6,1 dias.

E o upgrade de veículo **é escolha**: a mesma tonelagem custa **+11,7% de manutenção** e cabe em
**1,60× menos viagens**. Subir troca custo por unidade por vazão por veículo — vale para quem tem
vaga de frota escassa, não vale para quem já tem veículo ocioso.

⚠️ Quase registrei +33,9%. Era o `ceil()` de 4 viagens contra 3, não economia: com tonelagem pequena
o arredondamento domina a medida. O número que eu ia publicar estava errado por arredondamento.

### O que NÃO foi ligado

`estoque_settings.ativo` continua `false`. O mundo guarda ~35 mil de água por colônia contra 10.000
de teto no nível 1, e 25 das 29 têm Depósito Local nível 1 — ligar hoje travaria a produção de quase
todas de uma vez.

O teto **nunca destrói estoque**: acima dele a produção para e o que existe fica, como o teto
habitacional da população (D-178). Mas "não destrói" não é "pode ligar". A ativação é decisão
separada, e precisa de um plano para as veteranas.

### Verificação

1074 testes verdes (9 novos) e 10 suítes e2e verdes. Migration aplicada no dev em MariaDB antes do
deploy — SQLite não prova DDL.

## D-182 — A2.5 item 7: o Diplomata ganha o sistema que o nome prometia

O D-174 fechou a fatia anterior da fase registrando o que faltava: *"Diplomata é um cargo, não um
sistema."* O papel existe desde o D-114 e só sabia convidar colônia. Nunca houve tratado, aliança ou
relação de qualquer espécie **entre** federações.

E havia uma peça esperando há meses: `desconto_tributo_aliados_bps` vale 50% desde o D-120, mas
*"aliado"* ali quer dizer **mesma federação**. O jogo tinha desconto entre aliados sem ter aliados.

### As decisões de desenho, declaradas antes do código

**⚠️ Dois estados, e não três.** Aliada e neutra. **Hostilidade não entra**: não há guerra entre
federações no jogo — a A2.10 é quem a traz —, então um estado "hostil" hoje não faria nada. Publicar
estado sem efeito é exatamente a peça inerte que esta fase vem consertando (`vehicles.level` sem
rota, `population_settings.ativo` sem leitor, `.botao` sem definição).

**Consentimento mútuo para aliar, unilateral para romper.** Entrar exige acordo, sair não exige
refém: uma aliança que precisasse dos dois para acabar seria armadilha — bastaria a outra parte
calar-se para prender alguém num pacto que já não serve. Quem propôs **não aceita a própria
proposta**, e há teste nisso: sem a trava, um Diplomata proporia e aceitaria sozinho, e o
"consentimento mútuo" seria um comentário no código.

**O desconto entre federações aliadas é MENOR que o interno** (20% contra 50%). Se rendesse o mesmo,
o teto de 12 membros viraria letra morta: bastaria montar três federações aliadas em vez de uma
grande.

### ⚠️ A decisão mais importante: o antimonopólio passa a contar o BLOCO

Uma federação aliada a outras duas não são 12 colônias: são até **36 operando em conjunto**. Se o
teto de ocupação de zonas continuasse olhando só a federação, **aliar-se viraria lavanderia de
monopólio** — a regra do §04 seria contornada pela porta da frente, montando federações aliadas em
vez de uma grande, que é precisamente o arranjo que ela existe para impedir.

`OcuparZonaNeutra` e `Concentracao` passaram a somar o bloco, e a tela avisa disso **antes** de a
aliança ser feita. Esconder o custo faria a aliança parecer só vantagem, e o preço apareceria como
uma ocupação negada sem explicação.

O bloco é **raso**: aliado de aliado não é aliado. Com transitividade, um teto de 2 aliadas ainda
produziria uma corrente ligando o mundo inteiro num bloco só.

### Três defeitos que os testes acharam

⚠️ **`max_aliadas` não estava no `$fillable`.** A atribuição em massa o descartava **em silêncio** —
o painel do operador salvaria sem erro e sem efeito. Um teste pegou.

⚠️ **`update()` numa tabela de parâmetros ainda vazia não afeta linha nenhuma**, e o `singleton()` a
cria depois com o padrão. O teste media o valor de fábrica achando que media o meu.

⚠️ **E o `tsc` deste projeto não estava conferindo nada.** `tsconfig.json` é só um arquivo de
referências com `"files": []`; `npx tsc --noEmit -p tsconfig.json` percorre **zero arquivos e sempre
sai 0**. Descobri porque um `<Selo tom="sucesso">` inválido passou — a prop certa é `estado`. O
comando correto é `tsc -b`, que o `npm run build` já usa; conferi quebrando de propósito e vendo o
erro aparecer. As checagens avulsas que rodei neste projeto com `-p` não valiam nada.

### O que a A2.5 ainda não fecha

O critério de saída — *"capacidade estratégica que um conjunto de jogadores independentes não
possui"* — agora tem duas pernas reais (desconto de tributo entre aliadas e o bloco territorial),
mas o **item 4**, "objetivos federativos", continua sendo as missões cooperativas do D-120 sem
revisão contra o que a fase quer dizer por objetivo.

### Verificação

1090 testes verdes (16 novos) e 10 suítes e2e verdes, com seis asserções novas na Capital — incluindo
o clique que propõe a aliança e volta com resposta.

⚠️ E o semeador do e2e ganhou duas federações, **cada colônia na sua**: se ficassem na mesma, o
desconto entre filiadas passaria a incidir nas entregas que outras suítes conferem, e elas
reprovariam sem que nada do que afirmam tivesse mudado. É o acoplamento do D-180, evitado desta vez.

## D-183 — A2.5 item 4: não havia objetivos federativos, havia missões pessoais fantasiadas

O item 4 do trabalho pedia *"criar objetivos federativos"*, e o D-174 o dera como parcialmente
resolvido pelas missões cooperativas do D-120. Fui conferir, e o defeito é **de conceito**.

### O que as missões `categoria = 'federacao'` realmente faziam

Cada membro ganha a **sua** linha, o progresso espelha entre as irmãs, e **cada um é pago
individualmente** — `Colony::increment('fert_micro')`, XP pessoal. Uma federação inteira cumpre um
objetivo comum e **nada é produzido para a federação**.

E o **fundo**, que existe desde o D-114, só se enche por **doação física**: alguém dirigir um veículo
carregado até lá. Não havia um único caminho pelo qual conquistar algo enchesse o caixa comum.

### A decisão: objetivo federativo é o que paga à FEDERAÇÃO

É a propriedade que os distingue, e é ela que produz o que o critério de saída pede — *"capacidade
estratégica que um conjunto de jogadores independentes não possui"*: um **tesouro comum que cresce do
trabalho coletivo**, distribuído pelo Líder ou pelo Intendente com o `SacarDoFundo` que já existe.

O XP pessoal continua: quem trabalhou merece o reconhecimento. O que muda é de quem é o **produto**.

⚠️ E o prêmio é **uma vez por federação, não uma por membro**. Doze membros concluem a mesma linha
semanal; sem guarda, seriam doze prêmios. A chave carrega federação, template e **janela** — nunca o
`id` da linha pessoal, que difere por membro e faria a chave deixar de colidir.

### ⚠️ Três armadilhas, e a segunda quase foi para produção

**1. O `update()` da migration rodou contra tabela vazia.** A migration corre **antes** de o seeder
inserir os templates: em produção as linhas já existem e ela resolve; num banco recriado — o dos
testes e o do e2e — o prêmio nasceria nulo. O lugar certo é o **seeder**. É a mesma armadilha que já
me pegou no `federation_settings` horas antes, agora pelo outro lado.

**2. `insertOrIgnore` engole `NOT NULL`, e isso silenciou a funcionalidade inteira.** A primeira
versão gravava uma linha-sentinela com `resource_type` nulo para servir de guarda de idempotência —
e a coluna é `NOT NULL`. O `insertOrIgnore` engoliu a violação, devolveu zero, a função saiu cedo, e
**o prêmio nunca era pago**. Nenhum erro, nenhum log: só um fundo que não crescia.

A guarda passou a ser a **própria linha do prêmio**, com dado de verdade — não há o que violar, e a
primeira volta do laço decide. Quem pegou foi o teste que confere o **saldo**, não a execução.

**3. Codificação dupla.** Passei `json_encode()` ao seeder, que insere pelo modelo — e o cast `array`
codifica de novo. O valor voltava como *string*, e o `foreach` estourava. A irmã dela,
`recompensa_recursos`, sempre passou o array cru; bastava olhar a linha de cima.

### Sobre a unicidade do `federation_ledger`

Ele tinha `ref` com índice **comum, não único**. Conferido antes de criar o único: a tabela está
**vazia** em produção, sem par duplicado a resolver.

⚠️ De passagem, desconfiei que o `ledger` principal também não tivesse a unicidade que o comentário
do tributo afirma. **Falso alarme, e vale registrar:** a garantia existe e está no lugar certo —
`tax_events.economic_event_key` é único, e o `insertOrIgnore` devolvendo zero é o que impede tributar
duas vezes. O comentário aponta para lá.

### Os números são HIPÓTESE

2.000 Metal Bruto para o Comboio da Aliança; 600 Ligas e 1.000 Metal Bruto para a Defesa Conjunta.
Como todo número desta fase, existem para o mecanismo ter o que pagar; promovê-los exige uma rodada
registrada da trilha A2.S.

### A A2.5 fecha

Os sete itens do trabalho estão entregues. O critério de saída — *"capacidade estratégica que um
conjunto de jogadores independentes não possui"* — tem agora **três pernas reais**: o desconto de
tributo entre filiadas e entre aliadas, o bloco territorial com o antimonopólio que o acompanha, e o
tesouro comum que cresce do trabalho coletivo.

⚠️ O que **não** posso declarar é que o balanceamento está certo: os números da diplomacia e dos
objetivos são hipótese, e só o campo — ou a trilha A2.S — dirá se a federação organizada compensa o
custo de organizar-se.

### Verificação

1094 testes verdes (4 novos) e 10 suítes e2e verdes.

## D-184 — A2.6: a população passa a restringir, e o que a medida impediu

O D-178 ligou a população em duas etapas e disse o porquê: *"primeiro a população existe e aparece;
depois, com semanas de dados reais, ela restringe"*. Esta é a segunda etapa.

### ⚠️ A medida que impediu um estrago, feita antes de ligar

O `Ciclo` calculava `eficiencia_bps` desde o D-178 e **ninguém consumia** — o número certo indo para
o vazio. Antes de conectá-lo, medi contra a produção real:

| eficiência que a penalidade aplicaria | colônias |
|---|---|
| 100% | 12 |
| **50–74%** | **17** |

**Dezessete das 29 cairiam para metade da produção**, e o gargalo era um só: **energia**.

Não é escassez, é **dupla contagem**. Energia é estoque e fluxo ao mesmo tempo: o Reator credita e
**toda construção debita o consumo operacional**. Uma colônia que gasta o que produz fica com estoque
zero, e isso é o estado **normal** de quem roda no que gera. Cobrá-la outra vez por colono
transformava a operação normal de 17 colônias em fome permanente, sem saída — elas não têm excedente
justamente porque estão operando.

E o §6.7 proibiria mesmo que o desenho fosse desejável: aplicar de uma vez a quem construiu antes da
regra é o que a promessa veda. Energia saiu da cesta; depois disso, **as 29 ficam em 100%**.

### Alocação explícita, e uma entrega estreitada por escrito

*"Transferência colônia → zona"* e *"retorno"* viraram **alocar** e **devolver** operadores,
instantâneos. Colono em trânsito seria sistema novo — o GDD não publica tempo de deslocamento de
pessoas, e inventá-lo duplicaria a logística que já existe para carga. A decisão que a fase quer é
*"quais zonas consigo manter operando"*, e ela existe inteira sem trânsito. ⚠️ Isto **estreita** uma
entrega, e por isso está escrito.

`alocadaEmZonas()` passou a somar o que está **alocado**, e não o que o nível exigiria: derivar não
produzia escolha nenhuma, e a fase existe para criar a decisão de onde pôr gente quando ela falta.

### Degrada, não se perde (§6.6)

Zona desfalcada extrai menos, com piso, e **continua sendo do dono**. O custo de manutenção **não
cai junto**, e isso é decisão: é a assimetria que torna a falta de operadores um problema econômico
em vez de um "rende menos" neutro. Se o custo caísse, zona vazia seria indiferente.

### O Abrigo de Robôs finalmente faz o que o nome diz

Cada nível dispensa um operador humano — *"poucos humanos operam muitos robôs"*, o princípio
declarado da fase. Até aqui ele só servia de defesa contra o Predador, e o próprio catálogo admitia
que a função de recuperação *"o GDD promete e nunca cronometra"*. **Piso de 1**: zerar o requisito
faria território operar sozinho para sempre e apagaria a decisão que a fase cria.

### ⚠️ Três defeitos silenciosos, e dois teriam ido para produção

**1. `operadores` fora do `$fillable` do `NeutralZone`.** `OcuparZonaNeutra` grava a equipe da zona
nova por `update()`: a atribuição em massa a descartaria **em silêncio**, e toda zona nasceria
desfalcada sem erro nenhum. **Terceira vez hoje** que esta armadilha aparece (`max_aliadas`,
`recompensa_federacao`, agora esta).

**2. `BuildingOperatorRequirementSeeder` não estava no `DatabaseSeeder`.** Em produção eu o rodei à
mão, e **foi isso que mascarou o defeito**: qualquer instalação nova nasce sem requisito de operador
nenhum, o grandfathering concede o piso de 1 colono a todo mundo, e a mecânica inteira fica inerte.
Quem pegou foi o e2e, que recria o banco do zero.

**3. E o impedimento revelou um número de balanceamento.** A folga do §6.7 é 20% do que a colônia
precisa, e sobre uma colônia pequena isso dá **um** colono sobrando — enquanto uma zona nova pede
dois. Medido: **9 das 29 colônias podem ocupar zona nova hoje**, mediana de 0 livres, e **nenhuma
com disponível negativo**. Ninguém quebrou, e quem quiser expandir sobe a habitação primeiro — que é
o incentivo que a população deveria criar. Fica registrado como observação, não como conserto.

### O e2e passou a exercitar a fase

`tools/e2e.sh` liga a população, roda o mesmo `populacao-grandfather` que rodou em produção, e
cresce a colônia até o teto habitacional. Sem isso o painel de operadores não renderizaria e a fase
ficaria sem cobertura de ponta a ponta — que foi exatamente como publiquei uma rota sem tela no
D-180.

### O que fica de fora

Telemetria de custo territorial entrou (`custo_territorial`, com custo **e** eficiência no mesmo
evento — custo sem rendimento não responde nada). Os números da fase seguem **HIPÓTESE**: nenhuma
rodada da trilha A2.S exercitou operadores de zona ainda.

### Verificação

1106 testes verdes (12 novos) e 10 suítes e2e verdes, com seis asserções novas na suíte de Zonas —
incluindo devolver a equipe e ver a tela dizer *quanto* a zona perdeu e que ela não se perde.

## D-185 — A2.8: o Motor de Eventos, e a média ponderada que dispensou fatiar o tick

*"Dar ao Dono capacidade de criar emoção sem precisar alterar código para cada evento."* Hoje uma
tempestade que derrubasse a extração exigiria um `if` novo no tick. Agora é **uma linha de tabela**.

### ⚠️ A decisão de arquitetura da fase: a média ponderada pelo tempo é EXATA

O roadmap exige que o modificador seja *"reconstruível no passado, para que 'Desde sua última visita'
consiga explicar por que a produção caiu"*. O caminho óbvio seria **fatiar o delta do tick** nas
bordas de cada evento, como o `ColonyTick` já faz com as conclusões de obra.

Não é preciso, e a razão é aritmética: a produção de um intervalo é `taxa × tempo`, e o modificador é
constante por trechos. Então `Σ(taxa × mᵢ × tᵢ)` é **idêntico** a `taxa × T × (Σ mᵢtᵢ / T)` — a média
ponderada. **Não é aproximação: é a mesma conta**, escrita de outro jeito, e sem multiplicar as
consultas do caminho mais quente do jogo.

⚠️ Isso vale porque produção é **linear no tempo**. Se um dia entrar um modificador não linear, ele
precisa fatiar — e está escrito no código, onde quem for mexer vai ler.

### As três promessas do roadmap, cada uma com teste

| promessa | como |
|---|---|
| **nunca escreve no ledger** | o evento muda a TAXA; quem credita continua sendo o tick |
| **reconstruível no passado** | `para()` aceita qualquer intervalo, inclusive já ocorrido |
| **cancelar não apaga o histórico** | `cancelado_em` encerra o futuro; o passado continua calculável |

Um evento que lançasse no ledger criaria receita do nada e faria a telemetria derivada dele (D-163)
passar a mentir. E apagar a linha ao cancelar faria o resumo de retorno dizer que a produção caiu sem
motivo — um jogo que não consegue explicar a própria economia perde a confiança do jogador de um
jeito que não se recupera.

### Preço fica de fora, e isso é do roadmap

`price_interventions` existe desde o D-35. *"O motor não a absorve nem a duplica nesta versão"* —
duas verdades sobre o preço seria a pior herança possível deste motor.

### Um evento, um modificador

Dois numa linha pareceriam economia e viravam confusão: cancelar metade seria impossível, e o
operador perderia a leitura de qual dos dois causou o quê. É o mesmo princípio que fez a ativação da
população ser em duas etapas (D-178). O comando recusa, e há teste.

### A §Segurança, item por item

- **preview antes de ativar**: o comando *sempre* mostra o que faria, e ativar exige `--ativar` em voz
  alta. Mesma escolha do `populacao-grandfather`, que salvou a operação da população (D-178);
- **dry-run**: `--colonia=N` roda o evento de verdade, em escala de um;
- **auditoria**: `criado_por` e a linha preservada para sempre;
- **cancelamento** e **rollback lógico**: `cancelado_em`, que nunca toca o passado.

O preview mostra a conta que o operador precisa antes de apertar o botão: *"uma taxa de 200/h passaria
a 160/h"*. "−20%" é abstrato; isso não é.

### ⚠️ O MariaDB recusou a tabela, e o SQLite teria deixado passar

Duas colunas `timestamp NOT NULL` na mesma tabela: só a primeira ganha o default implícito, e a
segunda recebe `0000-00-00`, que o modo estrito rejeita. Trocadas por `dateTime`. Foi o MariaDB do
**dev** que pegou — os testes rodam em SQLite, e o verde deles não prova DDL.

### E o motor não é invisível

Rota `/eventos` e uma faixa no jogo, porque **um motor que muda a economia sem que ninguém veja é
indistinguível de um defeito**. O jogador veria a produção cair e concluiria que o jogo quebrou.

A visibilidade `parcial` mostra a tensão e esconde a explicação: *"algo está afetando a produção"*,
sem dizer o quê. É a única das três que cria mistério em vez de confusão. `notas_internas` nunca sai
do servidor, em nenhuma visibilidade.

⚠️ E `segredo` é afirmação **separada** de `visibilidade = secreto`: quem quiser segredo tem de
dizê-lo duas vezes. Uma trava só seria fácil demais de desligar por acidente.

### O que fica para as versões seguintes

Os modificadores de taxa, logística, construção, pesquisa, população e território; os gatilhos por
condição (o campo `gatilho` já existe, para que acrescentá-los não exija migração de dados); e
recompensas e missões atreladas ao evento, cujas colunas estão lá e **ainda não são lidas por
ninguém** — dito aqui para que não passem por entregues.

### Verificação

1121 testes verdes (15 novos) e 10 suítes e2e verdes, com três asserções novas: a faixa aparece, o
nome do evento aparece, e o efeito aparece junto.

## D-186 — A2.12 (hardening): não havia limite de tentativa nenhum no login

Fui percorrer o checklist da fase de endurecimento e o primeiro item já pagou a viagem.

### ⚠️ `/login` e `/register` aceitavam tentativas sem limite, com o jogo no ar

Nenhum `throttle` em rota nenhuma, e nenhum limitador declarado. Adivinhar a senha de uma conta real
custava só tempo de CPU alheia. É a falha mais séria que encontrei na Alpha 2 inteira, e ela estava
lá desde o começo.

**A chave é e-mail + IP.** Só por IP puniria uma casa — ou uma escola — porque um vizinho errou a
senha três vezes; só por e-mail deixaria o atacante distribuir tentativas entre contas. As duas
juntas travam o ataque a uma conta sem derrubar quem divide a saída de internet.

### ⚠️ E o middleware `throttle` estava errado para este caso

A primeira versão usava `->middleware('throttle:login')`. O middleware conta **toda** requisição,
inclusive as bem-sucedidas — quem entra e sai várias vezes, trocando de aba ou reconectando, bateria
no teto **por usar o jogo direito**. O e2e provou na hora: dez suítes, dez logins.

Tentei zerar o contador no acerto e não funcionou: o middleware deriva a chave internamente, e o meu
`clear()` não batia com ela. A conta passou a ser feita **à mão no controller** — `tooManyAttempts`
antes, `hit` só no fracasso, `clear` no acerto. Explícito, testável, e só quem erra é contado.

O registro continua no middleware: ali toda tentativa deve mesmo contar.

### O `npm audit` que eu NÃO consertei, e por quê

Três avisos `high` viraram dois com `npm audit fix`. Os que restam são
*"React Router: RSC Mode CSRF Bypass"* — e a única correção oferecida é **rebaixar uma versão
maior** (7.18 → 7.11).

Conferido antes de decidir: `main.tsx` usa `BrowserRouter`, roteamento puramente de cliente. **Não há
RSC nem server actions neste projeto** — o servidor é um Laravel separado. A vulnerabilidade não
alcança este código, e rebaixar uma dependência maior para calar um aviso que não se aplica trocaria
um risco teórico por uma regressão certa.

⚠️ Fica registrado como **decisão consciente**, não como pendência esquecida: quando o React Router
publicar correção sem quebra, atualiza-se.

### Os 69 `page.click` crus, varridos

`page.click` do Puppeteer **não espera**. Numa suíte cujas asserções usam `esperarTexto` — que insiste
por 8 s —, cada clique cru era uma corrida silenciosa. Já produziu **dois vermelhos falsos** neste
projeto, e nos dois casos a suíte voltou verde sem uma linha de código mudar. *Um teste que reprova
com o jogo perfeito é pior que teste nenhum: ensina a ignorar o vermelho.*

Um helper `clicar()` que espera antes de clicar, e 69 chamadas trocadas em sete suítes. As duas que
sobraram em `comum.mjs` já esperavam.

### Migrations em MariaDB, do zero

Item do checklist, e vale porque **o verde dos testes é SQLite**. Banco novo, 103 migrations, seed
completo: 60 requisitos de operador, 52 templates de missão, 120 zonas. Confirma de quebra que a
correção do `BuildingOperatorRequirementSeeder` (D-184) funciona em instalação nova — que era
exatamente o caso que estava quebrado.

### O que do checklist NÃO foi feito, e é honesto dizer

- **Carga** e **simulação longa em staging**: não há staging, e o roadmap já diz *"quando houver"*;
- **Teto global de requisições**: o cliente consulta o servidor em laço (mapa, chat, fila, tick). Um
  teto chutado sem medir o ritmo real derrubaria jogador legítimo no meio da partida. Precisa de
  medição antes, e está anotado;
- **Acessibilidade** e **mobile** têm cobertura parcial (a suíte `mobile.e2e.mjs` existe, o
  `prefers-reduced-motion` e o `:focus-visible` existem desde a A2.V1), mas não houve auditoria
  dedicada;
- **Backups**: existem e foram exercitados hoje quatro vezes, com verificação de conteúdo — mas
  restauração nunca foi testada de verdade. **Backup que ninguém restaurou é hipótese.**

### Verificação

1124 testes verdes (3 novos) e 10 suítes e2e verdes depois da varredura dos cliques.

## D-187 — A2.9: o item único ganha a identidade que a fase inteira exigia

A **regra central** da A2.9 é uma frase: *"um item marcado como único deve possuir identidade
persistente e histórico."* Ela estava inteiramente ausente.

### O que "único" significava até aqui

`endurance_items.tipo` já carregava `comum|raro|unico` desde o D-135, e o painel já forçava
`quantidade_total = 1`. Mas a posse continuava **fungível**: `colony_endurance_items` é
`(colônia, item, quantidade)` — e **quantidade não tem identidade**. Não havia descobridor, nem
proprietário registrado, nem transferência. Os três que o §11.1 exige.

### ⚠️ O descobridor é imutável, e é ele que dá valor ao item

`descobridor_colony_id` se escreve uma vez e **nunca mais** — por isso são duas colunas, e não uma.
O que faz um único valer mais que um raro **não é a escassez**: raro também é escasso. É ele ter uma
origem que ninguém pode reescrever. Se a primeira venda apagasse a descoberta, o item viraria só um
número 1.

O histórico é **append-only**, como o `ledger` e o `federation_ledger`: a biografia de um item não
pode ser editada depois — nem por nós — ou deixa de valer como biografia. Há teste que tenta
reescrever e outro que tenta apagar.

### Só o único ganha instância

Do roadmap: *"apenas o único recebe instância"*. Dar identidade a tudo multiplicaria por milhares as
linhas de posse sem responder pergunta nenhuma — ninguém quer a biografia do parafuso comum de número
4.312. Conferido antes de escrever: em produção há **um item, tipo `raro`, 42 unidades**, e **nenhum
único jamais existiu**. A tabela nasceu vazia, sem migração de dados — exatamente o que o roadmap
previu.

### O leilão move a instância junto, inclusive para o escrow

Dono nulo é *"saiu de uma mão e ainda não chegou na outra"*. Sem isso, o item ficaria registrado com
o vendedor enquanto estivesse à venda, e a biografia mentiria sobre onde a peça esteve — o oposto do
que o §11.1 quer dela. Os dois caminhos do fechamento (arrematante, ou volta ao vendedor sem lance)
passam pelo mesmo ponto, e é isso que impede um deles de esquecer de registrar.

### ⚠️ Um bug meu que o teste pegou: telemetria adiada nunca descarregada

Usei `adiar: true` — correto, porque o registro roda dentro da transação da transferência e um
rollback levaria a métrica junto (D-173). Mas o buffer só descarrega no fim da requisição, via
`app()->terminating`, e um teste que chama o domínio direto **nunca fecha requisição**. O evento
existia e não chegava ao banco. Em produção o `terminating` roda; no teste, descarrego à mão — e o
comentário explica que isso é reproduzir o que a produção faz sozinha.

### E a identidade aparece na tela

Selo, descobridor, dono atual e quantas vezes trocou de mão. **"Identidade persistente" que ninguém
enxerga não é identidade** — e já publiquei rota sem tela nesta base (D-180). Aqui a consequência
seria pior: o item teria história no banco e o jogador veria só mais uma peça.

O selo é derivado (`FW-U-nucleo_da_endurance-8F2A`) e não sequencial: um contador revelaria quantos
únicos existem no jogo, que é informação do operador e não do jogador.

### O que a A2.9 ainda não tem

- **Eventos da Endurance** no motor da A2.8: a própria A2.8 os põe na lista *"Depois"*, junto com
  combate e Federação. O motor de hoje só sabe produção e consumo, e forçar Endurance nele agora
  seria inventar um modificador que ninguém desenhou;
- **Descoberta por escavação**: hoje o único nasce na **compra**. Escavar e achar é o que a fase
  chama de *"aprofundar a escavação/desmontagem"*, e depende de uma mecânica de escavação que não
  existe — dito aqui para não passar por entregue.

### Verificação

1137 testes verdes (13 novos) e 10 suítes e2e verdes, com três asserções novas na Capital: o selo
aparece, é legível, e a origem aparece junto.

## D-188 — A2.10: o documento, e por que nenhuma linha de código foi escrita

O roadmap da A2.10 é categórico, e é a **única fase** com esta trava:

> *"Esta é a única fase da Alpha 2 que exige um documento de design próprio antes de qualquer linha
> de código. Nenhum prompt deve ser disparado enquanto ele não existir."*

Então a entrega desta fase, hoje, é o documento: `docs/alpha2/GDD_GUERRA_FEDERATIVA.md`, cobrindo os
18 tópicos que o roadmap lista. **Nada de código.**

### ⚠️ O levantamento trouxe o fato que mais importa: não há com quem guerrear

Medido na produção:

| | |
|---|---|
| colônias | 29 |
| **federações ativas** | **1** |
| **zonas ocupadas** | **1** de 120 |
| alianças firmadas | 0 |

**Uma federação não entra em guerra com ninguém.** Construir guerra federativa hoje seria erguer um
sistema que nenhum jogador consegue usar — e esta Alpha inteira vem provando que peça sem uso apodrece
(`vehicles.level` sem rota, `.botao` sem definição, `population_settings.ativo` sem leitor).

Recomendo esperar um gatilho de mundo — 3 federações e 15% das zonas ocupadas — e gastar o esforço
na A2.V e em fazer o território valer a pena, que é o que **produz** as federações que a guerra
pressupõe.

### ⚠️ E duas dependências que o roadmap exige NÃO estão cumpridas

O roadmap manda não iniciar antes de sete coisas. Cinco estão prontas. Duas não:

- **pesquisa**: construída na A2.3 e **nunca ligada** — `research_settings.ativo` continua `false`;
- **eventos**: o motor está no ar (D-185), mas **não sabe falar de guerra**. Ele só tem modificador de
  produção e consumo, e a própria roadmap da A2.8 põe "combate" na lista *Depois*. O §17 do documento
  o marca como pré-requisito.

Dizer que as dependências estão cumpridas porque as fases foram "entregues" seria o mesmo erro de ler
uma chave-mestra desligada como funcionalidade no ar.

### As posições de desenho que o documento assume

Todas derivadas de princípios que o jogo já publicou, e todas marcadas como recomendação:

- **Colônia nunca é alvo.** Num mundo sem reset, saquear a casa cria classe de vítima permanente:
  quem perdeu fica mais fraco e perde de novo. É o oposto do §6.6 e do §1.1;
- **Nenhuma perda é permanente.** Perda permanente acumula para sempre e produz uma casta que nunca
  mais alcança — o defeito que mata jogos persistentes;
- **Aliança não arrasta para a guerra.** A A2.5 desenhou aliança como acordo econômico; torná-la
  pacto militar mudaria o que ela significa depois de assinada;
- **O bloco antimonopólio vale na guerra.** Sem isso, conquistar seria a porta dos fundos do monopólio
  que o D-182 fechou pela porta da frente;
- **O espólio vai à federação**, com XP pessoal para quem lutou — mesmo princípio do D-183;
- **A zona conquistada chega DESFALCADA**: a equipe era do antigo dono e voltou para casa dele.
  Conquistar não é o fim do custo, e isso a A2.6 nos deu de graça;
- **Hostilidade só agora faz sentido.** A A2.5 entregou `aliada` e `neutra` deliberadamente sem
  `hostil`, porque um estado sem efeito seria peça inerte. A A2.10 é quem lhe dá consequência.

### ⚠️ O item que mais pode estragar a fase: contas vinculadas

Alguém cria uma segunda federação com contas próprias, declara guerra a si mesmo, capitula de
propósito e transfere território "legitimamente" — lavando a concentração que o teto de 20% existe
para impedir.

Recomendo **não tentar impedir por heurística**, e sim tornar caro e visível: o espólio conta para o
teto do bloco (então a lavagem simplesmente não completa se estourar), toda guerra fica no registro
público, o cooldown é do **par**, e o operador ganha relatório de pares recorrentes.

Detectar multiconta por IP produz falso positivo em casa com dois jogadores — e **punir irmão que joga
junto é pior do que deixar passar um trapaceiro**.

### Doze decisões ficam em aberto

Estão tabeladas no fim do documento. Design é do Dono; o que eu podia fazer era chegar com
recomendação fundamentada em cada uma, e é o que está lá.

## D-189 — A2.V: a auditoria do design system, e a dívida era minha

O roadmap da A2.V avisa em uma frase por que ela não pode ficar para o fim:

> *"Se os tokens vierem depois, ela e o painel de população **nascem fora do design system e terão
> de ser refeitos**."*

Os tokens vieram antes (A2.V1, no começo desta Alpha). Fui medir se a tela **nascida depois** deles
os usou.

### ⚠️ A medida, e ela me acusa

| | |
|---|---|
| componentes do design system | 4 (`Botao`, `Cartao`, `Selo`, `Estado`) |
| arquivos que o importam | **4**, de 40 |
| usos de `<Botao>` | 15 |
| `<button>` crus na interface | **~150** |

E a tela que **eu** construí nesta sessão — mesa diplomática, painel de operadores da zona, upgrade
de veículo, faixa de eventos — nasceu quase toda **fora** do sistema: `<button className="border-ink/30
text-ink hover:bg-ink…">` repetido à mão, que é exatamente a cópia que o `Botao` existe para
eliminar.

Fiz o que o roadmap avisou para não fazer, no mesmo dia em que li o aviso.

### Por que isso importa mais do que parece

O docblock do `Botao` explica: **o par de cores não é parâmetro**. `tools/valida_contraste.py` mediu
a paleta inteira e achou duas armadilhas — `rust` com texto `ink` dá 3,08:1 e reprova; `ember` com
texto claro dá 1,74:1 e reprova. Por isso a variante escolhe **fundo e texto juntos**, e quem usa
escolhe a *intenção*.

Cada botão cru é uma chance de alguém escolher o par que reprova. Os meus não reprovaram — a
disciplina de cor deste código é boa —, mas isso é sorte, não sistema.

### O que foi corrigido agora

As seis peças desta sessão passaram a usar `Botao`: três na mesa diplomática, duas no painel de
operadores, uma no upgrade de veículo. Contraste revalidado: **14 pares aprovados, 3 proibições
intactas**. E2E verde nas 10 suítes.

### ⚠️ O que NÃO foi feito, e é o grosso da fase

Restam ~150 botões crus em 26 arquivos, e as sub-fases **A2.V2 a A2.V6** inteiras — HUD, colônia,
mapa, Capital, Endurance, combate. Isso é revisão visual de verdade: releitura espacial da colônia,
estados visuais de edifício, ameaças no mapa, identidade própria da Endurance.

**Não é trabalho de um turno, e fingir que é produziria uma migração mecânica sem revisão de
desenho** — que é o oposto do que "revisão visual gigantesca" quer dizer. Fica dito: a A2.V está
**começada e longe de pronta**, e o que entreguei foi a auditoria mais a correção da dívida que eu
mesmo criei.

## D-190 — "Ligue a pesquisa": a chave ligava o nada, e três peças faltavam

Pedido para ligar a pesquisa. Parei antes de virar a chave — e ainda bem.

### ⚠️ Ligar hoje não teria feito absolutamente nada

| | |
|---|---|
| rota de API | **nenhuma** |
| tela | **nenhuma** |
| quem consome `EfeitosDaPesquisa` | **ninguém** |
| quem chama `ConcluirPesquisa` | **ninguém** |
| onde `research_settings.ativo` é lido | **um** lugar: um serviço inalcançável |

A A2.3 entregou o **modelo** — catálogo, trilhas, custos, vagas, vocabulário de efeitos — e parou
aí. O D-168 registrou isso no título: *"a estrutura entra, os números não"*. O que ninguém registrou
é que a estrutura também não tinha porta.

É o mesmo defeito da população no D-178, e a lição pegou: a primeira coisa que fiz foi conferir se a
chave liga alguma coisa.

### ⚠️ E um docblock que afirmava o que não acontecia

`ConcluirPesquisa` dizia, com todas as letras: *"É por isso que `ColonyTick` chama isto **antes** de
calcular produção."* **O `ColonyTick` não o chamava.** A frase descrevia uma intenção que ninguém
tinha ligado — e isso é pior do que não ter a frase, porque quem lesse concluiria que estava feito.

O efeito real: uma pesquisa iniciada **nunca terminava**. A colônia perdia a vaga do Laboratório
para sempre e não recebia bônus nenhum.

### O teto de produção passou a ser AGREGADO

Endurance e pesquisa somam antes de o teto ser aplicado, e não cada uma por si. Duas fontes de 30%
dariam 60% — e o teto existe para limitar o **total** que uma colônia acumula, não cada origem.

Isto não é invenção minha: o docblock de `somaBps()` já avisava que *"a soma das duas fontes ainda
pode passar do teto individual — quem quiser um teto conjunto precisa somá-las antes de limitar, no
consumidor"*. Era uma dívida anotada esperando quem a pagasse.

### O que foi construído

- `bonusDeProducaoPorAlvo()` em lote na pesquisa, sem teto — quem consome soma e limita;
- o bônus de produção e o desconto de tributo chegando ao tick e ao tributo, com teto agregado;
- `ConcluirPesquisa` chamado pelo `ColonyTick` **antes** de produzir, como o docblock sempre disse;
- `GET /pesquisa` e `POST /pesquisa/{id}`;
- a tela, aberta **de dentro do Laboratório** — do jeito que o Depósito Local abre os recursos
  (D-105). Sem essa porta, a rota seria peça inerte, que foi o erro do D-180.

⚠️ A tela mostra **os efeitos que já estão valendo**, e não só o que dá para pesquisar. Sem isso o
jogador concluiria uma tecnologia e não teria como saber se ela funciona — a mesma armadilha da
penalidade invisível que a A2.6 evitou. **Progressão que não se vê é indistinguível de progressão
que não aconteceu.**

### ⚠️ Codificação dupla, terceira vez nesta sessão

Passei `json_encode()` para uma coluna que o modelo já faz cast de `array`, e o `foreach` estourou.
Aconteceu no `recompensa_federacao` (D-183), no seeder de missões, e agora aqui. **É o meu erro mais
repetido nesta base**, e o sinal é sempre o mesmo: a coluna tem `_json` no nome e o modelo já cuida
disso.

### E um teste que me ensinou algo sobre o relógio

`ConcluirPesquisa` compara `finishes_at` com `now()` — o relógio de parede, não o instante que o
tick recebe. Está certo para a produção, onde o tick roda com o agora real. Mas um teste que só passa
`now()->addHours(2)` como **argumento** não move o `now()` que a conclusão consulta, e a pesquisa
nunca vencia. O relógio tem de andar de verdade (`travel()`).

### Verificação

1144 testes verdes (7 novos). E2E vermelho uma vez em `Runtime.callFunctionOn timed out` — timeout
de protocolo do Puppeteer, não asserção; rodado de novo **sem mudar nada**, 10 suítes verdes. Foi
carga da máquina: eu havia disparado suíte, build e e2e juntos num servidor de 4 GB.

## D-191 — O teto de estoque não pode ser ligado, e a medida diz por quê

Última chave dormente. Fui medir o que aconteceria ao virá-la, e a resposta é clara: **não dá,
como está.**

### ⚠️ Ligar hoje faria 29 colônias pararem de produzir

| | |
|---|---|
| pares colônia×recurso acima do teto | 112 de 725 |
| **colônias com ao menos um recurso travado** | **29 de 29** |
| quais recursos | oxigênio 29 · água 28 · biomassa 28 · metal 15 · energia 12 |

São justamente os quatro essenciais. E o §6.7 é categórico: *"nenhuma colônia existente pode parar de
produzir por uma regra que não existia quando ela foi construída."*

### ⚠️ E o grandfathering pelo prédio é IMPOSSÍVEL

O caminho óbvio seria o mesmo da população: subir o Depósito Local de cada colônia até caber o que
ela já tem. A conta:

| | |
|---|---|
| Depósito Local hoje | n1×25, n2×4 |
| nível **necessário** | **n9 a n18** |
| **nível máximo do prédio** | **10** |

**Oito colônias precisariam de níveis que não existem.** E não é caso extremo: a **mediana** de
oxigênio no mundo é **90.201** — nove vezes o teto do nível 1, e acima dos 74.501 que o nível 10
oferece. O maior estoque do jogo é 406.555 de energia.

### O que isso realmente significa

A curva foi calibrada contra uma colônia madura **simulada** (rodada 8 da trilha A2.S: 20 h no n1,
6,1 dias no n10, dentro da posição declarada). O mundo **real** acumulou por meses sem teto nenhum, e
está entre **6× e 44×** além do que a curva foi desenhada para conter.

É a lição do D-178 outra vez, e maior: **simulação não é o mundo.** A rodada não estava errada — ela
mediu o ritmo de produção, que era a pergunta certa. O que ela não podia medir era o **acervo** de um
mundo que rodou sem limite.

### Os quatro caminhos, e o que cada um custa

| # | caminho | custo |
|---|---|---|
| a | ligar como está | **29 colônias param de produzir** — viola o §6.7 |
| b | subir `capacidade_base` para ~55.000 | o nível 1 passa a segurar ~4,5 dias de produção: o teto deixa de ensinar exatamente onde deveria |
| c | elevar o máximo do Depósito Local acima de 10 | mudança de desenho de prédio que ninguém decidiu |
| d | **piso pessoal por grandfathering** | veterano congela a vantagem que já tinha |

✅ **Recomendo (d)**, e há uma coluna esperando por ela: `resources.storage_cap` existe desde o D-14,
está **NULL em todas as 754 linhas**, e não é lida por ninguém — o `Silo` calcula sob demanda e o
docblock dele diz que a coluna *"continua NULL de propósito"*.

A regra seria: **o teto de cada linha é `max(curva do nível, o que a colônia já tinha na ativação)`**.

- ninguém para de produzir — o §6.7 fica honrado por construção;
- o teto passa a **morder para todo mundo daí em diante**: veterano não acumula mais, novato encontra
  a curva de verdade;
- é a mesma forma do teto habitacional (D-178) e do teto de zona (D-184): **trava o ganho, nunca tira
  o que existe.**

⚠️ O preço é real e precisa ser dito: o veterano fica com um teto pessoal alto para sempre. Mas ele o
acumulou sob as regras que existiam, e o §6.7 existe precisamente para proteger isso.

### Fica DORMENTE, e a decisão é do Dono

Não vou ligar. Escolher entre (a), (b), (c) e (d) muda o jogo de formas materialmente diferentes num
mundo com 29 colônias reais, e nenhuma das quatro é obviamente certa. O que eu podia fazer era
transformar "está dormente" numa decisão informada — com os números na mesa.

## D-192 — O piso pessoal, e o teto de estoque enfim ligável

Decisão do Dono: opção **d** do D-191. O teto de cada linha passa a ser
`max(curva do nível, o que a colônia já tinha)`.

### ⚠️ A descoberta que mudou a implementação

O piso **não pode ser o estoque exato**. Se fosse, `espacoLivre` valeria zero e a produção pararia
**no mesmo instante da virada** — exatamente a regra que o piso existe para evitar. O §6.7 não seria
cumprido; seria cumprido no papel e violado no relógio.

Por isso o piso é `estoque × (1 + folga)`, com folga de **20%** — o mesmo número que o §6.7 usa na
migração da população, e não por coincidência: é a mesma promessa cumprida do mesmo jeito.

Há teste para os dois lados: a veterana **continua produzindo** depois da virada, e **trava exatamente
no piso** quando a folga acaba. O piso **adia** a parada; não a remove.

### Onde o piso mora

`resources.storage_cap` — coluna do D-14, NULL nas 754 linhas, não lida por ninguém. O `Silo` calcula
proteção sob demanda e o docblock dele dizia que a coluna *"continua NULL de propósito"*. Deixou de
estar.

⚠️ **Só quem passa da curva ganha piso.** Gravar em todo mundo encheria a coluna de números
irrelevantes, e um dia alguém leria aquilo como "o teto é este" — quando o teto é a curva.

### ⚠️ O preço, dito por escrito

O veterano cujo estoque já passa da capacidade do nível 10 **não consegue subir o próprio teto
construindo**: o piso dele É o teto, para sempre. Ele para de acumular aquilo de que tem anos de
sobra, e os extratores ficam ociosos até gastar.

Isso estava na mesa quando a opção foi escolhida (D-191), e está aqui para ninguém a redescobrir
daqui a seis meses achando que é defeito.

### E uma armadilha que o comando evita

`TetoDoEstoque::capacidade()` devolve `null` enquanto a chave estiver desligada — e o grandfathering
roda **antes** da virada, por definição. Se o comando usasse aquele método, mediria o nada e diria
que não havia nada a fazer: o pior jeito possível de falhar, porque pareceria sucesso. Ele calcula a
curva na mão, a partir da mesma linha de parâmetros.

### Verificação

1148 testes verdes (4 novos).

## D-193 — As doze decisões da guerra federativa, tomadas

O D-188 entregou o documento e travou o código até que as doze decisões existissem. Elas existem.
`docs/alpha2/GDD_GUERRA_FEDERATIVA.md` passou a **DECIDIDO**, e o corpo do rascunho foi preservado —
onde a escolha inverteu a recomendação, a seção original fica no lugar com a nota do que foi decidido.
Reescrevê-la apagaria o raciocínio que produziu a pergunta, e é ele que explica por que a alternativa
existia.

### O que foi decidido

| # | decisão |
|---|---|
| 1 | **construir e ligar agora** |
| 2 | colônia **é** alvo, limitada ao **excedente do Depósito** |
| 3 | declarar custa **Fert$ do fundo + Nióbio** |
| 4 | **não há recusa** — quem é declarado está em guerra |
| 5 | **7 dias** de duração |
| 6 | Capital e Espaçoporto **fora, sempre** |
| 7 | tropa **consome operadores** da colônia |
| 8 | declarar guerra a uma aliada **rompe a aliança** |
| 9 | capitulação: **o vencedor escolhe** entre uma zona e Fert$ |
| 10 | fórmula do ranking segue como desenho próprio |
| 11 | contas vinculadas: **travas econômicas + detecção com revisão humana** |
| 12 | neutralidade **declarada antes da guerra**; inatividade **não** protege |

Sete saíram como eu recomendava. Cinco não — e essas são as que valem registrar.

### ⚠️ O "exposto" do Silo deixa de ser inerte

O `Silo` calcula *protegido* e *exposto* desde o D-66/D-107, e o docblock dele dizia que *"conectar
'exposto' a alguma consequência de jogo é uma entrega futura, deliberadamente fora desta"*. A decisão
2 é essa entrega: **na colônia, o excedente do Depósito vira espólio; o protegido nunca é tocado.**

Eu recomendava colônia intocável. A escolha foi outra, e ela tem uma virtude que a minha não tinha:
dá função a uma peça que estava parada há meses, e cria pressão real para **retirar** mineral em vez
de empilhar — que é pilar declarado do jogo.

### ⚠️ Na ZONA, o Depósito deixa de proteger

Decisão separada e mais forte: a zona conquistada é **totalmente saqueável**, revogando o D-66/D-107
nesse contexto.

**A consequência precisa ser dita:** o Depósito de zona perde a função de proteger, e com ela o motivo
de subi-lo. O D-66 registrava que a extração deliberadamente **não** para no teto — *"o excedente
empilha ao relento"* — justamente para haver espólio de guerra. Com saque total, o cálculo do jogador
vira simples: **retirar sempre, acumular nunca**. É jogável, aumenta a pressão logística, e deixa
`Domain\Guerra\Protegido` valendo só fora da guerra federativa.

### ⚠️ A combinação colide com o §1.1, e isso foi escolhido de olhos abertos

**Colônia saqueável + guerra de 7 dias + inatividade sem proteção.** As três juntas fazem de uma
viagem de uma semana uma perda material, e o §1.1 do GDD diz para não exigir login constante.

A mitigação é a **neutralidade declarada com antecedência** — e ela é real: neutralidade deixa de ser
prêmio por ausência e vira **ato político tomado com o jogador presente**. Quem sabe que vai sumir,
declara. O que ela não cobre é a ausência imprevista, e esse é o custo aceito.

⚠️ **Recomendo uma medida desde o primeiro dia:** a telemetria da A2.0 deve acompanhar a **taxa de
retorno de quem foi saqueado estando ausente**. Se quem apanha ausente não volta, o número aparece
antes de virar êxodo, e o parâmetro de neutralidade ainda dá para mudar. É a mesma disciplina que
salvou a ativação da população (D-178) e a da penalidade de eficiência (D-184): **medir antes de
descobrir pelo estrago**.

### O que ainda falta para a primeira linha de código

O D-188 listou duas dependências que o roadmap exige e que **continuam não cumpridas** — uma delas
deixou de estar:

- **pesquisa**: ✅ resolvida. Ligada em produção no D-190;
- **eventos**: ⚠️ **continua pendente**. O motor da A2.8 só sabe produção e consumo, e o §17 deste GDD
  exige um **modificador de guerra** para que evento externo possa abrir janela, impor trégua ou mexer
  no custo de mobilização. A própria A2.8 pôs "combate" na lista *Depois*.

E a decisão 10 — a fórmula do ranking — segue sendo desenho próprio, não escolha binária.

## D-194 — O modificador de guerra, e a média que não servia

Última dependência que o roadmap da A2.10 exigia. O motor da A2.8 só sabia produção e consumo; o §17
do GDD da guerra pede que um evento externo possa **impor trégua** e **mexer no custo de mobilização**.

### ⚠️ A decisão de desenho: portão não se mede por média

O motor calcula **média ponderada pelo tempo**, e o D-185 provou que isso é **exato** para produção,
porque taxa é linear no tempo. Guerra não é taxa: *"há trégua agora?"* e *"quanto custa declarar
agora?"* são perguntas de **instante**.

Uma trégua cobrindo metade do intervalo viraria "meio bloqueada" — e o pior não é ser errado, é ser
**plausível**: 5.000 é um número que ninguém desconfiaria, e o erro apareceria meses depois como uma
guerra declarada durante uma trégua.

Por isso `Modificadores::em()` responde por instante, e **`para()` recusa** os pontuais com uma
exceção em vez de converter em silêncio. Explodir custa um teste vermelho; devolver a média custaria
um bug invisível.

O docblock que escrevi no D-185 já avisava: *"isso vale porque a produção é linear no tempo; se um
dia entrar um modificador não linear, ele precisa fatiar"*. Entrou, e o aviso foi honrado.

### Dois modificadores, e não três

- **`guerra_declaracao`** — o portão. `-10000` fecha, e ninguém declara enquanto durar;
- **`guerra_custo`** — quanto custa declarar e mobilizar.

*"Abrir janela de guerra"* e *"impor trégua"* são a mesma alavanca vista dos dois lados. Dois nomes
para o mesmo portão só criariam a chance de os dois discordarem.

⚠️ O preview avisa que **só −10000 fecha**: qualquer outro valor no portão não impede declaração
nenhuma, e um operador que digitasse −5000 acharia ter imposto meia trégua.

### ⚠️ O SQLite impõe `enum`, ao contrário do que eu supus

A primeira versão da migration só mexia no MariaDB, com o comentário de que *"o SQLite dos testes não
impõe a restrição"*. **Impõe** — o Laravel traduz `enum` em CHECK constraint, e o teste quebrou com
*"CHECK constraint failed: modificador"*.

É a assimetria SQLite×MariaDB de sempre, **invertida**: desta vez o banco dos testes era o mais
estrito, e foi ele que pegou. O `change()` nativo resolve os dois sem ramo por driver — que era
exatamente o erro.

A coluna deixou de ser `enum` também por outra razão: a A2.8 promete mais seis modificadores (taxa,
logística, construção, pesquisa, população, território), e `enum` obrigaria uma migration a cada um.
A lista canônica agora mora em `Modificadores::TODOS`, onde o código já a procura.

### ⚠️ E isto nasce sem consumidor, de propósito e por uma fatia só

Nenhum código lê `guerra_declaracao` ainda, porque guerra federativa não existe. É a coisa que esta
Alpha inteira vem condenando — peça sem uso apodrece —, e a diferença aqui é o prazo: o consumidor é
a **próxima fatia**, não um "algum dia". Se a A2.10 não continuar, isto é código morto, e fica dito
para que seja cobrado.

### Verificação

1155 testes verdes (7 novos), entre eles o que prova que `para()` recusa um pontual e o que prova que
a trégua vale por instante: antes não bloqueia, durante bloqueia, depois não bloqueia mais.

## D-195 — Guerra federativa, primeira fatia: o esqueleto

Declaração, prazo de 7 dias, cooldown de par e aviso público. Cada regra é uma das doze decisões do
D-193, e cada uma tem um teste que a guarda.

### ⚠️ O custo decidido era impagável por construção

A decisão 3 manda declarar custar *"Fert$ do fundo + Nióbio"*. Fui implementar e descobri que **a
federação não tem dinheiro**: o fundo é `federation_holdings`, uma tabela de **recursos**, e o
`federation_ledger` sequer aceita lançamento sem `resource_type`.

Não havia de onde tirar o Fert$ — e não havia como pôr. O custo era uma regra que ninguém conseguiria
cumprir, o que não é regra, é impedimento.

Duas peças fecharam o laço: `federations.fert_micro` (o caixa que o D-114 sempre implicou ao chamá-lo
de "fundo") e `ContribuirParaOFundo`, para qualquer membro abastecê-lo. **Sem a segunda, o saldo
nasceria em zero e ficaria lá** — o mesmo impasse com outra cara.

⚠️ Contribuir é de qualquer membro; sacar continua sendo de Líder e Intendente. A assimetria é a
mesma do D-114: pôr no caixa comum não precisa de cargo, tirar precisa.

### O primeiro consumidor do modificador de guerra

O D-194 publicou `guerra_declaracao` e `guerra_custo` **sem consumidor**, e eu disse que ele viria na
fatia seguinte. Veio: a trégua fecha o portão antes de qualquer outra conferência, e o custo de
mobilização multiplica o preço. Os dois lidos **por instante**, nunca por média.

### Três decisões que viraram trava com teste

- **Não há recusa** (decisão 4): não existe caminho para o alvo negar. O teste confere que a guerra
  existe do lado de quem foi declarado sem ele ter aceitado nada;
- **Declarar a uma aliada rompe a aliança** (decisão 8) — e ⚠️ **só se a guerra realmente acontecer**.
  A ruptura ficou depois de todas as conferências: se uma delas recusasse, a federação teria perdido
  a aliada por uma guerra que não houve. Há teste para os dois lados;
- **O cooldown é do PAR** (§10): impede declarar de novo à mesma federação, e **não** impede declarar
  a um terceiro. Congelar a geopolítica puniria quem foi atacado.

### E o encerramento existe, porque quem inicia sem quem conclui já me custou uma fase

`EncerrarGuerras` entra no tick. Sem ele a guerra ficaria `ativa` para sempre, o cooldown — que conta
a partir do fim — nunca começaria, e o par jamais poderia declarar de novo: proteção contra assédio
virando bloqueio permanente. É exatamente o defeito que a pesquisa carregou por meses (D-190).

### ⚠️ Dois erros meus, ambos pegos por teste

**Uma vírgula comida por regex** no `$fillable` do `Federation` derrubou 97 testes de uma vez com
`ParseError`. Barato porque foi imediato — e é o argumento contra editar PHP com expressão regular.

**E li o modelo em memória em vez do banco:** o teste que prova que o custo sai do fundo comparava o
saldo da colônia *antes*, lido de um objeto criado sem `fert_micro`, contra o valor real depois. Zero
contra o saldo inicial que o banco dá. Mesma armadilha do D-166, terceira ou quarta aparição nesta
base: **o modelo em memória não conhece os defaults do banco.**

### O que esta fatia NÃO faz

Nada de alvos, combate ou espólio — nenhuma zona muda de dono, nenhuma colônia é saqueada. A guerra
existe, tem prazo e aparece; o que ela **faz** é a fatia seguinte, e antes dela vem a **neutralidade
declarada**, que precisa existir antes do saque para não haver uma semana de consequência sem a
válvula de escape que o D-193 escolheu.

### Verificação

1170 testes verdes (15 novos) e 10 suítes e2e verdes, com a mesa de guerra provada na Capital: o
custo aparece, a tela avisa que o outro lado não pode recusar, e o clique chega ao domínio.

## D-196 — O GDD do estado do jogo, e um número meu que estava errado

Pedido para documentar tudo e consolidar num GDD atualizado. Saiu
`docs/alpha2/GDD_ESTADO_DO_JOGO.md`.

### Por que um documento novo, e não uma edição do GDD existente

O `GDD_ALPHA2.md` diz o que o jogo **deve ser**; o roadmap, em que ordem construir; o `BALANCEAMENTO`,
o que foi medido; o `decisoes.md`, o porquê de cada escolha. Faltava o que o jogo **é**.

⚠️ E a diferença entre intenção e estado foi **o defeito mais caro desta Alpha**. Sete vezes uma fase
estava "entregue" e a mecânica não fazia nada: `vehicles.level` sem rota, `population_settings.ativo`
sem leitor, `.botao` sem definição, `EfeitosDaPesquisa` sem consumidor, `ConcluirPesquisa` sem quem o
chamasse, `silo_capacidades` plana em todos os níveis, `BuildingOperatorRequirementSeeder` fora do
seeder padrão.

Por isso o documento separa **três** estados e nunca os mistura: **no ar**, **dormente**, **não
existe**. *"Entregue"* não é um deles, e essa é a tese do documento inteiro.

### ⚠️ Escrevendo, encontrei um número errado que eu mesmo publiquei

O GDD da guerra federativa (D-188) dizia *"1 zona ocupada de **120**"*. A produção tem **77**. O 120 é
o número do **semeador do e2e**, e eu o carreguei para um documento sobre produção sem conferir.

A conclusão não muda — uma zona ocupada continua não sendo geopolítica. Mas **fato errado em documento
de decisão é como erro entra em decisão**, e a correção ficou registrada no próprio documento em vez
de apagada.

Isso aconteceu porque escrevi de memória. O documento novo foi inteiro medido contra a produção no
dia, e diz isso na primeira linha.

### O que a fotografia revelou

A leitura mais dura do quadro não é o que existe — é **o que não foi usado**:

| mecânica | usos |
|---|---|
| tecnologias pesquisadas | **0** de 8 |
| alianças firmadas | **0** |
| veículos acima do nível 1 | **0** de 51 |
| itens únicos | **0** |
| eventos de mundo | **0** |
| guerras | **0** |

Quase todas subiram há horas ou dias, então não é sinal de defeito. É sinal de que **nenhuma foi
validada por jogador**, e de que todo número deste documento continua hipótese até que seja.

E um dado bom no meio: a população saiu de 535 para **559**. Ela está crescendo — a única mecânica
desta Alpha que já tem prova de vida em campo.

### Os sete invariantes, escritos pela primeira vez num lugar só

Degrada não se perde · nada existente para por regra nova · o ledger registra o que aconteceu ·
chave-mestra nasce desligada **e tem de ligar alguma coisa** · peça sem uso apodrece · simulação não é
o mundo · medir antes **e medir a coisa certa**.

Cada um foi pago com um erro desta Alpha, e cada um é hoje guardado por teste. Estavam espalhados por
trinta e cinco decisões; agora estão numa página.

## D-197 — Neutralidade declarada, e a pergunta que a decisão 12 não respondia

Antes do saque, como eu mesmo tinha dito que seria: publicar consequência sem a válvula de escape
deixaria uma semana em que quem quisesse ficar fora não teria como.

### ⚠️ A decisão 12 não dizia quanto a neutralidade custa — e sem isso ela não funciona

> *"A neutralidade só pode ocorrer se declarada pelo jogador antes do início da guerra."*

Se fosse grátis e reversível na hora, **todos se declarariam neutros e largariam o escudo no instante
de atacar**. A neutralidade viraria o estado padrão do mundo, e a A2.10 inteira, decoração.

Duas regras resolvem, e são arbitragem minha registrada:

1. **Simetria.** A neutra não pode ser declarada **e não pode declarar**. É o que a palavra significa,
   e é o custo que paga a proteção.
2. **Carência para SAIR.** Encerrar só vale 24 h depois. Entrar é imediato; sair, não.

⚠️ A assimetria é a mesma da aliança (D-182) **pelo motivo oposto**: lá, sair é livre porque ninguém
deve ficar refém de um pacto; aqui, sair é lento porque ninguém deve poder atacar de dentro do abrigo.

E **não se declara neutralidade em guerra** — seria fugir do que já começou. A saída de lá é a
capitulação, que vem na fatia seguinte.

### ⚠️ Um teste achou uma corrida real, não um defeito de teste

`DeclararGuerra` confiava no modelo do **alvo** como ele chegou do roteador. Entre o início da
requisição e a transação, o alvo pode declarar neutralidade — e a leitura velha diria que não. O alvo
passou a ser relido **com trava**, como a `Diplomacia` já fazia com a colônia do autor.

Não era um teste chato: era o teste encontrando exatamente a janela que o `lockForUpdate` existe para
fechar.

### ⚠️ E a mesma armadilha de estado velho, de novo, do outro lado

O endpoint lia `$request->user()->colony->federation` — carregado uma vez e mantido em memória.
Qualquer coisa que mudasse a federação **depois** daquele carregamento não apareceria. Em produção
cada requisição reconstrói o usuário e o problema não dá as caras; sob `actingAs`, e em qualquer
caminho que reaproveite o modelo, dá. A federação passou a ser relida.

**Terceira vez nesta sessão** que "modelo em memória ≠ banco" me custa tempo: o `fert_micro` do
D-195, o alvo aqui, e agora a relação do usuário.

### Duas correções de método que se repetiram

- **Pint encurta o FQCN antes da minha busca.** Duas edições minhas não aplicaram hoje porque procurei
  `\App\Domain\...` onde já estava `Modificadores::class`. Editar PHP com texto exige olhar o
  arquivo **depois** do formatador, não antes;
- **A tela recarrega assíncrona**: o recado aparece antes da lista. Esperar o *seletor* em vez do
  texto é a cura, e é a mesma do D-180.

### Verificação

1178 testes verdes (8 novos) e 10 suítes e2e verdes, com cinco asserções novas na Capital — incluindo
a que prova que **pedir para sair não tira a proteção na hora**, que é a regra inteira num clique.

## D-198 — O saque de colônia exporia 85% do mundo, e a culpa é de um número em branco

Fatia de alvos e espólio. Medi antes de codar, e a medição a reprova como está.

### ⚠️ "Só o excedente do Depósito" quer dizer, hoje, "quase tudo"

| | |
|---|---|
| estoque total do mundo | 8.233.416 |
| **exposto ao saque** | **7.004.832 — 85%** |
| protegido pelo Depósito | 1.228.228 — 15% |
| o que um saque de 50% (§27.8) levaria | **3.502.416** |

A decisão 2 do D-193 foi tomada entendendo que o Depósito protege e **o excedente** fica em risco.
Na prática ele protege **15%**, e uma invasão bem-sucedida leva mais de 40% de tudo o que a colônia
tem.

### A causa: `silo_capacidades` é plana, e o prédio está no nível 1

Já registrei no D-181 que a tabela é **10.000 em todos os dez níveis** — o nível do Depósito Local
não altera a proteção. E medi agora que **25 das 29 colônias estão no nível 1**, exatamente como
estavam na Estrutura de Sobrevivência antes da população.

⚠️ **Por isso mexer no FATOR por nível não resolve nada:**

| fator | protegido |
|---|---|
| 1,25×/nível | 15% |
| 1,50×/nível | 16% |
| 1,75×/nível | 16% |

Quem está no nível 1 recebe a base, e ponto. **A alavanca é a base:**

| base do Silo | protegido | exposto |
|---|---|---|
| 10.000 (hoje) | 15% | **85%** |
| 30.000 | 41% | 59% |
| 50.000 | 59% | 41% |
| 100.000 | 82% | 18% |
| 200.000 | 96% | 4% |

### É a mesma forma do D-191, e a terceira vez nesta Alpha

Uma decisão de desenho apoiada num **parâmetro que nunca foi preenchido**. Antes foram a curva do
teto de estoque (validada em simulação, 6× a 44× fora do mundo real) e os requisitos de operador
(tabela vazia em produção, fazendo a conferência do §7.1 medir o nada).

O padrão é sempre o mesmo: **o número placeholder não avisa que é placeholder.** Ele responde a
consultas, passa nos testes, e só a medição contra o mundo mostra que ele nunca significou nada.

### Por que parei em vez de escolher sozinho

Não é timidez: as opções produzem jogos diferentes, num mundo com 29 colônias reais, e nenhuma é
obviamente certa. Publicar 85% de exposição achando que estava publicando "o excedente" seria
exatamente o erro que esta Alpha inteira vem evitando — e o único irreversível, porque estoque
saqueado não volta.

A fatia fica aberta até a base do Silo ter um número decidido.

## D-199 — A curva de proteção do Depósito, e o que ela muda no mundo

Decisão do Dono sobre o D-198: **base 50.000, +25% por nível**.

| nível | protege |
|---|---|
| 1 | 50.000 |
| 5 | 122.070 |
| 10 | 372.525 |

### O que muda em produção

| | antes | depois |
|---|---|---|
| protegido | 15% | **59%** |
| **exposto ao saque** | **85%** | **41%** |
| um saque de 50% leva | 42% do total | **~20% do total** |

"Excedente do Depósito" volta a querer dizer excedente. Dói de verdade e não arrasa.

### ⚠️ O fator não muda nada hoje, e isso está certo

25 das 29 colônias estão no Depósito Local **nível 1** — mesmo retrato da Estrutura de Sobrevivência
antes da população. Os +25% por nível não movem uma unidade neste mundo; passam a valer conforme ele
cresce, e é o que dá ao prédio uma **segunda razão para subir**, além da capacidade.

⚠️ Registrar isso importa: alguém que meça o efeito amanhã vai achar que a curva não faz nada, e vai
estar certo — sobre hoje.

### Proteção e capacidade continuam separadas

O `Silo` responde *"quanto está a salvo"*; o `TetoDoEstoque`, *"quanto cabe"* (D-181). Partilham o
prédio e a forma da curva, e **nada mais**. Os números se movem em separado, e é por isso que moram em
tabelas diferentes — conflá-las teria sido a decisão fácil e errada lá atrás.

### O teste que faltava havia meses

`silo_capacidades` foi plana em 10.000 nos dez níveis durante toda a vida do projeto, e **nenhum teste
percebeu** — a tabela respondia a consultas e passava em tudo. O novo não guarda a curva: guarda a
**propriedade**. *Subir o Depósito tem de proteger mais.* Com a tabela de antes, ele falharia.

É a resposta mais direta que encontrei para o padrão do D-198: **o número placeholder não avisa que é
placeholder** — então o que tem de existir é um teste sobre a propriedade que ele deveria produzir.

### Verificação

1182 testes verdes (4 novos).

## D-200 — Publiquei com um teste vermelho, e o teste defendia o erro

Duas coisas erradas de uma vez, e as duas valem registro.

### ⚠️ 1. Eu não olhei a saída dos testes antes de comitar

A suíte terminou em **1 falha, 1181 passando**, e eu segui direto para o commit, o push e o deploy.
O `deploy.sh` não roda testes — ele confere migrations, `APP_DEBUG`, opcache e fumaça —, então nada
me barrou.

Produção não foi afetada: o teste afirmava o valor **antigo**, e o código novo estava certo. Mas isso
é sorte, não processo. **A regra que eu quebrei é minha e é simples: ler a saída antes de comitar.**

### ⚠️ 2. O teste vermelho estava defendendo o placeholder

```
test_capacidade_padrao_e_10000_para_qualquer_recurso_e_nivel
```

Ele cravava **10.000 para todo recurso e todo nível** — exatamente o valor plano que fez 85% do
estoque do mundo ficar exposto (D-198). Um teste assim não protege nada: ele fica verde **porque nada
mudou**, e transforma um número em branco em decisão aparente.

Foi ele que manteve a tabela plana invisível durante toda a vida do projeto. E quando o valor
finalmente foi corrigido, ele reprovou — **defendendo o defeito**.

### A lição, que é a resposta ao padrão do D-198

Já registrei que *"o número placeholder não avisa que é placeholder"*. Faltava a outra metade:
**teste que afirma o valor de um parâmetro em branco é como o placeholder se defende.**

O teste passou a guardar a **propriedade**: a capacidade existe para todo recurso, e **sobe com o
nível**. Com a tabela antiga ele falharia — que é o que um teste deveria ter feito desde o começo.

### Verificação

1182 testes verdes.

## D-201 — Saquear colônia contradiz o §01, e onze jogadores já pagaram por uma defesa que não defende

Segunda parada da fatia de espólio. A primeira foi um parâmetro em branco (D-198); esta é estrutural.

### ⚠️ O §01 declara a colônia INVIOLÁVEL

E não fui eu quem descobriu: o catálogo de funções já dizia, sobre a Torre de Defesa —

> *"O GDD se contradiz: o slot principal é **INVIOLÁVEL (§01)**, então não há o que defender aqui. O
> bônus nunca é dado em número. Hoje só consome energia."*

A decisão 2 do D-193 torna a colônia saqueável no excedente do Depósito. Isso **revoga o §01**, e a
revogação precisa ser consciente — não consequência lateral de uma decisão sobre espólio.

### ⚠️ E a colônia não tem defesa nenhuma

Todas as defesas do jogo — Muralha de Perímetro, Torre de Vigia, Bastião — são **estruturas de zona**.
A colônia tem duas construções de aparência militar, e as duas são inertes:

| construção | efeito hoje | erguidas em produção |
|---|---|---|
| `torre_de_defesa` | **nenhum** | **11** |
| `quartel` | **nenhum** (só habilita atacar) | 3 |

**Onze jogadores construíram uma torre que não defende nada.** Publicar saque de colônia sem dar
função a ela seria transformar a decisão num imposto sobre estar menos online: o atacante manda, o
defensor não tem o que opor, e o resultado é determinístico. Isso não é balanceamento ruim — é
ausência de mecânica.

### A oportunidade que o problema traz

É o mesmo padrão que a A2.2 e a A2.6 já resolveram duas vezes: a Estrutura de Sobrevivência era
`'efeito' => 'nenhum'` até a população lhe dar teto habitacional; o Abrigo de Robôs só servia de
defesa contra o Predador até a A2.6 fazê-lo dispensar operadores.

**O saque de colônia é o que finalmente dá número à Torre de Defesa** — e recompensa onze jogadores
que apostaram nela antes de ela valer alguma coisa.

### Por que parei outra vez

Construir agora exigiria eu inventar, sozinho: o que a Torre faz, o que o Quartel faz na defesa, e
como se ataca uma colônia — um fluxo que não existe, já que `Atacar` só aceita Zona Neutra.

São decisões de desenho, não de fiação. E a diferença entre elas e as anteriores é que **esta revoga
um parágrafo do GDD**: o §01 diz inviolável, e o jogo passaria a dizer o contrário.

## D-202 — As decisões 13 a 16, e por que paro antes do cerco de colônia

O D-201 parou a fatia com três perguntas. As respostas estão no GDD da guerra, e são estas:

| # | decidido |
|---|---|
| 13 | **o §01 fica revogado em guerra declarada** — inviolável em paz, saqueável no excedente em guerra |
| 14 | **a Torre de Defesa reduz o quanto o saque leva** |
| 15 | **ataque a colônia reusa o cerco da zona, e exige Quartel erguido** |
| 16 | base de proteção do Depósito: 50.000, +25%/nível (D-199, já no ar) |

### O terceiro prédio inerte que esta Alpha ressuscita

A Estrutura de Sobrevivência era `'efeito' => 'nenhum'` até a população lhe dar teto habitacional. O
Abrigo de Robôs só servia contra o Predador até a A2.6 fazê-lo dispensar operadores. Agora a Torre de
Defesa — e **11 colônias já a construíram**, apostando nela antes de ela valer alguma coisa.

### ⚠️ Por que paro aqui, e não é objeção de desenho

O cerco atual é **profundamente específico de zona**: o desfecho troca `owner_colony_id`, o saque lê
`deposit_amount` e `refined_amount`, e tudo isso vive numa classe de 700 linhas que já resolve três
tipos de ataque. Reusá-lo para colônia é fatia grande — ramificação em todo o caminho de resolução,
mais uma tabela de cerco, mais o cálculo de defesa, mais o saque, mais a telemetria.

Emendar a mecânica mais complexa do jogo, **num jogo no ar**, no fim de uma sessão muito longa e com
pouca margem, é exatamente como nascem os erros que passei o dia catalogando — inclusive o de hoje,
em que publiquei com um teste vermelho por não ler a saída (D-200).

**As decisões estão registradas e nada se perde.** A fatia começa limpa, e a ordem dela é:

1. `combats.zone_id` nulável — o alvo é colônia quando não há zona. `defender_colony_id` já existe, e
   é o que torna o reuso viável sem tabela nova;
2. `AtacarColonia`, exigindo Quartel e guerra ativa;
3. ramificar `ResolverCombates` no desfecho: em vez de trocar o dono da zona, saquear o exposto;
4. a Torre de Defesa entrando na conta do espólio;
5. **a telemetria de retorno de quem foi saqueado ausente, junto** — não depois.

⚠️ E antes de qualquer linha, a medição: quanto uma invasão levaria de cada uma das 29 colônias, hoje.
As três ativações anteriores foram salvas por isso.

## D-203 — O cerco de colônia, e o §01 revogado na prática

Pedido para seguir depois de eu ter proposto parar. Segui, e a preocupação que levantei no D-202 fica
registrada uma vez, não repetida.

### A medição que autorizou a fatia

| perda por invasão | do estoque da colônia |
|---|---|
| mínima | 0% |
| **mediana** | **16,8%** |
| máxima | 32,7% |

Maior perda absoluta: 181.711 unidades. É o alvo que o D-199 tinha declarado — *"dói de verdade e não
arrasa"* —, e agora medido em vez de estimado.

⚠️ E medi o banco errado na primeira tentativa: rodei do diretório do repositório, que aponta para o
**dev**, e recebi zeros em tudo. Refiz contra a produção. O erro é meu e é o mesmo de sempre: **a
medição só vale se for do lugar certo.**

### ⚠️ Resolvedor próprio, e não um ramo no `ResolverCombates`

Aquele resolve três tipos de ataque em 700 linhas fortemente tipadas em `NeutralZone`: a defesa vem de
`Forcas::defensiva(NeutralZone)`, os defensores são da zona, e o desfecho troca `owner_colony_id` e
saqueia de `deposit_amount`. Ramificá-lo em cada ponto poria em risco **o único sistema de combate
testado que o jogo tem**, num jogo no ar.

**O preço é duplicação da fórmula da rodada**, e está escrito no docblock das duas classes: se o dano
por rodada mudar num lado, tem de mudar no outro. Foi troca consciente — a alternativa, generalizar
aquela classe agora, era a mudança mais arriscada disponível numa fatia que já revoga um parágrafo do
GDD.

⚠️ E o resolvedor de zona ganhou `whereNotNull('zone_id')`: sem isso ele encontraria `$zona` nulo no
primeiro cerco de colônia e **quebraria o tick inteiro**. Há teste para isso.

### `zone_id` nulável foi o que tornou o reuso possível

`combats` já tinha `defender_colony_id` — num ataque de zona ele guarda o dono dela. Num de colônia,
guarda o alvo, e `zone_id` fica nulo. **O alvo é colônia quando não há zona**, e nenhuma tabela nova
precisou existir.

### O terceiro prédio inerte ressuscitado

A Torre de Defesa reduz o espólio, 10% por nível, **com teto de 70%**. Sem o teto, uma Torre alta
zeraria o saque e atacar viraria puro custo — a mecânica se desligaria sozinha. Há teste para os dois:
que ela **reduz**, e que ela **nunca zera**.

Onze colônias em produção já a tinham erguido, defendendo o que ninguém podia atacar.

### Os dois lados da revogação do §01, cada um com teste

- **fora de guerra a colônia continua inviolável** — o §01 só cai dentro de guerra declarada;
- **dentro dela é alvo**, e o saque leva só o excedente do Depósito: o protegido **nunca é tocado**.

É a linha que separa *"a colônia é alvo"* de *"a colônia é destruída"*.

### E a telemetria subiu junto, como o D-193 pediu

`colonia_saqueada` carrega `defensor_offline`. É o número que dirá se a decisão de não proteger quem
some está expulsando gente — se quem apanha ausente não volta, isso aparece **antes** de virar êxodo,
e o parâmetro de neutralidade ainda dá para mudar.

### Verificação

1192 testes verdes (10 novos).

## D-204 — A porta do cerco de colônia, e três cenários de teste que estavam errados

O D-203 entregou o cerco sem rota e sem tela — eu mesmo apontei que aquilo era a peça inalcançável que
venho consertando a sessão inteira. Esta fecha.

### O que entrou

- `GET /war/inimigos` — quem pode ser atacado **agora**, já filtrado por guerra ativa. Sem ela,
  atacar exigiria adivinhar o id de uma colônia alheia;
- `POST /war/attack-colony`, **rota separada** da de zona: as travas são outras (guerra declarada,
  Quartel), o desfecho é outro (saque em vez de tomada), e o §01 só cai aqui. Um `alvo_tipo` na rota
  antiga faria duas regras muito diferentes entrarem pela mesma porta;
- a seção no Quartel, que mostra **o exposto do alvo e a Torre dele** — marchar sem saber o que se
  ganha é aposta, não decisão.

### ⚠️ Três cenários de teste errados, e nenhum deles era erro de código

**1. Semeei a guerra antes de as federações existirem.** O bloco do e2e ficou 60 linhas acima do que
cria as federações, `federation_id` veio nulo, e o semeador inteiro quebrou — a suíte reprovou já no
primeiro login, longe da causa.

**2. A guerra semeada impediu a neutralidade.** O bloco que testava declarar-se neutro passou a
reprovar, e a regra estava certa: **não se declara neutralidade no meio de uma guerra** (decisão 12).
Não era o teste nem o código — era o **cenário**. Reescrevi o bloco para afirmar a recusa, que é a
regra que de facto se aplica ali; o caminho feliz continua nos testes de backend, que controlam o
mundo inteiro.

⚠️ Registro isto porque a tentação, no vermelho, é mexer na regra para o teste passar.

**3. E a recusa é um 422, que o vigia da suíte trata como falha.** Existe `janela.esperandoFalha`
exatamente para isso — dizer que a próxima falha é esperada, em vez de baixar o vigia. Usá-lo é a
diferença entre um teste que aceita erro e um que **prova** que o erro certo aconteceu.

### Verificação

1195 testes verdes (3 novos) e 10 suítes e2e verdes, com quatro asserções novas no Quartel — incluindo
a que prova que a tela diz **os dois lados da revogação do §01**: fora de guerra, inviolável.

---

## D-205 — O saque total da zona conquistada, e o teto de estoque que o butim atravessa

A decisão do usuário estava tomada desde o D-202 e nunca virou código: **a zona conquistada em guerra
federativa é totalmente saqueável, revogando o D-66/D-107 nesse contexto.** Esta a entrega.

### ⚠️ A medição contra a produção mudou o desenho, outra vez

Antes da primeira linha, como em toda ativação desta sessão:

| medida | produção, hoje |
|---|---|
| zonas ocupadas no mundo | **1** |
| estoque nela | **34.438** (31.358 bruto + 2.450 refinado + 630 minerais) |
| capacidade do Depósito dela | **500** (nível 1) |
| protegido / exposto | **500 / 33.938** |

Três coisas caíram dessa tabela, e nenhuma eu teria acertado no papel.

**1. O Depósito já não protegia quase nada.** No nível 1 ele guarda 1,4% do que a zona tem. Revogar a
proteção em guerra custa, hoje, **500 unidades** — a mudança é grande no desenho e minúscula no
mundo. É bom saber antes, não depois.

**2. A conquista já levava o estoque junto.** A zona muda de dono na vitória, e o que não foi saqueado
fica no depósito — de uma zona que agora é do atacante. O que o saque total muda é **onde o recurso
cai**: direto na colônia, e não parado na zona recém-tomada, à espera de veículo e vulnerável a
reconquista. A justificativa registrada no D-202 — *"retirar sempre, acumular nunca"* — já valia antes
desta decisão; ela morde de verdade é no **cerco**, que não toma a zona. Registro porque o argumento
não é o que eu tinha escrito.

**3. `bps = 10000` não é saque total, e o teste prova.** Com tudo dentro da capacidade o exposto é
zero, e 100% de zero é zero: a proteção sobreviveria inteira à guerra. O que muda é a **base** da
conta (`estoqueTotal()` em vez de `exposto()`), não a porcentagem. `saqueDetalhado()` ganhou
`$ignorarDeposito`, e não um `$bps` maior.

### ⚠️ O butim atravessa o teto de estoque da colônia — e isso foi escolhido, não esquecido

`TetoDoEstoque` está ligado desde o D-191, mas ele governa a **produção**: quem credita saque é
`ResolverCombates::saquear()`, com `increment()` direto, e sempre foi assim. Com o saque total a
diferença deixou de ser teórica:

- a maior folga de `metal_bruto` entre as 29 colônias é **14.663**;
- o saque total daquela zona entrega **31.358** só de bruto.

**Um saque total estoura o teto de todas as 29.** Mantido de propósito, e é a opção que não destrói
nada (§6.6/§14: o jogador perde oportunidade, nunca estoque): a colônia recebe o butim inteiro e passa
a produzir **zero** daquele recurso até gastá-lo, porque `espacoLivre()` devolve `max(0, …)`. O freio é
o próprio espólio — quem leva mais do que cabe paralisa a própria mina.

A alternativa, travar o saque no teto, deixaria o resto na zona (que numa conquista já é do atacante,
logo não freia nada) e no cerco viraria **escudo grátis para o defensor**: bastaria o atacante estar
cheio para o saque ser zero.

### O que NÃO mudou

- **O cerco de zona continua nos 30% do exposto**, em guerra ou fora dela. A decisão fala da zona
  **conquistada**, e o §27.8 declara a hierarquia: quem quer tudo invade e toma o território; quem
  cerca leva uma fatia e vai embora. Estender o saque total ao cerco apagaria a diferença entre os
  dois ataques;
- **fora de guerra federativa nada muda** — e este é o caso de todo o mundo hoje, que tem uma
  federação só.

### A guerra é conferida no instante da VITÓRIA, não no despacho

Entre a marcha e a chegada uma guerra pode acabar (as 7 dias) ou uma federação pode mudar. Quem chega
depois da paz saqueia como em tempo de paz. É a mesma regra de instante que os modificadores de evento
usam — e o defensor é o **dono atual** da zona, não o `defender_colony_id` do combate, porque o §27.10
deixa dois exércitos marcharem sobre a mesma zona.

### `EmGuerra`: a quarta cópia da mesma pergunta

"Estas duas estão em guerra?" já existia inline em `AtacarColonia`, no `WarController` e, privada, na
`Neutralidade`. Virou classe. Se a definição mudar — uma trégua por evento, uma guerra suspensa — quem
tivesse a cópia esquecida responderia o contrário das outras, e o jogador veria a mesma guerra existir
numa tela e não existir na outra.

Ela lê a federação **do banco**, não do modelo em mãos: o combate marcha por horas, e o erro é caro nos
dois sentidos — saquear tudo de quem já saiu da guerra, ou proteger quem já entrou nela.

### As telas passam a dizer qual regime está valendo

Quatro lugares afirmavam *"só o que EXCEDE o Depósito pode ser saqueado"*. Em guerra isso é falso, e um
número de tela que **subestima** o risco é pior que nenhum, porque é com base nele que o defensor
decide não reagir. `MinhasZonas` e o Quartel passam a mostrar o estoque inteiro quando a regra é essa;
a tela da zona troca o parágrafo; o mapa diz as duas regras, porque não sabe se há guerra com o dono
daquela zona.

### ⚠️ Rodei `npx prettier --write` num projeto que não usa prettier

Cinco arquivos do frontend foram reformatados por inteiro — aspas duplas, ponto e vírgula, o oposto do
estilo da casa — e as minhas dez linhas de mudança sumiram dentro de mil. Não há `.prettierrc` nem
prettier no `package.json`; o `npx` baixou a ferramenta na hora e aplicou os padrões dela. Revertido
por `git stash` (a entrada **`prettier-acidental`** continua na pilha, e pode ser descartada) e as
edições refeitas à mão: o diff do frontend voltou a 54 linhas.

**Antes de formatar, confirmar que o projeto formata** — `npx` instala qualquer coisa sem perguntar.

### Verificação

`GuerraTest` com 5 testes novos, incluindo os dois controles que fazem a afirmação valer: federações
**em paz** saqueiam 500, e guerra **encerrada durante a marcha** saqueia 500. Sem eles, o teste do
saque total passaria por qualquer motivo.

---

## D-206 — Capitulação e tratado de paz, e três coisas que existiam sem poder ser usadas

O §8 da guerra federativa publica **três** jeitos de uma guerra acabar — *"prazo, capitulação ou
tratado de paz"* — e só o prazo existia. Esta fatia entrega os outros dois, que eram o que restava da
A2.10 fora a fórmula do ranking.

### As duas saídas, e por que são diferentes

| | quem propõe | quem responde | o que muda de mãos |
|---|---|---|---|
| **capitulação** | quem quer sair | **o vencedor, escolhendo o espólio** | uma zona **ou** Fert$ do fundo |
| **tratado de paz** | qualquer um dos dois | o outro, aceitando ou recusando | **nada** |

**Capitular é oferecer sem saber o preço**, e isso é a decisão 9 à letra: *"o vencedor escolhe"*.
Abrir a capitulação **é** consentir que o outro escolha — não há um terceiro passo em que o derrotado
aprova o que lhe cobraram. O que protege quem se rende não é poder recusar: é o preço ser
**estruturalmente limitado** a exatamente uma zona ou exatamente o valor publicado. O vencedor escolhe
qual, nunca quanto.

**E o vencedor não pode recusar uma rendição.** Não existe `recusar()` na `Capitulacao`, e existe no
`TratadoDePaz`. Recusar uma paz é querer continuar lutando, que é posição legítima; recusar uma
rendição prenderia o adversário na derrota que o §9 existe para encurtar — *"não é dificuldade, é
tempo perdido"*. O que o vencedor pode fazer é **não responder**, e aí vale o prazo dos 7 dias.

### ⚠️ Três medições, e a maior não é sobre a capitulação

Antes do primeiro número, contra a produção:

- **o fundo da única federação tem 0,00 F$.** Declarar guerra custa **500 F$ do fundo** (D-193): hoje
  **ninguém no mundo consegue declarar guerra**, e isso não é limitação da capitulação — é da fase
  inteira. Estava invisível porque nada obrigava a olhar o saldo;
- colônia mais rica: 1.350 F$; mediana: 75,68 F$;
- por isso o preço padrão da capitulação é **os mesmos 500 F$ da declaração**, e não um número novo:
  capitular custa o que custou declarar. É simétrico, é um valor que o jogo já publica, e não inventa
  uma âncora que ninguém poderia conferir.

**E leva-se o que houver.** Fundo mais pobre que o preço não bloqueia a rendição: o vencedor recebe
menos e a guerra acaba do mesmo jeito. Bloquear por pobreza é a armadilha que o §9 nomeia.

### Quatro decisões de desenho que o GDD não tinha como tomar

1. **A guarnição da zona cedida volta para casa, não morre.** Na conquista os robôs do derrotado são
   destruídos porque não têm para onde recuar — houve batalha. Aqui não houve: apagá-los cobraria um
   segundo preço que ninguém combinou, por cima do único que a decisão 9 autoriza.
2. **`ZoneEvent` do tipo `cedida`, e não `conquistada`.** O ranking conta zonas *conquistadas*
   (§27.13), e chamar de conquista uma zona entregue à mesa deixaria duas federações amigas encenarem
   uma guerra e trocarem zonas para subir no ranking. Mas `cedida` **entra** na reconstrução do tempo
   de controle: a posse mudou de verdade, e ignorá-la faria o relógio continuar a correr para quem já
   não tem a zona.
3. **`termina_em` não se antecipa.** O cooldown do par (§10) conta a partir dele. Antecipá-lo faria de
   capitular o jeito barato de zerar a proteção contra assédio e ser declarado de novo no dia
   seguinte. Quem capitula compra o fim da guerra, não o fim do que vem depois dela.
4. **Zona sob combate não se entrega.** Dois donos disputariam o mesmo território no mesmo instante, e
   o resolvedor leria um dono que mudou debaixo dele.

### ⚠️ Três coisas que existiam e ninguém podia usar

Achadas por estarem no caminho, não por busca:

**1. Os seis parâmetros da guerra federativa não tinham como ser mudados.** A migration do D-193 diz,
por escrito, que eles moram em `war_settings` para *"mudar sem deploy"* — e nem o `fillable` do modelo
nem o painel do operador tinham os campos. Só mudavam por SQL à mão no banco de produção, que é o
oposto do prometido. Os seis (duração, cooldown, custo em Fert$, custo em Nióbio, carência da
neutralidade e o novo preço da capitulação) passam a existir de verdade.

**2. O custo em Fert$ da declaração saía do fundo sem deixar rastro.** Só o Nióbio era lançado; os
500 F$ eram debitados e não apareciam em extrato nenhum, com o §18 prometendo o contrário. Não dava
para lançá-lo antes: `federation_ledger.resource_type` era NOT NULL e Fert$ não é recurso. O D-114
tinha registrado a condição para abrir a coluna — *"quando o fundo em Fert$ tiver mais de um
movimento"* —, e agora são três movimentos. A coluna abriu.

**3. E a inserção passava por fora do próprio livro.** `DeclararGuerra` gravava por
`DB::table()->insert()`, que não dispara o `creating` do modelo — logo não passava pela validação de
`TIPOS` (o tipo `debito` que ele usa **nunca esteve na lista**) nem pela garantia de append-only.
Passou a gravar pelo modelo.

### ⚠️ E um teste que eu mesmo enfraqueci na mesma sessão

A asserção da neutralidade em guerra (D-204) usava `/no meio de uma guerra|capitula/i`. O botão
**"Capitular"** que esta fatia pôs na mesma tela passou a casar com a alternativa `capitula` — e a
asserção ficava verde **sem esperar a recusa chegar**. O e2e seguia adiante com a requisição ainda no
ar e clicava num botão ainda desabilitado; o `click` do Puppeteer num botão desabilitado **não faz
nada e não reclama**, então a reprovação aparecia três linhas depois, longe da causa.

Duas correções: o regex passou a exigir a frase que só existe na recusa, e os cliques esperam
`:not([disabled])`. **Um teste que passa por causa de um texto vizinho não afirma nada** — e o texto
vizinho aqui fui eu quem escreveu, uma fatia depois.

### Verificação

1211 testes verdes (11 novos) e 10 suítes e2e verdes, com sete asserções novas na Capital. A migration
foi aplicada **e revertida** contra o MariaDB de dev antes de qualquer teste — o `artisan test` roda
em SQLite, e o verde dele não prova DDL.

---

## D-207 — O ranking federativo é Elo, e a soma zero é o argumento

A decisão 10 era a última peça da A2.10: *"a fórmula do ranking segue como desenho próprio"*. O §14
diz o que ele deve fazer — *"mede guerras travadas, não guerras vencidas"*, e *"considerar a diferença
de força premia enfrentar quem é páreo"* — e não publica conta nenhuma.

### ⚠️ A medição que mudou o critério de escolha

| | |
|---|---|
| combates já resolvidos na história do jogo | **0** |
| zonas já conquistadas | **0** |
| guerras federativas já abertas | **0** |
| colônias com exército | **0** (as 20 unidades do mundo são guarnição de zona) |
| linhas do ranking existente com `geral > 0` | **0 de 29** |

**O ranking de guerras que existe desde o D-128 é uma tabela de 29 zeros**, e vai continuar sendo:
com todo fundo em 0 F$ e a declaração custando 500 F$, nenhuma guerra pode começar.

Isso muda a pergunta. Não é *"qual fórmula descreve melhor o que acontece"* — nada acontece, e nenhuma
seria validável contra dados. É **qual resiste a ser fraudada**, porque essa é a única propriedade que
dá para julgar sem dados.

### As três propostas, e o que separou

| | prêmio por enfrentar forte | guerra encenada entre amigas |
|---|---|---|
| **A** pontos ponderados pela força | por peso inventado | **as duas ganham** |
| **B** Elo | **sai da fórmula** | **o par não ganha nada** |
| **C** território × contestação | nenhum | as duas dobram o território |

**Escolhido B.** O argumento não é elegância: Elo é **soma zero**, então o ataque da decisão 11 — duas
federações amigas guerreando entre si para subir juntas — não produz nada líquido para o par. É trava
estrutural, não uma que alguém precise vigiar. Nas outras duas, a fraude paga.

    esperado = 1 / (1 + 10^((rating_do_outro − meu) / 400))
    rating  += K × (resultado − esperado)          K = 32, início 1.000

⚠️ **Sem piso**, e é consequência direta do argumento: um chão devolveria o ganho ao par encenado — o
perdedor pararia de cair e o vencedor continuaria a subir. O §12 proíbe perda permanente de
**território**, não de posição num placar; e um ranking que só sobe é contador de tempo de jogo.

⚠️ **O delta do alvo é o simétrico do delta do declarante, e não uma segunda conta.** Arredondar cada
lado em separado faria a soma dar ±1 — e numa guerra encenada repetida o resíduo viraria ganho,
destruindo exatamente a propriedade que motivou a escolha.

### Os três desfechos

| fim da guerra | resultado |
|---|---|
| capitulação | 1 e 0 — quem aceita venceu |
| tratado | 0,5 e 0,5 — a paz não move espólio, e premiar quem propôs premiaria propor |
| prazo | pelo **saldo**: zonas tomadas; empatando, o saque; empatando, empate |

Sete dias sem que nenhum dos dois tirasse nada do outro **é** empate. Inventar desempate ali premiaria
quem declarou por ter declarado.

### `combats.war_id`: o pré-requisito que as três propostas dividiam

**Nenhuma batalha era atribuível a uma guerra.** O combate guarda as duas colônias, nunca as
federações. Sem a marca, o saldo por prazo não teria resposta e a única guerra "de verdade" — a que
ninguém encerra negociando — seria a única que não contaria. Carimbado no **despacho**, nos dois
pontos de ataque: é aí que se sabe sob que guerra o exército marchou, e uma guerra que acabe no meio
da marcha não deve reescrever a história do ataque.

### ✅ E o sexto sub-ranking do §27.13 enfim tem de onde sair

*"Guerras Vencidas (Federação)"* (peso 10) estava vazio desde o D-128 porque **não existia guerra
federativa**. O docblock registrou a pendência e recusou preenchê-la somando as vitórias dos membros —
seria duplicar o sub-ranking 2 num agregado sem base.

A A2.10 criou o conceito. Preenchido com o **rating da federação**, que é o que o próprio §27.13
aponta ao dizer que este sub-ranking é *"só no ranking de federações"*. **Com ele os pesos passam a
somar 100** — os cinco publicados somavam 90, e a recusa de renormalizar estava certa: não faltava
correção, faltava a sexta parcela.

Colônia sem federação fica com **zero**, e não com os 1.000 de partida: dar-lhe o rating inicial faria
um solitário empatar com uma federação que nunca perdeu uma guerra.

### ⚠️ Um `$fillable` esquecido, e o teste que passou por causa dele

`rating_guerra` ficou fora do `$fillable` de `Federation`, e o `update()` do `RatingFederativo` foi
descartado **em silêncio** — a quinta vez nesta fase (`max_aliadas`, `operadores`, `fert_micro`,
`war_id`).

O que vale registrar não é o esquecimento: é que **um dos testes do Elo passou assim mesmo**. Ele
montava o cenário com `update(['rating_guerra' => 800])` — também descartado —, então comparava
`1000 − 800 = 200` contra `1000 − 1200 = −200`, e `200 > −200` é verdade. **O cenário quebrado
satisfazia a asserção.** Só dois dos três testes reprovaram, e o terceiro teria ficado verde para
sempre defendendo uma fórmula que nunca rodou.

O mesmo vale para a soma zero: *"a soma não mudou"* é verdade trivial num mundo onde nada aconteceu.
Foi preciso acrescentar um controle — o rating **mudou** — antes da igualdade.

### Verificação

1217 testes verdes (6 novos) e 10 suítes e2e verdes, com duas asserções novas — a que importa afirma
que **a tela diz que o rating cai**, porque a soma zero precisa ser sabida antes de declarar, não
descoberta na primeira derrota. Migration aplicada e revertida contra o MariaDB antes dos testes.

---

## D-208 — A restauração de backup foi testada, e quatro coisas nela estavam prontas para dar errado

Oito backups existiam e **nenhum jamais tinha sido restaurado**. A pendência estava registrada há
semanas com a frase certa: *backup que ninguém restaurou é hipótese.* Testada agora, de ponta a ponta,
num banco descartável — nunca sobre produção. O procedimento verificado está em `docs/restauracao.md`.

### A boa notícia primeiro

**Restaura.** Os dez arquivos passam no `gzip -t`; o manual mais recente sobe em **4,2 s**; a seção do
`fertwaysbd` do diário sobe em **4,6 s**; e as contagens batem com a produção linha a linha — 29
colônias, 33 usuários, 457 construções, 754 recursos, 77 zonas. O `ledger` difere em 112 linhas, que é
o jogo tendo andado desde as 03:00.

A cópia externa no Drive existe e tem **três** dias, com bytes idênticos aos locais. E o
`--master-data=2` está mesmo lá: `mysql-bin.000065:28540974` no topo do dump — a âncora da recuperação
pontual que a documentação prometia e que ninguém tinha conferido.

### ⚠️ 1. Extrair um banco do dump `--all-databases` escreve na PRODUÇÃO

O uso que se vai querer quase sempre é *"restaurar o FERTWAYS ao lado, para olhar"*. A seção extraída
traz `CREATE DATABASE fertwaysbd` e **dois** `USE \`fertwaysbd\`;`.

**`mysql fertways_restore_teste < secao.sql` ignora o banco da linha de comando e escreve na
produção.** Não há aviso, não há erro: o `USE` simplesmente vence.

O runbook agora manda tirar essas linhas e **conferir que sobraram zero** antes de executar.

### ⚠️ 2. `grep -v` truncou 2 MB em silêncio

Filtrar as três linhas com `grep -vE` derrubou **2 MB de um arquivo de 5 MB**. Três linhas de 160
bytes ao todo.

A causa: o dump tem linhas de até **1 MB** (INSERTs em bloco), e o `grep` as cortou. O arquivo
resultante continuava sendo SQL válido, restaurava **sem erro nenhum**, e vinha pela metade.

Só apareceu porque a conta não fechou — 5,0 MB viraram 3,0 MB ao remover três linhas curtas. Refeito
com `awk`, a aritmética fecha ao byte: `5.182.507 − 160 = 5.182.347`.

**A regra que fica: conferir bytes, não confiar no "parece certo".** Um backup restaurado pela metade
é pior que um backup que falha, porque parece sucesso.

### ⚠️ 3. Sem o preâmbulo, morre na primeira chave estrangeira

`FOREIGN_KEY_CHECKS=0` está no cabeçalho do arquivo **inteiro**, antes do primeiro banco. A seção
extraída sozinha falha na primeira tabela com FK — as tabelas vêm em ordem alfabética, e `auctions`
referencia `colonies`, que ainda não existe.

Isto **não é defeito do backup**: o dump foi feito para ser restaurado por inteiro, e por inteiro ele
funciona. É defeito de quem extrai — eu — e por isso o runbook monta preâmbulo + seção + rodapé.

### ⚠️ 4. `DB_DATABASE=` não vence o cache de config, e eu testei a produção achando que testava o backup

A primeira verificação *"a aplicação lê o banco restaurado?"* devolveu 29 colônias e um ranking de 29
linhas. Números plausíveis, e **todos vindos da produção**: a árvore de deploy tem
`bootstrap/cache/config.php`, e com ele a `env()` do Laravel é ignorada por completo.

Foi leitura apenas, sem estrago. Mas o teste **não provava nada**, e era exatamente o formato de
"passou porque nada aconteceu" que esta sessão inteira vem perseguindo.

⚠️ É a mesma armadilha que o `tools/e2e.sh` documenta desde o D-27 — lá com consequência muito pior
(um `migrate:fresh` chegou a apagar o banco do jogo). A proteção é a mesma:
`APP_CONFIG_CACHE=<arquivo inexistente>`, e **confirmar `SELECT DATABASE()` antes de qualquer
conclusão**.

### E restaurar não devolve o jogo: falta migrar

O backup é do banco de ontem; o código no ar é o de hoje. A aplicação reprova contra o restaurado:

    SQLSTATE[42S22]: Column not found: 1054 Unknown column 'rating_guerra'

Com as duas migrations pendentes aplicadas, tudo passa a servir — ranking, teto de estoque, saque da
zona. **O procedimento é restaurar → migrar → servir**, e nenhum documento dizia isso.

Descoberto de quebra: `fertways@localhost` **não tem permissão em banco recém-criado**. Num desastre
real isso não aparece (restaura-se sobre `fertwaysbd`), mas num ensaio, sim.

### ⚠️ O buraco que o ensaio encontrou e não fechou

O backup manual mais recente é de **2026-08-03 17:52**, antes do cerco de colônia. **As três fases
seguintes — D-205, D-206 e D-207 — subiram sem backup manual prévio.** O diário das 03:00 cobre, com
até 24 h de perda possível.

O hábito de tirar um backup antes de cada fase existia e se perdeu **sem que nada avisasse**. Não é
decisão minha reinstaurá-lo como regra; fica registrado que ele parou.

---

## D-209 — O backup vira passo do deploy, e o deploy morre se ele falhar

O D-208 achou o buraco e não o fechou: o hábito de tirar um backup manual antes de cada fase existia,
parou **sem que nada avisasse**, e três fases subiram cobertas só pelo diário das 03:00 — até 24 h de
perda possível. Hábito que depende de alguém lembrar não é proteção; passo de script é.

Todo deploy que toca o backend agora tira um dump de `fertwaysbd` **dentro da janela de manutenção** e
**antes do `migrate`**. As duas posições têm motivos distintos: dentro da manutenção porque o mundo não
pode andar entre o retrato e a migration que vai agir sobre ele; antes de tudo o mais porque **se o
backup falhar, o deploy não acontece**. O arquivo leva o sha que está sendo publicado, e por isso se
sabe depois a qual estado ele pertence.

### Backup que ninguém conferiu é hipótese — três perguntas, e as três importam

O gzip fecha? Tem tamanho de banco de verdade? Tem mesmo a tabela `colonies` lá dentro? Falhando
qualquer uma, o dump é apagado e nada é publicado. **Um dump vazio que passa no `gzip -t` é o pior tipo
de backup, porque parece que existe.**

Retenção de 20 arquivos (~600 KB cada). Contra desastre o recurso continua sendo o diário das 03:00;
este é outro — desfazer **uma** migration ruim, com o retrato do minuto anterior a ela.

### ⚠️ Dois defeitos meus no caminho, os dois achados antes de rodar

- `alvo` colidia com a variável do symlink, já conferida no topo do script.
- `grep -qm1` fecha o cano no primeiro acerto e mata o `zcat` com SIGPIPE. Sob `set -o pipefail` o
  pipeline sai **141 mesmo com a tabela presente** — e isso abortaria **todo** deploy dizendo que o
  backup está errado. Medido: 141 com `-qm1`, achou 1 com `-c`. A guarda escrita para proteger o
  deploy teria sido o que o derrubaria.

### Verificação

Rodado em produção duas vezes. O passo aparece no log —
`==> backup conferido: /home/fertways/backups/fertwaysbd-antes-a2f06c0-20260804-171121.sql.gz (619 KB)`
— e o arquivo que ele produziu foi **restaurado num banco descartável e comparado com a produção**:
89 tabelas, 29 colônias, 35.202 lançamentos, 111 migrations — **idênticos**.

É o laço que o D-208 abriu, fechado: o backup não é só tirado e conferido, é tirado por um passo
automático e **restaurado para provar que serve**.

---

## D-210 — A população estava no ar e não aparecia em tela nenhuma (A2.V2)

Primeira fatia da revisão visual da navegação. O roadmap lista oito itens para a A2.V2 — HUD global,
notificações, "desde sua última visita", alertas de produção, alertas militares, fila, pesquisa e
**população**. Medido antes de escolher por onde começar: só **fila** e **pesquisa** existiam na
camada de navegação.

### ⚠️ Uma mecânica ligada em produção, invisível para todo mundo

A população foi ativada no D-178 e governa três coisas desde então: o **teto habitacional**, os
**operadores** que cada construção e cada zona exigem, e o **consumo** da cesta.
`Populacao::estado()` existia desde o D-176 e devolvia exatamente os números de uma tela — e **nunca
teve consumidor**. Nenhuma rota a publicava; `grep -rl "populacao" ui/` não achava um arquivo.

Medido em produção:

| | |
|---|---|
| colonos no mundo | **565** |
| colônias **no teto habitacional** | **28 de 29** |
| colônias em déficit de operadores | 21 de 29 |
| colônias perdendo produção por escassez **agora** | **0** |

⚠️ **Os dois últimos números importam juntos.** Ninguém está perdendo produção hoje — a penalidade
por falta de operador na colônia ainda não foi ligada (está escrito no `ColonyTick`: *"NÃO faz,
ainda"*). Então isto **não é vazamento**, é **constraint invisível**: 28 colônias pararam de crescer
e não têm como saber, nem como saber que o remédio é subir a Estrutura de Sobrevivência.

É o oitavo caso do mesmo padrão nesta Alpha (`vehicles.level` sem rota, `EfeitosDaPesquisa` sem
consumidor, o `exposto` do Silo sem consequência…). A diferença é que aqui a peça parada era a
**tela**, não a regra.

### O que entrou

`GET /colony` passa a publicar `populacao`, e o HUD ganhou um painel. Três escolhas que não são
óbvias:

1. **O número em destaque é o DISPONÍVEL, não o total.** Total é curiosidade; sem gente livre não se
   ocupa zona nova nem se ergue o que exige operador, e é essa a pergunta que o colono traz.
2. **`ativo` viaja no payload.** Com a chave-mestra desligada a tela **se cala**, em vez de mostrar
   zeros — que um jogador lê como colônia morta. A distinção entre *"não há gente"* e *"esta regra
   não vale aqui"* é do servidor, não da tela.
3. **A tela diz "acima do teto" em vez de esconder.** O grandfathering do D-178 concedeu a muita
   colônia mais colonos do que a Estrutura abriga; o número não é erro, e quem o vê precisa saber
   que **não cresce mais** até subir o prédio.

### ⚠️ Editei a árvore de deploy por engano

Três edições saíram por caminho **relativo** a partir de `/home/fertways/deploy/fertways/backend`, e
foram parar na cópia que o Apache serve. A cópia de deploy tem de ser descartável — o próprio
`deploy.sh` aborta se ela tiver alteração local, então o estrago seria descobrir isso no deploy
seguinte, longe da causa.

Revertido por `git stash` depois de guardar as versões, e reaplicado na árvore de trabalho com
caminhos absolutos. **A ferramenta de edição, que usa caminho absoluto, acertou as três vezes; o
`python3` com caminho relativo errou as três.**

### ⚠️ E três suítes de e2e reprovaram em lugares diferentes, sem eu ter tocado nelas

Resumo, mobile e chat reprovaram em execuções consecutivas, cada uma passando na corrida seguinte. A
tentação era caçar o defeito no código novo. A causa era a **máquina**:

- **~10 Chromium órfãos** acumulados de execuções abortadas. `navegador.close()` **não mata** um
  browser com frame desanexado — que é exatamente o estado em que ele é chamado, no `finally` de uma
  suíte que estourou —, e o `process.exit()` seguinte deixa o processo vivo. Cada suíte reprovada
  tornava a próxima mais provável de reprovar;
- e a suíte mobile fecha painéis com `assentar()`, um `setTimeout` de **300 ms**. Com a máquina
  carregada o painel ainda cobre o botão que o passo seguinte procura. O próprio repositório já tinha
  escrito essa lição em `resumo.e2e.mjs` — *"espera o elemento SUMIR, em vez de dormir 300 ms"* — e
  ela não tinha virado ferramenta.

Duas correções: `fecharNavegador()` tenta o fim gracioso com prazo e **mata o processo** se ele não
vier; `esperarSumir()` substitui o sono nos quatro fechamentos de painel da suíte mobile.

⚠️ **E um erro de leitura meu no meio:** contei os órfãos com `pgrep -c -f "chrome|chromium"`, que
**casa com o próprio comando de busca**. O "2 restantes" que eu vinha reportando era o `pgrep` a
contar-se a si mesmo — `ps` mostra zero. O número inicial de 12 era real; o piso de 2, não.

### Verificação

1220 testes verdes (3 novos, sobre o **contrato da rota** — é o que impede a tela de voltar a ser
peça parada) e as 10 suítes e2e verdes numa máquina limpa, com três asserções novas provando que a
população **aparece**, que o destaque é o disponível e que o teto está ao lado.

---

## D-211 — A faixa de avisos, e a medição que cortou dois avisos antes de eles existirem

Segunda fatia da A2.V2. Os sinais do jogo estavam espalhados por seis telas: o combate só no
Quartel, o cerco só em Minhas Zonas, a manutenção atrasada só ao abrir a zona, a vaga do Laboratório
só na Pesquisa. Quem não abrisse a tela certa não ficava sabendo — e o §1.1 promete um jogo que não
exige login constante, o que só é honesto se, ao voltar, o que importa estiver à vista.

### ⚠️ A medição decidiu o desenho, e o mais importante foi o que ela mandou NÃO construir

Contadas as 29 colônias de produção, quantas disparariam cada candidato:

| candidato | dispara para | decisão |
|---|---|---|
| estoque cheio (produção parada) | **1** | ✅ atenção |
| fila de obras vazia | **3** | ✅ oportunidade |
| laboratório ocioso | 15 | ✅ oportunidade |
| combate / cerco | 0 | ✅ urgente |
| ~~população no teto~~ | **28** | ❌ cortado |
| ~~sem colonos livres~~ | 19 | ❌ cortado |

**Um aviso que 28 de 29 veem sempre deixa de ser aviso e vira moldura** — e, pior, ensina o jogador a
ignorar a faixa inteira, levando junto o aviso de cerco, que é o único que custa caro. Os dois
cortados já moram no painel de População (D-210), que é onde a informação pertence.

**Aviso não é tudo o que é verdade; é o que é verdade e raro.** Há um teste guardando isso, porque a
tentação de acrescentar é permanente e o custo é invisível.

### ⚠️ E o número do laboratório que eu tinha medido estava errado

Medi "15 de 29 com laboratório ocioso" filtrando `status = 'em_curso'` — valor que **não existe**: o
`Pesquisa\Vagas` usa `pesquisando`. A consulta devolvia zero para todo mundo, e o aviso teria
disparado para toda colônia com Laboratório, com o número parecendo confirmá-lo.

Refeito com o valor certo: **15 colônias têm Laboratório e as 15 estão paradas** — a tabela
`colony_technologies` está vazia, coerente com o "0 de 8 tecnologias pesquisadas" que o GDD do estado
já registrava. O número final é o mesmo por acaso; o filtro estava errado.

### Três severidades, e elas ordenam a lista

`urgente` destrói valor agora (sob ataque, zona cercada). `atencao` desperdiça (estoque cheio,
escassez, manutenção atrasada). `oportunidade` é ganho não colhido (fila parada, laboratório ocioso).
A ordem da lista é a ordem de agir — sem isso, a vaga do Laboratório podia aparecer acima de uma zona
sendo estrangulada.

**Estar sob ataque é urgente; atacar é só atenção** — quem marcha escolheu marchar.

**Silêncio é estado válido:** sem avisos, a faixa some. Um "está tudo bem" permanente ocuparia espaço
para não dizer nada e faria o jogador parar de olhar justamente para onde a má notícia vai aparecer.

E o *"desde sua última visita"* ganhou como ser **reaberto**: ele se convida no login e, até aqui,
fechado por engano ia embora com a janela — num resumo desenhado (§5.1) para quem volta depois de
dias.

### ⚠️ Verificação incompleta, e é preciso dizer

**1226 testes verdes** (6 novos) e o typecheck limpo. **O e2e não foi confirmado nesta fatia**, e não
por causa dela: a suíte passou a reprovar **no login**, migrando de suíte a cada corrida (telas,
telas, resumo) com três erros diferentes — frame desanexado, frame desanexado, timeout de 30 s.

Fiz o experimento decisivo: **desmontei a faixa e rodei de novo — reprovou igual**. Não é o código
desta fatia. A corrida imediatamente anterior (D-210) tinha ficado 10/10 verde na mesma máquina.

⚠️ **E li dois screenshots velhos pelo caminho.** As suítes gravam a foto da falha dentro de um
`try{}catch{}`; com o frame desanexado a gravação falha **em silêncio**, e o arquivo antigo fica.
Analisei `/tmp/e2e-resumo-falha.png` duas vezes concluindo que painéis não renderizavam — a imagem
era de **31 de julho**. Conferir o `mtime` de um artefato antes de tirar conclusão dele não é
paranoia: sem isso, um arquivo de quatro dias atrás dita o diagnóstico.

**Não publiquei.** O backend está coberto por teste; o frontend, não — e a regra da casa (D-200) é não
publicar com verificação vermelha. Fica commitado e fora do ar até a suíte rodar limpa.

---

## D-212 — A suíte de e2e não era flaky por azar: cinco causas, quatro corrigidas

O D-211 ficou fora do ar porque a suíte reprovava **no login**, mudando de lugar a cada corrida com
erros diferentes. Isso parece azar de máquina e é a aparência mais enganosa que um teste pode ter:
convida a repetir até passar. Investigado com evidência, eram causas somáveis.

### 1. ⚠️ A API do e2e atendia UMA requisição por vez — e ela mesma avisava

`/tmp/e2e-api.log`, que eu nunca tinha aberto em toda a sessão:

    WARN Unable to respect the `PHP_CLI_SERVER_WORKERS` environment variable
         without the `--no-reload` flag. Only creating a single server.

O jogo dispara **dez requisições** logo após o login. Servidas em fila, os tempos eram todos ~500 ms
— `/login` 501, `/avisos` 512, `/zones/minhas` 512, `/chat/pendencias` 500. Essa igualdade suspeita
**é** a assinatura da serialização, e estava no log desde sempre.

Com `--no-reload` e três workers: `/avisos` caiu de **512 ms para 0,15 ms**. Três, e não seis —
cada worker é um processo PHP, e a máquina tem 4 GB sem swap dividida com o MariaDB de produção.

### 2. ⚠️ `--disable-dev-shm-usage` estava a fazer o contrário do que devia

A flag existe para contornar `/dev/shm` minúsculo (64 MB, o padrão do Docker): manda o Chromium usar
`/tmp`, isto é, **disco**. Nesta máquina `/dev/shm` tem **1,8 GB com 236 KB em uso**. Estava a trocar
RAM ociosa por I/O de disco. Retirada.

### 3. ⚠️ O teto do heap do JS estava em 256 MB, e o renderer morria nele

`--js-flags=--max-old-space-size=256`, com o comentário *"o jogo inteiro cabe folgado em 256 MB"* —
verdade quando foi escrito, antes das cenas de Phaser da colônia e da Capital e do mapa 101×101.
Estourar o teto **derruba o renderer**, e o sintoma é exatamente `Target closed` **sem uma linha de
OOM no kernel**: não era o kernel a matar, era o V8 a desistir. Subido para 512 MB.

⚠️ Confirmei que não era OOM olhando o `dmesg`: as cinco mortes do dia eram de **outro serviço**
(`gerenciador-whatsapp`), e a última tinha sido às 15:10 — as minhas falhas foram às 17:5x.

### 4. ⚠️ O `entrar()` esperava a REDE, não o elemento

`page.goto(BASE, { waitUntil: 'networkidle2' })` devolve o controlo quando a rede aquieta — que pode
ser antes de a página estar montada. O `page.type` seguinte agarrava um frame que se desanexava, e o
erro caía **sempre nesta linha**, mudando de suíte conforme o tempo. Passou a esperar o campo existir
e, depois do clique, a esperar o campo **sumir**.

O `resumo.e2e.mjs` já tinha metade desta lição escrita no corpo desde sempre.

### 5. ⚠️ E cada suíte reprovada deixava um Chromium órfão

`navegador.close()` não mata um browser com frame desanexado — que é o estado em que ele é chamado,
no `finally` de uma suíte que estourou. Doze deles acumularam-se e passaram a estrangular as corridas
seguintes: **cada falha tornava a próxima mais provável**, e as falhas mudavam de lugar. Corrigido
com `fecharNavegador()`, que mata o processo se o fim gracioso não vier. Órfãos hoje: zero.

⚠️ Contei-os a primeira vez com `pgrep -c -f "chrome|chromium"`, que **casa com o próprio comando**.
O piso de "2 restantes" que reportei era o `pgrep` a contar-se a si mesmo.

### O que isto deu, e o que NÃO deu

| corrida | suítes verdes |
|---|---|
| antes | 0 — morria na 1ª ou 2ª |
| com a API paralela | 2 |
| sem a flag de shm | 5 |
| com o heap em 512 | **10 — verde completo** |
| confirmação | 5 — o Chromium morreu no Mercado |

⚠️ **Não está resolvido.** Uma corrida verde depois de muitas vermelhas pode ser sorte, e a corrida
de confirmação provou que ainda é: o browser morre no Mercado, sem OOM, com 1,5 GB livres. O que se
pode afirmar é que as quatro causas acima **eram reais e foram medidas** — não que a instabilidade
acabou.

O que eu investigaria a seguir, com a máquina em paz: a suíte do Mercado é a mais longa (334 linhas)
e reusa o mesmo browser do princípio ao fim. Um renderer que acumula seis cenas de Phaser é o
próximo suspeito, e a medida seria o `rss` do processo ao longo da corrida — não mais uma tentativa.

---

## D-213 — Regra de jogo não é erro de servidor, e o log de produção não tinha rotação

Achado numa auditoria de estado, não numa queixa: o `laravel.log` de produção estava com **90 MB**,
arquivo único, `LOG_LEVEL=debug`, **sem rotação nenhuma**. E ~79 linhas por dia dele eram uma só
exceção, em nível ERROR:

    production.ERROR: Falta energia para esta viagem. {"userId":12}

A cada ~20 minutos, ininterruptamente desde pelo menos 01/08, vinda de `DespacharVeiculo.php:795`
**por rota HTTP** — não pelo tick. O ator é um **colono simulado** (`sim_3xevap@bots.fertways.local`,
colônia HAL9000) repetindo um despacho que não tem energia para pagar.

### A menor das duas: um arquivo que só cresce

Corrigido no `.env` de produção — `daily`, 14 dias, `info`. Como o `.env` **não é versionado**, o
aviso ficou no `.env.example`, que é o único lugar onde ele sobrevive a uma reinstalação. O disco
está em 77% (22 GB livres), então isto nunca foi urgente; era só uma dívida que não parava.

### A maior: o nível mentia sobre a gravidade

`DomainRuleException` é **o jogo funcionando**. "Falta energia", "não cabe no depósito", "a zona não
é sua" são respostas da regra a um pedido inválido, e viram **422 com código estável** para o front.
O Laravel não loga `ValidationException`, e é o mesmo caso.

Gravá-las como ERROR mentia duas vezes: um 422 esperado ficava com a mesma cara de um 500, e o
volume escondia o erro de verdade no meio do ruído. Quem precisa contar tentativa fracassada é a
**telemetria (D-163)**, que deriva do ledger e sabe de quem é a tentativa. Log de aplicação não é
lugar de métrica de gameplay.

### ⚠️ O defeito meu, e quem o desmentiu

Escrevi primeiro como `report(): bool { return false; }` na própria classe. **Está errado, e o nome
engana:** devolver `false` ali significa *"siga com o tratamento padrão"* — ou seja, **loga assim
mesmo**. É o oposto do que se lê. O certo é `dontReport()` no `bootstrap/app.php`.

Quem desmentiu foi o teste, na primeira corrida: *"should be called exactly 0 times but called 1
times"*. É por isso que ele testa **os dois lados** — a regra não vai para o log, **e a exceção
inesperada continua indo**. Um `dontReport` largo demais seria pior do que o barulho: o sintoma
apareceria só no dia em que algo quebrasse de verdade, com a produção emudecida.

1229 testes verdes (eram 1226; os três novos são estes).

### O que isto deixou em aberto

Há **21 colonos simulados entre os 33 usuários** de produção, e **20 das 29 colônias** são deles —
o mundo humano é de **12 contas e 9 colônias**. O `ROADMAP_ALPHA2.md` diz, na A2.11, que os bots são
"um programa externo, em servidor e banco próprios (`staging.tars.art.br`)". Eles estão rodando
contra a **produção**, com token ativo no minuto desta medição. Nada foi mexido: se é deliberado,
o roadmap é que está desatualizado; se não é, é decisão do usuário, não minha.

---

## D-214 — Derrubei a produção editando o .env, e o deploy inteiro disse "sucesso"

Eu quebrei a produção nesta sessão. Fica registrado com o detalhe todo, porque o modo como o erro
passou por **cinco guardas** é mais instrutivo do que o erro em si.

### O que fiz

Editei `/home/fertways/deploy/fertways/backend/.env` para dar rotação ao log (D-213). A edição em si
estava certa — o arquivo ficou correto, `DB_CONNECTION=mysql`, `DB_DATABASE=fertwaysbd`, conferido
depois linha a linha. **O que eu esqueci foi o `chown`.** O arquivo ficou `root:root 600`.

É a armadilha que a memória do projeto já registrava para arquivos de código ("edito como root e
apodreço o dono dos arquivos"), e que eu venho aplicando a cada commit desta sessão. **Não a apliquei
ao `.env`, porque `.env` não é versionado e não passa pelo `git status`** — nada me lembraria.

### O que acontece quando o Laravel não consegue ler o .env

Não é um erro. É um **fallback silencioso**: sem `.env`, `config/database.php` cai no default do
framework, que é `DB_CONNECTION=sqlite`. Daí em diante, em cascata:

1. `composer install` roda `ComposerScripts::postAutoloadDump`, que **apaga o config cache**. A
   partir daqui, tudo depende de ler o `.env` — que o `fertways` não lê.
2. `artisan migrate --force` **não falha: ele cria**. Fez um `database/database.sqlite` do zero e
   aplicou as **111 migrations** nele, em 40 ms, com status 0 e a saída bonita de um deploy normal.
   Foi a linha `rating_federativo ... DONE` que me fez desconfiar — uma migration de ontem não tinha
   por que rodar hoje.
3. `artisan config:cache` **assou o default sqlite** em `bootstrap/cache/config.php`, e o php-fpm
   passou a servir dali.
4. A produção ficou servindo **um mundo vazio** por ~11 minutos. `GET /central/estatisticas`
   devolvia `{"colonos":0,"colonias":0,...}`.

### ⚠️ As cinco guardas que passaram, e por que cada uma passou

O `deploy.sh` é um script cuidadoso, com guardas escritas por cicatriz. **Nenhuma pegou:**

| Guarda | Por que passou |
|---|---|
| symlink aponta para o deploy | apontava mesmo — a pergunta era outra |
| backup antes do migrate | **funcionou** — o `sed` que lê `DB_DATABASE` roda como **root**, então leu o `.env` perfeitamente e o dump saiu certo, de `fertwaysbd`, 620 KB, conferido nas três perguntas |
| `APP_DEBUG` está off | "off" — porque o default do framework é `false`. A guarda mediu o default, não o `.env` |
| opcache executa a árvore de deploy | executava mesmo, e o `index.php` era o certo |
| fumaça 200/401 | o `200` é o front estático; o `401` sai do middleware de auth **antes de tocar o banco** |

A lição é dura e vale mais do que a correção: **as duas guardas mais fortes do script (o backup e a
fumaça) mediram a coisa certa pelo caminho errado.** O backup passou justamente porque root lê o que
o `fertways` não lê — a assimetria que causou o incidente é a mesma que escondeu o incidente. E a
fumaça nunca tocou uma linha do banco.

### O que restou, e o que não restou

**Nada foi perdido.** O MariaDB não recebeu uma escrita sequer durante a janela: 33 usuários, 29
colônias, 35.398 lançamentos, iguais antes e depois. Os três usuários que apareceram no SQLite eram
as contas de sistema (Capital, Missões, Federação) criadas pelas próprias migrations — **nenhum
jogador se cadastrou na janela**. O arquivo intruso foi retirado da árvore.

O log registrou o sintoma exato, e por sorte antes de eu ter mudado o nível: duas linhas de
`MissingAppKeyException: No application encryption key has been specified` às 19:24:44 UTC — o
`APP_KEY` também mora no `.env`, e ele também sumiu.

Correção: `chown fertways:fertways .env`, `config:cache` de novo, `systemctl reload php84-php-fpm`.

### As duas guardas novas

**A — o `fertways` consegue ler o `.env`?** Cobre a causa exata, e é a mais barata do script. Roda
antes do backup.

**B — o banco que a aplicação RESOLVE é o que o `.env` DECLARA?** Roda **antes do `migrate`**, que é
o passo caro: um `migrate` apontado para o lugar errado não reclama, ele cria o lugar errado. Compara
as duas pontas em vez de acreditar em uma.

As duas foram testadas reproduzindo o defeito, não por leitura. A guarda A aborta com o `.env`
`root:root`. A guarda B só vale com o config cache limpo — que é exatamente o estado em que o
`composer install` a deixa — e nessa condição ela resolve
`/home/.../backend/database/database.sqlite` contra um `.env` que declara `fertwaysdev`, e aborta.
Testada na árvore de trabalho, que não é servida.

### O que eu faria diferente

Editar `.env` de produção é a única operação desta sessão que **não passa pelo git** e por isso não
tem rede nenhuma. Se voltar a acontecer: `chown` no mesmo comando que edita, sempre.

---

## D-215 — A2.V3, primeira fatia: a colmeia sabia três estados, e dois já eram verdade no servidor

A cena da colônia distinguia **três** coisas: slot vazio, obra nova e construção erguida. Três, para
a tela que é o jogo inteiro.

Medido contra a produção **antes** de escolher o que desenhar — o método que o D-210 fixou —, dois
estados já eram verdade no servidor e não apareciam em lugar nenhum:

| estado | o dado existia? | na tela |
|---|---|---|
| **melhorando** | `upgrade_finish_at`, no payload desde sempre | **nada** — subir do 3 para o 4 era idêntico a estar parado no 3 |
| **travada pelo teto** (§14) | `TetoDoEstoque` | **nada** — 5 recursos no teto em 2 colônias no dia da medida |

O segundo é o que dói. O §14 promete que *"o jogador perde oportunidade, nunca estoque"*; **perder
oportunidade sem saber é perder as duas.** Gerador, Captação, Fazenda e Reator estavam de pé,
rodando e rendendo **zero**, e nenhuma tela dizia isso.

### O que NÃO foi inventado

O roadmap da A2.V3 também lista *"falta de energia"* e *"operadores"*. **Nenhum dos dois existe como
estado de construção**, e desenhá-los seria publicar regra que ninguém decidiu:

- **energia** não trava prédio nenhum. O saldo pode ficar negativo e o estoque trava em zero (D-20);
  o GDD nunca definiu construção parada por falta de energia.
- **operadores** são de **zona neutra** (D-184), não da colmeia. São da A2.V4.

Onde não dá para afirmar, `EstadoDaConstrucao` devolve `null` e a cena desenha o que sempre desenhou.
**Ausência de afirmação, nunca afirmação errada.**

### Duas armadilhas que o teste guarda

- a **Indústria Siderúrgica** declara em `producao_hora_json` o que **consome** (D-82). O estado
  herda a correção que o controller já fazia — repetir a regra no serviço criaria duas verdades;
- o **Biocombustível** não passa pelo teto geral: quem trava a Destilaria é o **Tanque**
  (§21.9/D-131). Perguntar só ao teto geral diria "tem espaço" com o tanque cheio, justo na
  construção cuja parada é mais confusa.

### O selo, e por que ele tem glifo

O deck proíbe anunciar estado **só por cor**: a paleta é quente por identidade e o vermelho fica a
14° do `rust` da marca. O glifo é o **segundo canal**. E `ember` pinta o **fundo** — 1,62:1 como
texto, 8,71:1 como fundo com letra `ink`.

O estado entra também no **`aria-label`** do botão de cada slot: o selo é pixel de canvas, e leitor
de tela não o alcança. Seria informação exclusiva de quem enxerga — o erro que o D-59 evitou nos
cliques, repetido na informação.

### ⚠️ Sem animação, e por escrito

A A2.V3 pede "animações sutis", e uma pulsação no selo de travada era a candidata óbvia. Ficou de
fora: `desenhar()` reconstrói a árvore inteira a cada hover, resize e atualização de specs, e tween
sobre alvo destruído é a classe de defeito que obrigou a guarda `viva()` a existir. **Isto estreita a
entrega, e por isso está escrito.**

### E o que a foto mostrou de brinde

⚠️ **A faixa de eventos do mundo (A2.8) está ilegível**: ela é desenhada em fluxo no topo do
documento, e as duas barras de navegação (`Header` no desktop, `MobileNav` no mobile) são
`absolute top-0` por cima dela. O texto sai picado atrás do cabeçalho. O e2e passa porque o texto
**existe no DOM** — o falso-verde do D-63 outra vez, e a razão de `foto.mjs` existir. Não foi
corrigido aqui: o conserto é do layout global (A2.V2) e empurraria dez telas.

---

## D-216 — Os três pedidos sobre a faixa de avisos, e o botão que nunca funcionou

Pedidos do usuário olhando a faixa em produção.

### 1. O símbolo saiu

Era `▲`/`!`/`·` por severidade. A regra do deck continua valendo — *estado nunca se anuncia só por
cor* —, mas o segundo canal já existe e é melhor: **o título é uma frase inteira**. O próprio
`design-tokens.md` diz que é assim que a regra se cumpre (*"o componente `Erro` escreve a palavra
'Erro' pelo mesmo motivo"*). O glifo era um terceiro canal, não o segundo.

### 2. O aviso passa a dizer QUAL recurso

Dizia *"Um recurso encheu e parou de produzir"* e deixava o colono caçar qual, entre 26. O aviso é
montado **a partir da lista dos cheios** — ele sempre soube, só não contava. Acima de três, os dois
primeiros continuam nomeados (*"Biomassa, Água e mais 2"*): `"4 recursos"` não responde *"qual?"*.

### 3. ⚠️ O botão "Ver o que aconteceu desde sua última visita" não abria nada

E a causa não era a tela.

`resumo_visto_em` avança quando o jogador **fecha** o resumo. Um minuto depois de fechar, *"desde a
última visita"* é um intervalo de um minuto — janela vazia — e o **piso de uma hora** do §5.1 ainda
barra por cima. **O botão pedia uma janela que ele mesmo tinha acabado de consumir.** Existe desde o
D-211 e nunca funcionou fora da primeira hora de uma conta nova.

Guardar o marcador **anterior** resolve sem tocar no §5.1:

- o piso continua governando o resumo **automático**, que é o que ele existe para conter — um popup
  que se convida a cada carga de página;
- `?reabrir=1` é o clique **explícito**: devolve a janela anterior e ignora o piso. Negá-lo era
  transformar proteção contra insistência em proibição de reler;
- **reabrir não move marcador nenhum.** Se movesse, apagaria a janela que acabou de mostrar, e o
  botão pararia de funcionar na segunda vez. Tem teste.

A migration `janela_anterior_do_resumo` foi exercitada **nos dois sentidos no MariaDB**, e não só no
SQLite dos testes — é a lição do D-59, que já quebrou a produção uma vez.

---

## D-217 — A faixa de eventos era chrome e vivia no fluxo, e o conserto revelou outra oclusão

A faixa dos eventos de mundo (A2.8) estava **ilegível em produção**, e ninguém tinha visto porque
nenhum teste consegue ver isso. Foi a foto do D-215 que pegou.

### O defeito

Ela nascia em fluxo, como **primeiro elemento do documento**. As duas barras de navegação —
`Header` no desktop, `MobileNav` no mobile — são `absolute top-0`, fora do fluxo, e pintavam por
cima: o texto saía picado atrás do cabeçalho. E, por estar em fluxo, ela ainda **empurrava a colônia
inteira para baixo** — a colônia é `h-screen`, então o resultado era uma tela maior que a janela.

⚠️ **E o e2e passava**, com três asserções sobre ela. Todas afirmavam que o texto **existe no DOM**,
e existia. Nada num teste de texto ou de clique alcança o que está visualmente coberto. É o
falso-verde do D-63 na veia, e a razão de `e2e/foto.mjs` existir.

### O conserto

A faixa é **chrome**, não conteúdo — e o lugar dela é a camada das barras, não o fluxo:

- `absolute`, não `fixed`: aviso que persegue a rolagem vira moldura;
- `z-20` contra o `z-[25]` das barras: **nunca** cobre a navegação, que é o caminho de saída;
- `pointer-events-none`: na colônia ela flutua sobre a colmeia, e um aviso que rouba o clique de um
  slot é pior do que um aviso invisível;
- `md:pr-72` reserva a coluna do HUD.

### ⚠️ E aí apareceu a segunda oclusão, que estava lá o tempo todo

Com a faixa fora do fluxo, a coluna do HUD subiu para o lugar dela — e o **cabeçalho passou a cobrir
o topo da faixa de avisos**. Medido no navegador, não estimado:

| elemento | caixa |
|---|---|
| chips de navegação (esquerda) | descem até **80px** |
| chips da direita (Marco, colônia, ícones) | descem até **117px** |
| coluna do HUD | começava em **96px** (`top-24`) |

21px de sobreposição, bem no primeiro painel da coluna — a faixa de avisos, que é justamente o que
exige ação. Corrigido para `top-32` (128px).

**O defeito é anterior a tudo isto e vinha mascarado**: a faixa em fluxo empurrava a tela uns 48px,
o que por acaso resolvia a colisão — mas só enquanto houvesse evento ativo. Sem evento, a oclusão
sempre existiu.

### Duas ferramentas, porque a fase é visual

**A asserção que faltava.** O e2e agora confere geometria: nem a faixa de eventos nem a de avisos
podem cruzar um chip da navegação. ⚠️ A primeira versão comparava com o `<header>` e **estava
errada** — ele é `inset-x-0 pointer-events-none`, uma caixa vazia de 1400×137 que cobre a largura
inteira, e reprovaria qualquer layout. O que se vê são os chips.

**`E2E_SO_FOTOS=1`.** Sobe a pilha e vai direto às fotos, sem rodar suíte nenhuma. A A2.V é uma fase
inteiramente visual, e a foto é o instrumento — mas ela só saía depois de todas as suítes passarem,
e a suíte é instável (D-212: **quatro corridas para uma verde** nesta sessão, cada uma falhando em
ponto diferente). Isso tornava "fotografe e olhe" caro justamente na fase que mais depende de olhar.
Não substitui a suíte: é o atalho para conferir desenho.

### E o glifo saiu do painel também

O `!` que o D-216 tirou da faixa de avisos saiu do painel de estado da construção pelo mesmo motivo:
onde há frase inteira, **o texto é o segundo canal**. O selo no hexágono mantém o glifo — lá não cabe
frase nenhuma, e ele é o único segundo canal possível.

---

## D-218 — A2.V3, leitura espacial: o prédio de baixo pintava por cima do nome de cima

Segunda fatia da A2.V3, e a primeira sobre *"nova leitura espacial"*. Saiu de olhar a foto do D-215,
não de um relatório: os nomes da colmeia estavam sujos, e dois deles — **"Central de Transportes"** e
**"Estrutura de Sobrevivência"**, os de duas linhas — mal se liam.

### A causa: quem desenha depois vence

A cena montava **um contêiner completo por slot** (hexágono, prédio, nível, nome) e os adicionava na
ordem dos slots. Só que o sprite transborda o hexágono em `1,72·r` **de propósito** — é o que o faz
parecer um prédio pousado no terreno, e não um selo colado. Resultado: o sprite da linha de baixo
invadia o espaço do vizinho de cima e pintava por cima do rótulo dele.

A conta fecha: o topo do sprite fica a `0,86·r` acima do centro dele, as linhas distam `1,71·r`, e o
nome da linha de cima ocupa de `0,52·r` a `0,86·r` abaixo do centro dela. **Encontram-se exatamente
na borda** — e em nome de duas linhas o rótulo perdia.

**Duas passadas** resolvem sem mover nada: todo o terreno e todos os prédios primeiro, toda a
rotulagem depois. A regra que fica: **nada do que informa (nível, nome, selo de estado) pode ser
coberto pelo que ilustra.**

### A placa, no lugar do contorno

O nome era desenhado com contorno claro em volta de cada glifo. Aquilo resolvia o contraste e criava
outro problema: sobre um telhado irregular, letra contornada vira **renda** — legível de perto, ruído
a olho corrido, e a colmeia inteira ficava suja de texto.

A placa é o mesmo remédio que o deck usa no estado `aviso`: quando a cor do texto não passa sobre um
fundo qualquer, **pinte o fundo**. Uma faixa `sandLight` atrás do nome dá contraste constante, seja
qual for o telhado embaixo — e lê como placa de identificação no terreno, que é o que ela é.

Sem arte, nada muda: o hexágono chapado já dava contraste, e uma placa ali seria enfeite.

### Como foi conferido

Fotografado antes e depois, com `E2E_SO_FOTOS=1` (D-217) — é o único jeito de ver isto, porque a
colmeia é canvas e os testes de clique passam por cima dela sem enxergar nada. Suíte e2e inteira
verde depois da mudança: os alvos de clique são botões de DOM e não dependem da ordem de desenho, mas
isso precisava ser demonstrado, não suposto.

---

## D-219 — A escassez que existe é industrial, e 58 das 66 fábricas do mundo mentiam na tela

O usuário pediu para falar de escassez de recursos. A conversa começou pela penalidade do §6.6 e
terminou noutro lugar — porque a medição levou para lá.

### A arbitragem, fechada: escassez de população é REDE DE SEGURANÇA

O `BALANCEAMENTO.md` §7.1 tinha uma pergunta em aberto desde a Rodada 1 (*"ou a produção precisa ser
maior, ou o consumo per capita precisa cair — falta arbitrar"*). Duas coisas a respondem:

1. **As rodadas 6 e 7 já a tinham fechado**, no mesmo dia: *"consumo per capita fica onde está… é
   escolha, não omissão"*. Quem lesse a página de cima para baixo pararia na pergunta velha.
2. **A medição de campo (§7.1.1)** mostrou que a premissa da Rodada 1 era irreal: ela simulou uma
   colônia produzindo **2 de água/hora**, e a produção de verdade é **80**.

Os números do campo, com 29 colônias e o mecanismo ligado há cinco dias:

| medida | valor |
|---|---|
| população máxima possível | **74** (Estrutura nível 5) |
| consumo da colônia nesse teto | água 7,4/h · oxigênio 8,9/h · biomassa 5,9/h |
| uma Captação de Água **nível 1** | **80/h** — 10,8× o consumo máximo teórico |
| folga até a penalidade, menor do mundo | **953 h (40 dias)** |
| colônias degradadas | **0 de 29** |

E o gatilho compara o estoque com **uma hora** de consumo: uma colônia de 28 colonos só degrada
abaixo de 2,8 de água. Não é curva de escassez, é cheque de tanque vazio.

⚠️ **Por que não "dar peso" à população.** Exigiria ~30× no consumo só da água, e a penalidade é
**multiplicativa e da colônia inteira** — faltar água não reduz água, reduz **tudo** até o piso de
50%. É a forma exata do desastre que o D-184 mediu (17 de 29 colônias a metade da produção de uma
vez) e que o §6.7 proíbe. Arbitrado com o usuário: **não reabrir**.

### ⚠️ E o que a medição achou no lugar

| | |
|---|---|
| fábricas de conversão erguidas no mundo | **66** |
| **produzindo nada** por falta de insumo | **58 (88%)** |
| parariam de estar paradas só com energia | **13** |
| colônias com estoque de energia zerado | **17 de 29** |

As três receitas da Oficina pedem energia (10/14/20), a Refinaria 6, a Destilaria 3. Energia é
estoque **e** fluxo: quem opera no que gera fica em zero — o D-184 chama isso de estado **normal**.
E `ColonyTick::converter()` não converte sem insumo. **Treze Refinarias Químicas foram erguidas e
custeadas sem jamais converter um lote**, e a colmeia desenhava todas como `produzindo`.

### Duas correções minhas

**1. Eu publiquei uma afirmação errada no D-215.** Escrevi que *"energia não trava construção
nenhuma"*, citando o D-20. É verdade para a **operação** do prédio e **falso para as receitas**. O
item *"falta de energia"* do roadmap da A2.V3 existe — só não na forma que o roadmap descreve.

**2. E antes disso quase publiquei um alarme falso.** Medi com `TaxasDeProducao` (taxa **nominal**) e
concluí que 4 colônias secariam a água em 4 dias. Errado: aquele dreno é da Refinaria, que **está
parada por energia**. A própria classe avisa no docblock que não é projeção. Amostrei o estoque em
dois instantes — a água está **subindo** (+78/h). Taxa nominal não é previsão, e eu tratei como se
fosse.

### O estado `sem_insumo`

Vem **antes** do teto de saída na ordem, e a escolha tem razão: a boca fechada é **a montante** — uma
fábrica que não consegue consumir também não está enchendo nada. E é a menos descobrível: o depósito
cheio o jogador vê no Depósito Local, com número e barra; a energia que falta para a receita não
aparecia em tela nenhuma.

O selo é `×` em `perigo`, e **não** o mesmo `!` da travada, de propósito: os dois são "parada", mas
pedem ações **opostas** — a travada quer que o jogador **gaste** o que ela fez; esta quer que ele
**traga** o que falta. Um símbolo só mandaria metade dos jogadores para o lado errado.

O limiar é `< o que um lote pede`, e não `<= 0`, porque é o que `converter()` faz: faltando para um
lote, ele não converte nada. Perguntar só por zero deixaria a Refinaria com 3 de energia numa receita
que pede 6 dizendo que produz.

1248 testes verdes; suíte e2e inteira verde. Fotografado: os três estados lado a lado na colmeia.

---

## D-220 — O déficit de energia dito por extenso, e os dois números que não podiam brigar

Fecho do D-219. Aquele mandou 58 fábricas do mundo apontarem para a energia como causa, e o destino
de todas elas era uma linha do HUD que dizia `Energia +150 −273` — deixando **a subtração por conta
do jogador**. Um diagnóstico que exige aritmética de cabeça não é diagnóstico.

### ⚠️ O número certo não era o que estava à mão

A tentação era subtrair a própria linha. Seria errado, e pelo mesmo motivo que quase publicou um
alarme falso no D-219: `taxas_hora['energia']['consumido']` soma **duas coisas de naturezas
diferentes** —

- o que **toda construção debita por hora só para existir** (acontece sempre), e
- o que as **receitas** pediriam se rodassem (nominal — e sem energia elas não rodam).

Somar as duas e chamar de déficit descreve um mundo que não existe. Nasceu
`TaxasDeProducao::energiaOperacional()`, que devolve `gerada`/`operacional`/`saldo` a partir do
`consumoEnergia` que o `taxasNominais()` já separava — o dado sempre esteve lá, faltava alguém
perguntar por ele.

### E os dois números precisaram ser reconciliados na tela

Na primeira foto o painel mostrava `−273` na linha e `261` na caixa. Doze de diferença — exatamente a
energia da receita da Refinaria que **não** roda. Dois números para "energia consumida" no mesmo
painel, e a discrepância lê como defeito. A frase passou a nomear a diferença: *"a linha acima soma
também o que as receitas pediriam, e sem energia elas não rodam"*.

Foi a foto que mostrou isso, de novo. Nenhum teste compara dois números que estão certos.

### A boa notícia que a medição trouxe

Das 17 colônias com saldo negativo, quase todas estão com o **Reator no nível 1** (150/h) consumindo
300–400/h. A curva é 1,5× por nível: o nível 3 já dá 338/h e o nível 4, 506/h. **Ninguém está preso
— estava no escuro.** É por isso que a mensagem termina apontando o Reator, com a curva junto: o
jogador precisa saber que a saída existe e é barata.

### Onde NÃO foi posto, e por quê

Não virou aviso da faixa. São **17 de 29 colônias**, a mesma faixa dos avisos que o D-211 cortou por
virarem moldura ("população no teto", 28/29; "sem colonos livres", 19/29). Um aviso que 6 em cada 10
veem sempre ensina a ignorar a faixa inteira, e leva junto o aviso de cerco. O lugar é a linha do
recurso, onde a pergunta nasce.

Só aparece no vermelho: colônia equilibrada não recebe aviso nenhum. Um "está tudo bem" permanente
ensina a não olhar para o lugar onde a má notícia vai aparecer.

1250 testes verdes; suíte e2e inteira verde; fotografado.

---

## D-221 — A pulsação sem tween, e a regra: anima-se evento, não condição

Último item da A2.V3. O D-215 tinha adiado *"animações sutis"* **por escrito**, e o motivo continuava
de pé: `desenhar()` reconstrói a árvore inteira (`removeAll(true)`) a cada hover, resize e
atualização de specs. Um tween guardaria referência a um objeto que o próximo redesenho destrói — e
tween sobre alvo destruído é **exatamente** a classe de defeito que obrigou a guarda `viva()` a
existir.

### A saída foi não ter estado

A escala sai de uma **função do relógio da cena**, aplicada em `update()`. Não há tween, não há
ciclo de vida, não há referência a manter viva: o objeto recriado no meio do ciclo pega a fase
corrente e continua liso, **porque a fase nunca morou nele**.

A lista de pulsantes é zerada na mesma linha em que a árvore é destruída, e não depois — `update()`
roda no laço de quadros, e deixar a lista velha de pé por um instante seria pedir um `setScale` num
objeto morto. É o mesmo defeito de sempre, entrando pela porta dos fundos.

### ⚠️ A regra de desenho que saiu daqui

**Anima-se evento, não condição.**

- `erguendo` e `melhorando` são coisas **acontecendo**, com hora para acabar, e no máximo duas por
  colônia pelo teto da fila. Pulsam.
- `travada` e `sem_insumo` são **condições**. Numa colônia com quatro fábricas paradas, quatro selos
  pulsando viram ruído; num mundo com **58** delas (D-219), viraria um pisca-pisca. Ficam estáticos.

Condição se lê; evento se nota. Sem essa regra, "animações sutis" vira animação em tudo, e a tela que
o D-218 acabou de limpar sujaria de novo — por movimento em vez de por texto.

Amplitude 6% (0,94–1,06) num ciclo de ~2,8 s: perto do limiar de percepção, que é onde "sutil" mora.

### Como foi conferido, já que foto não prova movimento

`e2e/foto.mjs` passou a tirar **dois quadros do mesmo recorte**, separados por meio ciclo, e a
comparar os bytes. Se forem idênticos, nada se moveu — ou a `update()` deixou de ser chamada, ou a
lista de pulsantes ficou vazia. É grosseiro de propósito: não afirma que a animação está bonita,
afirma que ela **existe**, que é o que um redesenho quebrado apagaria em silêncio.

Resultado: `pulsação: viva (211699 vs 211841 bytes)`.

Com isto a **A2.V3 fecha**. 1250 testes verdes; suíte e2e inteira verde.

---

## D-222 — A2.V4 primeira fatia: o mapa não tinha eixo, e no telefone o painel tapava o planeta

Primeira fatia da A2.V4, e ela saiu inteira da **primeira foto** — o `foto.mjs` não fotografava o
mapa até hoje.

### O eixo X estava atrás do cabeçalho

O mapa é `h-screen w-screen` de propósito (D-154/D-156) e as duas barras de navegação são
`absolute top-0`, fora do fluxo. A régua do X mora na calha de cima do `viewBox` — ou seja, **debaixo
do cabeçalho**. Na foto dava para ver `-7`, `-6`, `1` e `7` espiando entre os chips, e mais nada.

**Um mapa cujo eixo horizontal não se lê é um mapa sem coordenada.** E nenhum teste reclamava: os
`<text>` estavam todos no DOM, e o e2e do mapa até **conta** quantos números a régua tem — ele
contava 25 números invisíveis e dava verde. É o falso-verde do D-63 pela quarta vez nesta sessão.

A régua mudou para a calha de **baixo**, que já era reservada e ficava vazia: `totalComReguas` a soma
dos dois lados de propósito, para o rótulo da última coluna não sair pela metade. Não custou um pixel
de layout — só usou espaço que já estava pago.

### ⚠️ E aí ela caiu na outra barra

Medido no viewport de 390×844: a régua foi para 823–837, e a barra fixa de baixo começa em **780**.
Sair de uma barra para cair na outra não é conserto.

No mobile o mapa passa a terminar **acima** da barra: `h-[calc(100dvh-4rem-env(safe-area-inset-bottom))]`.
O que fica debaixo de uma barra opaca não é mapa visível, é mapa desperdiçado. `dvh` e não `vh`
porque a barra de endereço entra e sai, e `100vh` mede a janela maior — justamente a que não está na
tela.

Depois: régua em 759–773, `coberta_por: 0` nos dois tamanhos.

### O painel de 288px numa tela de 390px

Com o mapa enfim visível, a foto do mobile mostrou o problema maior: **o painel lateral cobria o
planeta inteiro**. E ele nasceu visível em toda largura por decisão registrada — *"aqui o painel de
seleção não é acessório, é a resposta direta ao clique numa zona/colônia"*.

A decisão está certa **para o caso que ela descreve**. Sem seleção, porém, o painel é legenda e
listas — e legenda que tapa o mapa inverte a tela: o acessório vira o conteúdo. Só o caso **sem
seleção** recolhe, e só no mobile. Com seleção, nada muda.

O botão foi para baixo à esquerda depois de três posições descartadas **por medida**: em cima à
esquerda cai sobre a marca; em cima à direita, sobre os controles de zoom; em `top-20` mora o aviso
de evento de mundo. E `left-9`, não `left-3`, para não encostar nos números do eixo Y — a calha tem
29px nessa largura.

### O teste que precisou mudar, e por quê

O e2e mobile afirmava `/Grade \d+×\d+/` — a frase de orientação **dentro do painel**. Ele passava por
efeito colateral: o texto estava visível porque o painel cobria o mapa. Agora afirma `[data-mapa]` e
`[data-legenda-mapa]`.

⚠️ **Não foi enfraquecido para passar.** O texto do painel provava que o painel estava por cima; o
desenho mais o controle de abrir provam a tela funcional. A asserção ficou mais forte, não mais
frouxa.

Suíte e2e inteira verde.

---

## D-223 — O portão do território era assintótico, e ele não é o único

O usuário pediu a medição da A2.V4 (*"que verdade do servidor o mapa não mostra?"*). A resposta foi
que **o mapa não tem o que mostrar**, e o motivo não é de desenho.

### O que o mapa não pinta — e por que isso importa menos do que parecia

`corDaZona` pinta a zona **só por dono**. Ignora `maintenance_unpaid_since`, `sieged_at`,
`modules_offline`, `structures_saboted`, `garrison` e `status`. A frota é carregada e **não é
desenhada** — não há trajetos. E a colônia vizinha chega à tela sem campo nenhum de federação.

Só que o mundo, medido:

| camada | hoje |
|---|---|
| zonas ocupadas | **1 de 77** |
| inadimplentes · sob cerco · sabotadas · protegidas | 0 · 0 · 0 · 0 |
| **combates registrados, desde sempre** | **0** |
| veículos em rota | 0 (41 ociosos) |

A guerra federativa inteira — D-193 a D-207, a fase mais longa do Alpha 2 — **nunca teve um
combate**. Polir "ameaças" e "estados territoriais" seria polir portas que ninguém consegue abrir.

### ⚠️ O XP não é lento: ele parou

A primeira leitura foi ingênua. Dividi o XP total por 24 dias e conclui "46 dias para o líder". A
série temporal desmente:

| semana | atos | XP do mundo inteiro |
|---|---|---|
| 13/07 | 519 | **69.100** |
| 20/07 | 70 | 8.150 |
| 27/07 | 18 | 2.200 |
| 04/08 | 10 | **1.000** |

Queda de **98,5%**. O ritmo de hoje é ~300 XP/dia para as 29 colônias **somadas**, e nos últimos 14
dias só **cinco** colônias pontuaram — uma delas (Energizer do Gamer, humana) fez 2.950 dos 4.550.

A causa é estrutural: **96% do XP vem de `obra_concluida`**, que é fonte de largada. A colônia se
ergue na primeira semana e depois a curva de custo (1,5×/1,65×) engasga o ritmo. A quadrática do
marco sobe; a fonte que a alimenta desce. Com BASE 50, o marco 20 não era distante — era
**assintótico**.

### A recalibragem, e a âncora dela

BASE **50 → 15**. O 50 foi escolhido no D-75 sem campo nenhum (o Marco tinha acabado de nascer); o 15
sai de uma âncora medida:

> **O §05 dá o território ao marco 20 (Desbravador).** Com BASE 50 a colônia mais avançada do mundo
> estava no marco **11** — o portão pedia **3× o total de vida do melhor jogador**. Com 15 ela fica
> no **21**: o jogador mais avançado acaba de alcançar a faixa que o GDD associa a território, que é
> o que o documento descreve.

Efeito medido antes de publicar: colônias no marco 20+ vão de **0 para 2** — e as duas são
**humanas**. Os bots ficam no 15–16. A mediana (2.600) fica no marco 13: território é conquista, não
piso.

O D-75 avisou que mexer na BASE **reescala o marco de todo mundo** e que isso é arbitragem, não
balanceamento. É arbitragem, e foi do usuário.

### ⚠️ E isto sozinho NÃO destrava o território

A medida seguinte é a que impede a comemoração. Ocupar uma zona exige, além do marco: **300 Fert$ +
1.020 Metal Bruto + 1.200 Ligas + 400 Componentes**, e **população livre**. Os dois líderes humanos:

| colônia | falta |
|---|---|
| Maior Colonia | 5 componentes, 128 Fert$ — e **0 colonos livres** |
| Energizer do Gamer | 453 metal, 394 ligas, 300 componentes, 153 Fert$ — e **−9 colonos livres** |

São **três portões empilhados** (marco, material, população), e o marco era o mais fora de escala —
não o único. O gargalo seguinte é **população livre**, que se destrava subindo a Estrutura de
Sobrevivência. Nenhum deles foi tocado aqui: mexer em três números de uma vez tornaria impossível
saber qual causou o quê, que é a lição do D-184.

### O teste que guarda a razão, e não o número

`test_a_base_poe_o_jogador_mais_avancado_na_faixa_do_territorio` afirma a **âncora**: 6.900 XP tem de
dar Desbravador, e a mediana medida não. Se alguém mexer na BASE de novo, é esse sentido que precisa
continuar de pé. E os dois testes que fixavam `5_000` para dizer "marco 10" passaram a derivar de
`Curva::xpDoMarco(10)` — número cru em teste é armadilha para a próxima recalibragem.

1251 testes verdes.

---

## D-224 — O botão que sempre podia ser clicado, e o custo escrito à mão que mentia

Segunda fatia da A2.V4, e ela nasceu do D-223: assim que o portão do marco abriu para duas colônias,
o mapa virou a tela onde elas iriam tentar ocupar. Aí a pergunta ficou concreta — **a tela diz o que
ainda falta?**

### Duas mentiras numa linha só

O painel da zona livre trazia uma frase escrita à mão:

> *"Ocupar custa 800 Metal Bruto + 300 Fert$ (Posto de Comando) e 20 Robôs Mineradores"*

A cobrança real é **1.020 Metal Bruto + 1.200 Ligas Metálicas + 400 Componentes + 300 Fert$**:

- o Metal Bruto verdadeiro é 1.020 — os 800 do Posto **mais 220 dos robôs**, escondidos atrás da
  palavra "robôs";
- e as **duas maiores parcelas da conta** (1.200 Ligas, 400 Componentes) não eram citadas.

Custo escrito à mão nasce certo e envelhece sozinho: o custo do Robô Minerador é **editável pelo
operador** (D-108), então a frase estava condenada desde o primeiro dia.

### E o botão nunca sabia se ia funcionar

Sempre habilitado. O jogador clicava, o servidor recusava, e o motivo vinha **um de cada vez**, na
ordem em que o comando confere — que é o certo para uma transação e péssimo para uma tela: ele
conseguiria Fert$, clicaria de novo, e só então descobriria que faltam colonos.

Medido no dia em que o D-223 abriu o portão: os dois líderes humanos estavam bloqueados por
**componentes, Fert$ e população livre ao mesmo tempo**.

### A regra e a tela passam a ler o MESMO código

`custoDeRecursos()` era privado do `OcuparZonaNeutra` e virou `RequisitosDeOcupacao`, servido por
`GET /zones/requisitos`. O painel não recalcula nada — ele **mostra o que o comando cobra**.

O teste que existe por causa do defeito compara a **conta anunciada** com o **débito real** da
ocupação, recurso a recurso. Enquanto eram dois lugares, eles divergiram por 220 de metal e duas
linhas inteiras.

⚠️ E `falta` traz **todos** os impedimentos juntos, não o primeiro — com o que se tem e o que se
precisa em cada um. O botão desabilita quando o servidor já disse que vai recusar; enquanto os
requisitos não chegam, ele fica ativo, porque travar o único caminho para ocupar por causa de uma
chamada pendente seria pior do que não saber.

### Rota própria, e não campo de cada zona

O custo é o **mesmo para as 77 zonas** — repeti-lo 77 vezes seria payload por nada. E ela vem antes
de `/zones/{zone}` na tabela, pelo mesmo motivo que `/zones/minhas`: senão o Laravel procuraria uma
zona de id "requisitos".

### Nota de corrida

A suíte de Acordo de Troca reprovou na primeira corrida e passou na segunda, sem mudança nenhuma no
meio — a instabilidade que o D-212 mediu e deixou explicitamente em aberto. Nada do que esta fatia
toca chega perto de acordos; ficou registrado por honestidade, não por diagnóstico.

1256 testes verdes; suíte e2e inteira verde; fotografados os dois casos — o que pode ocupar e o que
não pode.

---

## D-225 — "−10 livre(s)" não se lê, e a colônia nova nascia estéril

O usuário pediu para conferir se o caminho do gargalo de população está claro na tela — era o segundo
dos três portões que o D-223 mapeou. A conferência achou um defeito de leitura e, atrás dele, um bug
que ainda não tinha mordido ninguém.

### O painel do D-210 já era bom, e errava num ponto

Ele mostra o disponível em destaque, o teto, e **nomeia o remédio** ("suba a Estrutura de
Sobrevivência"). O que faltava:

**1. O negativo era impresso cru.** `Operadores::disponivel()` pode ser negativo — o docblock avisa,
e todo consumidor já fazia `max(0, ...)`. Só a tela não fazia, e mostrava **"−10 livre(s) de 28"**.
Não se lê: não existe menos dez pessoas. O que existe é uma colônia **devendo 10 operadores ao que já
tem de pé**. Medido em produção: um dos dois líderes humanos estava exatamente assim (28 colonos, 38
exigidos).

⚠️ E o déficit **não degrada nada** — conferido antes de escrever a frase. `eficienciaBps` é de zona,
e nenhuma penalidade de operador atinge a produção da colônia. Ele só barra **ocupar zona e alocar
operador**, e é isso que a tela diz. Chamá-lo de penalidade seria inventar regra.

**2. O remédio vinha sem a dose.** "Suba a Estrutura" é conselho incompleto quando falta mais de um
nível: a colônia medida está no **nível 2** (teto 16) e precisa abrigar **38** — o nível 3 abriga 27,
ainda insuficiente. Ela pagaria a obra e continuaria travada, sem entender por quê. O painel passa a
dizer o **alvo**, e `null` quando nem o nível máximo resolve — aí a saída é demolir, e mandar subir
seria mandar gastar à toa.

### ⚠️ E o bug atrás disso: colônia nova nascia estéril

`colonies.populacao` tem `default(0)` e o **`CreateColony` nunca a escreveu**. O crescimento do
`Ciclo` é multiplicativo (`total × taxa`) e o próprio código devolve `parado` quando `total <= 0`:
**de zero a população nunca sai.**

Uma colônia fundada hoje ficaria para sempre com **0 colonos** — sem ocupar zona, sem alocar
operador, sem erguer o que exige equipe. A produção não sofreu **por acaso**: o
`fertways:populacao-grandfather` preencheu as 29 existentes em 2026-08-01 e ninguém fundou desde
então. O próximo a fundar é que pagaria — o pior tipo de defeito, o que só morde quem chega depois.

A colônia passa a nascer com a **mesma conta do grandfathering** (§6.7), aplicada no momento em que
ela faz sentido: gente para operar o que recebe ao nascer, com a mesma folga, limitada pelo próprio
teto. Reusar a regra em vez de inventar um número é o que impede as duas de divergirem.

### Como isto quase passou batido

O defeito apareceu porque um **teste meu falhou por um motivo que eu não esperava**: ao afirmar
"colônia com gente sobrando", a colônia recém-fundada veio com disponível negativo. Era para ser um
ajuste de fixture; era o bug.

⚠️ E o susto seguinte foi meu: medi "colônia com população 0" e quase publiquei alarme — a medida
tinha rodado contra o **banco de dev**, não a produção. Em produção nenhuma colônia está em zero. É a
segunda vez nesta sessão que a taxa/medida errada quase virou conclusão (a primeira foi a taxa
nominal do D-219). Conferir de onde vem o número é parte de medir.

### O que a tela mostra hoje, medido

Só **1 das 29** colônias está devendo operadores — o destaque em vermelho é raro, e não moldura
(a regra do D-211). As outras 28 continuam vendo o disponível de sempre.

1261 testes verdes; suíte e2e inteira verde.

---

## D-226 — A2.V5: a Endurance está morta, a Capital é o sistema mais usado, e ela mostrava só números

Primeira fatia da A2.V5, e ela começou pela medida — a lição que a A2.V4 cobrou caro. A fase se chama
"Capital e Endurance", e as duas metades estão em extremos opostos:

| sistema | uso real |
|---|---|
| **Mercado Central** | **1.448 ordens executadas**, 116 abertas, 15 parciais |
| Missões | 53 modelos, **155 concluídas**, 32 ativas |
| Acordos de troca | 3 |
| **Endurance** | **1 item no catálogo, 1 nas mãos de um colono, 0 transferências, 1 leilão** |

**A Endurance é a segunda A2.V4**: sistema construído (D-132 a D-140 — a Loja refeita, os efeitos no
motor, as missões narrativas, os leilões) e praticamente **não jogado**. Polir a tela dela seria polir
uma porta que ninguém abre. Não foi tocada.

A Capital é o oposto: é por onde se chega ao Mercado, e o Mercado é o sistema mais exercitado do jogo.

### Nove instituições, todas anônimas

O Governo Central desenhava **hexágonos com números**. Tributos, Notícias, Finanças, Reputações,
Transportes e Alianças — seis coisas que funcionam — e descobrir o que cada uma era exigia **clicar em
todas**.

⚠️ Os números **eram decisão registrada**, e continuam certos onde foram decididos: *"o vago não
engana... é o que faz a Capital parecer um lugar que vai crescer, e não um menu"*. Isso vale para o
que **ainda não existe**. Para o que já funciona, número é charada.

A linha que ficou: **só o ativo ganha nome.** O vago segue numerado e apagado — a promessa de
crescimento fica intacta — e o `em_breve` também, porque nomear o que não abre seria oferecer uma
porta que não existe.

### O rótulo é curto de propósito

Os hexágonos do Norte têm ~20px de raio e as linhas distam ~51px. *"Central de Pesquisas e
Notícias"* não cabe em lugar nenhum dessa grade, e forçá-lo quebraria em três linhas por cima do
vizinho. O nome inteiro continua existindo — é o **título do painel** que abre ao clicar. No hexágono
vale a palavra que identifica: Tributos, Notícias, Finanças, Reputações, Transportes, Alianças.

A placa por baixo é o mesmo remédio do D-218: quando a cor do texto não passa sobre um fundo
qualquer, **pinte o fundo**.

### O que a medida deixa decidido para o resto da A2.V5

A metade "Endurance" da fase **não tem o que desenhar** enquanto o sistema não for jogado — mesma
situação de ameaças/zonas/trajetos na A2.V4. O que sobra com dado real é a Capital e, por trás dela,
o Mercado. Fica registrado para não se gastar a fase no lado morto.

Suíte e2e inteira verde; fotografado antes e depois.

---

## D-227 — A referência que só o vendedor via, e a correção de um número que eu publiquei ontem

### ⚠️ Primeiro, a correção

O D-226 diz que *"o Mercado Central é o sistema mais exercitado do jogo — 1.448 ordens executadas"*.
**Está errado, e o erro é meu**: eu não separei humano de bot.

| | |
|---|---|
| ordens executadas | 1.440 |
| **de bots** | **1.440 (100%)** |
| **de humanos** | **0** |

Nenhum jogador humano executou **uma única ordem**. Eles têm 11 ofertas abertas e 1 parcial, e
compram do governo (51 lançamentos `compra_mercado` no ledger) — mas o livro entre colonos, que o
D-58 abriu, nunca fechou um negócio humano.

É a terceira vez nesta sessão que uma medida minha quase virou conclusão errada (taxa nominal no
D-219, banco de dev no D-225). O padrão é o mesmo: **o número estava certo, a população dele é que
não era a que eu supus.**

O que os 9 humanos de fato fazem, pelo ledger: estacionamento (1.257), custo de construção (355),
subsídio (325), tesouro (64), **compra no mercado (51)**, manutenção territorial (44). E **5 das 9**
agiram hoje — as outras 4 pararam em 23/07.

### A referência que só o vendedor via

O formulário de anunciar mostra *"Referência 0,0062 Fert$ · taxa de 3%"*. **A vitrine não mostrava
nada.** Quem lia a lista via só "0,0100 Fert$" e não tinha como saber se era caro ou barato — que é a
única pergunta que um comprador faz.

E o dado **já vinha no payload**: `Vitrine.catalogo` traz `preco_base_micro` de cada recurso, e
ninguém o lia. É o mesmo defeito que esta Alpha achou oito vezes — dado servido sem consumidor.

Agora cada oferta diz a referência e a razão: *"referência 0,0062 Fert$ · 1,61× acima"*, em `perigo`
acima e `sucesso` abaixo. `null` quando não há base — sem número para comparar, dizer "no preço"
seria afirmar o que não se sabe.

⚠️ E a medida dá o contexto que torna isto mais do que enfeite: os 1.440 negócios fechados saíram a
**exatamente 1× a referência**. Um mercado onde o preço justo é invisível não convida ninguém a
discordar dele — e os bots, que enxergam o banco, negociam no ponto exato em que ninguém precisa
pensar.

### E o resto da A2.V6, medido

A fase é "Combate e eventos", e **as duas metades estão vazias**: 0 combates desde sempre, e
**0 eventos** em `game_events`. O motor do D-185 funciona — mas criar evento é ato do Dono, por
`artisan fertways:evento`, e nenhum foi disparado. Não há o que desenhar em nenhuma das duas.

Com isto, **três das seis sub-fases visuais (A2.V4 combate/território, A2.V5 Endurance, A2.V6) não
têm sujeito**. O padrão da revisão inteira: onde há uso real, sempre havia verdade que a tela calava;
onde não há, não há o que polir.

Suíte e2e inteira verde; fotografado antes e depois.

---

## D-228 — O que trava a primeira troca humana: não é regra, é que ninguém tem o que o outro quer

Pedido do usuário: mapear a primeira troca entre humanos como o D-223 mapeou os portões do
território. A medida diz que **não há portão** — e o que há é pior de consertar.

### Nenhum bloqueio mecânico

| conferido | resultado |
|---|---|
| gate de marco no Mercado | **não existe** — o D-75 o recusou de propósito (§03 promete o primeiro lote ao recém-chegado) |
| Confiança Comercial (limiar 200) | as 9 colônias humanas estão em **500** |
| depósito na Capital | **4 das 9** têm, e cheios (2.142 Ligas, 995 Oxigênio, 970 Oxigênio…) |
| Fert$ | têm |

O livro está aberto, a doca está aberta, a carga está na Capital. Ninguém está barrado.

### As 12 ordens humanas, e o que elas dizem

| lado | preço vs referência | idade |
|---|---|---|
| 4 ordens de **compra** | 0,02× · 0,03× · 0,06× · 0,18× (uma a 0,6×) | 27–28 dias |
| 8 ordens de **venda** | 0,80× a 1,03× — **justas** — e uma a 16× | 14–22 dias |

⚠️ **As vendas humanas são honestas e ninguém as pega.** Água a 0,81×, Biomassa a 0,84×, Oxigênio a
0,80× — abaixo da referência, paradas há duas semanas.

### A causa: todos têm demais exatamente a mesma coisa

|  | água | oxigênio | biomassa | energia | metal | ligas | componentes |
|---|---|---|---|---|---|---|---|
| Maior Colonia | 183.350 | 213.303 | 150.802 | 80.552 | 30.434 | 12.426 | **395** |
| Agua Preta | 77.696 | 91.142 | 36.200 | 81.231 | 18.780 | **113** | **271** |
| SnowsLand | 44.294 | 82.806 | 33.744 | 22.429 | **2.240** | 2.102 | **292** |
| Energizer | **2.587** | 48.261 | 44.463 | 333.825 | **592** | **806** | **100** |

**O que os humanos oferecem é justamente o que todos têm às dezenas de milhares** — água, oxigênio,
biomassa, em lotes de 49 unidades (0,25 Fert$). Vender isso é oferecer areia no deserto.

E há vantagem comparativa real, **não anunciada**: Maior Colonia tem **100× mais Ligas** que Agua
Preta e 13× mais Metal que SnowsLand; Energizer tem 4× a energia dos outros. Nada disso está no
livro a preço razoável — a única oferta de Ligas está a **16× a referência**.

⚠️ E o bem mais escasso do planeta — **Componentes Eletrônicos**, entre 100 e 500 em todas — os
jogadores **não podem produzir**: a Oficina precisa dos 8 minerais eletrônicos, e o §4.3 os dá só ao
governo. O gargalo da economia é, por desenho, um monopólio estatal.

### O que foi feito, e o que não foi

Feito: **a aba agora conta as ofertas dos outros** ("Ofertas globais (12)"). As abas eram rótulos
secos, e o Mercado era um lugar que só encontra quem já ia lá. Some no zero — zero pendurado vira
moldura (D-211).

⚠️ **Não feito, e é decisão do usuário:** o mercado mostra oferta e quase não mostra **procura**. Um
sinal de escassez — "o planeta está sem Componentes" — criaria o outro lado do livro. Isso é desenho
de economia, não de tela, e não se inventa sozinho.

Suíte e2e inteira verde.

---

## D-229 — O silício sai de 5,54× para 0,83×, e o que a medida achou antes de mexer

Arbitragem do usuário (2026-08-06), depois do D-228: *"foi escolha pra ser um mineral raro, mas vamos
corrigir esse valor... faça um preço justo e acessível para o crescimento."*

⚠️ **Não era vírgula trocada** — eu supus que fosse, e o usuário corrigiu: o 0,2000 era deliberado,
para fazer do silício um mineral raro. A mudança é de balanceamento, não conserto de defeito.

### O que a medida achou antes de escolher o número

**1. Cinco dos oito minerais já têm fonte, e ela funciona.** A Indústria Siderúrgica (D-82) foi criada
exatamente para isto, e está entregando: Maior Colonia acumulou 1.505 Alumínio, 1.290 Cobre, 860
Estanho, 222 Ouro, 93 Tungstênio. **Dar esses cinco de graça competiria com o prédio que os jogadores
construíram** — foi por isso que a proposta de distribuir os oito por marco/missão/evento foi
estreitada antes de virar código.

**2. Só três não têm fonte nenhuma:** silício, lítio e tântalo. E entre eles, **só o silício trava
tudo** — as **três** receitas da Oficina o exigem; lítio e tântalo só a intermediária e a avançada.

**3. E só o silício estava fora da faixa.** O livro inteiro do Governo, contra a referência:

```
quartzo 1,05x · nióbio 0,95x · resina 0,95x · oxigênio 0,90x · tântalo 0,89x
metal bruto 0,84x · água 0,81x · lítio 0,79x · ouro 0,79x · tungstênio 0,71x
alumínio 0,43x · estanho 0,32x · biocombustível 0,29x        mediana: 0,84x
                              silício 5,54x   ← sozinho lá em cima
```

Lítio (0,79×) e tântalo (0,89×) — os outros dois "na mesma situação" — **já estavam justos**. O preço
de nenhum deles foi tocado.

### O número, e a âncora dele

**Silício: 0,2000 → 0,0300**, que é **0,83× a referência — a mediana do próprio livro do Governo**. A
âncora não é gosto: é a faixa que o operador já pratica nos outros 23 recursos.

### ⚠️ E a quantidade, que teria tornado o preço cosmético

Uma ocupação de zona pede 400 Componentes = **2.400 de silício**, e o Governo oferecia **500**. Preço
justo com estoque de 500 deixaria a cadeia travada do mesmo jeito.

Os três sem fonte foram a **2.500 à venda** (o Tesouro tinha ~9.450 de cada; ficou com ~7.450). É a
diferença entre "o preço é justo" e "dá para crescer", que é o que o pedido dizia.

| | antes | depois |
|---|---|---|
| silício, preço | 0,2000 (5,54×) | **0,0300 (0,83×)** |
| silício/lítio/tântalo, à venda | 500 | **2.500** |
| custo do silício de uma ocupação | 480 Fert$ | **72 Fert$** |

Maior Colonia tem 172 Fert$ — antes não alcançava, agora alcança com folga.

### Como foi feito, e como se desfaz

Pelo `OfertarComoGoverno::definir()`, que é o mesmo comando que o painel do admin chama — não por
`UPDATE` à mão. Com a quantidade igual ele só troca o preço; subindo-a, ele debita a diferença do
Tesouro para o escrow, e descê-la devolve. **Some código não mudou: nada a publicar.** O estado
anterior está registrado aqui e no scratchpad da sessão:
`silicio qtd=500 preco=200000 · litio qtd=500 preco=40000 · tantalo qtd=500 preco=90000`.

---

## D-230 — O GDD v40: a Alpha 2 inteira, e a seção que nenhuma versão anterior teve

Quarta regeneração do v36. O v39 (D-160) fechou no **D-159**; o v40 cobre **D-160 a D-229 — setenta
decisões**, que é a Alpha 2 do começo ao fim. É o maior salto entre versões deste documento, e por
um motivo simples: entre uma e outra o jogo ganhou **quatro sistemas que não existiam**.

### O que entrou

| seção | o que é | decisões |
|---|---|---|
| **§13 População** | teto habitacional, operadores, consumo, crescimento, escassez | D-167 a D-179, D-184, D-225 |
| **§14 Pesquisa** | trilhas, vagas do Laboratório, efeitos no motor | D-168 a D-172, D-190 |
| **§15 Eventos de mundo** | modificadores por janela, três graus de visibilidade | D-185 |
| **§16 O que o campo mediu** | *(novo em natureza — ver abaixo)* | D-219 a D-229 |

E, dentro das seções que já existiam: a **guerra federativa** (cerco de colônia, saque, capitulação,
tratado, neutralidade, Elo — D-193 a D-207), o **teto de estoque com piso pessoal** (D-191, D-192,
D-199), o **upgrade de veículos** (D-175, D-180, D-181) e a **curva do Marco recalibrada** (D-223).

### ⚠️ A §16 é de outra natureza, e é o que esta versão tem de diferente

Nenhum GDD anterior publicava **o que aconteceu com a regra depois de ela existir**. Este publica: 24
dias de produção, 29 colônias, e o que a medida encontrou — a guerra que nunca teve um combate, o XP
que caiu 98,5% em quatro semanas, a economia entre jogadores que não tinha sobre o que acontecer.

O critério: **metade das decisões desta leva saiu de uma medida que contradisse o que estava
escrito**. Um documento que só publica a regra e nunca o que aconteceu com ela é a metade que
envelhece primeiro — foi exatamente assim que o v35 virou mentira.

A §16.4 publica também as **três vezes em que uma medida minha quase virou conclusão errada** (taxa
nominal como previsão, banco de dev lido como produção, bots contados como jogadores). Registrar o
método que falha é mais útil do que registrar só o que deu certo.

### O que ficou de fora, e por quê

As ~25 decisões que só mudam **tela** e não mecânica: a revisão visual inteira (A2.V1 a A2.V6), o
backup do deploy (D-208/D-209) e o hardening do login (D-186). É o mesmo critério que a seção 0 já
publica. O que elas corrigiram **na regra** entrou nas seções correspondentes.

### Os números continuam não sendo digitados

O v40 lê duas fontes novas — `population_settings` e `estoque_settings` — além das cinco que o v39
já lia. São parâmetros **editáveis pelo operador sem deploy**: publicá-los de uma constante seria
publicar mentira já na semana seguinte. A tabela de operadores publica a **regra** (1 por nível) e
uma amostra que prova que ela é o que está no banco — não as 60 linhas, que seriam planilha.

35 testes de `tests/Gdd` verdes: as tabelas batem com o jogo.

### E a cópia à mão que o gerador não faz

`frontend/public/gdd.html` — o arquivo estático que o Vite publica em `/gdd.html` na landing page —
foi copiado com `/bin/cp -f` e conferido com `diff` (176.097 bytes idênticos). O gerador só escreve
em `docs/`, e **gerar o documento não alcança a landing page**; o alias `cp -i` do root já copiou
nada em silêncio uma vez.

O v39, o v38, o v36 e o v35 ficam **intocados**. Cada versão é um gerador novo, nunca uma edição
destrutiva da anterior — é isso que permite ler o que o jogo era em cada corte.

---

## D-232 — A Cesta de Presente, e os dois portões que o marco escondia

**Data:** 2026-08-07 · **Status:** decidido (arbitragem do usuário)

O usuário pediu duas coisas: distribuir uma **Cesta de Presente** a todo mundo por 30 dias, com
Fert$ suficiente para ocupar uma zona neutra mais 100, muita energia e um tanto de cada recurso; e
uma **aba de eventos** no `/central/admin`. O objetivo declarado era destravar o crescimento.

### A medida veio antes, e mudou o que foi construído

`RequisitosDeOcupacao::para()` rodado contra as 29 colônias da produção, colônia por colônia:

| trava | colônias travadas |
|---|---|
| Marco 20 (6.000 XP) | **27** de 29 |
| recursos / Fert$ | **28** de 29 |
| **colonos livres (2 por zona)** | **21** de 29 |

**1 zona ocupada de 77.** E os dois melhores jogadores não estavam presos pelo marco: *Maior
Colonia* (marco 21) está em **0 colonos livres**, e *Energizer do Gamer* (marco 20) em **−10**.

⚠️ **A cesta e o corte do marco, sozinhos, não destravariam 21 das 29.** `conferirPopulacao()` barra
antes de olhar recurso nenhum, e nada do que foi pedido tocava nisso. O usuário arbitrou: **isentar
colonos durante o evento**. Sem essa terceira peça, o evento teria sido entregue conforme o pedido e
falhado no objetivo — o pior resultado possível, porque pareceria funcionar.

### O motor não sabia presentear, e a razão para isso continua boa

A migration da A2.8 é categórica: *"o evento NUNCA escreve no ledger"*. A razão é de arquitetura — o
ledger registra o que **aconteceu**, e mudar uma taxa não é um acontecimento; quem credita é o tick.
Um evento que lançasse produção do nada faria a telemetria do D-163 mentir sobre a origem da receita.

Isso resolve a tempestade. Não resolve presente. **A regra passa a ter duas metades, e elas não se
contradizem:**

- **modificador** — muda a taxa, e **nunca** escreve no ledger;
- **cesta** — entrega uma vez, e **sempre** escreve, como `presente_evento`.

Emissão sem contrapartida já existe e tem forma declarada: o salário do conciliador (§26.7), a
recompensa de missão (§06), o subsídio do §24.7. Todas escrevem no ledger com tipo próprio. Sem
lançamento, o "Desde sua última visita" veria 20.000 de energia surgir e não teria o que dizer.

`presente_evento` entra em `DirecaoDoLedger::CONTA`, e **não** em `NAO_CONTA` ao lado do
`ajuste_admin`. Os dois criam valor sem origem econômica, por motivos opostos: o ajuste conserta um
estado errado (meta-jogo), a cesta é o Governo emitindo de propósito. A semana em que ela acontece
**deve** mostrar o salto.

### Por que não pelo Tesouro

O Tesouro sabe distribuir desde o D-113, e é o caminho certo para repartir o que foi **arrecadado**.
Uma cesta não foi arrecadada. E a medida decidia sozinha: o Tesouro tem **5.322** de metal bruto,
**1.649** de ligas e **2.361** de energia; 29 colônias pedem ~29.580, ~34.800 e muito mais. Passar
por ele exigiria creditar o caixa do nada primeiro, deixando um saldo de governo inflado depois que
o evento acabasse. O usuário escolheu a emissão direta.

### Os dois portões novos, e por que são pontuais

`ocupacao_marco` e `ocupacao_populacao`, ambos em `Modificadores::PONTUAIS`. *"Quanto XP o portão
pede AGORA?"* é pergunta de instante: ocupar acontece num clique, não há intervalo sobre o qual
ponderar, e uma média diria que o portão está meio aberto — que não é um estado que exista. Mesma
razão dos dois modificadores de guerra do A2.10.

⚠️ **A régua desce; o XP de ninguém sobe.** É o que garante que o mundo volte ao normal quando a
janela fechar. Um presente de XP seria irreversível, e o título do §05 passaria a mentir para sempre.
`Curva::marco()` continua dizendo a verdade sobre cada colônia durante o evento inteiro.

E o portão de ocupação sai do `ExigirMarco` genérico: aquele gate serve o Drone nível 2 também, e um
modificador que abrisse todos de uma vez seria mais fácil de escrever e impossível de dosar. Quem
calcula agora é `RequisitosDeOcupacao` — **o mesmo objeto que a tela lê**, que é a lição do D-224.

⚠️ E a comparação dentro de `para()` teve de mudar de `Curva::marco($xp) < 20` para `$xp <
xpExigido()`. Enquanto o portão era fixo as duas eram a mesma pergunta; com a régua em 300 XP deixam
de ser — 300 não é o piso de marco nenhum, e a versão por marco continuaria barrando quem o portão
já deixou passar.

### A idempotência é do banco, não da conferência

`game_event_entregas` com `unique(game_event_id, colony_id)`. A varredura é por **colônia sem linha
de entrega**, e não por "colônias que existiam quando o evento começou" — é o que faz quem funda no
dia 12 de uma janela de 30 receber também, decisão do usuário.

A ordem dentro da transação é deliberada: **marca primeiro, credita depois**. Se o INSERT colidir, a
transação inteira volta e nada foi creditado. O contrário daria recursos a quem já recebeu, e o
ledger é append-only — não haveria como desfazer.

### A aba, e o argumento que ela teve de enfrentar

O `fertways:evento` argumenta que criar evento **não** devia ser rota HTTP: um evento secreto
deixaria rastro no log de acesso do servidor web. O usuário pediu a aba mesmo assim, e o argumento
sobrevive quase inteiro — **o Apache registra método e URL, não corpo**. Um POST com o slug no corpo
não vaza; o que vazaria é um GET com `?slug=` na query. Por isso **a listagem não filtra por slug, e
nunca deve passar a filtrar**.

A aba é do **Dono** (a linha do D-61: "quem altera o estado do jogo de forma difícil de desfazer").
Criar **nunca ativa** — é a §Segurança da A2.8 traduzida para a web, com o segundo clique no lugar do
`--ativar` que falta. E um evento **já entregue não se reescreve**: as colônias servidas não recebem
de novo (chave única) e as seguintes receberiam a cesta nova, deixando metade do mundo com um
presente e metade com outro.

### O defeito que a aba achou na tela do jogador

`EventosDoMundo.tsx` escrevia `modificador === 'producao' ? 'produção' : 'consumo'`. São **seis**
modificadores desde a A2.10, e o ternário transformava trégua, custo de guerra e os dois portões em
"consumo". A Cesta teria chegado ao jogador como **"consumo −95% em tudo"** — pior do que não avisar,
porque parece informação. O tipo do cliente também estava em `'producao' | 'consumo'`, e o
TypeScript não pegou porque o servidor mandava `string`.

### O que o evento NÃO resolve, e a aba diz isso

Baixar o portão do XP não dá recurso a ninguém, e isentar colonos não constrói habitação. A aba
mostra as duas contas lado a lado — quantas colônias têm XP suficiente e quantas **conseguem ocupar
de fato** —, porque a diferença entre os dois números é a parte que o operador precisa ver.

1283 testes verdes (22 novos, em `CestaDePresenteTest` e `AdminEventosTest`).

### O que aconteceu quando foi ao ar (2026-08-07)

Publicado em `15d0c98`. Migration `[80] Ran` na produção, esquema conferido à mão no MariaDB —
`modificador varchar(40) NULL`, `efeito_bps int NULL`, `game_event_entregas` com o `unique` composto.
O scheduler lista `fertways:eventos-entregar` a cada 5 min.

Dois eventos, janela de 30 dias (07/08 22h06 → 06/09 22h06 no relógio de São Paulo; o app grava em
UTC, e o log soma 3 h):

| slug | modificador | efeito |
|---|---|---|
| `cesta_de_presente` | `ocupacao_marco` | −9.500 bps + a cesta |
| `cesta_de_presente_colonos` | `ocupacao_populacao` | −10.000 bps |

**São dois de propósito**, e não um. A regra do motor é um evento, um modificador — e ela existe
para que cancelar metade seja possível. Conflar 95% de XP e isenção de colonos atrás de um número só
economizaria uma linha e tiraria a alavanca que o operador vai querer primeiro.

A entrega: **29 de 29 colônias, uma vez cada** — 783 lançamentos de `presente_evento` (27 linhas ×
29) e 29 linhas em `game_event_entregas`. Conferido numa das mais pobres, *Colônia de Buscalouca*:
Fert$ 117 → 517, ligas 15 → 1.315, metal bruto 94 → 1.194, componentes 101 → 603, energia ~0 →
19.980.

O portão: **6.000 XP → 300**, e **2 colonos livres → 0**. Antes do evento, **0 das 29** colônias
conseguiam ocupar (todas tinham algo em `falta`); agora, **29 de 29**.

⚠️ **E nenhuma ocupou ainda.** Continuam 77 zonas, 1 ocupada, 76 livres — as mesmas de antes, e zero
`ZoneEvent` de ocupação nas 6 h seguintes. *Poder* ocupar não é *ocupar*: a decisão é do jogador, e o
que este evento mediu até agora é só que o caminho está aberto. Se a semana passar sem nenhuma
ocupação, o gargalo não era nenhum dos três portões — e essa é uma informação que só a espera dá.

(Duas afirmações minhas foram corrigidas aqui: eu li "76 livres" contra "77 total" e concluí que uma
zona havia sido ocupada durante a conferência; e disse "contra 1 antes" usando o número de zonas
ocupadas onde cabia o de colônias aptas, que era zero.)

⚠️ **O que o evento não faz, e vence junto com ele.** Isentar colonos não constrói habitação. As 21
colônias que estavam em 0 ou negativo continuam devendo operadores ao que têm de pé, e em 06/09 elas
estarão devendo o mesmo — com uma zona a mais para operar. O portão da população foi *contornado*,
não resolvido; resolvê-lo é mexer no teto habitacional, que é decisão de balanceamento, não de
evento.

---

## D-233 — A aba caiu com 500, e o teste que a aprovou a abria vazia

**Data:** 2026-08-08 · **Status:** corrigido

O usuário abriu `/central/admin/eventos` e recebeu 500. No log:

```
Call to undefined relationship [colony] on model [App\Models\GameEvent]
```

`PainelController::eventos()` fazia `GameEvent::with('colony')`, e o `GameEvent` **nunca teve essa
relação** — só a coluna `colony_id`. O erro é do D-232, publicado ontem.

### ⚠️ Por que o teste passou

`AdminEventosTest::test_a_aba_e_do_dono` faz `get('/admin/eventos')->assertOk()`, e passava. O
Laravel só chama `eagerLoadRelations()` **quando a consulta devolve linhas** — com a tabela vazia, o
`with()` de uma relação inexistente nunca é resolvido e nada quebra.

A aba passava vazia e caía cheia. É a mesma família do falso-verde do D-63: um teste que afirma o
caminho mais raro (nenhum evento) e nunca o normal.

**A regra que fica: página de listagem se testa COM linhas.** O teste novo põe **um evento de cada
forma** antes de abrir — vigente e rascunho, com modificador e sem, de mundo e de colônia, com cesta
e sem, cancelado, encerrado, secreto — e afirma o que cada modificador *significa* na tela, não só
que a página respondeu 200.

### E o teste novo achou dois defeitos meus

**1. `$base + [...]` no fixture, que é o operador errado.** A união de arrays do PHP mantém o valor
da **esquerda** em chave duplicada: `$base` trazia `'modificador' => null`, e todos os oito eventos
nasceram sem modificador nenhum. O teste teria passado "verde" exercitando um único caminho, o que é
pior do que não existir. `array_merge` — o da direita vence — é o certo aqui.

(A produção não foi afetada: os dois eventos vivos foram criados pelo `artisan`, e o
`xpExigido()` de 300 já estava conferido em campo.)

**2. `"só entrega cesta"` era incondicional.** A leitura de um evento sem modificador anunciava uma
cesta sem conferir se ela existe. A validação impede criar esse evento hoje, mas o banco não impede
nada — e a tela passa a dizer "não faz nada", que é a verdade quando é o caso.

### O que NÃO é deste defeito

Dois `Deadlock found` em `resources ... for update` no log de hoje. **Anteriores a esta mudança** —
há 4 no `laravel.log.gz` arquivado em 05/08. Ficam registrados aqui para não serem confundidos com o
500 da aba; investigá-los é trabalho à parte.

1287 testes verdes.

---

## D-234 — A segunda cesta, e a confirmação de que emitir não é gastar o Tesouro

**Data:** 2026-08-08 · **Status:** entregue

O usuário pediu uma segunda cesta só de Ligas Metálicas, mais 5.000 de energia para todos, com uma
condição declarada: *"essa energia não vai sair do fundo do governo, vamos 'inventar' ela"*.

**A condição já era o comportamento, e não só para a energia — para a cesta inteira.** O D-232
decidiu isso e a razão está lá: o Tesouro reparte o que **arrecadou** (o tributo do §2.1), e presente
não foi arrecadado. `EntregarCestas` credita a colônia direto e escreve `presente_evento` no ledger;
não há uma linha sequer que toque `TreasuryHolding`.

Conferido em campo antes de executar, e é o tipo de coisa que não se afirma de memória:

| | antes | depois |
|---|---|---|
| Tesouro, ligas | 1.471 | **1.471** |
| Tesouro, energia | 2.658 | **2.658** |
| `treasury_ledger` tipo `distribuicao` no dia | 0 | **0** |

### Por que 1.300 de ligas

Medido, não arbitrado. Das 29 colônias, **20 estavam travadas por Ligas Metálicas e por mais nada** —
déficit mínimo 530, mediana 1.122, **máximo 1.189**. 1.300 cobre o pior caso com folga e é o mesmo
número da primeira cesta, o que mantém as duas comparáveis quando alguém for medir o efeito.

### O primeiro evento sem modificador nenhum

`cesta_de_presente_2` não mexe em taxa alguma — os dois portões já estão abertos pelos eventos
irmãos, e abrir de novo não abriria mais. É o primeiro uso real do `modificador` nulo que o D-232
criou, e a prova de que valeu não gravar `producao 0` para satisfazer um `NOT NULL`.

A janela é de **705 h**, e não de 30 dias: termina junto com as duas primeiras (06/09 22h06 em São
Paulo). A entrega é única, no ato; a janela só governa a faixa do jogador e quem fundar no meio — e
três faixas sumindo em dias diferentes seria confusão sem ganho.

### O resultado, e o que ele ainda não responde

29 de 29 podem ocupar de novo. 841 lançamentos `presente_evento` no total (783 + 29 × 2).

⚠️ **A primeira cesta virou prédio em 13 horas** — 5.925 ligas em 60 obras, e **zero produzidas**. É
o motivo desta segunda, e é também o aviso: nada impede que a segunda vá pelo mesmo caminho. Os
jogadores estão escolhendo construir, não ocupar, e isso é uma resposta legítima — só não é a que o
evento foi criado para provocar.

⚠️ **A causa continua de pé: 19 das 29 colônias não têm Indústria Siderúrgica**, e portanto não
produzem Ligas Metálicas. Para elas a liga só entra por presente ou comércio, e o Posto de Comando
pede 1.200. Uma terceira cesta seria a terceira dose do mesmo remédio; o que ataca a causa é o custo
da Siderúrgica ou a receita da liga, que são balanceamento e não evento — e ficam como decisão em
aberto.
