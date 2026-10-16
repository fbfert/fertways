# Prompt de deploy na VPS — lote D-263 a D-275

> Cole o bloco abaixo numa sessão do Claude Code **aberta no servidor**, em
> `/home/fertways/apps/fertways`. O lote anterior (D-253 a D-262) já está no ar desde `fa1af6b`.
> Este lote foi escrito numa máquina Windows e testado só contra SQLite local.

---

```text
Vamos publicar em produção o lote D-263 a D-275: o Espaçoporto abre (D-263), a retenção no painel
(D-264), as primeiras horas do novato (D-266), a marca de conta da equipe (D-267), a revisão visual
(D-268), e as pendências do GDD — imposto, risco e portão por planeta com Solène e Drakmoor abrindo
(D-269), o Atendente do Espaçoporto (D-270), o aviso antecipado da Antena (D-271) e o tempo de
fabricação de unidades (D-272), a Endurance com identidade visual própria (D-274) e o relatório de batalha (D-275), os dois sem migration.
Leia docs/deploy.md e docs/RETOMAR.md antes. Siga a ordem abaixo e pare para me perguntar se
qualquer conferência falhar — não contorne.

CONTEXTO
- Seis migrations pendentes:
    2026_10_16_100000_espacoporto_com_os_numeros_de_partida   (D-263)
    2026_10_16_200000_tutoria_em_lingua_de_jogador            (D-266: troca texto só se ainda original)
    2026_10_16_300000_contas_da_equipe                        (D-267: users.equipe, nasce falso)
    2026_10_16_400000_carga_interplanetaria                   (D-269: risco, portão, imposto; abre Solène e Drakmoor)
    2026_10_16_500000_duvidas_do_espacoporto                  (D-270: tabela nova)
    2026_10_16_600000_fabricacao_de_unidades_leva_tempo       (D-272: war_settings, padrão 2 min)
- A 100000 chama o EspacoportoPartidaSeeder: configura o Cargueiro (ativo, 20 F$, 10.000/perna,
  2 cargueiros) SÓ se espacoporto_settings estiver vazio, e escreve preços SÓ nos planetas sem preço.
- A 400000 abre Solène e Drakmoor SÓ se eles têm preço e estão fechados, e sobe o Nióbio que algum
  planeta venda abaixo do preço do governo (war_settings.niobio_preco_micro) — o freio do exército.
- A 600000 liga o tempo de fabricação (2 min × nível, uma a uma). O down dela solta prontas as
  unidades que estiverem na linha de montagem.
- Lembretes: /usr/bin/php84; artisan como `sudo -u fertways`; a árvore de trabalho fala com o
  fertwaysdev e pede --force no migrate (APP_ENV=production no .env dela); nunca config:cache nela;
  nunca route:cache.

PASSO 1 — Código e suíte
- cd /home/fertways/apps/fertways; git status limpo; git pull --ff-only origin main.
- cd backend && sudo -u fertways /usr/bin/php84 /bin/composer install --no-interaction
- sudo -u fertways /usr/bin/php84 artisan test   → esperado: 1518 verdes.
- Frontend: export PATH="/usr/local/lib/nodejs/node-v22.12.0-linux-x64/bin:$PATH";
  cd ../frontend && npm ci && npm run build   → limpo.

PASSO 2 — As seis migrations no fertwaysdev, nos dois sentidos
- Confirme que o artisan da árvore de trabalho fala com fertwaysdev (tinker:
  DB::connection()->getDatabaseName()). Se não, PARE.
- migrate --force; migrate:rollback --step=6 --force; migrate --force.
- Depois: DB::table('planeta_npc_precos')->count() > 0;
  DB::table('planetas_npc')->pluck('aberto','chave') → os cinco abertos;
  DB::table('war_settings')->value('fabricacao_minutos_por_nivel') → 2.

PASSO 3 — ⚠️ A guarda que o painel não faz (antes do deploy)
- Um planeta paga 0,80× o preço de referência. Se o GOVERNO vender um recurso no Mercado Central
  abaixo disso, comprar do governo e revender ao planeta vira lucro garantido.
- Liste, na PRODUÇÃO (só leitura, na cópia de deploy, como fertways):
    DB::table('market_orders')->whereNull('colony_id')->where('side','sell')
      ->whereIn('status',['aberta','parcial'])
      ->join('resource_types','resource_types.code','=','market_orders.resource_type')
      ->get(['market_orders.resource_type','market_orders.price_micro','resource_types.preco_base_micro'])
  e me mostre, para cada recurso, price_micro / preco_base_micro.
- Se algum estiver ABAIXO de 0,80 (ou de 0,70 para os que só Auryn compra), PARE e me pergunte antes
  de publicar: ou subimos o preço do governo, ou tiramos aquele recurso do planeta.
- O imposto da carga (D-269) dá alguma folga a mais, mas não conte com ela: a guarda é a mesma.

PASSO 4 — GDD v41 contra o fertwaysdev
- sudo -u fertways /usr/bin/php84 tools/gdd-v41.php > /tmp/gdd-v41.html
- grep -c "Fatal\|Warning\|Exception\|▕" /tmp/gdd-v41.html → 0; tail termina em </html>.
- cp para docs/FERTWAYS_GDD_v41_CONSOLIDADO.html e frontend/public/gdd.html; commit e push se mudou.

PASSO 5 — Deploy
- sudo ./tools/deploy.sh
- Confira: backup conferido; banco [fertwaysbd]; as seis migrations DONE; APP_DEBUG off; opcache;
  bundle; fumaça 200/401.

PASSO 6 — Conferir a produção (só leitura)
- DB::table('espacoporto_settings')->first() → ativo 1, aluguel 20000000, 10000, 2
  (se não, o painel já tinha sido mexido — me diga o que está lá).
- DB::table('planetas_npc')->get(['nome','aberto','risco_chance_bps','risco_perda_bps','marco_exigido','tecnologia_exigida'])
  → os cinco abertos; Auryn 1000/2000/25; Drakmoor 3000/5000/35; Solène tecnologia tec_ciencia_1.
- DB::table('planeta_npc_precos')->where('resource_type','niobio_alienigena')->pluck('venda_micro')
  → nenhum abaixo de DB::table('war_settings')->value('niobio_preco_micro').
- DB::table('war_settings')->value('fabricacao_minutos_por_nivel') → 2.

PASSO 7 — A marca da equipe (NÃO rode sem me perguntar)
- O docs/PLAYTEST.md manda rodar `fertways:equipe --todos-os-atuais` ANTES do primeiro testador.
  Pergunte-me se é para rodar agora; se eu disser que sim, rode na cópia de deploy como fertways e
  me mostre quantas contas foram marcadas.

PASSO 8 — Fechar
- Atualize docs/RETOMAR.md (lote D-263 a D-275 no ar, sha, backup, resultado dos passos 3, 6 e 7). Commit e push.
- Me entregue um resumo curto. Lembre-me de que o Atendente do Espaçoporto só existe depois de
  nomeado: `fertways:cargo-civico <nick> atendente_espacoporto --nomear`.
```
