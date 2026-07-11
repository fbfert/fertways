import { useCallback, useEffect, useState } from 'react'
import { api, ApiError, token } from './api/client'
import type { Colonia, Fila, Spec } from './api/client'
import { ColonyCanvas } from './game/ColonyCanvas'
import { Capital } from './ui/Capital'
import { Frota } from './ui/Frota'
import { Fundacao } from './ui/Fundacao'
import { Login } from './ui/Login'
import { Mapa } from './ui/Mapa'
import { Marca } from './ui/Marca'
import { Mercado } from './ui/Mercado'
import { Ministerio } from './ui/Ministerio'
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
  const [mercadoAberto, setMercadoAberto] = useState(false)
  const [ministerioAberto, setMinisterioAberto] = useState(false)
  const [mapaAberto, setMapaAberto] = useState(false)
  const [frotaAberta, setFrotaAberta] = useState(false)
  const [capitalAberta, setCapitalAberta] = useState(false)

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

  /**
   * Sair de verdade: revoga o token no servidor antes de apagá-lo daqui.
   *
   * Se a chamada falhar — rede caída, ou token já inválido porque o servidor o revogou antes —
   * ainda assim saímos localmente. O pior desfecho de insistir seria prender o colono numa
   * sessão que ele pediu para encerrar.
   */
  const sair = useCallback(async () => {
    // Derruba o polling **antes** de revogar. Na ordem inversa, o `setInterval` de 5 s dispara
    // `/colony`, `/buildings` e `/queue` com um token que o servidor acabou de invalidar, e o
    // colono vê três 401 no console ao sair.
    setAutenticado(false)
    setMercadoAberto(false)
    setMinisterioAberto(false)
    setMapaAberto(false)
    setFrotaAberta(false)
    setCapitalAberta(false)
    setColonia(null)

    try {
      await api.logout()
    } catch {
      // Rede caída, ou token já revogado pelo servidor. Sair localmente mesmo assim: o pior
      // desfecho de insistir seria prender o colono numa sessão que ele pediu para encerrar.
    }

    token.clear()
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
    // O colono escolhe a célula (D-51): o seletor visual substitui o antigo formulário só-de-nome.
    return (
      <div className="relative min-h-screen">
        <div className="absolute right-4 top-4 z-10">
          {/* Sem isto, quem entra e ainda não fundou colônia não tem como sair da conta. */}
          <button onClick={() => void sair()} className="text-ink-soft hover:text-rust text-xs">
            Sair
          </button>
        </div>
        <Fundacao aoFundar={carregar} />
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
              onClick={() => setMapaAberto(true)}
              className="painel bg-sand-light text-ink hover:text-rust eyebrow px-5 py-4"
            >
              Mapa
            </button>

            <button
              onClick={() => setFrotaAberta(true)}
              className="painel bg-sand-light text-ink hover:text-rust eyebrow px-5 py-4"
            >
              Frota
            </button>

            <button
              onClick={() => setCapitalAberta(true)}
              className="painel bg-sand-light text-ink hover:text-rust eyebrow px-5 py-4"
            >
              Capital
            </button>

            <button
              onClick={() => setMinisterioAberto(true)}
              className="painel bg-sand-light text-ink hover:text-rust eyebrow px-5 py-4"
            >
              Ministério
            </button>

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

      {/* O mapa e a frota só leem: fechar não muda estoque nem saldo, e não precisa recarregar. */}
      {colonia && mapaAberto && (
        <Mapa
          aoFechar={() => setMapaAberto(false)}
          aoAbrirCapital={() => {
            setMapaAberto(false)
            setCapitalAberta(true)
          }}
        />
      )}

      {colonia && frotaAberta && <Frota aoFechar={() => setFrotaAberta(false)} />}

      {/* A Capital só lê: abrir/fechar não muda estoque nem saldo. Mercado e Ministério (slots 6 e 7)
          reusam as telas de topo — a Capital as abre fechando a si mesma. */}
      {colonia && capitalAberta && (
        <Capital
          aoFechar={() => setCapitalAberta(false)}
          aoAbrirMercado={() => setMercadoAberto(true)}
          aoAbrirMinisterio={() => setMinisterioAberto(true)}
        />
      )}

      {colonia && ministerioAberto && (
        <Ministerio
          aoFechar={() => {
            setMinisterioAberto(false)
            // Uma condenação pode ter tirado recurso de circulação; o HUD reflete o estado novo.
            void carregar()
          }}
        />
      )}

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
        <Detalhe
          spec={selecionada}
          aoConstruir={construir}
          aoAtualizar={() => void carregar()}
          erro={erro}
        />
        {fila && <FilaDeObras fila={fila} />}
      </div>

      <button
        onClick={() => void sair()}
        className="painel bg-sand-light text-ink-soft hover:text-rust hover:bg-sand eyebrow absolute bottom-4 left-5 px-4 py-2"
      >
        Sair
      </button>
    </div>
  )
}
