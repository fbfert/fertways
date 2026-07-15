# CI (GitHub Actions)

Este documento descreve a validação automática que roda em `.github/workflows/ci.yml`. É
CI apenas — **não faz deploy, não toca no VPS, não toca no banco de produção**. Deploy
continua sendo `tools/deploy.sh`, rodado manualmente pelo usuário. Ver `docs/deploy.md`.

## Quando roda

- `pull_request` com destino `main`.
- `push` direto em `main`.
- `workflow_dispatch` (disparo manual pela aba Actions do GitHub).

Execuções da mesma branch/PR se cancelam entre si (`concurrency` com `cancel-in-progress:
true`) — só o run mais recente importa.

## Os três jobs

### 1. Backend / SQLite

Roda `php artisan test` exatamente como `backend/phpunit.xml` já manda: banco SQLite em
memória, caches de config/rotas/eventos apontando para arquivos inexistentes,
`FERTWAYS_MEDIA_DIR` numa pasta temporária. É o mesmo comando que já se roda localmente:

```bash
cd backend
composer validate --strict
composer install --prefer-dist --no-interaction --no-progress
php artisan test
```

Rápido, mas **não prova nada sobre MariaDB** — ver job 2.

### 2. Backend / MariaDB

Sobe um container de serviço `mariadb:10.5` e roda a suíte inteira contra ele, com um
banco descartável criado só para o CI (`fertways_ci`/`fertways_ci`, senha de root
`root` — credenciais efêmeras, existem só durante o run e não têm nenhuma relação com
`fertwaysbd` nem `fertwaysdev`, os bancos reais do VPS).

Por quê um job separado de MariaDB, se o SQLite já roda a mesma suíte? Porque SQLite já
escondeu bug real antes: o D-08 documenta três comportamentos de `TIMESTAMP` sob
`STRICT_TRANS_TABLES` que só MariaDB reclamava — o SQLite aceitou o schema quebrado sem
avisar. A produção roda MariaDB 10.5.29 com `sql_mode` estrito por padrão de fábrica
(sem override em `/etc/my.cnf.d`); o CI usa `mariadb:10.5` porque não existe imagem
oficial fixada no patch exato `10.5.29` — é a mesma família, os mesmos defaults de
`sql_mode`/`explicit_defaults_for_timestamp`. Se a Distro trocar de minor no VPS, isto
deve ser revisto.

Passos, nesta ordem:

1. Sobe o serviço MariaDB e aguarda `mariadb-admin ping` responder.
2. **Antes de qualquer comando destrutivo**, confere mecanicamente qual é a conexão
   efetiva do Laravel (`config('database.default')`, o banco e o host resolvidos em
   runtime) — se não for exatamente `mysql` / `fertways_ci` / `127.0.0.1`, o job aborta
   sem rodar `migrate:fresh`. Isto existe porque `backend/phpunit.xml` já define as
   mesmas variáveis (`DB_CONNECTION`, `DB_DATABASE`...) para SQLite; a checagem garante
   que as variáveis de ambiente do job realmente sobrescreveram o XML antes de destruir
   qualquer coisa. (As tags `<env>` do `phpunit.xml` não têm `force="true"` — variável de
   ambiente externa vence, mas isto agora é verificado, não só assumido.)
3. Prova que as migrations fazem o caminho de ida e volta: `migrate:fresh --force`,
   depois `migrate:rollback --force`, depois `migrate --force`. Se o rollback não
   desfizer o batch ou o migrate seguinte não recriar o schema, o job falha aqui.
4. `php artisan test` contra o MariaDB efêmero.

Localmente, o equivalente exige um MariaDB rodando à parte (o servidor de
desenvolvimento já tem um, mas **nunca aponte para `fertwaysdev` para isto** — use um
banco descartável).

### 3. Frontend / Lint e build

```bash
cd frontend
npm ci
npm run lint      # oxlint
npm run build     # tsc -b && vite build — o typecheck roda de verdade, não só tsc --noEmit
```

Não gera preview, não copia nada para `public_html`, não é acessível fora do run do
Actions.

## O que a CI não faz (de propósito)

- **Não roda `tools/e2e.sh`.** O script tem caminhos fixos do VPS (`/usr/bin/php84`,
  o Node instalado manualmente em `/usr/local/lib/nodejs/...`) que não existem num
  runner do GitHub. `frontend/e2e/comum.mjs` já lê `E2E_CHROMIUM` de variável de
  ambiente, mas o binário PHP e o PATH do Node ainda estão hardcoded no script. Fase
  futura: parametrizar `tools/e2e.sh` (algo como `PHP_BINARY`, mais o `PATH` normal do
  runner) para que ele rode tanto no VPS quanto num runner `ubuntu-latest`. Até lá, e2e
  continua sendo responsabilidade de quem revisa rodar localmente, como hoje.
- **Não faz deploy.** `tools/deploy.sh` não é chamado em nenhum job.
- **Não usa runner self-hosted, não acessa o VPS, não usa segredo nenhum de produção.**
  As únicas credenciais no workflow são o banco `fertways_ci` (efêmero) e uma
  `APP_KEY` fixa de teste — nenhuma delas protege nada real.
- Permissão do workflow é só `contents: read`.

## Diagnosticando um run vermelho

1. Veja qual job falhou — o nome já diz a camada (SQLite, MariaDB, ou frontend).
2. **Backend / SQLite vermelho, Backend / MariaDB verde (ou vice-versa):** é sinal de
   diferença de banco — provavelmente algo como o D-08 (TIMESTAMP/strict mode) ou uma
   constraint que só um dos dois motores checa. Não é flake: reproduza local com o
   comando da seção do job que falhou.
3. **Passo "Verifica a conexão efetiva" falhou:** a variável de ambiente não chegou até
   o Laravel do jeito esperado — normalmente sinal de mudança no `phpunit.xml` (ex.:
   alguém adicionou `force="true"` numa tag `<env>`) ou na config de `database.php`.
4. **Passo do round-trip de migrations falhou:** uma migration nova não é reversível
   (o `down()` não desfaz o que o `up()` fez), ou depende de dado que só existe depois
   de outra migration rodar fora de ordem.
5. **Frontend vermelho no `npm run lint`:** rode `npm run lint` local, o oxlint aponta
   arquivo e linha.
6. **Frontend vermelho no `npm run build`:** normalmente é erro de tipo do TypeScript —
   `tsc -b` já roda antes do Vite, então o erro aparece antes de qualquer coisa do Vite.
7. Se nada disso bate e o run falhou sem motivo aparente (ex.: timeout esperando o
   MariaDB subir), dispare de novo via `workflow_dispatch` antes de investigar mais —
   pode ser variação do runner hospedado pelo GitHub, não do código.
