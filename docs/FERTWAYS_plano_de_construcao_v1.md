# FERTWAYS: The Next Colony — Plano de Construção v1

Baseado no GDD v3.5 (Mestre Unificado) e nas decisões abaixo, fechadas em conversa.

## 1. Decisões travadas

| Tema | Decisão |
|---|---|
| **Escopo do MVP** | Core loop + economia básica + multiplayer leve |
| **Banco de dados** | MariaDB, com tuning agressivo para pouca RAM |
| **Deploy** | Nativo via Virtualmin (PHP-FPM + web server), com `git pull` manual via terminal na VPS |
| **Domínio** | Subdomínio novo — `game.fertways.com` ou `play.tars.art.br` (definir na hora de criar o Virtual Server) |
| **Ticks de produção** | Cron leve, 1x/minuto, calculando por delta de tempo (não por "tempo online") |
| **Stack** | Laravel (API) + MariaDB + React/TypeScript + Phaser.js (já definido no GDD) |
| **Repositório** | https://github.com/fbfert/fertways |

## 2. Recorte do MVP — o que entra e o que fica pra depois

Seu servidor é 2 vCPU / ~3,5 GB RAM. A régua é: **nada que exija processo persistente extra** (sem Redis, sem websocket server, sem worker de fila dedicado) na v1. Tudo roda em cron + PHP-FPM sob demanda.

**Entra no MVP** (mapeado às seções do GDD v3.5):
- Loop principal, slot da Capital, identidade do colono, marcos de colonização (Parte II §2, §17, §24)
- Construções essenciais + progressão (Gerador de Atmosfera, Estrutura de Sobrevivência, Fazenda, Reator, Captação de Água, Oficina, Refinaria, Laboratório, Antena, Torre de Defesa, Mercado Local, Quartel, Plataforma de Pouso) — sem as 15 especializações do aditivo v3.4 por enquanto
- Recursos primários/secundários e cadeia de produção (Metal Bruto → Componentes Eletrônicos, Biocombustível) — §18, §19, §24
- Central de Transportes, Furgão de Comércio, Caminhão de Carga — logística física real (§16, §21, §25)
- Fert$ e Mercado Central com tributação única (§6, §22, §25)
- Zonas Neutras + Comércio Informal com Acordo de Troca (§7, §8, §17.4, §25, §26)
- Reputação — pelo menos o índice comercial (necessário pro risco do comércio informal fazer sentido) — versão simplificada dos 4 índices de §26
- Proteção de novato (slot principal inviolável + proteção temporária em zona neutra) — §27.11, regra de precedência da seção 0

**Fica para v2 (documentado, não descartado)**:
- Aditivo v3.4 completo: Missões de Reconhecimento, Telescópio Gagarin, 15 construções de especialização, luas
- Guerra territorial e conquista (§27 além da proteção de novato), Sentinela, Ranking de Guerras
- Federações formais, Cargos Públicos Neutros, Conciliador/Ministério das Reputações completo
- Chats em tempo real (§10) — v1 usa polling simples, não websocket
- Internacionalização multi-idioma (§11)

## 3. Stack técnica

```
Backend:   Laravel 11 (API-only, Sanctum p/ auth), PHP 8.3
Banco:     MariaDB (InnoDB, tuning abaixo)
Frontend:  React + TypeScript + Vite, Tailwind (UI/HUD), Phaser.js (canvas da colônia)
Ticks:     Laravel Scheduler + cron do sistema (1x/min), sem queue worker dedicado
Deploy:    Virtualmin (PHP-FPM), git pull manual via SSH
Sem:       Redis, websockets, filas assíncronas — adicionar só se o servidor aguentar depois
```

## 4. Estrutura do repositório (monorepo)

```
fertways/
├── backend/            # Laravel API
│   ├── app/Models/
│   ├── app/Services/   # regras de jogo puras (produção, tributação, combate futuro)
│   ├── app/Console/Commands/TickColonies.php
│   └── database/migrations/
├── frontend/            # React + Vite + Phaser
│   ├── src/game/         # cenas Phaser (colônia, mapa de zonas neutras)
│   └── src/ui/            # React (HUD, mercado, construções, login)
├── docs/
│   └── FERTWAYS_GDD_v35_MESTRE_UNIFICADO.html
└── deploy/
    ├── nginx.conf.example
    ├── mariadb-tuning.cnf
    └── deploy.sh
```

## 5. Modelo de dados (núcleo do MVP)

```
users            (id, name, email, password, reputation_comercial, created_at)
colonies         (id, user_id, name, founded_at, milestone)
buildings        (id, colony_id, type, level, slot, upgrade_started_at, upgrade_finish_at)
resources        (id, colony_id, resource_type, amount, storage_cap, last_tick_at)
vehicles         (id, colony_id, type, level, status, destination_id, departs_at, arrives_at, cargo)
neutral_zones    (id, coordinates, owner_colony_id, status, protected_until)
market_orders    (id, colony_id, resource_type, side, price, qty, created_at)
trade_agreements (id, colony_a_id, colony_b_id, terms_json, status, created_at, executed_at)
ledger           (id, colony_id, type, amount, resource_type, ref, created_at)  -- append-only, auditável
```

Taxas de produção **não** ficam salvas em tabela própria: são calculadas a partir do nível de cada `building` usando as fórmulas das tabelas §19 do GDD, aplicadas no tick.

## 6. Motor de tick (cron, 1x/min)

`php artisan schedule:run` chamado pelo cron do sistema a cada minuto. O comando `TickColonies`:
1. Para cada colônia com `last_tick_at` no passado: calcula `delta = now - last_tick_at`.
2. Soma produção de recursos (capada pela capacidade do Depósito/armazenamento).
3. Finaliza upgrades de construção cujo `upgrade_finish_at <= now`.
4. Finaliza viagens de veículos cujo `arrives_at <= now` (entrega de carga, chegada em zona neutra).
5. Expira proteções de zona neutra vencidas.
6. Grava tudo no `ledger` para auditoria.

Isso evita qualquer processo persistente — é só PHP rodando por alguns segundos a cada minuto, leve pro seu CPU de 2 núcleos.

## 7. Tuning do MariaDB para pouca RAM (ponto de partida, ajustar com monitoramento)

```ini
[mysqld]
innodb_buffer_pool_size = 192M
innodb_log_file_size = 64M
innodb_flush_log_at_trx_commit = 2
max_connections = 40
performance_schema = OFF
table_open_cache = 400
tmp_table_size = 32M
max_heap_table_size = 32M
```

## 8. Checklist de preparação do servidor (antes de codar)

1. Liberar espaço em disco (você já mencionou que vai fazer isso — hoje tem ~14 GB livres, o ideal é ficar bem acima disso antes de subir builds de frontend).
2. Confirmar/instalar PHP 8.3 via seletor de PHP do Virtualmin, com extensões: `pdo_mysql mbstring bcmath ctype fileinfo tokenizer xml`.
3. Instalar Composer (`curl -sS https://getcomposer.org/installer | php`).
4. Instalar Node.js LTS (só para build do frontend — não roda como serviço persistente).
5. Criar o Virtual Server / subdomínio no Virtualmin (`game.fertways.com` ou `play.tars.art.br`).
6. Criar banco MariaDB + usuário dedicado via Virtualmin.
7. Aplicar o tuning do passo 7 acima e reiniciar o MariaDB.
8. Configurar deploy via SSH: `git clone` do repo em `public_html` (ou pasta separada com symlink), `.env`, `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build`, `php artisan migrate --seed`, permissões de `storage` e `bootstrap/cache`.
9. Adicionar ao crontab: `* * * * * php /caminho/artisan schedule:run >> /dev/null 2>&1`.

---

## 9. PROMPT INICIAL — cole isso no Claude Code (ou aqui mesmo) para começar o scaffold

```
Vamos construir o FERTWAYS: The Next Colony, um MMO de estratégia e gestão de
colônia, a partir do zero, no repositório https://github.com/fbfert/fertways.

STACK:
- Backend: Laravel 11, API-only (Sanctum para auth), PHP 8.3, MariaDB (InnoDB).
- Frontend: React + TypeScript + Vite, Tailwind para UI/HUD, Phaser.js para o
  canvas visual da colônia.
- Sem Redis, sem websockets, sem queue worker dedicado nesta fase — os ticks de
  produção rodam via Laravel Scheduler + cron do sistema (1x/minuto), calculando
  por delta de tempo.
- Monorepo: /backend (Laravel), /frontend (React+Vite+Phaser), /docs (GDD),
  /deploy (configs de nginx, tuning do MariaDB, script de deploy).

REGRA DE OURO: a especificação completa do jogo é o arquivo
/docs/FERTWAYS_GDD_v35_MESTRE_UNIFICADO.html (vou colocar no repo). Toda
fórmula, tabela de custo, nome de construção e regra de negócio vem de lá —
não invente valores.

ESCOPO DO MVP (v1) — implemente só isto por enquanto:
1. Autenticação de jogador + criação de colônia (slot principal, identidade do
   colono, marco de colonização inicial).
2. Construções essenciais e de progressão do slot principal (do GDD: Gerador
   de Atmosfera, Estrutura de Sobrevivência, Fazenda, Reator de Energia,
   Captação de Água, Oficina, Refinaria Química, Laboratório, Antena de
   Comunicação, Torre de Defesa, Mercado Local, Quartel, Plataforma de Pouso),
   com fila de construção/upgrade e cálculo de custo pela curva do GDD.
3. Recursos primários/secundários e a cadeia de produção completa (Metal
   Bruto → Componentes Eletrônicos, cadeia de Biocombustível).
4. Central de Transportes + Furgão de Comércio + Caminhão de Carga: toda
   movimentação de recurso exige veículo físico, com tempo de viagem real.
5. Fert$ e Mercado Central, com a regra de tributação única (uma incidência
   por fato econômico).
6. Zonas Neutras + Comércio Informal com Acordo de Troca opcional (o acordo
   dá garantia; sem ele, existe risco real de calote).
7. Um índice de reputação comercial simplificado, o suficiente para o
   comércio informal fazer sentido.
8. Proteção de novato: slot principal inviolável sempre; zona neutra
   recém-ocupada protegida por um período (ver seção 0 e 27.11 do GDD).

NÃO IMPLEMENTE AINDA (documentado no plano, fica para v2): aditivo v3.4
completo (Reconhecimento, Gagarin, luas, 15 especializações), guerra
territorial e conquista de zona neutra, federações, cargos públicos, ranking
de guerras, chat em tempo real (usar polling simples por enquanto),
internacionalização.

PRIMEIRA ENTREGA: um "vertical slice" funcional de ponta a ponta —
1) scaffold do Laravel com migrations do modelo de dados abaixo,
2) scaffold do frontend Vite+React+Phaser com tela de login e uma cena de
   colônia renderizando o slot principal,
3) endpoint de criar colônia,
4) uma construção completa (ex.: Gerador de Atmosfera) com fila de
   construção, custo, e upgrade,
5) o comando de tick (php artisan schedule) processando produção e
   finalizando upgrades por delta de tempo,
antes de partir para o resto da lista.

MODELO DE DADOS INICIAL (ajuste livremente, mas mantenha a ideia):
- users (id, name, email, password, reputation_comercial, created_at)
- colonies (id, user_id, name, founded_at, milestone)
- buildings (id, colony_id, type, level, slot, upgrade_started_at, upgrade_finish_at)
- resources (id, colony_id, resource_type, amount, storage_cap, last_tick_at)
- vehicles (id, colony_id, type, level, status, destination_id, departs_at, arrives_at, cargo)
- neutral_zones (id, coordinates, owner_colony_id, status, protected_until)
- market_orders (id, colony_id, resource_type, side, price, qty, created_at)
- trade_agreements (id, colony_a_id, colony_b_id, terms_json, status, created_at, executed_at)
- ledger (id, colony_id, type, amount, resource_type, ref, created_at)

Comece propondo a estrutura de pastas e as migrations, depois me mostre antes
de gerar todo o código.
```

---

Repositório: https://github.com/fbfert/fertways
GDD de referência: `FERTWAYS_GDD_v35_MESTRE_UNIFICADO.html`
