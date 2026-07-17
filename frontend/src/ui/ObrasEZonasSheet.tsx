import type { Fila } from '../api/client'
import { FilaDeObras } from './Hud'
import { MinhasZonas } from './MinhasZonas'
import { painelFlutuante } from './painelFlutuante'

/**
 * A fila de obras e as zonas ocupadas, no mobile — reforma de navegação global (pedido do
 * usuário). No desktop essas duas seguem na barra lateral direita de sempre (`top-24 right-5`,
 * só em `/`); no mobile, agora que o ícone "Colônia" da barra inferior navega para a colônia (em
 * vez de abrir um sheet), este popup — aberto a partir de "Mais" — é o lugar delas.
 *
 * (Antes vivia dentro de `ColoniaSheet.tsx`, junto de "Recursos" — que saiu daqui: ver os recursos
 * agora exige abrir o Depósito Local, igual no desktop e no mobile.)
 */
export function ObrasEZonasSheet({ fila, aoFechar }: { fila: Fila | null; aoFechar: () => void }) {
  return (
    <div className={painelFlutuante.grande} data-tela="obras-e-zonas">
      <div className="border-rust/20 flex items-center justify-between border-b px-3 py-2">
        <span className="text-rust eyebrow">Obras e zonas</span>
        <button
          onClick={aoFechar}
          data-fechar-obras-e-zonas
          className="text-ink-soft hover:text-rust text-xl leading-none"
        >
          ×
        </button>
      </div>

      <div className="flex-1 space-y-4 overflow-y-auto p-3">
        {fila && <FilaDeObras fila={fila} />}
        <MinhasZonas />
      </div>
    </div>
  )
}
