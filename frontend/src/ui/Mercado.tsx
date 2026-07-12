import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from '../api/client'
import type {
  Colonia,
  ColoniaVizinha,
  ContaDoMercado,
  Frota,
  OfertaGlobal,
  SaldoDoRecurso,
  Veiculo,
  Vitrine,
} from '../api/client'
import { OfertarEntreColonos, VerOfertasDeColonos } from './Acordos'
import { fert, nomeRecurso, nomeVeiculo, paraMicro, relogio, segundosRestantes } from './recursos'

const INTERVALO_MS = 3000

/**
 * Os dois mercados, e a fronteira entre eles (D-65).
 *
 * **O que é entre colonos mora no Mercado Local**, a construção da sua colônia — o "comércio direto
 * com vizinhos" do §17.2. **O que é do governo mora no Mercado Central**, na Capital: as ofertas
 * com escrow e o depósito. Até aqui as duas coisas dividiam o mesmo salão, com duas portas para
 * ele, e o colono não tinha como saber qual canal estava usando.
 *
 * A regra que separa os dois canais continua a mesma (D-58): o que está **na colônia** se negocia
 * entre colonos — promessa, entrega física por veículo, calote possível (§26.5, D-40). O que está
 * **no depósito da Capital** se oferta no Mercado Central — escrow, e a execução move recurso de um
 * depósito ao outro sem veículo nenhum.
 *
 * Levar carga ao depósito aparece nas duas telas de propósito: a colônia nasce **sem** o Mercado
 * Local, e quem ainda não o ergueu ficaria com o depósito inalcançável.
 */
export type ContextoDoMercado = 'local' | 'central'

type Aba = 'doca' | 'ofertar_colono' | 'ver_colonos' | 'patio' | 'ofertar_central' | 'globais'

const ABAS: Record<ContextoDoMercado, { chave: Aba; rotulo: string }[]> = {
  local: [
    { chave: 'doca', rotulo: 'Enviar carga' },
    { chave: 'ofertar_colono', rotulo: 'Ofertar a colonos' },
    { chave: 'ver_colonos', rotulo: 'Ver ofertas de colonos' },
  ],
  central: [
    { chave: 'patio', rotulo: 'Pátio e depósito' },
    { chave: 'ofertar_central', rotulo: 'Ofertar no Mercado Central' },
    { chave: 'globais', rotulo: 'Ofertas globais' },
  ],
}

const TITULO: Record<ContextoDoMercado, { eyebrow: string; nome: string }> = {
  local: { eyebrow: 'Mercado Local', nome: 'A sua colônia' },
  central: { eyebrow: 'Mercado Central', nome: 'A Capital' },
}

export function Mercado({
  colonia,
  contexto,
  aoFechar,
}: {
  colonia: Colonia
  /** De qual dos dois mercados o colono entrou. Decide as abas, e nada mais é compartilhado. */
  contexto: ContextoDoMercado
  aoFechar: () => void
}) {
  const abas = ABAS[contexto]
  const [aba, setAba] = useState<Aba>(abas[0].chave)
  const [frota, setFrota] = useState<Frota | null>(null)
  const [conta, setConta] = useState<ContaDoMercado | null>(null)
  const [vitrine, setVitrine] = useState<Vitrine | null>(null)
  const [vizinhas, setVizinhas] = useState<ColoniaVizinha[]>([])
  const [erro, setErro] = useState<string | null>(null)

  const carregar = useCallback(async () => {
    try {
      // A vitrine vem inteira, sem filtro de recurso: é o que faz as ofertas dos outros aparecerem.
      const [f, c, v] = await Promise.all([api.frota(), api.conta(), api.vitrine()])
      setFrota(f)
      setConta(c)
      setVitrine(v)
    } catch (e) {
      if (e instanceof ApiError) setErro(e.message)
    }
  }, [])

  useEffect(() => {
    void carregar()
    const t = setInterval(() => void carregar(), INTERVALO_MS)
    return () => clearInterval(t)
  }, [carregar])

  /*
   * O diretório só muda quando alguém funda colônia — raro. Buscá-lo uma vez ao abrir a tela
   * basta, e mantém o polling de 3 s enxuto: ele já carrega frota, conta e livro.
   */
  useEffect(() => {
    api
      .colonias()
      .then((r) => setVizinhas(r.colonies))
      .catch((e: unknown) => {
        if (e instanceof ApiError) setErro(e.message)
      })
  }, [])

  // Faz as contagens regressivas andarem sem bater na API.
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

  // Os dois pátios (D-65): quem está ocioso **em casa** e quem está ocioso **na Capital**. Um
  // veículo no Pátio não pode sair de casa, e um veículo em casa não carrega do depósito.
  const emCasa = frota?.vehicles.filter((v) => v.status === 'ocioso' && v.local === 'colonia') ?? []
  const noPatio = frota?.vehicles.filter((v) => v.status === 'ocioso' && v.local === 'capital') ?? []

  return (
    <div className="bg-sand fixed inset-0 z-20 overflow-y-auto">
      <div className="bg-sand-light mx-auto min-h-screen w-full max-w-3xl p-6">
        <header className="flex items-start justify-between">
          <div>
            <div className="text-rust eyebrow">{TITULO[contexto].eyebrow}</div>
            <h2 className="text-ink text-2xl font-black">{TITULO[contexto].nome}</h2>
            {conta && (
              <p className="text-ink-soft mt-1 text-sm">
                {contexto === 'local'
                  ? `A Capital fica a ${conta.distance_slots} slots do seu slot.`
                  : `${conta.distance_slots} slots de distância do seu slot.`}
              </p>
            )}
          </div>
          <button onClick={aoFechar} className="text-ink-soft hover:text-rust text-2xl leading-none">
            ×
          </button>
        </header>

        <nav className="border-rust/20 mt-5 flex flex-wrap gap-1 border-b">
          {abas.map((a) => (
            <button
              key={a.chave}
              onClick={() => setAba(a.chave)}
              data-aba={a.chave}
              className={`eyebrow px-3 py-2 text-xs ${
                aba === a.chave ? 'bg-rust text-sand-light' : 'text-ink-soft hover:text-rust'
              }`}
            >
              {a.rotulo}
            </button>
          ))}
        </nav>

        {erro && <p className="text-rust mt-4 text-sm font-bold">{erro}</p>}

        {aba === 'doca' && (
          <Doca
            colonia={colonia}
            frota={frota}
            emCasa={emCasa}
            vizinhas={vizinhas}
            agir={agir}
          />
        )}

        {/* Entre colonos: sai do ESTOQUE da colônia, por veículo, e sem escrow (D-40). */}
        {aba === 'ofertar_colono' && <OfertarEntreColonos colonia={colonia} />}
        {aba === 'ver_colonos' && <VerOfertasDeColonos />}

        {aba === 'patio' && (
          <PatioEDeposito
            colonia={colonia}
            conta={conta}
            emCasa={emCasa}
            noPatio={noPatio}
            vizinhas={vizinhas}
            agir={agir}
          />
        )}

        {/* No Mercado Central: sai do DEPÓSITO da Capital, com escrow, e sem veículo. */}
        {aba === 'ofertar_central' && (
          <OfertarNoMercadoCentral vitrine={vitrine} conta={conta} agir={agir} />
        )}
        {aba === 'globais' && <OfertasGlobais vitrine={vitrine} conta={conta} agir={agir} />}
      </div>
    </div>
  )
}

/**
 * O que se pode carregar do estoque da colônia. Do mais abundante para o menos: sem ordenar, o
 * campo abriria num raro do kit inicial, do qual o colono tem punhados — a opção que ele quase
 * nunca quer despachar. Energia fica de fora: ela é o combustível da viagem (§21.1), não carga.
 */
function doEstoque(colonia: Colonia): Opcao[] {
  return Object.entries(colonia.resources)
    .filter(([c, q]) => q > 0 && c !== 'energia')
    .sort(([, a], [, b]) => b - a)
    .map(([c, q]) => ({ codigo: c, disponivel: q }))
}

function doDeposito(conta: ContaDoMercado | null): Opcao[] {
  return [...(conta?.balances ?? [])]
    .sort((a, b) => b.amount - a.amount)
    .map((b) => ({ codigo: b.resource_type, disponivel: b.amount }))
}

/**
 * O Mercado Local: daqui a carga **sai da colônia** — para o depósito da Capital, ou para o slot de
 * outro colono. É o §17.2 ("comércio direto com vizinhos") a ganhar a sua própria tela.
 */
function Doca({
  colonia,
  frota,
  emCasa,
  vizinhas,
  agir,
}: {
  colonia: Colonia
  frota: Frota | null
  emCasa: Veiculo[]
  vizinhas: ColoniaVizinha[]
  agir: (a: () => Promise<unknown>) => Promise<void>
}) {
  return (
    <div className="mt-5 grid gap-6 md:grid-cols-2">
      <section>
        <div className="text-rust eyebrow">Sua frota</div>
        {frota?.vehicles.length === 0 && (
          <p className="text-ink-soft mt-2 text-sm">Nenhum veículo.</p>
        )}
        <div className="mt-2 space-y-2">
          {frota?.vehicles.map((v) => (
            <LinhaVeiculo key={v.id} v={v} vizinhas={vizinhas} />
          ))}
        </div>
      </section>

      <section className="space-y-6">
        <FormularioDeCarga
          titulo="Levar ao seu depósito na Capital"
          ajuda="A carga sai do estoque agora, e o tributo incide quando ela chega. O veículo FICA estacionado no Pátio da Capital — é de lá que ele volta a sair."
          veiculos={emCasa}
          opcoes={doEstoque(colonia)}
          rotuloBotao="Despachar"
          aoEnviar={(veiculo, carga) => agir(() => api.depositar(veiculo, carga))}
        />

        {/*
         * Comércio informal (§25.7): os dois colonos combinam a troca por fora, e o veículo faz a
         * parte física. Não há escrow aqui — é o canal com risco de calote, por design.
         */}
        <FormularioDeCarga
          titulo="Enviar a outro colono"
          ajuda="A carga sai do estoque agora. O tributo incide quando ela chega ao slot dele, e o veículo volta para casa."
          veiculos={emCasa}
          opcoes={doEstoque(colonia)}
          destinos={vizinhas.map((c) => ({
            id: c.id,
            rotulo: `${c.nickname} · ${c.distance} slots`,
          }))}
          rotuloBotao="Enviar"
          aoEnviar={(veiculo, carga, destino) =>
            // `podeEnviar` já garante o destino quando há lista; isto é a rede, não a regra.
            destino === undefined
              ? Promise.resolve()
              : agir(() => api.enviarAColonia(veiculo, destino, carga))
          }
        />
      </section>
    </div>
  )
}

/**
 * O Pátio Logístico e o depósito, na Capital (D-65).
 *
 * O caminhão que leva carga ao depósito **fica estacionado aqui**, e é daqui que ele sai de novo —
 * carregado do depósito, para a sua colônia ou para a de outro colono. Enquanto está parado, paga
 * a hora do §2.1 (0,005 Fert$); sem Fert$, é rebocado para casa.
 *
 * Quem **não** tem veículo no Pátio continua podendo mandar um de casa buscar (§25.8) — é o
 * formulário de baixo, e ele paga a ida e a volta.
 */
function PatioEDeposito({
  colonia,
  conta,
  emCasa,
  noPatio,
  vizinhas,
  agir,
}: {
  colonia: Colonia
  conta: ContaDoMercado | null
  emCasa: Veiculo[]
  noPatio: Veiculo[]
  vizinhas: ColoniaVizinha[]
  agir: (a: () => Promise<unknown>) => Promise<void>
}) {
  const naDoca = conta?.balances ?? []

  // Do Pátio dá para ir para casa, ou para a colônia de qualquer outro colono. Casa vem primeiro:
  // é o que o colono quase sempre quer, e é a razão de o caminhão estar ali.
  const destinosDoPatio = [
    { id: colonia.id, rotulo: `A sua colônia (${colonia.name})` },
    ...vizinhas.map((c) => ({ id: c.id, rotulo: `${c.nickname} · a ${c.distance} slots de você` })),
  ]

  return (
    <div className="mt-5 grid gap-6 md:grid-cols-2">
      <section>
        <div className="text-rust eyebrow">No seu depósito na Capital</div>
        {naDoca.length === 0 ? (
          <p className="text-ink-soft mt-2 text-sm">
            Nada seu no Mercado. Leve carga até lá para poder vendê-la.
          </p>
        ) : (
          <div className="mt-2">
            {naDoca.map((b) => (
              <div
                key={b.resource_type}
                className="border-rust/10 flex justify-between border-b py-1.5 last:border-0"
              >
                <span className="text-ink-soft text-sm">{nomeRecurso(b.resource_type)}</span>
                <span className="text-ink font-bold tabular-nums">
                  {b.amount.toLocaleString('pt-BR')}
                </span>
              </div>
            ))}
          </div>
        )}

        <div className="text-rust eyebrow mt-6">No Pátio ({noPatio.length})</div>
        <p className="text-ink-soft/80 mt-1 text-xs">
          Veículo parado no Pátio paga 0,005 Fert$ por hora (§2.1). Sem Fert$, ele é rebocado para
          casa.
        </p>
        {noPatio.length === 0 ? (
          <p className="text-ink-soft mt-2 text-sm">
            Nenhum veículo seu na Capital. Todo veículo que leva carga ao depósito fica estacionado
            aqui.
          </p>
        ) : (
          <div className="mt-2 space-y-2">
            {noPatio.map((v) => (
              <LinhaVeiculo key={v.id} v={v} vizinhas={vizinhas} />
            ))}
          </div>
        )}

        <div className="text-rust eyebrow mt-6">Em casa ({emCasa.length})</div>
        <div className="mt-2 space-y-2">
          {emCasa.length === 0 ? (
            <p className="text-ink-soft text-sm">Nenhum veículo ocioso na colônia.</p>
          ) : (
            emCasa.map((v) => <LinhaVeiculo key={v.id} v={v} vizinhas={vizinhas} />)
          )}
        </div>
      </section>

      <section className="space-y-6">
        <FormularioDeCarga
          titulo="Despachar do Pátio"
          ajuda="A carga sai do seu depósito, aqui na Capital. Para a sua colônia, é viagem só de ida — o veículo chega e fica em casa. Para outro colono, ele entrega e segue para casa."
          veiculos={noPatio}
          semVeiculo="Nenhum veículo seu no Pátio. Leve carga ao depósito e o veículo fica aqui."
          opcoes={doDeposito(conta)}
          destinos={destinosDoPatio}
          rotuloBotao="Despachar"
          aoEnviar={(veiculo, carga, destino) =>
            destino === undefined
              ? Promise.resolve()
              : agir(() => api.enviarAColonia(veiculo, destino, carga))
          }
        />

        <FormularioDeCarga
          titulo="Buscar no seu depósito"
          ajuda="Manda um veículo de casa buscar: ele vai vazio e volta carregado, e você paga as duas pernas. Quem já tem veículo no Pátio não precisa disto."
          veiculos={emCasa}
          opcoes={doDeposito(conta)}
          rotuloBotao="Buscar"
          aoEnviar={(veiculo, carga) => agir(() => api.retirar(veiculo, carga))}
        />

        <FormularioDeCarga
          titulo="Levar ao seu depósito"
          ajuda="Do estoque da colônia para cá. O veículo fica estacionado no Pátio."
          veiculos={emCasa}
          opcoes={doEstoque(colonia)}
          rotuloBotao="Despachar"
          aoEnviar={(veiculo, carga) => agir(() => api.depositar(veiculo, carga))}
        />
      </section>
    </div>
  )
}

function LinhaVeiculo({ v, vizinhas }: { v: Veiculo; vizinhas: ColoniaVizinha[] }) {
  const restam = segundosRestantes(v.arrives_at)

  /*
   * Antes do diretório, um veículo em rota só sabia dizer "a colônia #7" — o `id` cru, que não
   * significa nada para quem joga. Agora ele nomeia o colono. O `#id` fica de reserva para o
   * intervalo entre abrir a tela e o diretório chegar, e para um destino que suma da lista.
   */
  const alvo = vizinhas.find((c) => c.id === v.destination_id)

  const destino =
    v.destination_type === 'mercado_central'
      ? 'a Capital'
      : alvo
        ? `a colônia de ${alvo.nickname}`
        : `a colônia #${v.destination_id}`

  return (
    <div className="border-rust/15 border p-2" data-veiculo={v.id}>
      <div className="flex items-center justify-between">
        <span className="text-ink text-sm font-bold">{nomeVeiculo(v.type)}</span>
        {v.status === 'ocioso' ? (
          <span className="text-ink-soft text-xs">
            {v.local === 'capital' ? 'no Pátio da Capital' : 'ocioso'}
          </span>
        ) : (
          <span className="text-rust font-bold tabular-nums">{relogio(restam)}</span>
        )}
      </div>

      {v.status === 'em_rota' && (
        <div className="text-ink-soft mt-0.5 text-xs">
          {v.trip_purpose === 'retirada'
            ? v.leg === 'ida'
              ? `indo vazio buscar carga em ${destino}`
              : 'voltando carregado ao seu slot'
            : v.trip_purpose === 'reboque'
              ? 'rebocado da Capital: sem Fert$ para a hora do Pátio'
              : v.leg === 'ida'
                ? `levando carga para ${destino}`
                : 'voltando para casa'}
          {v.cargo && (
            <span>
              {' · '}
              {Object.entries(v.cargo)
                .map(([c, q]) => `${q.toLocaleString('pt-BR')} de ${nomeRecurso(c)}`)
                .join(', ')}
            </span>
          )}
        </div>
      )}

      <div className="text-ink-soft mt-0.5 text-xs">
        capacidade {v.capacity_efetiva.toLocaleString('pt-BR')}
        {v.capacity_efetiva < v.capacity && (
          <span className="text-ink-soft/70"> (de {v.capacity.toLocaleString('pt-BR')}, desgaste)</span>
        )}
      </div>
    </div>
  )
}

type Opcao = { codigo: string; disponivel: number }

/** Uma linha da carroceria: um recurso e quanto dele vai. */
type Linha = { codigo: string; qtd: string }

/**
 * O formulário de carga (D-65): **vários recursos na mesma carroceria**, até lotar.
 *
 * O §25.4 sempre mediu a capacidade em unidades **somadas** — "1.000 unidades de qualquer recurso =
 * 1 m³" —, e o servidor sempre soube disso: `array_sum(carga)` contra a capacidade efetiva. Quem
 * insistia em um recurso por viagem era esta tela, que mandava `{ [código]: qtd }` de um `<select>`
 * só. Agora ela monta linhas, soma-as e as confere contra a capacidade **efetiva** do veículo — a
 * encolhida pelo desgaste (§16.4), que é a que o servidor vai cobrar.
 */
function FormularioDeCarga({
  titulo,
  ajuda,
  veiculos,
  semVeiculo = 'Nenhum veículo ocioso.',
  opcoes,
  destinos,
  rotuloBotao,
  aoEnviar,
}: {
  titulo: string
  ajuda: string
  veiculos: Veiculo[]
  /** O que dizer quando não há veículo aqui. O Pátio precisa de uma frase própria. */
  semVeiculo?: string
  opcoes: Opcao[]
  /**
   * Quando ausente, o destino é implícito (a doca da Capital) e nenhum seletor aparece. Quando
   * presente, o colono escolhe para onde a carga vai — e uma lista vazia é impedimento, não um
   * `<select>` vazio que deixa apertar o botão.
   */
  destinos?: { id: number; rotulo: string }[]
  rotuloBotao: string
  aoEnviar: (veiculo: number, carga: Record<string, number>, destino?: number) => Promise<void>
}) {
  const [linhas, setLinhas] = useState<Linha[]>([{ codigo: '', qtd: '' }])
  const [veiculoId, setVeiculoId] = useState<number | null>(null)
  const [destinoId, setDestinoId] = useState<number | null>(null)

  const veiculo = veiculos.find((v) => v.id === veiculoId) ?? veiculos[0]
  const destino = destinos?.find((d) => d.id === destinoId) ?? destinos?.[0]
  const capacidade = veiculo?.capacity_efetiva ?? 0

  const trocar = (i: number, campo: keyof Linha, valor: string) =>
    setLinhas((ls) => ls.map((l, j) => (j === i ? { ...l, [campo]: valor } : l)))

  // A carga que as linhas descrevem. Linha sem recurso ou sem quantidade é rascunho: fica de fora
  // em vez de virar erro — o colono está no meio de preencher.
  const carga: Record<string, number> = {}
  for (const l of linhas) {
    const q = Number(l.qtd)
    if (!l.codigo || !Number.isInteger(q) || q <= 0) continue
    carga[l.codigo] = (carga[l.codigo] ?? 0) + q
  }

  const total = Object.values(carga).reduce((s, q) => s + q, 0)

  const excedidos = Object.entries(carga).filter(
    ([codigo, q]) => q > (opcoes.find((o) => o.codigo === codigo)?.disponivel ?? 0),
  )

  const impedimento = !veiculo
    ? semVeiculo
    : destinos && !destino
      ? 'Nenhuma outra colônia no servidor.'
      : opcoes.length === 0
        ? 'Nada para carregar.'
        : excedidos.length > 0
          ? `Você não tem tudo isso de ${nomeRecurso(excedidos[0][0])}.`
          : total > capacidade
            ? `Passou da capacidade: ${total.toLocaleString('pt-BR')} de ${capacidade.toLocaleString('pt-BR')}.`
            : null

  const podeEnviar = !!veiculo && (!destinos || !!destino) && total > 0 && !impedimento

  /*
   * Sem veículo aqui, não há carga a montar: o formulário diz por que e se cala. Aberto, ele
   * mostrava uma carroceria de "0 / 0" e um seletor de destino que não levava ninguém a lugar
   * nenhum — o que a primeira captura de tela do Pátio deixou constrangedoramente claro.
   */
  if (!veiculo) {
    return (
      <div className="border-rust/20 border p-3" data-carga={titulo}>
        <div className="text-rust eyebrow">{titulo}</div>
        <p className="text-ink-soft mt-1 text-xs">{ajuda}</p>
        <p className="text-rust mt-2 text-xs">{semVeiculo}</p>
      </div>
    )
  }

  return (
    <div className="border-rust/20 border p-3" data-carga={titulo}>
      <div className="text-rust eyebrow">{titulo}</div>
      <p className="text-ink-soft mt-1 text-xs">{ajuda}</p>

      <div className="mt-3 space-y-2">
        {veiculos.length > 1 && (
          <select
            aria-label="Veículo"
            className="border-rust/25 bg-sand focus:border-rust w-full border px-2 py-1.5 text-sm outline-none"
            value={veiculo?.id ?? ''}
            onChange={(e) => setVeiculoId(Number(e.target.value))}
          >
            {veiculos.map((v) => (
              <option key={v.id} value={v.id}>
                {nomeVeiculo(v.type)} · leva {v.capacity_efetiva.toLocaleString('pt-BR')}
              </option>
            ))}
          </select>
        )}

        {destinos && (
          <select
            aria-label="Destino"
            className="border-rust/25 bg-sand focus:border-rust w-full border px-2 py-1.5 text-sm outline-none"
            value={destino?.id ?? ''}
            onChange={(e) => setDestinoId(Number(e.target.value))}
            disabled={destinos.length === 0}
          >
            {destinos.length === 0 && <option>—</option>}
            {destinos.map((d) => (
              <option key={d.id} value={d.id}>
                {d.rotulo}
              </option>
            ))}
          </select>
        )}

        {linhas.map((l, i) => (
          <div key={i} className="flex gap-1">
            <select
              aria-label="Recurso"
              className="border-rust/25 bg-sand focus:border-rust min-w-0 flex-1 border px-2 py-1.5 text-sm outline-none"
              value={l.codigo}
              onChange={(e) => trocar(i, 'codigo', e.target.value)}
              disabled={opcoes.length === 0}
            >
              <option value="">Recurso…</option>
              {opcoes.map((o) => (
                <option key={o.codigo} value={o.codigo}>
                  {nomeRecurso(o.codigo)} ({o.disponivel.toLocaleString('pt-BR')})
                </option>
              ))}
            </select>

            <input
              aria-label="Quantidade"
              className="border-rust/25 bg-sand focus:border-rust w-24 border px-2 py-1.5 text-sm outline-none"
              placeholder="Qtd"
              inputMode="numeric"
              value={l.qtd}
              onChange={(e) => trocar(i, 'qtd', e.target.value.replace(/\D/g, ''))}
            />

            {linhas.length > 1 && (
              <button
                aria-label="Tirar da carga"
                onClick={() => setLinhas((ls) => ls.filter((_, j) => j !== i))}
                className="text-ink-soft hover:text-rust w-7 shrink-0 text-lg leading-none"
              >
                ×
              </button>
            )}
          </div>
        ))}

        <div className="flex items-center justify-between">
          <button
            onClick={() => setLinhas((ls) => [...ls, { codigo: '', qtd: '' }])}
            disabled={opcoes.length === 0 || linhas.length >= opcoes.length}
            data-adicionar-recurso
            className="text-rust hover:text-rust-bright text-xs font-bold disabled:opacity-40"
          >
            + outro recurso
          </button>

          {/* A carroceria, cheia até onde está. É a capacidade EFETIVA — a que o servidor cobra. */}
          <span
            className={`text-xs tabular-nums ${total > capacidade ? 'text-rust font-bold' : 'text-ink-soft'}`}
          >
            {total.toLocaleString('pt-BR')} / {capacidade.toLocaleString('pt-BR')}
          </span>
        </div>

        {impedimento && <p className="text-rust text-xs">{impedimento}</p>}

        <button
          disabled={!podeEnviar}
          onClick={() => {
            void aoEnviar(veiculo.id, carga, destino?.id).then(() =>
              setLinhas([{ codigo: '', qtd: '' }]),
            )
          }}
          className="bg-rust text-sand-light hover:bg-rust-bright w-full py-2 text-sm font-bold disabled:cursor-not-allowed disabled:opacity-40"
        >
          {rotuloBotao}
        </button>
      </div>
    </div>
  )
}


/**
 * O par de números que o colono pediu para ver ao lado do recurso: o que ele tem **na colônia** e o
 * que tem **no depósito da Capital**. Sem isto a regra dos dois estoques é invisível, e o colono
 * descobre que não pode vender só quando o botão recusa.
 */
function DoisEstoques({ saldo }: { saldo: SaldoDoRecurso | undefined }) {
  if (!saldo) return null

  const cheio = saldo.livre === 0

  return (
    <div className="text-ink-soft mt-2 grid grid-cols-2 gap-2 text-xs">
      <div className="border-rust/15 border p-2">
        <div className="eyebrow">Na colônia</div>
        <div className="text-ink text-base font-bold tabular-nums">
          {saldo.na_colonia.toLocaleString('pt-BR')}
        </div>
        <div>negociável entre colonos</div>
      </div>
      <div className="border-rust/15 border p-2">
        <div className="eyebrow">No depósito da Capital</div>
        <div className="text-ink text-base font-bold tabular-nums">
          {saldo.no_deposito.toLocaleString('pt-BR')}
        </div>
        <div>
          ofertável no Mercado
          {saldo.em_ofertas > 0 && ` · ${saldo.em_ofertas.toLocaleString('pt-BR')} preso em ofertas`}
        </div>
        <div className={cheio ? 'text-rust font-bold' : ''}>
          teto {saldo.teto.toLocaleString('pt-BR')} · cabem mais{' '}
          {saldo.livre.toLocaleString('pt-BR')}
        </div>
      </div>
    </div>
  )
}

/** Anunciar uma oferta global. Só se oferta o que já está no depósito da Capital (D-58, regra 3). */
function OfertarNoMercadoCentral({
  vitrine,
  conta,
  agir,
}: {
  vitrine: Vitrine | null
  conta: ContaDoMercado | null
  agir: (a: () => Promise<unknown>) => Promise<void>
}) {
  const [lado, setLado] = useState<'buy' | 'sell'>('sell')
  const [recurso, setRecurso] = useState('metal_bruto')
  const [qtd, setQtd] = useState('')
  const [preco, setPreco] = useState('')

  const saldo = conta?.deposito.find((d) => d.resource_type === recurso)
  const tipo = vitrine?.catalogo.find((c) => c.code === recurso)
  const quantidade = Number(qtd)
  const precoMicro = paraMicro(preco)

  const minhas = (vitrine?.ofertas ?? []).filter((o) => o.minha)

  /*
   * O impedimento é dito ANTES do clique, e com o número: vender exige o lote já no depósito, e
   * comprar exige espaço para recebê-lo. Descobrir isso pelo erro do servidor é descobrir tarde.
   */
  const impedimento =
    !Number.isInteger(quantidade) || quantidade <= 0 || precoMicro <= 0
      ? null
      : lado === 'sell' && saldo && quantidade > saldo.no_deposito
        ? `Seu depósito na Capital tem ${saldo.no_deposito.toLocaleString('pt-BR')}. Leve carga até lá primeiro.`
        : lado === 'buy' && saldo && quantidade > saldo.livre
          ? `Seu depósito comporta mais ${saldo.livre.toLocaleString('pt-BR')}: a compra reserva o espaço que vai receber.`
          : null

  const valido =
    Number.isInteger(quantidade) && quantidade > 0 && precoMicro > 0 && !impedimento

  return (
    <div className="mt-5">
      <p className="text-ink-soft text-sm">
        Aqui só se oferta o que <strong className="text-ink">já está no depósito da Capital</strong>.
        A oferta fica parada até alguém executá-la — e a execução não usa veículo: o recurso passa do
        depósito de um ao do outro.
      </p>

      <div className="border-rust/20 mt-4 border p-3">
        <div className="mt-0 flex gap-1">
          {(['sell', 'buy'] as const).map((l) => (
            <button
              key={l}
              onClick={() => setLado(l)}
              className={`flex-1 py-1.5 text-sm font-bold ${
                lado === l ? 'bg-rust text-sand-light' : 'border-rust/25 text-ink-soft border'
              }`}
            >
              {l === 'sell' ? 'Vender' : 'Comprar'}
            </button>
          ))}
        </div>

        <select
          aria-label="Recurso"
          className="border-rust/25 bg-sand focus:border-rust mt-3 w-full border px-2 py-1.5 text-sm outline-none"
          value={recurso}
          onChange={(e) => setRecurso(e.target.value)}
        >
          {/* Os 26 do catálogo, não os 8 que a tela antiga escolhia a dedo. */}
          {(vitrine?.catalogo ?? []).map((c) => (
            <option key={c.code} value={c.code}>
              {c.nome}
            </option>
          ))}
        </select>

        <DoisEstoques saldo={saldo} />

        <div className="mt-3 grid gap-2 md:grid-cols-2">
          <input
            className="border-rust/25 bg-sand focus:border-rust border px-2 py-1.5 text-sm outline-none"
            placeholder="Quantidade"
            inputMode="numeric"
            value={qtd}
            onChange={(e) => setQtd(e.target.value.replace(/\D/g, ''))}
          />
          <input
            className="border-rust/25 bg-sand focus:border-rust border px-2 py-1.5 text-sm outline-none"
            placeholder="Preço por unidade, em Fert$"
            inputMode="decimal"
            value={preco}
            onChange={(e) => setPreco(e.target.value)}
          />
        </div>

        {tipo && (
          <p className="text-ink-soft mt-2 text-xs">
            Referência{' '}
            <span className="text-ink font-bold tabular-nums">
              {fert(tipo.preco_base_micro)} Fert$
            </span>{' '}
            · taxa de {(tipo.taxa_bps / 100).toFixed(0)}%, paga por quem vende
          </p>
        )}

        {impedimento && <p className="text-rust mt-2 text-xs font-bold">{impedimento}</p>}

        {valido && (
          <p className="text-ink-soft mt-2 text-xs">
            Total:{' '}
            <span className="text-ink font-bold">{fert(quantidade * precoMicro, 2)} Fert$</span>
          </p>
        )}

        <button
          disabled={!valido}
          onClick={() => {
            void agir(() =>
              api.ordenar({
                side: lado,
                resource_type: recurso,
                qty: quantidade,
                price_micro: precoMicro,
              }),
            ).then(() => {
              setQtd('')
              setPreco('')
            })
          }}
          className="bg-rust text-sand-light hover:bg-rust-bright mt-3 w-full py-2 text-sm font-bold disabled:cursor-not-allowed disabled:opacity-40"
        >
          {lado === 'sell' ? 'Anunciar venda' : 'Anunciar compra'}
        </button>
      </div>

      <div className="text-rust eyebrow mt-6">Suas ofertas na vitrine</div>
      {minhas.length === 0 ? (
        <p className="text-ink-soft mt-2 text-sm">Nenhuma oferta sua.</p>
      ) : (
        <div className="mt-2 space-y-2">
          {minhas.map((o) => (
            <div key={o.id} className="border-rust/15 flex items-center justify-between border p-2">
              <div className="text-sm">
                <span className="text-ink font-bold">{o.side === 'buy' ? 'Compra' : 'Venda'}</span>
                <span className="text-ink-soft">
                  {' '}
                  de {o.qty.toLocaleString('pt-BR')} de {nomeRecurso(o.resource_type)} a{' '}
                  {fert(o.price_micro)} Fert$
                </span>
              </div>
              <button
                onClick={() => void agir(() => api.cancelar(o.id))}
                className="text-ink-soft hover:text-rust text-xs font-bold"
              >
                Cancelar
              </button>
            </div>
          ))}
        </div>
      )}

    </div>
  )
}

/**
 * A vitrine. Toda oferta aberta, de todos os recursos, com o nome de quem a anunciou.
 *
 * É a tela que resolve a queixa que originou o D-58: o livro antigo casava as ordens no ato, então
 * uma oferta que cruzava era executada antes de qualquer um a ver, e o que sobrava aparecia sem
 * dono, misturado às próprias.
 */
function OfertasGlobais({
  vitrine,
  conta,
  agir,
}: {
  vitrine: Vitrine | null
  conta: ContaDoMercado | null
  agir: (a: () => Promise<unknown>) => Promise<void>
}) {
  const [filtro, setFiltro] = useState('')

  const ofertas = (vitrine?.ofertas ?? []).filter((o) => !filtro || o.resource_type === filtro)

  return (
    <div className="mt-5">
      <div className="flex items-center justify-between gap-4">
        <select
          aria-label="Filtrar por recurso"
          className="border-rust/25 bg-sand focus:border-rust border px-2 py-1.5 text-sm outline-none"
          value={filtro}
          onChange={(e) => setFiltro(e.target.value)}
        >
          <option value="">Todos os recursos</option>
          {(vitrine?.catalogo ?? []).map((c) => (
            <option key={c.code} value={c.code}>
              {c.nome}
            </option>
          ))}
        </select>
        <p className="text-ink-soft text-xs">
          A oferta fica aqui até alguém executá-la. Nada é enviado: o recurso troca de depósito.
        </p>
      </div>

      {ofertas.length === 0 ? (
        <p className="text-ink-soft mt-4 text-sm">
          Nenhuma oferta na vitrine{filtro ? ' neste recurso' : ''}. Anuncie a sua na aba ao lado.
        </p>
      ) : (
        <div className="mt-4 space-y-2">
          {ofertas.map((o) => (
            <LinhaDaVitrine
              key={o.id}
              oferta={o}
              saldo={conta?.deposito.find((d) => d.resource_type === o.resource_type)}
              agir={agir}
            />
          ))}
        </div>
      )}
    </div>
  )
}

function LinhaDaVitrine({
  oferta,
  saldo,
  agir,
}: {
  oferta: OfertaGlobal
  saldo: SaldoDoRecurso | undefined
  agir: (a: () => Promise<unknown>) => Promise<void>
}) {
  const [qtd, setQtd] = useState('')
  const quantidade = Number(qtd) || oferta.qty

  /*
   * Executar uma VENDA alheia é comprar: preciso de espaço no depósito para receber.
   * Executar uma COMPRA alheia é vender: preciso do lote já no depósito.
   */
  const impedimento =
    oferta.minha || !saldo
      ? null
      : oferta.side === 'sell'
        ? quantidade > saldo.livre
          ? `Seu depósito comporta mais ${saldo.livre.toLocaleString('pt-BR')}.`
          : null
        : quantidade > saldo.no_deposito
          ? `Seu depósito na Capital tem ${saldo.no_deposito.toLocaleString('pt-BR')}.`
          : null

  const vende = oferta.side === 'sell'

  return (
    <div className="border-rust/15 border p-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="text-sm">
          <span className={`font-bold ${vende ? 'text-ink' : 'text-rust'}`}>
            {vende ? 'VENDE' : 'COMPRA'}
          </span>{' '}
          <span className="text-ink font-bold">{nomeRecurso(oferta.resource_type)}</span>
          <span className="text-ink-soft">
            {' · '}
            {oferta.qty.toLocaleString('pt-BR')} un a{' '}
            <span className="text-ink font-bold tabular-nums">{fert(oferta.price_micro)}</span> Fert$
          </span>
          <div className="text-ink-soft text-xs">
            {oferta.minha ? 'sua oferta' : (oferta.colonia ?? `colônia #${oferta.colony_id}`)}
            {' · total '}
            {fert(oferta.qty * oferta.price_micro, 2)} Fert$
          </div>
        </div>

        {oferta.minha ? (
          <button
            onClick={() => void agir(() => api.cancelar(oferta.id))}
            className="text-ink-soft hover:text-rust text-xs font-bold"
          >
            Cancelar
          </button>
        ) : (
          <div className="flex items-center gap-2">
            <input
              aria-label="Quantidade a executar"
              className="border-rust/25 bg-sand focus:border-rust w-24 border px-2 py-1.5 text-sm outline-none"
              placeholder={String(oferta.qty)}
              inputMode="numeric"
              value={qtd}
              onChange={(e) => setQtd(e.target.value.replace(/\D/g, ''))}
            />
            <button
              disabled={!!impedimento || quantidade <= 0 || quantidade > oferta.qty}
              onClick={() => {
                void agir(() => api.executarOferta(oferta.id, quantidade)).then(() => setQtd(''))
              }}
              className="bg-rust text-sand-light hover:bg-rust-bright px-4 py-1.5 text-sm font-bold disabled:cursor-not-allowed disabled:opacity-40"
            >
              {vende ? 'Comprar' : 'Vender'}
            </button>
          </div>
        )}
      </div>

      {impedimento && <p className="text-rust mt-1 text-xs font-bold">{impedimento}</p>}
    </div>
  )
}
