import { useEffect, useState } from 'react'
import { api, ApiError } from '../api/client'
import type { Extrato as ExtratoResposta } from '../api/client'
import { Popup } from './Popup'
import { dataHumana } from './recursos'

/**
 * Rótulos por tipo de lançamento. O que não estiver aqui cai no `humano()` — o slug com
 * sublinhado virando espaço — em vez de aparecer cru feito `venda_mercado` na tela do colono.
 */
const NOMES: Record<string, string> = {
  saldo_inicial: 'Saldo inicial',
  kit_inicial: 'Kit inicial',
  estacionamento: 'Estacionamento no Pátio',
  custo_ocupacao: 'Ocupação de zona',
  custo_upgrade_zona: 'Upgrade de zona',
  manutencao_territorial: 'Manutenção territorial',
  salario_conciliador: 'Salário de conciliador',
  bonus_conciliador: 'Bônus de conciliador',
  compra_veiculo: 'Compra de veículo',
  venda_veiculo: 'Venda de veículo',
  frete_publico: 'Frete público',
  compra_niobio: 'Compra de Nióbio Alienígena',
  recompensa_missao: 'Recompensa de missão',
  compra_mercado: 'Compra no Mercado',
  venda_mercado: 'Venda no Mercado',
  ajuste_admin: 'Ajuste administrativo',
}

function humano(tipo: string): string {
  return NOMES[tipo] ?? tipo.charAt(0).toUpperCase() + tipo.slice(1).replace(/_/g, ' ')
}

/**
 * O extrato bancário do colono (D-94) — só Fert$, nunca recurso. Abre ao clicar no valor ou na
 * palavra "Fert$" do card do HUD; antes não havia nenhuma tela para o colono ver de onde veio ou
 * para onde foi o próprio saldo, só o admin tinha esse extrato (`PainelController`).
 */
export function Extrato({ aoFechar }: { aoFechar: () => void }) {
  const [dados, setDados] = useState<ExtratoResposta | null>(null)
  const [pagina, setPagina] = useState(1)
  const [erro, setErro] = useState<string | null>(null)

  useEffect(() => {
    let vivo = true
    setDados(null)
    setErro(null)
    api
      .extrato(pagina)
      .then((d) => vivo && setDados(d))
      .catch((e: unknown) => vivo && setErro(e instanceof ApiError ? e.message : 'Falha ao carregar o extrato.'))
    return () => {
      vivo = false
    }
  }, [pagina])

  return (
    <Popup titulo="Extrato bancário" eyebrow="Só Fert$ — não o estoque de recursos" aoFechar={aoFechar}>
      {erro && <p className="text-rust text-sm">{erro}</p>}

      {!erro && !dados && <p className="text-ink-soft text-sm">Carregando…</p>}

      {dados && dados.lancamentos.length === 0 && (
        <p className="text-ink-soft text-sm">Nenhum lançamento em Fert$ ainda.</p>
      )}

      {dados && dados.lancamentos.length > 0 && (
        <div data-extrato>
          <ul className="max-h-96 space-y-1 overflow-y-auto">
            {dados.lancamentos.map((l) => (
              <li
                key={l.id}
                className="border-rust/10 flex items-center justify-between border-b py-1.5 last:border-0"
                data-lancamento={l.id}
              >
                <div>
                  <div className="text-ink text-sm">{humano(l.tipo)}</div>
                  <div className="text-ink-soft/70 text-xs">{dataHumana(l.quando)}</div>
                </div>
                <span
                  className={`font-bold tabular-nums ${l.fert >= 0 ? 'text-ink' : 'text-rust'}`}
                >
                  {l.fert >= 0 ? '+' : ''}
                  {l.fert.toLocaleString('pt-BR', { minimumFractionDigits: 2 })}
                </span>
              </li>
            ))}
          </ul>

          {dados.ultima_pagina > 1 && (
            <div className="mt-3 flex items-center justify-between text-xs">
              <button
                onClick={() => setPagina((p) => Math.max(1, p - 1))}
                disabled={pagina <= 1}
                className="text-rust hover:text-rust-bright disabled:text-ink-soft/40 font-bold"
              >
                ← anterior
              </button>
              <span className="text-ink-soft">
                página {dados.pagina_atual} de {dados.ultima_pagina} · {dados.total} lançamentos
              </span>
              <button
                onClick={() => setPagina((p) => Math.min(dados.ultima_pagina, p + 1))}
                disabled={pagina >= dados.ultima_pagina}
                className="text-rust hover:text-rust-bright disabled:text-ink-soft/40 font-bold"
              >
                próxima →
              </button>
            </div>
          )}
        </div>
      )}
    </Popup>
  )
}
