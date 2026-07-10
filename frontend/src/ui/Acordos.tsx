import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from '../api/client'
import type { Acordo, Acordos as Carteira, ColoniaVizinha, Frota, Veiculo } from '../api/client'
import {
  NEGOCIAVEIS,
  dataHumana,
  fert,
  nomeRecurso,
  nomeVeiculo,
  paraCampoLocal,
  prazoHumano,
  segundosRestantes,
} from './recursos'

const INTERVALO_MS = 3000

/** Uma hora de folga sobre o mínimo do backend. Ver `Propor`. */
const FOLGA_MS = 3600 * 1000

const ROTULO_STATUS: Record<Acordo['status'], string> = {
  proposto: 'Proposto',
  aceito: 'Aceito',
  executado: 'Cumprido',
  quebrado: 'Quebrado',
  cancelado: 'Cancelado',
}

const ABERTO = (a: Acordo) => a.status === 'proposto' || a.status === 'aceito'

/**
 * Acordo de Troca — o "aperto de mão digital" do GDD §26.5.
 *
 * **Não há escrow** (D-40). Propor não reserva nada, aceitar não reserva nada, e quem prometeu pode
 * simplesmente não entregar. É esse o ponto: o §26.5 quer que "o risco do calote continue real, mas
 * agora haja prova". O que a tela pode fazer é não deixar ninguém caloteirar por engano — daí o
 * número bruto no formulário de entrega.
 *
 * Cumprir é **entregar fisicamente** (D-41): o acordo não move um grama. Quem move é o veículo, e
 * só a carga que aponta este acordo abate a promessa.
 */
export function Acordos({ aoFechar }: { aoFechar: () => void }) {
  const [aba, setAba] = useState<'meus' | 'propor'>('meus')
  const [carteira, setCarteira] = useState<Carteira | null>(null)
  const [frota, setFrota] = useState<Frota | null>(null)
  const [vizinhas, setVizinhas] = useState<ColoniaVizinha[]>([])
  const [erro, setErro] = useState<string | null>(null)

  const carregar = useCallback(async () => {
    try {
      const [c, f] = await Promise.all([api.acordos(), api.frota()])
      setCarteira(c)
      setFrota(f)
    } catch (e) {
      if (e instanceof ApiError) setErro(e.message)
    }
  }, [])

  useEffect(() => {
    void carregar()
    const t = setInterval(() => void carregar(), INTERVALO_MS)
    return () => clearInterval(t)
  }, [carregar])

  // O diretório só muda quando alguém funda colônia. Uma vez ao abrir basta.
  useEffect(() => {
    api
      .colonias()
      .then((r) => setVizinhas(r.colonies))
      .catch((e: unknown) => {
        if (e instanceof ApiError) setErro(e.message)
      })
  }, [])

  // Faz os prazos andarem sem bater na API.
  const [, tique] = useState(0)
  useEffect(() => {
    const t = setInterval(() => tique((n) => n + 1), 1000)
    return () => clearInterval(t)
  }, [])

  async function agir(acao: () => Promise<unknown>) {
    setErro(null)
    try {
      await acao()
      await carregar()
    } catch (e) {
      setErro(e instanceof ApiError ? e.message : 'Falha na operação.')
    }
  }

  const ociosos = frota?.vehicles.filter((v) => v.status === 'ocioso') ?? []
  const abertos = carteira?.agreements.filter(ABERTO) ?? []
  const encerrados = carteira?.agreements.filter((a) => !ABERTO(a)) ?? []

  return (
    <div className="fixed inset-0 z-20 flex items-center justify-center bg-ink/70 p-4">
      <div className="painel bg-sand-light max-h-[90vh] w-full max-w-3xl overflow-y-auto p-6">
        <header className="flex items-start justify-between">
          <div>
            <div className="text-rust eyebrow">Acordo de Troca</div>
            <h2 className="text-ink text-2xl font-black">O aperto de mão</h2>
            <p className="text-ink-soft mt-1 text-sm">
              Nada fica reservado. A promessa é registro, não garantia — quem não entrega, calotea, e
              fica escrito.
            </p>
          </div>
          <button onClick={aoFechar} className="text-ink-soft hover:text-rust text-2xl leading-none">
            ×
          </button>
        </header>

        {carteira && <Confianca carteira={carteira} />}

        <nav className="border-rust/20 mt-5 flex gap-1 border-b">
          {(['meus', 'propor'] as const).map((a) => (
            <button
              key={a}
              onClick={() => setAba(a)}
              className={`eyebrow px-4 py-2 ${
                aba === a ? 'bg-rust text-sand-light' : 'text-ink-soft hover:text-rust'
              }`}
            >
              {a === 'meus' ? 'Meus acordos' : 'Propor'}
            </button>
          ))}
        </nav>

        {erro && <p className="text-rust mt-4 text-sm font-bold">{erro}</p>}

        {aba === 'meus' ? (
          <div className="mt-5 space-y-6">
            <section>
              <div className="text-rust eyebrow">Em aberto</div>
              {abertos.length === 0 ? (
                <p className="text-ink-soft mt-2 text-sm">
                  Nenhum acordo em aberto. Proponha um na outra aba.
                </p>
              ) : (
                <div className="mt-2 space-y-3">
                  {abertos.map((a) => (
                    <Cartao
                      key={a.id}
                      acordo={a}
                      vizinhas={vizinhas}
                      ociosos={ociosos}
                      agir={agir}
                    />
                  ))}
                </div>
              )}
            </section>

            <section>
              <div className="text-rust eyebrow">Histórico</div>
              {encerrados.length === 0 ? (
                <p className="text-ink-soft mt-2 text-sm">Nenhum acordo encerrado ainda.</p>
              ) : (
                <div className="mt-2 space-y-3">
                  {encerrados.map((a) => (
                    <Cartao
                      key={a.id}
                      acordo={a}
                      vizinhas={vizinhas}
                      ociosos={ociosos}
                      agir={agir}
                    />
                  ))}
                </div>
              )}
            </section>
          </div>
        ) : (
          <Propor vizinhas={vizinhas} agir={agir} aoPropor={() => setAba('meus')} />
        )}
      </div>
    </div>
  )
}

/**
 * §26.2: Confiança Comercial baixa fecha o Mercado Central. O GDD nomeia o efeito e nunca publica o
 * limiar; o usuário arbitrou 200 numa escala de 0 a 1000, com todo colono nascendo em 500 (D-43).
 *
 * Mostrar isto aqui, e não só no Mercado, é a única forma de o colono ligar a causa ao efeito: ele
 * perde o índice caloteando um acordo e descobre a doca fechada três telas adiante.
 */
function Confianca({ carteira }: { carteira: Carteira }) {
  const { confianca_comercial: valor, limiar_mercado: limiar } = carteira
  const bloqueado = valor < limiar

  return (
    <div className="border-rust/20 mt-4 border p-3">
      <div className="flex items-baseline justify-between">
        <span className="text-rust eyebrow">Confiança Comercial</span>
        <span className="text-ink text-xl font-black tabular-nums">
          {valor}
          <span className="text-ink-soft text-xs font-bold"> / 1000</span>
        </span>
      </div>

      <div className="bg-sand relative mt-2 h-2 w-full">
        <div
          className={bloqueado ? 'bg-rust h-2' : 'bg-rust-bright h-2'}
          style={{ width: `${(valor / 1000) * 100}%` }}
        />
        {/* O limiar, marcado onde ele está: 200 de 1000. */}
        <div
          className="bg-ink absolute top-0 h-2 w-px"
          style={{ left: `${(limiar / 1000) * 100}%` }}
        />
      </div>

      <p className="text-ink-soft mt-2 text-xs">
        {bloqueado ? (
          <span className="text-rust font-bold">
            Abaixo de {limiar}: o Mercado Central está fechado para você. Só cumprir acordos
            reabre — Status Cívico alto não compensa (§26.9).
          </span>
        ) : (
          <>
            Cumprir um acordo rende 10. Caloteirar custa 50. Abaixo de {limiar} o Mercado Central
            fecha.
          </>
        )}
      </p>
    </div>
  )
}

function Cartao({
  acordo,
  vizinhas,
  ociosos,
  agir,
}: {
  acordo: Acordo
  vizinhas: ColoniaVizinha[]
  ociosos: Veiculo[]
  agir: (a: () => Promise<unknown>) => Promise<void>
}) {
  const outra = vizinhas.find((c) => c.id === acordo.counterparty_id)
  const nome = outra ? outra.nickname : `colônia #${acordo.counterparty_id}`
  const restam = segundosRestantes(acordo.deadline_at)

  const devo = Object.keys(acordo.i_still_owe).length > 0
  const podeEntregar = acordo.status === 'aceito' && devo && !!outra

  return (
    <div className="border-rust/20 border p-3">
      <div className="flex items-start justify-between gap-3">
        <div>
          <span className="text-ink font-bold">
            {acordo.proposed_by_me ? 'Você propôs a' : 'Proposta de'} {nome}
          </span>
          <div className="text-ink-soft mt-0.5 text-xs">
            Prazo {dataHumana(acordo.deadline_at)}
            {acordo.status === 'aceito' && (
              <span className={restam < 3600 ? 'text-rust font-bold' : ''}>
                {' · '}
                {prazoHumano(restam)}
              </span>
            )}
          </div>
        </div>
        <Selo status={acordo.status} />
      </div>

      <div className="mt-3 grid gap-3 md:grid-cols-2">
        <Promessa titulo="Você promete" prometido={acordo.i_promise} entregue={acordo.i_delivered} />
        <Promessa
          titulo={`${nome} promete`}
          prometido={acordo.they_promise}
          entregue={acordo.they_delivered}
        />
      </div>

      {!acordo.moves_reputation && (
        <p className="text-ink-soft mt-2 text-xs">
          Vale {fert(acordo.value_micro, 2)} Fert$ somando os dois lados: abaixo do piso de 500, fica
          registrado mas não move a Confiança Comercial (§26.3).
        </p>
      )}

      {acordo.status === 'proposto' &&
        (acordo.proposed_by_me ? (
          <div className="mt-3 flex items-center gap-3">
            <p className="text-ink-soft text-xs">
              Esperando {nome} apertar a mão. Só a contraparte confirma.
            </p>
            <button
              onClick={() => void agir(() => api.cancelarAcordo(acordo.id))}
              className="text-ink-soft hover:text-rust ml-auto text-xs font-bold"
            >
              Desistir
            </button>
          </div>
        ) : (
          <div className="mt-3 flex gap-2">
            <button
              onClick={() => void agir(() => api.aceitarAcordo(acordo.id))}
              className="bg-rust text-sand-light hover:bg-rust-bright flex-1 py-2 text-sm font-bold"
            >
              Aceitar
            </button>
            <button
              onClick={() => void agir(() => api.cancelarAcordo(acordo.id))}
              className="border-rust/25 text-ink-soft hover:text-rust flex-1 border py-2 text-sm font-bold"
            >
              Recusar
            </button>
          </div>
        ))}

      {acordo.status === 'aceito' && !devo && (
        <p className="text-ink-soft mt-3 text-xs">
          Você entregou tudo o que prometeu. O acordo fecha quando a carga de {nome} chegar.
        </p>
      )}

      {podeEntregar && (
        <Entrega acordo={acordo} destino={outra.id} nome={nome} ociosos={ociosos} agir={agir} />
      )}

      {acordo.status === 'aceito' && devo && !outra && (
        <p className="text-rust mt-3 text-xs">
          A colônia de destino sumiu do diretório. Não há como despachar.
        </p>
      )}
    </div>
  )
}

function Selo({ status }: { status: Acordo['status'] }) {
  const cor =
    status === 'quebrado'
      ? 'bg-rust text-sand-light'
      : status === 'executado'
        ? 'bg-rust-bright text-sand-light'
        : 'border-rust/25 text-ink-soft border'

  return <span className={`eyebrow shrink-0 px-2 py-1 text-xs ${cor}`}>{ROTULO_STATUS[status]}</span>
}

function Promessa({
  titulo,
  prometido,
  entregue,
}: {
  titulo: string
  prometido: Record<string, number>
  entregue: Record<string, number>
}) {
  const linhas = Object.entries(prometido)

  return (
    <div>
      <div className="text-ink-soft eyebrow text-xs">{titulo}</div>
      {linhas.length === 0 ? (
        <p className="text-ink-soft mt-1 text-sm">—</p>
      ) : (
        <div className="mt-1">
          {linhas.map(([codigo, qtd]) => {
            const chegou = entregue[codigo] ?? 0
            const completo = chegou >= qtd

            return (
              <div
                key={codigo}
                className="border-rust/10 flex justify-between border-b py-1 text-sm last:border-0"
              >
                <span className="text-ink-soft">{nomeRecurso(codigo)}</span>
                <span className={`tabular-nums ${completo ? 'text-rust-bright' : 'text-ink'} font-bold`}>
                  {chegou.toLocaleString('pt-BR')} / {qtd.toLocaleString('pt-BR')}
                </span>
              </div>
            )
          })}
        </div>
      )}
    </div>
  )
}

/**
 * Despacha carga apontando este acordo (D-41).
 *
 * A quantidade nasce preenchida com o **bruto**, não com o que falta: o tributo do §25.2 come a
 * carga na chegada, e quem despacha exatamente o prometido entrega menos do que prometeu. Ninguém
 * deve descobrir que caloteou por três unidades de tributo.
 */
function Entrega({
  acordo,
  destino,
  nome,
  ociosos,
  agir,
}: {
  acordo: Acordo
  destino: number
  nome: string
  ociosos: Veiculo[]
  agir: (a: () => Promise<unknown>) => Promise<void>
}) {
  const devidos = Object.keys(acordo.i_still_owe)
  const [codigo, setCodigo] = useState('')

  const escolhido = devidos.includes(codigo) ? codigo : devidos[0]
  const liquido = acordo.i_still_owe[escolhido] ?? 0
  const bruto = acordo.gross_needed[escolhido] ?? 0
  const veiculo = ociosos[0]

  const impedimento = !veiculo
    ? 'Nenhum veículo ocioso.'
    : bruto > veiculo.capacity
      ? `O veículo leva ${veiculo.capacity.toLocaleString('pt-BR')}. Entregue em partes.`
      : null

  return (
    <div className="border-rust/15 bg-sand mt-3 border p-3">
      <div className="text-rust eyebrow">Entregar a {nome}</div>
      <p className="text-ink-soft mt-1 text-xs">
        A carga sai do estoque agora e abate a promessa quando chegar.
        {veiculo && (
          <>
            {' '}
            Vai o {nomeVeiculo(veiculo.type)}, que leva{' '}
            {veiculo.capacity.toLocaleString('pt-BR')}.
          </>
        )}
      </p>

      <div className="mt-2 flex flex-wrap items-center gap-2">
        <select
          aria-label="Recurso a entregar"
          className="border-rust/25 bg-sand-light focus:border-rust flex-1 border px-2 py-1.5 text-sm outline-none"
          value={escolhido ?? ''}
          onChange={(e) => setCodigo(e.target.value)}
        >
          {devidos.map((c) => (
            <option key={c} value={c}>
              {nomeRecurso(c)} (faltam {acordo.i_still_owe[c].toLocaleString('pt-BR')})
            </option>
          ))}
        </select>

        <button
          disabled={!!impedimento || !escolhido}
          onClick={() =>
            void agir(() => api.enviarAColonia(veiculo.id, destino, { [escolhido]: bruto }, acordo.id))
          }
          className="bg-rust text-sand-light hover:bg-rust-bright px-4 py-2 text-sm font-bold disabled:cursor-not-allowed disabled:opacity-40"
        >
          Despachar {bruto.toLocaleString('pt-BR')}
        </button>
      </div>

      {escolhido && bruto > liquido && (
        <p className="text-ink-soft mt-2 text-xs">
          Faltam <span className="text-ink font-bold">{liquido.toLocaleString('pt-BR')}</span>{' '}
          líquidos, então o veículo leva{' '}
          <span className="text-ink font-bold">{bruto.toLocaleString('pt-BR')}</span>: o tributo da
          entrega come a diferença no caminho.
        </p>
      )}

      {impedimento && <p className="text-rust mt-2 text-xs">{impedimento}</p>}
    </div>
  )
}

function Propor({
  vizinhas,
  agir,
  aoPropor,
}: {
  vizinhas: ColoniaVizinha[]
  agir: (a: () => Promise<unknown>) => Promise<void>
  aoPropor: () => void
}) {
  const [contraparte, setContraparte] = useState<number | null>(null)
  const [minimo, setMinimo] = useState<string | null>(null)
  const [prazo, setPrazo] = useState('')
  const [euPrometo, setEuPrometo] = useState<Record<string, number>>({})
  const [elePromete, setElePromete] = useState<Record<string, number>>({})

  const outra = vizinhas.find((c) => c.id === contraparte) ?? vizinhas[0]

  /*
   * O prazo mínimo é a viagem do veículo mais lento entre as duas colônias, mais 12 h (D-42), e só
   * o backend sabe calculá-lo. Buscá-lo a cada troca de contraparte, e preencher o campo com uma
   * hora de folga: preenchê-lo com o mínimo exato faria a proposta ser recusada por um segundo de
   * relógio entre carregar a tela e apertar o botão.
   */
  useEffect(() => {
    if (!outra) return
    let vivo = true

    api
      .prazoMinimoDoAcordo(outra.id)
      .then((p) => {
        if (!vivo) return
        setMinimo(p.minimum_deadline_at)
        setPrazo(paraCampoLocal(new Date(new Date(p.minimum_deadline_at).getTime() + FOLGA_MS).toISOString()))
      })
      .catch(() => {
        if (vivo) setMinimo(null)
      })

    return () => {
      vivo = false
    }
  }, [outra])

  const valido =
    !!outra &&
    !!prazo &&
    Object.keys(euPrometo).length > 0 &&
    Object.keys(elePromete).length > 0

  if (vizinhas.length === 0) {
    return <p className="text-ink-soft mt-5 text-sm">Nenhuma outra colônia no servidor.</p>
  }

  return (
    <div className="mt-5 space-y-4">
      <div>
        <label className="text-rust eyebrow" htmlFor="contraparte">
          Com quem
        </label>
        <select
          id="contraparte"
          className="border-rust/25 bg-sand focus:border-rust mt-1 w-full border px-2 py-1.5 text-sm outline-none"
          value={outra?.id ?? ''}
          onChange={(e) => setContraparte(Number(e.target.value))}
        >
          {vizinhas.map((c) => (
            <option key={c.id} value={c.id}>
              {c.nickname} · {c.distance} slots
            </option>
          ))}
        </select>
      </div>

      <div>
        <label className="text-rust eyebrow" htmlFor="prazo">
          Prazo de cumprimento
        </label>
        <input
          id="prazo"
          type="datetime-local"
          className="border-rust/25 bg-sand focus:border-rust mt-1 w-full border px-2 py-1.5 text-sm outline-none"
          value={prazo}
          onChange={(e) => setPrazo(e.target.value)}
        />
        {minimo && (
          <p className="text-ink-soft mt-1 text-xs">
            O mínimo é {dataHumana(minimo)} — a viagem do veículo mais lento, mais 12 h de folga. Um
            prazo curto demais fabricaria o calote do outro.
          </p>
        )}
      </div>

      {/*
       * Nada aqui é reservado, e por isso nada é conferido contra o estoque: o §26.5 deixa prometer
       * o que ainda vai ser minerado. Quem promete o que não tem, calotea — e fica escrito.
       */}
      <div className="grid gap-4 md:grid-cols-2">
        <Lado titulo="Você promete" itens={euPrometo} aoMudar={setEuPrometo} />
        <Lado titulo={`${outra?.nickname ?? 'A contraparte'} promete`} itens={elePromete} aoMudar={setElePromete} />
      </div>

      <button
        disabled={!valido}
        onClick={() => {
          if (!outra) return
          void agir(() =>
            api.proporAcordo({
              counterparty_id: outra.id,
              // O campo é hora local; o backend quer um instante sem ambiguidade de fuso.
              deadline_at: new Date(prazo).toISOString(),
              i_promise: euPrometo,
              they_promise: elePromete,
            }),
          ).then(() => {
            setEuPrometo({})
            setElePromete({})
            aoPropor()
          })
        }}
        className="bg-rust text-sand-light hover:bg-rust-bright w-full py-3 font-bold disabled:cursor-not-allowed disabled:opacity-40"
      >
        Propor acordo
      </button>

      <p className="text-ink-soft text-xs">
        Nada sai do seu estoque ao propor. O acordo só vira evidência quando a contraparte confirmar.
      </p>
    </div>
  )
}

/** Um lado da promessa: recursos e quantidades, somados livremente. */
function Lado({
  titulo,
  itens,
  aoMudar,
}: {
  titulo: string
  itens: Record<string, number>
  aoMudar: (i: Record<string, number>) => void
}) {
  const [codigo, setCodigo] = useState(NEGOCIAVEIS[0])
  const [qtd, setQtd] = useState('')

  const quantidade = Number(qtd)
  const pode = Number.isInteger(quantidade) && quantidade > 0

  return (
    <div className="border-rust/20 border p-3">
      <div className="text-rust eyebrow">{titulo}</div>

      <div className="mt-2 space-y-2">
        <select
          aria-label={titulo}
          className="border-rust/25 bg-sand focus:border-rust w-full border px-2 py-1.5 text-sm outline-none"
          value={codigo}
          onChange={(e) => setCodigo(e.target.value)}
        >
          {NEGOCIAVEIS.map((c) => (
            <option key={c} value={c}>
              {nomeRecurso(c)}
            </option>
          ))}
        </select>

        <div className="flex gap-2">
          <input
            className="border-rust/25 bg-sand focus:border-rust flex-1 border px-2 py-1.5 text-sm outline-none"
            placeholder="Quantidade"
            inputMode="numeric"
            value={qtd}
            onChange={(e) => setQtd(e.target.value.replace(/\D/g, ''))}
          />
          <button
            disabled={!pode}
            onClick={() => {
              // Somar, não substituir: pedir 100 e depois mais 50 do mesmo recurso é pedir 150.
              aoMudar({ ...itens, [codigo]: (itens[codigo] ?? 0) + quantidade })
              setQtd('')
            }}
            className="border-rust/25 text-ink-soft hover:text-rust border px-3 text-sm font-bold disabled:cursor-not-allowed disabled:opacity-40"
          >
            Somar
          </button>
        </div>
      </div>

      {Object.keys(itens).length === 0 ? (
        <p className="text-ink-soft mt-2 text-xs">Nada prometido ainda.</p>
      ) : (
        <div className="mt-2">
          {Object.entries(itens).map(([c, q]) => (
            <div
              key={c}
              className="border-rust/10 flex items-center justify-between border-b py-1 text-sm last:border-0"
            >
              <span className="text-ink-soft">{nomeRecurso(c)}</span>
              <span className="flex items-center gap-2">
                <span className="text-ink font-bold tabular-nums">{q.toLocaleString('pt-BR')}</span>
                <button
                  aria-label={`Remover ${nomeRecurso(c)}`}
                  onClick={() => {
                    const { [c]: _, ...resto } = itens
                    aoMudar(resto)
                  }}
                  className="text-ink-soft hover:text-rust text-xs"
                >
                  ×
                </button>
              </span>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
