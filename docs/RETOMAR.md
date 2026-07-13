# RETOMAR — ponto de parada do FERTWAYS

> **Para o Claude:** quando o usuário disser "retome", leia este arquivo primeiro, confira o
> estado real (os comandos da seção "Verificação rápida" abaixo — não confie nesta página),
> e então **faça ao usuário as perguntas da seção "Perguntas em aberto"** antes de escolher
> o que fazer. Atualize este arquivo ao fim de cada sessão.

**Última atualização:** 2026-07-11 · **Branch:** `main`

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
    receita da Oficina), e o painel é Blade, coberto por **34 testes PHP**.
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
  - **Teto de revenda = preço de fábrica × teto de conservação.** Só o **Caminhão** tem — o Furgão não
    é vendido pelo Ministério, logo não tem preço de fábrica, e o usuário decidiu deixá-lo **sem
    âncora**. ⚠️ **É por aí que a lavagem de Fert$ entre duas contas do mesmo jogador vai aparecer
    primeiro, se aparecer** (aditivo 14 do D-60).
  - **Veículo anunciado não sai em viagem** — senão o comprador levaria um erro por culpa do vendedor.
  - **Os parâmetros são do operador**, no painel de admin: desgaste, piso, custo da manutenção e
    perda de teto. O §16 manda o Ministério configurá-los e o GDD nunca publica nenhum — **foi isso
    que permitiu tirar a depreciação da geladeira sem inventar constante no código** (padrão do D-35).
  - **Fora de escopo, de propósito:** o **Cargueiro Interplanetário** e o seu aluguel. Dependem do
    Espaçoporto e dos planetas NPC, que não existem.

**414 testes PHP (3286 asserções) + 7 e2e, verdes.** O cron do tick está instalado (crontab do usuário
`fertways`, log em `/home/fertways/logs/fertways-tick.log`) e roda o `artisan` **da cópia de
deploy** — o mundo avança sozinho. O tick faz: produção, upgrades, proteções, trechos de viagem,
acordos vencidos, **casos reatribuídos, janelas de apelação fechadas e a folha do Ministério**.

As telas têm **teste de ponta a ponta em navegador de verdade**: `npm run e2e` (ou
`./tools/e2e.sh`) sobe uma pilha efêmera (SQLite temporário + `artisan serve` + `vite dev`) e dirige
o Chromium do sistema com `puppeteer-core`. Mapa e Frota, Mercado, Acordo, Ministério e a Fundação.
Nunca toca produção nem o MariaDB. A **receita da Oficina não tem e2e**: o painel está atrás de um
clique num hexágono do Phaser, e acertá-lo por coordenada quebraria ao primeiro ajuste de layout. A
API dela é coberta em PHP.

Os **sete** arquivos (`e2e/{telas,capital,mercado,acordos,ministerio,zonas,fundacao}.e2e.mjs`)
compartilham o andaime de `e2e/comum.mjs` **e o mesmo banco efêmero**, então **a ordem em que
`e2e.sh` os chama importa** e não é arbitrária:

1. **Mapa e Frota** — espera os **três furgões ociosos**, no pátio.
2. **Capital** — **subiu de posição no D-60**, e por um motivo concreto: a tela do Ministério dos
   Transportes precisa de um veículo **no pátio** para reparar e sucatear, e o botão de manutenção só
   existe para veículo ocioso. Rodando depois do Mercado, os três furgões já estão em rota e não há
   em que clicar.
3. **Mercado** — deixa dois furgões em rota. É também onde vive o e2e do **mercado de usados**, no fim.
4. **Acordo** — despacha o terceiro.
5. **Ministério** das Reputações, e **Zonas**.
6. **Fundação, por último**: registra um colono e funda uma quinta colônia — rodar antes bagunçaria
   as contagens de todas as telas anteriores.

> O seeder do e2e **gasta um dos furgões de propósito** (62% de conservação): sem desgaste, o botão
> de manutenção nasce desabilitado e o teste dela não teria o que exercitar.

> O e2e semeia **quatro** colônias (e2e em (0,3), vizinha em (0,6), ré, autora); o teste da Fundação
> acrescenta a quinta no fim. O mapa, visto pelo colono do e2e antes disso, desenha três vizinhas
> mais ele. Já me enganei uma vez esperando duas.

> **Instabilidade conhecida:** o do Mercado falhou uma vez em quatro com `Protocol error
> (Runtime.getProperties): Target closed`. Verde nas outras três. Se reprovar assim, rode de novo
> antes de investigar — mas se virar hábito, é bug de verdade.

**Publicado no GitHub e no ar.** O último deploy é de **2026-07-13**, no commit `bf2fc7b` — **a arte
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

- **O Furgão sem teto de revenda.** É o buraco por onde a lavagem de Fert$ entre duas contas do mesmo
  jogador vai aparecer primeiro, se aparecer (aditivo 14 do D-60). A cura é dar-lhe um teto.
- **O preço de 300 Fert$ do Caminhão** é ~9× o valor dos recursos dele. Foi escolhido para ser um
  objetivo de médio prazo e um dreno de Fert$; se ninguém comprar, é o primeiro número a revisitar.
- **Os quatro parâmetros da depreciação** estão no painel de admin e mudam sem deploy. É lá que se
  balanceia o envelhecimento da frota — não no código.

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

**O que existe (388 testes PHP + 7 e2e verdes):**
- **Catálogo (+5, agora 30 tabelas):** Muralha de Perímetro, Torre de Vigia, Bastião, Abrigo de Robôs
  (bases arbitradas) e a **Sentinela** (custo publicado no §27.1).
- **`units` — unidades com HP.** O `garrison` int **morreu**: o §27.6 exige HP individual, e um
  contador não sabe quem está ferido. A API ainda publica `garrison`, agora contado.
- **`war_settings`** — os bônus do §27.3 e as duas chances do §28.10, do **operador**, com default no
  banco. Falta ainda expô-los no **painel de admin** (hoje só se mudam por SQL).
- **`Domain/Guerra/`** — `Forcas` (§27.3, com o +20% offline por snapshot), `Protegido`,
  `Atacar` (os 4 tipos, marcha 1,3×, cooldown de 48 h), **`ResolverCombates`** (a rodada de 10 min,
  no tick), `ComprarNiobio` e `FabricarUnidade`.
- **O Quartel enfim fabrica.** Ele estava no catálogo desde sempre e não fazia nada: **nenhuma
  Sentinela poderia existir**. O nível dele é o teto do nível da unidade (não está no GDD — é o
  desenho da Central de Transportes do D-60).
- **Tela:** o **Quartel** é a porta (clique na construção), com o Nióbio, a fábrica, o exército e as
  batalhas em curso — **inclusive as em que se está defendendo**, que é o que o §27.5 exige. O
  **ataque** parte do painel de zona, no mapa.
- API: `GET /war`, `GET /war/combats`, `POST /war/units`, `POST /war/niobio`, `POST /war/attack`.

**O que falta, e nada disso bloqueia:**
1. **Os parâmetros da guerra no painel de admin.** Existem e valem; só não têm tela. É o mesmo padrão
   do D-60 (lá eles têm).
2. **e2e do Quartel.** Ele está atrás de um clique num hexágono do Phaser — **mesma razão da receita
   da Oficina (D-54) e da demolição (D-59)**. A API é coberta em PHP.
3. **Romper o cerco.** O §28.10 diz que o defensor manda Sentinelas às rotas externas para quebrá-lo.
   Hoje o cerco só se rompe **esperando as 48 h e entregando os 30%**. Falta a ação do defensor.
4. **Reforçar uma zona sob ataque.** O motor **já conta** reforços (recongela a força a cada chegada,
   que é o que faz o §27.5 dizer que "reforços tardios podem mudar o resultado"), mas **não há rota
   nem botão** para despachá-los. A tela até diz ao defensor que "ainda dá tempo" — e ainda não dá.

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

**Fatia 2 e 3, depois:** guerra do §27 (lacunas 4 e 8) e o Drone (lacunas 5, 6, 10). O gate do Marco
(§05) fica **suspenso**, por decisão do usuário.

## Perguntas em aberto — faça estas ao usuário ao retomar

0. **A guerra está no meio. A primeira pergunta é se ele quer que você a termine** — e, se sim,
   **não há mais nada a arbitrar para isso**: as oito lacunas do §27 estão fechadas no D-66. Comece
   pelo `ResolverCombates` (ver a seção da guerra, acima). **Não refaça as perguntas do D-66.**

1. **As lacunas do D-52 que ainda travam — só as das próximas fatias.** Não invente nenhuma. Já
   arbitradas: **Fatia 1** (extração 100/h, mineral por distrito, ocupação) e **Fatia 2 inteira**
   (D-66: Nióbio, estoque protegido, bônus defensivos, custo das 4 construções de defesa, Módulo
   Operacional, as duas chances do §28.10, o cerco, e o término do combate). Ainda abertas:
   - **Fatia 3 (Drone):** **velocidade** (Furgão 4 slots/min, Caminhão 1,5, Nave 10 são as âncoras),
     **raio de revelação** e **persistência**, e **onde é fabricado**. **Não pergunte o custo:** ele
     está publicado, e a errata do D-37 (2026-07-11) fixou qual das duas tabelas vale — a curva
     **1,65×** do §4.3 do v3.4, `50 83 136 225 371`. Bateria, recarga e depreciação também estão no
     GDD (D-52). As lacunas do Drone são **quatro**, não cinco.
   - **Custo/tempo das 6 estruturas de zona restantes** (Estrutura de Extração, Refinaria de Campo,
     Central de Comunicação, Plataforma de Pouso, Estacionamento, Cemitério de Robôs). O Posto de
     Comando saiu no D-52; a Muralha, a Torre de Vigia, o Bastião e o Abrigo de Robôs, no D-66.
     **Nenhuma das seis é exigida pela guerra.**
   - **Teto de zonas por jogador** e **upgrade de zona** (a Fatia 1 fixou nível 1).
   - **Fabricar unidade é instantâneo hoje** — o Robô Minerador, o Infiltrador e o Predador já eram
     assim, e a Sentinela seguiu a regra da casa. O freio do exército é o **Nióbio**, não o relógio.
     Ninguém decidiu isso: foi consistência. **Se ele quiser um tempo de fábrica, é decisão dele.**
   - **Ranking de guerras (§27.13)** — publicado por inteiro (percentis e pesos), mas **não há sistema
     de ranking** no jogo. Fora da Fatia 2.
   - **Federação** — o §28.10 diz que uma federação aliada pode romper um cerco. **Federações não
     existem** (mesma inércia do D-44). Por ora o cerco só se rompe pelo dono da zona.

3. **O Marco do GDD** (§03) continua congelado em `colonizacao_inicial`. O GDD nomeia os marcos
   (1 Sobrevivente … 100 Lenda de Fertways) e **não publica a fórmula**. Ver D-38 — o
   `building_levels_sum` do diretório é um proxy e **não** deve virar o Marco.

4. **Serviço logístico público** (§07): o GDD o cita como alternativa ao veículo próprio na
   retirada, e ele não existe. Hoje o comprador precisa de Furgão ou Caminhão. Sem preço nem prazo
   publicados — precisaria de arbitragem.

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
- **Metade do Ministério está inerte, por decisão** (D-44, D-49): silêncio precisa de chat, bloqueio
  de leilões precisa de leilões, e o impedimento por federação precisa de federações. Tudo grava com
  índice e prazo, e passa a morder sozinho no dia em que esses sistemas existirem.
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
