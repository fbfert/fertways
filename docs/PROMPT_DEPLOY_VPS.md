# Prompt de deploy na VPS — lote D-279 a D-286

> Cole o bloco abaixo numa sessão do Claude Code **aberta no servidor**, em
> `/home/fertways/apps/fertways`. O lote anterior (D-263 a D-278) está no ar desde `d7245ad`.
> Este lote foi escrito numa máquina Windows e testado contra SQLite e contra um MariaDB 10.5.29 local
> (container), a mesma versão da produção — a suíte inteira e o round-trip das migrations.

---

```text
Vamos publicar em produção o lote D-279 a D-286:
- D-279: a guarda de arbitragem do D-278 também no painel, e os 2 testes do SQLite 3.34.1 corrigidos;
- D-280: escolta (Sentinelas da colônia) e seguro de carga no Espaçoporto;
- D-281: a Nave de Transporte Planetária e as duas Plataformas de Pouso;
- D-282: as Missões de Reconhecimento e o Telescópio Gagarin;
- D-283 a D-285: a revisão completa do jogo — economia (demanda finita dos planetas, um Cargueiro
  por colônia, o XP de obra que só paga crescimento), bugs e segurança, e a primeira sessão do novato
  (o resumo "nada foi produzido" que mentia para todo mundo, validação em português);
- D-286: o tick medido com 1000 colônias — menos consultas por colônia e dois índices.
Leia docs/deploy.md, docs/RETOMAR.md e as entradas D-279 a D-286 de docs/decisoes.md antes. Siga a
ordem abaixo e pare para me perguntar se qualquer conferência falhar. Não contorne.

CONTEXTO
- Cinco migrations pendentes:
    2026_10_17_100000_escolta_e_seguro_de_carga     (D-280: colunas novas; números de partida)
    2026_10_17_200000_nave_de_transporte_planetaria (D-281: uma linha em fabrica_veiculos)
    2026_10_17_300000_missoes_de_reconhecimento     (D-282: seis tabelas novas, as oito luas, 2 colunas em planetas_npc)
    2026_10_17_400000_revisao_do_jogo               (D-283/D-284/D-285: demanda dos planetas, níveis de obra pagos,
                                                     teto de XP de Acordo, perda travada, producao_por_hora)
    2026_10_17_500000_indices_do_tick               (D-286: dois índices; em chat_messages pode levar alguns
                                                     segundos se a tabela for grande)
- ⚠️ D-283: cada planeta passa a comprar no máximo 100 F$ por dia de missão, somando o servidor. Hoje
  Auryn compra Componentes sem limite (~8.900 F$ por viagem); depois do deploy, não. É de propósito.
- ⚠️ D-285: a validação passa a falar português. O .env da cópia de deploy precisa de
  APP_LOCALE=pt_BR (passo 4).
- ⚠️ O Gagarin ativa SOZINHO no tick com 50 jogadores (sem bots e sem contas de sistema) ou 45 dias de
  servidor. Sem `servidor_aberto_em` no painel, a abertura é a fundação da primeira colônia. Se a
  primeira colônia da produção tem mais de 45 dias, o Gagarin ATIVA NO PRIMEIRO TICK depois do deploy,
  publica a notícia de ativação e abre a leitura da primeira lua (Armstrong). Confira no passo 3.
- ⚠️ A Nave entra na prateleira do Ministério (prateleira 2), e a fábrica consome do Tesouro 250 Ligas,
  120 Componentes e 45 Metal Bruto por unidade. Se o Tesouro não tiver, a prateleira fica vazia — não é
  erro.
- Lembretes: /usr/bin/php84; artisan como `sudo -u fertways`; a árvore de trabalho fala com o
  fertwaysdev e pede --force no migrate; nunca config:cache nem route:cache nela.

PASSO 1 — Código e suíte
- cd /home/fertways/apps/fertways; git status limpo; git pull --ff-only origin main.
- git log --oneline -1 → 79d49d5 ("D-283 a D-286: a revisão completa do jogo") ou um commit de docs
  posterior. Se o HEAD for anterior a 79d49d5, PARE.
- cd backend && sudo -u fertways /usr/bin/php84 /bin/composer install --no-interaction
- sudo -u fertways /usr/bin/php84 artisan test   → esperado: 1637 verdes, NENHUM vermelho.
- Frontend: export PATH="/usr/local/lib/nodejs/node-v22.12.0-linux-x64/bin:$PATH";
  cd ../frontend && npm ci && npm run build   → limpo.

PASSO 2 — As cinco migrations no fertwaysdev, nos dois sentidos
- Confirme que o artisan da árvore de trabalho fala com fertwaysdev. Se não, PARE.
- migrate --force; migrate:rollback --step=5 --force; migrate --force.
- Depois: DB::table('luas')->count() → 8; DB::table('gagarin_settings')->first();
  DB::table('fabrica_veiculos')->where('tipo','nave_de_transporte_planetaria')->exists() → true;
  DB::table('planetas_npc')->pluck('demanda_diaria_micro','nome') → 100000000 em todos;
  Schema::hasIndex('units',['status','arrives_at']) e Schema::hasIndex('chat_messages',['channel','created_at']) → true.

PASSO 3 — A primeira colônia e o Gagarin (só leitura, na PRODUÇÃO, antes do deploy)
- DB::table('colonies')->min('created_at') e quantos dias faz;
- quantos usuários humanos:
    DB::table('users')->where('email','not like','%@bots.fertways.local')->where('email','not like','%@fertways.sistema')->count()
- DB::table('chat_messages')->count() (o índice do D-286 é criado sobre ela; acima de 1 milhão, me
  avise antes do deploy).
- Me mostre os números e diga se o Gagarin vai ativar no primeiro tick. Se for ativar, PARE e me
  pergunte: ou deixamos ativar, ou eu ponho uma data de abertura no painel depois do deploy.

PASSO 4 — Deploy
- No .env da cópia de deploy: APP_LOCALE=pt_BR (se estiver en, troque; mostre-me a linha antes e depois).
- sudo ./tools/deploy.sh
- Confira: backup conferido; banco [fertwaysbd]; as cinco migrations DONE; APP_DEBUG off; opcache;
  bundle; fumaça 200/401; /central/estatisticas → 200 com x-ratelimit-limit: 600.

PASSO 5 — Conferir (só leitura)
- /admin/reconhecimento e /admin/transportes?aba=espacoporto abrem com 200.
- DB::table('espacoporto_settings')->first(['seguro_carregamento_bps','escolta_poder_pleno','escolta_reducao_max_bps'])
  → 2500, 800, 7500.
- D-283, ninguém ganhou nem perdeu XP: compare, em 5 colônias quaisquer, colonies.niveis_de_obra_pagos
  com a soma de buildings.level — têm de ser iguais.
- D-283: DB::table('milestone_settings')->value('xp_acordo_teto_diario') → 3.
- D-285: um POST na rota de cadastro (a mesma do frontend, `/register` da API) com apelido inválido e sem senha responde a mensagem em português (só leia a
  resposta; não crie conta).
- tail -n 50 backend/storage/logs/laravel.log da cópia de deploy → nada novo depois do deploy.
- Um minuto depois do deploy: DB::table('gagarin_settings')->value('ativado_em') e
  DB::table('missoes_de_reconhecimento')->count() — e me diga se bate com o que o passo 3 previu.

PASSO 5b — GDD v41 regenerado contra o fertwaysdev (já migrado no passo 2)
- O HTML publicado está atrás do código: ainda diz que a Plataforma de Pouso só consome energia.
- sudo -u fertways /usr/bin/php84 tools/gdd-v41.php > /tmp/gdd-v41.html
- grep -c "Fatal\|Warning\|Exception\|▕" /tmp/gdd-v41.html → 0; tail termina em </html>.
- cp para docs/FERTWAYS_GDD_v41_CONSOLIDADO.html e frontend/public/gdd.html; commit e push.

PASSO 6 — Fechar
- Atualize docs/RETOMAR.md (lote D-279 a D-286 no ar, sha, backup, resultado dos passos 3 e 5). Commit e push.
- Me entregue um resumo curto. Lembre-me de que o Atendente do Espaçoporto só existe depois de
  nomeado: `fertways:cargo-civico <nick> atendente_espacoporto --nomear`.
```
