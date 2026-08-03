# FERTWAYS — GDD da Guerra Federativa (A2.10)

> **Status: DECIDIDO em 2026-08-03.** As doze decisões foram tomadas pelo Dono; a tabela do fim
> registra cada uma. Onde a escolha divergiu da minha recomendação, o documento diz as duas coisas —
> o que eu recomendaria e o que foi decidido —, porque quem ler daqui a seis meses precisa saber que
> a alternativa foi considerada e descartada, e não esquecida.
>
> **Status original: RASCUNHO PARA DECISÃO.** O roadmap da A2.10 diz que esta é a *"única fase da Alpha 2 que
> exige um documento de design próprio antes de qualquer linha de código"*, e que *"nenhum prompt
> deve ser disparado enquanto ele não existir"*. Este é esse documento.
>
> ⚠️ **As seções abaixo preservam o texto original do rascunho**, com os ❓ como estavam. Onde a
> decisão tomada divergiu do que a seção recomenda, vale **a tabela do fim** — ela é a autoridade.
> Reescrever o corpo apagaria o raciocínio que levou à pergunta, e é justamente ele que explica por
> que a alternativa existia.

---

## 0. O que já existe, e que este desenho não pode ignorar

Guerra **não é assunto novo** no FERTWAYS. O que existe hoje:

| peça | o que faz |
|---|---|
| `Domain\Guerra\Atacar` | ataca **Zona Neutra**, nunca colônia |
| `ResolverCombates` | as 3 rodadas do cerco (§28.10) |
| `Forcas`, `Sorteio` | força de ataque e defesa, com Muralha/Torre/Bastião |
| `Protegido` | o que cabe no Depósito está a salvo; o excedente é saqueável |
| `RomperCerco`, `Reforcar`, `ChegarReforcos` | quebrar cerco e mandar tropa |
| `RankingDeGuerras` | o placar |
| `FabricarUnidade`, `ComprarNiobio` | a economia militar |
| `war_settings` | 11 parâmetros já versionados |

⚠️ **A guerra atual é colônia contra zona.** Não há guerra entre federações, e **não há saque de
colônia** — o `Silo` calcula "exposto" e o docblock dele diz, com todas as letras, que *"conectar
'exposto' a alguma consequência de jogo é uma entrega futura"*.

**Este documento define a camada federativa por cima do que existe. Não propõe reconstruir nada.**

---

## ⚠️ 1. O fato que muda tudo: não há com quem guerrear

Medido na produção em 2026-08-02:

| | |
|---|---|
| colônias | 29 |
| **federações ativas** | **1** |
| **zonas neutras ocupadas** | **1** de 77 |
| alianças firmadas | 0 |

**Uma federação não entra em guerra com ninguém, e uma zona ocupada não é geopolítica — é uma zona.**

> ⚠️ **Correção de 2026-08-03:** este quadro dizia "1 de **120**". O mundo tem **77** zonas; 120 é o
> número do semeador do e2e, e eu o carreguei para um documento sobre produção sem conferir. A
> conclusão não muda — muda o fato, e fato errado em documento de decisão é como erro entra em
> decisão.

Isso não invalida a fase; muda a **ordem**. Construir guerra federativa hoje seria erguer um sistema
que nenhum jogador consegue usar, e a Alpha 2 inteira vem provando que peça sem uso apodrece
(`vehicles.level` sem rota, `.botao` sem definição, `population_settings.ativo` sem leitor).

❓ **Decisão 1 — quando construir.** Recomendo ✅ **esperar um gatilho de mundo**: pelo menos **3
federações ativas** e **15% das zonas ocupadas**. Enquanto isso não acontecer, o esforço rende mais
em A2.V (revisão visual) e em fazer o território valer a pena — que é o que produz as federações que
a guerra pressupõe.

---

## 2. Objetivo da guerra

✅ **A guerra federativa disputa território e influência, nunca a casa de ninguém.**

A colônia é o lar do jogador e o único lugar onde ele guarda o que construiu. Um jogo persistente
**sem reset** que permita saquear a casa cria uma classe de vítima permanente: quem perdeu uma vez
perde de novo, porque ficou mais fraco. É o oposto do §6.6 (*"degrada, não se perde"*) e do §1.1
(*"não exigir login constante"*).

O que se disputa:

- **Zonas Neutras** — já são o objeto da guerra atual;
- **Influência** — ranking federativo, que abre acesso a conteúdo avançado (A2.5 já prevê "acesso a
  conteúdo avançado" como atributo de federação).

❓ **Decisão 2.** Confirmar que **colônia nunca é alvo**, nem em guerra federativa. Recomendo ✅ sim,
e que o "exposto" do `Silo` continue sem consequência até que exista um desenho próprio de saque de
colônia — que **não é** esta fase.

> **DECIDIDO ao contrário:** a colônia **é** alvo, limitada ao **excedente do Depósito** — o
> "exposto" que o `Silo` já calcula e que estava sem consequência desde o D-107. O protegido nunca é
> tocado. A peça deixa de ser inerte, e o texto acima fica como o registro do que se pesou contra.

---

## 3. Declaração

✅ **Quem declara é o Líder ou o Diplomata** — a mesma permissão que já trata de aliança (A2.5,
`podeConvidarParaFederacao`). Quem fala com gente de fora é uma pessoa só.

✅ **Declarar custa**, e o custo é do **fundo da federação**, não do bolso de quem declara. Guerra é
decisão coletiva; se o Líder pudesse declarar de graça, a federação inteira pagaria a conta de uma
escolha individual.

✅ **Declaração é pública e imediata.** Aparece no mural de Notícias e no chat das duas federações.
Guerra secreta é emboscada, e emboscada num jogo assíncrono pune quem estava dormindo.

❓ **Decisão 3 — o custo.** Sugestão inicial: um valor em Fert$ do fundo **mais** um insumo militar
(Nióbio), para que declarar exija preparo e não só vontade. Números por simulação da trilha A2.S.

❓ **Decisão 4 — há recusa?** Recomendo ✅ **não**: quem é declarado está em guerra. Poder recusar
transformaria a declaração num pedido, e um agressor nunca pede licença. A defesa é a capitulação
(§9), que existe e é barata.

---

## 4. Duração

✅ **Guerra tem prazo, e o prazo é curto.** Sugestão: **72 horas**, com fim automático.

Guerra sem fim vira estado permanente, e estado permanente deixa de ser evento — vira o clima. Além
disso, um jogo de sessões de 5–10 minutos (§15) não sustenta mobilização indefinida.

✅ **O prazo não se estende por atividade.** Renovar por combate faria a guerra durar enquanto houver
um insone de cada lado.

❓ **Decisão 5.** 72 h é palpite fundamentado, não medida.

> **DECIDIDO: 7 dias.** Campanha longa, com espaço para reviravolta, reforço e diplomacia no meio.
> ⚠️ A rodada da trilha A2.S continua devendo — o intervalo mediano entre sessões, que a telemetria
> da A2.0 já sabe medir, é o que dirá se sete dias cabem no ritmo real de jogo.

---

## 5. Alvos

✅ **Só Zonas Neutras ocupadas pela federação inimiga.** E com duas travas herdadas:

- **A proteção de zona recém-ocupada continua valendo** (`protected_until`). Ocupar e ser atacado no
  mesmo dia é armadilha;
- ~~**O Depósito continua protegendo** o que cabe nele (`Protegido`).~~
  > **DECIDIDO ao contrário para a zona:** em guerra federativa a zona conquistada é **totalmente
  > saqueável**, e o D-66/D-107 fica revogado nesse contexto. Ver a tensão 2 no fim do documento.
  > Na **colônia**, ao contrário, o Depósito continua protegendo: lá só o excedente é espólio.

❓ **Decisão 6 — Capital e Espaçoporto.** Recomendo ✅ **fora**: são infraestrutura de todos, e
tomá-las daria a uma federação poder sobre o jogo dos outros, o que nenhum ranking deveria comprar.

---

## 6. Participação

✅ **Membro de federação em guerra está em guerra.** Não há opt-out individual: a federação é a
unidade política, e permitir que membros escolham caso a caso esvaziaria a decisão do Líder.

✅ **Mas participação é voluntária.** Estar em guerra significa que suas zonas são alvo e que você
*pode* atacar — nunca que você *tem* de atacar. Quem não entra em campo não é punido: perde a chance
de espólio, e isso já é custo suficiente.

⚠️ **Aqui a A2.6 morde.** Atacar consome operadores da colônia (a tropa sai de algum lugar), e a
população passou a ser recurso escasso. Uma federação em guerra que esvazie as zonas de operadores
vê a própria extração degradar (§6.6) — **e isso é bom**: a guerra deve custar economia, não só
Nióbio.

❓ **Decisão 7.** Confirmar que a tropa consome operadores, e em que proporção.

---

## 7. Reforços

✅ **Aliados podem reforçar, mas não são arrastados.** A aliança da A2.5 dá desconto de tributo e conta
no bloco antimonopólio; **não** cria obrigação militar.

Aliança que arrasta para a guerra faria toda aliança ser uma decisão de guerra, e a A2.5 a desenhou
como acordo econômico. Quem quiser pacto militar declara guerra junto — voluntariamente, e com o
próprio custo de declaração.

⚠️ **E o bloco antimonopólio vale na guerra.** Se três federações aliadas atacam juntas, o espólio
territorial conta contra o teto de 20% **do bloco** — a mesma trava do D-182. Sem isso, a guerra
viraria a porta dos fundos do monopólio que a A2.5 fechou pela porta da frente.

---

## 8. Tratados

✅ **Três estados, e agora o terceiro faz sentido.** A A2.5 entregou `aliada` e `neutra`
deliberadamente **sem** `hostil`, porque hostilidade sem guerra não faria nada. A A2.10 é quem lhe dá
consequência.

| estado | como se entra | como se sai |
|---|---|---|
| **aliada** | consentimento mútuo | unilateral |
| **neutra** | padrão | — |
| **em guerra** | declaração unilateral | prazo, capitulação ou tratado de paz |

✅ **Tratado de paz encerra antes do prazo**, e exige consentimento mútuo — simétrico à aliança.

❓ **Decisão 8 — aliança e guerra são exclusivas?** Recomendo ✅ **sim**: declarar guerra a uma aliada
rompe a aliança automaticamente, e a tela avisa antes. Deixar as duas coexistirem tornaria o
vocabulário do jogo incoerente.

---

## 9. Capitulação

✅ **Sempre disponível, e sempre mais barata do que perder.**

Quem capitula: cede um espólio negociado (uma zona, ou um valor do fundo) e a guerra acaba na hora.
Quem não pode capitular fica preso numa derrota que já aconteceu, e isso não é dificuldade — é
tempo perdido.

✅ **Capitular não humilha.** Sem penalidade de reputação, sem marca permanente. O jogo já tem
reputação com consequência (§26) e ela existe para quebra de acordo, não para derrota militar.

❓ **Decisão 9 — o preço da capitulação.** Sugestão: **uma zona à escolha do vencedor** entre as do
perdedor, ou o equivalente em Fert$ do fundo se ele não tiver zonas.

---

## 10. Cooldown

✅ **Depois da guerra, as duas federações ficam impedidas de se declarar de novo por 7 dias.**

Sem isso, uma federação forte pode manter outra em guerra permanente — declarando de novo assim que
o prazo acaba. É o mesmo problema do assédio, resolvido do mesmo jeito: com relógio.

✅ **O cooldown é do PAR, não da federação.** Impedir de guerrear qualquer um durante 7 dias puniria
quem foi atacado. Impedir só aquele par resolve o assédio sem congelar a geopolítica.

---

## 11. Recompensa

✅ **O espólio é territorial e vai para a FEDERAÇÃO**, não para quem deu o último golpe.

É o mesmo princípio do D-183 (objetivos federativos): o produto do esforço coletivo é coletivo, e o
Líder/Intendente distribui. Espólio individual faria a guerra ser disputada dentro da própria
federação.

✅ **E há reconhecimento pessoal**: XP e ranking para quem lutou. Quem trabalhou merece
reconhecimento — o que muda é de quem é o **produto**.

---

## 12. Perda

✅ **Perder território, nunca perder a colônia.** E o que se perde tem de ser **recuperável**: a zona
tomada pode ser retomada, comprada de volta, ou substituída por outra das 77.

⚠️ **Nenhuma perda permanente**, em nenhuma hipótese. Num mundo sem reset, perda permanente acumula
para sempre e produz uma casta de perdedores que nunca mais alcança. Esse é o defeito que mata jogos
persistentes, e o FERTWAYS já escolheu o lado oposto três vezes (§6.6, D-178, D-184).

---

## 13. Território

✅ **A zona tomada muda de dono e mantém tudo**: nível, estruturas, depósito protegido. Destruir o
que o perdedor construiu seria queimar valor do jogo inteiro para que ninguém tivesse.

⚠️ **E a zona tomada chega DESFALCADA de operadores** — a equipe era do antigo dono e voltou para
casa dele. O novo dono precisa povoá-la (A2.6), e até lá ela degrada. É a consequência mais elegante
que a A2.6 nos deu de graça: conquistar não é o fim do custo.

---

## 14. Ranking

✅ **O ranking mede guerras travadas, não guerras vencidas.**

Ranking por vitória premia escolher inimigo fraco. Ranking que considera a **diferença de força**
entre os dois lados premia enfrentar quem é páreo — e é isso que produz a geopolítica que a fase quer.

❓ **Decisão 10 — a fórmula.** Precisa de desenho próprio. `RankingDeGuerras` já existe e serve de
ponto de partida.

---

## ⚠️ 15. Abuso por contas vinculadas

**O item mais difícil da lista, e o que mais pode estragar a fase.**

O ataque: alguém cria uma segunda federação com contas próprias, declara guerra a si mesmo, capitula
de propósito e transfere território "legitimamente" — lavando a concentração que o teto de 20%
existe para impedir.

O que **já ajuda**:

- o teto antimonopólio conta o **bloco** (D-182), não a federação;
- o `federation_ledger` e o histórico de transferência são append-only;
- a telemetria da A2.0 registra sessão, e contas coordenadas têm padrão de horário.

O que **falta**:

❓ **Decisão 11.** Recomendo ✅ **não tentar impedir por regra, e sim tornar caro e visível**:

1. **Transferência territorial por guerra conta para o teto do bloco** — se a lavagem estoura o teto,
   ela simplesmente não completa;
2. **Toda guerra fica no registro público** (mural de Notícias), com as duas partes nomeadas. Guerra
   de araque repetida entre o mesmo par vira padrão óbvio a olho nu;
3. **O cooldown de par** (§10) limita a frequência da lavagem;
4. **Auditoria de operador** (§18) com um relatório de pares recorrentes.

Tentar detectar multiconta por heurística de IP produz falso positivo em casa com dois jogadores — e
punir irmão que joga junto é pior do que deixar passar um trapaceiro.

---

## 16. Abandono

✅ **Federação sem atividade não é alvo.** Se todos os membros estão inativos há mais de ❓ *N* dias, a
federação entra em **neutralidade forçada** e não pode ser declarada.

O §1.1 do GDD proíbe exigir login constante. Uma federação que passou o fim de semana fora não pode
voltar sem território — isso não é dificuldade, é hostilidade, e é a mesma frase que já governa o
§6.6 e o teto habitacional.

✅ **E a guerra em curso termina** se um dos lados ficar inteiramente inativo: sem capitulação, sem
espólio. Não há vitória sobre quem não estava lá.

---

## 17. Neutralidade e evento externo

✅ **Neutralidade é o padrão, e ela não custa nada.** Uma federação que nunca guerreia não deve ficar
para trás — só deixa de ganhar espólio. O jogo é de economia e logística; guerra é **uma** das
estratégias, não a estratégia.

✅ **Evento externo entra pelo motor da A2.8**: um evento de mundo pode abrir uma janela de guerra,
suspender declarações (trégua imposta pelo Governo), ou alterar o custo de mobilização.

⚠️ Isso exige um **modificador de guerra** no motor, que a A2.8 hoje **não tem** — ela só sabe
produção e consumo, e a própria roadmap dela põe "combate" na lista *Depois*. **É pré-requisito desta
fase**, e está aqui para não passar por entregue.

---

## 18. Auditoria

✅ **Toda guerra é registro append-only**, no molde do `ledger` e do `endurance_item_transfers`:
declaração, cada combate, reforço, capitulação, tratado e espólio. Nada editável depois.

✅ **E o operador tem relatório**: pares recorrentes, espólio por federação, guerras por período. É o
que torna o §15 (abuso) tratável por gente em vez de por heurística.

---

## Dependências: conferidas em 2026-08-02

| dependência | estado |
|---|---|
| população | ✅ **ativa em produção** (D-179/D-184) |
| Federação | ✅ com diplomacia e bloco (D-182/D-183) |
| telemetria | ✅ 140 eventos registrados |
| especialização | ✅ (A2.4) |
| economia | ✅ |
| eventos | ⚠️ motor no ar (D-185), mas **sem modificador de guerra** — ver §17 |
| pesquisa | ⚠️ **construída e DESLIGADA** (`research_settings.ativo = false`) |

⚠️ Duas dependências não estão realmente cumpridas: a pesquisa nunca foi ligada, e o motor de eventos
não sabe falar de guerra. **Ambas precisam ser resolvidas antes da primeira linha de código da
A2.10.**

---

## As doze decisões, tomadas em 2026-08-03

| # | decisão | **decidido** | minha recomendação |
|---|---|---|---|
| 1 | quando construir | **construir e ligar agora** | esperar 3 federações |
| 2 | colônia é alvo | **sim, só o excedente do Depósito** | nunca |
| 3 | custo da declaração | **Fert$ do fundo + Nióbio** | igual |
| 4 | há recusa de declaração | **não** | igual |
| 5 | duração | **7 dias** | 72 h |
| 6 | Capital e Espaçoporto | **fora, sempre** | igual |
| 7 | tropa consome operadores | **sim, do bolo da colônia** | igual |
| 8 | aliança e guerra coexistem | **não — declarar rompe** | igual |
| 9 | preço da capitulação | **o vencedor escolhe: zona ou Fert$** | uma zona |
| 10 | fórmula do ranking | *segue como desenho próprio* | por diferença de força |
| 11 | contas vinculadas | **travas econômicas + detecção com revisão humana** | só as travas |
| 12 | neutralidade e abandono | **declarada ANTES da guerra; inatividade não protege** | neutralidade forçada por inatividade |

### O que o §12 decidido diz, na íntegra

> *"A neutralidade só pode ocorrer se declarada pelo jogador antes do início da guerra. Jogador que
> fica 7 dias offline não tem proteção. Zonas neutras ocupadas totalmente saqueáveis e podem ser
> capturadas para um novo jogador tomar conta e ocupar ela. Colônias saqueáveis no que estiver acima
> do limite do depósito."*

A neutralidade existe, e é **um ato político tomado com o jogador presente** — não um prêmio por
ausência. Quem quer ficar fora da guerra declara antes; quem não declarou está no jogo.

---

## ⚠️ Duas tensões que as decisões criam, registradas para não serem redescobertas como defeito

### 1. A combinação colide com o §1.1 do GDD

*"Não exigir login constante"* convive mal com **colônia saqueável + guerra de 7 dias + inatividade
sem proteção**. As três juntas fazem de uma viagem de uma semana uma perda material.

A mitigação escolhida é a **neutralidade declarada com antecedência**, e ela é real: quem sabe que
vai sumir declara antes. O que ela não cobre é a ausência **imprevista** — e isso é o custo aceito.

⚠️ Recomendo que a telemetria (A2.0) meça isto desde o primeiro dia: **taxa de retorno de quem foi
saqueado estando ausente**. Se quem apanha ausente não volta, o número aparece antes de virar êxodo,
e o parâmetro de neutralidade pode mudar. É a mesma disciplina que a população teve.

### 2. "Totalmente saqueável" revoga o D-66/D-107 na guerra

Hoje o Depósito da Zona Neutra **protege** o que cabe nele; só o excedente é espólio. A decisão o
revoga: em guerra federativa, o vencedor leva tudo.

⚠️ **Consequência que precisa ser dita:** o Depósito da zona perde a função de proteger, e com ela o
motivo de subi-lo. Ele continua definindo capacidade, e o D-66 já registrava que a extração
deliberadamente **não** para no teto — *"o excedente empilha ao relento"*, justamente para haver
espólio de guerra. Com saque total, o cálculo do jogador vira simples: **retirar sempre, acumular
nunca**.

Isso é jogável e aumenta a pressão logística, que é pilar do jogo. Mas é uma peça de desenho que
perde o propósito, e o `Domain\Guerra\Protegido` passa a valer só fora da guerra federativa.
