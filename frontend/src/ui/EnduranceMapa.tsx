import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import { carregarArte } from '../game/arte'
import type { Arte } from '../game/arte'
import { ControlesDeZoom } from '../game/ControlesDeZoom'
import { transformar, useVista } from '../game/vista'

/**
 * O campo de destroços da Endurance (D-132) — as 8 seções do casco, espalhadas e clicáveis.
 *
 * **DOM puro, não Phaser.** As oito imagens já existem como arte de verdade (D-132, importadas do
 * lote `structures.zip`); não há vetor para desenhar nem textura para carregar. A câmera é a MESMA
 * das outras cenas (`useVista()` — roda do mouse, arrastar, botões −/+/centralizar), só que aplicada
 * a `<img>` posicionadas por CSS em vez de a um canvas: um botão real, testável, com foco e
 * `aria-label` — o problema que fez os sete ministérios da Capital "saírem pálidos" no e2e (D-63)
 * não existe aqui porque não há canvas nenhum no meio.
 *
 * Posições normalizadas (0..1), arbitradas — o GDD não desenha uma planta dos destroços.
 */
const SECOES: { chave: string; nome: string; cx: number; cy: number }[] = [
  { chave: 'comando', nome: 'Comando', cx: 0.5, cy: 0.16 },
  { chave: 'anel_habitacional', nome: 'Anel Habitacional', cx: 0.18, cy: 0.32 },
  { chave: 'matriz_comunicacao', nome: 'Matriz de Comunicação', cx: 0.8, cy: 0.3 },
  { chave: 'baia_criogenica', nome: 'Baía Criogênica', cx: 0.14, cy: 0.62 },
  { chave: 'modulo_medico', nome: 'Módulo Médico', cx: 0.5, cy: 0.52 },
  { chave: 'secao_acoplagem', nome: 'Seção de Acoplagem', cx: 0.86, cy: 0.62 },
  { chave: 'silo_suprimentos', nome: 'Silo de Suprimentos', cx: 0.3, cy: 0.86 },
  { chave: 'nucleo_propulsao', nome: 'Núcleo de Propulsão', cx: 0.7, cy: 0.86 },
]

export function EnduranceMapa({
  aoAbrirLoja,
}: {
  aoAbrirLoja: (secao: string, nome: string) => void
}) {
  const [arte, setArte] = useState<Arte>({})
  const [tamanho, setTamanho] = useState({ largura: 0, altura: 0 })
  const container = useRef<HTMLDivElement>(null)
  const { vista, alvo, ampliar, centralizar, arrastando, gestos } = useVista()

  useEffect(() => {
    void carregarArte().then(setArte)
  }, [])

  // Mesmo padrão do `CapitalCanvas`: mede o contêiner e reage ao redimensionar, para a câmera e os
  // botões partilharem exatamente a mesma caixa.
  useLayoutEffect(() => {
    const el = container.current
    if (!el) return

    const medir = () => setTamanho({ largura: el.clientWidth, altura: el.clientHeight })
    medir()

    const observador = new ResizeObserver(medir)
    observador.observe(el)

    return () => observador.disconnect()
  }, [])

  return (
    <div
      ref={(el) => {
        container.current = el
        alvo.current = el
      }}
      {...gestos}
      className="border-rust/20 relative h-full w-full overflow-hidden border"
      style={{ cursor: arrastando ? 'grabbing' : 'grab', touchAction: 'none' }}
      data-mapa-endurance
    >
      <ControlesDeZoom vista={vista} ampliar={ampliar} centralizar={centralizar} />

      {tamanho.largura > 0 &&
        SECOES.map((s) => {
          const [x, y] = transformar(
            s.cx * tamanho.largura,
            s.cy * tamanho.altura,
            tamanho.largura,
            tamanho.altura,
            vista,
          )
          const lado = 110 * vista.escala
          const urls = arte[`endurance:secao:${s.chave}`]

          return (
            <button
              key={s.chave}
              onClick={() => aoAbrirLoja(s.chave, s.nome)}
              aria-label={`${s.nome} — abrir a Loja de Peças`}
              title={s.nome}
              data-destroco={s.chave}
              style={{
                position: 'absolute',
                left: x - lado / 2,
                top: y - lado / 2,
                width: lado,
                height: lado,
                background: 'transparent',
                border: 'none',
                padding: 0,
                cursor: 'pointer',
              }}
              className="focus-visible:ring-rust group flex flex-col items-center justify-center gap-1 focus-visible:ring-2 focus-visible:outline-none"
            >
              {urls ? (
                <img
                  src={urls.pequena}
                  alt=""
                  className="pointer-events-none drop-shadow-md transition-transform group-hover:scale-110"
                  style={{ width: lado, height: lado, objectFit: 'contain' }}
                />
              ) : (
                <div
                  className="border-rust/40 bg-sand-light/70 pointer-events-none rounded-full border-2 border-dashed transition-transform group-hover:scale-110"
                  style={{ width: lado * 0.7, height: lado * 0.7 }}
                />
              )}
              <span className="bg-sand-light/90 text-ink pointer-events-none px-1 text-[0.65rem] font-bold whitespace-nowrap">
                {s.nome}
              </span>
            </button>
          )
        })}
    </div>
  )
}
