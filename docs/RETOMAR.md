# RETOMAR — ponto de parada do FERTWAYS

> **Para o Claude:** quando o usuário disser "retome", leia este arquivo primeiro, confira o
> estado real (os comandos da seção "Verificação rápida" abaixo — não confie nesta página),
> e então **faça ao usuário as perguntas da seção "Perguntas em aberto"** antes de escolher
> o que fazer. Atualize este arquivo ao fim de cada sessão.

**Última atualização:** 2026-07-09 · **Branch:** `main`

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
- Frontend: login, HUD, colônia em Phaser, e **três telas** (três botões no HUD): Mercado, Acordo e
  Ministério.
  - A do **Acordo** propõe, aceita, recusa, desiste, mostra a Confiança Comercial contra o limiar e
    **despacha a entrega pelo bruto**, não pelo prometido: quem embarca 100 entrega 97, e o colono
    não deve descobrir que caloteou por três unidades de tributo (D-41).
  - A do **Ministério** mostra os quatro índices, as punições vigentes com prazo, abre denúncia (com
    a evidência filtrada: só o Acordo quebrado entre os dois serve, §26.8), e dá ao conciliador a
    fila com o relógio das 48 h. **Ela publica a pena tabelada antes do julgamento** e só lhe oferece
    "Procedente" e "Improcedente": a pena não é escolha dele (§26.8, D-49).

**165 testes, 1785 asserções, verdes.** O cron do tick está instalado (crontab do usuário
`fertways`, log em `/home/fertways/logs/fertways-tick.log`) e roda o `artisan` **da cópia de
deploy** — o mundo avança sozinho. O tick faz: produção, upgrades, proteções, trechos de viagem,
acordos vencidos, **casos reatribuídos, janelas de apelação fechadas e a folha do Ministério**.

As três telas têm **teste de ponta a ponta em navegador de verdade**: `npm run e2e` (ou
`./tools/e2e.sh`) sobe uma pilha efêmera (SQLite temporário + `artisan serve` + `vite dev`) e dirige
o Chromium do sistema com `puppeteer-core`. 26 verificações no Mercado, 22 no Acordo, 30 no
Ministério. Nunca toca produção nem o MariaDB.

Os três arquivos (`e2e/{mercado,acordos,ministerio}.e2e.mjs`) compartilham o andaime de
`e2e/comum.mjs` **e o mesmo banco efêmero**, então a ordem em que `e2e.sh` os chama importa: o do
Mercado deixa dois furgões em rota, e o do Acordo despacha o terceiro.

> **Instabilidade conhecida:** o do Mercado falhou uma vez em quatro com `Protocol error
> (Runtime.getProperties): Target closed`. Verde nas outras três. Se reprovar assim, rode de novo
> antes de investigar — mas se virar hábito, é bug de verdade.

**Publicado no GitHub.** `main` e `origin/main` estavam 17 commits apartados; foram empurrados em
2026-07-09. Confira com `git status -sb` — se voltar a divergir, republique.

## O deploy, depois do D-45

- Edita-se em `apps/fertways`. **Não é servido.**
- O Apache serve `/home/fertways/deploy/fertways`, e o cron do tick executa o `artisan` de lá.
- Publicar é `sudo ./tools/deploy.sh` — e **só ele publica**, porque agora ele recarrega o php-fpm.
  Sem o reload, o opcache mantém os workers presos na árvore para onde o symlink apontava quando
  eles subiram, e o deploy não tem efeito nenhum (D-45). A fumaça de `200`/`401` não detecta isso:
  o script pergunta ao opcache qual árvore está no ar e aborta se achar `/home/fertways/apps/`.
- **Os bancos agora são dois** (D-46): `fertwaysdev` na árvore de trabalho, `fertwaysbd` só no
  deploy. O D-36 está fechado.

## Perguntas em aberto — faça estas ao usuário ao retomar

1. **Qual o próximo passo?** Candidatos, sem ordem decidida. **Todo sistema do jogo tem tela.**
   - **Nomear um conciliador em produção.** O Ministério está no ar, e **não há conciliador nenhum**:
     todo caso sobe à equipe, isto é, ao seu terminal. `artisan fertways:conciliador <nick> --nomear`
     numa das quatro contas. Repare que o nomeado passa a receber 50 Fert$/dia (§26.7) — e ninguém
     mais tem renda passiva no jogo.
   - **Serviço logístico público** (§07): o GDD o cita como alternativa ao veículo próprio na
     retirada, e ele não existe. Hoje o comprador precisa de Furgão ou Caminhão. O GDD não publica
     preço nem prazo — precisaria de arbitragem.
   - **O Marco do GDD** (§03): `colonies.milestone` é uma string congelada em `colonizacao_inicial`
     desde a fundação, e nada a atualiza. O GDD nomeia os marcos (1 Sobrevivente … 100 Lenda de
     Fertways) mas **não publica a fórmula**. Precisaria de arbitragem. Ver D-38 — o
     `building_levels_sum` do diretório é um proxy, e não deve ser renomeado para virar o Marco.

2. **Ligar o binlog do MariaDB?** O backup é diário, às 03:00, e o binlog está desligado: **até 24 h
   de perda**. O código já está a salvo no GitHub desde 2026-07-09, mas os dados do jogo não. Com 4
   colônias de teste isso não dói; no dia em que doer, já será tarde.

3. **O Drone de Exploração continua sem função** (D-37): o diretório revela todas as colônias, então
   restam-lhe as zonas neutras. É consequência assumida, não esquecimento. Vale perguntar se o
   usuário quer devolver-lhe um papel.

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
  Depósito de Zona Neutra.
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
Ministério. Nenhuma é conciliadora.

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

O binlog do MariaDB está **desligado** e o backup é diário, às 03:00 (`/backup-local/mysql/`, dump
de *todos* os bancos do servidor — extraia só o `fertwaysbd` antes de restaurar). Isso significa
**até 24 h de perda**. Se algum dado passar a importar, ligar o binlog é o primeiro passo.

## Leia também

- `docs/decisoes.md` — as decisões, com as divergências e lacunas do GDD. **A regra de ouro é
  não inventar valores.** Quando o GDD não decide, pergunte ao usuário e registre ali. Quando ele
  **se contradiz**, o D-47 diz como ler: a tabela de precedência da seção 0 primeiro; depois, o
  parágrafo de número maior *dentro da mesma parte*. Contradição e lacuna são coisas diferentes — o
  D-47 resolve a primeira e não toca na segunda.
- `docs/deploy.md` — php84, Node, o symlink `/central`, e por que `route:cache` está proibido.
