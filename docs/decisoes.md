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
