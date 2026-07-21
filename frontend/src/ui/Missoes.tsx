import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from '../api/client'
import type { Missao } from '../api/client'
import { painelFlutuante } from './painelFlutuante'
import { nomeRecurso } from './recursos'

/**
 * As Missões do §06 (docs/decisoes.md D-78) — tutoria (5, nos 3 primeiros dias), diárias (3 do
 * pool, com a 1 rejeição publicada) e a semanal (qua 07h → ter 23h59).
 *
 * Não há botão de "resgatar": concluir PAGA na hora, e esta tela é só o espelho — barra de
 * progresso, prêmio e prazo. O único verbo aqui é rejeitar uma diária que não combina com o seu dia.
 */

const NOME_CATEGORIA = {
  tutoria: 'Tutoria',
  diaria: 'Diárias',
  semanal: 'Semanal',
  federacao: 'Federação',
  narrativa: 'A Endurance',
} as const

export function Missoes({ aoFechar }: { aoFechar: () => void }) {
  const [missoes, setMissoes] = useState<Missao[]>([])
  const [rejeicoes, setRejeicoes] = useState(0)
  const [erro, setErro] = useState<string | null>(null)

  const carregar = useCallback(async () => {
    try {
      const r = await api.missoes()
      setMissoes(r.missoes)
      setRejeicoes(r.rejeicoes_restantes)
    } catch (e) {
      setErro(e instanceof ApiError ? e.message : 'Falha ao carregar as missões.')
    }
  }, [])

  useEffect(() => {
    void carregar()
  }, [carregar])

  async function rejeitar(id: number) {
    setErro(null)
    try {
      await api.rejeitarMissao(id)
      await carregar()
    } catch (e) {
      setErro(e instanceof ApiError ? e.message : 'Não deu para rejeitar.')
    }
  }

  const grupos = (['tutoria', 'diaria', 'semanal', 'federacao', 'narrativa'] as const)
    .map((c) => ({ categoria: c, itens: missoes.filter((m) => m.categoria === c) }))
    .filter((g) => g.itens.length > 0)

  return (
    <div className={painelFlutuante.grande} data-tela="missoes">
      <div className="border-rust/20 flex items-center justify-between border-b px-3 py-2">
        <span className="text-rust eyebrow">Missões</span>
        <button onClick={aoFechar} data-fechar-missoes className="text-ink-soft hover:text-rust text-xl leading-none">
          ×
        </button>
      </div>

      {erro && <p className="text-rust px-3 py-1 text-xs">{erro}</p>}

      <div className="flex-1 space-y-3 overflow-y-auto p-3">
        {grupos.map((g) => (
          <section key={g.categoria}>
            <h4 className="text-ink eyebrow mb-1">
              {NOME_CATEGORIA[g.categoria]}
              {g.categoria === 'diaria' && (
                <span className="text-ink-soft/70 ml-2 normal-case">
                  {rejeicoes > 0 ? 'pode trocar 1 hoje' : 'a troca de hoje já foi'}
                </span>
              )}
            </h4>
            <div className="space-y-2">
              {g.itens.map((m) => (
                <Carta key={m.id} m={m} podeRejeitar={g.categoria === 'diaria' && rejeicoes > 0} aoRejeitar={rejeitar} />
              ))}
            </div>
          </section>
        ))}
        {grupos.length === 0 && <p className="text-ink-soft/60 text-xs">Nada por aqui — volte amanhã às 07h.</p>}
      </div>
    </div>
  )
}

function Carta({
  m,
  podeRejeitar,
  aoRejeitar,
}: {
  m: Missao
  podeRejeitar: boolean
  aoRejeitar: (id: number) => Promise<void>
}) {
  const feita = m.status === 'concluida'
  const fracao = Math.min(1, m.progresso / m.meta)

  const premio = [
    m.recompensa.fert > 0 ? `${m.recompensa.fert.toLocaleString('pt-BR')} F$` : null,
    m.recompensa.xp > 0 ? `${m.recompensa.xp} XP` : null,
    ...Object.entries(m.recompensa.recursos ?? {}).map(([r, q]) => `${q} ${nomeRecurso(r)}`),
  ]
    .filter(Boolean)
    .join(' + ')

  return (
    <div
      className={`border p-2 ${feita ? 'border-rust/10 bg-sand opacity-60' : 'border-rust/25 bg-sand'}`}
      data-missao={m.id}
      data-status={m.status}
    >
      <div className="flex items-baseline justify-between gap-2">
        <strong className={`text-sm ${feita ? 'text-ink-soft line-through' : 'text-ink'}`}>{m.titulo}</strong>
        <span className="text-rust shrink-0 text-xs font-bold">{premio}</span>
      </div>
      <p className="text-ink-soft/80 mt-0.5 text-xs">{m.descricao}</p>

      <div className="mt-1.5 flex items-center gap-2">
        <div className="bg-sand-light border-rust/20 h-2 flex-1 border">
          <div className="bg-rust h-full" style={{ width: `${fracao * 100}%` }} />
        </div>
        <span className="text-ink-soft text-xs tabular-nums">
          {feita ? 'paga ✓' : `${m.progresso}/${m.meta}`}
        </span>
        {!feita && podeRejeitar && (
          <button
            onClick={() => void aoRejeitar(m.id)}
            data-rejeitar={m.id}
            title="Trocar por outra do pool (1 por dia, §06)"
            className="text-ink-soft hover:text-rust text-xs underline"
          >
            trocar
          </button>
        )}
      </div>
    </div>
  )
}
