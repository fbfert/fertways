import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { LandingNav } from './LandingNav'
import { Marca } from '../Marca'

/** A moldura das páginas satélite da landing (Construções, Veículos, Guerra, Luas, NPCs, Estatísticas). */
export function LandingPageChrome({
  eyebrow,
  titulo,
  intro,
  children,
}: {
  eyebrow: string
  titulo: string
  intro: ReactNode
  children: ReactNode
}) {
  return (
    <div className="bg-sand min-h-screen">
      <LandingNav />

      <section className="mx-auto max-w-6xl px-6 py-14">
        <div className="text-rust eyebrow">{eyebrow}</div>
        <h1 className="text-ink mt-2 max-w-3xl text-4xl font-black">{titulo}</h1>
        <div className="text-ink-soft mt-4 max-w-2xl space-y-3 text-lg">{intro}</div>
      </section>

      {children}

      <section className="bg-ink py-14 text-center">
        <h2 className="text-sand-light text-2xl font-black">A próxima colônia começa agora.</h2>
        <Link
          to="/#entrar"
          className="bg-rust text-sand-light hover:bg-rust-bright mt-6 inline-block px-8 py-4 font-bold"
        >
          Criar meu colono
        </Link>
      </section>

      <footer className="py-8 text-center">
        <Marca compacto />
      </footer>
    </div>
  )
}
