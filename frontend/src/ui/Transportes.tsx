import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from '../api/client'
import type { Transportes as TransportesDto } from '../api/client'
import { dataHumana, nomeVeiculo } from './recursos'

/**
 * Ministério dos Transportes (§16) — slot 8 da Capital (D-60).
 *
 * A tela precisa contar três coisas que o jogador não tem como adivinhar:
 *
 *  1. **Só aqui se compra caminhão.** A Central de Transportes dele NÃO fabrica mais nada — e como
 *     o GDD (§17.2) diz que ela "produz Caminhões de Carga", um jogador que leia o documento vai
 *     procurá-la na colônia e não achar. A tela diz onde a fábrica foi parar.
 *  2. **De onde vem o teto de frota.** O número não sai do nada: é o nível da Central dele.
 *  3. **Se leva na hora ou espera.** A prateleira do governo é pública de propósito.
 */
export function Transportes() {
  const [dados, setDados] = useState<TransportesDto | null>(null)
  const [erro, setErro] = useState<string | null>(null)
  const [comprando, setComprando] = useState(false)
  const [recibo, setRecibo] = useState<string | null>(null)

  const carregar = useCallback(async () => {
    try {
      setDados(await api.transportes())
    } catch (e) {
      setErro(e instanceof ApiError ? e.message : 'Falha ao carregar o Ministério dos Transportes.')
    }
  }, [])

  useEffect(() => {
    void carregar()
  }, [carregar])

  async function comprar() {
    setComprando(true)
    setErro(null)
    setRecibo(null)

    try {
      const { comprado } = await api.comprarCaminhao()
      setRecibo(`Caminhão ${comprado.placa} é seu. Ele vem dirigindo da Capital.`)
      await carregar()
    } catch (e) {
      setErro(e instanceof ApiError ? e.message : 'Falha ao comprar.')
    } finally {
      setComprando(false)
    }
  }

  if (erro && !dados) return <p className="text-rust mt-4 text-sm font-bold">{erro}</p>
  if (!dados) return <p className="text-ink-soft mt-4 text-sm">Carregando…</p>

  const { caminhao, frota, veiculos } = dados
  const semVaga = frota.livres < 1
  const semEstoque = caminhao.em_estoque < 1
  const podeComprar = !semVaga && !semEstoque && !comprando

  return (
    <div className="mt-5 space-y-5" data-tela="transportes">
      {/* ---------------------------------------------------------------- a fábrica */}
      <section className="border-rust/20 bg-sand border p-4">
        <div className="flex items-start justify-between gap-4">
          <div>
            <div className="text-rust eyebrow">Fábrica do Governo</div>
            <h3 className="text-ink text-lg font-black">Caminhão de Carga</h3>
            <p className="text-ink-soft mt-1 text-sm">
              {caminhao.capacidade.toLocaleString('pt-BR')} unidades por viagem — cinco vezes o Furgão.
            </p>
          </div>
          <div className="text-right">
            <div className="text-ink text-2xl font-black">{caminhao.preco_fert} F$</div>
            <div className="text-ink-soft/70 text-xs">preço do governo</div>
          </div>
        </div>

        <p className="text-ink-soft/70 mt-3 text-xs">
          Fabricar caminhão é <strong>privativo deste Ministério</strong>. A sua Central de
          Transportes não os produz — ela define quantos veículos você pode ter.
        </p>

        <div className="mt-3 flex items-center gap-4 text-sm">
          <span className="text-ink" data-estoque={caminhao.em_estoque}>
            <strong>{caminhao.em_estoque}</strong> na prateleira
          </span>
          <span className="text-ink-soft/70">
            {caminhao.em_fabricacao} na linha de montagem
          </span>
        </div>

        {recibo && <p className="text-rust mt-3 text-sm font-bold">{recibo}</p>}
        {erro && <p className="text-rust mt-3 text-sm font-bold">{erro}</p>}

        <button
          onClick={comprar}
          disabled={!podeComprar}
          data-comprar-caminhao
          className="bg-rust text-sand-light hover:bg-rust-bright disabled:bg-ink-soft/30 mt-3 px-5 py-2 text-sm font-bold disabled:cursor-not-allowed"
        >
          {comprando ? 'Comprando…' : `Comprar por ${caminhao.preco_fert} F$`}
        </button>

        {/* O botão desabilitado sem explicação é a pior tela possível: diga POR QUE. */}
        {semVaga && (
          <p className="text-ink-soft/80 mt-2 text-xs">
            A sua frota está no teto de {frota.teto}. Suba a Central de Transportes para abrir vaga.
          </p>
        )}
        {semEstoque && !semVaga && (
          <p className="text-ink-soft/80 mt-2 text-xs">
            O governo está sem caminhão pronto. A linha de montagem repõe a prateleira —{' '}
            {caminhao.minutos_fabricacao} min por unidade.
          </p>
        )}
      </section>

      {/* ---------------------------------------------------------------- a frota e as placas */}
      <section>
        <div className="flex items-baseline justify-between">
          <h3 className="text-ink font-black">Registro de Placas</h3>
          <span className="text-ink-soft text-sm" data-vagas={frota.livres}>
            {frota.ocupadas} de {frota.teto} vagas
          </span>
        </div>
        <p className="text-ink-soft/70 mt-1 text-xs">{frota.regra}</p>

        <ul className="mt-3 space-y-2">
          {veiculos.map((v) => (
            <li key={v.id} className="border-rust/20 bg-sand flex items-center gap-3 border p-3">
              <span className="bg-ink text-sand-light shrink-0 px-2 py-1 font-mono text-xs font-bold">
                {v.placa ?? '—'}
              </span>
              <div className="min-w-0 flex-1">
                <div className="text-ink text-sm font-bold">{nomeVeiculo(v.tipo)}</div>
                <div className="text-ink-soft/70 text-xs">
                  {v.status === 'em_rota'
                    ? `em rota — chega ${v.chega_em ? dataHumana(v.chega_em) : 'em breve'}`
                    : v.status}
                </div>
              </div>
            </li>
          ))}
        </ul>
      </section>
    </div>
  )
}
