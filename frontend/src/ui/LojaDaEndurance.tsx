import { useEffect, useState } from 'react'
import { carregarArte } from '../game/arte'
import type { Arte } from '../game/arte'
import type { PecaDaEndurance } from '../api/client'

const ROTULO_CAMADA: Record<PecaDaEndurance['camada'], string> = {
  comum: 'Comum',
  reputacao_1: 'Reputação I',
  reputacao_2: 'Reputação II',
  unica: 'Única',
}

/**
 * A Loja de Peças da Endurance (§05, D-132) — 8 seções do casco, 4 camadas cada, ligadas ao Marco.
 *
 * O bônus é desconto de tributo (arbitragem do usuário, sem base no GDD para o número): cada peça
 * soma um pouco, até o teto que `dados.teto_desconto_pct` publica — nunca escondido, a tela mostra
 * o desconto atual da colônia e o teto lado a lado.
 */
export function LojaDaEndurance({
  dados,
  aoComprar,
  aoFechar,
}: {
  dados: {
    meu_marco: number
    meu_desconto_pct: number
    teto_desconto_pct: number
    pecas: PecaDaEndurance[]
  }
  aoComprar: (chave: string) => Promise<void>
  aoFechar: () => void
}) {
  const [arte, setArte] = useState<Arte>({})
  const [comprando, setComprando] = useState<string | null>(null)
  const [erro, setErro] = useState<string | null>(null)

  useEffect(() => {
    void carregarArte().then(setArte)
  }, [])

  const porSecao = new Map<string, { nome: string; pecas: PecaDaEndurance[] }>()

  for (const p of dados.pecas) {
    const grupo = porSecao.get(p.secao) ?? { nome: p.secao_nome, pecas: [] }
    grupo.pecas.push(p)
    porSecao.set(p.secao, grupo)
  }

  async function comprar(chave: string) {
    setComprando(chave)
    setErro(null)
    try {
      await aoComprar(chave)
    } catch (e) {
      setErro(e instanceof Error ? e.message : 'Falha na compra.')
    } finally {
      setComprando(null)
    }
  }

  return (
    <div className="space-y-5" data-tela="loja-da-endurance">
      <div className="border-rust/20 bg-sand flex flex-wrap items-baseline justify-between gap-2 border p-3">
        <p className="text-ink-soft text-sm">
          Seu marco: <strong className="text-ink">{dados.meu_marco}</strong> · Desconto de tributo:{' '}
          <strong className="text-ink">{dados.meu_desconto_pct.toFixed(1)}%</strong> de um teto de{' '}
          {dados.teto_desconto_pct.toFixed(0)}%
        </p>
        <button onClick={aoFechar} className="text-rust hover:text-rust-bright text-sm" data-fechar-loja>
          ‹ Voltar ao mapa
        </button>
      </div>

      {erro && <p className="text-rust text-sm font-bold">{erro}</p>}

      {[...porSecao.entries()].map(([secaoChave, grupo]) => {
        const urls = arte[`endurance:secao:${secaoChave}`]

        return (
          <section key={secaoChave} className="border-rust/20 border p-4" data-secao-loja={secaoChave}>
            <div className="flex items-center gap-3">
              {urls && <img src={urls.pequena} alt="" className="h-14 w-14 object-contain" />}
              <h3 className="text-ink text-lg font-black">{grupo.nome}</h3>
            </div>

            <div className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
              {grupo.pecas.map((p) => (
                <div key={p.chave} className="painel bg-sand p-3" data-peca={p.chave}>
                  <div className="text-rust eyebrow text-xs">{ROTULO_CAMADA[p.camada]}</div>
                  <p className="text-ink-soft mt-1 text-xs">
                    Marco {p.marco_minimo} · {p.preco_fert.toLocaleString('pt-BR')} Fert$
                  </p>
                  <p className="text-ink-soft mt-1 text-xs">
                    +{p.desconto_tributo_pct.toFixed(1)}% de desconto de tributo
                  </p>

                  {p.estado === 'possuida' && (
                    <p className="text-ember mt-2 text-xs font-bold">Você tem esta peça</p>
                  )}
                  {p.estado === 'esgotada' && (
                    <p className="text-rust mt-2 text-xs font-bold">Esgotada — outra colônia já a tem</p>
                  )}
                  {p.estado === 'bloqueada' && (
                    <p className="text-ink-soft mt-2 text-xs">Exige o marco {p.marco_minimo}</p>
                  )}
                  {p.estado === 'disponivel' && (
                    <button
                      disabled={comprando === p.chave}
                      onClick={() => void comprar(p.chave)}
                      className="bg-rust text-sand-light hover:bg-rust-bright mt-2 w-full py-1.5 text-xs font-bold disabled:cursor-not-allowed disabled:opacity-40"
                    >
                      {comprando === p.chave ? 'Comprando…' : 'Comprar'}
                    </button>
                  )}
                </div>
              ))}
            </div>
          </section>
        )
      })}
    </div>
  )
}
