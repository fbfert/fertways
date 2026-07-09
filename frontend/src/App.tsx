import { useCallback, useEffect, useState } from 'react'
import { api, ApiError, token } from './api/client'
import type { Colonia, Fila, Spec } from './api/client'
import { ColonyCanvas } from './game/ColonyCanvas'
import { Login } from './ui/Login'
import { Marca } from './ui/Marca'
import { Mercado } from './ui/Mercado'
import { Detalhe, FilaDeObras, Recursos } from './ui/Hud'

/** Sem websocket nesta fase: polling simples, como o plano define. */
const INTERVALO_MS = 5000

export default function App() {
  const [autenticado, setAutenticado] = useState(!!token.get())
  const [colonia, setColonia] = useState<Colonia | null>(null)
  const [specs, setSpecs] = useState<Spec[]>([])
  const [fila, setFila] = useState<Fila | null>(null)
  const [selecionada, setSelecionada] = useState<Spec | null>(null)
  const [erro, setErro] = useState<string | null>(null)
  const [semColonia, setSemColonia] = useState(false)
  const [nome, setNome] = useState('')
  const [mercadoAberto, setMercadoAberto] = useState(false)

  const carregar = useCallback(async () => {
    try {
      const [c, s, f] = await Promise.all([api.colonia(), api.construcoes(), api.fila()])
      setColonia(c)
      setSpecs(s)
      setFila(f)
      setSemColonia(false)
      // Mantém o painel de detalhe em sincronia com o nível recém-concluído.
      setSelecionada((atual) => (atual ? (s.find((x) => x.id === atual.id) ?? null) : null))
    } catch (e) {
      if (e instanceof ApiError && (e.status === 404 || e.code === 'sem_colonia')) {
        setSemColonia(true)
      } else if (e instanceof ApiError && e.status === 401) {
        token.clear()
        setAutenticado(false)
      }
    }
  }, [])

  useEffect(() => {
    if (!autenticado) return
    void carregar()
    const t = setInterval(() => void carregar(), INTERVALO_MS)
    return () => clearInterval(t)
  }, [autenticado, carregar])

  // Faz o contador da fila andar de segundo em segundo, sem bater na API.
  const [, tique] = useState(0)
  useEffect(() => {
    const t = setInterval(() => tique((n) => n + 1), 1000)
    return () => clearInterval(t)
  }, [])

  async function construir(spec: Spec) {
    setErro(null)
    try {
      await api.enfileirar(spec.id)
      await carregar()
    } catch (e) {
      setErro(e instanceof ApiError ? e.message : 'Falha ao enfileirar.')
    }
  }

  if (!autenticado) return <Login aoEntrar={() => setAutenticado(true)} />

  if (semColonia) {
    return (
      <div className="flex min-h-screen items-center justify-center p-6">
        <div className="painel bg-sand-light w-full max-w-md p-8">
          <Marca />
          <h1 className="text-ink mt-8 text-2xl font-black">Funde sua colônia.</h1>
          <p className="text-ink-soft mt-2 text-sm">
            Você chega com 50 Fert$ e um Furgão de Comércio.
          </p>
          <form
            className="mt-5 space-y-3"
            onSubmit={async (e) => {
              e.preventDefault()
              setErro(null)
              try {
                await api.fundarColonia(nome)
                await carregar()
              } catch (err) {
                setErro(err instanceof ApiError ? err.message : 'Falha ao fundar.')
              }
            }}
          >
            <input
              className="border-rust/25 bg-sand w-full border px-3 py-2 outline-none focus:border-rust"
              placeholder="Nome da colônia"
              value={nome}
              onChange={(e) => setNome(e.target.value)}
              required
              minLength={3}
            />
            {erro && <p className="text-rust text-sm">{erro}</p>}
            <button className="bg-rust text-sand-light hover:bg-rust-bright w-full py-3 font-bold">
              Fundar
            </button>
          </form>
        </div>
      </div>
    )
  }

  return (
    <div className="relative h-screen w-screen overflow-hidden">
      <div className="absolute inset-0">
        <ColonyCanvas specs={specs} onSelecionar={setSelecionada} />
      </div>

      <header className="pointer-events-none absolute inset-x-0 top-0 flex items-start justify-between p-5">
        <div className="painel bg-sand-light pointer-events-auto px-4 py-3">
          <Marca compacto />
        </div>

        {colonia && (
          <div className="pointer-events-auto flex items-start gap-3">
            <button
              onClick={() => setMercadoAberto(true)}
              className="painel bg-rust text-sand-light hover:bg-rust-bright eyebrow px-5 py-4"
            >
              Mercado
            </button>

            <div className="painel bg-sand-light px-5 py-3 text-right">
              <div className="text-rust eyebrow">{colonia.name}</div>
              <div className="text-ink text-xl font-black tabular-nums">
                {colonia.fert.toLocaleString('pt-BR', { minimumFractionDigits: 2 })}{' '}
                <span className="text-rust text-sm">Fert$</span>
              </div>
            </div>
          </div>
        )}
      </header>

      {colonia && mercadoAberto && (
        <Mercado
          colonia={colonia}
          aoFechar={() => {
            setMercadoAberto(false)
            // O depósito tira recurso do estoque na hora: o HUD tem de refletir isso já.
            void carregar()
          }}
        />
      )}

      {colonia && (
        <div className="absolute top-24 left-5">
          <Recursos colonia={colonia} />
        </div>
      )}

      <div className="absolute top-24 right-5 space-y-4">
        <Detalhe spec={selecionada} aoConstruir={construir} erro={erro} />
        {fila && <FilaDeObras fila={fila} />}
      </div>

      <button
        onClick={() => {
          token.clear()
          setAutenticado(false)
        }}
        className="text-ink-soft hover:text-rust absolute bottom-4 left-5 text-xs"
      >
        Sair
      </button>
    </div>
  )
}
