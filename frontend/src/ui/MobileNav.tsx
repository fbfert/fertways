import { useState } from 'react'
import { useLocation } from 'react-router-dom'
import type { Colonia, Fila } from '../api/client'
import { Marca } from './Marca'
import { ObrasEZonasSheet } from './ObrasEZonasSheet'
import { Popup } from './Popup'

/**
 * A navegação, versão mobile (`md:hidden`) — reforma de navegação global (pedido do usuário): o
 * header desktop (`Header.tsx`) e esta barra deixaram de existir só na rota `/` e agora envolvem
 * `<Routes>` inteiro em `App.tsx`, visíveis em qualquer tela.
 *
 *  - **Faixa superior**: a marca e o saldo (toque abre o Extrato, como no desktop).
 *  - **Barra inferior fixa**: Colônia (a rota `/` — primeiro item, como no header desktop),
 *    Mapa e Capital (navegam, com destaque na rota ativa), Chat (o painel de sempre, D-77), e
 *    "Mais" (Marco, Missões, Obras e zonas, Bugs/Melhorias, Perfil, Sair).
 *
 * "Colônia" costumava abrir um sheet de Recursos/Fila/Zonas — agora só navega, igual ao botão novo
 * do header desktop. Recursos saiu de vez (agora mora no Depósito Local, uma construção); Obras e
 * zonas foi para dentro de "Mais".
 */
export function MobileNav({
  colonia,
  fila,
  chatPendente,
  aoAbrirChat,
  aoAbrirMissoes,
  aoAbrirBugs,
  aoAbrirExtrato,
  aoIrColonia,
  aoIrMapa,
  aoIrCapital,
  aoIrPerfil,
  aoSair,
}: {
  colonia: Colonia
  fila: Fila | null
  chatPendente: number
  aoAbrirChat: () => void
  aoAbrirMissoes: () => void
  aoAbrirBugs: () => void
  aoAbrirExtrato: () => void
  aoIrColonia: () => void
  aoIrMapa: () => void
  aoIrCapital: () => void
  aoIrPerfil: () => void
  aoSair: () => void
}) {
  const [maisAberto, setMaisAberto] = useState(false)
  const [obrasEZonasAbertas, setObrasEZonasAbertas] = useState(false)
  const { pathname } = useLocation()

  // Ministério e Mercado só se alcançam PELA Capital (D-59, item 6) — contam como "Capital".
  const ativo: 'colonia' | 'mapa' | 'capital' | null =
    pathname === '/'
      ? 'colonia'
      : pathname === '/mapa'
        ? 'mapa'
        : pathname === '/capital' || pathname.startsWith('/capital/') || pathname === '/ministerio' || pathname.startsWith('/mercado/')
          ? 'capital'
          : null

  return (
    <>
      <header className="pointer-events-none absolute inset-x-0 top-0 z-[25] flex items-center justify-between p-3 md:hidden">
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
        className="bg-sand-light border-rust/20 fixed inset-x-0 bottom-0 z-[25] flex border-t pb-[max(0.5rem,env(safe-area-inset-bottom))] md:hidden"
        data-nav-mobile
      >
        <BotaoNav rotulo="Colônia" onClick={aoIrColonia} marcador="colonia" ativo={ativo === 'colonia'}>
          <IconeColonia />
        </BotaoNav>
        <BotaoNav rotulo="Mapa" onClick={aoIrMapa} marcador="mapa" ativo={ativo === 'mapa'}>
          <IconeMapa />
        </BotaoNav>
        <BotaoNav rotulo="Capital" onClick={aoIrCapital} marcador="capital" ativo={ativo === 'capital'}>
          <IconeCapital />
        </BotaoNav>
        <BotaoNav rotulo="Chat" onClick={aoAbrirChat} badge={chatPendente} marcador="chat">
          <IconeChat />
        </BotaoNav>
        <BotaoNav rotulo="Mais" onClick={() => setMaisAberto(true)} marcador="mais">
          <IconeMais />
        </BotaoNav>
      </nav>

      {obrasEZonasAbertas && (
        <ObrasEZonasSheet fila={fila} aoFechar={() => setObrasEZonasAbertas(false)} />
      )}

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
              aoAbrirMissoes()
            }}
            data-abrir-missoes-mobile
            className="text-ink hover:text-rust border-rust/10 block w-full border-b py-3 text-left text-sm font-bold"
          >
            Missões
          </button>

          <button
            onClick={() => {
              setMaisAberto(false)
              setObrasEZonasAbertas(true)
            }}
            data-abrir-obras-e-zonas-mobile
            className="text-ink hover:text-rust border-rust/10 block w-full border-b py-3 text-left text-sm font-bold"
          >
            Obras e zonas
          </button>

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
  ativo = false,
  children,
}: {
  rotulo: string
  onClick: () => void
  badge?: number
  /** Vira `data-nav="{marcador}"`, o gancho que o e2e usa para achar o botão. */
  marcador: string
  /** O destaque segue a rota (pedido do usuário) — Chat e Mais não são rota, nunca destacam. */
  ativo?: boolean
  children: React.ReactNode
}) {
  return (
    <button
      onClick={onClick}
      // "Ir para {rotulo}", não `rotulo` puro — a barra é global desde a reforma de navegação
      // (pedido do usuário), e um `aria-label="Capital"` aqui colidia com o do losango da Capital
      // em `Mapa.tsx`: o e2e clicava no ícone escondido (`md:hidden`, caixa zero) em vez do
      // losango, porque `[aria-label="Capital"]` pegava o primeiro do DOM, não o visível.
      aria-label={`Ir para ${rotulo}`}
      data-nav={marcador}
      className={`flex flex-1 flex-col items-center justify-center gap-0.5 py-2 ${
        ativo ? 'text-rust' : 'text-ink-soft hover:text-rust active:text-rust'
      }`}
    >
      <span className="relative">
        {children}
        {!!badge && badge > 0 && (
          <span className="bg-rust text-sand-light absolute -top-1 -right-1.5 flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-micro font-black">
            {badge > 9 ? '9+' : badge}
          </span>
        )}
      </span>
      <span className="text-micro font-bold tracking-wide uppercase">{rotulo}</span>
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

/** O hexágono é o motivo repetido do deck (docs/design-tokens.md — a mesma forma da `.hex`). */
function IconeColonia() {
  return (
    <svg viewBox="0 0 24 24" className="h-6 w-6" fill="none" stroke="currentColor" strokeWidth="2">
      <path d="M12 2.5 21 7.8v8.4L12 21.5l-9-5.3V7.8L12 2.5z" strokeLinejoin="round" />
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
