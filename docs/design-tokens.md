# Design System — A2.V1

A fonte de verdade é `frontend/src/index.css`. Este documento explica **por que** cada token é o
que é; o CSS é quem manda.

> **A regra da casa vale para cor como vale para número de GDD: não se escolhe a olho.**
> As sete cores de marca foram amostradas dos PNGs do deck (`tools/sample_png.py`). As cores de
> estado, que faltavam, saíram dos mesmos PNGs (`tools/amostra_estados.py`). E todos os pares em uso
> passaram por `tools/valida_contraste.py`, que **falha com status 1** se alguma combinação deixar de
> cumprir a WCAG. Os números abaixo vêm de lá.

## Paleta de marca

| Token | Hex | Onde aparece no deck |
|---|---|---|
| `rust` (marca) | `#B4450B` | cabeçalhos dos cartões de recurso (slide 07), hexágono do logo |
| `rust-bright` | `#CD5512` | acentos, setas de fluxo, ícones ativos |
| `ember` | `#EAAE65` | brilho/hover, bordas iluminadas |
| `sand` | `#F8E7D6` | fundo dos painéis, superfície principal |
| `sand-light` | `#FDF0E2` | cartões internos, listas |
| `ink` | `#1E1C17` | títulos e texto forte (preto quente, nunca `#000`) |
| `ink-soft` | `#372F27` | texto secundário |

O deck nunca usa preto puro nem branco puro em texto. O fundo é sempre quente.

## Paleta de estado

A paleta de marca não tinha como dizer "deu certo" ou "deu errado" — havia 120 menções a *erro* no
código e nenhuma cor de erro. Estas três foram amostradas do deck e são **escuras de propósito**: a
superfície do jogo é clara, então cor de estado só vira texto legível se for mais escura que o fundo.

| Token | Hex | Contraste sobre `sand` |
|---|---|---|
| `sucesso` | `#245448` | 7,14:1 |
| `perigo` | `#78180C` | 8,99:1 |
| `info` | `#243C48` | 9,58:1 |

### ⚠️ Duas armadilhas medidas, que o sistema resolve por você

**1. `ember` não é texto.** Sobre areia ele dá **1,62:1** — ilegível. Mas como *fundo*, com letra
`ink`, dá 8,71:1. Por isso o estado `aviso` é o único que sempre pinta o fundo, ao contrário de todos
os outros. Não é inconsistência; é a única forma que passa.

**2. O vermelho da paleta fica a 14° de matiz do `rust` da marca.** A paleta do FERTWAYS é quente por
identidade — não existe vermelho frio nela. Num relance, ou para quem tem deficiência de visão de cor,
*apagar para sempre* e *confirmar* são o mesmo botão.

> **A regra que sai daí: destrutivo nunca se anuncia só por cor.** O `Botao` de variante `perigo`
> desenha um triângulo antes do rótulo, e esse glifo **não é enfeite** — é o segundo canal, o que
> carrega o aviso quando a cor falha. O componente `Erro` escreve a palavra "Erro" pelo mesmo motivo.

### Pares proibidos

`tools/valida_contraste.py` também guarda o que **não** pode passar. Se um dia passarem, a proibição
virou folclore e o comentário que a explica sai junto.

| Combinação | Medido | Por quê |
|---|---|---|
| texto `ink` sobre fundo `rust` | 3,08:1 | fundo rust pede texto claro |
| `ember` como texto sobre `sand` | 1,62:1 | ember é decorativo, nunca letra |
| texto `sand-light` sobre `ember` | 1,74:1 | ember só aceita letra escura |

Margem apertada digna de nota: **`rust` sobre `sand` passa por 4,58:1** — folga de 0,08 sobre o
mínimo. Não é número que se defenda de memória; é por isso que o validador existe.

## Hairline

A linha fina que separa blocos existia em **seis** opacidades (`/10`, `/15`, `/20`, `/25`, `/30`,
`/40`) dizendo a mesma coisa. São duas:

- `hairline` — `#B4450B33` (rust a 20%), o padrão;
- `hairline-forte` — `#B4450B66` (rust a 40%), a divisão que precisa pesar.

## Tipografia

- `--text-micro` (0,625rem) — o degrau abaixo de `text-xs`, para rótulo de selo e de eixo. Nasceu
  porque onze lugares escreviam `text-[9px]`, `text-[10px]` e `text-[0.6rem]` para o mesmo efeito.
  Os onze foram convertidos; **não há mais tamanho arbitrário em `.tsx`**.
- O resto da escala é a do Tailwind, e continua sendo. Uma escala paralela só brigaria com ela.

### ⚠️ Pendência real: a fonte nunca foi carregada

`--font-sans` declara `'Archivo'`, mas **não há `@font-face`, nem link, nem arquivo local** — todo
mundo cai em `system-ui`. Os títulos condensados de peso alto do deck não existem no produto hoje.

Carregar a fonte é uma decisão em aberto, com custo dos dois lados: auto-hospedar acrescenta peso ao
bundle (que já dá 1,9 MB); CDN acrescenta uma dependência externa e um RTT. **Não decidido aqui de
propósito.**

## Espaçamento e raio

Não há tokens novos, e isso é deliberado: a escala de 4px do Tailwind já é o sistema de espaçamento.
O que faltava era regra de uso, não número novo.

- **Raio** — `rounded` para controles (botão, campo, selo); `rounded-full` para pílulas e avatares;
  e **`.painel`** para superfícies, que não é raio nenhum: é o **chanfro** do deck. Painel do jogo
  não tem canto arredondado, tem entalhe.
- **Formas do deck** — o **hexágono** (`.hex`) é o motivo repetido: logo, ícones, marcadores de mapa,
  badges. Linhas finas laranja separando blocos. Títulos em peso alto; subtítulos em versalete
  espaçado (`.eyebrow`).

## Acessibilidade

- **Foco visível por padrão.** `:focus-visible` no `index.css` desenha um contorno `rust-bright` de
  2px em qualquer elemento que ninguém pensou em estilizar. Quem quer foco próprio usa `outline-none`
  mais um `focus-visible:ring-*` — 55 lugares já faziam isso e continuam mandando.
- **Movimento reduzido.** `prefers-reduced-motion` desliga animação e transição para quem pediu isso
  no sistema operacional.
- **Estado não se anuncia só por cor** — ver a armadilha nº 2 acima.
- `Carregando` usa `role="status"` com `aria-live="polite"`; `Erro` usa `role="alert"`, que
  interrompe. A diferença é intencional: carregar espera uma pausa, erro não.

## Primitivos

Em `frontend/src/ui/sistema/`. Importe do barril: `import { Botao, Selo } from './sistema'`.

| Componente | Para quê |
|---|---|
| `Botao` | variantes `primaria`, `secundaria`, `perigo`, `fantasma`; estados de carregando e desabilitado |
| `Cartao` | a superfície com chanfro, mais o cabeçalho padrão (eyebrow + título + ação) |
| `Selo` | a etiqueta de estado, nos tons `claro` (texto colorido) e `forte` (fundo colorido) |
| `Carregando` / `Vazio` / `Erro` | os três estados de toda tela que busca dados |

**O par de cores não é parâmetro de nenhum deles.** Quem usa escolhe a intenção — "isto é
destrutivo" —, e o componente escolhe fundo e texto juntos, dentro do que foi medido.

O `Popup` (modal) **não** mora ali: é anterior ao design system, já resolve bem as três formas de
fechar (clicar fora, Esc, ×) e é usado por meia dúzia de telas. Fica onde está até a A2.V2.

### O que a A2.V1 não fez

Os 171 `<button>` espalhados por 26 arquivos **não foram migrados** para o `Botao`. É de propósito e
está no roadmap: a A2.V1 constrói o sistema, e **V2 a V6 o aplicam, coladas em cada fase**. Migrar
tudo de uma vez seria um diff gigante sem tela nova para provar que o sistema serve.

## Achado que afeta o backend

O slide 07 ("Nada nasce pronto") apresenta **Componentes** como três recursos distintos —
Básicos, Intermediários, Avançados — em cartões separados, na mesma hierarquia de Metal Bruto e
Ligas Metálicas.

Isso é evidência a favor da leitura em tiers que ficou **em aberto no D-23**, onde optamos por um
único recurso `componentes_eletronicos` porque as tabelas de custo do GDD usam um código só.
O deck não é normativo (a regra de ouro aponta para o GDD), mas registra a intenção de design.
Ver também D-24, sobre os três preços-base do §24.8.
