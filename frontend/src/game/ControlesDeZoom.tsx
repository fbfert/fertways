import type { Vista } from './vista'
import { ZOOM_MAX, ZOOM_MIN } from './vista'

/**
 * Os botões de zoom das cenas (D-63). **São de DOM, e não do canvas** — pela mesma razão pela qual os
 * hexágonos também são: um canvas não responde a Tab, não tem `aria-label`, e o e2e não clica nele.
 *
 * O idioma é o do mapa do planeta, de propósito: **−**, **+** e **centralizar**. O jogador aprende
 * uma vez e usa nos três lugares.
 */
export function ControlesDeZoom({
  vista,
  ampliar,
  centralizar,
}: {
  vista: Vista
  ampliar: (fator: number) => void
  centralizar: () => void
}) {
  const enquadrado = vista.escala === 1 && vista.dx === 0 && vista.dy === 0

  // z-[26]: acima do header/barra de navegação (z-[25], global desde a reforma de navegação) —
  // sem isto, os cartões do canto direito do header cobrem estes botões quando as duas coisas
  // caem na mesma região da tela (a colônia, cujo canvas ocupa a tela inteira por baixo do
  // header flutuante).
  return (
    <div className="absolute top-3 right-3 z-[26] flex flex-col gap-1">
      <button
        onClick={() => ampliar(1.25)}
        disabled={vista.escala >= ZOOM_MAX}
        aria-label="Aproximar"
        data-zoom-mais
        className="border-rust/30 bg-sand-light text-rust hover:bg-rust hover:text-sand-light disabled:text-ink-soft/30 h-8 w-8 border text-lg leading-none font-black disabled:cursor-not-allowed disabled:hover:bg-transparent"
      >
        +
      </button>
      <button
        onClick={() => ampliar(1 / 1.25)}
        disabled={vista.escala <= ZOOM_MIN}
        aria-label="Afastar"
        data-zoom-menos
        className="border-rust/30 bg-sand-light text-rust hover:bg-rust hover:text-sand-light disabled:text-ink-soft/30 h-8 w-8 border text-lg leading-none font-black disabled:cursor-not-allowed disabled:hover:bg-transparent"
      >
        −
      </button>
      <button
        onClick={centralizar}
        disabled={enquadrado}
        aria-label="Centralizar"
        title="Voltar ao enquadramento"
        data-zoom-centralizar
        className="border-rust/30 bg-sand-light text-rust hover:bg-rust hover:text-sand-light disabled:text-ink-soft/30 h-8 w-8 border text-xs leading-none font-black disabled:cursor-not-allowed disabled:hover:bg-transparent"
      >
        ⌖
      </button>
    </div>
  )
}
