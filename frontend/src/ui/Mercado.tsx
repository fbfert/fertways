import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from '../api/client'
import type {
  Colonia,
  ColoniaVizinha,
  ContaDoMercado,
  Frota,
  ItemVendavelEmLeilao,
  Leilao,
  MinhaZona,
  OfertaGlobal,
  SaldoDoRecurso,
  Veiculo,
  Vitrine,
} from '../api/client'
import { IconeCompra, IconeRecurso, IconeVende } from './IconeRecurso'
import { Popup } from './Popup'
import { OfertarEntreColonos, VerOfertasDeColonos } from './Acordos'
import {
  fert,
  nomeRecurso,
  nomeVeiculo,
  paraMicro,
  prazoHumano,
  relogio,
  segundosRestantes,
} from './recursos'

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

type Aba =
  | 'doca'
  | 'ofertar_colono'
  | 'ver_colonos'
  | 'patio'
  | 'ofertar_central'
  | 'globais'
  | 'leiloes'

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
    { chave: 'leiloes', rotulo: 'Leilões' },
  ],
}

const TITULO: Record<ContextoDoMercado, { eyebrow: string; nome: string }> = {
  local: { eyebrow: 'Mercado Local', nome: 'A sua colônia' },
  central: { eyebrow: 'Mercado Central', nome: 'A Capital' },
}

export function Mercado({
  colonia,
  contexto,
}: {
  colonia: Colonia
  /** De qual dos dois mercados o colono entrou. Decide as abas, e nada mais é compartilhado. */
  contexto: ContextoDoMercado
}) {
  const abas = ABAS[contexto]
  const [aba, setAba] = useState<Aba>(abas[0].chave)
  const [frota, setFrota] = useState<Frota | null>(null)
  const [conta, setConta] = useState<ContaDoMercado | null>(null)
  const [vitrine, setVitrine] = useState<Vitrine | null>(null)
  const [leiloes, setLeiloes] = useState<{ abertos: Leilao[]; minhas: Leilao[] } | null>(null)
  /** Quantas ofertas de OUTROS colonos há na vitrine — ver a `<nav>` das abas. */
  const ofertasDeOutros = (vitrine?.ofertas ?? []).filter((o) => !o.minha).length
  const [itensVendaveis, setItensVendaveis] = useState<ItemVendavelEmLeilao[]>([])
  const [vizinhas, setVizinhas] = useState<ColoniaVizinha[]>([])
  const [zonas, setZonas] = useState<MinhaZona[]>([])
  const [erro, setErro] = useState<string | null>(null)

  const carregar = useCallback(async () => {
    try {
      // A vitrine vem inteira, sem filtro de recurso: é o que faz as ofertas dos outros aparecerem.
      const [f, c, v, l, iv] = await Promise.all([
        api.frota(),
        api.conta(),
        api.vitrine(),
        api.leiloes(),
        api.meusItensVendaveisEmLeilao(),
      ])
      setFrota(f)
      setConta(c)
      setVitrine(v)
      setLeiloes(l)
      setItensVendaveis(iv.itens)
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

  /**
   * As minhas zonas neutras — destino do despacho vazio (D-109), ao lado de casa/Capital. Mesma
   * lógica do diretório: muda raro, uma busca ao abrir a tela basta.
   */
  useEffect(() => {
    api
      .minhasZonas()
      .then((r) => setZonas(r.zones))
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

  // Os três lugares onde um veículo ocioso pode estar (D-65, e a zona neutra desde o D-109): em
  // casa, no Pátio da Capital, ou estacionado numa zona sua. Um veículo no Pátio não pode sair de
  // casa, e um veículo em casa não carrega do depósito.
  const emCasa = frota?.vehicles.filter((v) => v.status === 'ocioso' && v.local === 'colonia') ?? []
  const noPatio = frota?.vehicles.filter((v) => v.status === 'ocioso' && v.local === 'capital') ?? []
  const naZona = frota?.vehicles.filter((v) => v.status === 'ocioso' && v.local === 'zona') ?? []

  return (
    <div className="bg-sand fixed inset-0 z-20 overflow-y-auto">
      <div className="bg-sand-light mx-auto min-h-screen w-full max-w-3xl px-6 pt-20 pb-24 md:pt-28 md:pb-6">
        <header>
          <div className="text-rust eyebrow">{TITULO[contexto].eyebrow}</div>
          <h2 className="text-ink text-2xl font-black">{TITULO[contexto].nome}</h2>
          {conta && (
            <p className="text-ink-soft mt-1 text-sm">
              {contexto === 'local'
                ? `A Capital fica a ${conta.distance_slots} slots do seu slot.`
                : `${conta.distance_slots} slots de distância do seu slot.`}
            </p>
          )}
        </header>

        {/*
          ⚠️ A contagem na aba (A2.V5, D-228) — e ela existe por uma medida, não por enfeite.
          As abas eram rótulos secos: **nada dizia que havia ofertas esperando**. Medido em produção,
          o livro tem ~100 ordens abertas e os jogadores humanos **nunca executaram uma sequer** em
          28 dias. O Mercado era um lugar que só encontra quem já ia lá.

          Conta só as **dos outros**: a própria oferta não é convite para nada, e somá-la faria a
          aba prometer o que não entrega. Zero não vira "(0)" — um zero pendurado o tempo todo vira
          moldura, e é a mesma regra do D-211.
        */}
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
              {a.chave === 'globais' && ofertasDeOutros > 0 && (
                <span className="tabular-nums" data-conta-ofertas>
                  {' '}
                  ({ofertasDeOutros})
                </span>
              )}
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
            naZona={naZona}
            vizinhas={vizinhas}
            zonas={zonas}
            agir={agir}
          />
        )}

        {/* No Mercado Central: sai do DEPÓSITO da Capital, com escrow, e sem veículo. */}
        {aba === 'ofertar_central' && (
          <OfertarNoMercadoCentral vitrine={vitrine} conta={conta} agir={agir} />
        )}
        {aba === 'globais' && <OfertasGlobais vitrine={vitrine} conta={conta} agir={agir} />}
        {aba === 'leiloes' && (
          <Leiloes leiloes={leiloes} vitrine={vitrine} conta={conta} itensVendaveis={itensVendaveis} agir={agir} />
        )}
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
  naZona,
  vizinhas,
  zonas,
  agir,
}: {
  colonia: Colonia
  conta: ContaDoMercado | null
  emCasa: Veiculo[]
  noPatio: Veiculo[]
  naZona: Veiculo[]
  vizinhas: ColoniaVizinha[]
  zonas: MinhaZona[]
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
                <span className="text-ink-soft flex items-center gap-1.5 text-sm">
                  <IconeRecurso codigo={b.resource_type} />
                  {nomeRecurso(b.resource_type)}
                </span>
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
              <LinhaVeiculo key={v.id} v={v} vizinhas={vizinhas} colonia={colonia} zonas={zonas} agir={agir} />
            ))}
          </div>
        )}

        <div className="text-rust eyebrow mt-6">Em casa ({emCasa.length})</div>
        <div className="mt-2 space-y-2">
          {emCasa.length === 0 ? (
            <p className="text-ink-soft text-sm">Nenhum veículo ocioso na colônia.</p>
          ) : (
            emCasa.map((v) => (
              <LinhaVeiculo key={v.id} v={v} vizinhas={vizinhas} colonia={colonia} zonas={zonas} agir={agir} />
            ))
          )}
        </div>

        {naZona.length > 0 && (
          <>
            <div className="text-rust eyebrow mt-6">Numa zona neutra ({naZona.length})</div>
            <p className="text-ink-soft/80 mt-1 text-xs">
              Estacionado lá desde que você o reposicionou (D-109). Hoje só pode voltar para casa.
            </p>
            <div className="mt-2 space-y-2">
              {naZona.map((v) => (
                <LinhaVeiculo key={v.id} v={v} vizinhas={vizinhas} colonia={colonia} zonas={zonas} agir={agir} />
              ))}
            </div>
          </>
        )}
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

        {conta && <FreteDoGoverno conta={conta} agir={agir} />}

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

function LinhaVeiculo({
  v,
  vizinhas,
  colonia,
  zonas,
  agir,
}: {
  v: Veiculo
  vizinhas: ColoniaVizinha[]
  colonia?: Colonia
  zonas?: MinhaZona[]
  agir?: (a: () => Promise<unknown>) => Promise<void>
}) {
  const restam = segundosRestantes(v.arrives_at)

  /*
   * Antes do diretório, um veículo em rota só sabia dizer "a colônia #7" — o `id` cru, que não
   * significa nada para quem joga. Agora ele nomeia o colono. O `#id` fica de reserva para o
   * intervalo entre abrir a tela e o diretório chegar, e para um destino que suma da lista.
   */
  const alvo = vizinhas.find((c) => c.id === v.destination_id)
  const zonaAlvo = zonas?.find((z) => z.id === v.destination_id)

  const destino =
    v.destination_type === 'mercado_central'
      ? 'a Capital'
      : v.destination_type === 'zona_neutra'
        ? zonaAlvo
          ? `a zona ${zonaAlvo.name ?? `(${zonaAlvo.x},${zonaAlvo.y})`}`
          : 'a uma zona neutra'
        : alvo
          ? `a colônia de ${alvo.nickname}`
          : `a colônia #${v.destination_id}`

  return (
    <div className="border-rust/15 border p-2" data-veiculo={v.id}>
      <div className="flex items-center justify-between">
        <span className="text-ink text-sm font-bold">{nomeVeiculo(v.type)}</span>
        {v.status === 'ocioso' ? (
          <span className="text-ink-soft text-xs">
            {v.local === 'capital' ? 'no Pátio da Capital' : v.local === 'zona' ? 'numa zona neutra' : 'ocioso'}
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
              : v.trip_purpose === 'reposicionamento'
                ? `indo vazio, reposicionando para ${destino}`
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

      {v.status === 'ocioso' && colonia && zonas && agir && (
        <SeletorDeDestino v={v} colonia={colonia} zonas={zonas} agir={agir} />
      )}
    </div>
  )
}

/**
 * Reposiciona um veículo vazio (D-109) — substitui o antigo botão único "Chamar de volta". As
 * opções mudam com onde o veículo está: do Pátio, casa ou uma zona sua; de casa, a Capital ou uma
 * zona sua; de uma zona, hoje só casa (o backend recusa o resto — ver `DespacharVeiculo`).
 */
function SeletorDeDestino({
  v,
  colonia,
  zonas,
  agir,
}: {
  v: Veiculo
  colonia: Colonia
  zonas: MinhaZona[]
  agir: (a: () => Promise<unknown>) => Promise<void>
}) {
  type Opcao = { valor: string; rotulo: string; tipo: 'colonia' | 'mercado_central' | 'zona_neutra'; id: number | null }

  const opcoes: Opcao[] =
    v.local === 'capital'
      ? [
          { valor: 'casa', rotulo: 'Casa', tipo: 'colonia', id: colonia.id },
          ...zonas.map((z) => ({
            valor: `zona:${z.id}`,
            rotulo: z.name ?? `Zona (${z.x},${z.y})`,
            tipo: 'zona_neutra' as const,
            id: z.id,
          })),
        ]
      : v.local === 'colonia'
        ? [
            { valor: 'capital', rotulo: 'Capital', tipo: 'mercado_central', id: null },
            ...zonas.map((z) => ({
              valor: `zona:${z.id}`,
              rotulo: z.name ?? `Zona (${z.x},${z.y})`,
              tipo: 'zona_neutra' as const,
              id: z.id,
            })),
          ]
        : [{ valor: 'casa', rotulo: 'Casa', tipo: 'colonia', id: colonia.id }]

  const [escolha, setEscolha] = useState(opcoes[0]?.valor ?? '')
  const selecionada = opcoes.find((o) => o.valor === escolha)

  if (opcoes.length === 0) return null

  return (
    <div className="mt-1.5 flex items-center gap-1.5">
      <select
        aria-label="Enviar vazio para"
        value={escolha}
        onChange={(e) => setEscolha(e.target.value)}
        className="border-rust/25 bg-sand focus:border-rust border px-1.5 py-1 text-xs outline-none"
      >
        {opcoes.map((o) => (
          <option key={o.valor} value={o.valor}>
            {o.rotulo}
          </option>
        ))}
      </select>
      <button
        onClick={() =>
          selecionada && agir(() => api.reposicionarVazio(v.id, selecionada.tipo, selecionada.id))
        }
        disabled={!selecionada}
        className="text-rust hover:text-rust/70 text-xs font-bold disabled:cursor-not-allowed disabled:opacity-40"
      >
        Enviar vazio
      </button>
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
/**
 * O serviço logístico público do §07 (D-76): o governo busca na doca e leva até a colônia.
 *
 * Sem veículo próprio, sem energia — só Fert$ (o preço já aparece antes de pagar) e paciência: o
 * caminhão da Garagem é REAL, e se os do governo estiverem todos na estrada, o serviço recusa. A
 * entrega paga tributo na chegada como qualquer outra (D-32): frete não é rota de fuga.
 *
 * **Várias linhas, não uma** (pedido do usuário): o servidor sempre aceitou `cargo` como vários
 * recursos somados contra a capacidade (`array_sum`, igual ao `FormularioDeCarga` já usava para o
 * veículo próprio) — só esta tela é que mandava `{ [um código]: qtd }` de um `<select>` só. Mesmo
 * padrão de linhas do `FormularioDeCarga`, sem seletor de veículo nem de destino (aqui não há
 * escolha nenhuma dos dois: é sempre um caminhão do governo, sempre pra sua própria colônia).
 */
function FreteDoGoverno({
  conta,
  agir,
}: {
  conta: ContaDoMercado
  agir: (acao: () => Promise<unknown>) => Promise<void>
}) {
  const opcoes = doDeposito(conta)
  const [linhas, setLinhas] = useState<Linha[]>([{ codigo: '', qtd: '' }])

  const trocar = (i: number, campo: keyof Linha, valor: string) =>
    setLinhas((ls) => ls.map((l, j) => (j === i ? { ...l, [campo]: valor } : l)))

  // A mesma soma-por-recurso do `FormularioDeCarga`: linha sem recurso ou sem quantidade é
  // rascunho, fica de fora em vez de virar erro enquanto o colono ainda está preenchendo.
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

  const impedimento =
    opcoes.length === 0
      ? 'Nada seu no Mercado para fretar.'
      : excedidos.length > 0
        ? `Você não tem tudo isso de ${nomeRecurso(excedidos[0][0])}.`
        : total > conta.frete.capacidade
          ? `Passou da capacidade: ${total.toLocaleString('pt-BR')} de ${conta.frete.capacidade.toLocaleString('pt-BR')}.`
          : null

  const pode = total > 0 && !impedimento && conta.frete.caminhoes_livres >= 1

  return (
    <section className="border-rust/20 bg-sand mt-4 border p-3" data-frete-publico>
      <h4 className="text-ink font-black">Frete do governo</h4>
      <p className="text-ink-soft/80 mt-1 text-xs">
        Sem veículo? O governo leva por{' '}
        <strong>{conta.frete.preco_fert.toFixed(2).replace('.', ',')} F$</strong> a viagem (até{' '}
        {conta.frete.capacidade.toLocaleString('pt-BR')} unidades, somando os recursos). Caminhões
        livres na Garagem: <strong data-garagem-livres>{conta.frete.caminhoes_livres}</strong>. A
        entrega paga tributo na chegada, como toda entrega física.
      </p>

      <div className="mt-2 space-y-2">
        {linhas.map((l, i) => (
          <div key={i} className="flex gap-1">
            <select
              aria-label="Recurso"
              value={l.codigo}
              onChange={(e) => trocar(i, 'codigo', e.target.value)}
              disabled={opcoes.length === 0}
              data-frete-recurso
              className="border-rust/30 bg-sand-light text-ink min-w-0 flex-1 border px-2 py-1.5 text-sm"
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
              value={l.qtd}
              onChange={(e) => trocar(i, 'qtd', e.target.value.replace(/\D/g, ''))}
              inputMode="numeric"
              placeholder="Qtd"
              data-frete-qtd
              className="border-rust/30 bg-sand-light text-ink w-24 border px-2 py-1.5 text-sm"
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
            data-adicionar-recurso-frete
            className="text-rust hover:text-rust-bright text-xs font-bold disabled:opacity-40"
          >
            + outro recurso
          </button>

          <span
            className={`text-xs tabular-nums ${total > conta.frete.capacidade ? 'text-rust font-bold' : 'text-ink-soft'}`}
          >
            {total.toLocaleString('pt-BR')} / {conta.frete.capacidade.toLocaleString('pt-BR')}
          </span>
        </div>

        {impedimento && <p className="text-rust text-xs">{impedimento}</p>}

        <button
          onClick={() =>
            void agir(async () => {
              await api.fretePublico(carga)
              setLinhas([{ codigo: '', qtd: '' }])
            })
          }
          disabled={!pode}
          data-frete-enviar
          className="bg-rust text-sand-light hover:bg-rust-bright disabled:bg-ink-soft/30 w-full py-2 text-sm font-bold disabled:cursor-not-allowed"
        >
          Fretar
        </button>

        {conta.frete.caminhoes_livres < 1 && (
          <p className="text-rust text-xs">
            Os caminhões do governo estão todos na estrada. Tente mais tarde — ou mande um veículo seu.
          </p>
        )}
      </div>
    </section>
  )
}

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
  // Três linhas de saída, não uma: o colono quase sempre carrega mais de um recurso na mesma
  // viagem, e descobrir o "+ outro recurso" clicando era um passo a mais toda vez.
  const [linhas, setLinhas] = useState<Linha[]>([
    { codigo: '', qtd: '' },
    { codigo: '', qtd: '' },
    { codigo: '', qtd: '' },
  ])
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
        // Cards lado a lado a partir do desktop (md); empilhado no mobile — mesmo idioma de grid
        // responsivo que o resto do jogo já usa (PatioEDeposito, Acordos, as páginas do site).
        <div className="mt-4 grid gap-2 md:grid-cols-2 lg:grid-cols-3">
          {ofertas.map((o) => (
            <LinhaDaVitrine
              key={o.id}
              oferta={o}
              saldo={conta?.deposito.find((d) => d.resource_type === o.resource_type)}
              // O catálogo já vinha na vitrine, com o preço base de cada recurso — e ninguém o lia.
              base={
                vitrine?.catalogo.find((c) => c.code === o.resource_type)?.preco_base_micro ?? null
              }
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
  base,
  agir,
}: {
  oferta: OfertaGlobal
  saldo: SaldoDoRecurso | undefined
  /** O preço de referência do recurso (§06, D-35), ou `null` se o catálogo não o trouxe. */
  base: number | null
  agir: (a: () => Promise<unknown>) => Promise<void>
}) {
  /*
   * A razão contra a referência, arredondada a duas casas. `null` quando não há base: sem número
   * para comparar, dizer "no preço" seria afirmar o que não se sabe.
   */
  const razao = base && base > 0 ? Math.round((oferta.price_micro / base) * 100) / 100 : null

  const [qtd, setQtd] = useState('')
  const quantidade = Number(qtd) || oferta.qty
  const [confirmacao, setConfirmacao] = useState<{ qtd: number; recurso: string } | null>(null)

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
    <div className="border-rust/15 flex h-full flex-col justify-between border p-3">
      <div className="flex flex-col gap-2">
        <div className="text-sm">
          {/*
           * A etiqueta diz o que O ANUNCIANTE quer — não é um botão, é a descrição da oferta dele.
           * Por isso fica em tom neutro: colori-la de rust (a cor de toda ação clicável na tela)
           * fazia "COMPRA" parecer um convite para comprar, quando na verdade significa que É VOCÊ
           * quem venderia, entregando do seu depósito. A frase de baixo, essa sim em rust, é que diz
           * a ação de quem está lendo. Os ícones seguem o mesmo tom neutro — a forma da seta é que
           * distingue, não a cor.
           */}
          <span className="text-ink inline-flex items-center gap-1 font-bold">
            {vende ? <IconeVende /> : <IconeCompra />}
            {vende ? 'VENDE' : 'COMPRA'}
          </span>{' '}
          <span className="text-ink inline-flex items-center gap-1 font-bold">
            <IconeRecurso codigo={oferta.resource_type} />
            {nomeRecurso(oferta.resource_type)}
          </span>
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

          {/*
            ⚠️ A REFERÊNCIA, que só o vendedor via (A2.V5, D-227).

            O formulário de anunciar mostra "Referência 0,0062 Fert$"; a vitrine, não. Quem lê a
            lista via só "0,0100 Fert$" e não tinha como saber se era caro ou barato — a única
            pergunta que um comprador faz. O dado já vinha no payload (`Vitrine.catalogo`), sem
            consumidor: é o mesmo defeito que esta Alpha achou oito vezes.

            Medido em produção: os 1.440 negócios fechados saíram a **exatamente 1× a referência** —
            e todos são de bots. Nenhum humano executou uma ordem sequer. Um mercado onde o preço
            justo é invisível não convida ninguém a discordar dele.
          */}
          {base !== null && (
            <div className="text-ink-soft text-xs" data-referencia>
              referência{' '}
              <span className="tabular-nums">{fert(base)} Fert$</span>
              {razao !== null && (
                <span className={razao > 1 ? 'text-perigo' : razao < 1 ? 'text-sucesso' : ''}>
                  {' · '}
                  {razao === 1
                    ? 'no preço'
                    : `${razao.toFixed(2).replace('.', ',')}× ${razao > 1 ? 'acima' : 'abaixo'}`}
                </span>
              )}
            </div>
          )}
          {!oferta.minha && (
            <div className="text-rust mt-0.5 text-xs font-bold">
              {vende
                ? 'Você compra: paga Fert$ e recebe no seu depósito.'
                : 'Você vende: entrega do seu depósito e recebe Fert$.'}
            </div>
          )}
        </div>

        {oferta.minha ? (
          <button
            onClick={() => void agir(() => api.cancelar(oferta.id))}
            className="text-ink-soft hover:text-rust self-start text-xs font-bold"
          >
            Cancelar
          </button>
        ) : (
          <div className="flex flex-wrap items-center gap-2">
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
                void agir(() => api.executarOferta(oferta.id, quantidade)).then(() => {
                  setQtd('')
                  // Só ao COMPRAR (a oferta era de venda): quem vende não precisa de aviso, o
                  // Fert$ dele entra sem mistério nenhum — é o recurso chegando que é a novidade.
                  if (vende) setConfirmacao({ qtd: quantidade, recurso: oferta.resource_type })
                })
              }}
              className="bg-rust text-sand-light hover:bg-rust-bright px-4 py-1.5 text-sm font-bold disabled:cursor-not-allowed disabled:opacity-40"
            >
              {vende ? 'Comprar' : 'Vender'}
            </button>
          </div>
        )}
      </div>

      {impedimento && <p className="text-rust mt-1 text-xs font-bold">{impedimento}</p>}

      {confirmacao && (
        <Popup titulo="Compra concluída" eyebrow="Ofertas globais" aoFechar={() => setConfirmacao(null)}>
          <p className="text-ink text-sm">
            Compra realizada: <strong>{confirmacao.qtd.toLocaleString('pt-BR')}</strong> de{' '}
            <strong>{nomeRecurso(confirmacao.recurso)}</strong>.
          </p>
          <p className="text-ink-soft mt-1 text-sm">
            O recurso já está no seu depósito, na Capital.
          </p>
        </Popup>
      )}
    </div>
  )
}

const DURACOES_LEILAO = [1, 6, 12, 24, 48, 72] as const

/**
 * Leilões (D-129) — sem seção no GDD, desenho nosso sobre o Mercado Central: um lote, tudo ou
 * nada, sai do mesmo depósito da Capital que a venda comum usa. Lance é escrow na hora; quem é
 * superado recebe de volta no mesmo instante. Fecha sozinho, no tick, quando o prazo vence.
 */
/** O rótulo de um lote — recurso do catálogo, ou item da Endurance (D-135, Fase 2). */
function rotuloDoLote(l: Pick<Leilao, 'resource_type' | 'item_key' | 'item_nome'>): string {
  return l.item_key !== null ? (l.item_nome ?? l.item_key) : nomeRecurso(l.resource_type ?? '')
}

/** Selo do lote: `IconeRecurso` para recurso, uma sigla neutra para item da Endurance. */
function IconeDoLote({ leilao }: { leilao: Pick<Leilao, 'resource_type' | 'item_key' | 'item_nome'> }) {
  if (leilao.item_key === null) {
    return <IconeRecurso codigo={leilao.resource_type ?? ''} />
  }

  const sigla = (leilao.item_nome ?? leilao.item_key).slice(0, 2).toUpperCase()

  return (
    <span
      aria-hidden="true"
      className="bg-ink text-sand-light inline-flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full px-1 text-micro leading-none font-bold"
    >
      {sigla}
    </span>
  )
}

function Leiloes({
  leiloes,
  vitrine,
  conta,
  itensVendaveis,
  agir,
}: {
  leiloes: { abertos: Leilao[]; minhas: Leilao[] } | null
  vitrine: Vitrine | null
  conta: ContaDoMercado | null
  itensVendaveis: ItemVendavelEmLeilao[]
  agir: (a: () => Promise<unknown>) => Promise<void>
}) {
  const [alvo, setAlvo] = useState<'recurso' | 'item'>('recurso')
  const [recurso, setRecurso] = useState('metal_bruto')
  const [itemKey, setItemKey] = useState('')
  const [qtd, setQtd] = useState('')
  const [lanceMinimo, setLanceMinimo] = useState('')
  const [duracao, setDuracao] = useState<number>(24)

  const saldo = conta?.deposito.find((d) => d.resource_type === recurso)
  const itemEscolhido = itensVendaveis.find((i) => i.item_key === itemKey) ?? itensVendaveis[0]
  const quantidade = Number(qtd)
  const lanceMinimoValido = /^\d+([.,]\d{1,6})?$/.test(lanceMinimo.trim()) && Number(lanceMinimo.replace(',', '.')) > 0

  const impedimento =
    !Number.isInteger(quantidade) || quantidade <= 0
      ? null
      : alvo === 'recurso'
        ? saldo && quantidade > saldo.no_deposito
          ? `Seu depósito na Capital tem ${saldo.no_deposito.toLocaleString('pt-BR')}. Leve carga até lá primeiro.`
          : null
        : itemEscolhido && quantidade > itemEscolhido.quantidade
          ? `Você tem ${itemEscolhido.quantidade.toLocaleString('pt-BR')} deste item.`
          : null

  const valido =
    Number.isInteger(quantidade) &&
    quantidade > 0 &&
    lanceMinimoValido &&
    !impedimento &&
    (alvo === 'recurso' || itemEscolhido !== undefined)

  return (
    <div className="mt-5">
      <p className="text-ink-soft text-sm">
        O lote sai do <strong className="text-ink">depósito da Capital</strong> (ou da posse de um
        item da Endurance vendável em Leilão), como uma venda comum — só que aqui quem leva é quem
        der o maior lance até o prazo. Sem lance nenhum, o lote volta a quem anunciou.
      </p>

      <div className="border-rust/20 mt-4 border p-3">
        <div className="mb-3 flex gap-1" role="tablist" aria-label="Tipo de lote">
          {(['recurso', 'item'] as const).map((a) => (
            <button
              key={a}
              type="button"
              role="tab"
              aria-selected={alvo === a}
              onClick={() => setAlvo(a)}
              className={`border px-3 py-1 text-xs font-bold ${
                alvo === a
                  ? 'border-rust bg-rust text-sand-light'
                  : 'border-rust/25 text-ink-soft hover:border-rust/50'
              }`}
            >
              {a === 'recurso' ? 'Recurso' : 'Item da Endurance'}
            </button>
          ))}
        </div>

        {alvo === 'recurso' ? (
          <>
            <select
              aria-label="Recurso"
              className="border-rust/25 bg-sand focus:border-rust w-full border px-2 py-1.5 text-sm outline-none"
              value={recurso}
              onChange={(e) => setRecurso(e.target.value)}
            >
              {(vitrine?.catalogo ?? []).map((c) => (
                <option key={c.code} value={c.code}>
                  {c.nome}
                </option>
              ))}
            </select>

            <DoisEstoques saldo={saldo} />
          </>
        ) : itensVendaveis.length === 0 ? (
          <p className="text-ink-soft text-sm">
            Você ainda não tem nenhum item da Endurance vendável em Leilões — compre um na Loja de
            Peças dos destroços.
          </p>
        ) : (
          <>
            <select
              aria-label="Item da Endurance"
              className="border-rust/25 bg-sand focus:border-rust w-full border px-2 py-1.5 text-sm outline-none"
              value={itemEscolhido?.item_key ?? ''}
              onChange={(e) => setItemKey(e.target.value)}
            >
              {itensVendaveis.map((i) => (
                <option key={i.item_key} value={i.item_key}>
                  {i.nome} ({i.quantidade})
                </option>
              ))}
            </select>

            {itemEscolhido && (
              <p className="text-ink-soft mt-1 text-xs">Você tem {itemEscolhido.quantidade}.</p>
            )}
          </>
        )}

        <div className="mt-3 grid gap-2 md:grid-cols-3">
          <input
            className="border-rust/25 bg-sand focus:border-rust border px-2 py-1.5 text-sm outline-none"
            placeholder="Quantidade"
            inputMode="numeric"
            value={qtd}
            onChange={(e) => setQtd(e.target.value.replace(/\D/g, ''))}
          />
          <input
            className="border-rust/25 bg-sand focus:border-rust border px-2 py-1.5 text-sm outline-none"
            placeholder="Lance mínimo, em Fert$"
            inputMode="decimal"
            value={lanceMinimo}
            onChange={(e) => setLanceMinimo(e.target.value)}
          />
          <select
            aria-label="Duração"
            className="border-rust/25 bg-sand focus:border-rust border px-2 py-1.5 text-sm outline-none"
            value={duracao}
            onChange={(e) => setDuracao(Number(e.target.value))}
          >
            {DURACOES_LEILAO.map((h) => (
              <option key={h} value={h}>
                {h < 24 ? `${h} h` : `${h / 24} dia${h > 24 ? 's' : ''}`}
              </option>
            ))}
          </select>
        </div>

        {impedimento && <p className="text-rust mt-2 text-xs font-bold">{impedimento}</p>}

        <button
          disabled={!valido}
          onClick={() => {
            void agir(() =>
              api.anunciarLeilao(
                alvo === 'item' && itemEscolhido
                  ? {
                      item_key: itemEscolhido.item_key,
                      qty: quantidade,
                      lance_minimo_fert: Number(lanceMinimo.replace(',', '.')),
                      duracao_horas: duracao,
                    }
                  : {
                      resource_type: recurso,
                      qty: quantidade,
                      lance_minimo_fert: Number(lanceMinimo.replace(',', '.')),
                      duracao_horas: duracao,
                    },
              ),
            ).then(() => {
              setQtd('')
              setLanceMinimo('')
            })
          }}
          className="bg-rust text-sand-light hover:bg-rust-bright mt-3 w-full py-2 text-sm font-bold disabled:cursor-not-allowed disabled:opacity-40"
        >
          Anunciar leilão
        </button>
      </div>

      <div className="text-rust eyebrow mt-6">Leilões abertos</div>
      {(leiloes?.abertos.length ?? 0) === 0 ? (
        <p className="text-ink-soft mt-2 text-sm">Nenhum leilão aberto agora.</p>
      ) : (
        <div className="mt-2 grid gap-2 md:grid-cols-2 lg:grid-cols-3">
          {leiloes?.abertos.map((l) => <CardDeLeilao key={l.id} leilao={l} agir={agir} />)}
        </div>
      )}

      {(leiloes?.minhas.length ?? 0) > 0 && (
        <>
          <div className="text-rust eyebrow mt-6">Seus leilões</div>
          <div className="mt-2 space-y-1">
            {leiloes?.minhas.map((l) => (
              <div key={l.id} className="border-rust/15 flex items-center justify-between border p-2 text-sm">
                <span className="text-ink-soft">
                  {l.qty.toLocaleString('pt-BR')} de {rotuloDoLote(l)}
                  {l.lance_atual_fert !== null && (
                    <> · maior lance {l.lance_atual_fert.toLocaleString('pt-BR')} Fert$</>
                  )}
                </span>
                <span className="text-ink font-bold">{ROTULO_STATUS_LEILAO[l.status]}</span>
              </div>
            ))}
          </div>
        </>
      )}
    </div>
  )
}

const ROTULO_STATUS_LEILAO: Record<Leilao['status'], string> = {
  aberto: 'Aberto',
  arrematado: 'Arrematado',
  sem_lance: 'Encerrado sem lance',
  cancelado: 'Cancelado',
}

function CardDeLeilao({
  leilao,
  agir,
}: {
  leilao: Leilao
  agir: (a: () => Promise<unknown>) => Promise<void>
}) {
  const [lance, setLance] = useState('')
  const restam = segundosRestantes(leilao.deadline_at)
  const lanceNumero = Number(lance.replace(',', '.'))
  const lanceValido = /^\d+([.,]\d{1,6})?$/.test(lance.trim()) && lanceNumero >= leilao.proximo_lance_minimo_fert

  return (
    <div className="border-rust/15 flex h-full flex-col justify-between gap-2 border p-3">
      <div>
        <div className="text-sm">
          <span className="text-ink inline-flex items-center gap-1 font-bold">
            <IconeDoLote leilao={leilao} />
            {leilao.qty.toLocaleString('pt-BR')} de {rotuloDoLote(leilao)}
          </span>
        </div>
        <p className="text-ink-soft mt-0.5 text-xs">
          De {leilao.colonia ?? `colônia #${leilao.colony_id}`} · encerra em{' '}
          <span className={restam < 3600 ? 'text-rust font-bold' : ''}>{prazoHumano(restam)}</span>
        </p>
        <p className="text-ink-soft mt-1 text-xs">
          Mínimo {leilao.lance_minimo_fert.toLocaleString('pt-BR')} Fert$
          {leilao.lance_atual_fert !== null && (
            <>
              {' · '}
              <span className="text-ink font-bold">
                lance atual {leilao.lance_atual_fert.toLocaleString('pt-BR')} Fert$
              </span>
              {leilao.meu_lance && <span className="text-rust font-bold"> (seu)</span>}
            </>
          )}
        </p>
      </div>

      {leilao.minha ? (
        leilao.lance_colony_id === null ? (
          <button
            onClick={() => void agir(() => api.cancelarLeilao(leilao.id))}
            className="text-ink-soft hover:text-rust self-start text-xs font-bold"
          >
            Cancelar
          </button>
        ) : (
          <p className="text-ink-soft text-xs">Já tem lance; não pode mais ser cancelado.</p>
        )
      ) : (
        <div className="flex gap-1">
          <input
            className="border-rust/25 bg-sand focus:border-rust w-full border px-2 py-1 text-sm outline-none"
            placeholder={`Mín. ${leilao.proximo_lance_minimo_fert.toLocaleString('pt-BR')}`}
            inputMode="decimal"
            value={lance}
            onChange={(e) => setLance(e.target.value)}
          />
          <button
            disabled={!lanceValido}
            onClick={() => void agir(() => api.darLance(leilao.id, lanceNumero)).then(() => setLance(''))}
            className="bg-rust text-sand-light hover:bg-rust-bright px-3 py-1 text-xs font-bold whitespace-nowrap disabled:cursor-not-allowed disabled:opacity-40"
          >
            Dar lance
          </button>
        </div>
      )}
    </div>
  )
}
