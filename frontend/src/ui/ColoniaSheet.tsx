import { useState } from 'react'
import type { Colonia, Fila } from '../api/client'
import { FilaDeObras, Recursos } from './Hud'
import { MinhasZonas } from './MinhasZonas'
import { painelFlutuante } from './painelFlutuante'

type Aba = 'recursos' | 'obras'

/**
 * No desktop, `Recursos` e `FilaDeObras`+`MinhasZonas` são duas barras laterais sempre visíveis
 * (`App.tsx`, `top-24 left-5` e `top-24 right-5`) — não cabem lado a lado numa tela de telefone, e
 * desde o PR anterior desta reforma elas ficam escondidas no mobile (`hidden md:block`).
 *
 * Este é o lugar para onde foram: um sheet só, com abas, aberto pelo ícone "Colônia" da barra
 * inferior (`MobileNav.tsx`). Os três componentes internos não mudam nada — só ganham um lar novo.
 */
export function ColoniaSheet({
  colonia,
  fila,
  aoFechar,
}: {
  colonia: Colonia
  fila: Fila | null
  aoFechar: () => void
}) {
  const [aba, setAba] = useState<Aba>('recursos')

  return (
    <div className={painelFlutuante.grande} data-tela="colonia-sheet">
      <div className="border-rust/20 flex items-center justify-between border-b px-3 py-2">
        <span className="text-rust eyebrow">{colonia.name}</span>
        <button
          onClick={aoFechar}
          data-fechar-colonia-sheet
          className="text-ink-soft hover:text-rust text-xl leading-none"
        >
          ×
        </button>
      </div>

      <div className="border-rust/20 flex border-b text-xs">
        {(
          [
            ['recursos', 'Recursos'],
            ['obras', 'Obras e zonas'],
          ] as [Aba, string][]
        ).map(([id, rotulo]) => (
          <button
            key={id}
            onClick={() => setAba(id)}
            data-aba-colonia={id}
            className={`flex-1 py-2 font-bold ${aba === id ? 'bg-rust text-sand-light' : 'text-ink-soft hover:text-rust'}`}
          >
            {rotulo}
          </button>
        ))}
      </div>

      <div className="flex-1 overflow-y-auto p-3">
        {aba === 'recursos' ? (
          <Recursos colonia={colonia} />
        ) : (
          <div className="space-y-4">
            {fila && <FilaDeObras fila={fila} />}
            <MinhasZonas />
          </div>
        )}
      </div>
    </div>
  )
}
