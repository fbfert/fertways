# Deploy — servidor de produção

Ambiente atual: AlmaLinux 9.8 com Virtualmin, Apache + PHP-FPM, MariaDB. Domínio
`fertways.tars.art.br`, certificado Let's Encrypt já emitido pelo painel.

| O quê | Onde |
|---|---|
| Frontend (build estático) | `https://fertways.tars.art.br` → `/home/fertways/public_html` |
| Backend (API Laravel) | `https://fertways.tars.art.br/central` → symlink para `deploy/fertways/backend/public` |
| Código, onde se edita | `/home/fertways/apps/fertways` — **não é servido** |
| Código, o que está no ar | `/home/fertways/deploy/fertways` — clone; `origin` é o repo local |
| Banco | MariaDB, base `fertwaysbd` — **um só, compartilhado pelos dois** |

Desde o D-39 o deploy é explícito: **editar código não publica mais nada.** Publicar é
`sudo ./tools/deploy.sh`, que puxa `main` na cópia de deploy.

## PHP: use `php84`, não `php`

O `php` do `PATH` num shell não-interativo é **8.2**, e o `composer.lock` exige `>= 8.4.1`.
`php artisan` morre num fatal de *platform check* que parece o projeto quebrado, mas não é.

```sh
/usr/bin/php84 artisan test          # equivale a ~/bin/php, o symlink do domínio
```

O pool PHP-FPM do domínio já roda php84, como usuário `fertways`. Rode artisan como esse usuário
(`sudo -u fertways`) para não deixar arquivos root-owned em `storage/` e `bootstrap/cache/`.

## Node (só para buildar o front)

```sh
export PATH="/usr/local/lib/nodejs/node-v22.12.0-linux-x64/bin:$PATH"
```

A instalação de Node é um tarball manual por cima do RPM `nodejs-16`. **Nunca** use `dnf` para
mexer nela: `dnf remove nodejs` apagaria o symlink `/usr/bin/node` e quebraria tudo de uma vez.

O typecheck honesto é `npm run build` (que roda `tsc -b`), **não** `tsc --noEmit`: o projeto usa
`erasableSyntaxOnly`, e só o build reprova coisas como *parameter properties*.

## Como a montagem em `/central` funciona

```
/home/fertways/public_html/            <- DocumentRoot do domínio (build do front)
├── index.html, assets/                <- saída de `npm run build`
└── central -> /home/fertways/deploy/fertways/backend/public
```

O Apache **não canonicaliza o symlink**: serve por `/home/fertways/public_html/central/` e aplica o
`<Directory /home/fertways/public_html>` que o Virtualmin já gera (`AllowOverride All`,
`+SymLinksIfOwnerMatch`). Link e alvo pertencem ao usuário `fertways`, então o link é seguido.
Não é preciso nenhum arquivo extra em `/etc/httpd/conf.d/` — um que existiu ali era inerte.

O `SCRIPT_NAME` que chega ao PHP é `/central/index.php`, e o Symfony deduz dele que a aplicação está
montada em `/central`. Por isso `apiPrefix` é vazio (ver `docs/decisoes.md`, D-25).

Como o `.env` e o `vendor/` vivem **acima** de `public/`, não há rota até eles: `/central/.env`
devolve 404.

## Editar não publica mais (D-39)

Até 2026-07-09, `public_html/central` apontava para a **árvore de trabalho**, e qualquer arquivo
salvo em `backend/` entrava no ar no próximo request. Já quebrou a fundação de colônia: a logística
introduziu `colonies.x`/`y`, e fundar devolveu 500 até a migration rodar.

Hoje o symlink aponta para `/home/fertways/deploy/fertways`, um clone à parte. Edite à vontade em
`apps/fertways`: ninguém serve dali.

**Mas publicar continua sendo instantâneo _dentro_ da cópia de deploy** — o Apache serve o PHP
direto, sem build. Um `git pull` cru na cópia publicaria o código antes da migration, e a janela de
500 voltaria. Por isso **use `tools/deploy.sh`**, que fecha a porta com `artisan down` antes do pull
e só a reabre depois do `migrate`.

### O symlink não basta: recarregue o php-fpm (D-45)

Trocar o symlink **não muda o que está no ar**. Com `opcache.revalidate_path=0` — o padrão — o
opcache resolve o caminho que o Apache lhe entrega (`public_html/central/index.php`) uma única vez e
guarda o destino para sempre. Os workers do php-fpm continuam executando a árvore para onde o
symlink apontava **quando eles subiram**.

Entre 2026-07-09 21:18 e o D-45, isso deixou a produção rodando a árvore de trabalho — editar
publicava na hora, em silêncio — enquanto o cron do tick, que roda pelo CLI e não tem esse cache,
executava a cópia de deploy. Web e tick em commits diferentes, contra um banco só.

`tools/deploy.sh` agora roda `systemctl reload php84-php-fpm` e, depois, **pergunta ao opcache do
pool qual árvore ele está executando**, abortando se encontrar qualquer script sob
`/home/fertways/apps/`. A fumaça de `200`/`401` não serve para isso: passa igual nas duas árvores.

O master do php84 é compartilhado com os outros domínios do servidor. O reload é gracioso.

## Passos de um deploy

```sh
cd /home/fertways/apps/fertways
git commit ...          # o deploy publica o que está commitado em `main`, não o que está salvo
sudo ./tools/deploy.sh            # backend + frontend
sudo ./tools/deploy.sh --so-backend
sudo ./tools/deploy.sh --so-frontend
```

O script aborta se: o symlink não apontar para a cópia de deploy, a cópia tiver alteração local,
`APP_DEBUG` estiver ligado, o bundle no ar não for o recém-compilado, ou a fumaça final
(front 200, `/central/colony` 401) falhar. Se algo estourar no meio do backend, ele tira a
aplicação da manutenção antes de sair.

**Reverter a separação**, se precisar: os backups estão em `/home/fertways/deploy/.symlink-anterior`
e `/home/fertways/deploy/.crontab-anterior`. São uma linha cada — o symlink com `ln -sfn`, o cron
com `crontab -u fertways -`.

O tick é pulado enquanto a aplicação está em manutenção. É inofensivo: ele avança o mundo por delta
de tempo, então o minuto perdido volta no tick seguinte.

**Não rode `php artisan route:cache`.** Ele quebra a raiz da API quando a aplicação está montada num
subcaminho: `/central/` devolve 405 e `/central` devolve 404, enquanto as sub-rotas funcionam — o que
mascara o problema. Ver D-26.

## `.env` de produção

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://fertways.tars.art.br/central
```

`APP_DEBUG=true` em produção publica a página de erro do Laravel com o `.env` inteiro, incluindo
`DB_PASSWORD`, numa máquina que também serve mail e Virtualmin. Confira depois de todo `config:cache`.

O `.env` **não** é versionado. Um clone novo não o tem: copie-o da árvore de trabalho, modo 600,
dono `fertways`. E `storage/logs` também não vem no clone — crie-o, ou o Laravel morre no primeiro
log.

### Os bancos são dois (D-46)

`deploy/fertways/backend/.env` aponta para o MariaDB `fertwaysbd`, o banco do jogo.
`apps/fertways/backend/.env` aponta para `fertwaysdev`. Um `migrate:fresh` na árvore de trabalho já
não apaga a produção — o que aconteceu uma vez, no D-36.

A árvore de trabalho **não tem** `bootstrap/cache/config.php` e **não deve rodar `config:cache`**:
com a config cacheada o Laravel ignora `env()`, e exportar `DB_CONNECTION=sqlite` não redireciona
nada. Foi esse o mecanismo do D-36. Só a cópia de deploy tem config cacheada, gerada pelo
`deploy.sh`.

O banco separado é uma segunda trava, não um substituto: toda ferramenta destrutiva continua
precisando exportar `APP_CONFIG_CACHE` para um caminho inexistente **e** conferir a conexão efetiva
antes de rodar, como fazem `phpunit.xml` e `tools/e2e.sh`. Ver D-27, D-36 e D-46.

## Tick de produção (cron)

Sem isto, recursos não acumulam e construções nunca terminam. No crontab do usuário `fertways`:

```cron
* * * * * /usr/bin/php84 /home/fertways/deploy/fertways/backend/artisan schedule:run >> /home/fertways/logs/fertways-tick.log 2>&1
```

**É o `artisan` da cópia de deploy, não o da árvore de trabalho.** Se apontar para `apps/`, o mundo
passa a ser avançado por código não-commitado, mesmo com o Apache servindo a cópia. Foi repontado
junto com o symlink no D-39.

O `routes/console.php` já agenda `fertways:tick` a cada minuto. Para avançar o mundo à mão:

```sh
sudo -u fertways /usr/bin/php84 /home/fertways/deploy/fertways/backend/artisan fertways:tick
```

## Rodar a suíte no servidor

É seguro: o `phpunit.xml` neutraliza o config em cache e força SQLite em memória. Isso **não** é
detalhe cosmético — sem ele, `RefreshDatabase` mira o banco de produção. Ver D-27.

```sh
cd /home/fertways/apps/fertways/backend && /usr/bin/php84 artisan test
```
