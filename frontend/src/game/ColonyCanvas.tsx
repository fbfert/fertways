import { useEffect, useRef } from 'react'
import Phaser from 'phaser'
import { ColonyScene } from './ColonyScene'
import type { Spec } from '../api/client'

type Props = {
  specs: Spec[]
  onSelecionar: (spec: Spec) => void
}

/**
 * Ponte React -> Phaser. O jogo é criado uma única vez; mudanças de dados entram por
 * `cena.atualizar()`. Recriar o Phaser a cada render descartaria o canvas e piscaria a tela.
 */
export function ColonyCanvas({ specs, onSelecionar }: Props) {
  const container = useRef<HTMLDivElement>(null)
  const jogo = useRef<Phaser.Game | null>(null)
  const cena = useRef<ColonyScene | null>(null)

  // `onSelecionar` muda a cada render do pai; guardamos numa ref para não recriar o listener.
  const aoSelecionar = useRef(onSelecionar)
  aoSelecionar.current = onSelecionar

  useEffect(() => {
    if (!container.current || jogo.current) return

    const c = new ColonyScene((s: Spec) => aoSelecionar.current(s))
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
    cena.current?.atualizar(specs)
  }, [specs])

  return <div ref={container} className="h-full w-full" />
}
