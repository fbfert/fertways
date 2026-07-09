import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from '../api/client'
import type { Colonia, ColoniaVizinha, ContaDoMercado, Frota, Livro, Veiculo } from '../api/client'
import { NEGOCIAVEIS, fert, nomeRecurso, nomeVeiculo, paraMicro, relogio, segundosRestantes } from './recursos'

const INTERVALO_MS = 3000

/**
 * Mercado Central: a doca (§25.8) e o livro de ofertas (§07).
 *
 * A doca não é o estoque da colônia. Recurso só chega lá por veículo, e só volta por veículo —
 * é o que o GDD chama de "movimentação física". O livro casa ordens entre colonos; o Mercado
 * não compra nem vende, e o preço-base é referência, não teto nem piso.
 */
export function Mercado({ colonia, aoFechar }: { colonia: Colonia; aoFechar: () => void }) {
  const [aba, setAba] = useState<'doca' | 'livro'>('doca')
  const [frota, setFrota] = useState<Frota | null>(null)
  const [conta, setConta] = useState<ContaDoMercado | null>(null)
  const [livro, setLivro] = useState<Livro | null>(null)
  const [vizinhas, setVizinhas] = useState<ColoniaVizinha[]>([])
  const [recurso, setRecurso] = useState('metal_bruto')
  const [erro, setErro] = useState<string | null>(null)

  const carregar = useCallback(async () => {
    try {
      const [f, c, l] = await Promise.all([api.frota(), api.conta(), api.livro(recurso)])
      setFrota(f)
      setConta(c)
      setLivro(l)
    } catch (e) {
      if (e instanceof ApiError) setErro(e.message)
    }
  }, [recurso])

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

  const ociosos = frota?.vehicles.filter((v) => v.status === 'ocioso') ?? []

  return (
    <div className="fixed inset-0 z-20 flex items-center justify-center bg-ink/70 p-4">
      <div className="painel bg-sand-light max-h-[90vh] w-full max-w-3xl overflow-y-auto p-6">
        <header className="flex items-start justify-between">
          <div>
            <div className="text-rust eyebrow">Mercado Central</div>
            <h2 className="text-ink text-2xl font-black">A Capital</h2>
            {conta && (
              <p className="text-ink-soft mt-1 text-sm">
                {conta.distance_slots} slots de distância do seu slot.
              </p>
            )}
          </div>
          <button onClick={aoFechar} className="text-ink-soft hover:text-rust text-2xl leading-none">
            ×
          </button>
        </header>

        <nav className="border-rust/20 mt-5 flex gap-1 border-b">
          {(['doca', 'livro'] as const).map((a) => (
            <button
              key={a}
              onClick={() => setAba(a)}
              className={`eyebrow px-4 py-2 ${
                aba === a ? 'bg-rust text-sand-light' : 'text-ink-soft hover:text-rust'
              }`}
            >
              {a === 'doca' ? 'Doca e frota' : 'Livro de ofertas'}
            </button>
          ))}
        </nav>

        {erro && <p className="text-rust mt-4 text-sm font-bold">{erro}</p>}

        {aba === 'doca' ? (
          <Doca
            colonia={colonia}
            conta={conta}
            frota={frota}
            ociosos={ociosos}
            vizinhas={vizinhas}
            agir={agir}
          />
        ) : (
          <LivroDeOfertas
            livro={livro}
            recurso={recurso}
            aoTrocarRecurso={setRecurso}
            agir={agir}
          />
        )}
      </div>
    </div>
  )
}

function Doca({
  colonia,
  conta,
  frota,
  ociosos,
  vizinhas,
  agir,
}: {
  colonia: Colonia
  conta: ContaDoMercado | null
  frota: Frota | null
  ociosos: Veiculo[]
  vizinhas: ColoniaVizinha[]
  agir: (a: () => Promise<unknown>) => Promise<void>
}) {
  const naDoca = conta?.balances ?? []

  /*
   * Do mais abundante para o menos. Sem ordenar, `Object.entries` manda primeiro o que o catálogo
   * listar primeiro, e o campo abria num raro do kit inicial, do qual o colono tem punhados — a
   * opção que ele quase nunca quer despachar.
   *
   * Energia fica de fora: ela é o combustível da viagem (§21.1), não carga.
   */
  const doEstoque = Object.entries(colonia.resources)
    .filter(([c, q]) => q > 0 && c !== 'energia')
    .sort(([, a], [, b]) => b - a)
    .map(([c, q]) => ({ codigo: c, disponivel: q }))

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

        <div className="text-rust eyebrow mt-6">Na doca</div>
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
      </section>

      <section className="space-y-6">
        <FormularioDeCarga
          titulo="Levar à doca"
          ajuda="A carga sai do estoque agora. O tributo incide quando ela chega."
          veiculos={ociosos}
          opcoes={doEstoque}
          rotuloBotao="Despachar"
          aoEnviar={(veiculo, codigo, qtd) =>
            agir(() => api.depositar(veiculo, { [codigo]: qtd }))
          }
        />

        {/*
         * Comércio informal (§25.7): os dois colonos combinam a troca por fora, e o veículo faz a
         * parte física. Não há escrow aqui — é o canal com risco de calote, por design.
         */}
        <FormularioDeCarga
          titulo="Enviar a outro colono"
          ajuda="A carga sai do estoque agora. O tributo incide quando ela chega ao slot dele."
          veiculos={ociosos}
          opcoes={doEstoque}
          destinos={vizinhas.map((c) => ({
            id: c.id,
            rotulo: `${c.nickname} · ${c.distance} slots`,
          }))}
          rotuloBotao="Enviar"
          aoEnviar={(veiculo, codigo, qtd, destino) =>
            // `podeEnviar` já garante o destino quando há lista; isto é a rede, não a regra.
            destino === undefined
              ? Promise.resolve()
              : agir(() => api.enviarAColonia(veiculo, destino, { [codigo]: qtd }))
          }
        />

        <FormularioDeCarga
          titulo="Buscar na doca"
          ajuda="O saldo é reservado já no despacho. O tributo incide na chegada ao seu slot."
          veiculos={ociosos}
          opcoes={[...naDoca]
            .sort((a, b) => b.amount - a.amount)
            .map((b) => ({ codigo: b.resource_type, disponivel: b.amount }))}
          rotuloBotao="Buscar"
          aoEnviar={(veiculo, codigo, qtd) => agir(() => api.retirar(veiculo, { [codigo]: qtd }))}
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
    <div className="border-rust/15 border p-2">
      <div className="flex items-center justify-between">
        <span className="text-ink text-sm font-bold">{nomeVeiculo(v.type)}</span>
        {v.status === 'ocioso' ? (
          <span className="text-ink-soft text-xs">ocioso</span>
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
            : v.leg === 'ida'
              ? `levando carga para ${destino}`
              : 'voltando vazio'}
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
        capacidade {v.capacity.toLocaleString('pt-BR')}
      </div>
    </div>
  )
}

function FormularioDeCarga({
  titulo,
  ajuda,
  veiculos,
  opcoes,
  destinos,
  rotuloBotao,
  aoEnviar,
}: {
  titulo: string
  ajuda: string
  veiculos: Veiculo[]
  opcoes: { codigo: string; disponivel: number }[]
  /**
   * Quando ausente, o destino é implícito (a doca da Capital) e nenhum seletor aparece. Quando
   * presente, o colono escolhe para onde a carga vai — e uma lista vazia é impedimento, não um
   * `<select>` vazio que deixa apertar o botão.
   */
  destinos?: { id: number; rotulo: string }[]
  rotuloBotao: string
  aoEnviar: (veiculo: number, codigo: string, qtd: number, destino?: number) => Promise<void>
}) {
  const [codigo, setCodigo] = useState('')
  const [qtd, setQtd] = useState('')
  const [destinoId, setDestinoId] = useState<number | null>(null)

  const escolhido = opcoes.find((o) => o.codigo === codigo) ?? opcoes[0]
  const destino = destinos?.find((d) => d.id === destinoId) ?? destinos?.[0]
  const veiculo = veiculos[0]
  const quantidade = Number(qtd)

  const impedimento = !veiculo
    ? 'Nenhum veículo ocioso.'
    : destinos && !destino
      ? 'Nenhuma outra colônia no servidor.'
      : !escolhido
        ? 'Nada para carregar.'
        : !Number.isInteger(quantidade) || quantidade <= 0
          ? null
          : quantidade > escolhido.disponivel
            ? `Você tem ${escolhido.disponivel.toLocaleString('pt-BR')}.`
            : quantidade > veiculo.capacity
              ? `O veículo leva ${veiculo.capacity.toLocaleString('pt-BR')}.`
              : null

  const podeEnviar =
    !!veiculo &&
    !!escolhido &&
    (!destinos || !!destino) &&
    Number.isInteger(quantidade) &&
    quantidade > 0 &&
    !impedimento

  return (
    <div className="border-rust/20 border p-3">
      <div className="text-rust eyebrow">{titulo}</div>
      <p className="text-ink-soft mt-1 text-xs">{ajuda}</p>

      <div className="mt-3 space-y-2">
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

        <select
          className="border-rust/25 bg-sand w-full border px-2 py-1.5 text-sm outline-none focus:border-rust"
          value={escolhido?.codigo ?? ''}
          onChange={(e) => setCodigo(e.target.value)}
          disabled={opcoes.length === 0}
        >
          {opcoes.length === 0 && <option>—</option>}
          {opcoes.map((o) => (
            <option key={o.codigo} value={o.codigo}>
              {nomeRecurso(o.codigo)} ({o.disponivel.toLocaleString('pt-BR')})
            </option>
          ))}
        </select>

        <input
          className="border-rust/25 bg-sand w-full border px-2 py-1.5 text-sm outline-none focus:border-rust"
          placeholder="Quantidade"
          inputMode="numeric"
          value={qtd}
          onChange={(e) => setQtd(e.target.value.replace(/\D/g, ''))}
        />

        {impedimento && <p className="text-rust text-xs">{impedimento}</p>}

        <button
          disabled={!podeEnviar}
          onClick={() => {
            void aoEnviar(veiculo.id, escolhido.codigo, quantidade, destino?.id).then(() =>
              setQtd(''),
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

function LivroDeOfertas({
  livro,
  recurso,
  aoTrocarRecurso,
  agir,
}: {
  livro: Livro | null
  recurso: string
  aoTrocarRecurso: (r: string) => void
  agir: (a: () => Promise<unknown>) => Promise<void>
}) {
  const [lado, setLado] = useState<'buy' | 'sell'>('buy')
  const [qtd, setQtd] = useState('')
  const [preco, setPreco] = useState('')

  const quantidade = Number(qtd)
  const precoMicro = paraMicro(preco)
  const valido = Number.isInteger(quantidade) && quantidade > 0 && precoMicro > 0

  return (
    <div className="mt-5">
      <div className="flex items-center justify-between gap-4">
        <select
          className="border-rust/25 bg-sand border px-2 py-1.5 text-sm outline-none focus:border-rust"
          value={recurso}
          onChange={(e) => aoTrocarRecurso(e.target.value)}
        >
          {NEGOCIAVEIS.map((c) => (
            <option key={c} value={c}>
              {nomeRecurso(c)}
            </option>
          ))}
        </select>

        {livro && (
          <div className="text-ink-soft text-right text-xs">
            <div>
              referência{' '}
              <span className="text-ink font-bold tabular-nums">
                {fert(livro.preco_base_micro)} Fert$
              </span>
            </div>
            <div>taxa de venda {(livro.taxa_bps / 100).toFixed(0)}%, paga por quem vende</div>
          </div>
        )}
      </div>

      <div className="mt-4 grid gap-4 md:grid-cols-2">
        <Coluna titulo="Compras" vazio="Ninguém comprando." linhas={livro?.bids ?? []} />
        <Coluna titulo="Vendas" vazio="Ninguém vendendo." linhas={livro?.asks ?? []} />
      </div>

      <div className="border-rust/20 mt-6 border p-3">
        <div className="text-rust eyebrow">Nova ordem</div>

        <div className="mt-2 flex gap-1">
          {(['buy', 'sell'] as const).map((l) => (
            <button
              key={l}
              onClick={() => setLado(l)}
              className={`flex-1 py-1.5 text-sm font-bold ${
                lado === l ? 'bg-rust text-sand-light' : 'border-rust/25 text-ink-soft border'
              }`}
            >
              {l === 'buy' ? 'Comprar' : 'Vender'}
            </button>
          ))}
        </div>

        <p className="text-ink-soft mt-2 text-xs">
          {lado === 'sell'
            ? 'Vender exige a carga já na doca. O preço-base é referência: negocie o que quiser.'
            : 'O Fert$ é reservado no ato. O recurso comprado chega na doca, e você manda um veículo buscá-lo.'}
        </p>

        <div className="mt-3 grid gap-2 md:grid-cols-2">
          <input
            className="border-rust/25 bg-sand border px-2 py-1.5 text-sm outline-none focus:border-rust"
            placeholder="Quantidade"
            inputMode="numeric"
            value={qtd}
            onChange={(e) => setQtd(e.target.value.replace(/\D/g, ''))}
          />
          <input
            className="border-rust/25 bg-sand border px-2 py-1.5 text-sm outline-none focus:border-rust"
            placeholder="Preço por unidade, em Fert$"
            inputMode="decimal"
            value={preco}
            onChange={(e) => setPreco(e.target.value)}
          />
        </div>

        {valido && (
          <p className="text-ink-soft mt-2 text-xs">
            Total: <span className="text-ink font-bold">{fert(quantidade * precoMicro, 2)} Fert$</span>
          </p>
        )}

        <button
          disabled={!valido}
          onClick={() => {
            void agir(() =>
              api.ordenar({ side: lado, resource_type: recurso, qty: quantidade, price_micro: precoMicro }),
            ).then(() => {
              setQtd('')
              setPreco('')
            })
          }}
          className="bg-rust text-sand-light hover:bg-rust-bright mt-3 w-full py-2 text-sm font-bold disabled:cursor-not-allowed disabled:opacity-40"
        >
          {lado === 'buy' ? 'Colocar ordem de compra' : 'Colocar ordem de venda'}
        </button>
      </div>

      <div className="text-rust eyebrow mt-6">Suas ordens abertas</div>
      {livro?.minhas_ordens.length === 0 && (
        <p className="text-ink-soft mt-2 text-sm">Nenhuma ordem sua neste recurso.</p>
      )}
      <div className="mt-2 space-y-2">
        {livro?.minhas_ordens.map((o) => (
          <div key={o.id} className="border-rust/15 flex items-center justify-between border p-2">
            <div className="text-sm">
              <span className="text-ink font-bold">{o.side === 'buy' ? 'Compra' : 'Venda'}</span>
              <span className="text-ink-soft">
                {' '}
                de {o.qty.toLocaleString('pt-BR')} a {fert(o.price_micro)} Fert$
                {o.status === 'parcial' && ' · parcialmente executada'}
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
    </div>
  )
}

function Coluna({
  titulo,
  vazio,
  linhas,
}: {
  titulo: string
  vazio: string
  linhas: { price_micro: number; qty: number }[]
}) {
  return (
    <div>
      <div className="text-ink-soft eyebrow">{titulo}</div>
      {linhas.length === 0 ? (
        <p className="text-ink-soft mt-2 text-sm">{vazio}</p>
      ) : (
        <div className="mt-1">
          {linhas.map((l, i) => (
            <div
              key={i}
              className="border-rust/10 flex justify-between border-b py-1 text-sm last:border-0"
            >
              <span className="text-ink font-bold tabular-nums">{fert(l.price_micro)}</span>
              <span className="text-ink-soft tabular-nums">{l.qty.toLocaleString('pt-BR')}</span>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
