# FERTWAYS — Restaurar o banco a partir de um backup

**Ensaiado de ponta a ponta em 2026-08-04** (D-208). Antes disso havia oito backups e nenhum jamais
restaurado — *backup que ninguém restaurou é hipótese.* Este documento é o que o ensaio provou, com as
quatro armadilhas que ele encontrou.

---

## O que existe

| origem | onde | retenção | conteúdo |
|---|---|---|---|
| **diário, 03:00** | `/backup-local/mysql/all-databases-<dia>-0300.sql.gz` | **2** (`KEEP_BACKUPS`) | **todos** os bancos do servidor |
| **cópia externa** | Google Drive, `prof.felipe.fert:Backups-VPS/server.tars.art.br/mysql` | 3 dias observados | idem, bytes idênticos |
| **manual, antes de fase** | `/home/fertways/backups/fertwaysbd-antes-*.sql.gz` | manual | só `fertwaysbd` |

O script é `/root/backup-diario-vps.sh` (cron do root). Ele passa `--master-data=2`, que grava no topo
do dump a posição do binlog:

    -- CHANGE MASTER TO MASTER_LOG_FILE='mysql-bin.000065', MASTER_LOG_POS=28540974;

É a âncora da recuperação **pontual**: restaura-se o dump e reaplica-se o binlog dali em diante para
recuperar até o instante da falha, em vez de até as 03:00.

---

## Caso 1 — desastre: restaurar o servidor inteiro

Este é o caminho para o qual o dump foi feito, e o único que não precisa de cirurgia:

```bash
zcat /backup-local/mysql/all-databases-2026-08-04-0300.sql.gz | mysql
```

O arquivo traz `CREATE DATABASE` e `USE` de cada banco, e o preâmbulo que desliga as conferências.
**Não** extraia nada: é justamente a extração que quebra.

---

## Caso 2 — só o FERTWAYS, ao lado da produção (o ensaio)

O que se quer quase sempre: olhar o passado sem tocar no presente. Aqui **cada passo tem uma
armadilha**, e todas foram encontradas na prática.

```bash
S=/tmp/restauracao                  # fora da árvore de deploy: nada novo no git
mkdir -p $S

FONTE=/backup-local/mysql/all-databases-2026-08-04-0300.sql.gz
ALVO=fertways_restore_teste

# 1. o preâmbulo da SESSÃO — fica ANTES do primeiro banco
zcat $FONTE | awk 'NR<30' > $S/preambulo.sql

# 2. só a seção do fertwaysbd (o marcador seguinte fecha)
zcat $FONTE | awk '/^-- Current Database: `fertwaysbd`/{f=1}
                   f && /^-- Current Database: `fertwaysdev`/{f=0} f' > $S/secao.sql

# 3. ⚠️ TIRAR o que redireciona para produção — com awk, NUNCA com grep
awk '!(/^USE `fertwaysbd`;$/ || /^CREATE DATABASE .*`fertwaysbd`/)' $S/secao.sql > $S/seguro.sql

# 4. o rodapé, que restaura as conferências
zcat $FONTE | tail -12 > $S/rodape.sql

cat $S/preambulo.sql $S/seguro.sql $S/rodape.sql > $S/restaurar.sql

# 5. CONFERIR antes de executar. Tem de imprimir 0.
awk '/^USE |^CREATE DATABASE /' $S/restaurar.sql | wc -l

mysql -e "DROP DATABASE IF EXISTS $ALVO; CREATE DATABASE $ALVO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql $ALVO < $S/restaurar.sql
```

### ⚠️ Armadilha 1 — o `USE` leva o restore para a produção

A seção extraída contém `CREATE DATABASE fertwaysbd` e **dois** `USE \`fertwaysbd\`;`. Despejá-la em
`mysql fertways_restore_teste` **ignora o alvo da linha de comando** e escreve na produção. O passo 3
existe só por isso, e o passo 5 é a conferência que impede o engano de passar.

### ⚠️ Armadilha 2 — `grep -v` trunca linhas longas, em silêncio

Filtrar com `grep -vE ...` derrubou **2 MB** de um arquivo de 5 MB ao remover três linhas de 160
bytes. O dump tem linhas de até **1 MB** (INSERTs em bloco), e o `grep` as cortou sem dizer nada — o
resultado parecia um SQL válido, restaurava sem erro e vinha pela metade.

**Confira sempre a aritmética:** `bytes_originais − bytes_removidos == bytes_finais`. Com `awk` a conta
fecha exatamente.

### ⚠️ Armadilha 3 — sem o preâmbulo, morre na primeira chave estrangeira

`FOREIGN_KEY_CHECKS=0` está no cabeçalho do arquivo **inteiro**, antes do primeiro banco. Uma seção
extraída sozinha falha na primeira tabela com FK, porque as tabelas vêm em ordem alfabética e
`auctions` referencia `colonies`:

    ERROR 1005 (HY000): Can't create table `auctions` (errno: 150 "Foreign key constraint is
    incorrectly formed")

É o passo 1, e é a razão de ele existir.

---

## ⚠️ Restaurar NÃO devolve o jogo — falta migrar

O backup é do estado do banco naquele dia; o código no ar é o de hoje. A aplicação **reprova** contra
um backup mais antigo que a última migration:

    SQLSTATE[42S22]: Column not found: 1054 Unknown column 'rating_guerra'

O procedimento completo é **restaurar → migrar → servir**:

```bash
cd /home/fertways/deploy/fertways/backend
export APP_CONFIG_CACHE=$PWD/bootstrap/cache/nao-existe.php   # ⚠️ ver a armadilha 4
export DB_DATABASE=fertways_restore_teste
/usr/bin/php84 artisan migrate --force
```

### ⚠️ Armadilha 4 — `DB_DATABASE=` não vence o cache de config

`env()` do Laravel é ignorada quando existe `bootstrap/cache/config.php`, que a árvore de deploy
**tem**. No ensaio, o primeiro teste "contra o backup" rodou contra a **produção** e devolveu números
plausíveis — foi leitura apenas, mas não provava nada.

**Confirme o banco antes de qualquer conclusão:**

```bash
/usr/bin/php84 artisan tinker --execute='echo DB::selectOne("SELECT DATABASE() d")->d;'
```

É a mesma proteção que o `tools/e2e.sh` usa desde o D-27, e pelo mesmo motivo.

### E o usuário da aplicação não enxerga banco novo

`fertways@localhost` não tem permissão num banco recém-criado:

    ERROR 1044: Access denied for user 'fertways'@'localhost' to database 'fertways_restore_teste'

```bash
mysql -e "GRANT ALL PRIVILEGES ON fertways_restore_teste.* TO 'fertways'@'localhost'; FLUSH PRIVILEGES;"
```

Num desastre de verdade isso não aparece — restaura-se **sobre** `fertwaysbd`, onde a permissão já
existe.

---

## Limpeza, e ela não é opcional

```bash
mysql -e "REVOKE ALL PRIVILEGES ON fertways_restore_teste.* FROM 'fertways'@'localhost';
          DROP DATABASE fertways_restore_teste; FLUSH PRIVILEGES;"
rm -rf /tmp/restauracao
```

Os arquivos intermediários são um **dump da produção em texto plano**. Deixá-los em `/tmp` é deixar
todos os dados do jogo legíveis por quem tiver a máquina.

---

## O que o ensaio mediu

| | |
|---|---|
| integridade (`gzip -t`) dos 10 arquivos | **10/10 OK** |
| restauração do backup manual mais recente | **4,2 s** |
| restauração da seção `fertwaysbd` do diário | **4,6 s** |
| tabelas restauradas × produção | 88 × 89 (a nova é do deploy do mesmo dia) |
| colônias · usuários · construções · recursos · zonas | **29 · 33 · 457 · 754 · 77 — idênticos** |
| `ledger` | 35.086 × 35.198 (o jogo andou desde as 03:00) |
| aplicação servindo o restaurado, depois de migrar | **sim** — ranking, teto de estoque e saque lidos |

⚠️ **Buraco encontrado e não fechado:** o backup manual mais recente é de **2026-08-03 17:52**, antes
do cerco de colônia. As fases D-205, D-206 e D-207 subiram **sem backup manual prévio**. O diário das
03:00 cobre, mas com até 24 h de perda — e o hábito de tirar um antes de cada fase se perdeu sem que
nada avisasse.
