# RETOMAR — ponto de parada do FERTWAYS

> **Para o Claude:** quando o usuário disser "retome", leia este arquivo primeiro, confira o
> estado real (os comandos da seção "Verificação rápida" abaixo — não confie nesta página),
> e então **faça ao usuário as perguntas da seção "Perguntas em aberto"** antes de escolher
> o que fazer. Atualize este arquivo ao fim de cada sessão.

**Última atualização:** 2026-07-09 · **Commit:** `99c4570` · **Branch:** `main`, sincronizado com `origin`

---

## Onde o projeto está

O MVP tem, funcionando e no ar em `https://fertways.tars.art.br`:

- Autenticação (Sanctum), fundação de colônia, kit inicial (50 Fert$, raros, Furgão)
- 16 construções com fila, custo pela curva do GDD e subsídio governamental
- Tick por delta de tempo: produção, conclusão de upgrades, expiração de proteção
- Fabricação de Componentes Eletrônicos pelas três receitas do §24.5
- **Logística física** (última fatia): mapa 100×100, despacho de carga entre colônias,
  tempo e energia por distância, tributo na entrega, viagem de ida e volta
- Frontend: login, HUD, colônia desenhada como colmeia em Phaser

**75 testes, 1423 asserções, verdes.**

## Perguntas em aberto — faça estas ao usuário ao retomar

1. **Qual o próximo passo?**
   - **Conta de mercado**: hoje `POST /central/vehicles/{id}/dispatch` para `mercado_central` é
     recusado de propósito, com o código `mercado_central_indisponivel`. O §25.8 exige que o
     recurso seja depositado numa conta do colono no Mercado antes de poder ser vendido, e essa
     conta não existe. Entregar sem ela evaporaria a carga do jogador. Destravar isso abre o
     Mercado Central (item 5 do MVP).
   - **UI de despacho**: os endpoints de logística existem, mas não há tela. O jogador não
     consegue despachar carga pelo navegador.

2. **Instalar o cron do tick?** Ele **não está instalado**. Sem ele o mundo só avança quando
   alguém roda o comando à mão: recursos não acumulam e construções não terminam. A ação é
   bloqueada quando o pedido não a nomeia explicitamente — o usuário precisa dizer algo como
   "instale o crontab do tick", ou rodar ele mesmo:

   ```
   printf '# FERTWAYS — aciona o Laravel Scheduler, que roda `fertways:tick` a cada minuto.\n* * * * * /usr/bin/php84 /home/fertways/apps/fertways/backend/artisan schedule:run >> /home/fertways/logs/fertways-tick.log 2>&1\n' | crontab -u fertways -
   ```

3. **Separar o diretório de deploy do diretório de trabalho?** Hoje `public_html/central` é um
   symlink para `backend/public`, então **editar código aqui é publicar**. Já quebrei a fundação
   de colônia por alguns minutos ao salvar código que dependia de uma migration ainda não
   aplicada. Separar os dois eliminaria a classe inteira de problema.

## Pendências conhecidas, sem bloquear

- **D-24** — §22.2 e §24.8 dão preços trinta e oito vezes diferentes para os Componentes
  Eletrônicos. O seed usa o de §22.2. Só importa quando o Mercado Central existir; alguém terá
  que arbitrar.
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

# O cron do tick existe?
crontab -u fertways -l

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
