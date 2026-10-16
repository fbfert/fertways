# Prompt de deploy na VPS — D-279

> Cole o bloco abaixo numa sessão do Claude Code **aberta no servidor**, em
> `/home/fertways/apps/fertways`. O lote anterior (D-263 a D-278) já está no ar desde `d7245ad`.
> O D-279 foi escrito numa máquina Windows e testado contra SQLite e contra um MariaDB 10.5.29 local
> (container), a mesma versão da produção. **Não tem migration.**

---

```text
Vamos publicar em produção o D-279: a guarda de arbitragem do D-278 passa a valer também no
painel, nos dois lados (o planeta não compra acima do Governo, e o Governo não vende abaixo do que
um planeta paga), e os dois testes de EspacoportoCargaTest que falhavam só no SQLite 3.34.1 do
servidor foram corrigidos. Não há migration.
Leia docs/deploy.md, docs/RETOMAR.md e a entrada D-279 de docs/decisoes.md antes. Siga a ordem
abaixo e pare para me perguntar se qualquer conferência falhar. Não contorne.

LEMBRETES
- /usr/bin/php84; artisan como `sudo -u fertways`; nunca config:cache nem route:cache na árvore de
  trabalho.

PASSO 1 — Código e suíte
- cd /home/fertways/apps/fertways; git status limpo; git pull --ff-only origin main.
- cd backend && sudo -u fertways /usr/bin/php84 /bin/composer install --no-interaction
- sudo -u fertways /usr/bin/php84 artisan test   → esperado: 1544 verdes, NENHUM vermelho.
  (No lote anterior foram 1534 + 2 vermelhos. Os 2 eram os que o D-279 corrige; se algum deles
  ainda falhar, PARE e me mostre a saída.)

PASSO 2 — A guarda contra a produção, antes do deploy (só leitura)
- Na cópia de deploy, como fertways, confira que hoje não existe nenhum conflito. Se existisse, o
  formulário do Mercado do Governo passaria a recusar aquele recurso:
    DB::table('market_orders as o')->whereNull('o.colony_id')->where('o.side','sell')
      ->whereIn('o.status',['aberta','parcial'])
      ->join('planeta_npc_precos as p','p.resource_type','=','o.resource_type')
      ->whereColumn('p.compra_micro','>','o.price_micro')->count()
  → esperado 0. Se não for 0, PARE e me mostre as linhas.

PASSO 3 — Deploy
- sudo ./tools/deploy.sh
- Confira: backup conferido; banco [fertwaysbd]; "Nothing to migrate"; APP_DEBUG off; opcache;
  bundle; fumaça 200/401.
- curl -s -D - -o /dev/null https://fertways.tars.art.br/central/estatisticas | grep -i "^HTTP\|x-ratelimit"
  → 200 e x-ratelimit-limit: 600.

PASSO 4 — Conferir o painel (só leitura)
- /admin/transportes?aba=espacoporto e /admin/economia?aba=mercado abrem com 200, e cada um traz
  o aviso novo do D-279 no texto de ajuda.
- NÃO salve nenhum formulário para testar: a guarda tem 8 testes, e salvar mexe no Tesouro e nos
  preços de verdade.
- tail -n 50 backend/storage/logs/laravel.log da cópia de deploy → nada novo depois do deploy.

PASSO 5 — Fechar
- Atualize docs/RETOMAR.md (D-279 no ar, sha, backup, resultado dos passos 1 e 2). Commit e push.
- Me entregue um resumo curto. Lembre-me de que o Atendente do Espaçoporto continua só existindo
  depois de nomeado: `fertways:cargo-civico <nick> atendente_espacoporto --nomear`.
```
