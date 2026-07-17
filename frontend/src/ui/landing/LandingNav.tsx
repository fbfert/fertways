import { Link } from 'react-router-dom'
import { Marca } from '../Marca'

/**
 * O cabeçalho compartilhado da landing page e das páginas satélite (pedido do usuário,
 * 2026-07-17). O GDD é um documento à parte — HTML autocontido, com o próprio cabeçalho — por
 * isso abre em nova aba; as demais são vistas da própria landing, navegação client-side.
 */
const LINKS = [
  { href: '/gdd.html', rotulo: 'GDD', externo: true },
  { href: '/construcoes', rotulo: 'Construções' },
  { href: '/veiculos', rotulo: 'Veículos' },
  { href: '/guerra', rotulo: 'Guerra' },
  { href: '/luas', rotulo: '8 Luas' },
  { href: '/npcs', rotulo: 'NPCs' },
  { href: '/estatisticas', rotulo: 'Estatísticas' },
]

export function LandingNav() {
  return (
    <header className="border-rust/15 bg-sand/95 sticky top-0 z-10 border-b backdrop-blur">
      <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-6 py-4">
        <Link to="/">
          <Marca compacto />
        </Link>
        <nav className="flex flex-wrap items-center gap-x-5 gap-y-1">
          {LINKS.map((l) =>
            l.externo ? (
              <a
                key={l.href}
                href={l.href}
                target="_blank"
                rel="noopener"
                className="text-ink-soft hover:text-rust eyebrow"
              >
                {l.rotulo}
              </a>
            ) : (
              <Link key={l.href} to={l.href} className="text-ink-soft hover:text-rust eyebrow">
                {l.rotulo}
              </Link>
            ),
          )}
          <Link to="/#entrar" className="text-ink-soft hover:text-rust eyebrow">
            Entrar
          </Link>
        </nav>
      </div>
    </header>
  )
}
