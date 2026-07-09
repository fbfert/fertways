# RETOMAR — ponto de parada do FERTWAYS

> **Para o Claude:** quando o usuário disser "retome", leia este arquivo primeiro, confira o
> estado real (os comandos da seção "Verificação rápida" abaixo — não confie nesta página),
> e então **faça ao usuário as perguntas da seção "Perguntas em aberto"** antes de escolher
> o que fazer. Atualize este arquivo ao fim de cada sessão.

**Última atualização:** 2026-07-09 · **Branch:** `main`

> O commit não é anotado aqui de propósito: ele fica velho a cada sessão e a página passa a
> mentir. Rode `git log --oneline -1`.

---

## Onde o projeto está

O MVP tem, funcionando e no ar em `https://fertways.tars.art.br`:

- Autenticação (Sanctum), fundação de colônia, kit inicial (50 Fert$, raros, Furgão)
- 16 construções com fila, custo pela curva do GDD e subsídio governamental
- Tick por delta de tempo: produção, conclusão de upgrades, expiração de proteção
- Fabricação de Componentes Eletrônicos pelas três receitas do §24.5
- **Logística física**: mapa 100×100, despacho de carga entre colônias, tempo e energia por
  distância, tributo na entrega, viagem de ida e volta
- **Mercado Central** (última fatia): conta na doca com depósito e retirada físicos (§25.8, D-32)
  e **livro de ofertas com escrow** (§07, D-35). `GET/POST /central/market/orders`,
  `DELETE /central/market/orders/{id}`, `GET /central/market/account`,
  `POST /central/vehicles/{id}/withdraw`. O Mercado casa ordens; não compra nem vende.
- Frontend: login, HUD, colônia em Phaser, e a **tela do Mercado** (botão no HUD): doca, frota com
  contagem regressiva, despacho e retirada, livro de ofertas com escrow

**101 testes, 1523 asserções, verdes.** O **cron do tick está instalado** (crontab do usuário
`fertways`, log em `/home/fertways/logs/fertways-tick.log`) — o mundo avança sozinho.

O item 5 do MVP (Mercado Central) está **fechado, backend e tela**, e a tela tem **teste de ponta
a ponta em navegador de verdade**: `npm run e2e` (ou `./tools/e2e.sh`) sobe uma pilha efêmera
(SQLite temporário + `artisan serve` + `vite dev`) e dirige o Chromium do sistema com
`puppeteer-core`. 19 verificações. Nunca toca produção nem o MariaDB.

> ⚠️ **Produção serve um bundle mais velho que o `main`.** O último `git commit` do frontend
> (ordenação das opções de carga) **não foi publicado** — o deploy exige autorização explícita.
> Publique com o comando de `docs/deploy.md` e confira o hash do bundle servido.

## Perguntas em aberto — faça estas ao usuário ao retomar

1. **Qual o próximo passo?**
   - **Despacho entre colônias pela UI**: os endpoints aceitam `destino = colonia`, mas a tela só
     oferece o Mercado, porque **não há endpoint de diretório de colônias** — o jogador não tem
     como descobrir o `id` de ninguém. Falta um `GET /central/colonies`.
   - **Serviço logístico público** (§07): o GDD o cita como alternativa ao veículo próprio na
     retirada, e ele não existe. Hoje o comprador precisa de Furgão ou Caminhão. O GDD não
     publica preço nem prazo do serviço — precisaria de arbitragem.
   - **Comércio informal com Acordo de Troca** (§26.5): o outro canal, sem escrow, com o risco de
     calote deliberadamente real. Faz par com o Mercado e está no MVP social.

2. **Separar o diretório de deploy do diretório de trabalho?** Hoje `public_html/central` é um
   symlink para `backend/public`, então **editar código aqui é publicar**. Já quebrei a fundação
   de colônia por alguns minutos ao salvar código que dependia de uma migration ainda não
   aplicada. Perguntado em 2026-07-09; o usuário respondeu "agora não". Vale repreguntar quando
   o ritmo de mudança no backend cair.

   Enquanto não houver separação: **aplique a migration antes de salvar o código que depende
   dela.** Foi o que se fez na fatia do Mercado, e não houve janela quebrada.

3. **Zerar as colônias de teste?** `publico@fertways.test` foi abastecida à mão (1.000 de Metal
   Bruto, 100 de energia) para verificar o depósito em produção. Depois as duas contas fizeram um
   negócio real no livro: `publico` tem 54,85 Fert$ e 770 na doca; `mapa2` tem 45 Fert$ e 100 na
   doca. Não atrapalha ninguém, mas não é um estado "natural" de jogo.

## Pendências conhecidas, sem bloquear

- **O tributo do Mercado contradiz o §07 de propósito** (D-32). O §07 proíbe dupla incidência e
  isentaria depósito e retirada; o usuário arbitrou pelo §25.8, que tributa cada entrega física.
  **Não "conserte" sem perguntar.**
- **Metal Bruto vale 5,5× menos do que o §07 diz** (D-34, arbitrado). Se a economia de mineração
  parecer fraca quando o Mercado abrir, é o primeiro número a revisitar.
- **Ida e volta ao Mercado sem vender custa tributo duas vezes** (D-32). É o §25.9 aplicado à
  letra: uma incidência por entrega física, e são duas entregas. Fixado em teste. Se o usuário
  achar punitivo demais, é decisão de balanceamento, não bug.
- **Depreciação de veículos (§16.4)** — fora do MVP por decisão do usuário. O GDD descreve o
  comportamento mas não publica a curva de desgaste, o limite crítico nem o custo de manutenção.
- **Zonas neutras como destino de carga** — o despacho aceita `colonia`; zona neutra precisa do
  Depósito de Zona Neutra.
- **Frontend** — o bundle passa de 1,5 MB sem code splitting (quase tudo é Phaser). Não incomoda
  ainda. O `vite build` avisa a cada compilação.
- **`cp` é alias de `cp -i` para o root.** No passo de deploy do frontend ele trava num prompt e
  copia nada, em silêncio, com saída que *parece* sucesso. Use `/bin/cp -rf dist/. …`. Confira o
  hash do bundle servido contra o de `dist/assets/` antes de dizer que publicou.

## Verificação rápida (rode antes de confiar nesta página)

```sh
# Backend: use php84. O `php` do PATH é 8.2 e o composer.lock exige >= 8.4.1.
cd /home/fertways/apps/fertways/backend && /usr/bin/php84 artisan test

# O site está no ar?
curl -s -o /dev/null -w '%{http_code}\n' https://fertways.tars.art.br/          # 200 (front)
curl -s https://fertways.tars.art.br/central/                                    # índice JSON da API
curl -s -o /dev/null -w '%{http_code}\n' https://fertways.tars.art.br/central/colony  # 401

# O cron do tick existe, e está mesmo rodando?
crontab -u fertways -l
tail -3 /home/fertways/logs/fertways-tick.log

# Frontend: o typecheck honesto é `npm run build`, não `tsc --noEmit`.
export PATH="/usr/local/lib/nodejs/node-v22.12.0-linux-x64/bin:$PATH"
cd /home/fertways/apps/fertways/frontend && npm run build

# A tela do Mercado num navegador de verdade. Sobe e derruba a própria pilha.
cd /home/fertways/apps/fertways && ./tools/e2e.sh

# O bundle no ar é o do último build? (o deploy do front não é automático)
diff <(ls frontend/dist/assets/*.js | xargs -n1 basename) \
     <(curl -s https://fertways.tars.art.br/ | grep -oE 'index-[A-Za-z0-9_-]+\.js')
```

## Contas de teste

`publico@fertways.test` · `mapa2@fertways.test` — senha `segredo-forte-123` nas duas.

## Leia também

- `docs/decisoes.md` — 30 decisões, com as divergências e lacunas do GDD. **A regra de ouro é
  não inventar valores.** Quando o GDD não decide, pergunte ao usuário e registre ali.
- `docs/deploy.md` — php84, Node, o symlink `/central`, e por que `route:cache` está proibido.
