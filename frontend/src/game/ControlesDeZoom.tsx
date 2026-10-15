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
  canto = 'top-3 right-3',
}: {
  vista: Vista
  ampliar: (fator: number) => void
  centralizar: () => void
  /** Onde ficam. O padrão serve a cena dentro de uma moldura (Capital, Endurance). */
  canto?: string
}) {
  const enquadrado = vista.escala === 1 && vista.dx === 0 && vista.dy === 0

  // z-[26]: acima do header/barra de navegação (z-[25], global desde a reforma de navegação).
  // ⚠️ Mas o z-index só decide quem fica POR CIMA, não quem fica visível (D-268): na colônia, cujo
  // canvas ocupa a tela inteira por baixo do header flutuante, o canto de cima à direita punha os
  // botões sobre o "sair" (computador) e sobre o saldo (celular). Ali a colônia passa `canto` para
  // baixo, longe do header.
  return (
    <div className={`absolute ${canto} z-[26] flex flex-col gap-1`}>
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
