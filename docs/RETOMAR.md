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
- **Mercado Central** (última fatia): conta na doca com depósito e retirada físicos (§25.8, D-32)
  e **livro de ofertas com escrow** (§07, D-35). `GET/POST /central/market/orders`,
  `DELETE /central/market/orders/{id}`, `GET /central/market/account`,
  `POST /central/vehicles/{id}/withdraw`. O Mercado casa ordens; não compra nem vende.
- **Logout de verdade**: `POST /central/logout` revoga no servidor o token que fez a chamada — só
  ele, para não derrubar outra sessão do mesmo colono. Token do Sanctum não expira, então apagar o
  `localStorage` sozinho deixava credencial válida circulando para sempre.
- **Diretório de colônias**: `GET /central/colonies` lista as demais colônias, da mais próxima à
  mais distante, com `nickname`, `x`, `y`, `distance` e `building_levels_sum`. É o que tornou o
  despacho entre colônias alcançável pela UI — `dispatch` sempre pediu a PK do destino, e não havia
  como descobrir o `id` de ninguém. Ver D-37 e D-38.
- Frontend: login, HUD, colônia em Phaser, e a **tela do Mercado** (botão no HUD): doca, frota com
  contagem regressiva, despacho e retirada, livro de ofertas com escrow, **envio de carga ao slot
  de outro colono**, e veículos em rota que **nomeiam** o colono de destino em vez de mostrar o `id`

**120 testes, 1621 asserções, verdes.** O **cron do tick está instalado** (crontab do usuário
`fertways`, log em `/home/fertways/logs/fertways-tick.log`) — o mundo avança sozinho.

O item 5 do MVP (Mercado Central) está **fechado, backend e tela**, e a tela tem **teste de ponta
a ponta em navegador de verdade**: `npm run e2e` (ou `./tools/e2e.sh`) sobe uma pilha efêmera
(SQLite temporário + `artisan serve` + `vite dev`) e dirige o Chromium do sistema com
`puppeteer-core`. 26 verificações. Nunca toca produção nem o MariaDB.

> **Tudo publicado e verificado contra produção em 2026-07-09**: o logout (login → `/colony` 200 →
> `/logout` 200 → `/colony` 401) e o diretório (401 sem token; com token, lista ordenada por
> distância). O deploy **não** é automático e exige autorização explícita: confira sempre o hash do
> bundle servido com o `diff` da "Verificação rápida" antes de supor que o que está no ar é o que
> está em `main`.

**Deploy separado desde 2026-07-09 (D-39).** `apps/fertways` é onde se edita e **não é servido**;
`/home/fertways/deploy/fertways` é o clone que o Apache serve e que o cron do tick executa.
Publicar é `sudo ./tools/deploy.sh`. **O banco continua sendo um só** — ver D-36, ainda vivo.

## Perguntas em aberto — faça estas ao usuário ao retomar

1. **Qual o próximo passo?** O usuário escolheu, em 2026-07-09, o **Acordo de Troca** (§26.5) —
   **ainda não começado**. Hoje o envio a outro colono é carga de mão única: quem manda confia. O
   Acordo é o contrato que falta ao redor disso: sem escrow, com o risco de calote deliberadamente
   real. Faz par com o Mercado e está no MVP social. Comece por ler o §26.5 no GDD e levantar o que
   ele **não** decide (prazo do acordo, o que acontece no calote, se há registro de reputação) —
   essas viram perguntas ao usuário, não invenções. Restam depois:
   - **Serviço logístico público** (§07): o GDD o cita como alternativa ao veículo próprio na
     retirada, e ele não existe. Hoje o comprador precisa de Furgão ou Caminhão. O GDD não
     publica preço nem prazo do serviço — precisaria de arbitragem.
   - **O Marco do GDD** (§03): `colonies.milestone` é uma string congelada em `colonizacao_inicial`
     desde a fundação, e nada a atualiza. O GDD nomeia os marcos (1 Sobrevivente … 100 Lenda de
     Fertways) mas **não publica a fórmula**. Precisaria de arbitragem. Ver D-38 — o
     `building_levels_sum` do diretório é um proxy, e não deve ser renomeado para virar o Marco.
   - **O Drone de Exploração ficou sem função** (D-37): o diretório revela todas as colônias, então
     restam-lhe as zonas neutras. É consequência assumida, não esquecimento.

2. ~~**Separar o diretório de deploy do diretório de trabalho?**~~ **Feito em 2026-07-09 (D-39).**
   O symlink e o cron agora apontam para `/home/fertways/deploy/fertways`, um clone à parte.
   Editar em `apps/fertways` não publica mais nada; publicar é `sudo ./tools/deploy.sh`.

   **O banco continua sendo um só.** A separação isolou o código, não os dados: `migrate:fresh` na
   árvore de trabalho ainda apaga a produção. O D-36 vale palavra por palavra. Separar o banco de
   desenvolvimento do de produção é o trabalho que ficou por fazer — vale perguntar ao usuário.

3. **Publicar os commits no GitHub?** `main` está **15 commits à frente do `origin/main`** (o
   GitHub parou em `9a6f046`). Todo o Mercado Central e o diretório de colônias existem só neste
   disco, e o backup é diário: até 24 h de perda levariam o trabalho junto. Perguntado em
   2026-07-09 só de raspão, ao escolher a origem do clone de deploy; o usuário optou por manter o
   deploy puxando do repo local, o que **não** era uma recusa a publicar. Repergunte.

4. ~~**Zerar as colônias de teste?**~~ **Decidido em 2026-07-09: deixar como está.** `publico` tem
   54,85 Fert$ e 770 na doca; `mapa2` tem 45 Fert$ e 100 na doca — estado artificial (abastecimento
   à mão mais um negócio real no livro), mas serve de cenário pronto para testar o Mercado. Não
   atrapalha ninguém. Só repergunte se o jogo abrir para gente de fora.

## Pendências conhecidas, sem bloquear

- **O tributo do Mercado contradiz o §07 de propósito** (D-32). O §07 proíbe dupla incidência e
  isentaria depósito e retirada; o usuário arbitrou pelo §25.8, que tributa cada entrega física.
  **Não "conserte" sem perguntar.**
- **Metal Bruto vale 5,5× menos do que o §07 diz** (D-34, arbitrado). Se a economia de mineração
  parecer fraca quando o Mercado abrir, é o primeiro número a revisitar.
- **Ida e volta ao Mercado sem vender custa tributo duas vezes** (D-32). É o §25.9 aplicado à
  letra: uma incidência por entrega física, e são duas entregas. Fixado em teste. Se o usuário
  achar punitivo demais, é decisão de balanceamento, não bug.
- **Depreciação de veículos (§16.4)** — fora do MVP por decisão do usuário. O GDD descreve o
  comportamento mas não publica a curva de desgaste, o limite crítico nem o custo de manutenção.
- **Zonas neutras como destino de carga** — o despacho aceita `colonia`; zona neutra precisa do
  Depósito de Zona Neutra.
- **Frontend** — o bundle passa de 1,5 MB sem code splitting (quase tudo é Phaser). Não incomoda
  ainda. O `vite build` avisa a cada compilação.
- **`cp` é alias de `cp -i` para o root.** No passo de deploy do frontend ele trava num prompt e
  copia nada, em silêncio, com saída que *parece* sucesso. Use `/bin/cp -rf dist/. …`. Confira o
  hash do bundle servido contra o de `dist/assets/` antes de dizer que publicou. O
  `tools/deploy.sh` já faz as duas coisas e aborta se o bundle no ar não for o recém-compilado.

## Verificação rápida (rode antes de confiar nesta página)

```sh
# Backend: use php84. O `php` do PATH é 8.2 e o composer.lock exige >= 8.4.1.
cd /home/fertways/apps/fertways/backend && /usr/bin/php84 artisan test

# O site está no ar?
curl -s -o /dev/null -w '%{http_code}\n' https://fertways.tars.art.br/          # 200 (front)
curl -s https://fertways.tars.art.br/central/                                    # índice JSON da API
curl -s -o /dev/null -w '%{http_code}\n' https://fertways.tars.art.br/central/colony  # 401

# O cron do tick existe, aponta para o DEPLOY (não para apps/), e está mesmo rodando?
crontab -u fertways -l
tail -3 /home/fertways/logs/fertways-tick.log

# O symlink aponta para a cópia de deploy? (tem que dar deploy/fertways/backend/public)
readlink -f /home/fertways/public_html/central

# O que está no ar é o mesmo commit que `main`? (o deploy é explícito; podem divergir)
git -C /home/fertways/apps/fertways   log --oneline -1
git -C /home/fertways/deploy/fertways log --oneline -1

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

## ⚠️ Ferramentas destrutivas neste deploy

Existe `backend/bootstrap/cache/config.php`. **Com a config cacheada, o Laravel não lê `env()`**:
exportar `DB_CONNECTION=sqlite` não redireciona nada, e `migrate:fresh` cai no MariaDB de produção.
Foi assim que o banco do jogo foi apagado uma vez (D-36).

Toda ferramenta que rode `migrate:fresh`, `db:wipe` ou `truncate` precisa **exportar também
`APP_CONFIG_CACHE`** para um caminho inexistente (como o `phpunit.xml` faz desde o D-27) **e
verificar o alvo antes de executar**. O `tools/e2e.sh` faz as duas coisas e aborta se a conexão
efetiva não for o SQLite temporário.

O binlog do MariaDB está **desligado** e o backup é diário, às 03:00 (`/backup-local/mysql/`, dump
de *todos* os bancos do servidor — extraia só o `fertwaysbd` antes de restaurar). Isso significa
**até 24 h de perda**. Se algum dado passar a importar, ligar o binlog é o primeiro passo.

## Leia também

- `docs/decisoes.md` — 37 decisões, com as divergências e lacunas do GDD. **A regra de ouro é
  não inventar valores.** Quando o GDD não decide, pergunte ao usuário e registre ali.
- `docs/deploy.md` — php84, Node, o symlink `/central`, e por que `route:cache` está proibido.
