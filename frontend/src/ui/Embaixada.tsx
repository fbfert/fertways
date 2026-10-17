import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from '../api/client'
import type { EstadoEmbaixada } from '../api/client'
import { dataHumana, fert } from './recursos'
import { Botao } from './sistema'

/**
 * A Embaixada Interplanetária, slot 9 da Capital (D-289).
 *
 * O GDD reserva o slot e não diz o que ele faz. Aqui ele é a relação formal com os planetas do
 * Espaçoporto: a credencial comercial, paga e com prazo, que corta o imposto da carga para aquele
 * planeta, nas duas pernas. A conta é de volume, e a tela a mostra antes de cobrar.
 */
export function Embaixada() {
  const [estado, setEstado] = useState<EstadoEmbaixada | null>(null)
  const [erro, setErro] = useState<string | null>(null)
  const [aviso, setAviso] = useState<string | null>(null)
  const [emitindo, setEmitindo] = useState<string | null>(null)

  const carregar = useCallback(async () => {
    try {
      setEstado(await api.embaixada())
      setErro(null)
    } catch (e) {
      setErro(e instanceof ApiError ? e.message : 'Não foi possível abrir a Embaixada.')
    }
  }, [])

  useEffect(() => {
    void carregar()
  }, [carregar])

  async function emitir(chave: string) {
    setEmitindo(chave)
    setAviso(null)
    try {
      const r = await api.emitirCredencial(chave)
      setAviso(`Credencial emitida: vale até ${dataHumana(r.valida_ate)}.`)
      await carregar()
    } catch (e) {
      setAviso(e instanceof ApiError ? e.message : 'A Embaixada não emitiu a credencial.')
    } finally {
      setEmitindo(null)
    }
  }

  if (erro) return <p className="text-rust mt-5 text-sm">{erro}</p>
  if (!estado) return <p className="text-ink-soft mt-5 text-sm">Abrindo a Embaixada…</p>

  return (
    <div className="mt-5 space-y-5" data-tela="embaixada">
      <section className="border-rust/20 bg-sand border p-4">
        <div className="text-rust eyebrow">Credencial comercial</div>
        <p className="text-ink-soft mt-1 text-sm leading-relaxed">
          Cada planeta do Espaçoporto reconhece, por <b>{estado.dias} dias</b>, a colônia que se credencia
          na Embaixada, e cobra menos imposto da carga dela, <b>na ida e na volta</b>. O preço vai ao
          Tesouro. Vale a pena para quem leva carga àquele planeta com frequência; a renovação abre nos
          últimos {estado.renova_nos_ultimos_dias} dias e soma ao prazo que resta.
        </p>
      </section>

      {aviso && <p className="text-ink text-sm">{aviso}</p>}

      <ul className="space-y-3">
        {estado.planetas.map((p) => {
          const vigente = p.credencial !== null
          const podeEmitir = p.preco_micro !== null && p.portao.pode && (!vigente || p.credencial?.pode_renovar)

          return (
            <li key={p.chave} className="border-rust/20 border p-3" data-planeta-embaixada={p.chave}>
              <div className="flex items-start justify-between gap-3">
                <div>
                  <div className="text-ink font-black">{p.nome}</div>
                  <div className="text-ink-soft text-xs">
                    {p.preco_micro === null
                      ? 'Não emite credencial.'
                      : `${fert(p.preco_micro, 2)} Fert$ · ${(p.desconto_bps / 100).toLocaleString('pt-BR')}% a menos de imposto`}
                  </div>
                </div>
                {vigente ? (
                  <span className="eyebrow bg-rust-bright text-sand-light shrink-0 px-2 py-1 text-xs">Credenciada</span>
                ) : (
                  <span className="eyebrow border-rust/25 text-ink-soft shrink-0 border px-2 py-1 text-xs">Sem credencial</span>
                )}
              </div>

              {vigente && p.credencial && (
                <p className="text-ink-soft mt-2 text-xs">
                  Vale até <b className="text-ink">{dataHumana(p.credencial.valida_ate)}</b>, com{' '}
                  {(p.credencial.desconto_bps / 100).toLocaleString('pt-BR')}% de desconto no imposto.
                </p>
              )}

              {!p.portao.pode && p.preco_micro !== null && <p className="text-rust mt-2 text-xs">{p.portao.falta}</p>}

              {podeEmitir && (
                <div className="mt-3">
                  <Botao tamanho="pequeno" carregando={emitindo === p.chave} onClick={() => void emitir(p.chave)}>
                    {vigente ? 'Renovar credencial' : 'Credenciar a colônia'}
                  </Botao>
                </div>
              )}
            </li>
          )
        })}
      </ul>
    </div>
  )
}
