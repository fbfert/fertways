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
