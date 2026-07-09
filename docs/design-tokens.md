# Tokens visuais — extraídos do deck de apresentação

Nenhuma cor foi escolhida a olho. Todas foram **amostradas dos PNGs** de `/home/fertways/pitch`
por um decodificador PNG mínimo (`tools/sample_png.py`), buscando o laranja mais saturado na faixa
de matiz 10°–35°, o escuro quente de texto e as superfícies claras.

## Paleta

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

## Formas

- Painéis com cantos arredondados grandes e um **entalhe** no canto (chanfro), não retângulos secos.
- **Hexágono** é o motivo repetido: logo, ícones, marcadores de mapa, badges de numeração.
- Linhas finas laranja separando blocos, com um losango pequeno no centro.
- Títulos em peso alto, condensados; subtítulos em versalete com espaçamento entre letras.

## Achado que afeta o backend

O slide 07 ("Nada nasce pronto") apresenta **Componentes** como três recursos distintos —
Básicos, Intermediários, Avançados — em cartões separados, na mesma hierarquia de Metal Bruto e
Ligas Metálicas.

Isso é evidência a favor da leitura em tiers que ficou **em aberto no D-23**, onde optamos por um
único recurso `componentes_eletronicos` porque as tabelas de custo do GDD usam um código só.
O deck não é normativo (a regra de ouro aponta para o GDD), mas registra a intenção de design.
Ver também D-24, sobre os três preços-base do §24.8.
