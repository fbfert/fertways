import { useCallback, useEffect, useRef, useState } from 'react'

/**
 * A câmera das cenas de Phaser — colônia e Capital (D-63).
 *
 * ---
 *
 * **Por que o zoom NÃO é o da câmera do Phaser.**
 *
 * Nestas cenas o Phaser **pinta**, mas quem **recebe o clique** são botões de DOM sobrepostos ao
 * canvas (ver `ColonyCanvas`) — um canvas não tem foco, não responde a Tab, não tem `aria-label`, e
 * o Chromium do e2e não consegue clicar num alvo que só existe em pixels. Desde o D-59 a navegação
 * inteira do jogo passa por esses hexágonos.
 *
 * Se o zoom fosse `camera.setZoom()`, o desenho aproximaria e **os botões ficariam onde estavam** —
 * o colono veria a Oficina grande no meio da tela e clicaria nela para acertar o vizinho. Consertar
 * isso exigiria repetir a matemática da câmera do Phaser na camada de DOM, e são **duas contas que
 * divergem** — exatamente o que a função única de geometria existe para impedir.
 *
 * Então a vista entra **na geometria**: `colmeia(..., vista)` devolve os centros já transformados, e
 * a cena e os botões leem os mesmos números. Não há como divergirem porque não há duas contas.
 *
 * ---
 *
 * **O idioma é o do mapa do planeta**, de propósito: roda do mouse, botões −/+, e "centralizar". O
 * jogador aprende uma vez e usa nos três lugares. **O zoom não persiste** entre aberturas — a tela
 * abre sempre enquadrada, e quem volta depois de semanas não encontra a câmera onde a esqueceu.
 */
export type Vista = { escala: number; dx: number; dy: number }

export const VISTA_INICIAL: Vista = { escala: 1, dx: 0, dy: 0 }

/** 1 enquadra tudo. Aproximar além de 3× faz o hexágono estourar a tela sem ganhar informação. */
export const ZOOM_MIN = 0.6
export const ZOOM_MAX = 3

export const limitarEscala = (v: number) => Math.min(ZOOM_MAX, Math.max(ZOOM_MIN, v))

/** Aplica a vista a um ponto. É a única transformação, e a cena e os botões usam esta mesma. */
export function transformar(
  x: number,
  y: number,
  largura: number,
  altura: number,
  vista: Vista,
): [number, number] {
  // O zoom acontece em torno do CENTRO da tela, não do canto: aproximar não pode empurrar a cena
  // para fora do enquadramento.
  const cx = largura / 2
  const cy = altura / 2

  return [cx + (x - cx) * vista.escala + vista.dx, cy + (y - cy) * vista.escala + vista.dy]
}

/**
 * O estado da câmera, e os gestos que a movem.
 *
 * Devolve também o `aoRolar` e os handlers de arraste, para que o contêiner os pendure no elemento
 * certo — o `div` que embrulha o canvas.
 */
export function useVista() {
  const [vista, setVista] = useState<Vista>(VISTA_INICIAL)
  const arrastando = useRef<{ x: number; y: number } | null>(null)
  const alvo = useRef<HTMLDivElement | null>(null)

  const ampliar = useCallback((fator: number) => {
    setVista((v) => {
      const escala = limitarEscala(v.escala * fator)
      if (escala === v.escala) return v

      // O deslocamento acompanha a escala, senão aproximar arrastaria a cena para o lado.
      const k = escala / v.escala

      return { escala, dx: v.dx * k, dy: v.dy * k }
    })
  }, [])

  const centralizar = useCallback(() => setVista(VISTA_INICIAL), [])

  /*
   * A roda do mouse precisa de `passive: false` para que o `preventDefault` valha — e o React não
   * deixa passar essa opção pelo `onWheel`. Sem isso, aproximar a colônia rolaria a página inteira
   * junto, que é o pior dos dois mundos.
   */
  useEffect(() => {
    const el = alvo.current
    if (!el) return

    const aoRolar = (e: WheelEvent) => {
      e.preventDefault()
      ampliar(e.deltaY < 0 ? 1.12 : 1 / 1.12)
    }

    el.addEventListener('wheel', aoRolar, { passive: false })

    return () => el.removeEventListener('wheel', aoRolar)
  }, [ampliar])

  const aoPressionar = (e: React.PointerEvent) => {
    // Só o arraste do fundo move a câmera. Um arraste que comece num botão de slot é um clique
    // trêmulo, não uma panorâmica — e roubar esse gesto tornaria os hexágonos difíceis de acertar.
    if ((e.target as HTMLElement).closest('button')) return

    arrastando.current = { x: e.clientX, y: e.clientY }
    ;(e.currentTarget as HTMLElement).setPointerCapture(e.pointerId)
  }

  const aoMover = (e: React.PointerEvent) => {
    const a = arrastando.current
    if (!a) return

    const dx = e.clientX - a.x
    const dy = e.clientY - a.y
    arrastando.current = { x: e.clientX, y: e.clientY }

    setVista((v) => ({ ...v, dx: v.dx + dx, dy: v.dy + dy }))
  }

  const aoSoltar = () => {
    arrastando.current = null
  }

  return {
    vista,
    alvo,
    ampliar,
    centralizar,
    arrastando: arrastando.current !== null,
    gestos: { onPointerDown: aoPressionar, onPointerMove: aoMover, onPointerUp: aoSoltar, onPointerLeave: aoSoltar },
  }
}
