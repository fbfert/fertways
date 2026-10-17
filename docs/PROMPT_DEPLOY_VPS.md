# Prompt de deploy na VPS — lote D-287 a D-291

> Cole o bloco abaixo numa sessão do Claude Code **aberta no servidor**, em
> `/home/fertways/apps/fertways`. O lote anterior (D-279 a D-286) está no ar desde `7c68bd0`.
> Este lote foi escrito numa máquina Windows e testado contra SQLite e contra um MariaDB 10.5.29 local
> (container), a mesma versão da produção — a suíte inteira e o round-trip das migrations.

---

```text
Vamos publicar em produção o lote D-287 a D-291:
- D-287: a avaliação de 0 a 5 estrelas do comércio entre colonos (peso na reputação, janela de 7 dias,
  suspeita de contas vinculadas revisada no painel);
- D-288: o Ministério das Reputações julga chat, ataque a aliado, quebra de tratado e avaliação injusta,
  cada violação com a sua prova (registros do servidor);
- D-289: a Embaixada Interplanetária no slot 9 da Capital — credencial comercial por planeta (40 F$,
  50% a menos de imposto da carga, 30 dias). O Quartel de Alianças passa ao slot 10;
- D-290: o Cemitério de Robôs (enterra cada morte em combate e mostra os mortos da zona) e a Estrutura
  de Extração (acelera a extração: +10% no nível 1, pela curva, até dobrar);
- D-291: o assistente "Vender, passo a passo" nos dois Mercados (só frontend).
Leia docs/deploy.md, docs/RETOMAR.md e as entradas D-287 a D-291 de docs/decisoes.md antes. Siga a
ordem abaixo e pare para me perguntar se qualquer conferência falhar. Não contorne.

CONTEXTO
- Quatro migrations pendentes:
    2026_10_18_100000_avaliacao_de_comercio   (D-287: avaliacoes_de_comercio e login_ips, duas tabelas novas)
    2026_10_18_200000_ministerio_completo     (D-288: 4 colunas em reports, 2 em combats; nada é reescrito)
    2026_10_18_300000_embaixada_interplanetaria (D-289: 2 colunas em planetas_npc — preço 40 F$ nos cinco —,
                                               1 em espacoporto_settings, tabela credenciais_comerciais)
    2026_10_18_400000_cemiterio_de_robos      (D-290: tabela robos_enterrados)
- ⚠️ D-287: o login e o cadastro passam a gravar um HMAC do IP (com a APP_KEY; o IP em si não é guardado)
  em login_ips, para apontar contas possivelmente vinculadas. Começa vazio no deploy.
- ⚠️ D-289: a credencial fica à venda nos cinco planetas logo depois do deploy, por 40 F$. O dinheiro vai ao
  Tesouro. É de propósito; o preço muda no painel (Transportes → Espaçoporto).
- ⚠️ D-290: zonas que JÁ têm Estrutura de Extração erguida passam a extrair mais no primeiro tick. Confira
  quantas no passo 3. E o Cemitério só conhece as mortes daqui em diante: as de antes não foram guardadas.
- ⚠️ D-288: o carimbo de federação nos combates vale só para combates novos; os antigos não servem de
  prova de ataque a aliado (sem_federacoes). É de propósito.
- Lembretes: /usr/bin/php84; artisan como `sudo -u fertways`; a árvore de trabalho fala com o
  fertwaysdev e pede --force no migrate; nunca config:cache nem route:cache nela.
- ⚠️ Lição do deploy anterior: o `git pull` da cópia de deploy morreu no meio por causa de um arquivo
  de `.claude/` no repositório, e o deploy.sh tirou a aplicação da manutenção com código misturado.
  Antes do passo 4, confira que `git ls-files .claude` está VAZIO no HEAD que vai subir. Se não estiver, PARE.

PASSO 1 — Código e suíte
- cd /home/fertways/apps/fertways; git status limpo; git pull --ff-only origin main (como fertways, não root).
- git log --oneline -1 → df8a7b3 ("D-287 a D-291: estrelas, Ministério completo, Embaixada, Cemitério...")
  ou um commit de docs posterior. Se o HEAD for anterior a df8a7b3, PARE.
- git ls-files .claude → vazio.
- cd backend && sudo -u fertways /usr/bin/php84 /bin/composer install --no-interaction
- sudo -u fertways /usr/bin/php84 artisan test   → esperado: 1674 verdes, NENHUM vermelho.
- Frontend: export PATH="/usr/local/lib/nodejs/node-v22.12.0-linux-x64/bin:$PATH";
  cd ../frontend && npm ci && npm run build   → limpo.

PASSO 2 — As quatro migrations no fertwaysdev, nos dois sentidos
- Confirme que o artisan da árvore de trabalho fala com fertwaysdev. Se não, PARE.
- migrate --force; migrate:rollback --step=4 --force; migrate --force.
- Depois: Schema::hasTable('avaliacoes_de_comercio'), 'login_ips', 'credenciais_comerciais',
  'robos_enterrados' → true nas quatro;
  DB::table('planetas_npc')->pluck('credencial_preco_micro','nome') → 40000000 em todos;
  DB::table('espacoporto_settings')->value('credencial_dias') → 30;
  Schema::hasColumns('combats',['atacante_federation_id','defensor_federation_id']) → true.

PASSO 3 — Só leitura, na PRODUÇÃO, antes do deploy
- DB::table('zone_structures')->where('type','estrutura_de_extracao')->where('level','>',0)->count()
  e em quantas zonas distintas (são as que vão extrair mais no primeiro tick);
- DB::table('reports')->count() e DB::table('combats')->count() (as tabelas que ganham colunas).
- Me mostre os números. Só ids e contagens, nada de nome de jogador.

PASSO 4 — Deploy
- sudo ./tools/deploy.sh
- Confira: backup conferido; banco [fertwaysbd]; as quatro migrations DONE; APP_DEBUG off; opcache;
  bundle; fumaça 200/401; /central/estatisticas → 200 com x-ratelimit-limit: 600.

PASSO 5 — Conferir (só leitura)
- /admin/ministerio e /admin/transportes?aba=espacoporto abrem com 200 (o segundo mostra "Credencial (dias)"
  e o preço/desconto da credencial em cada planeta).
- GET /embaixada e GET /ministry/provas respondem 401 sem sessão (não 404 nem 500).
- DB::table('planetas_npc')->pluck('credencial_desconto_bps','nome') → 5000 em todos.
- tail -n 50 backend/storage/logs/laravel.log da cópia de deploy → nada novo depois do deploy.
- Um tick depois: as zonas do passo 3 com Estrutura de Extração têm last_extraction_at recente e nenhum
  erro no log.

PASSO 5b — GDD v41 regenerado contra o fertwaysdev (já migrado no passo 2)
- sudo -u fertways /usr/bin/php84 tools/gdd-v41.php > /tmp/gdd-v41.html
- grep -c "Fatal\|Warning\|Exception\|▕" /tmp/gdd-v41.html → 0; tail termina em </html>.
- cp para docs/FERTWAYS_GDD_v41_CONSOLIDADO.html e frontend/public/gdd.html; commit e push.
- ⚠️ O commit vem depois do deploy, então o gdd.html servido só se atualiza no próximo deploy.sh completo
  (o --so-frontend não puxa código). Me diga isso no resumo.

PASSO 6 — Fechar
- Atualize docs/RETOMAR.md (lote D-287 a D-291 no ar, sha, backup, resultado dos passos 3 e 5). Commit e push.
- Me entregue um resumo curto. Lembre-me do que segue pendente à mão: nomear o Atendente do Espaçoporto
  (`fertways:cargo-civico <nick> atendente_espacoporto --nomear`), se ainda não foi feito.
```
