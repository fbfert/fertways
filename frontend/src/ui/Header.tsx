import { useLocation } from 'react-router-dom'
import type { Colonia } from '../api/client'
import { Marca } from './Marca'

/**
 * O header desktop (`md:flex`) — reforma de navegação global (pedido do usuário): antes só
 * existia na rota `/`, agora envolve `<Routes>` inteiro em `App.tsx` e fica visível em qualquer
 * tela. O par mobile é `MobileNav.tsx`.
 *
 * O botão "Colônia" é novo: com o header em toda parte, cada tela deixou de ter o seu próprio `×`
 * (Mapa, Capital, Frota, Perfil, Quartel, Zona, Ministério, Mercado) — voltar para a colônia agora
 * é sempre este botão, nunca um botão da própria tela.
 */
export function Header({
  colonia,
  chatPendente,
  aoAbrirChat,
  aoAbrirMissoes,
  aoAbrirBugs,
  aoAbrirExtrato,
  aoIrColonia,
  aoIrMapa,
  aoIrCapital,
  aoIrPerfil,
  confirmandoSaida,
  setConfirmandoSaida,
  aoSair,
}: {
  colonia: Colonia | null
  chatPendente: number
  aoAbrirChat: () => void
  aoAbrirMissoes: () => void
  aoAbrirBugs: () => void
  aoAbrirExtrato: () => void
  aoIrColonia: () => void
  aoIrMapa: () => void
  aoIrCapital: () => void
  aoIrPerfil: () => void
  confirmandoSaida: boolean
  setConfirmandoSaida: (v: boolean | ((atual: boolean) => boolean)) => void
  aoSair: () => void
}) {
  const { pathname } = useLocation()

  // O destaque segue a rota (pedido do usuário) — antes era sempre o Mapa. Ministério e Mercado só
  // se alcançam PELA Capital (D-59, item 6), então contam como "Capital" para o destaque.
  const ativo: 'colonia' | 'mapa' | 'capital' | null =
    pathname === '/'
      ? 'colonia'
      : pathname === '/mapa'
        ? 'mapa'
        : pathname === '/capital' || pathname === '/ministerio' || pathname.startsWith('/mercado/')
          ? 'capital'
          : null

  const classeItem = (item: typeof ativo) =>
    `painel eyebrow px-5 ${
      ativo === item
        ? 'bg-rust text-sand-light'
        : 'bg-sand-light text-rust hover:text-rust-bright'
    }`

  return (
    <header className="pointer-events-none absolute inset-x-0 top-0 z-[25] hidden items-start justify-between p-5 md:flex">
      <div className="pointer-events-auto flex items-stretch gap-3">
        <div className="painel bg-sand-light flex items-center px-4 py-3">
          <Marca compacto />
        </div>

        <button onClick={aoIrColonia} data-nav-desktop="colonia" className={classeItem('colonia')}>
          Colônia
        </button>

        <button onClick={aoIrMapa} data-nav-desktop="mapa" className={classeItem('mapa')}>
          Mapa
        </button>

        <button onClick={aoIrCapital} data-nav-desktop="capital" className={classeItem('capital')}>
          Capital
        </button>

        {colonia && (
          <button
            onClick={aoAbrirMissoes}
            data-abrir-missoes
            className="painel bg-sand-light text-rust hover:text-rust-bright eyebrow px-5"
          >
            Missões
          </button>
        )}

        {colonia && (
          <button
            onClick={aoAbrirChat}
            data-abrir-chat
            data-chat-pendente={chatPendente}
            className="painel bg-sand-light text-rust hover:text-rust-bright eyebrow relative px-5"
          >
            Chat
            {chatPendente > 0 && (
              <span className="bg-rust text-sand-light absolute -top-1.5 -right-1.5 flex h-5 min-w-5 items-center justify-center rounded-full px-1 text-[10px] font-black">
                {chatPendente > 9 ? '9+' : chatPendente}
              </span>
            )}
          </button>
        )}

        {colonia && (
          <button
            onClick={aoAbrirBugs}
            data-abrir-bugs-melhorias
            className="painel bg-sand-light text-rust hover:text-rust-bright eyebrow px-5"
          >
            Bugs/Melhorias
          </button>
        )}
      </div>

      {colonia && (
        <div className="pointer-events-auto flex items-stretch gap-3">
          <div className="painel bg-sand-light px-5 py-3 text-right" data-marco={colonia.marco.numero}>
            <div className="text-rust eyebrow">Marco {colonia.marco.numero}</div>
            <div className="text-ink text-sm font-bold">{colonia.marco.titulo}</div>
            <div className="text-ink-soft text-xs tabular-nums">
              {colonia.marco.xp_do_proximo !== null
                ? `${colonia.marco.xp.toLocaleString('pt-BR')} / ${colonia.marco.xp_do_proximo.toLocaleString('pt-BR')} XP`
                : `${colonia.marco.xp.toLocaleString('pt-BR')} XP · máximo`}
            </div>
          </div>

          <div className="painel bg-sand-light px-5 py-3 text-right">
            <div className="text-rust eyebrow">{colonia.name}</div>
            <button
              onClick={aoAbrirExtrato}
              data-abrir-extrato
              title="Ver extrato"
              className="text-ink hover:text-rust text-xl font-black tabular-nums"
            >
              {colonia.fert.toLocaleString('pt-BR', { minimumFractionDigits: 2 })}{' '}
              <span className="text-rust text-sm">Fert$</span>
            </button>
          </div>

          <button
            onClick={aoIrPerfil}
            aria-label="O seu perfil"
            title="O seu perfil"
            data-abrir-perfil
            className="painel bg-sand-light text-rust hover:bg-sand flex w-14 items-center justify-center"
          >
            <svg viewBox="0 0 24 24" className="h-6 w-6" fill="none" stroke="currentColor" strokeWidth="2">
              <circle cx="12" cy="8" r="4" />
              <path d="M4 21c0-4 3.6-6 8-6s8 2 8 6" strokeLinecap="round" />
            </svg>
          </button>

          <div className="relative flex">
            <button
              onClick={() => setConfirmandoSaida((v) => !v)}
              aria-label="Sair"
              title="Sair"
              data-sair
              className="painel bg-sand-light text-rust hover:bg-sand flex w-14 items-center justify-center"
            >
              <svg viewBox="0 0 24 24" className="h-6 w-6" fill="none" stroke="currentColor" strokeWidth="2">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" strokeLinecap="round" strokeLinejoin="round" />
                <polyline points="16 17 21 12 16 7" strokeLinecap="round" strokeLinejoin="round" />
                <line x1="21" y1="12" x2="9" y2="12" strokeLinecap="round" />
              </svg>
            </button>

            {confirmandoSaida && (
              <div className="painel bg-sand-light border-rust/30 absolute right-0 top-full z-10 mt-2 w-48 border p-3 text-right">
                <p className="text-ink text-xs font-bold">Sair da conta?</p>
                <div className="mt-2 flex justify-end gap-2">
                  <button
                    onClick={() => setConfirmandoSaida(false)}
                    className="text-ink-soft hover:text-ink px-2 py-1 text-xs"
                  >
                    Não
                  </button>
                  <button
                    onClick={aoSair}
                    data-confirmar-sair
                    className="bg-rust text-sand-light px-3 py-1 text-xs font-bold"
                  >
                    Sim
                  </button>
                </div>
              </div>
            )}
          </div>
        </div>
      )}
    </header>
  )
}
