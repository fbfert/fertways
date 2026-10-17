import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from '../api/client'
import type { ColoniaVizinha, Frota as FrotaDto, MinhaZona, Veiculo } from '../api/client'
import { nomeRecurso, nomeVeiculo, relogio, segundosRestantes } from './recursos'

const INTERVALO_MS = 3000

const ESTADO: Record<Veiculo['status'], string> = {
  ocioso: 'Ocioso',
  carregando: 'Carregando',
  em_rota: 'Em rota',
  descarregando: 'Descarregando',
  em_upgrade: 'Em upgrade',
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
export function Frota() {
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

  // D-281: as zonas do colono, para os voos da Nave. Só se busca quando há Nave na frota.
  const temNave = frota?.vehicles.some((v) => v.type === NAVE) ?? false
  const [zonas, setZonas] = useState<MinhaZona[]>([])
  useEffect(() => {
    if (!temNave) return
    api
      .minhasZonas()
      .then((r) => setZonas(r.zones))
      .catch(() => {
        /* Sem a lista, o painel da Nave diz que não há zona; o resto da frota segue. */
      })
  }, [temNave])

  function destino(v: Veiculo): string {
    if (v.destination_type === 'mercado_central') return 'Mercado Central'
    if (v.destination_type === 'zona_neutra') {
      const z = zonas.find((x) => x.id === v.destination_id)
      return z ? (z.name ?? `Zona (${z.x}, ${z.y})`) : 'Zona neutra'
    }
    if (v.destination_type === 'colonia') {
      const c = vizinhas.find((x) => x.id === v.destination_id)
      return c ? c.name : `Colônia #${v.destination_id}`
    }
    return '—'
  }

  return (
    <div className="bg-sand fixed inset-0 z-20 overflow-y-auto">
      <div className="bg-sand-light mx-auto min-h-screen w-full max-w-3xl px-6 pt-20 pb-24 md:pt-28 md:pb-6">
        <header>
          <div className="text-rust eyebrow">Ministério dos Transportes</div>
          <h2 className="text-ink text-2xl font-black">Sua frota</h2>
          {frota && (
            <p className="text-ink-soft mt-1 text-sm">
              {frota.vehicles.length} {frota.vehicles.length === 1 ? 'veículo' : 'veículos'} ·{' '}
              {frota.vehicles.filter((v) => v.status === 'ocioso').length} ocioso(s)
            </p>
          )}
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
                    <NomeEApelido veiculo={v} aoRenomear={() => void carregar()} />
                    <div className="text-ink-soft text-sm">
                      {nomeVeiculo(v.type)} · nível {v.level}
                      {v.plate && <span className="ml-2 tabular-nums">{v.plate}</span>}
                    </div>
                  </div>
                  <span
                    className={`eyebrow ${v.status === 'ocioso' ? 'text-ink-soft' : 'text-rust'}`}
                  >
                    {ESTADO[v.status]}
                  </span>
                </div>

                {v.status === 'ocioso' && v.type === NAVE && frota ? (
                  <PainelDaNave nave={v} colonia={frota.colony.id} zonas={zonas} aoVoar={() => void carregar()} />
                ) : v.status === 'ocioso' ? (
                  <p className="text-ink-soft mt-2 text-sm">
                    {v.local === 'capital'
                      ? 'Estacionado no Pátio da Capital.'
                      : v.local === 'zona'
                        ? 'Estacionado numa zona neutra sua.'
                        : 'Em casa.'}{' '}
                    Capacidade de {v.capacity.toLocaleString('pt-BR')} unidades.
                  </p>
                ) : v.status === 'em_upgrade' ? (
                  /* D-259: sem destino nem carga — está na oficina do Ministério. */
                  <p className="text-ink-soft mt-2 text-sm">
                    Na oficina do Ministério, subindo para o nível {v.level + 1}. Volta ao pátio
                    sozinho quando o serviço acabar — veja o prazo na Central de Transportes.
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
                        valor={
                          v.trip_purpose === 'entrega'
                            ? 'Entrega'
                            : v.trip_purpose === 'reposicionamento'
                              ? 'Indo vazio, para ficar lá'
                              : 'Retirada'
                        }
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
          Despachar carga é do Mercado (depósito e retirada), do Acordo (entrega) e de cada zona
          (retirada e material de obra). Quem leva carga ao depósito da Capital fica estacionado no
          Pátio; as outras viagens voltam para casa.
        </p>
      </div>
    </div>
  )
}

const NAVE = 'nave_de_transporte_planetaria'

/**
 * Os voos da Nave de Transporte Planetária (D-281). Ela só voa entre a colônia e as zonas com
 * Plataforma de Pouso: daqui ela vai vazia até uma zona, volta para casa, troca de zona, ou leva até
 * quatro Robôs Mineradores da zona onde está para outra. Retirar carga e levar material continuam
 * na tela de cada zona, como para qualquer veículo.
 */
function PainelDaNave({
  nave,
  colonia,
  zonas,
  aoVoar,
}: {
  nave: Veiculo
  colonia: number
  zonas: MinhaZona[]
  aoVoar: () => void
}) {
  const naZona = nave.local === 'zona'
  const aqui = naZona ? zonas.find((z) => z.id === nave.destination_id) : undefined
  const destinos = zonas.filter((z) => z.plataforma_de_pouso && z.id !== aqui?.id)
  const [alvo, setAlvo] = useState<number | null>(null)
  const [robos, setRobos] = useState(0)
  const [ocupado, setOcupado] = useState(false)
  const [aviso, setAviso] = useState<string | null>(null)
  const escolhida = destinos.find((z) => z.id === alvo) ?? destinos[0]
  const nomeZona = (z: MinhaZona) => z.name ?? `Zona (${z.x}, ${z.y})`
  const robosAqui = aqui?.guarnicao.robos ?? 0

  async function voar(acao: () => Promise<unknown>, recibo: string) {
    setOcupado(true)
    try {
      await acao()
      setAviso(recibo)
      aoVoar()
    } catch (e) {
      setAviso(e instanceof ApiError ? e.message : 'A Nave não decolou.')
    } finally {
      setOcupado(false)
    }
  }

  return (
    <div className="mt-2 space-y-2 text-sm" data-painel-nave={nave.id}>
      <p className="text-ink-soft">
        {naZona ? (
          <>
            Pousada em <b className="text-ink">{aqui ? nomeZona(aqui) : 'uma zona sua'}</b>.
          </>
        ) : (
          'Na Plataforma de Pouso da colônia.'
        )}{' '}
        Leva {nave.capacity.toLocaleString('pt-BR')} unidades e queima 50 de Gelo de Metano por minuto de voo
        (10 slots por minuto).
      </p>

      {destinos.length === 0 ? (
        <p className="text-ink-soft text-xs">
          Nenhuma {naZona ? 'outra ' : ''}zona sua tem Plataforma de Pouso. Erga uma na zona para a Nave poder
          pousar lá.
        </p>
      ) : (
        <div className="flex flex-wrap items-center gap-2">
          <select
            className="border-rust/30 border bg-transparent px-1 py-0.5 text-xs"
            value={escolhida?.id ?? ''}
            onChange={(e) => setAlvo(Number(e.target.value))}
            aria-label="Zona de destino da Nave"
            data-destino-nave={nave.id}
          >
            {destinos.map((z) => (
              <option key={z.id} value={z.id}>
                {nomeZona(z)}
                {z.cercada ? ' (cercada)' : ''}
              </option>
            ))}
          </select>
          <button
            type="button"
            className="botao text-xs"
            disabled={ocupado || !escolhida}
            data-voar-nave={nave.id}
            onClick={() =>
              escolhida &&
              void voar(
                () => api.reposicionarVazio(nave.id, 'zona_neutra', escolhida.id),
                `A Nave decolou vazia para ${nomeZona(escolhida)}.`,
              )
            }
          >
            Voar vazia para lá
          </button>
        </div>
      )}

      {naZona && escolhida && robosAqui > 0 && (
        <div className="flex flex-wrap items-center gap-2" data-robos-nave={nave.id}>
          <span className="text-ink-soft text-xs">Levar</span>
          <input
            type="number"
            min={1}
            max={Math.min(4, robosAqui)}
            value={robos || ''}
            onChange={(e) => setRobos(Math.min(4, robosAqui, Math.max(0, Number(e.target.value) || 0)))}
            aria-label="Robôs Mineradores a levar"
            className="border-rust/30 w-14 border px-1 py-0.5 text-right text-xs tabular-nums"
          />
          <span className="text-ink-soft text-xs">
            de {robosAqui} Robô(s) Minerador(es) desta zona (até 4 por voo) para {nomeZona(escolhida)}
          </span>
          <button
            type="button"
            className="botao text-xs"
            disabled={ocupado || robos < 1}
            onClick={() =>
              void voar(
                () => api.transportarRobos(nave.id, escolhida.id, robos),
                `A Nave decolou com ${robos} robô(s) para ${nomeZona(escolhida)}.`,
              )
            }
          >
            Levar robôs
          </button>
        </div>
      )}

      {naZona && (
        <button
          type="button"
          className="botao text-xs"
          disabled={ocupado}
          onClick={() => void voar(() => api.reposicionarVazio(nave.id, 'colonia', colonia), 'A Nave voltou a voar para casa.')}
        >
          Voltar para casa
        </button>
      )}

      {aviso && <p className="text-ink text-xs">{aviso}</p>}
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

/**
 * O apelido do veículo (pedido do usuário). Mesmo padrão de "sem mudança, sem botão" que a zona já
 * usa: o "Salvar" só aparece quando o campo difere do que está gravado, e vazio remove o apelido.
 */
function NomeEApelido({ veiculo, aoRenomear }: { veiculo: Veiculo; aoRenomear: () => void }) {
  const [nome, setNome] = useState(veiculo.nickname ?? '')
  const [salvando, setSalvando] = useState(false)

  useEffect(() => setNome(veiculo.nickname ?? ''), [veiculo.nickname])

  const mudou = nome.trim() !== (veiculo.nickname ?? '')

  return (
    <div className="flex flex-wrap items-center gap-2">
      <input
        type="text"
        value={nome}
        onChange={(e) => setNome(e.target.value)}
        placeholder={nomeVeiculo(veiculo.type)}
        maxLength={60}
        data-apelido-veiculo={veiculo.id}
        className="text-ink border-rust/25 focus:border-rust w-40 border-b bg-transparent font-black outline-none"
      />
      {mudou && (
        <button
          type="button"
          className="botao text-xs"
          data-salvar-apelido={veiculo.id}
          disabled={salvando}
          onClick={() =>
            void (async () => {
              setSalvando(true)
              try {
                await api.renomearVeiculo(veiculo.id, nome.trim())
                aoRenomear()
              } catch {
                /* A tela recarrega sozinha a cada 3s; um erro aqui não trava nada. */
              } finally {
                setSalvando(false)
              }
            })()
          }
        >
          Salvar
        </button>
      )}
    </div>
  )
}
