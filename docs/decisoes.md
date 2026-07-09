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
