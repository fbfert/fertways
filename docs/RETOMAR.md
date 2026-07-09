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
- **Conta no Mercado Central** (última fatia, §25.8): depósito e retirada físicos. `GET
  /central/market/account` e `POST /central/vehicles/{id}/withdraw`. Ver D-32.
- Frontend: login, HUD, colônia desenhada como colmeia em Phaser

**83 testes, 1466 asserções, verdes.** O **cron do tick está instalado** (crontab do usuário
`fertways`, log em `/home/fertways/logs/fertways-tick.log`) — o mundo avança sozinho.

## Perguntas em aberto — faça estas ao usuário ao retomar

1. **Qual o próximo passo?**
   - **Venda no Mercado Central**: a conta existe e recebe carga, mas **não há venda**. O preço
     deixou de ser bloqueio: o D-24 foi arbitrado (§24.8, 1,2778 para os Componentes). Falta
     decidir o **mecanismo**: preço fixo do catálogo, livro de ofertas entre colonos, ou
     sensibilidade à distância (o §25.8 chama o Mercado de "Sensível à Distância", mas a única
     sensibilidade que ele descreve é o custo de levar a carga até lá, que já existe). Pergunte
     antes de escrever código.
   - **UI de despacho**: os endpoints de logística e de mercado existem, mas não há tela. O
     jogador não consegue despachar carga nem ver seu saldo no Mercado pelo navegador.

2. **Separar o diretório de deploy do diretório de trabalho?** Hoje `public_html/central` é um
   symlink para `backend/public`, então **editar código aqui é publicar**. Já quebrei a fundação
   de colônia por alguns minutos ao salvar código que dependia de uma migration ainda não
   aplicada. Perguntado em 2026-07-09; o usuário respondeu "agora não". Vale repreguntar quando
   o ritmo de mudança no backend cair.

   Enquanto não houver separação: **aplique a migration antes de salvar o código que depende
   dela.** Foi o que se fez na fatia do Mercado, e não houve janela quebrada.

3. **Zerar a colônia de teste `publico@fertways.test`?** Ela foi abastecida à mão (1.000 de Metal
   Bruto, 100 de energia) para verificar o depósito em produção, e tem 970 de Metal Bruto na conta
   do Mercado. Não atrapalha ninguém, mas não é um estado "natural" de jogo.

## Pendências conhecidas, sem bloquear

- **D-33** — §24.8 revoga o preço do **Biocombustível** junto com o dos Componentes, mas não
  publica substituto, e o GDD não traz a receita dele. O catálogo segue com o valor revogado do
  §22.2 (0,0166). Só dói quando o Mercado vender Biocombustível.
- **Ida e volta ao Mercado sem vender custa tributo duas vezes** (D-32). É o §25.9 aplicado à
  letra: uma incidência por entrega física, e são duas entregas. Fixado em teste. Se o usuário
  achar punitivo demais, é decisão de balanceamento, não bug.
- **Depreciação de veículos (§16.4)** — fora do MVP por decisão do usuário. O GDD descreve o
  comportamento mas não publica a curva de desgaste, o limite crítico nem o custo de manutenção.
- **Zonas neutras como destino de carga** — o despacho aceita `colonia`; zona neutra precisa do
  Depósito de Zona Neutra.
- **Frontend** — o bundle passa de 1,5 MB sem code splitting. Não incomoda ainda.

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
```

## Contas de teste

`publico@fertways.test` · `mapa2@fertways.test` — senha `segredo-forte-123` nas duas.

## Leia também

- `docs/decisoes.md` — 30 decisões, com as divergências e lacunas do GDD. **A regra de ouro é
  não inventar valores.** Quando o GDD não decide, pergunte ao usuário e registre ali.
- `docs/deploy.md` — php84, Node, o symlink `/central`, e por que `route:cache` está proibido.
