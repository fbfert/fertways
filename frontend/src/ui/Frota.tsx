import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from '../api/client'
import type { ColoniaVizinha, Frota as FrotaDto, Veiculo } from '../api/client'
import { nomeRecurso, nomeVeiculo, relogio, segundosRestantes } from './recursos'

const INTERVALO_MS = 3000

const ESTADO: Record<Veiculo['status'], string> = {
  ocioso: 'Ocioso',
  carregando: 'Carregando',
  em_rota: 'Em rota',
  descarregando: 'Descarregando',
}

/**
 * A frota do colono: onde cada veículo está, o que carrega e quando chega.
 *
 * Só mostra. Despachar continua sendo do Mercado (depósito e retirada) e do Acordo (entrega),
 * porque o destino e a carga só fazem sentido dentro daquelas negociações — um "despachar" solto
 * pediria ao jogador que escolhesse destino, carga e propósito sem contexto nenhum.
 *
 * Antes desta tela não havia onde ver um Furgão em viagem: o jogador despachava e o veículo sumia
 * até voltar. A viagem é de ida e volta (D-30), e a volta é o que libera o veículo.
 */
export function Frota({ aoFechar }: { aoFechar: () => void }) {
  const [frota, setFrota] = useState<FrotaDto | null>(null)
  const [vizinhas, setVizinhas] = useState<ColoniaVizinha[]>([])
  const [erro, setErro] = useState<string | null>(null)

  const carregar = useCallback(async () => {
    try {
      setFrota(await api.frota())
    } catch (e) {
      if (e instanceof ApiError) setErro(e.message)
    }
  }, [])

  useEffect(() => {
    void carregar()
    const t = setInterval(() => void carregar(), INTERVALO_MS)
    return () => clearInterval(t)
  }, [carregar])

  // O diretório só muda quando alguém funda colônia: uma busca ao abrir basta. Serve para dar
  // nome ao destino, em vez de mostrar ao jogador a chave primária de uma colônia.
  useEffect(() => {
    api
      .colonias()
      .then((r) => setVizinhas(r.colonies))
      .catch(() => {
        /* Sem o diretório o destino aparece como "Colônia #id". Não vale barrar a tela por isso. */
      })
  }, [])

  // Faz as contagens regressivas andarem sem bater na API.
  const [, tique] = useState(0)
  useEffect(() => {
    const t = setInterval(() => tique((n) => n + 1), 1000)
    return () => clearInterval(t)
  }, [])

  function destino(v: Veiculo): string {
    if (v.destination_type === 'mercado_central') return 'Mercado Central'
    if (v.destination_type === 'colonia') {
      const c = vizinhas.find((x) => x.id === v.destination_id)
      return c ? c.name : `Colônia #${v.destination_id}`
    }
    return '—'
  }

  return (
    <div className="fixed inset-0 z-20 flex items-center justify-center bg-ink/70 p-4">
      <div className="painel bg-sand-light max-h-[90vh] w-full max-w-3xl overflow-y-auto p-6">
        <header className="flex items-start justify-between">
          <div>
            <div className="text-rust eyebrow">Ministério dos Transportes</div>
            <h2 className="text-ink text-2xl font-black">Sua frota</h2>
            {frota && (
              <p className="text-ink-soft mt-1 text-sm">
                {frota.vehicles.length} {frota.vehicles.length === 1 ? 'veículo' : 'veículos'} ·{' '}
                {frota.vehicles.filter((v) => v.status === 'ocioso').length} ocioso(s)
              </p>
            )}
          </div>
          <button onClick={aoFechar} className="text-ink-soft hover:text-rust text-2xl leading-none">
            ×
          </button>
        </header>

        {erro && <p className="text-rust mt-4 text-sm">{erro}</p>}

        {frota?.vehicles.length === 0 && (
          <p className="text-ink-soft mt-6 text-sm">Nenhum veículo. O kit inicial traz um Furgão.</p>
        )}

        <ul className="mt-5 space-y-3">
          {frota?.vehicles.map((v) => {
            const faltam = segundosRestantes(v.arrives_at)
            const carga = Object.entries(v.cargo ?? {}).filter(([, q]) => q > 0)

            return (
              <li key={v.id} className="border-rust/25 bg-sand border p-4">
                <div className="flex items-baseline justify-between">
                  <div>
                    <span className="text-ink font-black">{nomeVeiculo(v.type)}</span>
                    <span className="text-ink-soft ml-2 text-sm">nível {v.level}</span>
                  </div>
                  <span
                    className={`eyebrow ${v.status === 'ocioso' ? 'text-ink-soft' : 'text-rust'}`}
                  >
                    {ESTADO[v.status]}
                  </span>
                </div>

                {v.status === 'ocioso' ? (
                  <p className="text-ink-soft mt-2 text-sm">
                    No pátio. Capacidade de {v.capacity.toLocaleString('pt-BR')} unidades.
                  </p>
                ) : (
                  <dl className="text-ink-soft mt-2 space-y-1 text-sm">
                    <Linha termo="Destino" valor={destino(v)} />
                    <Linha
                      termo="Trecho"
                      valor={v.leg === 'volta' ? 'Voltando ao slot' : 'Indo ao destino'}
                    />
                    {v.trip_purpose && (
                      <Linha
                        termo="Propósito"
                        valor={v.trip_purpose === 'entrega' ? 'Entrega' : 'Retirada'}
                      />
                    )}
                    {v.distance_slots !== null && (
                      <Linha termo="Distância" valor={`${v.distance_slots} slots`} />
                    )}
                    {v.arrives_at && (
                      <Linha termo="Chega em" valor={faltam > 0 ? relogio(faltam) : 'chegando…'} />
                    )}
                  </dl>
                )}

                {carga.length > 0 && (
                  <div className="mt-3">
                    <div className="text-rust eyebrow text-xs">Carga</div>
                    <ul className="text-ink-soft mt-1 text-sm">
                      {carga.map(([r, q]) => (
                        <li key={r} className="flex justify-between">
                          <span>{nomeRecurso(r)}</span>
                          <span className="text-ink tabular-nums">{q.toLocaleString('pt-BR')}</span>
                        </li>
                      ))}
                    </ul>
                  </div>
                )}
              </li>
            )
          })}
        </ul>

        <p className="text-ink-soft/70 mt-5 text-xs">
          Despachar carga é do Mercado (depósito e retirada) e do Acordo (entrega). A viagem é
          sempre de ida e volta: o veículo só volta a ficar ocioso ao regressar ao seu slot.
        </p>
      </div>
    </div>
  )
}

function Linha({ termo, valor }: { termo: string; valor: string }) {
  return (
    <div className="flex justify-between">
      <dt>{termo}</dt>
      <dd className="text-ink">{valor}</dd>
    </div>
  )
}
