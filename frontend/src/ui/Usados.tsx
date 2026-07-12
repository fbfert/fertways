import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from '../api/client'
import type { AnuncioUsado, RegistroVeiculo } from '../api/client'
import { nomeVeiculo } from './recursos'

/**
 * Mercado de veículos usados — 6ª aba do Mercado (D-60, fatia 3; GDD §16.4).
 *
 * **Com escrow do Ministério**, e a tela precisa dizer isso, porque em todo o resto do Mercado a
 * regra é outra: o que está na colônia se negocia com risco de calote (D-58). Aqui não — o
 * Ministério é o cartório da placa, retém os Fert$, e o vendedor **só recebe quando o veículo chega**
 * à colônia do comprador. É a diferença que justifica um Caminhão de 300 F$ mudar de mãos.
 *
 * E o **estado de conservação vai no anúncio**, porque o §16.4 diz que ele "afeta diretamente o
 * preço de venda no mercado de usados". Sem ele, compra-se às cegas.
 */
export function Usados() {
  const [anuncios, setAnuncios] = useState<AnuncioUsado[] | null>(null)
  const [meusVeiculos, setMeusVeiculos] = useState<RegistroVeiculo[]>([])
  const [erro, setErro] = useState<string | null>(null)
  const [recibo, setRecibo] = useState<string | null>(null)
  const [ocupado, setOcupado] = useState(false)

  const [veiculoId, setVeiculoId] = useState<number | ''>('')
  const [preco, setPreco] = useState('')

  const carregar = useCallback(async () => {
    try {
      const [vitrine, ministerio] = await Promise.all([api.usados(), api.transportes()])
      setAnuncios(vitrine.anuncios)
      // Só o que está no pátio e ainda não foi anunciado pode ir à venda.
      setMeusVeiculos(ministerio.veiculos.filter((v) => v.status === 'ocioso' && !v.anunciado))
    } catch (e) {
      setErro(e instanceof ApiError ? e.message : 'Falha ao carregar o mercado de usados.')
    }
  }, [])

  useEffect(() => {
    void carregar()
  }, [carregar])

  async function agir(acao: () => Promise<string>) {
    setOcupado(true)
    setErro(null)
    setRecibo(null)

    try {
      setRecibo(await acao())
      await carregar()
    } catch (e) {
      setErro(e instanceof ApiError ? e.message : 'Falha na operação.')
    } finally {
      setOcupado(false)
    }
  }

  if (erro && !anuncios) return <p className="text-rust mt-4 text-sm font-bold">{erro}</p>
  if (!anuncios) return <p className="text-ink-soft mt-4 text-sm">Carregando…</p>

  const escolhido = meusVeiculos.find((v) => v.id === veiculoId)
  const teto = escolhido?.teto_de_revenda_fert ?? null
  const acimaDoTeto = teto !== null && Number(preco) > teto
  const podeAnunciar = escolhido && Number(preco) > 0 && !acimaDoTeto && !ocupado

  return (
    <div className="mt-4 space-y-5" data-aba="usados">
      <p className="text-ink-soft/80 border-rust/20 bg-sand border-l-2 p-2 text-xs">
        Aqui o <strong>Ministério é o cartório</strong>: ele retém os Fert$ do comprador, o veículo
        dirige-se sozinho até a colônia dele, e o <strong>vendedor só recebe na chegada</strong>. Não
        há calote — ao contrário do que acontece com recursos entre colonos.
      </p>

      {recibo && <p className="text-rust text-sm font-bold">{recibo}</p>}
      {erro && <p className="text-rust text-sm font-bold">{erro}</p>}

      {/* ---------------------------------------------------------------- anunciar */}
      <section>
        <h3 className="text-ink font-black">Vender um veículo seu</h3>

        {meusVeiculos.length === 0 ? (
          <p className="text-ink-soft/70 mt-2 text-xs">
            Nenhum veículo no pátio para anunciar — os que estão em rota, e os já anunciados, não
            contam.
          </p>
        ) : (
          <div className="mt-2 flex flex-wrap items-end gap-2">
            <label className="text-ink-soft text-xs">
              Veículo
              <select
                value={veiculoId}
                onChange={(e) => setVeiculoId(e.target.value ? Number(e.target.value) : '')}
                data-usado-veiculo
                className="border-rust/30 bg-sand-light text-ink mt-1 block border px-2 py-1 text-sm"
              >
                <option value="">escolha…</option>
                {meusVeiculos.map((v) => (
                  <option key={v.id} value={v.id}>
                    {v.placa} — {nomeVeiculo(v.tipo)} ({v.conservacao.toFixed(0)}%)
                  </option>
                ))}
              </select>
            </label>

            <label className="text-ink-soft text-xs">
              Preço (F$)
              <input
                value={preco}
                onChange={(e) => setPreco(e.target.value)}
                inputMode="decimal"
                data-usado-preco
                className="border-rust/30 bg-sand-light text-ink mt-1 block w-28 border px-2 py-1 text-sm"
              />
            </label>

            <button
              onClick={() =>
                agir(async () => {
                  await api.anunciarUsado({ vehicle_id: Number(veiculoId), preco_fert: Number(preco) })
                  setVeiculoId('')
                  setPreco('')
                  return 'Anunciado. Ele continua seu e no pátio até alguém comprar.'
                })
              }
              disabled={!podeAnunciar}
              data-anunciar-usado
              className="bg-rust text-sand-light hover:bg-rust-bright disabled:bg-ink-soft/30 px-4 py-1.5 text-sm font-bold disabled:cursor-not-allowed"
            >
              Anunciar
            </button>
          </div>
        )}

        {/* O teto só existe para o Caminhão, e o jogador precisa saber de onde ele vem. */}
        {escolhido && teto !== null && (
          <p className={`mt-2 text-xs ${acimaDoTeto ? 'text-rust font-bold' : 'text-ink-soft/70'}`}>
            Teto de revenda: <strong>{teto} F$</strong> — ele é o preço de fábrica corrigido pelo
            desgaste, e cai a cada manutenção (§16.4).
          </p>
        )}
        {escolhido && teto === null && (
          <p className="text-ink-soft/70 mt-2 text-xs">
            O Furgão não tem teto de revenda: o Ministério não o vende novo, logo ele não tem preço de
            fábrica. Peça o que quiser.
          </p>
        )}
      </section>

      {/* ---------------------------------------------------------------- a vitrine */}
      <section>
        <h3 className="text-ink font-black">À venda no planeta</h3>

        {anuncios.length === 0 ? (
          <p className="text-ink-soft/70 mt-2 text-xs">Nenhum veículo à venda no momento.</p>
        ) : (
          <ul className="mt-2 space-y-2" data-anuncios={anuncios.length}>
            {anuncios.map((a) => (
              <li key={a.id} className="border-rust/20 bg-sand border p-3" data-anuncio={a.id}>
                <div className="flex items-center gap-3">
                  <span className="bg-ink text-sand-light shrink-0 px-2 py-1 font-mono text-xs font-bold">
                    {a.veiculo.placa}
                  </span>
                  <div className="min-w-0 flex-1">
                    <div className="text-ink text-sm font-bold">{nomeVeiculo(a.veiculo.tipo)}</div>
                    <div className="text-ink-soft/70 text-xs">
                      {a.meu ? 'seu anúncio' : `de ${a.vendedor ?? 'colono'}`} ·{' '}
                      {a.veiculo.conservacao.toFixed(0)}% de conservação · anda a{' '}
                      {a.veiculo.desempenho.toFixed(0)}% e carrega{' '}
                      {a.veiculo.capacidade_efetiva.toLocaleString('pt-BR')}
                    </div>
                  </div>
                  <div className="text-ink shrink-0 text-lg font-black">{a.preco_fert} F$</div>
                </div>

                <div className="mt-2 flex justify-end">
                  {a.meu ? (
                    <button
                      onClick={() =>
                        agir(async () => {
                          await api.cancelarAnuncio(a.id)
                          return 'Anúncio retirado.'
                        })
                      }
                      disabled={ocupado}
                      data-cancelar-anuncio={a.id}
                      className="text-ink-soft/60 hover:text-rust text-xs"
                    >
                      Retirar anúncio
                    </button>
                  ) : (
                    <button
                      onClick={() =>
                        agir(async () => {
                          const { comprado } = await api.comprarUsado(a.id)
                          return `${comprado.placa} é seu. Ele vem dirigindo até a sua colônia — o vendedor só recebe quando chegar.`
                        })
                      }
                      disabled={ocupado}
                      data-comprar-usado={a.id}
                      className="bg-rust text-sand-light hover:bg-rust-bright disabled:bg-ink-soft/30 px-4 py-1.5 text-xs font-bold disabled:cursor-not-allowed"
                    >
                      Comprar por {a.preco_fert} F$
                    </button>
                  )}
                </div>
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  )
}
