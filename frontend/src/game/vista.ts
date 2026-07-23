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
 * **O idioma é o do mapa do planeta**, de propósito: roda do mouse, botões −/+, "centralizar", e
 * pinça de dois dedos (docs/decisoes.md D-154). O jogador aprende uma vez e usa nos três lugares.
 * **O zoom não persiste** entre aberturas — a tela abre sempre enquadrada, e quem volta depois de
 * semanas não encontra a câmera onde a esqueceu.
 *
 * **A pinça ancora no CENTRO da tela, não nos dedos** — mesmo idioma que a roda do mouse já tem
 * aqui (ela também ignora onde o cursor está). O deslocamento do ponto médio entre um frame de
 * `pointermove` e o anterior soma direto em `dx`/`dy`: pan e zoom saem do mesmo gesto sem precisar
 * resolver "que ponto do jogo estava sob os dedos" — só compara com o frame de ANTES, nunca com o
 * início do gesto, o que evita divergir depois de muitos frames.
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

/** A distância e o ponto médio entre dois pontos de tela — usada pela pinça, abaixo. */
function distanciaEMeio(a: { x: number; y: number }, b: { x: number; y: number }) {
  return { dist: Math.hypot(a.x - b.x, a.y - b.y), meio: { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 } }
}

/**
 * O estado da câmera, e os gestos que a movem.
 *
 * Devolve também o `aoRolar` e os handlers de arraste, para que o contêiner os pendure no elemento
 * certo — o `div` que embrulha o canvas.
 */
export function useVista() {
  const [vista, setVista] = useState<Vista>(VISTA_INICIAL)
  // Um dedo (ou o mouse) arrasta; dois fazem pinça. A chave é o `pointerId` — sem isto não dá pra
  // saber, no meio do gesto, quais dois pontos de tela pertencem a quais dedos.
  const ponteiros = useRef<Map<number, { x: number; y: number }>>(new Map())
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

    ponteiros.current.set(e.pointerId, { x: e.clientX, y: e.clientY })
    ;(e.currentTarget as HTMLElement).setPointerCapture(e.pointerId)
  }

  const aoMover = (e: React.PointerEvent) => {
    if (!ponteiros.current.has(e.pointerId)) return

    const antes = [...ponteiros.current.values()]
    ponteiros.current.set(e.pointerId, { x: e.clientX, y: e.clientY })
    const agora = [...ponteiros.current.values()]

    if (agora.length >= 2) {
      // Pinça: só os dois primeiros ponteiros contam — uma eventual terceira ponta (raro, mas
      // possível) é ignorada, não estraga a conta dos dois primeiros.
      const p = distanciaEMeio(antes[0], antes[1])
      const q = distanciaEMeio(agora[0], agora[1])
      if (p.dist === 0 || q.dist === 0) return

      const fator = q.dist / p.dist
      const dxMeio = q.meio.x - p.meio.x
      const dyMeio = q.meio.y - p.meio.y

      setVista((v) => {
        const escala = limitarEscala(v.escala * fator)
        const k = escala / v.escala

        return { escala, dx: v.dx * k + dxMeio, dy: v.dy * k + dyMeio }
      })

      return
    }

    const a = antes[0]
    const b = agora[0]
    setVista((v) => ({ ...v, dx: v.dx + (b.x - a.x), dy: v.dy + (b.y - a.y) }))
  }

  const aoSoltar = (e: React.PointerEvent) => {
    ponteiros.current.delete(e.pointerId)
  }

  return {
    vista,
    alvo,
    ampliar,
    centralizar,
    arrastando: ponteiros.current.size > 0,
    gestos: {
      onPointerDown: aoPressionar,
      onPointerMove: aoMover,
      onPointerUp: aoSoltar,
      onPointerCancel: aoSoltar,
      onPointerLeave: aoSoltar,
    },
  }
}
