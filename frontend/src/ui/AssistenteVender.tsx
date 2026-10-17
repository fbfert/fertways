import { useState } from 'react'
import { api, ApiError } from '../api/client'
import type { ContaDoMercado, OfertaGlobal, Veiculo, Vitrine } from '../api/client'
import { IconeRecurso } from './IconeRecurso'
import { Popup } from './Popup'
import { fert, nomeRecurso, nomeVeiculo, paraMicro, prazoHumano, segundosRestantes } from './recursos'

/**
 * O assistente "Vender" (D-291).
 *
 * A revisão do novato (D-285) achou que **vender exigia três abas e dois formulários**: levar a
 * carga ao depósito da Capital no Mercado Local, esperar, e então anunciar no Mercado Central — sem
 * nada dizendo que a primeira coisa era condição da segunda. O assistente segue a ordem que o jogo
 * exige, um passo por vez, e cada passo diz o que falta antes do clique:
 *
 *  1. **o quê** e quanto, com os dois estoques à vista;
 *  2. **levar à Capital** o que falta no depósito, num veículo seu que esteja em casa (pulado se
 *     o depósito já tem tudo);
 *  3. **por quanto**: vender agora a quem já anunciou compra, pelo preço dele, ou anunciar a venda
 *     pelo seu preço.
 *
 * Não há regra nova aqui. São as mesmas chamadas das abas (`depositar`, `executarOferta`,
 * `ordenar`), e o servidor continua sendo quem recusa.
 */
type Passo = 'oque' | 'levar' | 'preco' | 'feito'

export function AssistenteVender({
  conta,
  vitrine,
  emCasa,
  aoMudar,
  aoFechar,
}: {
  conta: ContaDoMercado | null
  vitrine: Vitrine | null
  emCasa: Veiculo[]
  /** Recarrega o Mercado por trás: o depósito e a vitrine mudam a cada passo. */
  aoMudar: () => Promise<void>
  aoFechar: () => void
}) {
  const [passo, setPasso] = useState<Passo>('oque')
  const [recurso, setRecurso] = useState('')
  const [qtd, setQtd] = useState('')
  const [veiculo, setVeiculo] = useState<number | null>(null)
  const [preco, setPreco] = useState('')
  const [erro, setErro] = useState<string | null>(null)
  const [ocupado, setOcupado] = useState(false)
  const [aCaminho, setACaminho] = useState<{ qtd: number; chega: string | null } | null>(null)
  const [feito, setFeito] = useState<string | null>(null)

  const saldos = (conta?.deposito ?? []).filter((s) => s.na_colonia + s.no_deposito > 0)
  const saldo = conta?.deposito.find((s) => s.resource_type === recurso)
  const quantidade = Number(qtd)
  const valida = Number.isInteger(quantidade) && quantidade > 0
  const noDeposito = saldo?.no_deposito ?? 0
  const naColonia = saldo?.na_colonia ?? 0
  const falta = Math.max(0, quantidade - noDeposito)
  const tipo = vitrine?.catalogo.find((c) => c.code === recurso)
  const taxaBps = tipo?.taxa_bps ?? 0

  async function fazer(acao: () => Promise<unknown>, depois: () => void) {
    setErro(null)
    setOcupado(true)
    try {
      await acao()
      await aoMudar()
      depois()
    } catch (e) {
      setErro(e instanceof ApiError ? e.message : 'Falha na operação.')
    } finally {
      setOcupado(false)
    }
  }

  // ── 1. O quê ────────────────────────────────────────────────────────────────────────────────
  const impedimentoDoQue = !recurso
    ? 'Escolha o recurso.'
    : !valida
      ? 'Diga quanto quer vender.'
      : quantidade > naColonia + noDeposito
        ? `Você tem ${(naColonia + noDeposito).toLocaleString('pt-BR')} de ${nomeRecurso(recurso)}, somando a colônia e o depósito.`
        : null

  // ── 2. Levar ────────────────────────────────────────────────────────────────────────────────
  const escolhido = emCasa.find((v) => v.id === veiculo) ?? null
  const cabe = Math.min(falta, escolhido?.capacity_efetiva ?? 0, saldo?.livre ?? 0)
  const impedimentoDoLevar =
    emCasa.length === 0
      ? 'Nenhum veículo seu está em casa e ocioso. Espere um voltar, ou fabrique um no Ministério dos Transportes.'
      : !escolhido
        ? 'Escolha o veículo.'
        : (saldo?.livre ?? 0) <= 0
          ? 'O seu depósito na Capital está cheio. Venda ou retire o que está lá antes.'
          : null

  // ── 3. Por quanto ───────────────────────────────────────────────────────────────────────────
  const vendavel = Math.min(quantidade, noDeposito)
  const compradores = (vitrine?.ofertas ?? [])
    .filter((o) => o.side === 'buy' && !o.minha && o.resource_type === recurso)
    .sort((a, b) => b.price_micro - a.price_micro)
    .slice(0, 3)
  const precoMicro = paraMicro(preco)
  const liquido = (bruto: number) => bruto - Math.floor((bruto * taxaBps) / 10_000)

  const titulo: Record<Passo, string> = {
    oque: 'O que vender',
    levar: 'Levar à Capital',
    preco: 'Por quanto',
    feito: 'Pronto',
  }

  return (
    <Popup titulo={titulo[passo]} eyebrow="Vender, passo a passo" aoFechar={aoFechar}>
      <ol className="text-ink-soft mb-4 flex gap-3 text-xs" data-passos-vender>
        {(['oque', 'levar', 'preco'] as const).map((p, i) => (
          <li key={p} className={passo === p ? 'text-rust font-bold' : ''}>
            {i + 1}. {titulo[p]}
          </li>
        ))}
      </ol>

      {passo === 'oque' && (
        <div className="space-y-3">
          {saldos.length === 0 ? (
            <p className="text-ink-soft text-sm">Nada para vender: o estoque da colônia e o depósito estão vazios.</p>
          ) : (
            <select
              aria-label="Recurso"
              value={recurso}
              onChange={(e) => setRecurso(e.target.value)}
              data-vender-recurso
              className="border-rust/30 bg-sand text-ink w-full border px-2 py-1.5 text-sm"
            >
              <option value="">Recurso…</option>
              {saldos.map((s) => (
                <option key={s.resource_type} value={s.resource_type}>
                  {nomeRecurso(s.resource_type)} ({(s.na_colonia + s.no_deposito).toLocaleString('pt-BR')})
                </option>
              ))}
            </select>
          )}

          {saldo && (
            <div className="grid grid-cols-2 gap-2 text-sm">
              <div className="border-rust/15 border p-2">
                <div className="text-ink-soft text-xs">Na colônia</div>
                <div className="text-ink flex items-center gap-1 font-bold tabular-nums">
                  <IconeRecurso codigo={recurso} /> {naColonia.toLocaleString('pt-BR')}
                </div>
              </div>
              <div className="border-rust/15 border p-2">
                <div className="text-ink-soft text-xs">No depósito da Capital</div>
                <div className="text-ink flex items-center gap-1 font-bold tabular-nums">
                  <IconeRecurso codigo={recurso} /> {noDeposito.toLocaleString('pt-BR')}
                </div>
              </div>
            </div>
          )}

          <input
            aria-label="Quantidade"
            value={qtd}
            onChange={(e) => setQtd(e.target.value.replace(/\D/g, ''))}
            inputMode="numeric"
            placeholder="Quantidade"
            data-vender-qtd
            className="border-rust/30 bg-sand text-ink w-full border px-2 py-1.5 text-sm"
          />

          <p className="text-ink-soft text-xs">
            Só se vende o que já está no depósito da Capital. Se faltar, o próximo passo leva da colônia.
          </p>

          {impedimentoDoQue && qtd !== '' && <p className="text-rust text-xs font-bold">{impedimentoDoQue}</p>}

          <button
            disabled={impedimentoDoQue !== null}
            onClick={() => setPasso(falta > 0 ? 'levar' : 'preco')}
            data-vender-avancar
            className="bg-rust text-sand-light hover:bg-rust-bright w-full py-2 text-sm font-bold disabled:cursor-not-allowed disabled:opacity-40"
          >
            {falta > 0 ? `Continuar: faltam ${falta.toLocaleString('pt-BR')} no depósito` : 'Continuar'}
          </button>
        </div>
      )}

      {passo === 'levar' && (
        <div className="space-y-3">
          {aCaminho ? (
            <>
              <p className="text-ink text-sm" data-vender-a-caminho>
                {aCaminho.qtd.toLocaleString('pt-BR')} de {nomeRecurso(recurso)} a caminho da Capital
                {aCaminho.chega ? `, chegando em ${prazoHumano(segundosRestantes(aCaminho.chega))}` : ''}. O
                tributo de entrega incide na chegada, então chega um pouco menos.
              </p>
              <p className="text-ink-soft text-xs">
                Você pode fechar e voltar quando a carga chegar: o assistente começa do depósito.
                {noDeposito > 0 && ' Ou venda agora o que já está lá.'}
              </p>
              {noDeposito > 0 && (
                <button
                  onClick={() => setPasso('preco')}
                  className="bg-rust text-sand-light hover:bg-rust-bright w-full py-2 text-sm font-bold"
                >
                  Vender os {noDeposito.toLocaleString('pt-BR')} que já estão lá
                </button>
              )}
            </>
          ) : (
            <>
              <p className="text-ink-soft text-sm">
                Faltam <strong className="text-ink">{falta.toLocaleString('pt-BR')}</strong> no depósito. Um veículo
                seu leva da colônia, e fica estacionado no Pátio da Capital.
              </p>

              {emCasa.length > 0 && (
                <select
                  aria-label="Veículo"
                  value={veiculo ?? ''}
                  onChange={(e) => setVeiculo(e.target.value ? Number(e.target.value) : null)}
                  data-vender-veiculo
                  className="border-rust/30 bg-sand text-ink w-full border px-2 py-1.5 text-sm"
                >
                  <option value="">Veículo…</option>
                  {emCasa.map((v) => (
                    <option key={v.id} value={v.id}>
                      {v.nickname ?? nomeVeiculo(v.type)} · leva {v.capacity_efetiva.toLocaleString('pt-BR')}
                    </option>
                  ))}
                </select>
              )}

              {escolhido && cabe > 0 && cabe < falta && (
                <p className="text-ink-soft text-xs">
                  Esta viagem leva {cabe.toLocaleString('pt-BR')}: é o que cabe
                  {cabe === escolhido.capacity_efetiva ? ' no veículo' : ' no depósito'}.
                </p>
              )}

              {impedimentoDoLevar && <p className="text-rust text-xs font-bold">{impedimentoDoLevar}</p>}

              <button
                disabled={impedimentoDoLevar !== null || cabe <= 0 || ocupado}
                onClick={() =>
                  escolhido &&
                  void fazer(
                    async () => {
                      const v = await api.depositar(escolhido.id, { [recurso]: cabe })
                      setACaminho({ qtd: cabe, chega: v.arrives_at })
                    },
                    () => setVeiculo(null),
                  )
                }
                data-vender-despachar
                className="bg-rust text-sand-light hover:bg-rust-bright w-full py-2 text-sm font-bold disabled:cursor-not-allowed disabled:opacity-40"
              >
                Despachar {cabe > 0 ? cabe.toLocaleString('pt-BR') : ''}
              </button>
            </>
          )}

          <button onClick={() => setPasso('oque')} className="text-ink-soft hover:text-rust text-xs font-bold">
            ← voltar
          </button>
        </div>
      )}

      {passo === 'preco' && (
        <div className="space-y-4">
          <p className="text-ink-soft text-sm">
            {vendavel.toLocaleString('pt-BR')} de {nomeRecurso(recurso)} no depósito, prontos para vender.
            {taxaBps > 0 && ` A taxa do Mercado é de ${(taxaBps / 100).toLocaleString('pt-BR')}%, paga por quem vende.`}
          </p>

          <section>
            <div className="text-rust eyebrow">Vender agora</div>
            {compradores.length === 0 ? (
              <p className="text-ink-soft mt-1 text-sm">Ninguém anunciou compra deste recurso agora.</p>
            ) : (
              <div className="mt-1 space-y-2">
                {compradores.map((o: OfertaGlobal) => {
                  const n = Math.min(vendavel, o.qty)
                  return (
                    <div key={o.id} className="border-rust/15 flex items-center justify-between gap-2 border p-2">
                      <div className="text-sm">
                        <span className="text-ink font-bold">{o.colonia ?? 'Colono'}</span>
                        <span className="text-ink-soft">
                          {' '}
                          compra {o.qty.toLocaleString('pt-BR')} a {fert(o.price_micro)} F$
                        </span>
                      </div>
                      <button
                        disabled={n <= 0 || ocupado}
                        onClick={() =>
                          void fazer(
                            () => api.executarOferta(o.id, n),
                            () => {
                              setFeito(
                                `Vendido: ${n.toLocaleString('pt-BR')} de ${nomeRecurso(recurso)} a ${fert(o.price_micro)} F$. Você recebe cerca de ${fert(liquido(n * o.price_micro), 2)} F$.`,
                              )
                              setPasso('feito')
                            },
                          )
                        }
                        data-vender-executar
                        className="bg-rust text-sand-light hover:bg-rust-bright shrink-0 px-3 py-1.5 text-xs font-bold disabled:opacity-40"
                      >
                        Vender {n.toLocaleString('pt-BR')}
                      </button>
                    </div>
                  )
                })}
              </div>
            )}
          </section>

          <section>
            <div className="text-rust eyebrow">Ou anunciar pelo seu preço</div>
            <input
              aria-label="Preço por unidade"
              value={preco}
              onChange={(e) => setPreco(e.target.value)}
              inputMode="decimal"
              placeholder={tipo ? `Referência: ${fert(tipo.preco_base_micro)}` : 'Preço por unidade, em Fert$'}
              data-vender-preco
              className="border-rust/30 bg-sand text-ink mt-1 w-full border px-2 py-1.5 text-sm"
            />
            {precoMicro > 0 && vendavel > 0 && (
              <p className="text-ink-soft mt-1 text-xs">
                {vendavel.toLocaleString('pt-BR')} × {fert(precoMicro)} ={' '}
                <strong className="text-ink">{fert(vendavel * precoMicro, 2)} F$</strong>; depois da taxa, cerca de{' '}
                {fert(liquido(vendavel * precoMicro), 2)} F$. A oferta fica na vitrine até alguém executá-la.
              </p>
            )}
            <button
              disabled={!(precoMicro > 0) || vendavel <= 0 || ocupado}
              onClick={() =>
                void fazer(
                  () => api.ordenar({ side: 'sell', resource_type: recurso, qty: vendavel, price_micro: precoMicro }),
                  () => {
                    setFeito(
                      `Anunciado: ${vendavel.toLocaleString('pt-BR')} de ${nomeRecurso(recurso)} a ${fert(precoMicro)} F$. A oferta aparece em "Ofertas globais" e você pode cancelá-la no Mercado Central.`,
                    )
                    setPasso('feito')
                  },
                )
              }
              data-vender-anunciar
              className="bg-rust text-sand-light hover:bg-rust-bright mt-2 w-full py-2 text-sm font-bold disabled:cursor-not-allowed disabled:opacity-40"
            >
              Anunciar a venda
            </button>
          </section>

          <button onClick={() => setPasso('oque')} className="text-ink-soft hover:text-rust text-xs font-bold">
            ← voltar
          </button>
        </div>
      )}

      {passo === 'feito' && (
        <div className="space-y-3">
          <p className="text-ink text-sm" data-vender-feito>
            {feito}
          </p>
          <button
            onClick={aoFechar}
            className="bg-rust text-sand-light hover:bg-rust-bright w-full py-2 text-sm font-bold"
          >
            Fechar
          </button>
        </div>
      )}

      {erro && <p className="text-rust mt-3 text-xs font-bold">{erro}</p>}
    </Popup>
  )
}
