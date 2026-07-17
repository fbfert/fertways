import { useEffect, useState } from 'react'
import { useLocation } from 'react-router-dom'
import { api, ApiError, token } from '../../api/client'
import { Marca } from '../Marca'
import { LandingNav } from './LandingNav'
import { PitchSlideshow } from './PitchSlideshow'

const CONSTRUCOES = [
  { img: '/media/colonia-base/reator-helios.png', nome: 'Reator de Energia' },
  { img: '/media/colonia-base/estufa-aurora.png', nome: 'Fazenda' },
  { img: '/media/colonia-base/estacao-nereida.png', nome: 'Captação de Água' },
  { img: '/media/colonia-base/habitat-pioneiro.png', nome: 'Estrutura de Sobrevivência' },
  { img: '/media/colonia-base/nucleo-ares.png', nome: 'Gerador de Atmosfera' },
]

const PILARES = [
  {
    titulo: 'Uma economia que deixa rastros',
    texto:
      'Todo Fert$ e todo recurso tem origem registrada num ledger append-only — nem o próprio administrador do jogo apaga uma linha.',
  },
  {
    titulo: 'Território conquistado, colônia protegida',
    texto:
      'O conflito acontece nas zonas neutras do planeta. O slot principal da sua colônia é inviolável — sempre.',
  },
  {
    titulo: 'Logística física, sem teleporte',
    texto:
      'Cada unidade de recurso viaja de verdade, num veículo de verdade, gastando tempo e energia reais até chegar.',
  },
  {
    titulo: 'Comércio com peso de verdade',
    texto:
      'O Mercado Central garante a entrega; o comércio informal entre colonos corre por conta e risco — e por reputação.',
  },
]

const PASSOS = [
  {
    n: '01',
    titulo: 'Funde sua colônia',
    texto: 'Escolha um slot no planeta e receba o kit inicial: as cinco construções essenciais e um Furgão de Comércio.',
  },
  {
    n: '02',
    titulo: 'Produza e comercie',
    texto: 'Construa, produza pela cadeia de recursos, e leve sua carga ao Mercado Central — Fert$ é a moeda de todos.',
  },
  {
    n: '03',
    titulo: 'Explore e conquiste',
    texto: 'Ocupe zonas neutras, cumpra missões, dispute território e construa uma reputação que os outros colonos veem.',
  },
]

/** A landing page (era `Login.tsx` inteiro; virou uma das vistas do despachante — ver `Login.tsx`). */
export function PaginaInicial({ aoEntrar }: { aoEntrar: () => void }) {
  const location = useLocation()
  const [modo, setModo] = useState<'login' | 'registro'>('login')
  const [f, setF] = useState({ name: '', nickname: '', email: '', password: '' })
  const [erro, setErro] = useState<string | null>(null)
  const [enviando, setEnviando] = useState(false)

  const registrando = modo === 'registro'

  useEffect(() => {
    if (location.hash === '#entrar') {
      document.getElementById('entrar')?.scrollIntoView({ block: 'center' })
    }
  }, [location.hash])

  async function enviar(e: React.FormEvent) {
    e.preventDefault()
    setErro(null)
    setEnviando(true)
    try {
      const s = registrando ? await api.register(f) : await api.login(f)
      token.set(s.token)
      aoEntrar()
    } catch (err) {
      setErro(err instanceof ApiError ? err.message : 'Falha de conexão com o servidor.')
    } finally {
      setEnviando(false)
    }
  }

  function irPara(alvo: 'login' | 'registro') {
    return (e: React.MouseEvent) => {
      e.preventDefault()
      setModo(alvo)
      setErro(null)
      document.getElementById('entrar')?.scrollIntoView({ behavior: 'smooth', block: 'center' })
    }
  }

  const campo = 'w-full border border-rust/25 bg-sand-light px-3 py-2 text-ink outline-none focus:border-rust'

  return (
    <div className="bg-sand min-h-screen">
      <LandingNav />

      {/* ══════════════════════════════════════════════════════ hero + formulário */}
      <section className="relative overflow-hidden">
        <div
          className="from-rust-bright/20 via-sand pointer-events-none absolute inset-0 bg-gradient-to-br to-transparent"
          aria-hidden="true"
        />
        <div className="relative mx-auto grid max-w-6xl gap-12 px-6 py-14 lg:grid-cols-[1.1fr_0.9fr] lg:items-center lg:py-20">
          <div>
            <div className="text-rust eyebrow">Um MMO de estratégia, economia e colonização</div>
            <h1 className="text-ink mt-4 text-4xl leading-[1.05] font-black sm:text-5xl">
              Construir. Conectar. <span className="text-rust">Conquistar.</span>
            </h1>
            <p className="text-ink-soft mt-5 max-w-lg text-lg">
              Funde uma colônia num planeta persistente, onde economia, território e reputação
              contam a sua história — e nenhum número aparece sem vir de algum lugar.
            </p>
            <div className="mt-8 flex flex-wrap gap-3">
              <a
                href="#entrar"
                onClick={irPara('registro')}
                className="bg-rust text-sand-light hover:bg-rust-bright px-6 py-3 text-center font-bold"
              >
                Fundar minha colônia
              </a>
              <a
                href="#entrar"
                onClick={irPara('login')}
                className="border-rust/30 text-ink hover:border-rust px-6 py-3 text-center font-bold"
                style={{ borderWidth: 1 }}
              >
                Já tenho conta
              </a>
            </div>
          </div>

          <div id="entrar" className="painel bg-sand-light w-full p-8 shadow-lg">
            <h2 className="text-ink text-2xl font-black">
              {registrando ? 'Fundar um colono.' : 'Bem-vindo de volta.'}
            </h2>
            <div className="border-rust/30 my-5 border-t" />

            <form onSubmit={enviar} className="space-y-3">
              {registrando && (
                <>
                  <input
                    className={campo}
                    placeholder="Nome"
                    value={f.name}
                    onChange={(e) => setF({ ...f, name: e.target.value })}
                    required
                  />
                  <input
                    className={campo}
                    placeholder="Nickname (único no servidor)"
                    value={f.nickname}
                    onChange={(e) => setF({ ...f, nickname: e.target.value })}
                    required
                  />
                </>
              )}
              <input
                className={campo}
                type="email"
                placeholder="E-mail"
                value={f.email}
                onChange={(e) => setF({ ...f, email: e.target.value })}
                required
              />
              <input
                className={campo}
                type="password"
                placeholder="Senha"
                value={f.password}
                onChange={(e) => setF({ ...f, password: e.target.value })}
                required
              />

              {erro && <p className="text-rust text-sm">{erro}</p>}

              <button
                type="submit"
                disabled={enviando}
                className="bg-rust text-sand-light hover:bg-rust-bright w-full py-3 font-bold disabled:opacity-50"
              >
                {enviando ? 'Enviando…' : registrando ? 'Criar colono' : 'Entrar'}
              </button>
            </form>

            <button
              onClick={() => {
                setModo(registrando ? 'login' : 'registro')
                setErro(null)
              }}
              className="text-ink-soft hover:text-rust mt-4 w-full text-sm"
            >
              {registrando ? 'Já tenho conta' : 'Ainda não tenho conta'}
            </button>
          </div>
        </div>
      </section>

      {/* ══════════════════════════════════════════════════════ o slideshow do pitch */}
      <PitchSlideshow />

      {/* ══════════════════════════════════════════════════════ pilares */}
      <section className="mx-auto max-w-6xl px-6 py-16">
        <div className="text-rust eyebrow">Por que Fertways</div>
        <h2 className="text-ink mt-2 text-3xl font-black">Um mundo com regras de verdade.</h2>
        <div className="mt-10 grid gap-6 sm:grid-cols-2">
          {PILARES.map((p) => (
            <div key={p.titulo} className="painel border-rust/15 bg-sand-light border p-6">
              <h3 className="text-ink text-lg font-black">{p.titulo}</h3>
              <p className="text-ink-soft mt-2 text-sm">{p.texto}</p>
            </div>
          ))}
        </div>
      </section>

      {/* ══════════════════════════════════════════════════════ o que você constrói */}
      <section className="bg-sand-light py-16">
        <div className="mx-auto max-w-6xl px-6">
          <div className="text-rust eyebrow">Sua colônia</div>
          <h2 className="text-ink mt-2 text-3xl font-black">Cinco estruturas essenciais, desde o primeiro dia.</h2>
          <p className="text-ink-soft mt-2 max-w-lg text-sm">
            O kit inicial já traz o miolo da colônia de pé — energia, água, comida, atmosfera e
            abrigo — mais Fert$ e um Furgão de Comércio para começar a produzir.
          </p>
          <div className="mt-10 grid grid-cols-2 gap-6 sm:grid-cols-5">
            {CONSTRUCOES.map((c) => (
              <div key={c.nome} className="text-center">
                <img src={c.img} alt={c.nome} className="mx-auto h-24 w-24 object-contain" loading="lazy" />
                <div className="text-ink-soft mt-2 text-xs font-bold">{c.nome}</div>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* ══════════════════════════════════════════════════════ como começar */}
      <section className="mx-auto max-w-6xl px-6 py-16">
        <div className="text-rust eyebrow">Como começar</div>
        <h2 className="text-ink mt-2 text-3xl font-black">Três passos até a sua primeira colheita.</h2>
        <div className="mt-10 grid gap-8 sm:grid-cols-3">
          {PASSOS.map((p) => (
            <div key={p.n}>
              <div className="hex bg-rust text-sand-light flex h-12 w-12 items-center justify-center font-black">
                {p.n}
              </div>
              <h3 className="text-ink mt-4 text-lg font-black">{p.titulo}</h3>
              <p className="text-ink-soft mt-2 text-sm">{p.texto}</p>
            </div>
          ))}
        </div>
      </section>

      {/* ══════════════════════════════════════════════════════ CTA final */}
      <section className="bg-ink py-16 text-center">
        <h2 className="text-sand-light text-3xl font-black">A próxima colônia começa agora.</h2>
        <a
          href="#entrar"
          onClick={irPara('registro')}
          className="bg-rust text-sand-light hover:bg-rust-bright mt-6 inline-block px-8 py-4 font-bold"
        >
          Criar meu colono
        </a>
      </section>

      <footer className="py-8 text-center">
        <Marca compacto />
      </footer>
    </div>
  )
}
