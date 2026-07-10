# RETOMAR — ponto de parada do FERTWAYS

> **Para o Claude:** quando o usuário disser "retome", leia este arquivo primeiro, confira o
> estado real (os comandos da seção "Verificação rápida" abaixo — não confie nesta página),
> e então **faça ao usuário as perguntas da seção "Perguntas em aberto"** antes de escolher
> o que fazer. Atualize este arquivo ao fim de cada sessão.

**Última atualização:** 2026-07-10 · **Branch:** `main`

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
- **Mercado Central**: conta na doca com depósito e retirada físicos (§25.8, D-32) e **livro de
  ofertas com escrow** (§07, D-35). O Mercado casa ordens; não compra nem vende.
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
- Frontend: login, HUD, colônia em Phaser, e **cinco telas** (cinco botões no HUD): Mapa, Frota,
  Mercado, Acordo e Ministério.
  - A do **Acordo** propõe, aceita, recusa, desiste, mostra a Confiança Comercial contra o limiar e
    **despacha a entrega pelo bruto**, não pelo prometido: quem embarca 100 entrega 97, e o colono
    não deve descobrir que caloteou por três unidades de tributo (D-41).
  - A do **Ministério** mostra os quatro índices, as punições vigentes com prazo, abre denúncia (com
    a evidência filtrada: só o Acordo quebrado entre os dois serve, §26.8), e dá ao conciliador a
    fila com o relógio das 48 h. **Ela publica a pena tabelada antes do julgamento** e só lhe oferece
    "Procedente" e "Improcedente": a pena não é escolha dele (§26.8, D-49).

**174 testes, 1892 asserções, verdes.** O cron do tick está instalado (crontab do usuário
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

**Publicado no GitHub e no ar.** Em 2026-07-10 foi empurrado e publicado o **mapa concêntrico do
D-51** (`2a71a1c`): grade 101×101, Capital em (0,0), fundação por escolha, e a realocação das 4
colônias. A cópia de deploy ficou nesse commit. Confira com `git log --oneline -1` nas duas árvores
— se voltar a divergir, republique. (Antes, em 2026-07-10, saíram o `fix` da fila do D-53 e as telas
do D-54.)

> **Lição registrada (2026-07-10).** Ao conferir o D-53 em produção, enfileirei uma construção de
> teste na colônia 4 pelo `EnqueueUpgrade`. Funcionou, mas escrever no banco de produção "para ver
> com os próprios olhos" deixou resíduo: item de fila, marca de upgrade na Oficina e **seis
> lançamentos no ledger**. Limpei os três, o último com autorização do usuário (apagar ledger de
> produção é barrado por padrão, e com razão). **Não escreva em produção para verificar** — confie
> no e2e e nos testes, ou use só leitura. Ver [[fertways-nao-escrever-em-producao-para-testar]].

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

Leia **D-52**. O mapa (pré-requisito) já está pronto; agora vêm as zonas neutras e o Drone.
- Base horária da extração **arbitrada em 100/h** (2026-07-10). Restam as outras lacunas do D-52.
- **120 zonas neutras** em 4 distritos de 30 (6×5) encostados nos cantos (a geometria dos distritos
  está no D-51, mas as zonas em si são D-52). Guerra do §27 incluída.
- O gate do Marco (§05) fica **suspenso**, por decisão do usuário.

## Perguntas em aberto — faça estas ao usuário ao retomar

1. **As dez lacunas do D-52 precisam de arbitragem, e travam a implementação.** Não invente nenhuma.
   A base horária da extração **já foi arbitrada em 2026-07-10: 100/h** (ver D-52, lacuna 1). As três
   que bloqueiam primeiro agora:
   - **Drone: velocidade, raio de revelação e persistência.** Publicadas: Furgão 4 slots/min,
     Caminhão 1,5, Nave Planetária 10. O Drone não carrega nada e o GDD cala a sua velocidade.
   - **Os três requisitos de ocupação** (§07). Só o primeiro tem âncora: "20 a 150+" robôs.
   - **O que é "estoque protegido"** — o saque de 50% depende disso e o GDD nunca o define.

2. **Por onde começar?** O mapa (D-51) é pré-requisito das zonas neutras e não depende de nenhuma
   arbitragem pendente: dá para escrevê-lo já. Mas ele mexe em produção — migration de coordenada
   com sinal, realocação das 4 colônias, e o Phaser desenha o mapa.

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
- **O D-37 erra num ponto de fato.** Ele diz que o GDD "nunca publicou raio, persistência nem custo
  de revelação" do Drone. O **custo está publicado** — em duas tabelas, e o próprio GDD resolve qual
  vale (§4.3 da v3.4, curva 1,65×). Raio e persistência são lacunas de verdade. Ver D-52.
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
