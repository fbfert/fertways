import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from '../api/client'
import type { Tesouro as TesouroDto } from '../api/client'
import { dataHumana, fert, nomeRecurso } from './recursos'

/**
 * Central de Tributos / Ministério do Tesouro (slot 2). Só leitura para o colono.
 *
 * O caixa real do governo (D-57): dotação inicial + o tributo que entra (§8.3, §2.1) − o que o admin
 * redistribui. Aqui o colono vê o saldo de cada recurso e de Fert$; quem move é a administração.
 */
export function Tesouro() {
  const [dados, setDados] = useState<TesouroDto | null>(null)
  const [erro, setErro] = useState<string | null>(null)

  const carregar = useCallback(async () => {
    try {
      setDados(await api.tesouro())
    } catch (e) {
      setErro(e instanceof ApiError ? e.message : 'Falha ao carregar o Tesouro.')
    }
  }, [])

  useEffect(() => {
    void carregar()
  }, [carregar])

  if (erro) return <p className="text-rust mt-4 text-sm font-bold">{erro}</p>
  if (!dados) return <p className="text-ink-soft mt-4 text-sm">Carregando…</p>

  return (
    <div className="mt-5 space-y-6">
      <p className="text-ink-soft text-sm">
        A reserva do Ministério do Tesouro. Todo tributo do comércio (§8.3) entra aqui, e o governo
        redistribui parte aos colonos (§2.1). Você vê o saldo; quem move é a administração.
      </p>

      {/* Saldo: Fert$ das vendas + recursos retidos no transporte. */}
      <section>
        <div className="text-rust eyebrow">Saldo do Tesouro</div>
        <div className="border-rust/20 bg-sand mt-2 border p-3">
          <div className="flex items-baseline justify-between">
            <span className="text-ink-soft text-sm">Fert$ (tributo de vendas)</span>
            <span className="text-ink font-black tabular-nums" data-tesouro-fert>
              {fert(dados.fert_micro, 2)} Fert$
            </span>
          </div>
        </div>

        {dados.recursos.length > 0 ? (
          <ul className="mt-2 divide-y divide-rust/10 border-rust/20 border">
            {dados.recursos.map((r) => (
              <li key={r.code} className="flex items-baseline justify-between px-3 py-1.5">
                <span className="text-ink-soft text-sm">{r.nome}</span>
                <span className="text-ink tabular-nums">{r.total.toLocaleString('pt-BR')}</span>
              </li>
            ))}
          </ul>
        ) : (
          <p className="text-ink-soft/70 mt-2 text-xs">Nenhum recurso arrecadado ainda.</p>
        )}
      </section>

      {/* Painel de taxas: as alíquotas do §8.3. */}
      <section>
        <div className="text-rust eyebrow">Painel de taxas</div>
        <ul className="mt-2 flex gap-2">
          {dados.aliquotas.map((a) => (
            <li key={a.tax_class} className="border-rust/20 bg-sand flex-1 border p-2 text-center">
              <div className="text-ink text-xl font-black tabular-nums">{a.bps / 100}%</div>
              <div className="text-ink-soft eyebrow">{a.rotulo}</div>
            </li>
          ))}
        </ul>
      </section>

      {/* Últimas transferências tributadas. */}
      <section>
        <div className="text-rust eyebrow">Últimas transferências tributadas</div>
        {dados.recentes.length > 0 ? (
          <ul className="mt-2 divide-y divide-rust/10 border-rust/20 border">
            {dados.recentes.map((e, i) => (
              <li key={i} className="flex items-baseline justify-between px-3 py-1.5 text-sm">
                <span className="text-ink-soft truncate">
                  {e.colonia ?? '—'}
                  <span className="text-ink-soft/60"> · {dataHumana(e.created_at)}</span>
                </span>
                <span className="text-ink ml-2 shrink-0 tabular-nums">
                  {e.kind === 'mercado_venda'
                    ? `${fert(e.tax_amount, 2)} Fert$`
                    : `${e.tax_amount.toLocaleString('pt-BR')} ${nomeRecurso(e.resource_type ?? '')}`}
                </span>
              </li>
            ))}
          </ul>
        ) : (
          <p className="text-ink-soft/70 mt-2 text-xs">Nenhum tributo cobrado ainda.</p>
        )}
      </section>
    </div>
  )
}
