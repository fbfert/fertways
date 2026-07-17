import { useState } from 'react'
import type { Colonia } from '../api/client'
import { Marca } from './Marca'
import { Popup } from './Popup'

/**
 * A navegação da colônia (rota `/`), versão mobile (`md:hidden`) — o header de `App.tsx` é
 * absolutamente posicionado com botões de largura fixa, pensado para uma tela larga; numa tela de
 * 360-430px, os seis botões e os dois cartões nem chegam a caber numa linha.
 *
 * Duas peças, ambas só decoração + navegação — a lógica (o que cada botão faz) continua em
 * `App.tsx`, passada por props, como o resto do jogo já faz.
 *
 *  - **Faixa superior**: a marca e o saldo (toque abre o Extrato, como no desktop).
 *  - **Barra inferior fixa**: os cinco destinos que cabem sem espremer — Mapa e Capital navegam,
 *    Missões e Chat abrem o painel de sempre (D-78/D-77), e "Mais" abre um resumo com o que sobrou
 *    do header desktop (Marco, Bugs/Melhorias, Perfil, Sair).
 *
 * Recursos, Fila de obras e Zonas (as duas barras laterais do desktop) ainda não têm lugar aqui —
 * ficam para o próximo passo desta reforma; por ora continuam visíveis só a partir de `md:`.
 */
export function MobileNav({
  colonia,
  chatPendente,
  aoAbrirChat,
  aoAbrirMissoes,
  aoAbrirBugs,
  aoAbrirExtrato,
  aoIrMapa,
  aoIrCapital,
  aoIrPerfil,
  aoSair,
}: {
  colonia: Colonia
  chatPendente: number
  aoAbrirChat: () => void
  aoAbrirMissoes: () => void
  aoAbrirBugs: () => void
  aoAbrirExtrato: () => void
  aoIrMapa: () => void
  aoIrCapital: () => void
  aoIrPerfil: () => void
  aoSair: () => void
}) {
  const [maisAberto, setMaisAberto] = useState(false)

  return (
    <>
      <header className="pointer-events-none absolute inset-x-0 top-0 z-20 flex items-center justify-between p-3 md:hidden">
        <div className="painel bg-sand-light pointer-events-auto flex items-center px-3 py-2">
          <Marca compacto />
        </div>

        <button
          onClick={aoAbrirExtrato}
          data-abrir-extrato
          className="painel bg-sand-light pointer-events-auto px-4 py-2 text-right"
        >
          <span className="text-ink text-base font-black tabular-nums">
            {colonia.fert.toLocaleString('pt-BR', { minimumFractionDigits: 2 })}
          </span>{' '}
          <span className="text-rust text-xs">Fert$</span>
        </button>
      </header>

      <nav
        className="bg-sand-light border-rust/20 fixed inset-x-0 bottom-0 z-20 flex border-t pb-[max(0.5rem,env(safe-area-inset-bottom))] md:hidden"
        data-nav-mobile
      >
        <BotaoNav rotulo="Mapa" onClick={aoIrMapa} marcador="mapa">
          <IconeMapa />
        </BotaoNav>
        <BotaoNav rotulo="Capital" onClick={aoIrCapital} marcador="capital">
          <IconeCapital />
        </BotaoNav>
        <BotaoNav rotulo="Missões" onClick={aoAbrirMissoes} marcador="missoes">
          <IconeMissoes />
        </BotaoNav>
        <BotaoNav rotulo="Chat" onClick={aoAbrirChat} badge={chatPendente} marcador="chat">
          <IconeChat />
        </BotaoNav>
        <BotaoNav rotulo="Mais" onClick={() => setMaisAberto(true)} marcador="mais">
          <IconeMais />
        </BotaoNav>
      </nav>

      {maisAberto && (
        <Popup titulo="Mais" eyebrow={colonia.name} aoFechar={() => setMaisAberto(false)}>
          <div className="border-rust/15 border-b pb-3">
            <div className="text-rust eyebrow">Marco {colonia.marco.numero}</div>
            <div className="text-ink text-sm font-bold">{colonia.marco.titulo}</div>
            <div className="text-ink-soft text-xs tabular-nums">
              {colonia.marco.xp_do_proximo !== null
                ? `${colonia.marco.xp.toLocaleString('pt-BR')} / ${colonia.marco.xp_do_proximo.toLocaleString('pt-BR')} XP`
                : `${colonia.marco.xp.toLocaleString('pt-BR')} XP · máximo`}
            </div>
          </div>

          <button
            onClick={() => {
              setMaisAberto(false)
              aoAbrirBugs()
            }}
            data-abrir-bugs-melhorias-mobile
            className="text-ink hover:text-rust border-rust/10 block w-full border-b py-3 text-left text-sm font-bold"
          >
            Bugs/Melhorias
          </button>

          <button
            onClick={() => {
              setMaisAberto(false)
              aoIrPerfil()
            }}
            data-abrir-perfil-mobile
            className="text-ink hover:text-rust border-rust/10 block w-full border-b py-3 text-left text-sm font-bold"
          >
            O seu perfil
          </button>

          <SairComConfirmacao aoSair={aoSair} />
        </Popup>
      )}
    </>
  )
}

function SairComConfirmacao({ aoSair }: { aoSair: () => void }) {
  const [confirmando, setConfirmando] = useState(false)

  if (!confirmando) {
    return (
      <button
        onClick={() => setConfirmando(true)}
        data-sair-mobile
        className="text-ink hover:text-rust block w-full py-3 text-left text-sm font-bold"
      >
        Sair
      </button>
    )
  }

  return (
    <div className="flex items-center justify-between py-3">
      <span className="text-ink text-sm font-bold">Sair da conta?</span>
      <div className="flex gap-2">
        <button onClick={() => setConfirmando(false)} className="text-ink-soft hover:text-ink px-2 py-1 text-xs">
          Não
        </button>
        <button
          onClick={aoSair}
          data-confirmar-sair-mobile
          className="bg-rust text-sand-light px-3 py-1 text-xs font-bold"
        >
          Sim
        </button>
      </div>
    </div>
  )
}

function BotaoNav({
  rotulo,
  onClick,
  badge,
  marcador,
  children,
}: {
  rotulo: string
  onClick: () => void
  badge?: number
  /** Vira `data-nav="{marcador}"`, o gancho que o e2e usa para achar o botão. */
  marcador: string
  children: React.ReactNode
}) {
  return (
    <button
      onClick={onClick}
      aria-label={rotulo}
      data-nav={marcador}
      className="text-ink-soft hover:text-rust active:text-rust flex flex-1 flex-col items-center justify-center gap-0.5 py-2"
    >
      <span className="relative">
        {children}
        {!!badge && badge > 0 && (
          <span className="bg-rust text-sand-light absolute -top-1 -right-1.5 flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-[9px] font-black">
            {badge > 9 ? '9+' : badge}
          </span>
        )}
      </span>
      <span className="text-[9px] font-bold tracking-wide uppercase">{rotulo}</span>
    </button>
  )
}

function IconeMapa() {
  return (
    <svg viewBox="0 0 24 24" className="h-6 w-6" fill="none" stroke="currentColor" strokeWidth="2">
      <path d="M12 21s7-7.2 7-12a7 7 0 1 0-14 0c0 4.8 7 12 7 12z" strokeLinejoin="round" />
      <circle cx="12" cy="9" r="2.5" />
    </svg>
  )
}

function IconeCapital() {
  return (
    <svg viewBox="0 0 24 24" className="h-6 w-6" fill="none" stroke="currentColor" strokeWidth="2">
      <path d="M4 21h16" strokeLinecap="round" />
      <path d="M5 21V10l7-6 7 6v11" strokeLinejoin="round" />
      <path d="M10 21v-6h4v6" strokeLinejoin="round" />
    </svg>
  )
}

function IconeMissoes() {
  return (
    <svg viewBox="0 0 24 24" className="h-6 w-6" fill="none" stroke="currentColor" strokeWidth="2">
      <rect x="4.5" y="3" width="15" height="18" rx="1.5" />
      <path d="M8 8h8M8 12h8M8 16h5" strokeLinecap="round" />
    </svg>
  )
}

function IconeChat() {
  return (
    <svg viewBox="0 0 24 24" className="h-6 w-6" fill="none" stroke="currentColor" strokeWidth="2">
      <path
        d="M21 11.5a8.4 8.4 0 0 1-8.5 8.4 8.6 8.6 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8A8.4 8.4 0 0 1 12.5 3a8.5 8.5 0 0 1 8.5 8.5z"
        strokeLinejoin="round"
        strokeLinecap="round"
      />
    </svg>
  )
}

function IconeMais() {
  return (
    <svg viewBox="0 0 24 24" className="h-6 w-6" fill="currentColor" stroke="none">
      <circle cx="5" cy="12" r="1.75" />
      <circle cx="12" cy="12" r="1.75" />
      <circle cx="19" cy="12" r="1.75" />
    </svg>
  )
}
