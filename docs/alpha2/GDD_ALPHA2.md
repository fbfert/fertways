# FERTWAYS — GDD ALPHA 2

**Status:** Diretriz de design aprovada para a próxima fase de desenvolvimento  
**Data-base:** 30/07/2026  
**Relação com o GDD canônico:** este documento registra as decisões de design da Alpha 2. O GDD canônico publicado é o **v39** (`docs/FERTWAYS_GDD_v39_CONSOLIDADO.html`) e continua sendo o documento que descreve o que está efetivamente implementado. As regras abaixo devem ser incorporadas ao GDD canônico à medida que forem implementadas e validadas.  
**Fonte histórica:** o GDD **v35** (`docs/FERTWAYS_GDD_v35_MESTRE_UNIFICADO.html`) pode ser consultado para esclarecer intenções antigas e cobrir pontos que ficaram falhos no v39, mas não possui precedência automática sobre o v39, sobre `docs/decisoes.md`, sobre decisões posteriores nem sobre o estado atual do jogo.

---

## 1. Identidade do jogo

FERTWAYS é um MMO persistente de estratégia, economia, logística e geopolítica.

O jogador administra uma colônia, desenvolve cadeias produtivas, pesquisa tecnologias, sustenta uma população, especializa sua economia, participa de uma Federação, disputa zonas neutras, negocia com outros jogadores e participa de acontecimentos de escala planetária.

A guerra é um dos pilares do jogo, mas não substitui a economia. O conflito deve depender de preparação econômica, logística, tecnologia, alianças e informação.

### 1.1 Princípios

1. **Mundo persistente.** O progresso de meses não deve ser apagado por calendário.
2. **Sessões curtas e recorrentes.** A experiência deve funcionar bem em sessões de 5 a 10 minutos, várias vezes ao dia.
3. **Evolução visível.** Cada retorno deve mostrar ao jogador que seu mundo continuou funcionando.
4. **Especialização forte.** Uma colônia não deve ser economicamente ótima em todas as atividades.
5. **Interdependência.** Comércio e Federação devem se tornar necessários no médio e longo prazo.
6. **Logística real.** Recursos continuam sujeitos a transporte e distância.
7. **Geopolítica emergente.** Território, economia, diplomacia, alianças e guerra formam um único sistema.
8. **Administração progressivamente automatizada.** O Dono mantém poder de operação e emergência, mas tarefas normais devem migrar para regras e motores sistêmicos.
9. **Eventos como fonte de instabilidade controlada.** O mundo não deve parecer totalmente previsível.
10. **Sem reset automático.** Reset somente por determinação explícita do Dono.
11. **Backend configurável.** Temporadas, onboarding, eventos, parâmetros de balanceamento e recompensas relevantes devem ser configuráveis sem deploy sempre que razoável.
12. **Telemetria antes de grandes ajustes numéricos.** Mudanças de balanceamento precisam de evidência.

---

## 2. Persistência, temporadas e mundos

### 2.1 Fertways original

O planeta Fertways é concebido como mundo permanente.

Uma temporada possui referência inicial de aproximadamente **6 meses**, prorrogável pelo Dono. O término de uma temporada não implica reset do planeta.

Temporadas servem para:

- organizar eventos;
- criar arcos narrativos;
- alterar objetivos coletivos;
- introduzir novas tecnologias ou sistemas;
- marcar fases históricas do servidor;
- conceder títulos e registros permanentes.

### 2.2 Novos planetas

No futuro, uma nova temporada poderá ocorrer em um novo planeta.

Esse planeta poderá operar em outro servidor e utilizar contas próprias, independentes das contas de Fertways.

Não faz parte da Alpha 2 implementar comércio entre servidores.

A integração econômica entre planetas é uma visão futura e deverá ser feita por API entre servidores.

### 2.3 Luas

As luas pertencem ao universo de Fertways e devem utilizar a **mesma conta do jogador de Fertways**, diferentemente de futuros planetas independentes.

---

## 3. Loop central da Alpha 2

O loop desejado é:

**sobreviver → produzir → transformar → pesquisar → especializar → transportar → negociar → federar → ocupar território → defender/atacar → participar dos eventos e da Endurance → evoluir**

O jogador deve conseguir identificar sempre:

- o que está produzindo;
- o que está consumindo;
- o que terminou desde a última visita;
- o que está faltando;
- qual construção deve desenvolver;
- quais pesquisas estão disponíveis;
- quanto da população está livre ou ocupada;
- quais veículos estão em trânsito;
- o que ocorreu militarmente;
- o que sua Federação está fazendo;
- quais eventos estão ativos.

---

## 4. Jornada inicial guiada

A jornada inicial será parcialmente obrigatória.

Os primeiros passos orientam o jogador e garantem que ele compreenda o sistema de produção básica. Depois dessa fase, o guia passa a sugerir objetivos sem bloquear a liberdade.

### 4.1 Sequência inicial

1. Conhecer a colônia e o resumo de recursos.
2. Compreender Energia.
3. Compreender Oxigênio.
4. Compreender Água.
5. Compreender Biomassa.
6. Entender produção por hora e consumo por hora.
7. Fazer o primeiro upgrade de uma estrutura essencial.
8. Entender a fila de construção.
9. Construir uma primeira estrutura de progressão.
10. Produzir um primeiro recurso transformado.
11. Conhecer o Depósito Local.
12. Conhecer transporte.
13. Realizar uma primeira operação logística.
14. Conhecer o Mercado.
15. Conhecer o mapa e as zonas neutras.
16. Conhecer a Federação e seu papel na progressão.

### 4.2 Recompensas

Cada etapa pode conceder uma recompensa configurável no backend.

Regras:

- todas as recompensas possuem identificador único;
- só podem ser recebidas uma vez por colônia;
- concessão registrada no ledger;
- não podem ser convertidas em mecanismo de farming;
- valores iniciais serão cadastrados, mas permanecerão editáveis no painel administrativo.

### 4.3 Implementação sobre o motor de Missões

A jornada inicial **não é um subsistema novo**. Ela é uma categoria do motor de Missões que já existe, aproveitando o encadeamento por pré-requisito, as recompensas em Fert$/XP/recursos, o registro no ledger e o cadastro administrativo.

Duas adaptações são necessárias:

- **Obrigatoriedade.** Missão hoje é recusável. As etapas da fase obrigatória mínima precisam de marca própria que impeça a recusa; da fase seguinte em diante o guia sugere sem travar.
- **Colônias existentes ficam de fora.** Elas são marcadas como concluídas e **não recebem as recompensas do tutorial**. Sem essa regra, todo veterano se tornaria elegível a um pacote de iniciante — farming imediato, registrado corretamente no ledger enquanto a economia derrete.

---

## 5. Tela “Desde sua última visita”

Esta tela é prioridade da Alpha 2.

Ao entrar, o jogador recebe um resumo consolidado do período desde sua última sessão relevante.

Deve apresentar, quando aplicável:

- recursos produzidos;
- recursos consumidos;
- saldo líquido;
- construções concluídas;
- upgrades concluídos;
- pesquisas concluídas;
- crescimento ou alteração populacional;
- veículos que chegaram;
- entregas concluídas;
- ordens executadas;
- mudanças da Federação;
- eventos iniciados ou encerrados;
- ataques recebidos;
- resultado de combates;
- zonas perdidas ou defendidas;
- notificações críticas.

O objetivo não é apenas notificar. É responder em segundos: **“o que mudou enquanto eu estava fora?”**

### 5.1 Janela do resumo

A janela é **por resumo visto**, não por sessão. O resumo cobre o período desde a última vez que o jogador fechou o resumo, e o marcador avança nesse momento. Reabrir a tela não repete o que já foi visto e não duplica evento.

**Piso de uma hora.** Se passou menos de uma hora desde o último resumo visto, a tela não aparece. Evita que quem recarrega a página, ou entra três vezes seguidas, receba um modal a cada visita.

Não existe hoje conceito de sessão no jogo — `users` não possui marca de último acesso e a tabela de sessões do framework é apagada no logout. A marca do “até quando você já viu” é estrutura nova desta fase e é dela que a janela depende.

---

## 6. População

A população passa a ser um recurso operacional da colônia.

### 6.1 Estrutura de Sobrevivência

A Estrutura de Sobrevivência define a capacidade máxima de habitantes.

A capacidade exata por nível deve ser definida no balanceamento e validada por simulação.

### 6.2 Necessidades

A economia populacional será simplificada.

A população depende principalmente de:

- água;
- oxigênio;
- biomassa/alimentação;
- energia/infraestrutura.

O sistema deve ser compreensível e evitar micromanagement excessivo.

### 6.3 Crescimento

A população cresce automaticamente quando:

- existe capacidade habitacional;
- as necessidades mínimas estão atendidas;
- a colônia não está sob uma condição grave que bloqueie crescimento.

O crescimento deve ser lento o suficiente para valorizar desenvolvimento, mas não deve exigir login constante.

### 6.4 Falta de suprimentos

Escassez deve primeiro produzir **perda de eficiência e bloqueio de crescimento**, e não punições irreversíveis imediatas.

Qualquer regra futura de redução populacional deverá ser explícita, telemetrizada e cuidadosamente balanceada.

### 6.5 Mão de obra

Construções passam a exigir uma quantidade de população operacional.

A população pode estar:

- disponível;
- alocada em construções;
- alocada em zonas neutras;
- futuramente alocada em outras operações.

Nesta primeira versão, não existem profissões individuais.

### 6.6 Zonas neutras

Ocupação de uma zona neutra exige deslocar uma pequena quantidade de habitantes da colônia para a zona.

Esses habitantes representam supervisores, técnicos e operadores dos sistemas robotizados.

A maior parte do trabalho físico continua sendo executada por robôs.

A população enviada:

- deixa de estar disponível na colônia;
- passa a estar vinculada à zona;
- deve aparecer no painel de população;
- retorna à colônia quando a operação territorial permitir;
- não deve ser tratada como tropa militar.

**Se a população cair abaixo do exigido por uma zona já ocupada, a zona não se perde.** Ela degrada — penalidade de produção — até a população ser reposta. Perder território por escassez seria a punição irreversível que a §6.4 proíbe.

### 6.7 Transição das colônias existentes

O mundo é persistente e não haverá reset. No dia em que operadores passarem a ser exigidos, **nenhuma colônia existente pode parar de produzir por uma regra que não existia quando ela foi construída.**

Regra de transição — **grandfathering**:

- toda colônia existente recebe, na migração, população suficiente para operar **tudo o que já construiu**;
- o mesmo vale para as **zonas neutras já ocupadas**: os operadores que elas passam a exigir são concedidos na migração;
- a exigência de operadores morde a partir dali: **construção nova, upgrade novo e zona nova** precisam de população real.

A razão não é generosidade. A tela do jogo **diz hoje ao jogador** que a Estrutura de Sobrevivência não faz nada — quem não a evoluiu seguiu o que o jogo lhe informou. Cobrar retroativamente puniria quem acreditou na interface, e contrariaria a §6.4, que manda escassez bloquear crescimento antes de causar perda.

Efeito colateral aceito: uma colônia veterana começa com mais folga que uma nova. Ela construiu aquilo. E continua precisando desenvolver a Estrutura de Sobrevivência para crescer dali em diante, que é onde a mecânica deve morder.

---

## 7. Pesquisa

Pesquisa é **individual por colônia**.

### 7.1 Laboratório

O Laboratório é a infraestrutura de pesquisa.

Pesquisa consome:

- recursos;
- tempo;
- capacidade de pesquisa disponível.

Não será criado, nesta fase, um recurso abstrato permanente de “Pontos de Pesquisa”.

### 7.2 Pesquisas simultâneas

A quantidade de projetos simultâneos depende do nível do Laboratório.

**Sobre o Observatório.** Ele **não existe no jogo** — nunca foi implementado, e aparece apenas em material de divulgação. A redação anterior desta seção dizia que ele "continua sendo" uma especialização que amplia o paralelismo, o que era falso.

O Observatório **entra no jogo**, mas não na primeira entrega de Pesquisa: criá-lo exige decisão de slot da colônia — recurso escasso e já disputado —, arte e especificação próprias, e isso não cabe dentro da fase que introduz a árvore de tecnologia inteira.

Até lá, o paralelismo sai do nível do Laboratório. O mecanismo de vagas deve nascer **extensível**, de modo que uma fonte adicional de paralelismo possa ser acrescentada depois sem refazer o modelo.

### 7.3 Caminhos especializados

A árvore não será linear.

Linhas iniciais propostas:

- Energia;
- Sobrevivência e Biosfera;
- Indústria;
- Logística;
- Comércio;
- Ciência;
- Defesa;
- Território.

Linha futura:

- Espacial.

### 7.4 Filosofia de especialização

É permitido pesquisar múltiplas linhas ao longo do tempo.

Entretanto, custo, tempo, infraestrutura, população e dependências devem tornar economicamente inviável maximizar todas as linhas rapidamente.

Escolhas devem ter consequência.

---

## 8. Especialização econômica

Especialização deve ser **forte**.

O objetivo não é conceder bônus cosméticos de 2% ou 5%. Especializações devem modificar significativamente:

- capacidade;
- produtividade;
- consumo;
- logística;
- pesquisa;
- defesa;
- acesso a determinadas cadeias.

O sistema deve estimular relações como:

- produtor agrícola comprando componentes industriais;
- industrial comprando energia ou matérias-primas;
- especialista logístico prestando serviço;
- Federação coordenando membros com perfis diferentes.

Uma colônia autossuficiente pode existir em nível básico, mas não deve ser competitivamente ótima em todo o conjunto econômico.

### 8.1 Especialização é a trilha de pesquisa

**Não existe escolha declarada de perfil.** O jogador não seleciona “sou agrícola”: ele se torna agrícola pelo que pesquisou e construiu.

As trilhas da §7.3 já são a escolha — têm custo de oportunidade real, são caras de reverter e são legíveis. Uma segunda camada declarada por cima faria dois sistemas disputarem o mesmo papel, e traria junto o problema do “posso trocar?”, com respec, custo de troca e o abuso de mudar de perfil na véspera de cada evento.

**Contrapartida obrigatória:** como a especialização não é declarada, ela precisa ser **exibida**. O jogo calcula o perfil da colônia a partir das trilhas pesquisadas e das construções erguidas, e o mostra ao jogador — junto com o que ele ganha e do que passa a depender. É leitura derivada, nunca um campo que o jogador preenche.

---

## 9. Federações

Federações deixam de ser conteúdo meramente opcional no médio/endgame.

### 9.1 Limite

Máximo de **12 membros**.

### 9.2 Papéis essenciais

- **Líder**
- **Diplomata**
- demais papéis operacionais podem permanecer conforme o sistema atual ou serem expandidos posteriormente.

### 9.3 Papel estratégico

A Federação deverá participar de:

- diplomacia;
- comércio coordenado;
- defesa;
- guerra;
- missões cooperativas;
- eventos;
- objetivos territoriais;
- conteúdo avançado;
- futuras tecnologias ou projetos coletivos específicos, quando formalmente definidos.

### 9.4 Necessidade

O jogo deve permitir que um jogador permaneça independente durante sua fase inicial.

A partir do médio jogo, parte relevante da progressão deve favorecer ou exigir coordenação federativa.

---

## 10. Guerra e geopolítica

FERTWAYS é um jogo econômico-geopolítico em que guerra é um dos pilares.

Guerra deve depender de:

- capacidade produtiva;
- estoque;
- logística;
- tecnologia;
- informação;
- população disponível;
- território;
- Federação;
- diplomacia;
- preparação.

Não deve ser um subsistema separado da economia.

A evolução futura inclui guerra entre Federações, ainda não considerada fechada neste documento.

---

## 11. Endurance of Mankind

A Endurance **não será reconstruída**.

Ela pousou em Fertways e não voltará a voar.

Seu papel é:

- marco narrativo;
- patrimônio histórico;
- fonte de missões;
- área de escavação/desmontagem controlada;
- origem de peças e artefatos;
- fonte de itens de diferentes raridades;
- motor de eventos e disputas econômicas/narrativas.

### 11.1 Filosofia de loot

A exploração da Endurance deve permitir itens:

- comuns;
- raros;
- únicos.

Itens únicos devem ser realmente escassos e possuir rastreabilidade.

O sistema deve evitar que “único” se transforme apenas em mais uma categoria de drop repetível.

**Só o único ganha identidade.** O catálogo atual da Endurance é fungível — item com quantidade — e continua assim: os itens já existentes passam a ser `comum`. Acrescenta-se o campo de raridade, e **apenas os itens marcados como únicos recebem linha de instância** com descobridor, proprietário atual e histórico de transferência. `Raro` é escasso, mas continua fungível.

Dar identidade rastreável a peça que ninguém vai rastrear infla o schema e não compra nada.

### 11.2 Progressão

A Endurance deve ganhar importância ao longo da vida do servidor.

Novas seções, missões, lotes, peças ou descobertas podem ser liberados por eventos e arcos narrativos.

---

## 12. Motor de Eventos

Será criado um Motor de Eventos administrável pelo backend.

### 12.1 Tipos de evento

O motor deve permitir, progressivamente:

- bônus de produção;
- penalidade de produção;
- alteração de consumo;
- escassez;
- excesso de oferta;
- efeitos logísticos;
- alteração de taxas;
- modificadores de mercado;
- eventos territoriais;
- eventos militares;
- eventos da Endurance;
- missões especiais;
- eventos de Federação;
- eventos secretos.

### 12.1.1 Escopo da primeira versão

O motor abre com **modificador multiplicativo de produção e de consumo**, com escopo global ou por recurso, janela de início/fim e visibilidade (anunciado, parcialmente anunciado, secreto).

Os demais tipos da lista acima permanecem como direção, não como primeira entrega.

**Preço fica de fora.** O jogo já possui intervenção de preço administrada (`PriceIntervention`); o motor não a absorve nem a duplica nesta versão.

**Regra inegociável: evento nunca escreve no ledger.** O evento altera a taxa; quem credita e debita continua sendo o tick, pelo caminho normal. Um evento que emitisse recurso diretamente criaria lançamento sem origem rastreável e envenenaria a própria fonte que a telemetria econômica usa.

O modificador precisa ser **reconstruível no passado**, para que a tela “Desde sua última visita” consiga explicar *por que* a produção caiu, e não apenas que caiu.

### 12.2 Configuração

Um evento deve possuir, quando aplicável:

- nome;
- descrição pública;
- descrição interna;
- planeta/escopo;
- início;
- fim;
- visibilidade;
- condição de ativação;
- modificadores;
- missões relacionadas;
- recompensas;
- severidade;
- versão;
- log de criação/alteração;
- opção de cancelamento seguro.

### 12.3 Eventos secretos

O Dono pode programar eventos sem anúncio antecipado.

O sistema deve permitir distinguir:

- evento anunciado;
- evento parcialmente anunciado;
- evento secreto até a ativação.

### 12.4 Eventos negativos

Eventos negativos são permitidos e desejados.

Devem criar:

- problema;
- reação;
- oportunidade;
- consequência econômica ou geopolítica.

Não devem destruir arbitrariamente meses de progresso sem contrajogo.

---

## 13. Administração

O Dono continua com ferramentas de emergência e curadoria.

Entretanto, a direção da Alpha 2 é automatizar:

- rotinas econômicas;
- progressão;
- eventos programados;
- onboarding;
- recompensas;
- gatilhos;
- avisos;
- regras de temporada;
- métricas;
- controles de segurança.

A Administração deve cada vez mais **configurar regras**, em vez de executar manualmente o funcionamento normal do mundo.

---

## 14. Bots e simulação

Os bots **não fazem parte do código do Fertways**.

São um programa à parte, hospedado em `staging.tars.art.br`, com servidor e banco de dados próprios. Comportam-se como **jogadores externos**: acessam o jogo pelas mesmas interfaces que um humano usaria e não possuem acesso privilegiado ao mundo.

Consequências:

- nenhuma fase da Alpha 2 constrói bots;
- o Fertways não pode depender de bots para funcionar nem para ser validado;
- o mundo de produção (`fertways.tars.art.br`) não recebe bots;
- perfis comportamentais (sobrevivência, agrícola, industrial, logístico, comerciante, pesquisador, territorial, agressivo, federativo) são assunto do programa externo, não deste repositório.

A telemetria mantém campo de origem para distinguir **humano** de **sistema/admin**. A distinção humano/bot é dada pelo **ambiente**, não por uma coluna: produção é humana, staging é onde os bots jogam.

**Visão futura, não backlog imediato:** o Fertways poderá ler a base do `staging.tars.art.br` para extrair dados de simulação e apoiar decisões de balanceamento. Essa integração será decidida quando existir, e é **leitura** — não escrita.

---

## 15. Direção visual da Alpha 2

A Alpha 2 inclui uma **revisão visual estrutural de grande escala**.

Não se trata apenas de trocar cores.

A revisão deverá abranger:

- identidade visual;
- design system;
- HUD;
- tipografia;
- hierarquia de informação;
- recursos;
- cards;
- painéis;
- colônia;
- mapa planetário;
- Capital;
- Endurance;
- zonas neutras;
- construções;
- estados de produção;
- estados de construção;
- animações;
- feedback de clique;
- feedback de conclusão;
- alertas;
- combate;
- efeitos ambientais;
- mobile;
- acessibilidade.

O visual deve comunicar estado de jogo sem depender de leitura excessiva.

---

## 16. Critério de “jogo completo”

O objetivo final permanece:

1. todo o GDD vigente implementado;
2. lacunas de design fechadas;
3. sistemas integrados;
4. telemetria operacional;
5. economia balanceada;
6. gameplay validado;
7. visual altamente atrativo e consistente.

A Alpha 2 é a fase que transforma a grande quantidade de sistemas já existentes em um jogo com loop, progressão, legibilidade e retenção coerentes.

---

## 17. Decisões explicitamente fora da Alpha 2

Não implementar agora:

- economia entre servidores/planetas;
- API econômica interplanetária;
- contas compartilhadas entre planetas independentes;
- profissões individuais de população;
- comércio interestelar entre mundos persistentes independentes;
- regras definitivas de colonização de novos planetas;
- reset automático periódico;
- construção de bots — são programa externo, em servidor e banco próprios (§14).

Esses itens devem permanecer documentados como visão futura, não backlog imediato.
