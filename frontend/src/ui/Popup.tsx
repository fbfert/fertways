import { useEffect, useRef } from 'react'

/**
 * O popup que abre por cima da colônia (docs/decisoes.md D-69).
 *
 * **E é POPUP, não tela — de propósito.** Desde o D-67 tudo o mais tem URL própria e ocupa a tela
 * inteira: o Mapa, a Capital, a Zona, o Quartel. Este não. A razão é o que o card mostra: o detalhe
 * de uma construção **só faz sentido com a colônia atrás dele**. Uma tela cheia esconderia o que dá
 * contexto ao card, e abrir um detalhe de prédio não é navegação — é olhar mais de perto.
 *
 * Fecha de três maneiras, e as três são hábito: **clicar fora**, **Esc**, e o **×**.
 */
export function Popup({
  titulo,
  eyebrow,
  aoFechar,
  children,
}: {
  titulo: string
  eyebrow?: string
  aoFechar: () => void
  children: React.ReactNode
}) {
  const caixa = useRef<HTMLDivElement>(null)

  /*
   * Esc fecha. `keydown` na janela e não no elemento: o foco pode estar em qualquer lugar do popup —
   * num input, num botão — e um listener preso ao container perderia a tecla assim que o colono
   * clicasse num campo.
   */
  useEffect(() => {
    const aoTeclar = (e: KeyboardEvent) => {
      if (e.key === 'Escape') aoFechar()
    }

    window.addEventListener('keydown', aoTeclar)

    return () => window.removeEventListener('keydown', aoTeclar)
  }, [aoFechar])

  return (
    <div
      className="bg-ink/50 fixed inset-0 z-30 flex items-start justify-center overflow-y-auto p-6 sm:items-center"
      data-popup
      /*
       * Fechar ao clicar FORA — e o `e.target === e.currentTarget` é o que impede o desastre óbvio:
       * sem ele, um clique em qualquer lugar de DENTRO do popup borbulharia até aqui e o fecharia.
       * O colono selecionaria um texto, soltaria o botão, e a janela sumiria.
       */
      onMouseDown={(e) => {
        if (e.target === e.currentTarget) aoFechar()
      }}
    >
      <div
        ref={caixa}
        className="painel bg-sand-light w-full max-w-md p-5 shadow-2xl"
        role="dialog"
        aria-modal="true"
        aria-label={titulo}
      >
        <div className="mb-3 flex items-start justify-between gap-3">
          <div>
            {eyebrow && <div className="text-rust eyebrow">{eyebrow}</div>}
            <h2 className="text-ink text-lg leading-tight font-black">{titulo}</h2>
          </div>
          <button
            onClick={aoFechar}
            data-fechar-popup
            aria-label="Fechar"
            className="text-ink-soft hover:text-rust text-2xl leading-none"
          >
            ×
          </button>
        </div>

        {children}
      </div>
    </div>
  )
}
