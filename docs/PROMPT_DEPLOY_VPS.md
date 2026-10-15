# Prompt de deploy na VPS — lote D-253 a D-262

> Cole o bloco abaixo numa sessão do Claude Code **aberta no servidor**, em
> `/home/fertways/apps/fertways`. Ele foi escrito em 15/10/2026, numa máquina Windows sem acesso à
> VPS; tudo o que está aqui foi testado só contra SQLite local.

---

```text
Vamos publicar em produção o lote que está no GitHub e ainda não foi ao ar: do D-253 ao D-262
(commit d14f7a0 ou posterior em main). Leia docs/deploy.md e docs/RETOMAR.md antes de começar, e
siga a ordem abaixo. Pare e me pergunte se qualquer conferência falhar — não contorne.

CONTEXTO
- O lote foi escrito numa máquina Windows, contra SQLite local. As migrations foram exercitadas nos
  dois sentidos SÓ no SQLite. Nada disto rodou ainda no MariaDB.
- Sete migrations pendentes em produção:
    2026_10_07_400000_eventos_de_federacao_encadeados_e_por_condicao  (D-253; já exercitada no fertwaysdev)
    2026_10_15_100000_observatorio_pela_ficha_do_v35                    (D-256)
    2026_10_15_200000_escavacao_com_os_numeros_de_partida               (D-256)
    2026_10_15_300000_upgrade_de_veiculo_leva_tempo                     (D-259: amplia o ENUM vehicles.status)
    2026_10_15_400000_tropa_consome_operadores                          (D-260)
    2026_10_15_500000_revisao_de_pares_de_guerra                        (D-261)
    2026_10_15_600000_espacoporto_e_planetas_npc                        (D-262)
- Lembretes do docs/deploy.md: use /usr/bin/php84 (o php do PATH é 8.2); rode artisan como
  `sudo -u fertways`; a árvore de trabalho (apps/) fala com o banco fertwaysdev e NUNCA roda
  config:cache; nunca rode route:cache.

PASSO 1 — Trazer o código para a árvore de trabalho
- cd /home/fertways/apps/fertways
- git status precisa estar limpo; se não estiver, pare e me mostre.
- git pull --ff-only origin main, e confira com git log --oneline -12 que d14f7a0 está lá.
- cd backend && sudo -u fertways /usr/bin/php84 /bin/composer install --no-interaction

PASSO 2 — A suíte, no servidor
- sudo -u fertways /usr/bin/php84 artisan test   (o phpunit.xml força SQLite em memória; é seguro)
- Esperado: 1446 testes verdes. Qualquer vermelho: pare e me mostre a saída.
- Frontend: export PATH="/usr/local/lib/nodejs/node-v22.12.0-linux-x64/bin:$PATH";
  cd ../frontend && npm ci && npm run build   (o build roda tsc -b; precisa sair limpo)

PASSO 3 — As migrations no MariaDB de desenvolvimento (fertwaysdev), nos dois sentidos
- Antes, CONFIRA que o artisan da árvore de trabalho fala com fertwaysdev, e não com fertwaysbd:
    sudo -u fertways /usr/bin/php84 artisan tinker --execute='echo DB::connection()->getDatabaseName();'
  Se não disser fertwaysdev, PARE.
- sudo -u fertways /usr/bin/php84 artisan migrate:status | tail -12   (anote as pendentes)
- sudo -u fertways /usr/bin/php84 artisan migrate
- sudo -u fertways /usr/bin/php84 artisan migrate:rollback --step=7
- sudo -u fertways /usr/bin/php84 artisan migrate
- As sete têm de ir e voltar sem erro. Atenções conhecidas:
    * D-259 muda um ENUM com ->change(): é o ponto mais provável de diferença MariaDB × SQLite.
      Depois do migrate, confira: SHOW COLUMNS FROM vehicles LIKE 'status'; — tem de listar em_upgrade.
    * os downs do D-256 (escavação) e do D-262 (espaçoporto) RECUSAM de propósito se houver peça do
      lote já achada ou cargueiro em viagem; no fertwaysdev não deve haver.
    * D-256 (observatório) só sobe o teto de vagas de 3 para 4 se ele estiver em 3.
  Se alguma falhar, pare e me mostre o erro — não edite a migration sem me perguntar.

PASSO 4 — Regerar o GDD v41 contra o fertwaysdev
- O que está no repositório foi gerado contra SQLite local (valores do operador nos defaults).
- cd /home/fertways/apps/fertways
- sudo -u fertways /usr/bin/php84 tools/gdd-v41.php > /tmp/gdd-v41.html
- ⚠️ O gerador pode sair com código 0 e ter um erro fatal impresso DENTRO do HTML. Confira:
    grep -c "Fatal\|Warning\|Exception\|▕" /tmp/gdd-v41.html   → tem de ser 0
    tail -c 200 /tmp/gdd-v41.html                              → tem de terminar em </html>
- cp /tmp/gdd-v41.html docs/FERTWAYS_GDD_v41_CONSOLIDADO.html
- cp docs/FERTWAYS_GDD_v41_CONSOLIDADO.html frontend/public/gdd.html
- Se mudou, commit ("D-254: GDD v41 regerado contra o fertwaysdev") e push. O deploy publica o que
  está commitado em main.

PASSO 5 — O deploy
- sudo ./tools/deploy.sh
- Confira na saída, uma por uma: backup conferido (gzip, tamanho, tabela colonies); "banco
  conferido: a aplicação fala com [fertwaysbd]"; as sete migrations DONE; APP_DEBUG off; opcache
  conferido; bundle conferido no ar; fumaça front 200 e /central/colony 401.
- Se o script abortar, ele já tira da manutenção. Me mostre a saída inteira e não tente de novo
  sem eu dizer.

PASSO 6 — Conferir a produção (só leitura, na cópia de deploy)
Rode como fertways, em /home/fertways/deploy/fertways/backend, com /usr/bin/php84 artisan tinker,
e me mostre o resultado de cada um:
- DB::table('endurance_escavacao_settings')->first()
    → se estava intocada, agora está ativo=1, duracao_minutos=360, custo {__fert__:15000000, energia:300}.
      Se o operador já tinha mexido no painel, ela fica como estava — me diga qual dos dois aconteceu.
- DB::table('endurance_items')->where('origem','escavacao')->count()        → 6 (o lote do D-256)
- DB::table('building_specs')->where('building_type','observatorio')->where('level',1)->first()
    → build_time_seconds 2700, energia_consumo_hora 30, custo com ligas 140
- DB::table('research_settings')->first()                                   → vagas_teto 4 (se estava 3)
- DB::table('war_settings')->value('operadores_por_unidade')                → 1
- DB::table('planetas_npc')->orderBy('ordem')->pluck('aberto','nome')       → os 5, todos fechados
- DB::table('espacoporto_settings')->first()                                → ativo 0, números nulos
- DB::table('population_settings')->value('ativo')
    → ⚠️ se for 1, a regra do D-260 vale já: atacar exige colonos livres para a tropa.

PASSO 7 — O que MUDA no jogo com este deploy (para eu saber o que esperar)
- O cerco de colônia passa a acontecer de verdade (D-255): até aqui nenhuma marcha pela tela era
  aceita. É o primeiro deploy em que uma guerra federativa pode saquear uma colônia.
- A escavação da Endurance liga, com os números aprovados (D-256), se o painel estava intocado.
- O Observatório fica mais caro e passa a dar vaga desde o nível 1; exige Laboratório nível 3 para
  ERGUER (os já erguidos continuam de pé).
- Upgrade de veículo leva 1 h por nível alvo (D-259); zero no painel volta ao instantâneo.
- O painel ganha: População/estoque/pesquisa (Operação), upgrade (Transportes), os parâmetros da
  guerra federativa e o relatório de pares (Guerra), e a aba Espaçoporto (Transportes).
- O Espaçoporto aparece na Capital, FECHADO, dizendo o que falta.

PASSO 8 — Fechar
- NÃO rode o EnduranceItemSeeder: o lote de escavação entrou pela migration, e não há o que semear.
- Atualize docs/RETOMAR.md: risque o deploy pendente do D-253 a D-262, anote o sha publicado, o
  backup tirado, e o resultado do passo 6. Commit e push.
- Me entregue um resumo curto: sha no ar, migrations DONE, backup, e qualquer coisa que tenha saído
  diferente do esperado.
```
