/**
 * A geometria do mapa: a célula do jogo ↔ o ponto do SVG, e o enquadramento (D-64).
 *
 * Vive à parte dos componentes (`Grade.tsx`) porque duas telas desenham o mesmo planeta com
 * projeções diferentes — o `Mapa` navega uma janela 15×15 sobre a grade 101×101, e a `Fundacao`
 * mostra o disco de founders ampliado e o planeta inteiro. Uma `Projecao` é só o par de funções
 * que leva a célula (x, y, com sinal, Capital em (0,0)) ao ponto do SVG, mais o lado da célula;
 * tudo o mais é escrito em cima dela e serve às duas.
 *
 * O lado da grade e os raios das faixas NÃO moram aqui: vêm da API (D-51). O que mora aqui é só a
 * conta que não depende deles.
 */

/** Lado da região do planeta, em unidades do SVG. */
export const LADO_SVG = 1000

/** Quantas células o jogador vê ao abrir o mapa: uma janela 15×15 centrada na colônia dele. */
export const JANELA_PADRAO = 15

/**
 * A calha das réguas, como fração do lado da janela visível.
 *
 * Fração, e não unidades do SVG, de propósito: o viewBox cresce e encolhe com o zoom, e uma calha
 * proporcional cresce e encolhe junto — cancelando-o. O resultado é uma calha com a mesma
 * espessura em pixels em qualquer zoom, sem ninguém ter de medir o elemento na tela.
 */
export const CALHA = 0.075

/** A célula do jogo → o ponto do SVG. */
export type Projecao = {
  /** O centro da coluna x. */
  px: (x: number) => number
  /** O centro da linha y. Y cresce para cima, o SVG cresce para baixo: esta função espelha. */
  py: (y: number) => number
  /** O lado de uma célula, em unidades do SVG. */
  passo: number
}

/**
 * A janela visível do desenho, em unidades do SVG. Nasceu quadrada (`Fundacao` continua abrindo
 * assim); o `Mapa` em tela cheia (D-154/D-156) é retangular — a proporção do contêiner de verdade,
 * não a do planeta.
 */
export type Caixa = { x0: number; y0: number; largura: number; altura: number }

/** A faixa de células que a janela alcança. */
export type FaixaDeCelulas = { xDe: number; xAte: number; yDe: number; yAte: number }

/** O planeta inteiro dentro de LADO_SVG: a grade `side`×`side`, Capital no meio. */
export function projecaoDoPlaneta(side: number): Projecao {
  const meia = Math.floor(side / 2)

  return {
    px: (x) => ((x + meia + 0.5) / side) * LADO_SVG,
    py: (y) => LADO_SVG - ((y + meia + 0.5) / side) * LADO_SVG,
    passo: LADO_SVG / side,
  }
}

/** Uma vizinhança ampliada: a célula (0,0) no centro e o passo escolhido à mão. */
export function projecaoAmpliada(passo: number, centro: number): Projecao {
  return { px: (x) => centro + x * passo, py: (y) => centro - y * passo, passo }
}

/** A célula do jogo sob um ponto do planeta, dado em unidades do SVG. */
export function celulaEm(p: { x: number; y: number }, side: number): { x: number; y: number } {
  const meia = Math.floor(side / 2)

  return {
    x: Math.round((p.x / LADO_SVG) * side - meia - 0.5),
    y: Math.round(((LADO_SVG - p.y) / LADO_SVG) * side - meia - 0.5),
  }
}

/**
 * O ponto do SVG sob o cursor. `getScreenCTM` já embute o viewBox, a calha e o zoom — refazer a
 * conta a partir do `getBoundingClientRect` erraria justamente por causa da calha, que desloca a
 * origem do desenho para dentro do elemento.
 */
export function pontoNoSvg(
  svg: SVGSVGElement,
  e: { clientX: number; clientY: number },
): { x: number; y: number } | null {
  const ctm = svg.getScreenCTM()
  if (!ctm) return null
  const p = new DOMPoint(e.clientX, e.clientY).matrixTransform(ctm.inverse())

  return { x: p.x, y: p.y }
}

/**
 * As células cujo **centro** cai dentro da janela — as que ganham número na régua.
 *
 * Não é a mesma coisa que "as células que a janela toca": a da beirada aparece pela metade, e o
 * número dela cairia meio de fora, invadindo a calha do outro eixo. Quem risca linha, esse sim,
 * quer uma célula de folga em cada ponta (o recorte apara o que sobra).
 */
export function celulasNaJanela(caixa: Caixa, side: number): FaixaDeCelulas {
  const meia = Math.floor(side / 2)
  /** A coordenada de célula (fracionária) de um ponto do eixo. */
  const cel = (u: number) => (u / LADO_SVG) * side - meia - 0.5
  const preso = (v: number) => Math.min(meia, Math.max(-meia, v))

  return {
    xDe: preso(Math.ceil(cel(caixa.x0))),
    xAte: preso(Math.floor(cel(caixa.x0 + caixa.largura))),
    // O Y é espelhado: o topo do SVG é o maior y do jogo.
    yDe: preso(Math.ceil(cel(LADO_SVG - caixa.y0 - caixa.altura))),
    yAte: preso(Math.floor(cel(LADO_SVG - caixa.y0))),
  }
}

/** A mesma faixa, com uma célula de folga em cada ponta: a das linhas da grade. */
export function comFolga(f: FaixaDeCelulas, side: number): FaixaDeCelulas {
  const meia = Math.floor(side / 2)

  return {
    xDe: Math.max(-meia, f.xDe - 1),
    xAte: Math.min(meia, f.xAte + 1),
    yDe: Math.max(-meia, f.yDe - 1),
    yAte: Math.min(meia, f.yAte + 1),
  }
}

/** A espessura da calha para uma janela deste lado. */
export const calhaDe = (lado: number) => lado * CALHA

/**
 * O lado do elemento inteiro: a janela mais a calha **dos dois lados**.
 *
 * Os números moram só em cima (o X) e à esquerda (o Y), mas a calha é simétrica: o rótulo da
 * última coluna é centrado na borda de fora dela, e sem folga à direita ele sairia pela metade.
 * Foi o que aconteceu com o "50" e o "−50" na primeira versão.
 */
export const totalComReguas = (lado: number) => lado + 2 * calhaDe(lado)

/**
 * O viewBox que reserva a calha das réguas em volta da janela.
 *
 * A calha usa a MENOR das duas dimensões — numa janela retangular (D-156), uma calha proporcional
 * à largura ficaria enorme numa tela bem larga; à altura, ela fica do mesmo tamanho físico que já
 * tinha no caso quadrado (largura === altura), sem depender de qual eixo cresceu.
 */
export function viewBoxComReguas(c: Caixa): string {
  const g = calhaDe(Math.min(c.largura, c.altura))

  return `${c.x0 - g} ${c.y0 - g} ${c.largura + 2 * g} ${c.altura + 2 * g}`
}

/**
 * De quantas em quantas células desenhar linha e número. Com o planeta inteiro na tela, uma linha
 * por célula seriam 101 traços a 6 px um do outro — um borrão. O passo abre conforme se afasta.
 */
export function passoDaGrade(celulasVisiveis: number): number {
  if (celulasVisiveis <= 26) return 1
  if (celulasVisiveis <= 60) return 5

  return 10
}

/** Os múltiplos de `passo` dentro de [de, ate]. */
export function multiplos(de: number, ate: number, passo: number): number[] {
  const primeiro = Math.ceil(de / passo) * passo
  const fora: number[] = []
  for (let v = primeiro; v <= ate; v += passo) fora.push(v)

  return fora
}
