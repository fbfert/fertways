# FERTWAYS — GDD do Estado do Jogo

**Fotografia de 2026-08-03.** Todos os números foram medidos contra a produção no dia, não lembrados.

---

## O que este documento é, e o que ele não é

O `GDD_ALPHA2.md` diz o que o jogo **deve ser**. O `ROADMAP_ALPHA2.md` diz em que ordem construir. O
`BALANCEAMENTO.md` guarda as rodadas de simulação. O `decisoes.md` tem as 195 decisões, uma a uma, com
o raciocínio de cada.

Faltava um documento que dissesse **o que o jogo é hoje** — e a diferença entre intenção e estado foi,
nesta Alpha, o defeito mais caro que encontrei. Repetidas vezes uma fase estava "entregue" e a mecânica
não fazia nada: `vehicles.level` sem rota, `population_settings.ativo` sem leitor, `.botao` sem
definição, `EfeitosDaPesquisa` sem consumidor, `ConcluirPesquisa` sem quem o chamasse.

⚠️ **Por isso este documento separa três estados, e nunca os mistura:** o que está **no ar**, o que
está **construído e dormente**, e o que **não existe**. "Entregue" não é um deles.

---

## 1. O mundo, medido

| | |
|---|---|
| colônias | **29** |
| população | **559** colonos (eram 535 no grandfathering — está crescendo) |
| zonas neutras | 77, das quais **1 ocupada** |
| federações ativas | **1** |
| alianças firmadas | 0 |
| guerras federativas | 0 |
| tecnologias pesquisadas | **0** de 8 disponíveis |
| eventos de mundo ativos | 0 |
| itens únicos da Endurance | 0 |
| veículos acima do nível 1 | **0** de 51 |
| eventos de telemetria | 219 |

⚠️ **A leitura mais importante deste quadro não é o que existe, é o que não foi usado.** Sete
mecânicas entregues não têm um único uso: nenhuma tecnologia pesquisada, nenhuma aliança, nenhum
veículo melhorado, nenhum item único, nenhum evento.

Isso não significa que estejam quebradas — quase todas subiram há horas ou dias. Significa que
**nenhuma foi validada por jogador**, e que qualquer número deste documento é hipótese até que seja.

---

## 2. O que está NO AR

### 2.1 População (A2.2, A2.6 — D-176 a D-179, D-184)

Cinco estados, dos quais só o **total** é guardado; o resto é derivado, porque contadores paralelos
criam duas verdades e a segunda dessincroniza na primeira demolição esquecida.

| regra | valor | origem |
|---|---|---|
| capacidade habitacional | base 10, ×1,65 por nível da Estrutura de Sobrevivência | medido (rodada 5) |
| crescimento | 70 bps/h — recuperação de metade ao teto em **4 dias** | medido (rodada 6) |
| consumo | água 0,1 · oxigênio 0,12 · biomassa 0,08 por colono/hora | medido (rodada 6) |
| operadores por construção | 1 por nível, só em quem produz | medido (rodada 5) |
| operadores por zona | 2 no nível 1, 16 no nível 10 | hipótese |

⚠️ **Energia NÃO entra na cesta de consumo**, e isso foi decidido por medição (D-184): com ela,
**17 das 29 colônias** cairiam a metade da produção no instante da ativação. Energia é estoque **e**
fluxo — toda construção já a debita operacionalmente —, e cobrá-la de novo por colono é dupla
contagem. Estoque zero de energia é o estado *normal* de quem roda no que produz, não fome.

**O que a escassez faz:** degrada a produção até um piso de 50%, e **nunca mata**. O gargalo é o
recurso mais escasso, não a média — média esconderia a colônia nadando em água e sem oxigênio.

### 2.2 Zonas neutras × população (A2.6 — D-184)

Operadores são **alocados explicitamente**, não derivados do nível: derivar não produzia escolha
nenhuma, e a decisão que a fase cria é *"quais zonas consigo manter operando"*.

- zona desfalcada **extrai menos e continua sendo do dono** (§6.6);
- o **custo de manutenção não cai junto** — é essa assimetria que torna a falta de gente um problema
  econômico, e não um "rende menos" neutro;
- o **Abrigo de Robôs dispensa um operador por nível**, com piso de 1;
- ocupar zona nova **exige gente livre**. Medido: **9 das 29** colônias conseguem hoje.

⚠️ *"Transferência colônia → zona"* e *"retorno"* foram lidos como **alocar e devolver, instantâneos**.
Colono em trânsito seria sistema novo, e o GDD não publica tempo de deslocamento de pessoas. **Isto
estreita uma entrega do roadmap, e está escrito.**

### 2.3 Pesquisa (A2.3 — D-168 a D-172, D-190)

Oito tecnologias em cinco trilhas, com vagas vindas do Laboratório. Ligada em 2026-08-02.

⚠️ **Ligar a chave, sozinho, não teria feito nada.** Não havia rota, não havia tela, ninguém consumia
`EfeitosDaPesquisa`, e ninguém chamava `ConcluirPesquisa` — uma pesquisa iniciada **nunca terminaria**,
e a colônia perderia a vaga do Laboratório para sempre. Os quatro foram construídos antes de virar.

**O teto de bônus é AGREGADO** entre Endurance e pesquisa: duas fontes de 30% dariam 60%, e o teto
existe para limitar o total, não cada origem.

### 2.4 Teto de estoque (A2.7 item 4 — D-191, D-192)

O teto **trava, não derrama**: ao encher, a produção para e nada transborda. O jogador perde
oportunidade, nunca estoque.

⚠️ **Não podia ser ligado como estava.** As 29 colônias tinham ao menos um dos quatro essenciais acima
da curva, e o grandfathering pelo prédio era **impossível** — elas precisariam de Depósito Local n9 a
n18, e o prédio para no 10. A mediana de oxigênio do mundo (90.201) não cabe nem no nível máximo.

A saída foi o **piso pessoal**: o teto de cada linha é `max(curva do nível, o que a colônia já tinha ×
1,2)`, gravado em `resources.storage_cap` — coluna que existia desde o D-14 e estava NULL nas 754
linhas. **112 pisos** foram gravados.

⚠️ **A folga de 20% não é conforto: é o que cumpre o §6.7.** Piso igual ao estoque exato faria a
produção parar no mesmo instante da virada — a regra que o piso existe para evitar.

⚠️ **O preço, aceito com o custo na mesa:** o veterano cujo estoque passa da capacidade do nível 10
**não consegue subir o próprio teto construindo**. O piso dele *é* o teto, para sempre.

### 2.5 Federação (A2.5 — D-174, D-182, D-183)

Doze membros, quatro cargos, fundo, extrato, missões — tudo anterior. O que a A2.5 acrescentou:

- **Aliança entre federações**: consentimento mútuo para entrar, **unilateral para sair**. Entrar exige
  acordo; sair não exige refém;
- **desconto de tributo de 20% entre aliadas**, contra 50% entre filiadas. ⚠️ **Menor de propósito**:
  se fosse igual, o teto de 12 membros viraria letra morta;
- ⚠️ **o teto antimonopólio conta o BLOCO**, não a federação. Três federações aliadas são até 36
  colônias operando juntas — sem isso, aliar-se seria lavanderia de monopólio;
- **objetivos federativos** pagam **ao fundo**, não a quem cumpriu. Era o que faltava para eles serem
  federativos: antes, doze membros cumpriam um objetivo comum e nada era produzido para a federação.

### 2.6 Motor de Eventos (A2.8 — D-185, D-194)

Uma linha de tabela substitui um `if` no tick. Quatro modificadores: **produção**, **consumo**,
**guerra_declaracao** e **guerra_custo**.

⚠️ **Duas semânticas distintas, e confundi-las seria o pior tipo de bug:**

| | como se mede | por quê |
|---|---|---|
| produção, consumo | **média ponderada pelo tempo** | são taxas, lineares no tempo — a média é EXATA, não aproximação |
| guerra | **por instante** | *"há trégua agora?"* não tem média; "meio bloqueada" seria um número plausível e errado |

`Modificadores::para()` **recusa** os pontuais com exceção, em vez de devolver uma média que ninguém
desconfiaria.

**Três promessas com teste:** o evento nunca escreve no ledger (ele muda a taxa; quem credita é o
tick); o modificador é reconstruível no passado; e **cancelar encerra o futuro preservando o passado**
— apagar faria o "Desde sua última visita" dizer que a produção caiu sem motivo.

### 2.7 Endurance (A2.9 — D-187)

Itens **únicos** ganharam identidade persistente e biografia (§11.1): selo, descobridor, dono atual e
histórico append-only. Comum e raro seguem fungíveis, como o roadmap manda.

⚠️ **O descobridor nunca muda.** O que faz um único valer mais que um raro não é a escassez — raro
também é escasso — é ter uma origem que **ninguém pode reescrever**. Nem nós: o histórico tranca
`update` e `delete`.

### 2.8 Transporte (A2.7 — D-175, D-180, D-181)

`vehicles.level` existia desde sempre **sem caminho para subir**. Agora sobe: capacidade +15%/nível e
manutenção +20%/nível — a contrapartida que torna o upgrade escolha, não botão.

Medido (rodada 9): a mesma tonelagem custa **+11,7% de manutenção** e cabe em **1,60× menos viagens**.
Troca custo por unidade por vazão por veículo. ⚠️ Velocidade **não** entra: é traço do tipo, e se o
nível acelerasse, a distância encolheria a cada upgrade — e distância é pilar declarado do jogo.

### 2.9 Guerra federativa — esqueleto (A2.10 — D-193, D-195)

Declaração, prazo de **7 dias**, cooldown de par de 7 dias, aviso público no mural e no chat.

- custo de **500 F$ + 50 Nióbio, do fundo** — guerra é decisão coletiva;
- **não há recusa**: quem é declarado está em guerra;
- **declarar a uma aliada rompe a aliança** — e só se a guerra realmente acontecer;
- a **trégua do Governo** fecha o portão, pelo Motor de Eventos.

### 2.10 Guerra federativa — alvos e espólio (A2.10 — D-201 a D-205)

O esqueleto ganhou o que faltava, em quatro fatias:

- **neutralidade declarada** (D-201) — só antes da guerra; inatividade **não** protege;
- **cerco de colônia** (D-203) — o §01 revogado *só* dentro de guerra declarada, saque limitado ao
  **excedente do Depósito**, Capital e Espaçoporto fora, exige Quartel;
- **a porta** (D-204) — `GET /war/inimigos` e `POST /war/attack-colony`, com a tela no Quartel;
- **saque total da zona conquistada** (D-205) — em guerra federativa a **invasão** leva o estoque
  **inteiro** da zona: o Depósito dela deixa de proteger. Só a invasão; o cerco de zona continua nos
  30% do exposto.

⚠️ **O butim atravessa o teto de estoque da colônia** — `TetoDoEstoque` governa a produção, não o
saque. Escolhido: nada se destrói (§6.6), e a colônia acima do teto para de produzir aquele recurso
até escoá-lo. Medido: um saque total daquela única zona ocupada (**34.438**) estoura a folga de
todas as 29 colônias (a maior é **14.663**).

⚠️ **E nada disto é exercitável por jogador:** o mundo tem **uma federação**. Toda a guerra
federativa vive hoje só nos testes.

### 2.11 Segurança (A2.12 — D-186)

⚠️ `/login` e `/register` **aceitavam tentativas sem limite**, com o jogo no ar. Era a falha mais séria
da Alpha 2, e estava lá desde o começo. Agora: dez erros por minuto por **e-mail + IP**, e **só o
fracasso conta** — o acerto zera, senão quem entra e sai várias vezes bateria no teto por usar o jogo
direito.

---

## 3. O que está CONSTRUÍDO E DORMENTE

**Nada.** Todas as chaves-mestras foram viradas: população, pesquisa e teto de estoque.

⚠️ Mas há uma peça **sem consumidor**, e ela não é chave: os modificadores `guerra_declaracao` e
`guerra_custo` do Motor de Eventos foram publicados no D-194 antes de existir quem os lesse. O
consumidor chegou na fatia seguinte (D-195) — a trégua e o custo de declaração. **A dívida foi paga em
um dia**, e isso está registrado porque a promessa era essa.

---

## 4. O que NÃO existe

| | |
|---|---|
| **capitulação e tratados** | decididos (o vencedor escolhe entre uma zona e Fert$), não construídos |
| **uma segunda federação** | o mundo tem **uma**; nenhum jogador consegue exercitar guerra, blocos ou diplomacia |
| **ranking de guerra** | fórmula ainda é desenho, não escolha binária |
| **eventos da Endurance** no motor | adiado pelo Dono |
| **descoberta por escavação** | o item único nasce na compra; escavar não existe |
| **A2.V2 a A2.V6** | HUD, colônia, mapa, Capital, Endurance, combate |
| **modificadores de taxa, logística, construção, pesquisa, população, território** | o motor promete seis; tem dois |

---

## 5. Os invariantes — as promessas que o código guarda

Estas não são preferências. São regras que apareceram repetidamente, cada uma paga com um erro, e que
o código hoje **protege com teste**.

### 5.1 Degrada, não se perde (§6.6)

Escassez de insumo, falta de operador, população acima do teto habitacional, estoque acima do teto:
**nada disso destrói o que existe**. Trava o ganho, e reverte sozinho quando a causa passa.

> Num jogo persistente sem reset, punir quem passou o fim de semana fora não é dificuldade, é
> hostilidade.

### 5.2 Nada existente para de funcionar por regra nova (§6.7)

Toda ativação desta Alpha exigiu grandfathering, e **duas foram salvas por medir antes**:

- a população teria deixado 21 de 29 colônias em déficit (D-178);
- a penalidade de eficiência teria derrubado 17 de 29 a metade da produção (D-184);
- o teto de estoque teria parado **as 29** (D-191).

⚠️ **A única exceção aceita** é a guerra federativa: colônia saqueável + 7 dias + inatividade sem
proteção colide com o §1.1, e foi escolhido de olhos abertos, com a neutralidade declarada como
mitigação.

### 5.3 O ledger é o registro do que aconteceu

Direção vem do **sinal**, nunca do tipo (D-164). Evento **nunca** escreve nele. Livros de história —
`federation_ledger`, `endurance_item_transfers` — são **append-only**: biografia que se edita depois
deixa de valer como biografia.

### 5.4 Chave-mestra: nasce desligada, e tem de ligar alguma coisa

O mundo não tem reset, então mecânica nova entra dormente. ⚠️ **E a chave precisa ser lida por alguém
antes de ser virada** — três vezes nesta Alpha a chave não ligava nada, e virá-la teria sido mentir.

### 5.5 Peça sem uso apodrece

O erro mais repetido desta base. Rota sem tela, efeito sem consumidor, constante sem leitor, parâmetro
plano em todos os níveis. **Toda entrega agora tem de responder: quem chama isto?**

### 5.6 Simulação não é o mundo

A trilha A2.S mede o **ritmo**, que é a pergunta certa. O que ela não mede é o **acervo** de um mundo
que rodou meses sem limite — e foi por isso que a curva de estoque, validada em simulação, estava
entre 6× e 44× fora da realidade.

### 5.7 Medir antes, e contra a produção

⚠️ E **medir a coisa certa**: a conferência do teto habitacional (D-178) rodou antes de semear os
requisitos de operador e devolveu "zero acima do teto" — aritmética sobre o nada. O ensaio a seco do
comando pegou o número real: 21 de 29.

---

## 6. Os números: o que é medido e o que é chute

| medido pela trilha A2.S | rodada |
|---|---|
| capacidade e operadores da população | 5 |
| consumo e crescimento | 6 e 7 |
| curva do teto de estoque | 8 |
| economia do upgrade de veículo | 9 |

**Todo o resto é HIPÓTESE**, e isto inclui: operadores por zona, custo e duração da guerra, cooldown,
prêmios dos objetivos federativos, desconto entre aliadas, teto de aliadas, e os oito custos da árvore
de pesquisa.

⚠️ O `BALANCEAMENTO.md` §16 exige hipótese, dados, simulação, impacto, risco e rollback antes de
promover número. **Nenhum número da guerra federativa passou por isso.**

---

## 7. O que vem a seguir

1. ✅ **Neutralidade declarada** — feita (D-201), e antes do saque, como o D-193 exigia;
2. ✅ **Alvos e espólio** — feitos (D-203 a D-205), cada um precedido da medição contra a produção
   que salvou as três ativações anteriores;
3. **Capitulação e tratados, e a fórmula do ranking** — o que resta da A2.10. A fórmula segue sendo
   desenho: quando chegar a vez dela, o que se leva ao Dono são **propostas de fórmula**, não opções;
4. **Telemetria de retorno de quem foi saqueado ausente** — ainda não existe, e a decisão que a torna
   necessária já está no ar;
5. **A2.V2 a A2.V6** — a maior dívida restante.

### E duas coisas que não são fase nenhuma

⚠️ **Restauração de backup nunca foi testada.** Existem sete, verificados por conteúdo, e nenhum foi
restaurado. **Backup que ninguém restaurou é hipótese.**

⚠️ **O mundo tem uma federação.** A guerra federativa, a diplomacia e o bloco antimonopólio não têm
como ser exercitados por jogador nenhum. Alavancas baratas — tornar o desconto visível no mercado,
mostrar o que as zonas livres rendem, um objetivo de primeira federação — custam uma fração da A2.10 e
são o que **produz** os adversários que ela pressupõe.
