# FERTWAYS — ROADMAP_ALPHA2.md

**Objetivo da Alpha 2:** transformar o conjunto atual de sistemas em um MMO persistente com loop compreensível, progressão orientada, economia interdependente, pesquisa, população, Federação relevante, eventos sistêmicos, telemetria e grande revisão visual.

**Regra:** o roadmap define **ordem de desenvolvimento**. O GDD define **o que o jogo é**. O BALANCEAMENTO define **por que um número existe e quando deve mudar**.

---

## 0. Regras de execução

Antes de cada bloco:

1. confirmar estado real do código;
2. registrar a decisão D-n correspondente;
3. escrever testes antes/de junto da mecânica;
4. definir telemetria da funcionalidade;
5. evitar números hardcoded quando forem parâmetros operacionais;
6. atualizar o GDD canônico apenas quando a funcionalidade estiver realmente implementada;
7. registrar números novos em BALANCEAMENTO.md;
8. não alterar regras históricas apenas porque aparecem no GDD v35;
9. não fazer reset do mundo atual;
10. preservar migrations forward-only e compatibilidade com dados existentes;
11. executar PHP sempre por `/usr/bin/php84` — o `php` do PATH não é a versão do servidor;
12. lembrar que os testes rodam em SQLite em memória (`backend/phpunit.xml`) e a produção é MariaDB: o verde do `artisan test` **não valida DDL**. Migration nova precisa ser exercitada contra MariaDB antes de ser dada como pronta.

---

# FASE A2.0 — Fundação de produto e observabilidade

## Objetivo

Parar de avaliar o jogo apenas pelo “funciona/não funciona” e passar a medir comportamento, progressão e economia.

## Entregas

### A2.0.1 Telemetria de gameplay

Criar uma camada de eventos de telemetria para registrar, no mínimo:

- login;
- duração de sessão;
- intervalo entre sessões;
- colônia fundada;
- upgrade iniciado/concluído;
- construção iniciada/concluída;
- produção;
- falta de insumo;
- falta de energia;
- saldo por recurso;
- compra/venda;
- transporte iniciado/concluído;
- pesquisa futura;
- população futura;
- entrada/saída de Federação;
- ocupação/perda de zona;
- ataque enviado/recebido;
- evento global;
- interação com Endurance;
- abandono do onboarding.

Separar:

- humano;
- sistema/admin.

(Bots são externos e jogam em staging — ver GDD ALPHA 2 §14. A distinção humano/bot é dada pelo ambiente, não por coluna.)

### A2.0.1.1 Granularidade e retenção

Duas camadas, não uma:

- **Eventos discretos** — login, upgrade concluído, construção concluída, compra/venda, transporte, ocupação de zona, ataque, evento global, abandono de onboarding. Vão para a tabela de eventos, com **retenção de 90 dias**; o que envelhece já foi agregado antes de sumir.
- **Fluxo contínuo** — produção, consumo e saldo por recurso **não viram evento por tick**. Viram snapshot diário agregado por colônia, escrito uma vez ao dia. Com tick por minuto, instrumentar produção evento a evento produz uma tabela ingovernável sem responder nenhuma pergunta que o snapshot não responda.

O **ledger já é append-only e já registra todo fato econômico**. A telemetria deve **derivar dele** o que for possível — emissão e destruição de Fert$, compras, subsídios, tributos — e instrumentar apenas o que o ledger não enxerga: sessão, navegação, abandono, falta de insumo, falta de energia.

### A2.0.2 Painel de métricas

Backend com indicadores:

- DAU/WAU;
- sessões/dia;
- duração mediana;
- tempo até primeira ação;
- tempo até primeiro upgrade;
- tempo até primeira estrutura de progressão;
- tempo até primeiro transporte;
- tempo até primeiro comércio;
- tempo até primeira Federação;
- recursos gerados e destruídos;
- Fert$ emitido e destruído;
- concentração de riqueza;
- gargalos de cadeia;
- ataques;
- zonas;
- funil do onboarding.

### A2.0.3 “Desde sua última visita”

Implementar o resumo de retorno.

Critério de aceite: o jogador deve entender em menos de um minuto o que mudou desde sua última sessão.

---

# FASE A2.1 — Onboarding produtivo

## Objetivo

Fazer um jogador novo entender o coração de Fertways antes de ser exposto à complexidade do restante do jogo.

## Sequência

1. Energia.
2. Oxigênio.
3. Água.
4. Biomassa.
5. Produção/h e consumo/h.
6. Upgrade essencial.
7. Fila.
8. Primeira construção de progressão.
9. Primeiro processamento.
10. Depósito.
11. Transporte.
12. Mercado.
13. Mapa/zona.
14. Federação.

## Requisitos

- estado de tutorial persistido;
- etapas configuráveis;
- recompensa por etapa configurável;
- idempotência;
- ledger;
- pular somente após fase obrigatória mínima;
- painel admin;
- telemetria de abandono por etapa;
- tutorial não pode depender de uma oferta real de outro jogador.

## Implementação: sobre o motor de Missões

Não se cria subsistema novo. O onboarding é uma **categoria do motor de Missões existente** — encadeamento por pré-requisito, recompensas, ledger e cadastro admin já estão prontos. Ver GDD ALPHA 2 §4.3.

Duas adaptações:

- **marca de obrigatoriedade** — missão hoje é recusável; a fase obrigatória mínima não pode ser;
- **colônias existentes são marcadas como concluídas e não recebem as recompensas** — do contrário todo veterano vira elegível a um pacote de iniciante.

## Critério de saída

**Testadores humanos, em contas recém-criadas, concluem a sequência sem intervenção manual** — nenhuma etapa exige que o Dono destrave nada pelo painel, e nenhuma depende de oferta real de outro jogador.

O critério original citava bots; eles são externos e podem exercitar a sequência em staging mais tarde, mas não são condição de saída desta fase.

---

# FASE A2.2 — População

## Objetivo

Dar função real à Estrutura de Sobrevivência e criar uma restrição estratégica que conecte sobrevivência, expansão e território.

## Entregas

### A2.2.1 Modelo de população

Estados:

- população total;
- capacidade habitacional;
- disponível;
- alocada em construções;
- alocada em zonas neutras.

### A2.2.2 Sustento

Recursos:

- Água;
- Oxigênio;
- Biomassa;
- Energia/infraestrutura.

### A2.2.3 Crescimento

Crescimento automático em tick.

Condições e taxas inteiramente parametrizadas.

### A2.2.4 Mão de obra

Cada construção pode possuir requisito de operadores.

O backend deve permitir requisito por:

- construção;
- nível.

### A2.2.5 População territorial

Ocupar zona neutra exige transferir pequena equipe operacional.

Essa equipe permanece vinculada à zona.

### A2.2.6 Transição das colônias existentes (grandfathering)

Na migração, cada colônia recebe população suficiente para operar **tudo o que já construiu**, e os operadores exigidos pelas **zonas que já ocupa** são concedidos.

A exigência morde a partir dali: construção nova, upgrade novo e zona nova precisam de população real.

Nenhuma colônia existente pode parar de produzir por uma regra que não existia quando ela foi construída. Ver GDD ALPHA 2 §6.7.

## Critério de saída

Não é possível expandir produção e território indefinidamente sem desenvolver a Estrutura de Sobrevivência e os recursos essenciais.

Nenhum parâmetro populacional sai de HIPÓTESE sem uma rodada registrada do simulador da **trilha A2.S**, cuja primeira entrega pertence a esta fase.

---

# TRILHA A2.S — Simulador de balanceamento (paralela)

Nasce **dentro da A2.2** e permanece disponível para todas as fases seguintes.

Existe porque o passo 6 do fluxo da §3 do BALANCEAMENTO — exercitar um número em escala antes de promovê-lo — ficou sem executor quando os bots saíram do escopo.

## O que é

Um comando `artisan` que roda o **código de domínio real** (tick de produção, consumo, crescimento populacional, custo e duração de pesquisa) contra um banco descartável em memória, avança N dias de tick e devolve curvas.

Não é um segundo modelo do jogo. É o jogo rodando acelerado num mundo que não existe.

## Regras

1. **Reusa o domínio, não o reimplementa.** Um simulador que reescreve a fórmula diverge do jogo na primeira mudança e passa a mentir com aparência de autoridade.
2. **Os parâmetros saem da mesma fonte que o jogo usa** — seeders e tabelas —, nunca de uma cópia digitada no simulador. Mesma disciplina do `building_specs`.
3. **Nunca toca banco de produção nem de staging.** Mundo descartável, em memória.
4. Saída legível: curva por dia, ponto de saturação, gargalo identificado e o conjunto de parâmetros que a produziu.
5. É ferramenta de decisão, não de entrega: não tem requisito de UI e não aparece para o jogador.

## Primeira entrega (dentro da A2.2)

Escopada a população. Para um conjunto de parâmetros, deve responder:

- em quantos dias uma colônia típica bate no teto habitacional;
- qual recurso essencial satura primeiro;
- que percentual da população fica comprometido em operação — a métrica-chave da §7.3 do BALANCEAMENTO;
- em que ponto a expansão trava.

## Entregas seguintes, conforme a fase

- **A2.3** — custo e duração de tecnologia: existe sequência dominante na árvore?
- **A2.4** — vantagem comparativa; é o executor do critério de saída daquela fase.
- **A2.7** — economia de upgrade de veículo e de capacidade de estoque.

## Critério de saída da trilha

Nenhum número novo de População, Pesquisa, Especialização ou Veículos é promovido de HIPÓTESE a BASELINE sem uma rodada do simulador registrada em `BALANCEAMENTO.md`.

---

# FASE A2.3 — Pesquisa

## Objetivo

Dar função real ao Laboratório e criar escolhas de longo prazo.

## Modelo

Pesquisa individual por colônia.

Consome:

- recursos;
- tempo;
- vaga de pesquisa.

## Trilhas iniciais

1. Energia
2. Sobrevivência/Biosfera
3. Indústria
4. Logística
5. Comércio
6. Ciência
7. Defesa
8. Território

Espacial fica preparado conceitualmente, mas não precisa entrar na primeira entrega.

## Observatório: fora desta entrega

O Observatório **não existe no jogo** e não entra aqui. Criá-lo exige decisão de slot, arte e especificação próprias, e não cabe na fase que introduz a árvore inteira.

O paralelismo sai do **nível do Laboratório**. O mecanismo de vagas nasce extensível, para aceitar uma fonte adicional depois sem refazer o modelo. Ver GDD ALPHA 2 §7.2.

## Backend

Cada tecnologia precisa permitir:

- chave;
- nome;
- descrição;
- trilha;
- pré-requisitos;
- custo de recursos;
- duração;
- nível máximo;
- efeitos;
- requisitos de Laboratório;
- status ativo/inativo;
- versão.

## Critério de saída

Dois jogadores com tempo semelhante podem desenvolver colônias significativamente diferentes por escolhas tecnológicas.

---

# FASE A2.4 — Especializações econômicas fortes

## Objetivo

Transformar especialização em necessidade econômica real.

## Trabalho

- auditar as especializações já existentes;
- medir bônus atuais;
- identificar quais ainda não alteram decisão econômica;
- conectar especialização à árvore de pesquisa;
- conectar especialização a população;
- conectar especialização a consumo e logística;
- medir dependência do Mercado;
- impedir combinação que torne uma colônia dominante em todas as cadeias.

## Modelo: especialização é a trilha de pesquisa

Não se cria escolha declarada de perfil. O jogador se especializa **pelo que pesquisou e construiu**, e o jogo **calcula e exibe** o perfil resultante — leitura derivada, nunca campo preenchido pelo jogador. Ver GDD ALPHA 2 §8.1.

## Meta de design

Um especialista deve ser claramente melhor em seu domínio, mas claramente dependente de outros domínios.

## Critério de saída

O critério original — “bots especializados negociam entre si por necessidade econômica” — dependia de simulação que não existe neste repositório. Substituto verificável sem bots:

**A dependência é estrutural e demonstrável no papel.** Para cada especialização, ao menos uma cadeia de que ela depende não é suprível por produção própria em nível competitivo, e isso é mostrado pelo **simulador da trilha A2.S**, não por observação de tráfego.

A confirmação em campo — volume real de mercado entre perfis diferentes — fica para quando houver população de jogadores ou simulação em staging.

---

# FASE A2.5 — Federação como infraestrutura de midgame

## Objetivo

Fazer Federação deixar de ser apenas agrupamento social.

## Regras

- máximo 12 membros;
- Líder;
- Diplomata;
- demais papéis conforme necessidade operacional;
- fundo;
- extrato;
- missões cooperativas;
- diplomacia;
- território;
- acesso a conteúdo avançado.

## Trabalho

1. revisar regra atual contra a decisão de 12 membros;
2. assegurar migração compatível;
3. aperfeiçoar papéis/permissões;
4. criar objetivos federativos;
5. criar métricas de concentração;
6. criar proteções antimonopólio observáveis;
7. preparar interface diplomática.

## Critério de saída

Uma Federação organizada passa a oferecer capacidade estratégica que um conjunto de jogadores independentes não possui.

---

# FASE A2.6 — Zonas neutras + população + automação

## Objetivo

Integrar completamente território ao novo modelo populacional.

## Entregas

- equipe operacional mínima por zona;
- transferência colônia → zona;
- retorno;
- visualização de operadores;
- impedimento por falta de população;
- interação com Abrigo de Robôs;
- impacto de manutenção;
- telemetria de custo territorial;
- **degradação por falta de operadores**: se a população cair abaixo do exigido, a zona não se perde — sofre penalidade de produção até ser reposta (GDD ALPHA 2 §6.6).

## Princípio

Poucos humanos operam muitos robôs.

Zonas não devem exigir populações enormes.

---

# FASE A2.7 — Upgrades de veículos e estoques

## Decidido

**O nível do veículo aumenta a capacidade e aumenta a manutenção junto.** Um eixo, com contrapartida — é o que a §13 do BALANCEAMENTO pede ao proibir melhorar tudo de graça.

**Velocidade não é atributo de nível: é traço do tipo de veículo.** É o que diferencia Furgão de Caminhão. Se o nível também acelerasse, a distância — pilar declarado do jogo — encolheria a cada upgrade.

Hoje `vehicles.level` já existe no banco, mas **não há rota de upgrade**: o nível existe sem caminho para subir. É isso que esta fase fecha.

**Estoque tem teto, e o teto trava em vez de derramar.** Ao encher, a produção para; nada transborda e vira desperdício. O jogador perde oportunidade, nunca estoque — o que dá peso à decisão de construir armazenamento sem punir quem passou o dia fora. Ver §14 do BALANCEAMENTO.

## Ordem

1. implementar a rota de upgrade que falta;
2. conectar custo/tempo;
3. aplicar capacidade↑/manutenção↑ por nível, mantendo velocidade como traço do tipo;
4. finalizar capacidades de estoque ainda abertas, com teto que trava;
5. colocar tudo sob parâmetros versionados;
6. simular impacto econômico pela trilha A2.S.

## Critério de saída

Upgrade de veículo apresenta escolha econômica mensurável e não apenas aumento nominal de nível.

---

# FASE A2.8 — Motor de Eventos

## Objetivo

Dar ao Dono capacidade de criar emoção sem precisar alterar código para cada evento.

## MVP do motor

Campos:

- nome;
- slug;
- descrição;
- mensagem pública;
- notas internas;
- início;
- fim;
- status;
- visibilidade;
- escopo;
- gatilho;
- modificadores;
- recompensas;
- missões;
- segredo;
- versão.

## Modificadores da primeira versão

Apenas dois:

- **produção**;
- **consumo**.

Escopo global ou por recurso, janela de início/fim, visibilidade anunciada/parcial/secreta.

**Preço fica de fora**: já existe `PriceIntervention` no jogo e o motor não a absorve nem a duplica nesta versão.

**Evento nunca escreve no ledger.** Ele altera a taxa; quem credita continua sendo o tick. E o modificador precisa ser reconstruível no passado, para que “Desde sua última visita” consiga explicar por que a produção caiu.

## Modificadores seguintes

- taxa;
- logística;
- construção;
- pesquisa;
- população;
- território.

## Depois

- combate;
- Endurance;
- Federação;
- eventos encadeados;
- condições compostas.

## Segurança

- preview antes de ativar;
- dry-run/simulação;
- auditoria;
- cancelamento;
- rollback lógico do modificador futuro, sem apagar efeitos históricos já ocorridos.

---

# FASE A2.9 — Endurance como sistema de progressão narrativa e loot

## Regra central

A Endurance não será reconstruída.

## Objetivo

Aprofundar a escavação/desmontagem e a obtenção de peças.

## Entregas

- catálogo de itens;
- raridade;
- origem/seção;
- disponibilidade;
- estoque;
- histórico de descoberta;
- propriedade;
- itens únicos;
- eventos da Endurance;
- leilões associados;
- missões narrativas;
- telemetria de circulação.

## Classes

- comum;
- raro;
- único.

## Regra de item único

Um item marcado como único deve possuir identidade persistente e histórico.

**Só o único ganha linha de instância.** O catálogo atual é fungível e continua: os itens existentes viram `comum`, sem migração dolorosa. `Raro` é escasso mas segue fungível. Apenas o `único` recebe instância com descobridor, proprietário atual e histórico de transferência. Ver GDD ALPHA 2 §11.1.

---

# FASE A2.10 — Guerra federativa e geopolítica

## Objetivo

Fechar a lacuna de guerra entre Federações.

## Antes de implementar

Esta é a **única fase da Alpha 2 que exige um documento de design próprio antes de qualquer linha de código**. Nenhum prompt deve ser disparado enquanto ele não existir.

Produzir um GDD específico da guerra federativa com:

- declaração;
- objetivo;
- duração;
- alvos;
- participação;
- reforços;
- tratados;
- capitulação;
- cooldown;
- recompensa;
- perda;
- território;
- ranking;
- abuso por contas vinculadas;
- abandono;
- neutralidade;
- evento externo;
- auditoria.

## Dependências

Não iniciar antes de:

- população;
- pesquisa;
- Federação;
- telemetria;
- especialização;
- economia;
- eventos.

---

# FASE A2.V — Revisão visual gigantesca

A revisão visual não deve ser deixada para o último commit.

Ela corre em paralelo, mas com ordem controlada.

## Ordem: A2.V1 vem antes ou junto com A2.0

Há uma dependência dura. A tela **“Desde sua última visita” é a primeira tela nova da Alpha 2** e vai fixar o padrão visual na marra. Se os tokens vierem depois, ela e o painel de população nascem fora do design system e terão de ser refeitos.

Portanto: **A2.V1 antes ou junto com A2.0**; V2 a V6 seguem coladas em cada fase. Ponto de partida já existente: `docs/design-tokens.md`.

## A2.V1 — Design System

- tokens;
- tipografia;
- espaçamento;
- iconografia;
- recursos;
- botões;
- cards;
- modais;
- estados;
- mobile;
- acessibilidade.

## A2.V2 — HUD e navegação

- HUD global;
- notificações;
- desde sua última visita;
- alertas de produção;
- alertas militares;
- fila;
- pesquisa;
- população.

## A2.V3 — Colônia

- nova leitura espacial;
- estados visuais de edifício;
- construção;
- upgrade;
- produção;
- falta de recurso;
- falta de energia;
- operadores;
- animações sutis.

## A2.V4 — Mapa e zonas

- mapa planetário;
- seleção;
- ameaças;
- Federação;
- zonas;
- trajetos;
- leitura de distância;
- estados territoriais.

## A2.V5 — Capital e Endurance

- áreas institucionais;
- Endurance com identidade visual própria;
- exploração por seções;
- peças;
- raridades;
- eventos.

## A2.V6 — Combate e eventos

- avisos;
- preparação;
- impacto;
- relatórios;
- timeline;
- efeitos ambientais.

---

# FASE A2.11 — Bots de simulação (FORA DO ESCOPO)

Os bots são um **programa externo**, em servidor e banco próprios (`staging.tars.art.br`), e se comportam como jogadores externos. Nenhuma fase da Alpha 2 os constrói. Ver GDD ALPHA 2 §14.

O que permanece no escopo deste repositório:

- a telemetria distingue **humano** e **sistema/admin** — a distinção humano/bot é dada pelo ambiente;
- o jogo não pode depender de bots para funcionar nem para ser validado.

Integração futura, não é backlog: leitura da base do staging pelo Fertways para apoiar balanceamento.

---

# FASE A2.12 — Hardening da Alpha 2

Checklist:

- testes unitários;
- integração;
- E2E;
- carga;
- migrations em MariaDB;
- jobs idempotentes;
- tick;
- segurança;
- rate limits;
- logs;
- métricas;
- erros;
- auditoria;
- backups;
- mobile;
- acessibilidade;
- onboarding completo;
- economy smoke test;
- simulação longa em staging, quando houver.

---

# Fora da Alpha 2 imediata

- novos planetas independentes;
- servidores interplanetários;
- API econômica entre servidores;
- comércio entre mundos;
- profissão individual de população;
- conta compartilhada entre planetas independentes.

As luas continuam parte do universo futuro da conta de Fertways.

---

# Ordem recomendada de decisões D-161+

A numeração definitiva deve seguir o diário real do projeto, mas a sequência conceitual recomendada é:

- **D-161:** visão e escopo da Alpha 2;
- **D-162:** política de telemetria;
- **D-163:** “Desde sua última visita”;
- **D-164:** onboarding produtivo;
- **D-165:** recompensas configuráveis do onboarding;
- **D-166:** modelo de população;
- **D-167:** sustento e crescimento;
- **D-168:** operadores por construção;
- **D-169:** população em zona neutra;
- **D-170:** arquitetura da pesquisa;
- **D-171:** trilhas de pesquisa;
- **D-172:** paralelismo Laboratório/Observatório;
- **D-173:** especialização econômica + integração com pesquisa;
- **D-174:** Federação limitada a 12 e papéis;
- **D-175:** Federação como gate de conteúdo avançado;
- **D-176:** níveis de veículos;
- **D-177:** estoques/capacidades;
- **D-178:** Motor de Eventos;
- **D-179:** eventos secretos/negativos;
- **D-180:** Endurance: raridades e identidade de itens;
- **D-181+:** guerra federativa;
- **trilha V paralela:** revisão visual.

A numeração acima é proposta de organização, não deve ser tratada como decisão registrada até ser efetivamente lançada em `docs/decisoes.md`.

**Os números não ficam reservados.** A última decisão realmente registrada é a **D-160** (`docs/decisoes.md`, 185 entradas). Cada entrega toma o próximo número livre no momento em que é lançada — se uma fase se dividir em três decisões, as seguintes deslocam, e a lista acima continua valendo apenas como ordem conceitual.
