# RETOMAR — ponto de parada do FERTWAYS

> **Para o Claude:** quando o usuário disser "retome", leia este arquivo primeiro, confira o
> estado real (os comandos da seção "Verificação rápida" abaixo — não confie nesta página),
> e então **faça ao usuário as perguntas da seção "Perguntas em aberto"** antes de escolher
> o que fazer. Atualize este arquivo ao fim de cada sessão.

**Última atualização:** 2026-07-20 · **Branch:** `main`

> **Se o usuário disser "retome" e houver uma seção "EM ANDAMENTO AGORA" abaixo**, ela já tem
> autorização permanente para seguir sem novas perguntas ("siga por todas as fases... quero que
> me entregue pronto") — não repita a pergunta, só continue de onde parou.

> O commit não é anotado aqui de propósito: ele fica velho a cada sessão e a página passa a
> mentir. Rode `git log --oneline -1`.

---

## Onde o projeto está

O MVP tem, funcionando e no ar em `https://fertways.tars.art.br`:

- Autenticação (Sanctum), fundação de colônia, kit inicial (50 Fert$, raros, Furgão)
- 16 construções com fila, custo pela curva do GDD e subsídio governamental
- Tick por delta de tempo: produção, conclusão de upgrades, expiração de proteção
- Fabricação de Componentes Eletrônicos pelas três receitas do §24.5
- **Logística física**: mapa 100×100, despacho de carga entre colônias, tempo e energia por
  distância, tributo na entrega, viagem de ida e volta
- **Mercado Central, reorganizado no D-58 (2026-07-11)** — cinco abas, e **dois canais que a regra
  agora separa**: o que está **na colônia** se negocia entre colonos (promessa, entrega física por
  veículo, calote possível); o que está **no depósito da Capital** se oferta no Mercado Central
  (escrow, e a execução move recurso de um depósito ao outro, sem veículo).
  - **A vitrine matou o casamento automático.** A oferta **repousa** e fica visível até alguém
    executá-la (`POST /market/orders/{id}/execute`, parcial permitida). Era o casamento no ato que
    fazia o livro parecer deserto — a oferta que cruzava era consumida antes de qualquer um a ver.
    `GET /market/orders` agora dispensa `resource_type` (traz **todos**) e **diz de quem é** cada
    oferta. As duas faltas somadas eram a queixa "não vejo as ofertas dos outros".
  - **Teto no depósito da Capital**: 10.000 por primário, 2.500 por secundário, 100 por raro.
    **Arbitragem do usuário, não do GDD** — vivem em `Domain/Market/Deposito`, não no catálogo, que é
    gerado do GDD. Ocupa espaço o saldo **mais** o que está preso em ofertas (de venda **e** de
    compra). O despacho barra o que não cabe; se o depósito encher durante a viagem, entra o que
    couber e **o excedente volta na carroceria, sem tributo** — não foi entregue.
  - **Mural entre colonos**: o Acordo de Troca pode ir **sem contraparte** (`colony_b_id` nulo), e
    quem aceitar primeiro leva (`GET /trade/board`, `POST /trade/agreements/{id}/accept`). O prazo do
    D-42 é cobrado **na aceitação**, quando enfim existe um par e uma distância.
  - A taxa de fechamento (3/2/1% em Fert$, ao Tesouro) **continua no vendedor** — confirmada de
    propósito, para não ser apagada por omissão.
  - **O botão "Acordos" saiu do HUD**: virou aba do Mercado. São **cinco** botões agora.
- **Logout de verdade**: `POST /central/logout` revoga no servidor só o token que fez a chamada.
- **Diretório de colônias**: `GET /central/colonies`, da mais próxima à mais distante. É o que
  tornou o despacho entre colônias alcançável pela UI. Ver D-37 e D-38.
- **Acordo de Troca (§26.5)** — backend e tela. `GET/POST /central/trade/agreements`,
  `POST /central/trade/agreements/{id}/confirm`, `DELETE /central/trade/agreements/{id}`,
  `GET /central/trade/deadline`. Sem escrow: o calote é real e deliberado (D-40). Cumprir é
  entregar fisicamente e vale o líquido que chega (D-41). Prazo mínimo = viagem + 12 h (D-42).
  Confiança Comercial começa em 500, bloqueia abaixo de 200 (D-43).
- **Ministério das Reputações (§9.1–9.4, §26.6–26.8)** — backend e tela. Denúncia com
  evidência mínima conferida, triagem (grave → equipe; simples → conciliador sem impedimento),
  48 h para decidir, punição pela **tabela fixa do D-49**, apelação de 48 h, reversão que estorna e
  suspende em 5. `GET /central/ministry/me`, `GET/POST /central/ministry/reports`,
  `POST /central/ministry/reports/{id}/decide`, `POST /central/ministry/reports/{id}/appeal`.
  A **equipe** do §9.2 é o operador e não tem rota: `artisan fertways:equipe --fila`,
  `fertways:equipe {id} --procedente|--improcedente|--manter|--reverter`, e
  `artisan fertways:conciliador {nick} --nomear|--demitir|--reintegrar|--listar`.
  Os **quatro** índices do §26.2 agora nascem em 500 (antes, três nasciam em zero). A única punição
  que morde hoje é a **restrição comercial**: fecha toda saída de carga por 7 dias.
  **`publico` é conciliador desde 2026-07-10.** Foi escolhido por ter senha documentada — sem ela
  não se entra na tela para julgar, e o caso venceria as 48 h e subiria à equipe de qualquer jeito.
  Recebeu os primeiros 50 Fert$ no tick seguinte à nomeação; o lançamento `salario_conciliador`
  está no ledger da colônia 2.
- **Mapa e Frota (D-54)** — duas telas novas, dois botões novos no HUD. O mapa desenha em SVG a
  Capital, a sua colônia e as vizinhas, com a distância que o frete cobra; a geometria (`side`,
  `capital`, `me`) vem da API, **não** de constante no React, porque o D-51 vai mudá-la. A Frota
  mostra estado, destino, carga e chegada de cada veículo; despachar continua no Mercado e no
  Acordo.
- **Receita da Oficina (D-54)** — `PATCH /buildings/{id}/recipe` existia e nenhuma tela o chamava.
  Agora o painel de detalhe da Oficina oferece as três receitas do §24.5. Criado o `GET /recipes`.
- Frontend: login, HUD, colônia em Phaser, e **cinco botões no HUD**: Mapa, Frota, Capital, Ministério
  e Mercado. O do **Acordo** saiu no D-58: negócio virou assunto do Mercado, e ele é aba de lá.
  - A aba do **Acordo** (dentro do Mercado) propõe, aceita, recusa, desiste, mostra a Confiança
    Comercial contra o limiar e **despacha a entrega pelo bruto**, não pelo prometido: quem embarca
    100 entrega 97, e o colono não deve descobrir que caloteou por três unidades de tributo (D-41).
  - A do **Ministério** mostra os quatro índices, as punições vigentes com prazo, abre denúncia (com
    a evidência filtrada: só o Acordo quebrado entre os dois serve, §26.8), e dá ao conciliador a
    fila com o relógio das 48 h. **Ela publica a pena tabelada antes do julgamento** e só lhe oferece
    "Procedente" e "Improcedente": a pena não é escolha dele (§26.8, D-49).
- **A Capital (D-55)** — o hub das instituições do governo (§02), botão novo no HUD e clique no
  losango do mapa. Diretório dos 7 slots; os slots 6 (Mercado) e 7 (Ministério) reusam as telas de
  topo, o 1 é "operada pela equipe", o 5 (Guerra) é "em breve". Três instituições novas:
  - **Central de Tributos / Ministério do Tesouro (2)** — desde o **D-57** é um **caixa real**
    (`treasury_holdings`), não mais a view derivada do D-55: dotação de 10 mil de cada recurso +
    1.000.000 Fert$; o tributo do comércio (3/2/1%) que antes sumia agora **entra** no caixa; o admin
    **redistribui** a partir dele (§2.1); o colono só vê o saldo. Ver o painel de admin.
  - **Secretaria de Finanças (4)** — preços de referência (§06), indicadores mensuráveis e
    **intervenção de preço** declarada pelo operador (`artisan fertways:intervencao`, com prazo).
    Enquanto vigente, o `ColocarOrdem` rejeita ordens fora da faixa. Sem faixa fixa no código (D-35).
  - **Central de Pesquisas e Notícias (3)** — mural de comunicados (`artisan fertways:noticia`) e o
    estado honesto do Gagarin (inativo até 50 jogadores / 45 dias).
- **Painel de administração da equipe (D-56)** — um painel **Blade por sessão** em
  **`https://fertways.tars.art.br/central/admin`**, com **credencial separada** (tabela `admins`,
  guard `admin`, isolado da auth de colono que é token). Contas se criam por `artisan fertways:admin
  --criar` (não há auto-registro). **Ver:** dashboard do estado (colônias, Fert$, Tesouro, filas do
  Ministério, conciliadores, intervenções, mural, jogadores, obras, zonas). **Agir:** julgar casos da
  equipe, apelações, conciliadores, intervenções, notícias, tick e realocar founders. As ações reusam
  o domínio (extraídos `GerirConciliador`, `DeclararIntervencao`, `PublicarNoticia`; tick/realocar via
  `Artisan::call`). O `bootstrap/app.php` deixa o `/admin` fora do comportamento API-only; o resto da
  API segue JSON e o índice `/central/` não lista as rotas do painel. **Primeiro admin em produção
  criado à mão** (2026-07-11): `by_nvs@outlook.com`. O painel tem a seção **Ministério do Tesouro**
  (D-57): saldo do caixa + envio de recurso/Fert$ a uma colônia.
- **O painel cresceu, e agora deixa rastro (D-61)** — era uma página só, e **não auditava nada**.
  - **Auditoria `audit_log`, append-only**: quem, quando, o quê, sobre quem, os **valores antes e
    depois**, o IP e o navegador — mais os **logins que falharam**. O modelo recusa *update* e
    *delete*, e a tabela não tem `updated_at`. **Auditar deixou de ser opcional:** o `ok()` do
    `AcoesController` **exige o nome da ação**, então não dá para acrescentar um botão e esquecer.
  - **Oito seções navegáveis** e **busca global** (nome, nickname, e-mail, colônia ou **placa** — a
    placa é o único identificador de outro jogador que aparece na tela de um colono).
  - **CRUD de jogadores**: ficha completa, **suspensão**, **correção de estado** e **realocação**.
    **Não se apaga jogador** — a cascata levaria o ledger, os acordos e as denúncias em que ele é
    parte, quebrando o histórico de **outros**.
  - **A suspensão** barra o login, **revoga os tokens** e congela **só o comércio** (reusa a restrição
    do §9.4). **A colônia continua produzindo:** o mundo não para, e nada se perde.
  - **Corrigir estado lança `ajuste_admin` no ledger, sempre**, com motivo escrito. A auditoria guarda
    o antes/depois; o ledger guarda o delta. É a única coisa no jogo que cria valor sem origem
    econômica, e por isso é **obrigada** a ter história.
  - **Dois papéis**: **dono** (tudo, inclusive gerir admins e realocar) e **operador**. Duas travas:
    **ninguém se desativa**, e **não se desativa o último dono** — o painel ficaria inacessível para
    sempre. A guarda é no **servidor** (middleware `dono`), não só no menu.
  - **Realocar FORÇA e refaz as viagens** em curso a partir da posição nova, exigindo a palavra
    **REALOCAR**. ⚠️ **A energia já gasta não é acertada**: o governo come a diferença. E os Acordos
    abertos ficam com o prazo da **distância antiga** — o painel avisa antes.
  - **Demolir exige a palavra `DEMOLIR` — na tela E NA API.** Só na tela seria cosmético.
  - **Sem e2e**, os dois: a demolição está atrás de um clique num hexágono do Phaser (mesma razão da
    receita da Oficina), e o painel é Blade, coberto por **48 testes PHP**.
- **A PORTA do painel, endurecida (D-71)** — a auditoria do D-61 era cega para a própria entrada.
  - ⚠️ **Quem volta pelo cookie do "lembrar de mim" NÃO passava pelo controller**, que era onde o
    login era auditado. Em produção o `audit_log` estava havia meses **sem uma única linha de
    login**, enquanto o dono usava o painel todo dia. Agora quem audita são os **eventos do `Auth`**
    (`Login`/`Failed`) — o único ponto por onde os dois caminhos passam. Quatro fatos distintos:
    `login.ok`, **`login.lembrado`**, `login.falhou` e `login.bloqueado`.
  - **Throttle no login**: 5/min por e-mail+IP e 20/min por IP. Antes eram **tentativas ilimitadas**,
    na mesma porta que realoca colônia e distribui o Tesouro. ⚠️ **O IP entra na chave de propósito:**
    só com o e-mail, qualquer um trancaria o dono para fora martelando o e-mail dele.
  - **O `fertways:admin` virou de novo um quebre-o-vidro de verdade.** Ele era de antes dos papéis:
    **`--criar` não escrevia o papel** (default `operador` — não havia como criar um dono pela CLI) e
    **`--remover` apagava por fora do `Domain\Admin\Contas`**, contornando a trava do último dono.
    Agora tudo passa pelo `Contas`, tudo audita, e `--listar` **avisa se há um dono só**.
  - **Dois donos ativos em produção** (`by_nvs@outlook.com` e `fbfert+fertways@gmail.com`).
- **Ministério do Tesouro e kit de recursos (D-57)** — o Tesouro virou caixa gastável (ver slot 2
  acima). E toda colônia recebe um **kit fixo**: 1000 metal bruto, 1000 ligas, 500 compostos, 300
  biocombustível, 500 componentes — **emissão do governo**, concedido na fundação
  (`ColonyController::store`) e por backfill (`artisan fertways:kit-recursos --aplicar`). Números
  decididos pelo usuário, **não do GDD**.
- **Os 21 slots da colônia (D-59)** — a colônia deixou de ser uma lista e virou um lugar. Colmeia de
  4/4/5/4/4; a construção tem **posição**, e **construção não erguida não ocupa slot** (a linha de
  `buildings` só nasce quando o colono aponta o buraco — antes, as 16 nasciam todas no nível 0).
  - As **5 essenciais nascem prontas no nível 1**, no miolo fixo — **revisa o D-13**, e o nível 1
    entra no ledger como `subsidio_governo`. O subsídio do §24.7 segue valendo do nível 2 ao 3.
  - **Mina, Oficina, Refinaria e Destilaria podem ser repetidas**, cada cópia com o seu nível: morre
    o `unique(colony_id, type)`, entra `unique(colony_id, slot)`. O freio de quantas cabem é o
    **Reator** (§19.8) — o único teto que o GDD publica.
  - **Demolição** (`DELETE /buildings/{id}`): o investido **não volta**, as essenciais são
    indemolíveis, e não se demole o que está em obra. Nada disso está no GDD.
  - **Cada construção agora diz o que faz** (`Domain/Building/Funcoes`), em duas camadas que não se
    confundem: o que o GDD **promete** (verbatim, com o §) e o que o jogo **entrega hoje**. Sete
    construções são promessa pura — dizê-lo evita que o colono gaste 90 Ligas num prédio inerte.
  - O **Tanque de Combustível** entrou no MVP (o GDD sempre listou 12 de progressão): **17
    construções**, 16 slots livres.
  - **Backfill:** `artisan fertways:slots` simula, `--aplicar` migra. Idempotente. **Construir e
    demolir não têm e2e** — o painel está atrás de um clique num hexágono do Phaser, mesma razão da
    receita da Oficina (D-54). Cobertos em PHP.

- **Ministério dos Transportes — Fatia 1 (D-60)** — **slot 8 da Capital**, contra o §2.1, que o
  reservava ao Quartel de Alianças. Arbitragem do usuário; a Capital tem **8 slots** agora.
  - **Fabricar caminhão é privativo dele.** A Central de Transportes do colono **não fabrica nada** —
    e o GDD (§17.2, §21.3) diz que fabrica. Contradição deliberada, registrada. Antes do D-60 o
    Caminhão de Carga era **inalcançável**: nada no jogo o produzia.
  - **A Central virou a vaga, e a vaga passa a morder**: teto = **máximo(1, nível)**. O piso de 1
    existe porque o D-59 fez colônia nova nascer *sem* Central, e o Furgão do kit precisava caber.
    A fórmula preserva as duas tabelas do GDD (§19.5 dá 1..10; o Terminal de Cargas, +2, dá 3..12).
  - **300 Fert$**, só o nível 1, pagos ao Tesouro. O Ministério **consome o caixa do Tesouro**
    (90 Ligas, 25 Componentes, 16 Metal Bruto por caminhão): **se o Tesouro secar, não há caminhão.**
  - Fabricação de **1 h**; o governo mantém **5 prontos** na prateleira, repondo sozinho no tick.
  - **A entrega é física:** o caminhão **dirige-se sozinho da Capital** até a colônia. Reusa o trecho
    de "volta" do `ConcluirTrechos` — **zero linha nova** na máquina de viagem.
  - **Placas (§16.3):** `FW-00001-C`. Todo veículo civil é registrado. Backfill: `artisan
    fertways:placas --aplicar`.
  - **O caminhão do governo é um veículo sem dono** (`colony_id` nulo) — é a "Frota Governamental"
    do §16.2. Vender **não cria veículo**: dá dono a um que já existia, e a placa atravessa intacta.
- **A frota envelhece, e há mercado de usados (D-60, fatias 2 e 3)**
  - **Depreciação (§16.4):** **0,5% por hora de uso ativo**, só Furgão e Caminhão. Veículo parado
    **não** envelhece. As duas viagens de **entrega** (caminhão novo da fábrica, e usado vendido)
    **não contam como uso** — quem comprou não recebe o veículo mais gasto do que o anúncio dizia.
  - **O desgaste encolhe velocidade E capacidade**, com o mesmo multiplicador. Por isso **toda a
    máquina de viagem passa pela `Conservacao`**, e não pelo `VeiculoSpecs` cru — inclusive a cotação
    da Frota.
  - **Nada trava. Nunca.** Contradição deliberada ao §16.4, que nomeia um "bloqueio operacional". O
    "limite crítico" do painel virou o **piso de desempenho** (25%): uma carcaça a 5% ainda anda a
    25% e carrega 25%. **Não "conserte" sem perguntar** — é o caso do D-32 outra vez.
  - **Manutenção na Central de Transportes do colono**, custando **10% do custo do veículo** em
    recursos (fração da tabela publicada, não constante nova). Restaura até o **teto**; e o teto cai
    **5 pontos** a cada serviço (~14 manutenções até não haver mais o que recuperar). **Sem Central
    não há manutenção** — e colônia nova não tem Central (D-59). É pressão, não sentença: o piso é
    25% e nada trava.
  - **Sucata só por vontade do dono**, sem devolução. E ela **arquiva, não apaga** (`SoftDeletes`):
    senão a placa do morto seria reciclada pelo próximo veículo do planeta, e os sucateados que o §16
    manda contar "por período" seriam incontáveis.
  - **Mercado de usados: 6ª aba do Mercado, com escrow do MINISTÉRIO.** Contraria de propósito a
    regra dos dois estoques do D-58 — o veículo está na colônia e mesmo assim não há calote. A razão:
    o Ministério é o **cartório da placa** (§16.3). O comprador paga, o veículo **dirige-se sozinho**
    até ele, e **o vendedor só recebe na chegada**.
  - **Teto de revenda = âncora × teto de conservação.** A âncora do **Caminhão** é o preço de fábrica
    (300 Fert$); a do **Furgão** é um **preço de referência do operador** (60 Fert$ por padrão, painel
    dos Transportes) — ele ficou **sem âncora** do aditivo 14 até o D-73, e era por aí que duas contas
    do mesmo jogador podiam **lavar Fert$** (Furgão sucateado anunciado por 5.000, escrow, sem carga,
    sem tributo). O usuário reviu; o cenário exato da lavagem virou teste. ⚠️ O painel recusa
    referência **zero**: teto 0 recusaria todo anúncio e tiraria o Furgão do mercado por acidente.
  - **Veículo anunciado não sai em viagem** — senão o comprador levaria um erro por culpa do vendedor.
  - **Os parâmetros são do operador**, no painel de admin: desgaste, piso, custo da manutenção e
    perda de teto. O §16 manda o Ministério configurá-los e o GDD nunca publica nenhum — **foi isso
    que permitiu tirar a depreciação da geladeira sem inventar constante no código** (padrão do D-35).
  - **Fora de escopo, de propósito:** o **Cargueiro Interplanetário** e o seu aluguel. Dependem do
    Espaçoporto e dos planetas NPC, que não existem.

**528 testes PHP (3775 asserções) + 7 e2e, verdes.** O cron do tick está instalado (crontab do usuário
`fertways`, log em `/home/fertways/logs/fertways-tick.log`) e roda o `artisan` **da cópia de
deploy** — o mundo avança sozinho. O tick faz: produção, upgrades, proteções, trechos de viagem,
acordos vencidos, **casos reatribuídos, janelas de apelação fechadas e a folha do Ministério**.

As telas têm **teste de ponta a ponta em navegador de verdade**: `npm run e2e` (ou
`./tools/e2e.sh`) sobe uma pilha efêmera (SQLite temporário + `artisan serve` + `vite dev`) e dirige
o Chromium do sistema com `puppeteer-core`. Mapa e Frota, Mercado, Acordo, Ministério e a Fundação.
Nunca toca produção nem o MariaDB. A **receita da Oficina não tem e2e**: o painel está atrás de um
clique num hexágono do Phaser, e acertá-lo por coordenada quebraria ao primeiro ajuste de layout. A
API dela é coberta em PHP.

Os **oito** arquivos (`e2e/{telas,chat,capital,mercado,acordos,ministerio,zonas,fundacao}.e2e.mjs`)
compartilham o andaime de `e2e/comum.mjs` **e o mesmo banco efêmero**, então **a ordem em que
`e2e.sh` os chama importa** e não é arbitrária:

1. **Mapa e Frota** — espera os **três furgões ociosos**, no pátio.
2. **Chat (D-81)** — não mexe em veículo nem recurso, só em mensagens; cabe em qualquer ponto da
   ordem, e fica cedo por não depender de nada que as telas seguintes ainda vão montar.
3. **Capital** — **subiu de posição no D-60**, e por um motivo concreto: a tela do Ministério dos
   Transportes precisa de um veículo **no pátio** para reparar e sucatear, e o botão de manutenção só
   existe para veículo ocioso. Rodando depois do Mercado, os três furgões já estão em rota e não há
   em que clicar.
4. **Mercado** — deixa dois furgões em rota. É também onde vive o e2e do **mercado de usados**, no fim.
5. **Acordo** — despacha o terceiro.
6. **Ministério** das Reputações, e **Zonas**.
7. **Fundação, por último**: registra um colono e funda uma quinta colônia — rodar antes bagunçaria
   as contagens de todas as telas anteriores.

> O seeder do e2e **gasta um dos furgões de propósito** (62% de conservação): sem desgaste, o botão
> de manutenção nasce desabilitado e o teste dela não teria o que exercitar.

> O e2e semeia **quatro** colônias (e2e em (0,3), vizinha em (0,6), ré, autora); o teste da Fundação
> acrescenta a quinta no fim. O mapa, visto pelo colono do e2e antes disso, desenha três vizinhas
> mais ele. Já me enganei uma vez esperando duas.

> **Instabilidade conhecida:** o do Mercado falhou uma vez em quatro com `Protocol error
> (Runtime.getProperties): Target closed`. Verde nas outras três. Se reprovar assim, rode de novo
> antes de investigar — mas se virar hábito, é bug de verdade.
>
> **Segunda variante (2026-07-14, D-81):** o Mercado também já falhou com `HTTP 401 em
> .../central/chat/pendencias` no console, com **todas as asserções verdes** — não é o teste que
> erra, é uma corrida: o HUD faz *polling* de `chatPendencias` a cada ~30 s **mesmo com o chat
> fechado** (D-77), e se o tique cair no instante do `Logout` do teste, ele usa o token que acabou
> de ser revogado. Rodou de novo e deu tudo verde. Mesma receita: reprovou assim, rode de novo.

**Publicado no GitHub e no ar.** O último deploy é de **2026-07-13**, no commit `8d409b3` — **o card
vira popup, o colono ganha PERFIL, e as zonas saem do esconderijo (D-69)**. **Sem migration e sem
passo à mão.**

> ⚠️ **O colono não podia trocar a própria senha.** Descoberto ao implementar: ele podia fundar
> colônia, guerrear, comerciar e ocupar território — e não podia mudar **nada** da conta. A única
> saída era pedir a um operador. Agora há `/perfil`: nome, nickname, e-mail, senha e nome da colônia.
>
> **Trocar o e-mail exige a senha atual; trocar o nome, não.** O e-mail é com o que se entra, e **não
> há recuperação de conta em Fertways**. E **trocar a senha REVOGA as outras sessões** — senão uma
> senha nova não expulsa o invasor, porque o token do Sanctum não expira (lição do D-53).
>
> Os quatro índices de reputação aparecem e **não se editam**: seria o colono apagar as próprias
> condenações.

Antes dele, `bf2fc7b` — **a arte
na Capital**. As áreas (Endurance, Mercado e Pátio, Espaçoporto) e os slots do Governo Central passam
a mostrar o prédio. **Só frontend: sem migration e sem passo à mão.**

> ⚠️ **A CapitalScene nunca fora avisada da arte** — os vínculos estavam no banco, a API os devolvia,
> e a cena não sabia. É o D-63 outra vez, **na mesma tela**: os sete e2e passavam porque os cliques
> funcionavam e só o desenho estava vazio. **Foi preciso fotografar e olhar.** Ao mexer em cena de
> Phaser: `E2E_FOTOS=1 ./tools/e2e.sh`.

> Os slots **1, 4, 5, 7 e 8** da Capital continuam **sem arte** — de propósito. Sobraram cinco imagens
> na categoria `capital` da biblioteca (`torre-axiom`, `cofre-meridian`, `forum-concordia`,
> `terminal-atlas`, `bastiao-aegis`) e **eu não sei qual é qual**. O usuário as vincula pelo painel,
> vendo a miniatura. Chutar poria arte errada num ministério.

Antes dele, `27fa4dc` — **a gestão
de imagens (D-68)**: o jogo ganhou rosto. **UM passo à mão:** `artisan fertways:importar-imagens
--aplicar` no `fertwaysbd`. Os arquivos moram em `/home/fertways/media` (fora da árvore de deploy),
servidos pelo symlink `public_html/media` — que precisa ser do **`fertways`**, senão o Apache dá 403
(`SymLinksIfOwnerMatch`).

> ⚠️⚠️ **O MEU PRÓPRIO TESTE APAGOU UMA IMAGEM DE PRODUÇÃO, e o `curl` de conferência mentiu.**
>
> `Biblioteca::RAIZ` era uma constante apontando para `/home/fertways/media`. O `ImagensTest`
> exercita o botão de apagar; o `apagar()` chama `unlink()` no caminho real; e o teste, que cria um
> registro chamado `reator-helios.png`, **destruiu o arquivo de verdade**. A suíte passou verde.
>
> E o `curl` que eu usei para conferir devolveu **200** — porque, antes daquele deploy, `/media` não
> estava excluído do fallback do SPA, e o Apache respondia com o `index.html`. Eu vi 200 e segui.
>
> **A correção não é lembrar de não fazer isso.** A raiz vem da config, o `phpunit.xml` a aponta para
> uma pasta temporária, e a `Biblioteca` **recusa-se a rodar** se um teste mirar a pasta real — com um
> teste que prova que a trava dispara. É a família de defesa do D-27.
>
> **Se for mexer em código que apaga arquivo, pergunte primeiro qual pasta o teste vai usar.**

> Conferido por leitura depois: 46 imagens, 18 vínculos, o Reator de Energia com a arte de volta. As
> imagens servem `200 image/png` e uma inexistente dá **404** — não mais o SPA. O painel responde 302
> e `/central/images` responde 401.

Antes dele, `c791cd2` — **a zona
vira lugar e as telas viram telas (D-67)**. Migration `a_zona_vira_lugar` (canteiro de obras, fila de
obras da zona, Refinaria, aviso da Torre). **UM passo à mão:** `artisan db:seed
--class=BuildingSpecSeeder --force` no `fertwaysbd`.

> ⚠️⚠️ **E esse passo à mão CONSERTOU a guerra, que estava quebrada em produção desde o dia anterior.**
>
> O catálogo de produção tinha **25 construções** e nenhuma da guerra: nem Sentinela, nem Muralha, nem
> Torre de Vigia. O Quartel procurava o custo da Sentinela no `building_specs` e não achava — **ninguém
> podia fabricar unidade nenhuma**, e nada reclamava. O deploy do D-66 foi feito e o
> `BuildingSpecSeeder` foi esquecido.
>
> É **exatamente** a armadilha que a Verificação rápida abaixo descreve, e eu caí nela mesmo com ela
> escrita: *"o `deploy.sh` NÃO roda seeders, e o esquecimento é silencioso"*. Já aconteceu com o Tesouro
> (D-57), as zonas (D-52), os parâmetros do transporte (D-60) — e agora com o **catálogo inteiro**.
>
> **Daqui em diante: toda vez que `building_specs.json` mudar, o seeder é passo à mão no deploy.** O
> seeder é `upsert`, então repeti-lo é seguro. Confira com a contagem de tipos: hoje são **33**.

> Conferido por leitura depois do deploy: catálogo com 33 tipos e a Sentinela com o custo do §27.1; a
> migration registrada (lote 18); `zone_materials` e `zone_build_queue` criadas; a zona ocupada intacta
> (20 unidades); o aviso da Torre em 10 min/nível, do default do banco. **`/mapa`, `/capital`,
> `/zona/1` e `/quartel` respondem 200** — antes davam **404**, porque não existem em disco. E a API
> continua devolvendo **JSON**: `/central/war` dá 401 com `{"message":"Unauthenticated."}`, não HTML —
> era esse o risco catastrófico do `.htaccess` novo.

Antes dele, `5aa850e` — **a GUERRA
(D-66, a Fatia 2 do D-52)** e a **segunda leva do painel de admin** (Reputações, Notícias com estado,
frota com placa, realocação pontual). Duas migrations, ambas ensaiadas no MariaDB com dados; **nenhum
passo à mão**. Ver as duas seções abaixo.

Antes dele, `b43586c` — **os dois
mercados, a carroceria com vários recursos e o Pátio da Capital (D-65)**, e ele **levou junto o D-64**
(a grade do mapa), que estava comitado havia um dia e nunca fora publicado. A migration
`patio_da_capital` (quatro colunas em `vehicles`) rodou sozinha. **Nenhum passo à mão** — a tarifa do
Pátio é constante de código, não linha de banco, então não há seeder a esquecer.

> ⚠️ **Como o D-64 ficou esquecido:** comitou-se e empurrou-se sem publicar, e a página não anotou a
> pendência. **Um commit em `main` que não está na árvore de deploy não é visível em lugar nenhum** —
> o site não muda, os testes passam, e nada reclama. Confira sempre `git log -1` nas **duas** árvores
> (está na Verificação rápida); se divergirem, há coisa pronta parada no vestiário.

> Conferido por leitura depois do deploy: a migration registrada (lote 16), as quatro colunas
> (`local`, `parked_at`, `patio_cobrado_ate`, `return_distance_slots`) no `fertwaysbd`, os **10
> veículos de produção em casa** (`local=colonia`, nenhum no Pátio — o certo: ninguém o usou ainda), as
> tabelas semeadas ainda cheias, e o bundle no ar igual ao recém-compilado. **Não se fez login em
> produção para "olhar"** — isso deixaria um token de teste no banco (lição de 2026-07-10).

Antes dele, `cf75925` (D-64) e `d29b981` (D-63), que subiram juntos neste mesmo deploy.

Antes deles, `bfd334f` — **o painel de administração com auditoria, CRUD e papéis (D-61)**, mais o
**GDD v36** (D-62, que é só documento). A migration `auditoria_papeis_e_suspensao` rodou sozinha,
exercitada antes no `fertwaysdev` nos dois sentidos. **Nenhum passo à mão.**

Conferido por leitura depois: `audit_log` criada, os campos de suspensão em `users`, e — o que mais
importava — **o admin `by_nvs@outlook.com` virou `dono` sozinho**, pela migration. Se ele tivesse
nascido `operador`, o painel ficaria **sem ninguém que pudesse gerir admins** e a única saída seria o
`artisan`. As **8 seções** respondem 302 (pedem login), não 404.

> **O `audit_log` nasce vazio, e isso é o certo.** Ele só grava atos do **painel** — o cron do tick
> não é ninguém. A primeira linha aparece no seu próximo login; a segunda, se alguém errar a senha.

Antes dele, `0efd041` — **o D-60 fechado: a frota envelhece e há mercado de usados (fatias 2 e 3)**.
Houve **um passo à mão** no `fertwaysbd`: `artisan db:seed --class=TransportSettingSeeder --force`.
**Sem ele a depreciação nasce inerte** — os quatro parâmetros são do operador, e a tabela vinha vazia.
Conferido depois: desgaste 0,5%/h, piso 25%, manutenção 10%, perda de teto 5 pontos.

Conferido por leitura: as colunas de conservação e o `deleted_at` (a sucata **arquiva**), as tabelas
`transport_settings` e `vehicle_listings`, as rotas no ar (`GET/POST /transport/listings` — 401), e a
frota de produção **inteira em 100% de conservação e 0 h de uso** — ninguém perdeu nada
retroativamente, o desgaste começa a correr de agora.

> **Os 5 caminhões do governo levam 1 h para sair da linha de montagem**, e o relógio está certo (a
> encomenda saiu no tick do deploy da fatia 1). Se você olhar a prateleira e a vir vazia logo depois
> de um deploy, é isto — não é bug.

Antes dele, `cee3cee` — **o Ministério dos Transportes, Fatia 1 (D-60)**. A migration
`ministerio_dos_transportes` rodou sozinha, **sem quebrar**, porque foi exercitada no `fertwaysdev`
(MariaDB) nos dois sentidos antes de publicar — a lição do D-59, aplicada. Um passo à mão:
`artisan fertways:placas --aplicar` — 5 furgões, um por colônia, placas `FW-00006-F` a `FW-00010-F`.

> **Elas não começaram em `FW-00001`, e isso não é bug.** Entre o deploy e o backfill, o **cron do
> tick rodou** e o Ministério já tinha fabricado os seus 5 primeiros caminhões, que levaram as placas
> `FW-00001-C` a `FW-00005-C`. O sequencial é global e por ordem de emissão (§16.3), não por tipo. Se
> você for conferir isto e estranhar, é esta a explicação.

Conferido por leitura depois do deploy: migration registrada, `colony_id` nulável (a frota do governo
do §16.2), `plate` única, o enum de `status` com `fabricando` e `estoque`, as rotas no ar
(`GET /transport`, `POST /transport/buy` — 401, não 404), o bundle no ar igual ao compilado, e a
**contabilidade do Tesouro fechando nos três recursos**: 5 caminhões × (90 Ligas, 25 Componentes, 16
Metal Bruto). As Ligas parecem 500 a menos do que a conta pede — **não é erro**: o Tesouro distribuiu
500 Ligas à colônia 4 em 2026-07-11 19:03, pelo painel de admin. `10.000 − 500 − 450 = 9.050`.

> **O que muda para quem joga, hoje:** o teto de frota passou a valer, e **nenhuma das 5 colônias de
> produção tem Central de Transportes** — logo, teto 1 para todas. Ninguém perde veículo (o teto só
> barra **comprar**), mas **ninguém compra caminhão** antes de erguer uma Central ao **nível 2**. A
> colônia 4 tem uma, mas no nível 1 — ainda não basta. É o desenho que o usuário escolheu: caminhão
> exige infraestrutura.

Antes dele, `4c51831` — **os 21 slots da colônia (D-59)**, mais o `fix` da migration que derrubou a
primeira tentativa (leia a lição acima antes da próxima migration). Houve **um passo à mão** no
`fertwaysbd`, como no D-57: `artisan fertways:slots --aplicar`. Conferido
por leitura depois: a migration registrada, o `unique(colony_id, slot)` no lugar do
`unique(colony_id, type)`, a FK `buildings_colony_id_foreign` intacta, as rotas novas no ar
(`GET /buildings/catalogo`, `POST /buildings`, `DELETE /buildings/{id}` — 401, não 404), e as **5
colônias com slot em todas as construções, nenhuma nula**. O backfill promoveu as essenciais das
colônias 1, 2 e 3 (que estavam no nível 0) ao nível 1, com o subsídio no ledger, e **preservou a
Plataforma de Pouso da colônia 4, que estava em obra** no nível 0 — o resto das linhas de nível 0
virou slot vazio, como o D-59 manda. A idempotência do backfill é coberta por teste
(`test_o_backfill_e_idempotente`); **não a reconfira rodando `--aplicar` de novo em produção.**

Antes dele, `2fe39fa` — **o Mercado novo (D-58): vitrine, teto no depósito e mural entre colonos**. A migration
`oferta_aberta_entre_colonos` (`colony_b_id` nulável) rodou sozinha no `deploy.sh`, lote 11, e
**não houve passo à mão** — ao contrário do D-56 e do D-57. Conferido por leitura depois do deploy:
as duas árvores em `2fe39fa`, o opcache executando a cópia de deploy, as rotas novas no ar
(`POST /market/orders/{id}/execute`, `GET /trade/board`, `POST /trade/agreements/{id}/accept`), e as
**5 ordens de compra que já existiam continuam abertas** — viraram ofertas da vitrine, como o D-58
previa. Nenhum acordo de contraparte nula ainda (o mural nasce vazio). Antes dele, no mesmo dia,
`4843c11` — de conteúdo só documental (a errata do D-37), mas que **carregou a mudança de `.env`** do
cookie seguro, que é o que exigia o `config:cache` e o reload do php-fpm; e **o Ministério do Tesouro
— caixa real + kit por colônia** (`32e3ed2`, D-57): a migration
`treasury_holdings` rodou sozinha no deploy, e houve **dois passos à mão** no `fertwaysbd` (conferidos
por leitura): `db:seed --class=TreasurySeeder` (dotou o caixa: 10k de cada + 1M Fert$) e
`fertways:kit-recursos --aplicar` (kit às 5 colônias existentes). Confira com `git log --oneline -1`
nas duas árvores — se voltar a divergir, republique. (Antes: **o painel de administração** (`3896b77`, D-56, migration
`admins` sozinha, primeiro admin `by_nvs@outlook.com` à mão), **a Capital** (`a6d37d4`, D-55), a
**Fatia 1 do D-52 — zonas neutras** (`0e0e3dd`), o arraste/zoom do mapa (`525b8c3`), o **mapa
concêntrico do D-51** (`2a71a1c`), o `fix` da fila do D-53 e as telas do D-54.)

> **Nota de segurança (D-56) — RESOLVIDA em 2026-07-11.** `SESSION_SECURE_COOKIE=true` está no `.env`
> de produção e **em vigor** (conferido na config cacheada, que é a que vale: `config("session.secure")`
> dá `true`). Backup do `.env` anterior em `/home/fertways/deploy/.env-producao.bak-20260711` — **fora**
> da árvore de deploy, de propósito; ver a lição abaixo.
>
> Duas coisas que a versão antiga desta nota errava, e que vale saber se o assunto voltar:
>
> - **O cookie nunca esteve inseguro.** O `config/session.php` faz `env('SESSION_SECURE_COOKIE')`
>   **sem default**, o valor virava `null`, e nesse caso o Symfony aplica o *secure default* herdado de
>   a requisição ser HTTPS — então o `Set-Cookie` já vinha com `; secure`. O que se ganhou aqui foi
>   trocar uma flag **inferida** por uma **declarada**: agora ela não depende de a detecção de esquema
>   (ou de proxy) continuar acertando.
> - **Mexer no `.env` sem `config:cache` + reload não faz nada.** É o D-45 outra vez: a config de
>   produção é cacheada. Foi por isso que a linha entrou junto de um `deploy.sh`, que já faz os dois.
>
> O `.env` de trabalho **não** recebe a linha: o `artisan serve` é HTTP e um cookie `Secure` não
> voltaria. O `.env.example` documenta isso, comentado.

> **Lição registrada (2026-07-12) — o e2e prova que CLICA, não que está CERTO na tela. Olhe.**
> Ao construir a Capital (D-63), os sete ministérios apareciam **pálidos, iguais aos slots vagos**: a
> cena do Phaser nunca era informada de quais slots existiam (o efeito que a atualiza não rodava de
> novo depois de o jogo ser criado), e desenhava tudo como "vago". **Os 7 e2e passavam** — e passavam
> com razão, porque os **cliques funcionavam**: os alvos são botões de DOM, e eles liam a lista certa.
> Só o **desenho** mentia.
>
> Nenhum teste de clique acharia isso, e nenhum teste de texto também: o canvas não tem DOM. **Foi
> preciso tirar um screenshot e olhar.** Quando mexer em cena de Phaser — cor, posição, rótulo,
> estado —, **fotografe e olhe**; o verde do e2e não é evidência sobre pixels.
>
> (Os outros dois defeitos do D-63 o e2e pegou: o `div` da cena colapsando a zero, e o seletor de zoom
> ambíguo com dois canvases no DOM. Ele é bom no que é bom.)

> **Lição registrada (2026-07-12) — a suíte roda em SQLite; a produção é MariaDB. Migration não
> testada é migration não escrita.** O primeiro deploy do D-59 **quebrou em produção**, na migration,
> com o app já fora da manutenção: `1553 Cannot drop index 'buildings_colony_id_type_unique': needed
> in a foreign key constraint`. O InnoDB exige que toda FK seja coberta por um índice que *comece*
> pela coluna dela, e o `unique(colony_id, type)` que a migration vinha matar era o único que cobria
> a FK `buildings_colony_id_foreign`. **A cura é criar o índice novo antes de matar o velho** — o
> `unique(colony_id, slot)` também começa por `colony_id` e cobre a FK. O `down()` tinha a mesma
> armadilha ao contrário.
>
> **Os 266 testes passavam.** Eles correm em SQLite, que não tem a regra do índice de FK: lá as três
> operações passam em qualquer ordem. E o `phpunit` usa `migrate` num banco novo, então **nem o
> caminho normal da migration tocava o MariaDB alguma vez**. A falha só podia aparecer em produção,
> e apareceu.
>
> A migration ficou **idempotente** porque a tentativa falha deixou produção pela metade (coluna
> `slot` criada, índice velho vivo, migration **não registrada** — o `migrate` seguinte tentaria
> recriar a coluna). Ela agora confere cada passo antes de agir e termina o serviço de onde parou.
>
> **A regra, daqui em diante:** antes de publicar, rode a migration no `fertwaysdev`, que é MariaDB
> de verdade — `artisan migrate` e `artisan migrate:rollback`, os dois. Está na Verificação rápida.
> O verde do `artisan test` **não é evidência** sobre DDL.

> **Lição registrada (2026-07-14) — a mesma doença do D-59, em nova roupa: largura de coluna, não
> ordem de índice.** O usuário reportou "Server Error" ao clicar "Comprar" no Ministério dos
> Transportes. Causa: `vehicles.trip_purpose` nasceu `VARCHAR(12)` em 09/07 (só existiam `entrega` e
> `retirada`); o D-60 (12/07) passou a gravar `entrega_de_fabrica` — **19 caracteres** — e ninguém
> alargou a coluna. Em produção (MariaDB, modo estrito), a gravação é recusada
> (`1406 Data too long for column`), a transação sofre rollback (limpo: nenhum Fert$ perdido, nenhum
> caminhão fantasma) e o colono só vê "Server Error".
>
> **`test_comprar_debita_o_fert_e_o_caminhao_vem_dirigindo` passava, e sempre passou**, incluindo
> `assertSame('entrega_de_fabrica', $caminhao->trip_purpose)` — porque roda em SQLite, que não aplica
> largura de `VARCHAR`. **Ninguém em produção jamais comprou um Caminhão de Carga com sucesso**: o
> `Ledger` de `compra_veiculo` está em zero desde que o Ministério foi ao ar (conferido por leitura,
> 2026-07-14).
>
> Corrigido: migration `trip_purpose_estava_curto_demais`, coluna para `VARCHAR(32)` (mesma largura
> de `structure`/`resource_type`), ensaiada nos dois sentidos no `fertwaysdev`. **A regra do D-59 vale
> de novo, mais ampla:** o SQLite dos testes não aplica FK-antes-de-índice **nem largura de coluna**.
> Qualquer valor de string novo que um domínio passe a gravar merece conferir a largura da coluna no
> MariaDB — não só rodar a migration.

> **Lição registrada (2026-07-10).** Ao conferir o D-53 em produção, enfileirei uma construção de
> teste na colônia 4 pelo `EnqueueUpgrade`. Funcionou, mas escrever no banco de produção "para ver
> com os próprios olhos" deixou resíduo: item de fila, marca de upgrade na Oficina e **seis
> lançamentos no ledger**. Limpei os três, o último com autorização do usuário (apagar ledger de
> produção é barrado por padrão, e com razão). **Não escreva em produção para verificar** — confie
> no e2e e nos testes, ou use só leitura. Ver [[fertways-nao-escrever-em-producao-para-testar]].

> **Lição registrada (2026-07-11) — o Claude roda como root, e isso apodrece a árvore.** Toda edição
> feita por aqui grava o arquivo com dono `root`. O `git commit` como `fertways` então falha com
> *"insufficient permission for adding an object"*, e o remendo óbvio — comitar como root — piora o
> problema, porque deixa objetos e refs do `.git` com dono root. Quando eu fui olhar, **102 arquivos**
> da árvore de trabalho estavam assim, acumulados de sessões anteriores (código, e2e, docs e o
> `.git/refs/heads/main`). Corrigido com `chown -R fertways:fertways /home/fertways/apps/fertways`.
>
> O nó é que **o push exige root e o commit exige `fertways`**: a credencial do GitHub é o token do
> `gh` do root (`/root/.gitconfig` manda o helper para `gh auth git-credential`), e o `fertways` não
> tem nenhuma. A receita que funciona, nesta ordem:
>
> 1. `sudo -u fertways -H git add … && sudo -u fertways -H git commit …`
> 2. `git push origin main` **como root** (é o único que tem credencial)
> 3. `chown -R fertways:fertways .git` — o push acabou de escrever `refs/remotes/origin/main` como root
>
> E, depois de qualquer sessão que tenha editado arquivos: `chown -R fertways:fertways` na árvore.
> A árvore de **deploy** não sofre disso — o `deploy.sh` puxa tudo como `fertways`.

> **Lição registrada (2026-07-11) — não deixe arquivo novo na árvore de deploy.** Fiz o backup do
> `.env` de produção como `backend/.env.bak-…`, *dentro* da cópia de deploy, e o `deploy.sh` abortou:
> ele exige que a árvore seja descartável e viu um arquivo não rastreado. O `.env` é ignorado pelo
> git; um `.env.bak` **não é**. A guarda está certa e não deve ser afrouxada — **guarde o backup fora
> da árvore** (foi para `/home/fertways/deploy/.env-producao.bak-20260711`).

## O deploy, depois do D-45

- Edita-se em `apps/fertways`. **Não é servido.**
- O Apache serve `/home/fertways/deploy/fertways`, e o cron do tick executa o `artisan` de lá.
- Publicar é `sudo ./tools/deploy.sh` — e **só ele publica**, porque agora ele recarrega o php-fpm.
  Sem o reload, o opcache mantém os workers presos na árvore para onde o symlink apontava quando
  eles subiram, e o deploy não tem efeito nenhum (D-45). A fumaça de `200`/`401` não detecta isso:
  o script pergunta ao opcache qual árvore está no ar e aborta se achar `/home/fertways/apps/`.
- **Os bancos agora são dois** (D-46): `fertwaysdev` na árvore de trabalho, `fertwaysbd` só no
  deploy. O D-36 está fechado.

## O mapa concêntrico do D-51 — **no ar** (2026-07-10)

O mapa do D-51 está implementado e publicado. A produção usa a grade **101×101**, Capital em
**(0,0)**, coordenadas **com sinal** (`tinyint`); founders no disco `d ≤ 4` (48 células: 28 populáveis
+ 20 reservados), anel livre `4 < d ≤ 5`, periferia `d > 5`. As faixas usam a distância euclidiana
**exata**; o **frete/tributo (§25.6) continua na arredondada half-up** — não unifique os dois. Tudo em
`MapaFertways`. O colono **escolhe** a célula (`EscolherPosicao` morreu): `POST /colony` recebe `x,y`,
`GET /map` serve o seletor, e `Fundacao.tsx` é o seletor visual (disco ampliado + aba de periferia).
As **4 colônias de produção foram realocadas** para slots de founder ((0,1),(0,-1),(-1,1),(1,-1)) pelo
`artisan fertways:realocar-founders` — comando guardado por veículos ociosos, útil de novo se um dia
houver que remanejar.

## Os dois mercados e o Pátio da Capital (D-65) — **no ar** (2026-07-12)

**O Mercado Local virou tela própria** (o botão da construção agora diz "Abrir o Mercado"): envia
carga ao depósito e a outros colonos, oferta a colonos e vê as ofertas deles. **O Mercado Central**
(pelo Leste da Capital) ficou com o que é do governo: **Pátio e depósito**, Ofertar no Mercado
Central e Ofertas globais. Os **usados** mudaram para o **Ministério dos Transportes**.

**A carroceria leva vários recursos até lotar** — o servidor sempre aceitou (o §25.4 soma unidades);
era a tela que mandava um por vez.

**O Pátio:** todo veículo que entrega no depósito **fica estacionado na Capital** e é de lá que sai
de novo (para casa, ou direto para outro colono — e, entregando, segue para casa). Paga **0,005
Fert$/hora** ao Tesouro, sem limite de vagas; **sem Fert$, é rebocado para casa** de graça. Se a
carga não coube no teto do depósito, o veículo **volta na hora** com a sobra, em vez de estacionar.

**Duas coisas do motor mudaram, e é bom saber antes de mexer em logística:**

- A viagem tem **pernas independentes** (`return_distance_slots`). **Nulo = viagem só de ida**: o
  veículo termina no destino e fica lá. É esse nulo que estaciona o veículo no Pátio, que traz o
  caminhão do Pátio para casa de vez, e que reboca quem não paga.
- **Cada perna paga a sua energia** (revisão do D-30). Levar ao depósito custa **metade** do que
  custava — uma perna, porque ele não volta. A volta forçada pela sobra é **de graça**: quem a causou
  foi o teto, não o colono.

`Domain/Capital/Patio.php` cobra a hora no tick e reboca. A tarifa fecha a lacuna que o D-63 deixou
aberta: o GDD publica "cobrança por hora" no slot 6 e nunca o preço.

## A grade do mapa (D-64) — **no ar** (2026-07-12, junto com o D-65)

O mapa **abre em 15×15, centrado na colônia do jogador**, e o botão da mira devolve esse
enquadramento. O zoom livre continua por cima (até o planeta inteiro — sem ele não se chega às zonas
dos cantos). A grade risca **uma linha por célula** (rareia para 5 e 10 conforme se afasta), os
números de X e de Y moram numa **calha fora do mapa** que não escorrega com o arraste, e as faixas do
centro (disco de founders, anel livre) são **sombreadas célula a célula**. Na borda do planeta a
vista passa da grade: você fica sempre no meio da tela. A **Fundação** usa a mesma grade nas duas
abas. `GET /colonies` passou a publicar `raio_founder` e `raio_anel`.

A geometria compartilhada vive em `frontend/src/ui/geometria.ts`; o que se desenha dela, em
`frontend/src/ui/Grade.tsx`. Nenhuma constante de grade no React — vêm da API (D-51).

## O Ministério dos Transportes (D-60) — **fechado e no ar** (2026-07-12)

As três fatias estão publicadas. Não sobrou nada por construir e não há pergunta em aberto. O que
ficou de fora foi **de propósito**: o **Cargueiro Interplanetário** e o seu aluguel (a 5ª atribuição
do painel do §16) dependem do Espaçoporto e dos 5 planetas NPC, que não existem.

**O que vigiar quando o jogo abrir**, e por que:

- ~~O Furgão sem teto de revenda~~ — **fechado no D-73**: âncora de 60 Fert$ do operador, no painel.
  Se 60 se mostrar apertado ou largo quando o mercado de usados mexer, muda-se lá, sem deploy.
- **O preço de 300 Fert$ do Caminhão** é ~9× o valor dos recursos dele. Foi escolhido para ser um
  objetivo de médio prazo e um dreno de Fert$; se ninguém comprar, é o primeiro número a revisitar.
- **Os cinco parâmetros do transporte** (desgaste, piso, manutenção, perda de teto e — desde o D-73 —
  a referência do Furgão) estão no painel de admin e mudam sem deploy. É lá que se balanceia a
  frota — não no código.

- **A Capital virou lugar, e as cenas ganharam zoom (D-63)** — ela era um menu de sete linhas.
  - **Quatro áreas e uma praça**, em Phaser, como a colônia: **Norte** o Governo Central (19 slots),
    **Oeste** os destroços da **Endurance**, **Leste** o **slot 6** (Mercado Central + Pátio
    Logístico, a mesma área), **Sul** o **Espaçoporto**, e ao **centro a praça — decorativa**.
  - ⚠️ **A planta não está no GDD.** Ele trata a Capital como uma **lista plana de 20 slots** (§2.1),
    sem geografia nenhuma. As quatro áreas são arbitragem do usuário. **Não a procure no documento.**
  - **O Norte mostra 19 slots, não 20:** o **6 não está lá porque ele *é* o Leste.** No GDD o slot 6
    *é* o Estacionamento de Caminhões, que a versão sanitizada rebatiza de Pátio Logístico — e é
    dentro dele que o Mercado mora desde o D-55. Uma coisa, um lugar.
  - **A Endurance e o Espaçoporto contam a verdade:** mostram o que o GDD publica (a história da nave;
    os 5 planetas NPC com distância e risco) e **admitem que missões e rotas não existem**. É o padrão
    do Gagarin (D-55). O painel da Endurance **resolve a contradição do Gagarin**: ele é satélite
    orbital, **não** repousa no casco (D-47).
  - **O Estacionamento é só desenho.** O GDD publica as 20 vagas e **nunca o preço da hora** — lacuna.
  - **Zoom na colônia e na Capital**, com o idioma do mapa: roda, botões −/+, centralizar, arrastar.
    **Não persiste** entre aberturas.
  - ⚠️ **O zoom NÃO é o da câmera do Phaser**, e isso é deliberado. A cena pinta, mas quem recebe o
    clique são **botões de DOM** sobrepostos (D-59). Se o zoom fosse `camera.setZoom()`, o desenho
    aproximaria e **os botões ficariam onde estavam** — o colono clicaria num hexágono e acertaria o
    vizinho. A vista entra na **função de geometria**, que a cena e os botões compartilham: não há
    duas contas, então não há como divergirem. **O e2e prova isso**: aproxima e depois clica.

## O GDD v36 — **existe** (2026-07-12) <span>D-62</span>

**`/home/fertways/FERTWAYS_GDD_v36_CONSOLIDADO.html`.** Substitui o v35, que fica **intocado** como
registro histórico. Resolve as contradições **no texto** — não há mais tabela de precedência, porque
não há mais duas redações concorrentes —, marca as **lacunas abertas** sem inventar número nenhum, e
separa o que o jogo **entrega** do que ele **promete**.

> **É um GERADOR, não um arquivo escrito à mão:** `tools/gdd-v36.php`. As tabelas numéricas são
> lidas de `building_specs` e `resource_types` — **as mesmas de onde o jogo lê** —, e essas tabelas
> têm testes que provam que batem com o GDD (`tests/Gdd/`). **O documento não pode divergir do jogo.**
> Foi essa a doença do v35: ele era estático, o jogo mudou 59 vezes e o texto não.
>
> Regere-o depois de mudar qualquer número:
> ```sh
> cd /home/fertways/apps/fertways
> /usr/bin/php84 tools/gdd-v36.php > /home/fertways/FERTWAYS_GDD_v36_CONSOLIDADO.html
> ```
> Ele **falha alto** (código 1) se uma construção nova não tiver nome próprio no mapa — senão o GDD
> sairia com o nome do prédio escrito errado, sem acento, e ninguém perceberia.

Hoje: **31 tabelas · 28 implementado · 17 promessa · 6 lacuna aberta · 13 arbitrado**.

**A seção 10 é a mais útil:** a lista de tudo o que ainda falta decidir (guerra, Drone, árvore de
pesquisa, receita das Ligas, população, Marco, serviço logístico, teto de estoque, níveis de veículo,
Espaçoporto). **Nenhum número ali foi inventado, e nenhum será até que você o decida.**

**Quando o v36 estiver assentado, o D-47 vira história:** não há mais precedência a aplicar.

## A GUERRA (D-66) — **no ar** (2026-07-13)

**A Fatia 2 do D-52 está publicada.** As arbitragens estão todas fechadas (**D-66** em
`docs/decisoes.md` — leia-o antes de tocar em qualquer coisa; são **oito**, e três delas nasceram de
bugs do próprio GDD). Deploy no commit `5aa850e`, **sem passo à mão**.

> ⚠️ **A migration da guerra foi a primeira em muito tempo a mexer em DADOS**, e não só em esquema: ela
> converte o `garrison` (um inteiro) em linhas de `units`. Quando foi escrita, produção tinha **zero**
> zonas ocupadas e o backfill seria vazio; na hora de publicar, já havia **uma**, com 20 robôs. O
> backfill **nunca fora exercitado com dados em lugar nenhum** — nem no dev (que não tem zonas), nem
> nos testes (que migram um banco vazio). Foi ensaiado antes, no MariaDB, com dados no formato da
> produção: ida (20 unidades), volta (o `garrison` retorna em 20) e ida de novo (continuam 20, não 40).
>
> **Conferido em produção depois:** a zona 1 tem 20 unidades, todas nível 1 e inteiras; nenhuma órfã.
>
> **A lição:** `migrate:status` dizendo "Ran" não diz que o backfill foi *exercitado*. Um backfill que
> nunca encontrou um registro nunca foi testado.

> ⚠️ **O `fertwaysdev` tinha PERDIDO a coluna `vehicles.deleted_at`** — a migration constava como
> "Ran" e a coluna não existia (rollback interrompido em alguma sessão anterior). Ou seja: **o banco de
> dev não era espelho fiel da produção, e ensaiar nele não provava nada.** Corrigido. Se for exercitar
> uma migration lá, **compare os esquemas primeiro** (`Schema::getColumnListing` nos dois bancos).

**O que existe (437 testes PHP + 7 e2e verdes):** — o D-70 (2026-07-13) fechou os quatro buracos que
esta seção listava. **A guerra está inteira.**
- **Catálogo (+5, agora 30 tabelas):** Muralha de Perímetro, Torre de Vigia, Bastião, Abrigo de Robôs
  (bases arbitradas) e a **Sentinela** (custo publicado no §27.1).
- **`units` — unidades com HP.** O `garrison` int **morreu**: o §27.6 exige HP individual, e um
  contador não sabe quem está ferido. A API ainda publica `garrison`, agora contado.
- **`war_settings`** — os bônus do §27.3 e as duas chances do §28.10, do **operador**. **Têm aba no
  painel** desde o D-70 (`/central/admin/guerra`); antes só se mudavam por SQL. Mudança **não afeta
  batalha em curso** — a força e o dano congelam na chegada.
- **`Domain/Guerra/`** — `Forcas` (§27.3, com o +20% offline por snapshot), `Protegido`,
  `Atacar` (os 4 tipos, marcha 1,3×, cooldown de 48 h), **`ResolverCombates`** (a rodada de 10 min,
  no tick), `ComprarNiobio`, `FabricarUnidade`, e — do D-70 — **`Reforcar`**, **`ChegarReforcos`** e
  **`RomperCerco`**.
- **O defensor tem as duas mãos (D-70).** **Reforçar** (§27.5): a tropa que **chega** recongela a
  força, e a mesma batalha que tomava a zona passa a ser repelida; a que está **em marcha** não conta.
  **Romper o cerco** (§28.10): o sitiado sai a campo com Sentinelas — na ruptura **quem ataca é o dono
  da zona**. ⚠️ **Zona cercada não recebe reforço** (é o que "cercada" significa), e por isso a
  ruptura existe. A tela oferece um botão **ou** o outro, nunca os dois.
- **O Quartel enfim fabrica.** Ele estava no catálogo desde sempre e não fazia nada: **nenhuma
  Sentinela poderia existir**. O nível dele é o teto do nível da unidade (não está no GDD — é o
  desenho da Central de Transportes do D-60).
- **Tela:** o **Quartel** é a porta (clique na construção), com o Nióbio, a fábrica, o exército e as
  batalhas em curso — **inclusive as em que se está defendendo**, que é o que o §27.5 exige, agora
  com o **botão de despacho** (o colono diz *quantas*; a UI escolhe *quais* — as Sentinelas mais
  sãs primeiro). O **ataque** parte do painel de zona, no mapa.
- API: `GET /war`, `GET /war/combats`, `POST /war/units`, `POST /war/niobio`, `POST /war/attack`,
  **`POST /war/reinforce`**, **`POST /war/break-siege`**.

**O que falta, e nada disso bloqueia:**
1. **e2e do Quartel.** Ele está atrás de um clique num hexágono do Phaser — **mesma razão da receita
   da Oficina (D-54) e da demolição (D-59)**. A API é coberta em PHP (`DefesaTest`, 14 testes).
2. **Federação rompendo cerco por aliado** (§28.10). **Federações não existem.** O dono rompe o seu.

### As três coisas que esta sessão descobriu e que ninguém sabia

- **A guerra nasceria morta, e por causa do Nióbio.** A Sentinela custa **3 Nióbio Alienígena**, o
  Quartel onde ela é feita custa outros 3, **nada no jogo produz Nióbio**, e o planeta inteiro tem
  **20 unidades** (do kit de fundação; a colônia `teste` tem zero). Cada colônia ergueria **uma**
  Sentinela — 80 de ataque — contra uma zona de **500 de defesa**. Atacar seria **matematicamente
  impossível**. É o Caminhão antes do D-60 outra vez. **A cura arbitrada: o governo vende Nióbio** do
  caixa do Tesouro (que tem 10.000), a 10× o preço de referência do §06.
- **O saque seria sempre zero.** "Protegido = o que cabe no Depósito" (arbitragem) + "a extração para
  no teto do Depósito" (Fatia 1) ⇒ nada jamais exposto ⇒ **espólio nenhum**. Curado: **a extração
  deixou de parar no teto**, o excedente empilha ao relento, e é ele o butim. ⚠️ **Contraria o §19.6
  de propósito** — ele chama aqueles números de "capacidade". **Não "conserte" sem perguntar.**
- **Nenhuma batalha terminaria.** A fórmula do §27.5 tira o dano da força "**atual**", que decai
  geometricamente e nunca zera: no cenário que o GDD estima em ~4 rodadas, a defesa ainda tem 92
  pontos na rodada 12. Curado: **o dano sai da força INICIAL**. E isso **reproduz exatamente** a
  estimativa do próprio GDD no cenário equilibrado (12 rodadas, 120 min) — quem escreveu a tabela
  calculou assim e escreveu "atual" no texto.

## As Missões (§06, D-78) — **no ar** (2026-07-14), commit `c916329`

O §06 publica os ciclos (Tutoria, Diária, Semanal, Federação, Guerra, Evento, Conquista) e cala os
valores. Leia **D-78** em `docs/decisoes.md` antes de mexer — três arbitragens do usuário e o
aditivo de gestão que veio depois, todos ali.

**O que está ligado:** Tutoria (5 missões, entregues na fundação, 3 dias de prazo — **recompensa
mas não trava** o subsídio das essenciais, contradição consciente com o §03), Diárias (3 por dia,
sorteadas de um pool de 33 no primeiro pedido da janela, 1 rejeição/dia) e Semanal (1 por semana,
qua 07h → ter 23h59). Recompensas **generosas** por decisão do usuário — e são **emissão**: o
painel é o torniquete se o Fert$ inflar.

**13 ganchos** espalhados pelo domínio inteiro escutam o jogo (obra, zona, combate, acordo,
mercado, ordem, despacho, unidade, drone, nióbio, chat, frete, manutenção) e chamam
`Progresso::registrar()`. Concluir **paga na hora**, sem botão de resgate.

**O CRUD (aditivo do mesmo dia):** o usuário pediu "quero que essas missões sejam gerenciáveis no
backend" — o painel ganhou **aba própria** (Missões): criar, editar, ligar/desligar e apagar
(só se o molde nunca foi sorteado — a FK é `cascadeOnDelete`, e apagar um molde usado destruiria o
histórico de uma recompensa que já saiu do Tesouro). `App\Domain\Missoes\Acoes::TODAS` é a lista
canônica das 13 ações — o formulário só oferece essas, então não dá para criar uma missão
impossível (ação que nenhum gancho dispara). ⚠️ Editar `ação`/`meta` só vale para o próximo
sorteio; editar o **prêmio** vale também para quem já tem a missão na mão — de propósito.

⚠️ **`deploy.sh` não roda seeders.** Depois do deploy, `MissionTemplateSeeder` foi rodado à mão em
produção (`sudo -u fertways php84 artisan db:seed --class=MissionTemplateSeeder --force`) — 46
moldes (5 tutoria + 33 diárias + 8 semanais), conferidos por leitura. Se um dia o catálogo sumir
(banco novo, rollback), é este comando que o repõe — idempotente por `chave`.

**528 testes PHP (3775 asserções) + 7 e2e, verdes** no momento do deploy.

## A sessão de 2026-07-14: D-79, D-80, D-81 e o `trip_purpose` — **no ar**

Quatro trabalhos, dois deploys. **536 testes PHP** — pré-existente e não relacionada segue **1 falha
em Missões** (`MissoesTest::a_semanal_e_uma_por_semana_e_persiste`, confirmada com `git stash` antes
de qualquer edição desta sessão — não investigada, não bloqueia). **e2e: oito arquivos, todos
verdes** (Chat é o novo; Mercado tem duas variantes de flake conhecidas — ver "Instabilidade
conhecida" na Verificação rápida —, reproduza de novo antes de investigar).

**D-79 — as três últimas estruturas de zona.** Estrutura de Extração, Central de Comunicação e
Plataforma de Pouso (da zona) — que o D-67 tinha deixado fora de escopo — ganham custo e tempo,
**inertes** de propósito, como o Cemitério de Robôs. `Estruturas::AUSENTES` fica **vazio**: as 12
estruturas de zona têm todas custo e função declarados. ⚠️ Colisão de nome: a Plataforma de Pouso da
zona é entidade diferente da do slot principal da colônia — slug `plataforma_de_pouso_da_zona`.
Migration `as_tres_ultimas_estruturas_da_zona`, três colunas em `neutral_zones`.

**D-80 — a zona ganha nome, e três queixas de UX.** A mensagem de despacho de material passa a dizer
qual veículo (tipo + placa), o que ele leva e quando chega — antes só dizia "um veículo". O botão
"Já há uma obra em curso" ganhou uma linha dizendo QUAL e QUANDO — a informação já existia lá embaixo,
no Canteiro, só não estava onde a dúvida nasce. E a zona pode ser nomeada, como a colônia (`PATCH
/zones/{id}/name`, texto livre, opcional, sem unicidade) — aparece no mapa, no popup e em "Minhas
zonas". Migration `nome_da_zona_neutra`.

⚠️ **`trip_purpose` estava curto demais, e quebrava TODA compra de Caminhão em produção desde o
D-60 (12/07).** `VARCHAR(12)` não cabia `entrega_de_fabrica` (19 caracteres); o MariaDB recusava a
gravação, a transação sofria rollback (limpo — nada perdido), e o colono via "Server Error". O teste
que cobre isso sempre passou, porque roda em SQLite, que não aplica largura de coluna. **Confirmado
por leitura em produção: zero compras de Caminhão bem-sucedidas desde sempre** (`Ledger` de
`compra_veiculo` em zero). Corrigido: coluna para `VARCHAR(32)`. Ver a "Lição registrada
(2026-07-14)" na Verificação rápida, logo abaixo da do D-59 — é a mesma família de bug.

**D-81 — o nick vira porta.** No canal público, clicar no nick de outro colono abre uma privada com
ele. Na privada, clicar no nick abre um popup: colônia, posição, porte e as zonas que ele ocupa —
mesma régua de privacidade do diretório (D-37), sem recursos, saldo, frota, reputação nem guarnição
e depósito de zona (a névoa do Drone, D-74, protege isso também aqui). A lupa ao lado de "Privadas"
busca por nickname entre colonos com colônia fundada; clicar num resultado abre o mesmo popup. Sem
migration — só leitura do que já é público em `GET /colonies`. e2e novo (`chat.e2e.mjs`).

Todas as migrations ensaiadas nos dois sentidos no `fertwaysdev` (MariaDB) antes de publicar.

## A Indústria Siderúrgica (D-82) — **no ar** (2026-07-15)

Construção nova, pedida pelo usuário — **não está no GDD**. Existe na colônia e na zona neutra:
processa Metal Bruto em Ligas Metálicas e nos cinco minerais eletrônicos, a cada 1000 processado:
350 Ligas, 35 Alumínio, 30 Cobre, 20 Estanho, 4 Ouro, 1 Tungstênio. Taxa de processamento igual à
Mina Local, nível a nível; custo 25% maior que a Mina, em cada nível; repetível como a Mina (D-59).

⚠️ **Quebra de propósito o §4.3 do GDD**, que reserva esses cinco minerais só ao governo na
Temporada 1 ("jogadores não extraem esses minerais"). **Confirmado pelo usuário** — mesma família
de arbitragem do tributo (D-32) e do Ministério dos Transportes (D-60). Ver **D-82** em
`docs/decisoes.md` para o raciocínio completo, a tabela de custo/taxa por nível, e a lição sobre a
curva de custo (`GddSpecsTest` exige `half-up(base × 1,65^n)` a partir de uma base única — "Mina ×
1,25 nível a nível" diverge da curva em até 1 unidade em alguns níveis; a base do nível 1 é que
leva o ×1,25, e o resto sai da curva padrão).

Na zona, só funciona em Metal Bruto (Nordeste) e **disputa o mesmo depósito que a Refinaria de
Campo** — decisão do usuário, quem chegar primeiro no tick leva. Ligas vai para `refined_amount`
(o mesmo pote da Refinaria); os cinco minerais precisaram de armazenamento novo (`zone_minerals`),
porque a zona só tinha lugar para dois recursos. Contam no MESMO teto de capacidade e ficam
expostos ao MESMO saque de tudo o mais — `Protegido` agora reparte butim entre qualquer número de
potes, não só dois.

De quebra, a retirada por veículo (Mapa/Zona) que só sabia buscar o minério bruto agora busca
qualquer coisa que esteja no Depósito — bruto, refinado, minerais —, o que também destravou uma
lacuna antiga: o refinado da Refinaria de Campo não tinha como sair da zona pela tela. E
`entregarMaterial`/`retirarDeZona` passam a dizer tipo e placa do veículo, mesma razão do D-80.

Migrations `industria_siderurgica_na_colonia` e `industria_siderurgica_na_zona`, ensaiadas nos dois
sentidos no `fertwaysdev` (MariaDB), inclusive as duas juntas em sequência. **550 testes PHP** —
549 verdes, a mesma falha pré-existente e não relacionada de Missões. **e2e: os oito arquivos,
todos verdes**, com a Indústria Siderúrgica coberta em `zonas.e2e.mjs`.

**Publicado** no commit `c65a162`. **Um passo à mão** no `fertwaysbd`: `artisan db:seed
--class=BuildingSpecSeeder --force` — o `deploy.sh` não roda seeders, e a Indústria Siderúrgica só
existe no catálogo depois disso (idempotente, upsert por `type`+`level`: repetir é seguro). Conferido
por leitura depois: as 5 linhas do catálogo (custo, tempo, taxa) e as três colunas/tabela novas, todas
no ar.

## A receita de Ligas e Compostos (D-83) — **no ar** (2026-07-15)

Fechou a lacuna 5 do D-52: "o GDD publica a taxa (30/h) e nunca a receita" de Ligas Metálicas
(Oficina) e Compostos Químicos (Refinaria Química), inertes desde o D-19.

**Ligas Metálicas não ganhou receita — perdeu a fonte.** Em vez de arbitrar uma proporção nova pela
Oficina, a decisão foi abandonar essa fonte: Ligas só nascem da **Indústria Siderúrgica** (D-82),
que já converte Metal Bruto de verdade. `ligas_metalicas` saiu de `production.json` da Oficina —
nunca era lido mesmo (o laço de `ColonyTick` só extraía `componentes_eletronicos` de lá).

**Compostos Químicos ganhou receita: 1 Metal Bruto + 10 Água + 5 Biomassa + 6 Energia → 1
Composto.** ⚠️ Mas a taxa publicada (30/h) não coube nessa proporção — pediria 300 Água/h contra
os 80/h que a Captação nível 1 produz. **A taxa foi reduzida junto, mantendo a proporção da
receita**, confirmado pelo usuário: agora é **2 → 3 → 5 → 7 → 10** Compostos/h (nível 1 a 5), era
30 → 45 → 68 → 101 → 152. Ver **D-83** em `docs/decisoes.md` para o raciocínio completo.

Sem migration — só `production.json` (reseedado via `BuildingSpecSeeder`) e lógica de domínio
(`ColonyTick`, reaproveitando o `converter()` que a Destilaria já usava). **552 testes PHP** — 551
verdes, a mesma falha pré-existente e não relacionada de Missões. Não mexe em nada visível ao e2e
(nenhum arquivo constrói Oficina/Refinaria Química ou verifica esses dois recursos).

**Publicado** no commit `291fed3`. **Um passo à mão** no `fertwaysbd`: `artisan db:seed
--class=BuildingSpecSeeder --force` (mesmo do D-82, idempotente) — sem ele, `production.json`
muda mas o `building_specs` semeado continua com a taxa velha. Conferido por leitura depois: a
Oficina sem `ligas_metalicas` no JSON, e a Refinaria Química com 2/3/5/7/10.

## Teto de zonas, upgrade de nível e manutenção territorial (D-84) — **mesclado e no ar** (2026-07-15), commit `73f46dc`

Fecha as duas últimas lacunas do D-52 ("teto de zonas por jogador" e "upgrade de zona fica para
uma fatia posterior") e liga pela primeira vez a manutenção territorial do §27.12, que nunca tinha
cobrado nada de nenhuma zona — nem a de nível 1.

- **Teto: 5 zonas por colônia.** Arbitrado — o GDD não publica número. `OcuparZonaNeutra` recusa a
  sexta.
- **Upgrade de 1 a 5**, por `POST /zones/{id}/upgrade`: debita Metal Bruto + Fert$ (curva 1,65×
  sobre a base do Posto, 800/300) e a diferença de Robôs Mineradores até a guarnição-alvo (também
  1,65×: 20/33/54/90/148 — dentro do "20 a 150+" do §16.1) direto da colônia, **não do canteiro**
  (decisão: o upgrade é o Posto crescendo, o mesmo ato da ocupação, não uma obra de estrutura). O
  nível sobe no tick seguinte, depois de `horasDeUpgrade()` (curva 1,5×: 12h/18h/27h/41h).
- **Manutenção territorial ativada de verdade**: custo diário por nível (o publicado no §27.12,
  verbatim), cobrado da colônia no tick. Sem pagar 24h, a Força Defensiva decai 5%/dia
  (`Forcas::defensiva()`); sem pagar 72h, a zona é **abandonada automaticamente** — reset completo
  ao estado nunca-ocupado (não um congelamento: preservar níveis abriria lavagem de zona entre
  contas do mesmo jogador, a mesma brecha que o D-73 fechou para o Furgão). Os números de
  decaimento/abandono são os já corrigidos no D-52 (5%/DIA, 72h — não o "5%/hora, 48h" do texto cru
  do §27.12, que a precedência da seção 0 substitui).
- Quem já tem zona ganha **24h de trégua** no backfill da migration antes da primeira cobrança.

Ver o **D-84** completo em `docs/decisoes.md` para as curvas, o raciocínio do abandono (sem
precedente no código — conquista por guerra sempre TRANSFERE, nunca esvazia) e a lista de arquivos.

**Validado antes de abrir o PR:** 562 testes (SQLite e um `mariadb:10.5` efêmero em container
local — não o MySQL compartilhado de produção, [[fertways-nao-escrever-em-producao-para-testar]]),
round-trip de migrations limpo nos dois, `npm run lint`/`build` OK, e2e completo (8 arquivos, 252
asserções, incluindo o fluxo de ocupar zona que este PR toca). Um teste novo,
`tests/Feature/UpgradeDeZonaTest.php` (10 casos), cobre teto, custo/guarnição do upgrade, conclusão
no tick, cobrança bem e mal-sucedida, decaimento, abandono aos 72h e a integração com `Forcas`.

⚠️ **Impacto econômico real, para quem já tem zona ocupada em produção**: a manutenção passa a
cobrar de verdade — 50 Biomassa + 30 Energia/dia no nível 1, subindo com o nível. Não é um número
pequeno para colônias que nunca esperaram esse custo. Vale avisar antes de mesclar, não só
documentar depois.

Mesclado (squash, PR #3) em `main` e publicado por `sudo ./tools/deploy.sh`, junto com o D-88
(veja abaixo) na mesma sessão — o usuário pediu explicitamente para publicar as duas mudanças
depois de prontas. A migration rodou em produção sem erro; quem já tinha zona ocupada entrou na
trégua de 24h do backfill antes da primeira cobrança de manutenção.

**Para retomar isto:** não há mais pendência — é o estado normal do jogo agora. Zonas cobram
manutenção de verdade, têm teto de 5 por colônia, e podem ser upadas de nível.

## CI básica no GitHub Actions — **mesclada e no ar** (2026-07-15), commit `9915bef`

Existe `.github/workflows/ci.yml`, três jobs (`Backend / SQLite`, `Backend / MariaDB`,
`Frontend / Lint e build`), documentados em `docs/ci.md`. PR #1
(`https://github.com/fbfert/fertways/pull/1`) foi mesclado (squash) em `main` e publicado por
`sudo ./tools/deploy.sh`.

**O primeiro run real encontrou três bugs de produção de verdade** — o job MariaDB nunca tinha
chegado a rodar até o fim antes disto (morria antes, ver abaixo), então a suíte nunca havia
executado contra o banco que a produção de fato usa. Todos os quatro, corrigidos no mesmo commit
que fechou o PR (`18aa7d4`, dentro do #1):

1. **`migrate:rollback` completo quebrava** em
   `2026_07_12_120000_ministerio_dos_transportes.php`: o `down()` apagava a frota do governo via
   `Vehicle::delete()` (Eloquent), que grava em `deleted_at`; numa rollback completa essa coluna
   já tinha sido derrubada, porque a migration que deu `SoftDeletes` a `Vehicle` é posterior e
   desfaz primeiro (ordem inversa). Só afetava rollback do zero — nunca aconteceu em produção,
   que nunca reverte migrations. Trocado para `DB::table('vehicles')`, cru.
2. **`GET /recipes` estava fora do ar em produção** (`BuildingController::recipes()`):
   `orderBy('id')` num `component_recipes` cuja chave é `code`, sem coluna `id` nenhuma. SQLite
   tolera a coluna inexistente nesta consulta; MariaDB — o banco real — não. Como a produção
   sempre foi MariaDB, **este endpoint vinha devolvendo 500 desde que existe**, e ninguém tinha
   notado porque nada no jogo chama `/recipes` num caminho óbvio de smoke test.
3. **A ficha do jogador no painel de admin também estava quebrada em produção**
   (`PainelController::jogador()`): a lista de denúncias consultava colunas `reporter_id`/
   `accused_id`, que nunca existiram — o schema real é `reporter_colony_id`/`accused_colony_id`
   (chave da colônia, não do usuário). Mesma causa: SQLite deixa passar, MariaDB recusa.
   `/admin/jogadores/{id}` vinha dando 500 no operador desde o D-44 ou antes.
4. Dois testes eram frágeis contra MariaDB por diferença real de motor, não por bug de app:
   `AdminPortaTest` assumia `admin:1` (o `auto_increment` do MariaDB não recua com o rollback do
   `RefreshDatabase` entre testes, o do SQLite recua) e `MissoesTest` chamava
   `Janela::proximoDia()` sem congelar o relógio (perto da virada real de terça-noite/quarta-07h
   o "dia seguinte" podia cair numa semana nova). Os dois passaram a pinar o estado real em vez
   de assumi-lo.

**Validado antes de mesclar:** os quatro achados foram reproduzidos e corrigidos contra um
`mariadb:10.5` **efêmero, em container local** (não o MySQL compartilhado de produção — ver
[[fertways-nao-escrever-em-producao-para-testar]]) — round-trip completo
`migrate:fresh`/`migrate:rollback`/`migrate` limpo, e as 552 tentativas de teste passando tanto
contra esse MariaDB quanto contra SQLite. Depois de mesclado, os três jobs do Actions rodaram
verdes no PR (`https://github.com/fbfert/fertways/actions/runs/29435326666`).

**Para retomar isto:** não há mais pendência — é o estado normal do repositório agora. Qualquer
push para `main` ou PR contra ela roda os três jobs automaticamente.

## O rótulo COMPRA/VENDE da vitrine do Mercado Central — **no ar** (2026-07-15), commit `9f4f149`

Queixa do usuário: "quero comprar um recurso ofertado, e se eu não tenho nada no depósito central
não me deixa comprar". A regra de negócio nunca esteve errada — comprar sempre exigiu só Fert$ e
espaço no depósito para *receber* (nunca saldo do recurso); só vender exige que o lote já esteja
lá (`ExecutarOrdem::comprarDaOferta` vs `venderParaOferta`, confirmado por leitura de todo o
`Domain/Market` e por um teste já existente cobrindo compra com saldo zero). O bug era só na
tela: `LinhaDaVitrine`, em `Mercado.tsx`, pintava o rótulo grande "COMPRA" na mesma cor
(`text-rust`) que a interface inteira usa para botões clicáveis — então "COMPRA" (dizendo que
**outro** colono quer comprar) lia como um convite para *você* comprar, quando clicar naquela
linha na verdade te faz **vender**, entregando do seu depósito. Daí o erro que o usuário via ser
exatamente a recusa de venda (`saldo_mercado_insuficiente`).

**A correção:** o rótulo VENDE/COMPRA virou neutro (`text-ink`, não é mais um falso botão), e uma
linha nova, em primeira pessoa e na cor de ação, diz o que o clique realmente faz: "Você compra:
paga Fert$ e recebe no seu depósito" ou "Você vende: entrega do seu depósito e recebe Fert$".
Textos e classes que o e2e ancora (`/VENDE/`, `/^Comprar$/` em `frontend/e2e/mercado.e2e.mjs`)
foram preservados sem mudança.

**Validado:** lint, `npm run build`, e o e2e completo (8 arquivos, incluindo o cenário exato de
comprar do zero na vitrine) — todos verdes antes de mesclar.

## Sair vira ícone, a lateral troca de ordem, o card da zona ganha corpo (D-88) — **mesclado e no ar** (2026-07-15), commit `9acaccf`

Pedido de visual do usuário, três partes, empilhado sobre o D-84 (precisa dos campos de nível/
upgrade/manutenção que aquele PR introduz):

1. **Sair** saiu do canto inferior esquerdo (texto solto) e virou ícone ao lado do perfil, no
   canto superior direito — com confirmação "Sim/Não" antes de sair de verdade (o mesmo padrão
   que `Transportes.tsx` já usa para sucatear um veículo; **não** `window.confirm()`, que não
   existe em nenhum lugar do projeto).
2. **A lateral inverteu a ordem**: Fila de Construção primeiro, Zonas Neutras depois — a Fila é o
   que o colono acabou de mexer, e antes as Zonas (quando existiam) empurravam a Fila para baixo.
3. **O card de cada zona, na lateral**, ganhou nível/upgrade em andamento, guarnição e defesa,
   manutenção em atraso e o que já chegou ao canteiro — as quatro opções que o usuário escolheu
   quando perguntado, tudo que antes só aparecia abrindo a zona.

Ver o **D-88** completo em `docs/decisoes.md`.

**Validado antes de abrir o PR:** 562 testes de backend, `npx tsc -b`/lint/build limpos, e2e
completo (8 arquivos) — incluindo o fluxo de logout reescrito (`mercado.e2e.mjs`) clicando
`[data-sair]` e depois `[data-confirmar-sair]`.

Mesclado (squash, PR #8, rebaseado sobre `main` depois que o D-84/#3 entrou) e publicado junto com
o D-84 no mesmo `sudo ./tools/deploy.sh`, a pedido explícito do usuário.

**Para retomar isto:** não há mais pendência — é o estado normal da tela da colônia agora.

## O GDD v36 atualizado até o D-92 (docs/gdd-v36-atualizacao) — **mesclado e no ar** (2026-07-15/16), commit `8e8088a`

Pedido do usuário: um GDD novo, "de acordo com tudo que já construímos". Já existia a
infraestrutura certa para isto (D-62): `tools/gdd-v36.php` é um **gerador**, não um documento
escrito à mão — as tabelas numéricas vêm ao vivo de `building_specs`/`resource_types`. Só que
não era regenerado desde o D-79; 24 decisões de conteúdo real (guerra, Drone, Marco, Missões,
Chat, a Capital em áreas, os dois mercados) ficaram de fora ou continuavam como lacuna aberta
mesmo já implementadas. Uma primeira passada reescreveu até o D-83.

**2026-07-16 — reescrito de novo, até o D-92**, agora que D-84 a D-92 mescladaram em `main` ao
longo desta sessão. Seções novas ou reescritas:

- **§3.2.1 O kit inicial** (D-85/D-92): vira uma tabela lida ao vivo de
  `KitInicial::recursos()`/`fertMicro()`/`frota()` — Fert$, os 26 recursos e a frota, com o aviso
  do "muro de progressão" (Nióbio/Quartzo) e a nota de que é editável pelo admin sem deploy.
- **§5.1**: a linha de cada veículo agora diz quantos vêm no kit, ao vivo.
- **§6.2.1 O Governo vende, na mesma vitrine** (D-87): a semântica "quanto deve estar à venda
  AGORA", e por que não existe uma colônia sintética do Governo.
- **§6.3.1 O Pátio Logístico**: a tarifa passa a ser lida de `Patio::TARIFA_MICRO_HORA` (não mais
  digitada), e ganhou duas linhas — chamar de volta vazio e o aviso da Capital (D-91).
- **§8.6/§8.7**: D-84 (teto/upgrade/manutenção) perde o rótulo "PR aberto"; §8.7 é nova, sobre a
  zona em cinco abas e o Histórico (D-86) — só o Histórico é conteúdo de jogo de verdade, o resto
  é reorganização de tela.
- **§9.3 nova**: "o que o operador arbitra sem deploy" — reúne Marco, Transportes, kit inicial
  (D-92) e o Governo no Mercado (D-87) num só lugar, porque cada vez mais números do jogo viraram
  configuração de banco em vez de constante em código.
- **A nota da seção 0** foi reescrita: só o que é de fato interface (D-88, o fix da Siderúrgica)
  fica de fora do documento — o resto (D-84 a D-92) já está integrado nas seções acima, sem mais
  nenhuma decisão "pronta mas não mesclada" pendente.
- **§10**: a lista de "fechados desde a última revisão" ganhou as seis referências às seções
  novas/reescritas acima.

Regenerado em `/home/fertways/FERTWAYS_GDD_v36_CONSOLIDADO.html` (fora do git, como sempre foi —
é documento para ler, não para o git). Validado: `tests/Gdd/*` (23 testes) continuam batendo com
o que o jogo lê; suíte de backend inteira (588 testes) — nenhum arquivo além de
`tools/gdd-v36.php` mudou, então nada mais tinha por que quebrar.

Mesclado (squash, PR #6) em `main`. Sem deploy: o `.html` gerado (fora do git) já estava
atualizado desde a regeneração, e nenhum código do jogo mudou.

**Para retomar isto:** não há mais pendência — o v36 reflete tudo que está em `main` até o D-92.

## O Governo vende no Mercado Central (feat/mercado-do-governo) — **mesclado e no ar** (2026-07-15), commit `c92e218`

Pedido do usuário: a aba Economia do painel de admin virou quatro sub-abas (Finanças, Tesouro,
Enviar Recursos, **Mercado** — nova), e nesta última o Governo lista recursos do Tesouro à
venda no Mercado Central, na mesma vitrine dos colonos.

**A lacuna que isso expôs:** `market_orders.colony_id` era obrigatório — o Mercado não sabia
lidar com um vendedor que não fosse uma colônia de verdade. Fechada com `colony_id` nulo = o
Governo, mesmo padrão que a frota pública já usa (`vehicles.colony_id` nulo, D-60) — evita
inventar uma colônia sintética que apareceria (por engano) no mapa, no diretório e como alvo de
guerra.

**A semântica do formulário:** o número digitado por recurso é quanto deve estar **disponível
agora** — não soma ao que já está anunciado. Subir reserva mais do Tesouro; descer devolve;
zerar cancela. Trava no saldo real do Tesouro (não deixa prometer o que não tem). Um card na
visão geral avisa quando algum dos 26 recursos está sem oferta ativa — inclusive os que nunca
foram anunciados.

Ver **D-87** em `docs/decisoes.md` para o raciocínio completo (inclusive como o dinheiro da
venda do Governo é creditado sem duplicar líquido/taxa).

**Validado antes de abrir o PR:** 560 testes de backend (SQLite e MariaDB 10.5 efêmero em
container local), round-trip de migrations limpo nos dois, lint, build e e2e completo do
frontend (o tipo `OfertaGlobal` mudou, então revalidei o fluxo do Mercado inteiro). 8 casos
novos em `MercadoDoGovernoTest.php`.

Mesclado (squash, PR #7) em `main` e publicado por `sudo ./tools/deploy.sh`, a pedido do
usuário. O branch havia divergido de `main` (D-84/D-88 entraram primeiro nesta sessão) — foi
rebaseado antes de mesclar; único conflito foi de prosa em `docs/RETOMAR.md`/`docs/decisoes.md`
(duas seções novas concorrendo pelo mesmo ponto de inserção), sem tocar código. Revalidado
depois do rebase: 570 testes, CI verde nos três jobs, e2e completo (8 arquivos) — tudo antes de
publicar.

**Para retomar isto:** não há mais pendência — é o estado normal do painel de admin agora.

## O painel da Indústria Siderúrgica dizia "Produz por hora: Metal Bruto" — **mesclado e no ar** (2026-07-16), commit `fb467b2`

Queixa do usuário: a Indústria Siderúrgica parecia estar **produzindo** Metal Bruto, quando ela
deveria **consumi-lo** para produzir Ligas Metálicas e os cinco minerais eletrônicos (D-82). A
regra de negócio nunca esteve errada — `TickColoniesTest`/`ZonaLugarTest` já cobriam, e continuam
cobrindo, que o tick debita Metal Bruto e credita as seis saídas em lotes de 1000 — o bug era só
na tela, e só nela: `ColonyTick.php` reaproveita a chave `metal_bruto` de `producao_hora_json`
(a mesma da Mina Local) para guardar a taxa de **processamento** da Siderúrgica, não uma
produção. `BuildingController::specs()` devolvia esse JSON verbatim como `producao_hora` para
qualquer construção, sem saber da diferença — e o painel (`OQueFaz` em `Hud.tsx`) lia esse campo e
escrevia "Produz por hora: Metal Bruto: 15", o oposto do que a nota logo abaixo, no mesmo painel,
já dizia em prosa.

**A correção:** `BuildingController::specs()` agora zera `producao_hora` e move o número para um
campo novo, `insumo_hora`, quando o tipo é `industria_siderurgica`. `OQueFaz` ganha uma seção
"Processa por hora" para `insumo_hora`, ao lado (não no lugar) de "Produz por hora" — as duas
podem coexistir no futuro, se algum dia uma construção produzir E consumir insumo ao mesmo tempo.

**Validado:** teste novo, `SlotsDaColoniaTest::test_a_siderurgica_nao_aparece_como_produtora_de_metal_bruto`,
crava `producao_hora` nulo e `insumo_hora.metal_bruto = 15` no nível 1. Suíte completa (563
testes), `npx tsc -b`/lint/build e e2e completo — todos verdes antes de abrir o PR. Nenhum teste
existente (backend ou e2e) assumia o texto antigo — o painel da zona (onde a Siderúrgica também
pode existir) usa só a `nota` estática, nunca `producao_hora`, então não foi tocado.

Mesclado (squash, PR #9) em `main` e publicado por `sudo ./tools/deploy.sh`, a pedido do
usuário. Rebaseado sobre `main` antes de mesclar (conflito de prosa em `docs/RETOMAR.md`, sem
tocar código) e revalidado depois: 571 testes, CI verde, e2e completo (8 arquivos).

**Para retomar isto:** não há mais pendência — o painel da Siderúrgica mostra "Processa por
hora" para o Metal Bruto, não mais "Produz".

## Chamar o veículo de volta do Pátio, vazio, e a Capital avisa pelo rádio (D-91) — **mesclado e no ar** (2026-07-16), commit `d883151`

Dois pedidos do usuário sobre o Pátio da Capital (D-65): não havia como chamar de volta um
veículo parado lá sem carga, e ninguém avisava que ele estava ali, comendo Fert$ hora a hora.

- **Volta vazia**: cobra energia como qualquer despacho, mas não exige Confiança Comercial — é
  resgatar o próprio veículo, não usar o Mercado (mesma lógica do reboque automático). Só o caso
  exato "vazio, para a própria colônia" ganha a isenção.
- **Aviso da Capital**: uma conta de sistema de verdade (`capital@fertways.sistema`, nickname
  "Capital", reservada por migration), mandando mensagem privada de verdade pelo chat — ao
  estacionar, e a cada 24h que o veículo continuar lá.

Ver **D-91** completo em `docs/decisoes.md`.

**Validado antes de abrir o PR:** 579 testes (SQLite e MariaDB 10.5 efêmero em container local),
round-trip de migrations limpo nos dois, lint, build, e2e completo (regressão — o e2e não roda o
tick, então o fluxo novo só é observável pelos testes de domínio: `PatioDaCapitalTest` +3 casos,
`AvisoDoPatioTest` novo com 5 casos).

Mesclado (squash, PR #10) em `main` e publicado por `sudo ./tools/deploy.sh`, junto com a coluna
Preço Base do admin (PR #11), a pedido do usuário.

**Para retomar isto:** não há mais pendência — é o estado normal do Pátio agora.

## A aba Mercado da Economia ganha a coluna "Preço Base" — **mesclado e no ar** (2026-07-16), commit `fefbff9`

Pedido do usuário: em `/central/admin/economia?aba=mercado`, entre "No Tesouro" e "À venda
agora", uma coluna com o preço de referência do §06 (`resource_types.preco_base_micro`) — o
mesmo que a Secretaria de Finanças já publica para o jogador. Sem ela, o operador não tinha como
julgar se o preço que está anunciando pelo Governo estava caro ou barato perto da base.

**Validado:** teste novo em `AdminPainel2Test.php` (ordem das colunas + valor formatado), 572
testes de backend. Sem migration nem mudança de frontend — só o controller e a view Blade.

Ajuste do usuário logo depois: a coluna nasceu com 2 casas decimais (o padrão do resto da tela),
e precisava de 4 — a mesma precisão do campo "Preço/un." ao lado. Corrigido e publicado junto
(PR #12, commit `f3d47ab`).

**Para retomar isto:** não há mais pendência.

## A zona vira cinco abas, o Canteiro pergunta a obra, e nasce o Histórico (D-86) — **mesclado e no ar** (2026-07-16), commit `80ae7e5`

Pedidos pontuais do usuário:

1. **Clicar no nome do dono (colônia ou zona), no mapa, abre a ficha do jogador — e é DENTRO dela
   que mora o botão "Conversar" novo**, que leva ao chat privado. A ponte entre o Mapa (rota
   própria) e o Chat (estado local de `App.tsx`, só existe dentro de `/`) é um `conversaAlvo`
   levantado até `App.tsx`.
2. **`Zona.tsx` virou cinco abas**: Zona Neutra (identidade + planta + upgrade de nível),
   Depósito, Canteiro de obras, Guarnição, Histórico (novo).
3. **O Canteiro foi redesenhado**: perguntava sempre os mesmos três recursos fixos e usava sempre
   o primeiro veículo ocioso, em silêncio — o colono não tinha como saber o que a obra realmente
   pedia. Agora pergunta **a obra primeiro**, e só então mostra o que falta enviar, com veículo
   escolhível quando há mais de um ocioso.
4. **O Depósito não precisou de mecânica nova** — já era genérico (bruto + refinado + minerais da
   Siderúrgica, sem nome hardcoded); só ganhou aba própria, pronto para quando uma zona produzir
   mais recursos.
5. **A Guarnição ganhou o Reforço** (`Domain\Guerra\Reforcar`, D-70) — antes só existia atrelado a
   um combate ativo, no Quartel; agora dá para reforçar uma zona em paz, direto da aba dela.
6. **Histórico é feature nova**: tabela `zone_events` (posse — ocupação/abandono/conquista) +
   `Ledger` filtrado por `zona:{id}:%` (financeiro) + `Combat` da zona (guerra), mesclados numa
   linha do tempo. Só o dono vê.

Ver **D-86** em `docs/decisoes.md` para o raciocínio completo de cada item.

**Validado antes de abrir o PR:** suíte de backend inteira (566 testes, incluindo
`HistoricoDaZonaTest` novo), lint, build, e2e completo — `zonas.e2e.mjs` cobre as cinco abas e o
Histórico mostrando a ocupação recém-feita; `chat.e2e.mjs` cobre o fluxo Mapa → ficha do jogador
→ Conversar → Chat já na privada certa.

Mesclado (squash, PR #5, rebaseado sobre `main` — a base original, `feat/zona-teto-upgrade-
manutencao`, já tinha ido para `main` como D-84) e publicado por `sudo ./tools/deploy.sh`, a
pedido do usuário.

**Para retomar isto:** não há mais pendência — é o estado normal da tela da zona agora.

## O kit inicial vira uma tabela só, e depois vira tela de admin (D-85 + D-92) — **mesclado e no ar** (2026-07-15/16), commit `cdf8841`

Pedido do usuário (D-85): uma tabela única, Fert$ + um valor fixo para os 26 recursos do catálogo,
substituindo as **três fontes separadas** que a fundação juntava até aqui — os 50 Fert$ do GDD, os
raros calculados do "muro de progressão" (D-17) e o kit fixo de cinco recursos do D-57.

No dia seguinte, o usuário pediu para não precisar mais editar código para mudar esses números
(D-92): a `const RECURSOS` virou `kit_inicial_recursos`/`kit_inicial_settings` no banco, editáveis
em `/central/admin` → aba Operação → **Kit inicial**. A frota entrou no kit pela primeira vez —
antes o Furgão era hardcoded em `CreateColony`; agora é só mais uma quantidade arbitrável (hoje 1
Furgão, 0 Caminhões, igual ao que já existia).

⚠️ **Duas coisas para saber, que seguem valendo em produção (do D-85):**

1. **O "muro de progressão" quebra de propósito.** O kit dá 0 Nióbio Alienígena e 2 Quartzo
   Piezoelétrico — nenhum dos dois é produzível no jogo. Torre de Defesa + Quartel (juntas, 5
   Nióbio) e uma das duas entre Refinaria Química/Antena de Comunicação (juntas, 3 Quartzo) ficam
   **trancadas** para quem fundou depois do deploy, até comprar do governo. **Confirmado com o
   usuário — não é lacuna, é decisão.** A tela do admin AVISA (não trava) se alguém subir esses
   dois números além do limiar que reabre o muro — mas o aviso é do D-92, a decisão de trancar é
   do D-85.
2. **Só vale para quem funda depois de cada mudança.** Colônias que já existem não são tocadas —
   ao contrário do D-57, não há comando de backfill, e o D-92 manteve essa regra também para as
   edições pelo painel.

Ver **D-85** e **D-92** completos em `docs/decisoes.md`.

**Validado antes de abrir o PR:** 588 testes (SQLite e MariaDB 10.5 efêmero em container local),
round-trip de migrations limpo nos dois, lint, build, e2e completo (uma falha isolada de
rede/timing na primeira corrida, confirmada flake — verde na repetição, sem relação com esta
mudança).

Mesclado (squash, PR #4) em `main` e publicado por `sudo ./tools/deploy.sh`, a pedido do usuário.

**Para retomar isto:** não há mais pendência — `/central/admin` → Operação → Kit inicial é o
estado normal do painel agora.

**Para retomar isto:** olhe o PR no GitHub — se a CI vier verde, está pronto para mesclar e
publicar.

## Seis ajustes no HUD e no Mapa, o extrato bancário, e Bugs/Melhorias (D-93/D-94/D-95) — **mesclados e no ar** (2026-07-16)

Três PRs pequenos, pedidos na mesma leva ("Correções Visuais e Funcionais"), mesclados e
publicados juntos.

**D-93, commit `4c8416a`** — Sair do mesmo tamanho do Perfil (o wrapper do dropdown de
confirmação não estava esticando o botão dentro dele); card do Marco ao lado do Fert$; atalho
para a Capital ao lado do Mapa; busca do Chat casando nome de colônia, não só nickname;
"Conversar" direto no painel da colônia do Mapa; `FormularioDeCarga` do Mercado com 3 linhas por
padrão. Um bug de e2e no caminho: o botão "Conversar" novo usava `data-conversar`, a mesma marca
que `InfoJogador.tsx` já usava — os dois convivem na tela ao mesmo tempo, e o seletor virou
ambíguo. Renomeado para `data-conversar-direto`.

**D-94, commit `ec78207`** — o extrato bancário: clicar no valor/palavra "Fert$" do card do HUD
abre um popup com os lançamentos em Fert$ da colônia (`resource_type IS NULL` no ledger, não o
ledger inteiro). `GET /profile/extrato`, paginado, mais recente primeiro.

**D-95, commit `cc9a035`** — Bugs/Melhorias: formulário ao lado do Chat (tipo, assunto,
mensagem), dados do jogador/colônia/e-mail anexados pelo servidor. Aba nova em
`/central/admin` → Bugs/Melhorias: lista com filtros, marcar lida/não lida, responder (avisa o
jogador pelo rádio, remetente "Capital" — reusa o D-91), marcar como FEITO. Card na Visão Geral
quando há mensagem não lida. Um bug pego pelos testes: a migration criou `feedbacks` (plural),
mas `Feedback` é um dos substantivos que o Eloquent NÃO pluraliza — o model procurava `feedback`
(singular). Corrigido antes de qualquer teste passar.

Ver **D-93**, **D-94** e **D-95** completos em `docs/decisoes.md`.

**Validado antes de cada merge:** suíte de backend completa (588 → 592 → 597 → 601 testes, ao
longo dos três), round-trip de migrations limpo em SQLite e MariaDB 10.5 efêmero (container
local), `npx tsc -b`/lint/build limpos, e2e completo (8 arquivos) em cada um — com rebase e
revalidação completa a cada merge anterior, porque os três tocavam `App.tsx`/`docs/decisoes.md`
e cada merge exigia resolver o próximo contra o `main` que acabara de mudar.

**Para retomar isto:** não há mais pendência — os seis ajustes, o extrato e o Bugs/Melhorias são
o estado normal do jogo agora.

## Economia, Transportes, Visão Geral e Missões ganham abas; o Tesouro ganha extrato (D-96/D-97/D-98/D-99) — **mesclados e no ar** (2026-07-16), commit `517ba8e`

Cinco itens pedidos numa leva só ("Mais funcionalidades"), com uma instrução explícita do
usuário: construir tudo sem perguntas, seguindo minhas próprias recomendações, e só publicar
(mesclar + `deploy.sh`) quando os cinco estivessem prontos — diferente da leva anterior
(D-93/94/95), que publicou incrementalmente. Cada item ganhou seu próprio branch e PR; os quatro
PRs (#16–#19) foram mesclados em sequência só depois que os cinco itens estavam prontos, e o
deploy e este registro vieram por último.

**D-96, PR #16** — Economia ganha três abas: Ofertas Globais (o livro do Mercado Central
inteiro — todo colono e o Governo — paginado/filtrado/buscável), Extrato do Governo e Extrato
Colonos. O Tesouro nunca tinha histórico, só saldo (`treasury_holdings`) — uma tabela nova,
`treasury_ledger`, resolve isso sem tocar na constraint `NOT NULL` de `ledger.colony_id` (que
levaria a alterar uma tabela "regra de ouro" já em produção, e o Laravel nem tem `doctrine/dbal`
instalado para o `->nullable()->change()`). `Tesouro.php` ganha um `lancarTesouro()` privado, e
os 12 pontos de chamada que mexem no caixa passam a anotar um `$ref` de contexto.

**D-97, PR #17** — Transportes ganha três abas (Ministério dos Transportes, Garagem do Governo,
Frota do Planeta), e a Frota ganha busca por Dono e ordenação por clique no cabeçalho (Placa,
Tipo, Dono, Situação, Conservação, Teto, Manut., Uso) — via `leftJoin` com `colonies`, porque
"dono" só existe do lado de lá e `orderBy` num relacionamento Eloquent não alcança SQL.

**D-98, PR #18** — Visão Geral ganha quatro abas: Panorama (números de topo + os dois cards de
alerta), Últimos atos, Colônias, Logística. Escolha própria do agrupamento — o pedido não
especificou.

**D-99, PR #19** — Missões ganha três abas: Missões Catálogo (nova — visão geral por molde:
sorteada/concluída/rejeitada/ativa vigente/ativa vencida, o que "sorteada: N×" sozinho não
dizia), Criar um Molde (com a categoria "eventuais" nova) e O Baralho (com sub-abas por
categoria). A lista de categorias, que vivia solta em três lugares (a tela, a validação, e
nenhum enum no banco), virou `MissionTemplate::CATEGORIAS`, fonte única.

**Item 4 (link de mensagem privada no card de busca do Chat) não precisou de código** —
investigação confirmou que `Chat.tsx`/`Mapa.tsx` já passavam `aoConversar` para `InfoJogador`,
que já renderizava o botão. O pedido já estava implementado.

Ver **D-96** a **D-99** completos em `docs/decisoes.md`.

**Validado antes de cada merge:** suíte de backend completa (601 → 604/605/607/611 testes,
conforme o branch), migração do D-96 testada num MariaDB 11 efêmero em container local (up +
rollback limpos — os outros três não mudam schema), `npx tsc -b`/lint/build limpos, e2e completo
(8 arquivos) rodado uma vez no `main` já com os quatro branches mesclados, antes do deploy.

**Para retomar isto:** não há mais pendência — os cinco itens são o estado normal do painel
agora.

## Ponte D-100 a D-141 (2026-07-16 a 2026-07-21) — resumo, detalhe em `docs/decisoes.md`

Esta página parou de ser atualizada decisão a decisão desde o D-99. Tudo abaixo está **no ar em
produção**, cada um com sua entrada completa em `docs/decisoes.md` (arbitragens, testes,
validação) — não repito aqui, só aponto:

D-100 (4 ações novas de missão) · D-101 (placa+apelido da Frota) · D-102 (rádio avisa missão
concluída) · D-103 (HUD mobile-first) · D-104 (chat Região/Núcleo sai; aba acende sozinha) ·
D-105 (navegação global, header/barra em todo o site) · D-106 (Depósito Local, 22º slot) ·
D-107 (lote canônico de 68 estruturas de arte) · D-108 (Gestão de Construções admin-editável) ·
D-109 (Furgão ganha fábrica) · D-110 (ícones de recurso) · D-111 (fila admin-editável) ·
D-112 (manutenção de estruturas) · D-113 (Subsídios) · **D-114 a D-116 (Federação — núcleo,
Fatia 2, Fatia 3: cargos, fundo, chat, apoio no cerco, missões cooperativas)** · D-117 (piso
anti-farming 5 F$) · D-118 (Módulo Operacional, §28.10) · D-119 (limite antimonopólio da
Federação) · D-120 (desconto de tributo entre aliados, 50%) · D-121 (confirmação ao sair da
federação) · D-122 a D-124 (teto do canteiro da zona, e o bug que ele causou em produção) ·
D-125 a D-127 (fila de obras na ficha e no Mapa; Histórico da zona corrigido) ·
**D-128 (Ranking de Guerras, §27.13)** · **D-129 (Leilões — sistema desenhado do zero, sem base
no GDD)** · **D-130 (Cargos Públicos — Repórter, Fiscal de Mercado, Auxiliar de Tesouro)** ·
D-131 (Tanque de Combustível trava produção no teto) · D-132/D-133 (Loja de Peças da Endurance v1
— 8 seções × 4 camadas fixas, CRUD admin) · D-134 (pendência: a v1 da Loja não diferenciava as
camadas o bastante) · **D-135 (a Loja refeita: catálogo dinâmico, efeitos que mexem no motor de
verdade)** · **D-136 (Leilões, D-129, vendem item da Endurance — Fase 2 do D-135)** ·
D-137 (aba Manual dos Benefícios no painel da Endurance) · **D-138 (demolição de estrutura de
zona neutra — fecha o achado 7 do D-122/D-123, nunca decidido antes)** · D-139 (e2e da Endurance
estava quebrado desde o D-135, achado ao verificar o D-138 e corrigido no mesmo ciclo) ·
**D-140 (as missões narrativas da Endurance existem — 4 capítulos encadeados, o primeiro
encadeamento do motor de Missões, D-78)** · **D-141 (GDD v38 — segunda regeneração do v36,
D-101 a D-140, com Federação e a Endurance como seções novas de nível 1)**.

**O GDD agora é a v38** (`docs/FERTWAYS_GDD_v38_CONSOLIDADO.html`, gerador em
`tools/gdd-v38.php`). O v36 fica intocado como histórico, como o v35 ficou quando o v36 nasceu.

A partir do D-114, o usuário deu a instrução padrão: **avançar pelos próximos itens do GDD sem
parar para perguntar, decidir e seguir** — é por isso que boa parte da lista acima foi escolhida
e arbitrada sozinha, com o julgamento sempre registrado em `docs/decisoes.md`.

## A reconstrução da Loja de Peças da Endurance terminou — Fase 1 e Fase 2 no ar (2026-07-20)

A pendência do D-134 está resolvida por completo: **D-135** (catálogo dinâmico, efeitos que mexem
no motor de verdade — produção, veículo, drone, tributo — admin CRUD de 8 abas, frontend do
mapa/loja) e **D-136** (Leilões, D-129, vendem item da Endurance, não só recurso) — as duas fases do
plano aprovado, cada uma com seu próprio ciclo de entrega completo e no ar em produção. Ver as duas
entradas em `docs/decisoes.md` para o desenho completo, arbitragens e testes. Nenhuma pendência
conhecida deste trabalho — o que sobrar de ideia (ex.: as "Direções possíveis" que o D-134 chegou a
listar antes de a reconstrução decidir por um caminho diferente) já não se aplica.

## O trabalho anterior: zonas neutras + Drone (D-52)

Leia **D-52**. Sequência decidida: Fatia 1 = o núcleo (ocupar/extrair/retirar); Fatia 2 = a guerra
(§27); Fatia 3 = o Drone. O mapa (pré-requisito) e a **Fatia 1 já estão no ar** (2026-07-10).

**Fatia 1 — no ar (2026-07-10).** Arbitragens (D-52): mineral por distrito (NE Metal Bruto, SE Água,
SO Oxigênio, NO Biomassa), ocupação pesada (Posto de Comando 800 MB + 300 F$ + 8h + 20 Robôs
Mineradores + 12h), extração 100/h. Backend (`ZonasNeutras`, migration que estende `neutral_zones`,
`NeutralZoneSeeder`, `OcuparZonaNeutra`, `ExtrairZonasNeutras` no tick, `retirarDeZona`,
`NeutralZoneController`) e frontend (o `Mapa.tsx` desenha as 120 zonas, tem **zoom (+/−) e centralizar
na colônia**, e o painel de zona ocupa e despacha a retirada). **197 testes PHP + 6 e2e verdes** (novo
`zonas.e2e.mjs`), conferidos antes do deploy. O `deploy.sh` rodou a migração; o `NeutralZoneSeeder`
foi rodado à parte no `fertwaysbd` (não roda no deploy) — 120 zonas em 4 distritos, conferidas por
leitura. Ele é idempotente, então repetir é seguro se um dia houver dúvida.

**Fatia 2 — no ar** (D-66/D-70: a guerra inteira). **Fatia 3 — no ar** (D-74, 2026-07-13): o Drone
e a névoa que lhe deu ofício. **O D-52 está completo.** O gate do Marco (§05) segue **suspenso**,
por decisão do usuário.

**A Fatia 3, em uma linha:** o interior de zona ALHEIA (guarnição e depósito) virou névoa — vem
`null`, e a tela mostra "?" — e o Drone é o único olho que a atravessa. Fabrica-se na **Oficina**
(nível dela = teto do nível), guarda-se no Quartel (§21.4), e a missão parte do MAPA, mirando uma
zona: **foto** (ida e volta; registro permanente e DATADO — "vista há 3 h") ou **vigilância** (ida
simples: transmite ao vivo até a bateria publicada acabar — 24 h nível 1 … 122 h nível 5 — e volta
sozinho, deixando a última foto). Raio 6×1,5 por nível (6/9/13/20/30); 8 slots/min; não gasta
energia da colônia; NÃO entra no mercado de usados (sem âncora — seria a lavagem do D-73 de volta).
Domínio em `app/Domain/Drone/`; a missão vive nas colunas de viagem do próprio veículo (`leg`
ida→vigia→volta), sem tabela nova além de `drone_sightings` (as fotos).

## Perguntas em aberto — faça estas ao usuário ao retomar

0. **A guerra está INTEIRA — não pergunte mais sobre ela.** O D-66 fechou as oito lacunas do §27 e
   pôs o motor de combate no ar; o **D-70** deu ao defensor as duas mãos que faltavam (**reforçar**
   uma zona sob ataque, §27.5, e **romper o cerco**, §28.10) e tirou os dez parâmetros do SQL para
   uma aba do painel. Ataque, defesa, cerco, ruptura e apreensão de módulos: tudo jogável.

   **A pergunta agora é qual frente atacar.** As que estão na mesa, e ele já sabe de todas:
   - ~~O teto de revenda do Furgão~~ — **fechado no D-73** (âncora de 60 Fert$ do operador, no
     painel dos Transportes). A lavagem por Furgão superfaturado morreu; o cenário dela virou teste.
   - **As 7 imagens com duas leituras** (D-72) — as 28 do D-68 foram olhadas uma a uma: 12
     evidentes estão NO AR, 9 não têm lar no jogo (Endurance e o Cargueiro), e **7 esperam a
     escolha dele no painel** (`torre-axiom`, `aquifero-talassa`, `bastiao-vanguarda`,
     `estufa-lumen`, `centro-cerco-kraken`, `terminal-aduaneiro-vetor`, `camara-escrow-prisma` —
     as opções de cada uma estão no D-72). Há também ~10 entidades **sem imagem candidata
     nenhuma** — encomenda ao artista, não vínculo.
   - ~~O Marco do §03~~ — **no ar (D-75)**: XP por atos (ledger `xp_entries`), curva 50×N², posse
     preservada + retroativo (`fertways:marco --aplicar`), valores no painel (aba Operação). Gates
     vivos: marco 10 = Drone nível 2+; marco 20 = ocupar zona. **O Mercado NÃO tem gate** —
     contradição consciente com o §05 (o §03 promete o primeiro lote ao recém-chegado). Não reabra.
   - ~~O serviço logístico público do §07~~ — **no ar (D-76)**: a Garagem do Governo (10 caminhões
     reais, expansíveis pelo painel), frete 1 F$ + 0,02 F$/slot (operador, painel dos Transportes),
     só da doca do Mercado para a colônia. **Com tributo na chegada** (D-32 — frete não é rota de
     fuga) e sem desgaste da frota pública (leitura consciente). **Não há mais arbitragem pendente
     do GDD.**

   ⚠️ **O "segundo admin dono" SAIU da lista, e a lição de por quê vale mais do que a tarefa.** Ele
   **já existia** — a pendência estava velha. Mas conferir o caminho de emergência inteiro (D-71)
   achou três buracos de verdade: a auditoria **não via** quem entrava pelo cookie do "lembrar de
   mim" (o log estava havia meses sem uma linha de login), o `POST /admin/login` **não tinha throttle
   nenhum**, e o `fertways:admin` **não sabia criar um dono** e sabia **apagar o último**. Antes de
   fazer uma pendência velha, confira se ela ainda é verdade — e olhe o que está ao redor dela.

1. **As lacunas do D-52 que ainda travam — só as das próximas fatias.** Não invente nenhuma. Já
   arbitradas: **Fatia 1** (extração 100/h, mineral por distrito, ocupação) e **Fatia 2 inteira**
   (D-66: Nióbio, estoque protegido, bônus defensivos, custo das 4 construções de defesa, Módulo
   Operacional, as duas chances do §28.10, o cerco, e o término do combate). Ainda abertas:
   - ~~Fatia 3 (Drone)~~ — **as quatro lacunas foram arbitradas e estão no ar (D-74)**: velocidade
     8 slots/min, raio 6×1,5 por nível, persistência = os dois modos do §21.4 (foto datada /
     vigilância até a bateria publicada acabar — nenhum número inventado), fábrica = Oficina.
     **Não reabra.** E a névoa que o D-37 anotou entrou no D-74, só no interior de zona alheia.
   - ~~Custo/tempo das 6 estruturas de zona restantes~~ — **fechado (D-67 + D-79).** O D-67 já tinha
     custeado Refinaria de Campo, Estacionamento e Cemitério; o D-79 (2026-07-14) fechou as três
     últimas — Estrutura de Extração, Central de Comunicação e Plataforma de Pouso (da zona,
     `plataforma_de_pouso_da_zona` — cuidado, é homônima da construção do slot principal) —,
     **inertes de propósito**, como o Cemitério: erguem-se, custam, não fazem nada até o sistema de
     que dependem (Federação, Nave de Transporte Planetária) existir. **As 12 estruturas de zona têm
     custo e função declarados. Não reabra.**
   - ~~Teto de zonas por jogador~~ e ~~upgrade de zona~~ — **fechados no D-84** (2026-07-15): teto
     de 5 zonas por colônia, upgrade de 1 a 5 pela curva 1,65× (custo/guarnição) e 1,5× (tempo), e a
     manutenção territorial do §27.12 ativada de verdade pela primeira vez (nunca tinha cobrado nada
     de nenhuma zona). Ver "Onde o projeto está" e o D-84 em `docs/decisoes.md`. **Não reabra.**
   - **Fabricar unidade é instantâneo hoje** — o Robô Minerador, o Infiltrador e o Predador já eram
     assim, e a Sentinela seguiu a regra da casa. O freio do exército é o **Nióbio**, não o relógio.
     Ninguém decidiu isso: foi consistência. **Se ele quiser um tempo de fábrica, é decisão dele.**
   - **Ranking de guerras (§27.13)** — publicado por inteiro (percentis e pesos), mas **não há sistema
     de ranking** no jogo. Fora da Fatia 2.
   - ~~Federação~~ — **fechada em seis fatias (D-114 a D-116, D-119 a D-121; 2026-07-19).** O
     núcleo (cargos, convite/pedido, fundo por entrega física), o canal de chat, o apoio de aliado
     ao romper cerco (o §28.10 agora vale por inteiro, não só pelo dono da zona), a outra metade do
     impedimento do conciliador, as missões cooperativas, a Central de Comunicação (visão ao vivo
     do aliado + alerta de cerco), o limite antimonopólio territorial (20% de todas as zonas
     ocupadas do jogo), o desconto de tributo entre aliados (50% no comércio entre DUAS colônias
     da mesma federação — não na contribuição ao próprio fundo, que continua cheia), confirmação
     obrigatória ("SAIR") pra deixar a federação, e 6 das 9 ações sociais avisando pelo chat
     (conta "Federação") em vez de silenciosas. Os dois números do antimonopólio moram no painel
     de Federações (`FederationSetting`). **Não reabra.** O que ficou de fora, e por quê:
     `SacarDoFundo`/`CriarFederacao`/pedido de entrada não ganharam aviso (D-121, escopo); o §04
     tem mais dois eixos de antimonopólio (volume entre contas, estoque de
     minerais estratégicos) que ficaram fora por serem outra categoria de problema (antifraude) ou
     inertes por o governo ainda monopolizar os 8 minerais eletrônicos.

3. ~~O Marco do GDD (§03)~~ — **existe desde o D-75** (XP por atos, curva 50×N²; ver a lista de
   frentes acima). O varchar `colonies.milestone` do D-38 segue intocado, dormindo: o Marco deriva
   de `colonies.xp`. O `building_levels_sum` continua sendo só o porte do diretório.

4. ~~Serviço logístico público~~ (§07) — **estava desatualizado nesta mesma página**: já é D-76,
   citado acima ("Onde o projeto está"). A Garagem do Governo resolveu isso em 2026-07-13. Este item
   ficou esquecido quando o D-76 fechou; corrigido em 2026-07-14, ao retomar a sessão.

## Pendências conhecidas, sem bloquear

- **O tributo do Mercado contradiz o §07 de propósito** (D-32). O §07 proíbe dupla incidência e
  isentaria depósito e retirada; o usuário arbitrou pelo §25.8, que tributa cada entrega física.
  **Não "conserte" sem perguntar.**
- **Metal Bruto vale 5,5× menos do que o §07 diz** (D-34, arbitrado). Se a economia de mineração
  parecer fraca quando o Mercado abrir, é o primeiro número a revisitar.
- **Ida e volta ao Mercado sem vender custa tributo duas vezes** (D-32). É o §25.9 aplicado à
  letra: uma incidência por entrega física, e são duas entregas. Fixado em teste. Se o usuário
  achar punitivo demais, é decisão de balanceamento, não bug.
- **O salário do conciliador é emissão contínua** (D-50): 50 Fert$/dia, e o kit inicial de um colono
  é 50 Fert$. Um conciliador ganha um kit inicial por dia sem jogar. Com quatro colônias não importa;
  quando o jogo abrir, é o primeiro número a revisitar. Está no ledger (`salario_conciliador`), então
  dá para medir.
- **Parte do Ministério ainda é inerte, por decisão** (D-44, D-49): ~~silêncio precisa de chat~~ —
  **o chat existe (D-77) e o silêncio morde**: fecha os canais públicos pelo prazo, a privada
  continua. Seguem inertes: bloqueio de leilões (precisa de leilões) e impedimento por federação
  (precisa de federações). Tudo grava com índice e prazo, como sempre.
- **Depreciação de veículos (§16.4)** — **saiu da geladeira e está no ar** (D-60, fatia 2). Não é mais
  pendência. O GDD nunca publica a curva, o limite crítico nem o custo de manutenção — e o painel do
  §16 existe justamente para o **operador declará-los**. Foi isso que destravou o assunto, e é no
  **painel de admin** que se balanceia, sem deploy.
- **A frota nunca trava, e isso contraria o §16.4 de propósito** (D-60). O documento nomeia um
  "bloqueio operacional" abaixo do limite crítico; nós fizemos do limite crítico um **piso de
  desempenho** (25%). Uma carcaça a 5% ainda anda a 25%. **Não "conserte" sem perguntar.**
- **O Ministério dos Transportes contradiz o §17.2 e o §21.3 de propósito** (D-60). Os dois dizem que
  o Caminhão é "produzido pela Central de Transportes"; desde o D-60 a fábrica é do Ministério, e a
  Central só dá vaga. **Não "conserte" sem perguntar** — é o mesmo caso do tributo (D-32).
- **O caminhão só tem nível 1** (D-60). O GDD publica custo de veículo até o nível 5, mas **nunca diz
  o que o nível muda** (capacidade e velocidade são fixas por tipo). E as **duas tabelas de custo do
  Caminhão divergem a partir do nível 2** (§21.3 na curva 1,50×; §20 na 1,65×) — a armadilha do D-37.
  O nível 1 é idêntico nas duas, então hoje não nos toca. Se os níveis 2+ entrarem, **reabra o D-37
  antes de copiar qualquer tabela.**
- **Ninguém em produção pode comprar caminhão ainda** (2026-07-12): o teto de frota é máximo(1, nível
  da Central), e nenhuma das 5 colônias tem Central acima do nível 1. É o desenho, não um bug — o
  caminhão exige infraestrutura. O primeiro colono a subir a Central ao **nível 2** abre a primeira
  vaga do planeta.
- **O Furgão não tem teto de revenda** (D-60, aditivo 14) — decisão do usuário, com o risco aceito de
  olhos abertos: **um Furgão sucateado pode ser anunciado por 5.000 Fert$**, e duas contas do mesmo
  jogador podem lavar Fert$ de uma para a outra por aí, sem tributo. O Caminhão é imune (tem teto).
  **Se o multi-conta virar problema, é aqui que ele aparece primeiro.**
- **A realocação pelo painel não acerta a energia já gasta** (D-61). O veículo pagou, no despacho, a
  energia da viagem inteira pela distância **antiga**. Se a colônia se mudar para longe, a viagem
  refeita é mais cara do que o que ele pagou; para perto, mais barata. **O governo come a
  diferença** — cobrar do colono uma energia que ele não escolheu gastar seria puni-lo por um ato do
  operador. E os **Acordos abertos ficam com o prazo da distância antiga**: o painel avisa antes.
- **Um único dono em produção** (D-61). O painel impede desativar o último, mas se a senha se perder,
  só o `artisan fertways:admin --criar` recupera o acesso. **Crie um segundo dono.**
- **Zonas neutras como destino de carga** — o despacho aceita `colonia`; zona neutra precisa do
  Depósito de Zona Neutra. Entra no escopo do D-52.
- **Frontend** — o bundle passa de 1,5 MB sem code splitting (quase tudo é Phaser). Não incomoda
  ainda. O `vite build` avisa a cada compilação.
- **`cp` é alias de `cp -i` para o root.** No passo de deploy do frontend ele trava num prompt e
  copia nada, em silêncio, com saída que *parece* sucesso. Use `/bin/cp -rf dist/. …`. O
  `tools/deploy.sh` já cuida disso e aborta se o bundle no ar não for o recém-compilado.

## Verificação rápida (rode antes de confiar nesta página)

```sh
# Backend: use php84. O `php` do PATH é 8.2 e o composer.lock exige >= 8.4.1.
cd /home/fertways/apps/fertways/backend && /usr/bin/php84 artisan test

# A árvore de trabalho aponta para o banco de DEV? (tem que dar fertwaysdev)
/usr/bin/php84 artisan db:show | grep Database

# ⚠️ Migration nova? EXERCITE-A NO MariaDB antes de publicar — o `artisan test` é SQLite e não
# vale como evidência sobre DDL (foi assim que o D-59 quebrou a produção). Os dois sentidos:
/usr/bin/php84 artisan migrate --force && /usr/bin/php84 artisan migrate:rollback --step=1 --force \
  && /usr/bin/php84 artisan migrate --force

# ⚠️ Seeder novo? O `deploy.sh` NÃO os roda. Todo seeder de produção é passo à mão, e o esquecimento
# é silencioso: a tabela fica vazia e a regra nasce inerte. Já aconteceu com o Tesouro (D-57), as
# zonas (D-52) e os parâmetros do transporte (D-60). Confira que nenhuma destas está vazia:
cd /home/fertways/deploy/fertways/backend && /usr/bin/php84 artisan tinker --execute='
foreach (["treasury_holdings","neutral_zones","transport_settings","resource_types","building_specs"] as $t)
  echo str_pad($t, 22).DB::table($t)->count()."\n";'

# O site está no ar?
curl -s -o /dev/null -w '%{http_code}\n' https://fertways.tars.art.br/          # 200 (front)
curl -s https://fertways.tars.art.br/central/                                    # índice JSON da API
curl -s -o /dev/null -w '%{http_code}\n' https://fertways.tars.art.br/central/colony  # 401

# O cron do tick existe, aponta para o DEPLOY (não para apps/), e está mesmo rodando?
crontab -u fertways -l
tail -3 /home/fertways/logs/fertways-tick.log

# O symlink aponta para a cópia de deploy? (tem que dar deploy/fertways/backend/public)
readlink -f /home/fertways/public_html/central

# O que está no ar é o mesmo commit que `main`? E o GitHub, está em dia?
git -C /home/fertways/apps/fertways   log --oneline -1
git -C /home/fertways/deploy/fertways log --oneline -1
git -C /home/fertways/apps/fertways   status -sb | head -1

# ⚠️ O symlink NÃO prova o que está no ar: o opcache pode estar preso na árvore antiga (D-45).
# Só o `deploy.sh` confere isso de verdade. Na dúvida, `sudo systemctl reload php84-php-fpm`.

# Frontend: o typecheck honesto é `npm run build`, não `tsc --noEmit`.
export PATH="/usr/local/lib/nodejs/node-v22.12.0-linux-x64/bin:$PATH"
cd /home/fertways/apps/fertways/frontend && npm run build

# A tela do Mercado num navegador de verdade. Sobe e derruba a própria pilha.
cd /home/fertways/apps/fertways && ./tools/e2e.sh

# O bundle no ar é o do último build? (o deploy do front não é automático)
diff <(ls frontend/dist/assets/*.js | xargs -n1 basename) \
     <(curl -s https://fertways.tars.art.br/ | grep -oE 'index-[A-Za-z0-9_-]+\.js')
```

## Contas de teste

`publico@fertways.test` · `mapa2@fertways.test` — senha `segredo-forte-123` nas duas.
Recriadas em 2026-07-09 depois do incidente do D-36; nascem zeradas, sem nada na doca.
`f@t.test` ("Nova Aurora", nickname `fb`) veio do backup e sua senha não está documentada.

Há ainda uma quarta colônia em produção, `teste` (nickname `Teste`), de origem não documentada —
apareceu quando o diretório começou a listar todo mundo. E uma quinta, `Agua Preta muito Longe`,
igualmente não documentada. Produção tem **5 colônias** — esta página dizia 4 até 2026-07-12, e
estava errada; o D-57 já falava em cinco.

Essas contas vivem em `fertwaysbd` (produção). O banco de desenvolvimento, `fertwaysdev`, nasceu
migrado e semeado em 2026-07-09, **sem nenhuma colônia**: funde a sua própria ao testar.

As quatro têm os quatro índices de reputação em 500, conferido em produção depois do deploy do
Ministério. **`publico` é conciliador desde 2026-07-10**; as outras três, não.

### Contas de administração (D-61)

Vivem na tabela **`admins`**, separadas das de colono. Em produção há **uma**:
**`by_nvs@outlook.com`**, papel **`dono`** — promovido automaticamente pela migration do D-61.

> ⚠️ **É o único dono.** O painel **impede** que ele seja desativado ou rebaixado (senão ninguém mais
> poderia gerir admins e a única saída seria o `artisan`), mas **um único dono é um único ponto de
> falha**: se a senha se perder, só o `artisan fertways:admin --criar` recupera o acesso. Vale criar
> um segundo dono pelo painel.

**Papéis:** `dono` faz tudo, inclusive gerir admins e **realocar colônias**. `operador` julga casos,
publica notícias, distribui o Tesouro, e nos jogadores **vê, suspende e corrige estado** — não gere
admins e não realoca.

## ⚠️ Ferramentas destrutivas

**Com a config cacheada, o Laravel não lê `env()`**: exportar `DB_CONNECTION=sqlite` não redireciona
nada, e `migrate:fresh` cai no banco apontado pelo cache. Foi assim que o jogo foi apagado uma vez
(D-36). Por isso a árvore de trabalho **não tem** `bootstrap/cache/config.php` e **não deve rodar
`config:cache`** (D-46). A cópia de deploy tem, e é o `deploy.sh` que a gera.

Toda ferramenta que rode `migrate:fresh`, `db:wipe` ou `truncate` precisa **exportar também
`APP_CONFIG_CACHE`** para um caminho inexistente (como o `phpunit.xml` faz desde o D-27) **e
verificar o alvo antes de executar**. O `tools/e2e.sh` faz as duas coisas e aborta se a conexão
efetiva não for o SQLite temporário. O banco separado do D-46 é uma segunda trava, não um
substituto.

O binlog do MariaDB está **ligado** desde 2026-07-10 (`/etc/my.cnf.d/binlog.cnf`: formato ROW,
7 dias de retenção, `sync_binlog=1`). O backup continua diário às 03:00 (`/backup-local/mysql/`,
dump de *todos* os bancos do servidor — extraia só o `fertwaysbd` antes de restaurar), e o
`/root/backup-diario-vps.sh` agora passa `--master-data=2`, que grava no topo do dump a posição do
binlog em que ele foi tirado. **Com as duas coisas há point-in-time recovery de verdade**: restaure
o dump, depois aplique `mysqlbinlog --start-position=<a do dump> --stop-datetime='<T>'`.

Duas ressalvas. O binlog mora em `/var/lib/mysql`, o mesmo disco do banco: ele **não** protege
contra perda de disco — disso cuida o dump que o `rclone` manda ao Google Drive. E o
`--master-data=2` **exige** o binlog ligado: se alguém remover o `binlog.cnf`, o backup das 03:00
passa a falhar. O MariaDB é compartilhado com outros 26 bancos do servidor; ligar o binlog exigiu
reiniciá-lo.

## Leia também

- `docs/decisoes.md` — as decisões, com as divergências e lacunas do GDD. **A regra de ouro é
  não inventar valores.** Quando o GDD não decide, pergunte ao usuário e registre ali. Quando ele
  **se contradiz**, o D-47 diz como ler: a tabela de precedência da seção 0 primeiro; depois, o
  parágrafo de número maior *dentro da mesma parte*. Contradição e lacuna são coisas diferentes — o
  D-47 resolve a primeira e não toca na segunda.
- `docs/deploy.md` — php84, Node, o symlink `/central`, e por que `route:cache` está proibido.
