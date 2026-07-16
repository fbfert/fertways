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
