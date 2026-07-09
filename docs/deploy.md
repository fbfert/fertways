# Deploy — servidor de produção

Ambiente atual: AlmaLinux 9.8 com Virtualmin, Apache + PHP-FPM, MariaDB. Domínio
`fertways.tars.art.br`, certificado Let's Encrypt já emitido pelo painel.

| O quê | Onde |
|---|---|
| Frontend (build estático) | `https://fertways.tars.art.br` → `/home/fertways/public_html` |
| Backend (API Laravel) | `https://fertways.tars.art.br/central` → symlink para `backend/public` |
| Código | `/home/fertways/apps/fertways` |
| Banco | MariaDB, base `fertwaysbd` |

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
└── central -> /home/fertways/apps/fertways/backend/public
```

O Apache **não canonicaliza o symlink**: serve por `/home/fertways/public_html/central/` e aplica o
`<Directory /home/fertways/public_html>` que o Virtualmin já gera (`AllowOverride All`,
`+SymLinksIfOwnerMatch`). Link e alvo pertencem ao usuário `fertways`, então o link é seguido.
Não é preciso nenhum arquivo extra em `/etc/httpd/conf.d/` — um que existiu ali era inerte.

O `SCRIPT_NAME` que chega ao PHP é `/central/index.php`, e o Symfony deduz dele que a aplicação está
montada em `/central`. Por isso `apiPrefix` é vazio (ver `docs/decisoes.md`, D-25).

Como o `.env` e o `vendor/` vivem **acima** de `public/`, não há rota até eles: `/central/.env`
devolve 404.

## Atenção: editar o código aqui é publicar

`public_html/central` é um symlink para `backend/public`, e não há build intermediário no PHP.
Qualquer arquivo salvo em `backend/` **entra no ar no próximo request**. Não existe janela entre
"escrevi" e "publiquei".

A consequência prática: **rode a migration antes de salvar o código que depende dela.** Salvar
primeiro deixa a produção quebrada no intervalo. Já aconteceu — a logística introduziu
`colonies.x`/`y`, e fundar colônia devolveu 500 até a migration rodar.

Se isso incomodar, a solução estrutural é separar o diretório de deploy do diretório de trabalho
(clonar o repo em outro lugar e apontar o symlink para lá), fazendo o deploy ser um `git pull`
explícito. Hoje não é assim.

## Passos de um deploy

```sh
cd /home/fertways/apps/fertways
git pull

# backend
cd backend
sudo -u fertways composer install --no-dev --optimize-autoloader
sudo -u fertways /usr/bin/php84 artisan migrate --force
sudo -u fertways /usr/bin/php84 artisan config:cache
sudo -u fertways /usr/bin/php84 artisan tinker --execute='echo config("app.debug") ? "DEBUG LIGADO" : "ok";'

# frontend
cd ../frontend
export PATH="/usr/local/lib/nodejs/node-v22.12.0-linux-x64/bin:$PATH"
npm ci && npm run build
cp -r dist/. /home/fertways/public_html/
chown -R fertways:fertways /home/fertways/public_html
```

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

## Tick de produção (cron)

Sem isto, recursos não acumulam e construções nunca terminam. No crontab do usuário `fertways`:

```cron
* * * * * /usr/bin/php84 /home/fertways/apps/fertways/backend/artisan schedule:run >> /home/fertways/logs/fertways-tick.log 2>&1
```

O `routes/console.php` já agenda `fertways:tick` a cada minuto. Para avançar o mundo à mão:

```sh
sudo -u fertways /usr/bin/php84 artisan fertways:tick
```

## Rodar a suíte no servidor

É seguro: o `phpunit.xml` neutraliza o config em cache e força SQLite em memória. Isso **não** é
detalhe cosmético — sem ele, `RefreshDatabase` mira o banco de produção. Ver D-27.

```sh
cd /home/fertways/apps/fertways/backend && /usr/bin/php84 artisan test
```
