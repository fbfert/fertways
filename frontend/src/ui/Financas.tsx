import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from '../api/client'
import type { Financas as FinancasDto } from '../api/client'
import { dataHumana, fert } from './recursos'

const CLASSE: Record<string, string> = {
  primario: 'Primário',
  secundario: 'Secundário',
  raro: 'Raro',
}

/**
 * Secretaria de Finanças e Tesouro (slot 4). Só leitura.
 *
 * Mostra os preços de referência do §06 ("faixa de segurança, não preço obrigatório"), as
 * intervenções de preço vigentes (declaradas pela Secretaria via operador — D-35) e indicadores
 * mensuráveis. Sem PIB (fórmula lacunar) e sem faixa automática.
 */
export function Financas() {
  const [dados, setDados] = useState<FinancasDto | null>(null)
  const [erro, setErro] = useState<string | null>(null)

  const carregar = useCallback(async () => {
    try {
      setDados(await api.financas())
    } catch (e) {
      setErro(e instanceof ApiError ? e.message : 'Falha ao carregar Finanças.')
    }
  }, [])

  useEffect(() => {
    void carregar()
  }, [carregar])

  if (erro) return <p className="text-rust mt-4 text-sm font-bold">{erro}</p>
  if (!dados) return <p className="text-ink-soft mt-4 text-sm">Carregando…</p>

  return (
    <div className="mt-5 space-y-6">
      {/* Indicadores. */}
      <section>
        <div className="text-rust eyebrow">Indicadores</div>
        <ul className="mt-2 grid grid-cols-3 gap-2">
          <Indicador rotulo="Fert$ em circulação" valor={`${fert(dados.indicadores.fert_em_circulacao_micro, 2)}`} />
          <Indicador rotulo="Tesouro (Fert$)" valor={`${fert(dados.indicadores.tesouro_fert_micro, 2)}`} />
          <Indicador rotulo="Colônias" valor={dados.indicadores.colonias.toLocaleString('pt-BR')} />
        </ul>
      </section>

      {/* Intervenções vigentes. */}
      <section>
        <div className="text-rust eyebrow">Intervenções de preço vigentes</div>
        {dados.intervencoes.length > 0 ? (
          <ul className="mt-2 divide-y divide-rust/10 border-rust/20 border" data-intervencoes>
            {dados.intervencoes.map((i) => (
              <li key={i.id} className="px-3 py-2 text-sm">
                <div className="flex items-baseline justify-between">
                  <span className="text-ink font-bold">{i.nome}</span>
                  <span className="text-ink-soft tabular-nums">
                    {i.floor_micro !== null ? `piso ${fert(i.floor_micro)}` : ''}
                    {i.floor_micro !== null && i.ceil_micro !== null ? ' · ' : ''}
                    {i.ceil_micro !== null ? `teto ${fert(i.ceil_micro)}` : ''}
                  </span>
                </div>
                <div className="text-ink-soft/70 text-xs">
                  {i.reason} · até {dataHumana(i.expires_at)}
                </div>
              </li>
            ))}
          </ul>
        ) : (
          <p className="text-ink-soft/70 mt-2 text-xs">
            Nenhuma. O Mercado negocia livre — o preço de referência é faixa de segurança, não
            obrigação (§06).
          </p>
        )}
      </section>

      {/* Preços de referência. */}
      <section>
        <div className="text-rust eyebrow">Preços de referência (§06)</div>
        <div className="mt-2 max-h-72 overflow-y-auto">
          <table className="w-full text-sm">
            <thead className="text-ink-soft eyebrow border-rust/20 border-b">
              <tr>
                <th className="py-1 text-left">Recurso</th>
                <th className="py-1 text-left">Classe</th>
                <th className="py-1 text-right">Taxa</th>
                <th className="py-1 text-right">Preço-base</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-rust/10">
              {dados.precos.map((p) => (
                <tr key={p.code}>
                  <td className="text-ink py-1">{p.nome}</td>
                  <td className="text-ink-soft py-1">{CLASSE[p.tax_class] ?? p.tax_class}</td>
                  <td className="text-ink-soft py-1 text-right tabular-nums">{p.tax_bps / 100}%</td>
                  <td className="text-ink py-1 text-right tabular-nums">
                    {p.preco_base_micro !== null ? fert(p.preco_base_micro) : '—'}
                    {p.derivado && <span className="text-rust" title="preço derivado (D-34)">*</span>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <p className="text-ink-soft/60 mt-1 text-xs">* preço derivado da fórmula, não publicado direto (D-34).</p>
      </section>
    </div>
  )
}

function Indicador({ rotulo, valor }: { rotulo: string; valor: string }) {
  return (
    <li className="border-rust/20 bg-sand border p-2 text-center">
      <div className="text-ink text-lg font-black tabular-nums">{valor}</div>
      <div className="text-ink-soft eyebrow text-micro">{rotulo}</div>
    </li>
  )
}
