import { LADO_SVG, calhaDe, multiplos } from './geometria'
import type { Caixa, FaixaDeCelulas, Projecao } from './geometria'

/**
 * O que se desenha da grade do mapa: as linhas de X e de Y, as réguas de coordenadas, o chão do
 * planeta, as faixas do centro e o realce da célula sob o cursor (D-64).
 *
 * Todos recebem uma `Projecao` (ver `geometria.ts`) e por isso servem tanto ao `Mapa`, que navega
 * uma janela sobre a grade 101×101, quanto à `Fundacao`, que amplia o disco de founders.
 *
 * **As réguas ficam numa calha fora da área do mapa**, e não sobrepostas a ela: o viewBox de quem
 * usa estes componentes é maior que o desenho (`viewBoxComReguas`), sobrando uma faixa em cima
 * para o X e outra à esquerda para o Y. Elas não escorregam com o arraste, porque a calha é
 * sempre a borda da janela visível, seja ela qual for.
 */

/**
 * As linhas de X e de Y. Uma linha por *borda* de célula — o número da régua fica no meio da
 * coluna, entre duas linhas, como numa planilha.
 *
 * Os dois eixos que passam pela Capital (x=0 e y=0) saem mais fortes e independem do passo: são a
 * referência do sinal das coordenadas, e sumirem quando o passo abre seria perder o norte.
 */
export function Grade({
  proj,
  faixa,
  passo,
  k = 1,
}: {
  proj: Projecao
  faixa: FaixaDeCelulas
  passo: number
  /** O fator do zoom (1/escala), para o traço ficar com a mesma espessura na tela. */
  k?: number
}) {
  const { xDe, xAte, yDe, yAte } = faixa
  const meio = proj.passo / 2

  // A borda esquerda da coluna x, e a de baixo da linha y.
  const bx = (x: number) => proj.px(x) - meio
  const by = (y: number) => proj.py(y) + meio

  // As linhas atravessam a janela inteira: da borda de fora da primeira célula à da última.
  const topo = proj.py(yAte) - meio
  const base = proj.py(yDe) + meio
  const esq = proj.px(xDe) - meio
  const dir = proj.px(xAte) + meio

  // `xAte + 1` fecha a última coluna; sem ele a célula da ponta ficaria aberta.
  const colunas = multiplos(xDe, xAte + 1, passo)
  const linhas = multiplos(yDe, yAte + 1, passo)

  return (
    <g fill="none">
      <g stroke="var(--color-rust)" strokeOpacity={0.16} strokeWidth={k}>
        {colunas.map((x) => (
          <line key={`v${x}`} x1={bx(x)} y1={topo} x2={bx(x)} y2={base} />
        ))}
        {linhas.map((y) => (
          <line key={`h${y}`} x1={esq} y1={by(y)} x2={dir} y2={by(y)} />
        ))}
      </g>

      <g stroke="var(--color-rust)" strokeOpacity={0.45} strokeWidth={1.5 * k}>
        <line x1={proj.px(0)} y1={topo} x2={proj.px(0)} y2={base} />
        <line x1={esq} y1={proj.py(0)} x2={dir} y2={proj.py(0)} />
      </g>
    </g>
  )
}

/** Os números de X (em cima) e de Y (à esquerda), na calha. */
export function Reguas({
  proj,
  caixa,
  faixa,
  passo,
}: {
  proj: Projecao
  caixa: Caixa
  faixa: FaixaDeCelulas
  passo: number
}) {
  const { xDe, xAte, yDe, yAte } = faixa
  const g = calhaDe(caixa.lado)

  // O zero é o da Capital: sai destacado, para o jogador achar o referencial num relance.
  const cor = (v: number) => (v === 0 ? 'var(--color-rust)' : 'var(--color-ink-soft)')

  return (
    <g fontSize={g * 0.5} className="tabular-nums select-none" textAnchor="middle">
      <g data-regua-x>
        {multiplos(xDe, xAte, passo).map((x) => (
          <text
            key={`x${x}`}
            x={proj.px(x)}
            y={caixa.y0 - g * 0.45}
            fill={cor(x)}
            dominantBaseline="central"
          >
            {x}
          </text>
        ))}
      </g>

      <g data-regua-y>
        {multiplos(yDe, yAte, passo).map((y) => (
          <text
            key={`y${y}`}
            x={caixa.x0 - g * 0.5}
            y={proj.py(y)}
            fill={cor(y)}
            dominantBaseline="central"
          >
            {y}
          </text>
        ))}
      </g>
    </g>
  )
}

/**
 * O chão do planeta. Existe para a borda ser visível: desde o D-64 a vista pode passar da grade
 * (o jogador fica **sempre** no centro, mesmo fundado em (50,50)), e sem este retângulo o vazio
 * de fora do planeta seria indistinguível do planeta.
 */
export function Planeta({ k = 1 }: { k?: number }) {
  return (
    <rect
      x={0}
      y={0}
      width={LADO_SVG}
      height={LADO_SVG}
      fill="var(--color-sand-light)"
      stroke="var(--color-rust)"
      strokeOpacity={0.35}
      strokeWidth={2 * k}
    />
  )
}

/**
 * As faixas do centro, célula a célula (D-51): o disco de founders (0 < d ≤ 4) e o anel livre
 * (4 < d ≤ 5), onde ninguém funda.
 *
 * Sombreia as células de verdade, e não um círculo por cima delas, porque a faixa **é** um
 * conjunto de células: a fronteira aqui é a distância euclidiana exata, a mesma de
 * `MapaFertways::faixaDe`, e um círculo desenhado por cima mentiria justamente nas células da
 * beirada — que são as que importam. (Um `<circle>` também estragaria a contagem de círculos do
 * e2e do mapa, que conta colônias.)
 */
export function Faixas({
  proj,
  raioFounder,
  raioAnel,
}: {
  proj: Projecao
  raioFounder: number
  raioAnel: number
}) {
  const celulas: { x: number; y: number; founder: boolean }[] = []

  for (let x = -raioAnel; x <= raioAnel; x++) {
    for (let y = -raioAnel; y <= raioAnel; y++) {
      const d = Math.hypot(x, y)
      // (0,0) é a Capital, e o losango dela já marca a célula.
      if (d === 0 || d > raioAnel) continue
      celulas.push({ x, y, founder: d <= raioFounder })
    }
  }

  return (
    <g>
      {celulas.map((c) => (
        <rect
          key={`f${c.x}:${c.y}`}
          x={proj.px(c.x) - proj.passo / 2}
          y={proj.py(c.y) - proj.passo / 2}
          width={proj.passo}
          height={proj.passo}
          fill={c.founder ? 'var(--color-rust)' : 'var(--color-ink-soft)'}
          fillOpacity={c.founder ? 0.12 : 0.06}
        />
      ))}
    </g>
  )
}

/** O realce da célula sob o cursor. Leitura apenas: célula vazia não é alvo de clique (D-64). */
export function CelulaSobOCursor({
  proj,
  celula,
  k = 1,
}: {
  proj: Projecao
  celula: { x: number; y: number }
  k?: number
}) {
  return (
    <rect
      data-celula-cursor={`${celula.x},${celula.y}`}
      x={proj.px(celula.x) - proj.passo / 2}
      y={proj.py(celula.y) - proj.passo / 2}
      width={proj.passo}
      height={proj.passo}
      fill="var(--color-rust)"
      fillOpacity={0.1}
      stroke="var(--color-rust)"
      strokeWidth={1.5 * k}
      pointerEvents="none"
    />
  )
}
