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
- **Ministério do Tesouro e kit de recursos (D-57)** — o Tesouro virou caixa gastável (ver slot 2
  acima). E toda colônia recebe um **kit fixo**: 1000 metal bruto, 1000 ligas, 500 compostos, 300
  biocombustível, 500 componentes — **emissão do governo**, concedido na fundação
  (`ColonyController::store`) e por backfill (`artisan fertways:kit-recursos --aplicar`). Números
  decididos pelo usuário, **não do GDD**.

**245 testes PHP (2351 asserções) + 7 e2e, verdes.** O cron do tick está instalado (crontab do usuário
`fertways`, log em `/home/fertways/logs/fertways-tick.log`) e roda o `artisan` **da cópia de
deploy** — o mundo avança sozinho. O tick faz: produção, upgrades, proteções, trechos de viagem,
acordos vencidos, **casos reatribuídos, janelas de apelação fechadas e a folha do Ministério**.

As telas têm **teste de ponta a ponta em navegador de verdade**: `npm run e2e` (ou
`./tools/e2e.sh`) sobe uma pilha efêmera (SQLite temporário + `artisan serve` + `vite dev`) e dirige
o Chromium do sistema com `puppeteer-core`. Mapa e Frota, Mercado, Acordo, Ministério e a Fundação.
Nunca toca produção nem o MariaDB. A **receita da Oficina não tem e2e**: o painel está atrás de um
clique num hexágono do Phaser, e acertá-lo por coordenada quebraria ao primeiro ajuste de layout. A
API dela é coberta em PHP.

Os **cinco** arquivos (`e2e/{telas,mercado,acordos,ministerio,fundacao}.e2e.mjs`) compartilham o
andaime de `e2e/comum.mjs` **e o mesmo banco efêmero**, então a ordem em que `e2e.sh` os chama
importa: o de Mapa e Frota vem primeiro, porque espera os três furgões ociosos; o do Mercado deixa
dois em rota; o do Acordo despacha o terceiro; e o da **Fundação vem por último**, porque registra um
colono e funda uma quinta colônia — rodar antes bagunçaria as contagens das telas anteriores.

> O e2e semeia **quatro** colônias (e2e em (0,3), vizinha em (0,6), ré, autora); o teste da Fundação
> acrescenta a quinta no fim. O mapa, visto pelo colono do e2e antes disso, desenha três vizinhas
> mais ele. Já me enganei uma vez esperando duas.

> **Instabilidade conhecida:** o do Mercado falhou uma vez em quatro com `Protocol error
> (Runtime.getProperties): Target closed`. Verde nas outras três. Se reprovar assim, rode de novo
> antes de investigar — mas se virar hábito, é bug de verdade.

**Publicado no GitHub e no ar.** O último deploy é de 2026-07-11, no commit `4843c11` — de conteúdo
só documental (a errata do D-37), mas ele **carregou a mudança de `.env`** do cookie seguro, que é o
que exigia o `config:cache` e o reload do php-fpm. As duas árvores ficaram nesse commit. Antes dele,
no mesmo dia, **o Ministério do Tesouro — caixa real + kit por colônia** (`32e3ed2`, D-57): a migration
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

## O trabalho em curso: zonas neutras + Drone (D-52)

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

1. **As lacunas do D-52 que ainda travam — só as das próximas fatias.** Não invente nenhuma. Já
   arbitradas (Fatia 1): base de extração 100/h, mineral por distrito, requisitos de ocupação. Ainda
   abertas, por fatia:
   - **Fatia 2 (guerra):** o que é **"estoque protegido"** (o saque de 50% depende disso) e os
     **bônus defensivos** de Muralha e Torre de Vigia (§27.3).
   - **Fatia 3 (Drone):** **velocidade** (Furgão 4 slots/min, Caminhão 1,5, Nave 10 são as âncoras),
     **raio de revelação** e **persistência**, e **onde é fabricado**. **Não pergunte o custo:** ele
     está publicado, e a errata do D-37 (2026-07-11) fixou qual das duas tabelas vale — a curva
     **1,65×** do §4.3 do v3.4, `50 83 136 225 371`. Bateria, recarga e depreciação também estão no
     GDD (D-52). As lacunas do Drone são **quatro**, não cinco.
   - Em qualquer fatia: **custo/tempo das 9 estruturas** de zona restantes (só o Posto de Comando foi
     arbitrado), **teto de zonas por jogador** e **upgrade de zona** (a Fatia 1 fixou nível 1).

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
- **Depreciação de veículos (§16.4)** — fora do MVP por decisão do usuário. O GDD descreve o
  comportamento mas não publica a curva de desgaste, o limite crítico nem o custo de manutenção.
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
apareceu quando o diretório começou a listar todo mundo. Produção tem **4 colônias**.

Essas contas vivem em `fertwaysbd` (produção). O banco de desenvolvimento, `fertwaysdev`, nasceu
migrado e semeado em 2026-07-09, **sem nenhuma colônia**: funde a sua própria ao testar.

As quatro têm os quatro índices de reputação em 500, conferido em produção depois do deploy do
Ministério. **`publico` é conciliador desde 2026-07-10**; as outras três, não.

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
