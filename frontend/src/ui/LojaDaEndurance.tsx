import { useEffect, useState } from 'react'
import { carregarArte } from '../game/arte'
import type { Arte } from '../game/arte'
import type { EfeitoDoItemDaEndurance, ItemDaEndurance } from '../api/client'

const ROTULO_TIPO: Record<ItemDaEndurance['tipo'], string> = {
  comum: 'Comum',
  raro: 'Raro',
  unico: 'Único',
}

/** Um efeito em texto legível — o mesmo vocabulário que `admin/endurance.blade.php` já rotula. */
function rotuloEfeito(e: EfeitoDoItemDaEndurance): string {
  const pct = (e.valor_bps / 100).toFixed(1)
  switch (e.tipo_efeito) {
    case 'desconto_tributo':
      return `+${pct}% de desconto de tributo`
    case 'producao_bonus':
      return `+${pct}% de produção${e.alvo && e.alvo !== 'global' ? ` em ${e.alvo.replace(/_/g, ' ')}` : ''}`
    case 'velocidade_veiculo':
      return `+${pct}% de velocidade${e.alvo && e.alvo !== 'todos' ? ` de ${e.alvo.replace(/_/g, ' ')}` : ' de veículo'}`
    case 'capacidade_veiculo':
      return `+${pct}% de capacidade${e.alvo && e.alvo !== 'todos' ? ` de ${e.alvo.replace(/_/g, ' ')}` : ' de veículo'}`
    case 'drone_raio':
      return `+${pct}% de raio de vigia do Drone`
    case 'drone_bateria':
      return `+${pct}% de duração da bateria do Drone`
    default:
      return `${e.tipo_efeito}: +${pct}%`
  }
}

/**
 * A Loja de Peças de UMA seção da Endurance (§05, D-135) — catálogo dinâmico, efeitos empilháveis.
 * Substitui a versão do D-132/D-133 (8 grupos de 4 camadas fixas, um efeito só: desconto de
 * tributo) — ver D-134 (o usuário rejeitou aquilo) e D-135 (a reconstrução).
 */
export function LojaDaEndurance({
  secao,
  nomeDaSecao,
  dados,
  aoComprar,
  aoFechar,
}: {
  secao: string
  nomeDaSecao: string
  dados: { meu_marco: number; itens: ItemDaEndurance[] }
  aoComprar: (itemKey: string) => Promise<void>
  aoFechar: () => void
}) {
  const [arte, setArte] = useState<Arte>({})
  const [comprando, setComprando] = useState<string | null>(null)
  const [erro, setErro] = useState<string | null>(null)

  useEffect(() => {
    void carregarArte().then(setArte)
  }, [])

  const urls = arte[`endurance:secao:${secao}`]

  async function comprar(itemKey: string) {
    setComprando(itemKey)
    setErro(null)
    try {
      await aoComprar(itemKey)
    } catch (e) {
      setErro(e instanceof Error ? e.message : 'Falha na compra.')
    } finally {
      setComprando(null)
    }
  }

  return (
    <div className="space-y-5" data-tela="loja-da-endurance" data-secao-loja={secao}>
      <div className="border-rust/20 bg-sand flex flex-wrap items-center justify-between gap-2 border p-3">
        <div className="flex items-center gap-3">
          {urls && <img src={urls.pequena} alt="" className="h-12 w-12 object-contain" />}
          <div>
            <h3 className="text-ink text-lg font-black">{nomeDaSecao}</h3>
            <p className="text-ink-soft text-xs">Seu marco: {dados.meu_marco}</p>
          </div>
        </div>
        <button onClick={aoFechar} className="text-rust hover:text-rust-bright text-sm" data-fechar-loja>
          ‹ Voltar ao mapa
        </button>
      </div>

      {erro && <p className="text-rust text-sm font-bold">{erro}</p>}

      <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
        {dados.itens.map((item) => {
          const podeComprar = item.estado === 'disponivel' && item.estoque_livre > 0

          return (
            <div key={item.item_key} className="painel bg-sand p-3" data-item={item.item_key}>
              <div className="flex items-center justify-between gap-2">
                <div className="text-rust eyebrow text-xs">{ROTULO_TIPO[item.tipo]}</div>
                <div className="text-ink-soft text-xs">
                  {item.estoque_livre}/{item.quantidade_total}
                </div>
              </div>

              <p className="text-ink mt-1 text-sm font-bold">{item.nome}</p>

              {item.descricao && <p className="text-ink-soft mt-1 text-xs">{item.descricao}</p>}

              <p className="text-ink-soft mt-1 text-xs">
                {item.marco_minimo ? `Marco ${item.marco_minimo} · ` : ''}
                {item.preco_fert.toLocaleString('pt-BR')} Fert$
              </p>

              {item.efeitos.length > 0 && (
                <ul className="mt-2 space-y-0.5">
                  {item.efeitos.map((e, i) => (
                    <li key={i} className="text-ember text-xs font-bold">
                      {rotuloEfeito(e)}
                    </li>
                  ))}
                </ul>
              )}

              {item.vendavel_em_leilao && (
                <p className="text-ink-soft mt-2 text-[0.65rem]">Pode ser vendido no Mercado Central em Leilões</p>
              )}

              {item.possuo > 0 && (
                <p className="text-ember mt-2 text-xs font-bold">Você tem {item.possuo}</p>
              )}

              {item.estado === 'bloqueado' && (
                <p className="text-ink-soft mt-2 text-xs">Exige o marco {item.marco_minimo}</p>
              )}
              {item.estado === 'esgotado' && item.possuo === 0 && (
                <p className="text-rust mt-2 text-xs font-bold">Esgotado</p>
              )}

              {podeComprar && (
                <button
                  disabled={comprando === item.item_key}
                  onClick={() => void comprar(item.item_key)}
                  className="bg-rust text-sand-light hover:bg-rust-bright mt-2 w-full py-1.5 text-xs font-bold disabled:cursor-not-allowed disabled:opacity-40"
                >
                  {comprando === item.item_key ? 'Comprando…' : 'Comprar'}
                </button>
              )}
            </div>
          )
        })}

        {dados.itens.length === 0 && (
          <p className="text-ink-soft text-sm">Nenhum item cadastrado nesta seção ainda.</p>
        )}
      </div>
    </div>
  )
}
