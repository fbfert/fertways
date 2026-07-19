import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from '../api/client'
import type {
  Acordo,
  Acordos as Carteira,
  Colonia,
  ColoniaVizinha,
  Frota,
  OfertaDeColono,
  Veiculo,
} from '../api/client'
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

/**
 * Prazo que a tela sugere numa oferta **aberta**: três dias.
 *
 * O backend só exige o piso teórico (12 h) ao anunciar, porque sem contraparte não há distância a
 * calcular. Mas quem aceita precisa caber no D-42 — e um prazo de 12 h só seria aceitável por um
 * vizinho de porta. Três dias deixam a oferta ao alcance de qualquer colônia do mapa.
 */
const PRAZO_SUGERIDO_ABERTO_MS = 3 * 24 * 3600 * 1000

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
/**
 * "Ofertar entre colonos": o Acordo de Troca (§26.5), agora como aba do Mercado (D-58).
 *
 * Este é o canal do **estoque da colônia**: promessa registrada, entrega física por veículo, e
 * **sem escrow** — o calote é real e deliberado (D-40), e é ele que alimenta o Ministério.
 *
 * Desde o D-58 a oferta pode ir ao **mural**, sem destinatário: quem aceitar primeiro vira a
 * contraparte. Ver `VerOfertasDeColonos`.
 */
export function OfertarEntreColonos({ colonia }: { colonia: Colonia }) {
  const [aba, setAba] = useState<'meus' | 'propor'>('propor')
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
    <div className="mt-5">
      <p className="text-ink-soft text-sm">
        Aqui se negocia o que está <strong className="text-ink">na sua colônia</strong>. Nada fica
        reservado: a promessa é registro, não garantia — quem não entrega, calotea, e fica escrito.
      </p>

      {carteira && <Confianca carteira={carteira} />}

      <nav className="border-rust/20 mt-4 flex gap-1 border-b">
        {(['propor', 'meus'] as const).map((a) => (
          <button
            key={a}
            onClick={() => setAba(a)}
            className={`eyebrow px-4 py-2 ${
              aba === a ? 'bg-rust text-sand-light' : 'text-ink-soft hover:text-rust'
            }`}
          >
            {a === 'meus' ? 'Meus acordos' : 'Nova oferta'}
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
                Nenhum acordo em aberto. Faça uma oferta na outra aba.
              </p>
            ) : (
              <div className="mt-2 space-y-3">
                {abertos.map((a) => (
                  <Cartao key={a.id} acordo={a} vizinhas={vizinhas} ociosos={ociosos} agir={agir} />
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
                  <Cartao key={a.id} acordo={a} vizinhas={vizinhas} ociosos={ociosos} agir={agir} />
                ))}
              </div>
            )}
          </section>
        </div>
      ) : (
        <Propor
          colonia={colonia}
          vizinhas={vizinhas}
          agir={agir}
          aoPropor={() => setAba('meus')}
        />
      )}
    </div>
  )
}

/**
 * "Ver ofertas de colonos": o mural do D-58.
 *
 * As ofertas abertas de todos, sem contraparte definida. Quem aceitar primeiro vira a contraparte —
 * e o prazo do D-42 é cobrado **aqui**, não no anúncio: quem mora longe demais para cumprir o prazo
 * simplesmente não consegue aceitar, e é melhor assim do que herdar um calote já fabricado.
 */
export function VerOfertasDeColonos() {
  const [ofertas, setOfertas] = useState<OfertaDeColono[]>([])
  const [erro, setErro] = useState<string | null>(null)

  const carregar = useCallback(async () => {
    try {
      setOfertas((await api.mural()).ofertas)
    } catch (e) {
      if (e instanceof ApiError) setErro(e.message)
    }
  }, [])

  useEffect(() => {
    void carregar()
    const t = setInterval(() => void carregar(), INTERVALO_MS)
    return () => clearInterval(t)
  }, [carregar])

  async function aceitar(id: number) {
    setErro(null)
    try {
      await api.aceitarOfertaDoMural(id)
      await carregar()
    } catch (e) {
      setErro(e instanceof ApiError ? e.message : 'Falha ao aceitar.')
    }
  }

  return (
    <div className="mt-5">
      <p className="text-ink-soft text-sm">
        Ofertas abertas a quem quiser. Aceitar vira um acordo com prazo, e cumprir é{' '}
        <strong className="text-ink">entregar de verdade</strong>, por veículo, do estoque da sua
        colônia.
      </p>

      {erro && <p className="text-rust mt-4 text-sm font-bold">{erro}</p>}

      {ofertas.length === 0 ? (
        <p className="text-ink-soft mt-4 text-sm">
          Nenhuma oferta no mural. Anuncie a sua na aba "Ofertar entre colonos".
        </p>
      ) : (
        <div className="mt-4 space-y-3">
          {ofertas.map((o) => (
            <div key={o.id} className="border-rust/15 border p-3">
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="text-sm">
                  <div className="text-ink font-bold">
                    {o.minha ? 'Sua oferta' : (o.colonia ?? `colônia #${o.colony_id}`)}
                  </div>
                  <div className="text-ink-soft mt-1">
                    <span className="text-ink font-bold">dá</span> {listar(o.oferece)}
                  </div>
                  <div className="text-ink-soft">
                    <span className="text-ink font-bold">quer</span> {listar(o.quer)}
                  </div>
                  <div className="text-ink-soft mt-1 text-xs">
                    prazo até {dataHumana(o.deadline_at)}
                  </div>
                </div>

                {!o.minha && (
                  <button
                    onClick={() => void aceitar(o.id)}
                    className="bg-rust text-sand-light hover:bg-rust-bright px-4 py-1.5 text-sm font-bold"
                  >
                    Aceitar
                  </button>
                )}
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

/** "100 de Metal Bruto, 200 de Água" — a promessa em linguagem de gente. */
function listar(itens: Record<string, number>): string {
  const partes = Object.entries(itens).map(
    ([c, q]) => `${q.toLocaleString('pt-BR')} de ${nomeRecurso(c)}`,
  )

  return partes.length === 0 ? '—' : partes.join(', ')
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
          Vale {fert(acordo.value_micro, 2)} Fert$ somando os dois lados: abaixo do piso de 5 Fert$
          (D-117), fica registrado mas não move a Confiança Comercial (§26.3).
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

  /*
   * D-65: a promessa vai INTEIRA numa carroceria só, se couber.
   *
   * Um acordo pode prometer três recursos, e até aqui a tela despachava um por vez — três viagens
   * para pagar uma promessa, num veículo que sempre soube levar os três (o §25.4 mede a capacidade
   * em unidades somadas). Agora ele leva tudo o que estiver marcado, e o colono só desmarca o que
   * não couber.
   */
  const [fora, setFora] = useState<string[]>([])
  const marcados = devidos.filter((c) => !fora.includes(c))

  const bruto = (c: string) => acordo.gross_needed[c] ?? 0
  const total = marcados.reduce((s, c) => s + bruto(c), 0)

  const veiculo = ociosos[0]
  const capacidade = veiculo?.capacity_efetiva ?? 0

  const impedimento = !veiculo
    ? 'Nenhum veículo ocioso.'
    : marcados.length === 0
      ? 'Marque o que vai na carga.'
      : total > capacidade
        ? `O veículo leva ${capacidade.toLocaleString('pt-BR')}. Desmarque algo, ou entregue em partes.`
        : null

  const carga = Object.fromEntries(marcados.map((c) => [c, bruto(c)]))

  return (
    <div className="border-rust/15 bg-sand mt-3 border p-3">
      <div className="text-rust eyebrow">Entregar a {nome}</div>
      <p className="text-ink-soft mt-1 text-xs">
        A carga sai do estoque agora e abate a promessa quando chegar.
        {veiculo && (
          <>
            {' '}
            Vai o {nomeVeiculo(veiculo.type)}, que leva {capacidade.toLocaleString('pt-BR')}.
          </>
        )}
      </p>

      <div className="mt-2 space-y-1">
        {devidos.map((c) => (
          <label key={c} className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              aria-label={`Levar ${nomeRecurso(c)}`}
              checked={marcados.includes(c)}
              onChange={() =>
                setFora((f) => (f.includes(c) ? f.filter((x) => x !== c) : [...f, c]))
              }
            />
            <span className="text-ink-soft flex-1">
              {nomeRecurso(c)} — faltam{' '}
              <span className="text-ink font-bold">
                {acordo.i_still_owe[c].toLocaleString('pt-BR')}
              </span>{' '}
              líquidos, então embarcam{' '}
              <span className="text-ink font-bold">{bruto(c).toLocaleString('pt-BR')}</span>
            </span>
          </label>
        ))}
      </div>

      <div className="mt-3 flex flex-wrap items-center gap-2">
        <span
          className={`text-xs tabular-nums ${total > capacidade ? 'text-rust font-bold' : 'text-ink-soft'}`}
        >
          {total.toLocaleString('pt-BR')} / {capacidade.toLocaleString('pt-BR')}
        </span>

        <button
          disabled={!!impedimento}
          onClick={() => void agir(() => api.enviarAColonia(veiculo.id, destino, carga, acordo.id))}
          className="bg-rust text-sand-light hover:bg-rust-bright ml-auto px-4 py-2 text-sm font-bold disabled:cursor-not-allowed disabled:opacity-40"
        >
          Despachar {total.toLocaleString('pt-BR')}
        </button>
      </div>

      <p className="text-ink-soft mt-2 text-xs">
        Embarca mais do que falta porque o tributo da entrega come a diferença no caminho.
      </p>

      {impedimento && <p className="text-rust mt-2 text-xs">{impedimento}</p>}
    </div>
  )
}

function Propor({
  colonia,
  vizinhas,
  agir,
  aoPropor,
}: {
  colonia: Colonia
  vizinhas: ColoniaVizinha[]
  agir: (a: () => Promise<unknown>) => Promise<void>
  aoPropor: () => void
}) {
  const [aberta, setAberta] = useState(false)
  const [contraparte, setContraparte] = useState<number | null>(null)
  const [minimo, setMinimo] = useState<string | null>(null)
  const [prazo, setPrazo] = useState('')
  const [euPrometo, setEuPrometo] = useState<Record<string, number>>({})
  const [elePromete, setElePromete] = useState<Record<string, number>>({})

  const outra = aberta ? undefined : (vizinhas.find((c) => c.id === contraparte) ?? vizinhas[0])

  /*
   * O prazo mínimo é a viagem do veículo mais lento entre as duas colônias, mais 12 h (D-42), e só
   * o backend sabe calculá-lo. Buscá-lo a cada troca de contraparte, e preencher o campo com uma
   * hora de folga: preenchê-lo com o mínimo exato faria a proposta ser recusada por um segundo de
   * relógio entre carregar a tela e apertar o botão.
   *
   * Numa oferta **aberta** não há contraparte, logo não há distância: o backend só exige o piso
   * teórico (as 12 h de folga), e cobra o D-42 de verdade de quem aceitar. Aqui o campo nasce com
   * folga larga, porque um prazo apertado só poderia ser aceito por quem morasse ao lado.
   */
  useEffect(() => {
    if (aberta) {
      setMinimo(null)
      setPrazo(paraCampoLocal(new Date(Date.now() + PRAZO_SUGERIDO_ABERTO_MS).toISOString()))

      return
    }

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
  }, [outra, aberta])

  const valido =
    (aberta || !!outra) &&
    !!prazo &&
    Object.keys(euPrometo).length > 0 &&
    Object.keys(elePromete).length > 0

  if (vizinhas.length === 0 && !aberta) {
    return <p className="text-ink-soft mt-5 text-sm">Nenhuma outra colônia no servidor.</p>
  }

  return (
    <div className="mt-5 space-y-4">
      <div>
        <div className="text-rust eyebrow">Para quem</div>
        <div className="mt-1 flex gap-1">
          {[
            { valor: false, rotulo: 'Um colono específico' },
            { valor: true, rotulo: 'Aberta no mural' },
          ].map((o) => (
            <button
              key={String(o.valor)}
              onClick={() => setAberta(o.valor)}
              className={`flex-1 py-1.5 text-sm font-bold ${
                aberta === o.valor ? 'bg-rust text-sand-light' : 'border-rust/25 text-ink-soft border'
              }`}
            >
              {o.rotulo}
            </button>
          ))}
        </div>
        <p className="text-ink-soft mt-1 text-xs">
          {aberta
            ? 'A oferta vai ao mural, sem destinatário. O primeiro colono que aceitar vira a contraparte — e só consegue aceitar quem morar perto o bastante para cumprir o prazo.'
            : 'A proposta vai só para esta colônia, e só ela pode confirmar.'}
        </p>
      </div>

      {!aberta && (
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
      )}

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
        <Lado
          titulo="Você promete"
          itens={euPrometo}
          aoMudar={setEuPrometo}
          estoque={colonia.resources}
        />
        <Lado
          titulo={aberta ? 'Em troca de' : `${outra?.nickname ?? 'A contraparte'} promete`}
          itens={elePromete}
          aoMudar={setElePromete}
        />
      </div>

      <button
        disabled={!valido}
        onClick={() => {
          if (!aberta && !outra) return
          void agir(() =>
            api.proporAcordo({
              // Sem contraparte, a oferta vai ao mural (D-58).
              counterparty_id: aberta ? null : outra!.id,
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
        {aberta ? 'Anunciar no mural' : 'Propor acordo'}
      </button>

      <p className="text-ink-soft text-xs">
        Nada sai do seu estoque ao propor — a promessa é registro, não garantia. Só se cumpre
        entregando de verdade, por veículo, o que está na sua colônia.
      </p>
    </div>
  )
}

/**
 * Um lado da promessa: recursos e quantidades, somados livremente.
 *
 * Com `estoque`, mostra ao lado do recurso escolhido **quanto há na colônia** — é o estoque que
 * este canal negocia (D-58). Nada é conferido contra ele: o §26.5 deixa prometer o que ainda vai
 * ser minerado, e quem promete o que não tem, calotea. Mas ninguém deve caloteirar por *engano*.
 */
function Lado({
  titulo,
  itens,
  aoMudar,
  estoque,
}: {
  titulo: string
  itens: Record<string, number>
  aoMudar: (i: Record<string, number>) => void
  estoque?: Record<string, number>
}) {
  const [codigo, setCodigo] = useState(NEGOCIAVEIS[0])
  const [qtd, setQtd] = useState('')

  const quantidade = Number(qtd)
  const pode = Number.isInteger(quantidade) && quantidade > 0
  const naColonia = estoque?.[codigo] ?? 0

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

        {estoque && (
          <p className={`text-xs ${quantidade > naColonia ? 'text-rust font-bold' : 'text-ink-soft'}`}>
            Na sua colônia: {naColonia.toLocaleString('pt-BR')}
            {quantidade > naColonia && ' — você está prometendo mais do que tem.'}
          </p>
        )}

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
