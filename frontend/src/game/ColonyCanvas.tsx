import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import Phaser from 'phaser'
import { colmeia, ColonyScene, rotulo } from './ColonyScene'
import { ControlesDeZoom } from './ControlesDeZoom'
import { useVista } from './vista'
import type { Spec } from '../api/client'

type Props = {
  specs: Spec[]
  /** A colmeia (4/4/5/4/4) vem da API — ver `Catalogo` em client.ts e o D-54. */
  linhas: number[]
  onSelecionar: (spec: Spec) => void
  onSlotVazio: (slot: number) => void
}

/**
 * Ponte React -> Phaser, e a camada de alvos de clique.
 *
 * O jogo é criado uma única vez; mudanças de dados entram por `cena.atualizar()`. Recriar o Phaser
 * a cada render descartaria o canvas e piscaria a tela.
 *
 * **Os cliques não são do Phaser: são de botões de DOM sobrepostos aos hexágonos.** Um canvas não
 * tem DOM — não tem foco, não tem `aria-label`, não responde a Tab, e o Chromium headless não
 * consegue clicar num alvo que só existe em pixels. Isso era tolerável enquanto o canvas fosse
 * decoração, mas desde o D-59 (item 6) ele é a navegação inteira: a Frota vive dentro da Central
 * de Transportes e os Acordos dentro do Mercado Local. Um jogo cuja única porta é um pixel não é
 * navegável por teclado nem testável de ponta a ponta.
 *
 * A geometria dos botões e a dos hexágonos saem da MESMA função (`colmeia`), então não há como
 * divergirem. O Phaser continua dono do desenho — inclusive do realce, que estes botões acendem ao
 * receberem o cursor ou o foco.
 */
export function ColonyCanvas({ specs, linhas, onSelecionar, onSlotVazio }: Props) {
  const container = useRef<HTMLDivElement>(null)
  const jogo = useRef<Phaser.Game | null>(null)
  const cena = useRef<ColonyScene | null>(null)
  const [tamanho, setTamanho] = useState({ largura: 0, altura: 0 })

  // O zoom (D-63) — mesmo idioma do mapa do planeta: roda, botões −/+, centralizar, arrastar.
  const { vista, alvo, ampliar, centralizar, arrastando, gestos } = useVista()

  useEffect(() => {
    if (!container.current || jogo.current) return

    const c = new ColonyScene()
    jogo.current = new Phaser.Game({
      type: Phaser.AUTO,
      parent: container.current,
      backgroundColor: '#f8e7d6',
      scale: { mode: Phaser.Scale.RESIZE, autoCenter: Phaser.Scale.CENTER_BOTH },
      scene: c,
    })
    cena.current = c

    return () => {
      jogo.current?.destroy(true)
      jogo.current = null
      cena.current = null
    }
  }, [])

  useEffect(() => {
    cena.current?.atualizar(specs, linhas, vista)
  }, [specs, linhas, vista])

  // Os botões precisam saber o tamanho do canvas para se colocarem sobre os hexágonos. É o mesmo
  // tamanho que o Phaser usa (o `RESIZE` casa o canvas com este contêiner).
  useLayoutEffect(() => {
    const alvo = container.current
    if (!alvo) return

    const medir = () =>
      setTamanho({ largura: alvo.clientWidth, altura: alvo.clientHeight })

    medir()
    const observador = new ResizeObserver(medir)
    observador.observe(alvo)

    return () => observador.disconnect()
  }, [])

  // A MESMA chamada que a cena faz para desenhar, com a MESMA vista. Não há duas contas, então não
  // há como o botão e o hexágono se afastarem quando o colono aproxima (D-63).
  const { r, centros } =
    linhas.length && tamanho.largura
      ? colmeia(linhas, tamanho.largura, tamanho.altura, vista)
      : { r: 0, centros: [] as [number, number][] }

  const porSlot = new Map(specs.map((s) => [s.slot, s]))

  return (
    <div
      ref={(el) => {
        container.current = el
        alvo.current = el
      }}
      {...gestos}
      className="relative h-full w-full"
      style={{ cursor: arrastando ? 'grabbing' : 'grab', touchAction: 'none' }}
    >
      <ControlesDeZoom vista={vista} ampliar={ampliar} centralizar={centralizar} />

      {centros.map(([x, y], slot) => {
        const spec = porSlot.get(slot)
        const nome = spec
          ? `${rotulo(spec.type)}, ${spec.level > 0 ? `nível ${spec.level}` : 'em obra'}`
          : `Slot ${slot}, vazio — construir aqui`

        return (
          <button
            key={slot}
            onClick={() => (spec ? onSelecionar(spec) : onSlotVazio(slot))}
            onMouseEnter={() => cena.current?.realcar(slot)}
            onMouseLeave={() => cena.current?.realcar(null)}
            onFocus={() => cena.current?.realcar(slot)}
            onBlur={() => cena.current?.realcar(null)}
            aria-label={nome}
            title={nome}
            /*
             * Um quadrado inscrito no hexágono, não o hexágono inteiro: os cantos de dois
             * hexágonos vizinhos se aproximam, e alvos retangulares do tamanho cheio se
             * sobreporiam — o de cima roubaria o clique do de baixo. Com 1,4·r de lado, cada botão
             * cabe folgado dentro do seu losango e nenhum invade o vizinho.
             */
            style={{
              position: 'absolute',
              left: x - r * 0.7,
              top: y - r * 0.7,
              width: r * 1.4,
              height: r * 1.4,
              background: 'transparent',
              border: 'none',
              borderRadius: '50%',
              cursor: 'pointer',
              padding: 0,
            }}
            className="focus-visible:ring-rust focus-visible:ring-2 focus-visible:outline-none"
          />
        )
      })}
    </div>
  )
}
