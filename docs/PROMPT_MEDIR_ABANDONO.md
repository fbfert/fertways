# Prompt para medir o abandono na produção (D-264) — sem deploy

> ⚠️ **Não rode agora (D-265).** Todas as contas humanas do servidor são do próprio usuário ou bots: não há abandono para medir. Para o primeiro teste com pessoas de verdade, use `docs/PLAYTEST.md` (D-267): depois do deploy, o relatório roda direto na cópia de deploy, com as contas da equipe já marcadas.

> Cole o bloco abaixo numa sessão do Claude Code **aberta no servidor**, em
> `/home/fertways/apps/fertways`. Ele **não publica nada** e **não escreve em banco nenhum**: traz o
> código novo para a árvore de trabalho e roda, dali, um comando só de leitura contra a produção.
> Traga a saída de volta para a conversa no Windows.

---

```text
Quero medir onde os jogadores param, na PRODUÇÃO, sem fazer deploy. Leia docs/deploy.md antes —
principalmente a parte dos dois bancos (D-46) e do D-214. Nada aqui pode escrever em banco.

PASSO 1 — Trazer o código, sem publicar
- cd /home/fertways/apps/fertways; git status limpo; git pull --ff-only origin main.
- cd backend && sudo -u fertways /usr/bin/php84 /bin/composer install --no-interaction
- Isto mexe só na árvore de trabalho, que não é servida (D-39). NÃO rode deploy.sh.

PASSO 2 — Rodar o relatório contra o fertwaysbd, só leitura
- O comando é `fertways:retencao` (D-264). Ele só faz SELECT, e a primeira linha que ele imprime é o
  banco com que está falando.
- A árvore de trabalho aponta o fertwaysdev. Para ler a produção SEM editar nenhum .env, passe o banco
  por variável de ambiente, só nesta execução:
    sudo -u fertways env DB_DATABASE=fertwaysbd /usr/bin/php84 artisan fertways:retencao
  (a árvore de trabalho não tem config em cache — por isso a variável vale. NÃO rode config:cache.)
- ⚠️ A primeira linha TEM de dizer "Banco: fertwaysbd". Se disser outra coisa, pare.
- Se der "access denied": o usuário de banco do .env da árvore de trabalho não lê a produção. Então use
  as credenciais da cópia de deploy, também só por variável e só nesta execução:
    sed -n 's/^DB_USERNAME=//p;s/^DB_PASSWORD=//p' /home/fertways/deploy/fertways/backend/.env
  e rode com env DB_DATABASE=fertwaysbd DB_USERNAME=... DB_PASSWORD=... — sem gravar em arquivo
  nenhum, e sem colar a senha na conversa.
- Rode também com --json e guarde a saída:
    sudo -u fertways env DB_DATABASE=fertwaysbd /usr/bin/php84 artisan fertways:retencao --json

PASSO 3 — Três perguntas que o relatório não responde sozinho (só leitura, mysql no fertwaysbd)
- Quantos cadastros humanos houve depois de 17/07, e quantos deles fundaram colônia?
    SELECT COUNT(*) AS cadastros,
           SUM(EXISTS(SELECT 1 FROM colonies c WHERE c.user_id = u.id)) AS fundaram
    FROM users u
    WHERE u.created_at >= '2026-07-17'
      AND u.email NOT LIKE '%@bots.fertways.local' AND u.email NOT LIKE '%@fertways.sistema';
- De quem foi embora, quanto tempo levou da fundação até o último ato de XP — e qual foi esse ato?
  (o relatório dá o agregado; isto dá a lista, SEM e-mail nem nome — só id, datas e ato):
    SELECT c.user_id, c.founded_at, MAX(x.created_at) AS ultimo_ato,
           SUBSTRING_INDEX(GROUP_CONCAT(x.acao ORDER BY x.id DESC), ',', 1) AS qual
    FROM colonies c JOIN users u ON u.id = c.user_id LEFT JOIN xp_entries x ON x.colony_id = c.id
    WHERE u.email NOT LIKE '%@bots.fertways.local' AND u.email NOT LIKE '%@fertways.sistema'
    GROUP BY c.user_id, c.founded_at ORDER BY ultimo_ato;
- O que os humanos tinham nas mãos quando pararam: missões ativas, fila de obra, recursos zerados?
    SELECT c.user_id,
           (SELECT COUNT(*) FROM mission_assignments m WHERE m.colony_id = c.id AND m.status = 'ativa') AS missoes_ativas,
           (SELECT COUNT(*) FROM build_queue q WHERE q.colony_id = c.id AND q.status IN ('queued','building')) AS obras_na_fila,
           c.fert_micro / 1000000 AS fert
    FROM colonies c JOIN users u ON u.id = c.user_id
    WHERE u.email NOT LIKE '%@bots.fertways.local' AND u.email NOT LIKE '%@fertways.sistema';
  Se algum nome de coluna ou status não existir, ajuste lendo o schema — e me diga o que mudou.

PASSO 4 — Devolver
- NÃO commite nada (não houve mudança de código). Não mexa no RETOMAR.
- Me entregue: a saída do PASSO 2 (a tabela e o --json) e o resultado das três consultas do PASSO 3.
  Nada de e-mail, nome ou nickname de jogador na resposta — só ids e números.
```
