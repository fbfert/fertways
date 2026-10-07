import { useState } from 'react'
import type { EscavacaoDaSecao } from '../api/client'
import { nomeRecurso, prazoHumano, segundosRestantes } from './recursos'

const ROTULO_TIPO = { comum: 'comum', raro: 'rara', unico: 'ÚNICA' } as const

/**
 * A escavação de uma seção da Endurance (D-249) — o §11 chama a nave de "área de
 * escavação/desmontagem controlada" e "origem de peças", e até aqui o único nascia na compra.
 *
 * ⚠️ **O que a equipe vai achar nunca aparece aqui.** A peça já está reservada no servidor desde o
 * início, mas o retorno só vale alguma coisa se for surpresa. O que aparece é o que dá vontade de ir:
 * quantas peças ainda há para achar e se uma delas é única.
 *
 * Fechada pelo operador, o bloco não aparece: oferecer um botão que nunca funciona ensina a ignorar
 * a tela inteira (a mesma régua do aviso que não se pode atender, D-211).
 */
export function EscavacaoDaEndurance({
  secao,
  nomeDaSecao,
  escavacao,
  aoEscavar,
}: {
  secao: string
  nomeDaSecao: string
  escavacao: EscavacaoDaSecao
  aoEscavar: () => Promise<void>
}) {
  const [indo, setIndo] = useState(false)
  const [erro, setErro] = useState<string | null>(null)

  if (!escavacao.ligada) return null

  const fora = escavacao.em_andamento
  const aqui = fora?.secao === secao
  const custo = [
    ...(escavacao.custo_fert > 0 ? [`${escavacao.custo_fert.toLocaleString('pt-BR')} Fert$`] : []),
    ...Object.entries(escavacao.custo).map(([r, q]) => `${q.toLocaleString('pt-BR')} ${nomeRecurso(r)}`),
  ]

  async function escavar() {
    setIndo(true)
    setErro(null)
    try {
      await aoEscavar()
    } catch (e) {
      setErro(e instanceof Error ? e.message : 'A equipe não conseguiu sair.')
    } finally {
      setIndo(false)
    }
  }

  return (
    <div className="painel bg-sand p-3" data-escavacao={secao}>
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <div className="text-rust eyebrow text-xs">Escavação</div>
        <div className="text-ink-soft text-xs" data-escavacao-a-achar>
          {escavacao.a_achar === 0
            ? 'nada mais a achar aqui, por enquanto'
            : `${escavacao.a_achar} peça${escavacao.a_achar > 1 ? 's' : ''} ainda enterrada${escavacao.a_achar > 1 ? 's' : ''}`}
          {escavacao.tem_unico && (
            <span className="text-ember font-black" title="uma delas é única">
              {' '}
              ◈ uma é única
            </span>
          )}
        </div>
      </div>

      {aqui && fora ? (
        <p className="text-ink mt-2 text-sm font-bold" data-escavacao-em-andamento>
          Sua equipe está escavando {nomeDaSecao}. Volta em {prazoHumano(segundosRestantes(fora.termina_em))}.
        </p>
      ) : fora ? (
        <p className="text-ink-soft mt-2 text-sm">
          Sua equipe está em {fora.secao_nome} — volta em {prazoHumano(segundosRestantes(fora.termina_em))}. Uma
          escavação de cada vez.
        </p>
      ) : (
        <div className="mt-2 flex flex-wrap items-center gap-3">
          <button
            onClick={() => void escavar()}
            disabled={indo || escavacao.a_achar === 0}
            className="bg-rust text-sand-light hover:bg-rust-bright px-3 py-1.5 text-sm font-bold disabled:cursor-not-allowed disabled:opacity-50"
            data-escavar={secao}
          >
            {indo ? 'Saindo…' : 'Mandar a equipe escavar'}
          </button>
          <span className="text-ink-soft text-xs">
            {custo.length > 0 ? custo.join(' + ') : 'sem custo'}
            {escavacao.duracao_minutos ? ` · ${prazoHumano(escavacao.duracao_minutos * 60)}` : ''}
          </span>
        </div>
      )}

      {erro && <p className="text-rust mt-2 text-xs font-bold">{erro}</p>}

      {escavacao.ultima_achada && (
        <p className="text-ink-soft mt-2 text-xs" data-escavacao-ultima>
          Da última vez aqui sua equipe achou{' '}
          <b className={escavacao.ultima_achada.tipo === 'unico' ? 'text-ember' : 'text-ink'}>
            {escavacao.ultima_achada.nome}
          </b>{' '}
          (peça {ROTULO_TIPO[escavacao.ultima_achada.tipo]}).
        </p>
      )}
    </div>
  )
}
