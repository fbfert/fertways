# FERTWAYS — BALANCEAMENTO.md

**Objetivo:** registrar por que cada número importante existe, qual hipótese ele representa, quais métricas o sustentam e quando deve ser revisado.

Este documento não substitui as tabelas de dados utilizadas pelo jogo.

O GDD descreve a regra.  
As tabelas/banco fornecem o valor executado.  
Este documento explica a **racionalidade do valor**.

---

## 1. Regra de ouro

Nenhum número novo relevante deve entrar silenciosamente.

Para cada parâmetro relevante, registrar:

- sistema;
- chave;
- valor atual;
- unidade;
- origem;
- data de vigência;
- decisão D-n;
- hipótese;
- métrica esperada;
- telemetria observada;
- faixa de segurança;
- motivo da última alteração;
- próxima data/requisito de revisão.

---

## 2. Estados de um número

Cada parâmetro deve ser classificado como:

### CANÔNICO

Já definido, implementado e considerado regra vigente.

### BASELINE

Valor inicial utilizado para testar uma mecânica nova.

Pode mudar após telemetria.

### HIPÓTESE

Número usado em simulação, ainda não liberado.

### EXPERIMENTO

Valor aplicado a um ambiente/coorte de teste.

### PENDENTE

A mecânica está definida, mas ainda não existe número aprovado.

---

## 3. Não inventar números apenas para preencher tabela

Para sistemas novos da Alpha 2 — principalmente População e Pesquisa — números não aprovados devem começar como **PENDENTE** ou **HIPÓTESE**.

O fluxo correto:

1. definir função da mecânica;
2. definir unidade;
3. modelar fórmulas;
4. simular;
5. escolher baseline;
6. exercitar em escala — pelo **simulador da trilha A2.S** (ROADMAP) e por testadores humanos; a simulação por bots é externa e vive em staging (ver GDD ALPHA 2 §14);
7. observar telemetria;
8. ajustar;
9. promover para canônico.

O passo 6 é executado pelo simulador da trilha A2.S, que nasce dentro da A2.2. Enquanto ele não existir, número novo não sobe a canônico — ver a advertência da §18.

---

# 4. Objetivos macro de ritmo

## Sessão

Meta de design:

- 5 a 10 minutos;
- várias sessões por dia;
- cada sessão deve oferecer decisão útil;
- jogador não precisa manter a tela aberta.

## Mundo

- persistente;
- temporada de referência: 6 meses, prorrogável;
- sem reset automático.

## Implicação

Tempos e curvas precisam funcionar simultaneamente em três escalas:

- minutos: decisões e feedback imediato;
- horas/dias: produção, pesquisa, logística, conflitos;
- meses: especialização, Federação, território, Endurance e progressão avançada.

---

# 5. Telemetria obrigatória

## 5.1 Retenção e sessão

- jogadores ativos/dia;
- jogadores ativos/semana;
- sessões por jogador/dia;
- duração mediana;
- intervalo mediano entre sessões;
- retorno D1/D3/D7/D14/D30 quando houver usuários reais;
- os mesmos indicadores em staging, onde quem joga são os bots externos (ver GDD ALPHA 2 §14).

## 5.2 Onboarding

Para cada etapa:

- iniciou;
- concluiu;
- abandonou;
- tempo;
- erro;
- recurso insuficiente;
- clique de ajuda;
- recompensa concedida.

Indicador principal:

**percentual que chega ao fim da parte obrigatória sem intervenção administrativa.**

## 5.3 Economia

Por recurso:

- produzido;
- consumido;
- criado por subsídio;
- destruído;
- transportado;
- comercializado;
- estocado;
- preso em ordens;
- concentração por jogador;
- concentração por Federação.

Para Fert$:

- emissão;
- sumidouros;
- saldo total;
- saldo mediano;
- Gini/concentração;
- volume de mercado;
- velocidade de circulação.

## 5.4 Construção

- nível médio;
- tempo até upgrade;
- fila ociosa;
- fila saturada;
- cancelamentos;
- faltas de recurso.

## 5.5 População

- população total;
- capacidade;
- ocupação;
- população livre;
- população em produção;
- população territorial;
- crescimento/dia;
- tempo no teto habitacional;
- escassez de Água/Oxigênio/Biomassa/Energia;
- estruturas paradas por falta de operadores.

## 5.6 Pesquisa

- tecnologia iniciada;
- concluída;
- abandonada/cancelada;
- trilha escolhida;
- custo;
- duração;
- paralelismo;
- concentração de meta;
- tecnologias ignoradas.

## 5.7 Especialização

- especialização escolhida;
- momento da escolha;
- produção antes/depois;
- dependência de importação;
- exportação;
- mudança de especialização;
- concentração de cada especialização no servidor.

## 5.8 Federação

- membros;
- tempo até entrar;
- duração da associação;
- fundo;
- transações internas;
- missões;
- territórios;
- guerras;
- concentração econômica.

## 5.9 Guerra

- ataques;
- vitórias;
- derrotas;
- custo;
- dano;
- saque;
- tempo de preparação;
- diferença econômica atacante/defensor;
- recorrência contra mesmo alvo;
- zonas perdidas;
- abandono pós-ataque.

## 5.10 Endurance

- visitas;
- missões;
- peças obtidas;
- raridade;
- quantidade em circulação;
- propriedade;
- troca/leilão;
- concentração;
- itens únicos descobertos.

---

# 6. Onboarding — metodologia de balanceamento

O tutorial deverá conceder recursos suficientes para ensinar, não para financiar o midgame.

Cada recompensa deve responder a uma pergunta:

**“Qual ação esta recompensa permite que o jogador aprenda agora?”**

Evitar:

- prêmio sem finalidade;
- Fert$ excessivo;
- estoque que elimina a necessidade de produzir;
- recursos raros;
- prêmio repetível.

Tabela de trabalho:

| Etapa | Recompensa | Estado | Motivo | Métrica |
|---|---:|---|---|---|
| Energia | PENDENTE | PENDENTE | Permitir primeira ação guiada | conclusão da etapa |
| Oxigênio | PENDENTE | PENDENTE | Evitar bloqueio inicial | tempo até conclusão |
| Água | PENDENTE | PENDENTE | Ensinar cadeia essencial | abandono |
| Biomassa | PENDENTE | PENDENTE | Ensinar saldo líquido | abandono |
| Primeiro upgrade | PENDENTE | PENDENTE | Ensinar fila | tempo |
| Primeiro processamento | PENDENTE | PENDENTE | Ensinar cadeia | conclusão |
| Primeiro transporte | PENDENTE | PENDENTE | Ensinar logística | conclusão |
| Primeiro Mercado | PENDENTE | PENDENTE | Ensinar economia | conclusão |

Os valores serão cadastrados no backend e ajustados com **testadores humanos** antes de serem promovidos a baseline. A validação em escala fica para quando houver simulação em staging.

---

# 7. População — modelo de balanceamento

## 7.1 Variáveis

Proposta de chaves:

- `population_capacity[level]`
- `population_growth_rate`
- `population_water_per_capita`
- `population_oxygen_per_capita`
- `population_biomass_per_capita`
- `population_infrastructure_energy`
- `population_shortage_efficiency`
- `population_growth_min_supply_ratio`
- `building_operator_requirement[type][level]`
- `neutral_zone_operator_requirement[level]`
- `population_migracao_folga` — margem concedida acima do estritamente necessário na migração de grandfathering (GDD ALPHA 2 §6.7)

Todos começam como PENDENTE até simulação pela trilha A2.S.

### Rodada 1 da trilha A2.S — 2026-07-31 (evidência, NÃO promoção)

Primeira rodada registrada. **Nenhum número foi promovido de HIPÓTESE a BASELINE** — isto é a
evidência que a arbitragem exige, não a arbitragem.

Comando: `php84 artisan fertways:simular-populacao --dias=60 --nivel-habitacao=3 --populacao-inicial=5 --producao=agua:2,oxigenio:2,biomassa:1,energia:2`

| Parâmetro | Valor da rodada |
|---|---|
| `capacidade_base` / fator | 10 · 1,65× por nível (teto de 27 no nível 3) |
| `crescimento_bps_hora` | 50 (0,5%/h) |
| água / oxigênio / biomassa / energia por colono/hora | 0,100 · 0,120 · 0,080 · 0,060 |
| `escassez_eficiencia_bps` | 5000 (piso de 50%) |
| `crescimento_min_suprimento_bps` | 8000 (80%) |

**Resultado:**

- teto habitacional atingido no **dia 15** (5 → 27 colonos);
- primeiro gargalo: **biomassa**, a partir do **dia 19**;
- eficiência estabiliza em **73,2%**, com água, oxigênio e biomassa em falta permanente.

**Leitura:** com esta produção a colônia satura o teto rápido e depois vive cronicamente em
escassez — o que o §7.3 descreve como a faixa de *frustração*, não a de *decisão estratégica*. Ou a
produção de essenciais precisa ser maior do que a desta rodada, ou o consumo per capita precisa
cair. **Falta arbitrar qual dos dois**, e é decisão do usuário.

⚠️ A métrica-chave do §7.3 — percentual de população comprometida — **ainda não é mensurável**:
`building_operator_requirements` está vazia, e imprimir "0%" seria ausência de dado com cara de
resultado. Ela passa a existir quando os requisitos de operador forem semeados.

⚠️ **Achado do modelo, não do balanceamento:** a primeira rodada saiu com a curva perfeitamente
horizontal — a população não crescia **nunca**. Com 5 colonos a 0,5%/h, um passo de uma hora dá
5,025 e o `floor` devolve 5, para sempre. Foi corrigido com acumulador de resto fracionário
(`colonies.populacao_resto_milli`), o mesmo idioma de `siderurgica_lote_remainder`. O simulador
achou um defeito de modelo antes de ele chegar ao jogo — que é exatamente o que a trilha existe
para fazer.

⚠️ `population_capacity[level]` tem uma restrição que os demais não têm: na migração, a capacidade de cada colônia existente precisa **caber** a população concedida por grandfathering. Um valor baixo demais faria colônias veteranas nascerem acima do próprio teto habitacional.

## 7.2 Objetivo

A população deve:

- limitar expansão;
- valorizar a Estrutura de Sobrevivência;
- aumentar valor de recursos essenciais;
- obrigar escolha;
- não virar “The Sims” dentro de Fertways.

## 7.3 Métrica-chave

**Percentual de população comprometida.**

Faixas a testar:

- muito baixa → população irrelevante;
- moderada → decisão estratégica;
- próxima de 100% constantemente → frustração/gargalo excessivo.

O alvo exato será definido por simulação.

## 7.4 Zonas

A quantidade de operadores em zona deve ser pequena comparada à população da colônia.

Princípio: humanos supervisionam automação robotizada.

---

# 8. Pesquisa — modelo de balanceamento

## 8.1 Variáveis

- custo por tecnologia;
- duração;
- pré-requisitos;
- nível mínimo de Laboratório;
- vagas simultâneas (por nível do Laboratório);
- quantidade de níveis;
- efeito.

O **modificador do Observatório** sai desta lista: o Observatório não existe no jogo e não entra na primeira entrega (GDD ALPHA 2 §7.2). Volta quando ele for criado.

### Rodada 2 da trilha A2.S — 2026-07-31 (a árvore REPROVA no §8.3)

`php84 artisan fertways:simular-pesquisa --passos=3`

Cinco arquétipos de colônia (energética, agrícola, mineradora, industrial, generalista), cada um
escolhendo gulosamente a tecnologia de melhor retorno. **Os cinco escolheram a mesma sequência:**

    tec_biosfera_1 → tec_territorio_1 → tec_energia_1

Primeira escolha idêntica em **5/5 (100%)**, **1 sequência distinta de 5**. Pelo §8.3 — *"se a
maioria dos jogadores pesquisar a mesma sequência, a árvore falhou"* — a árvore como custeada hoje
**falha**.

#### A causa não é o efeito, é a composição do custo

| tecnologia | custo (Fert$) | recurso que domina o custo | melhor retorno |
|---|---|---|---|
| `tec_biosfera_1` | 2,25 | biomassa (55%) | **51 h** |
| `tec_territorio_1` | 8,59 | metal_bruto (97%) | 177 h |
| `tec_energia_1` | 32,22 | componentes_eletronicos (79%) | 649 h |
| `tec_industria_1` | 10,32 | metal_bruto (97%) | 2.968 h |
| `tec_defesa_1` | 4,71 | ligas_metalicas (66%) | — não medível — |
| `tec_logistica_1` | 40,83 | componentes_eletronicos (94%) | — não medível — |
| `tec_ciencia_1` | 54,28 | componentes_eletronicos (94%) | — não medível — |
| `tec_comercio_1` | 76,67 | componentes_eletronicos (100%) | — não medível — |

**`componentes_eletronicos` custa 1.277.800 micro contra 8.300 da biomassa — 154 vezes mais.**
Qualquer tecnologia que os exija sai do páreo antes de o efeito ser considerado. A escolha do jogador
não está sendo decidida pelo que a tecnologia FAZ, e sim por qual recurso ela PEDE.

#### Dois achados de desenho, e o segundo é erro meu

1. **A dispersão de custo domina tudo.** Enquanto a razão entre a tecnologia mais barata e a mais
   cara for de 34× (2,25 vs 76,67), não há escolha a fazer — há uma ordem de preço.
2. ⚠️ **Três tecnologias têm efeito sem sentido**: `tec_ciencia_1` e `tec_defesa_1` dão
   `producao_bonus` ao Laboratório e à Torre de Defesa, que **não produzem recurso nenhum**. O bônus
   é matematicamente zero. Isso é defeito do catálogo que eu semeei, não do balanceamento — e o
   simulador o expôs na primeira rodada.

#### O que fica para arbitragem

Nenhum número foi promovido. As saídas possíveis, e é decisão do usuário:

- **achatar a dispersão de custo** — se toda tecnologia custar aproximadamente o mesmo em Fert$, o
  efeito volta a decidir;
- **dar efeitos mensuráveis a Ciência e Defesa** — bônus de produção em prédio que não produz é
  inerte por construção;
- **modelar comércio e logística** para as trilhas correspondentes entrarem no páreo (hoje elas saem
  como "não medível", e não porque sejam ruins).

⚠️ O que este comando **não** mede: só `producao_bonus`. Desconto de tributo, velocidade e capacidade
de veículo dependem de volume de comércio e de logística, que o recorte não modela — e chutar um
volume seria inventar justamente o número que decide a resposta.

## 8.2 Regra de custo

Pesquisa consome recursos existentes no jogo.

Não criar “Pontos de Pesquisa” nesta fase.

## 8.3 Objetivo de escolha

Se a maioria dos jogadores pesquisar a mesma sequência, a árvore falhou.

Métricas:

- distribuição por trilha;
- primeira escolha;
- segunda escolha;
- tempo até bifurcação;
- percentual que pesquisa todas as linhas;
- benefício econômico realizado.

---

# 9. Especialização — modelo de balanceamento

A especialização **é a trilha de pesquisa** (GDD ALPHA 2 §8.1): não há escolha declarada de perfil, e por isso as medições abaixo são feitas **por trilha pesquisada**, não por um campo de perfil escolhido pelo jogador.

Especialização precisa ser forte.

Entretanto, bônus forte exige custo de oportunidade forte.

Para cada especialização medir:

1. ganho bruto;
2. consumo adicional;
3. população usada;
4. infraestrutura exigida;
5. dependências;
6. importações;
7. exportações;
8. retorno do investimento;
9. risco territorial;
10. sinergia com pesquisa.

### Indicador principal

**vantagem comparativa**, não apenas produção absoluta.

Uma especialização é saudável quando:

- gera excedente comerciável;
- cria necessidade de comprar algo;
- não domina toda a economia;
- tem pelo menos um contrapeso.

---

# 10. Federação — parâmetros iniciais confirmados

| Parâmetro | Valor | Estado | Justificativa |
|---|---:|---|---|
| Máximo de membros | 12 | DIRETRIZ APROVADA | Preserva grupos coordenáveis e reduz risco de megabloco único |
| Líder | 1 | DIRETRIZ APROVADA | Governança |
| Diplomata | 1 função | DIRETRIZ APROVADA | Relações externas |

Outros papéis permanecem sujeitos à revisão do sistema atual.

### Métricas

- tamanho médio;
- percentual no teto;
- concentração econômica;
- concentração territorial;
- número de Federações viáveis;
- migração de membros;
- alianças;
- guerras.

---

# 11. Eventos — balanceamento

A primeira versão do motor mexe em **produção e consumo apenas** (GDD ALPHA 2 §12.1.1). Preço fica fora, e evento nunca escreve no ledger — ele altera a taxa, e quem credita é o tick.

Um evento precisa ter **impacto perceptível**, mas não destruição arbitrária.

Para cada evento registrar:

- baseline afetado;
- modificador;
- duração;
- população afetada;
- recursos afetados;
- previsão de impacto;
- resultado real;
- ganhadores/perdedores;
- reação de mercado;
- concentração;
- recuperação pós-evento.

### Evento negativo saudável

Deve criar pelo menos uma resposta possível:

- substituir insumo;
- importar;
- estocar;
- negociar;
- cooperar;
- defender;
- mudar rota;
- cumprir missão;
- explorar oportunidade.

---

# 12. Endurance — raridade e economia

Classes iniciais:

- comum;
- raro;
- único.

Não definir aqui taxas de drop sem simulação.

## Item único

**Só o único é instanciado.** O catálogo atual é fungível e permanece: itens existentes viram `comum`, `raro` é escasso mas segue fungível, e apenas o `único` recebe linha de instância (GDD ALPHA 2 §11.1).

Deve ter:

- `item_instance_id`;
- origem;
- data de descoberta;
- descobridor;
- proprietário atual;
- histórico de transferência;
- estado;
- autenticidade verificável.

### Telemetria

- quantidade por raridade;
- concentração;
- preço;
- tempo de retenção;
- rotatividade;
- estoque ainda não descoberto;
- impacto de eventos.

---

# 13. Veículos

Antes de escolher números de upgrade, cada nível deve responder:

**“O que melhora?”**

**Decidido:** o nível aumenta a **capacidade** e aumenta a **manutenção** junto. Um eixo, com contrapartida.

**Velocidade não é atributo de nível** — é traço do tipo de veículo (Furgão × Caminhão). Se o nível acelerasse, a distância encolheria a cada upgrade, e distância é pilar do jogo.

As demais dimensões — consumo, durabilidade, tempo de carga, alcance, confiabilidade — permanecem em aberto e **não devem ser acrescentadas em conjunto**: melhorar tudo simultaneamente sem custo é exatamente o que esta seção existe para impedir.

Números a definir por simulação (trilha A2.S):

- `vehicle_capacity[type][level]`
- `vehicle_maintenance[type][level]`
- custo e tempo do upgrade

Todos PENDENTE.

---

# 14. Estoques

Capacidade é um instrumento econômico.

Deve influenciar:

- risco;
- planejamento;
- frequência de login;
- mercado;
- defesa;
- logística.

**Decidido: o teto trava, não derrama.** Ao encher, a produção **para**; nada transborda e vira desperdício. O jogador perde oportunidade, nunca estoque.

É o que reconcilia as duas exigências que brigam aqui: a §14 quer capacidade como instrumento econômico, e o GDD §1.1 proíbe exigir login constante. Um teto que descarta o excedente puniria quem passou o dia fora; um teto que trava apenas suspende o ganho e devolve a decisão ao jogador — construir armazenamento ou aceitar a parada.

Hoje só o Tanque de Combustível tem teto real; o Silo protege de saque, mas não limita. Os demais recursos não têm teto nenhum.

Capacidade baixa demais transforma o jogo em obrigação de login.

Capacidade alta demais torna estoque irrelevante.

Avaliar sempre em função do ritmo de produção por hora e do intervalo real entre sessões.

---

# 15. Sessões de 5–10 minutos: regra prática

Ao analisar qualquer sistema, verificar:

1. O jogador consegue entender seu estado em menos de 1 minuto?
2. Existe uma decisão útil que pode tomar?
3. Consegue iniciar uma ação e sair?
4. O resultado pode ocorrer offline?
5. Ao voltar, consegue compreender o resultado?
6. O sistema pune excessivamente quem não fica conectado?

Se qualquer resposta for “não”, revisar UX ou balanceamento.

---

# 16. Processo de mudança de número

Toda alteração relevante deve seguir:

## Antes

- hipótese;
- dados atuais;
- simulação;
- impacto esperado;
- risco;
- rollback.

## Durante

- versão do parâmetro;
- data/hora;
- coorte/servidor;
- telemetria.

## Depois

- comparação;
- efeito colateral;
- decisão: manter/reverter/ajustar;
- registro D-n quando alterar regra ou baseline canônico.

---

# 17. Registro de alterações de balanceamento

Modelo:

```text
BAL-0001
Data:
Sistema:
Parâmetro:
Valor anterior:
Valor novo:
Estado: HIPÓTESE | EXPERIMENTO | BASELINE | CANÔNICO
Motivo:
Dados usados:
Impacto esperado:
Resultado observado:
Decisão relacionada:
Responsável:
```

---

# 18. Primeira campanha de medição da Alpha 2

Antes de ajustes agressivos:

1. instrumentar mundo atual sem reset;
2. medir testadores humanos;
3. rodar ciclos longos;
4. medir inflação;
5. medir gargalos;
6. medir concentração;
7. medir tempo de progressão;
8. implementar população;
9. repetir;
10. implementar pesquisa;
11. repetir;
12. implementar especialização forte;
13. repetir.

**Sem reset do mundo atual.**

O staging (`staging.tars.art.br`) é ambiente separado, com servidor e banco próprios, e é onde os **bots externos** jogam — eles não fazem parte do código do Fertways (ver GDD ALPHA 2 §14). O staging pode ser resetável para experimentos; o mundo principal permanece intacto e **não recebe bots**.

⚠️ **Consequência para o método.** A §3 desta política mandava “rodar bots” antes de promover um número a baseline. Com os bots fora do repositório e fora do calendário da Alpha 2, esse passo ficou sem executor.

O substituto decidido é a **trilha A2.S** (simulador de balanceamento, ROADMAP): um comando `artisan` que roda o código de domínio real contra mundo descartável em memória. Ela nasce dentro da A2.2. Enquanto não existir, número novo permanece **BASELINE**, sustentado por testadores humanos e julgamento do Dono, e não sobe a CANÔNICO por simulação.
