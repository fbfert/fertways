import { useEffect, useState } from 'react'
import { api, ApiError } from '../../api/client'
import { LandingPageChrome } from './LandingPageChrome'

type Numeros = Awaited<ReturnType<typeof api.estatisticas>>

const fert = (micro: number) => (micro / 1_000_000).toLocaleString('pt-BR', { maximumFractionDigits: 0 })
const n = (v: number) => v.toLocaleString('pt-BR')

export function PaginaEstatisticas() {
  const [dados, setDados] = useState<Numeros | null>(null)
  const [erro, setErro] = useState<string | null>(null)

  useEffect(() => {
    api
      .estatisticas()
      .then(setDados)
      .catch((e) => setErro(e instanceof ApiError ? e.message : 'Não consegui buscar os números agora.'))
  }, [])

  const CARTOES = dados
    ? [
        { rotulo: 'Colonos cadastrados', valor: n(dados.colonos) },
        { rotulo: 'Colônias fundadas', valor: n(dados.colonias) },
        { rotulo: 'Fert$ em circulação', valor: fert(dados.fert_em_circulacao_micro) },
        { rotulo: 'Construções erguidas', valor: n(dados.construcoes_erguidas) },
        { rotulo: 'Veículos com placa', valor: n(dados.veiculos_registrados) },
        { rotulo: 'Zonas neutras ocupadas', valor: n(dados.zonas_ocupadas) },
        { rotulo: 'Lançamentos no ledger', valor: n(dados.lancamentos_no_ledger) },
      ]
    : []

  return (
    <LandingPageChrome
      eyebrow="O planeta, agora"
      titulo="Números reais, lidos do banco ao vivo — nenhum é decorativo."
      intro={
        <p>
          O mesmo ledger append-only que garante que nenhum Fert$ nasça sem origem (§3.3 do GDD) é
          de onde estes números saem. Não é uma maquete: é o estado do planeta neste instante.
        </p>
      }
    >
      <section className="mx-auto max-w-6xl px-6 pb-14">
        {erro && <p className="text-rust text-sm">{erro}</p>}

        {!dados && !erro && <p className="text-ink-soft text-sm">Carregando…</p>}

        {dados && (
          <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
            {CARTOES.map((c) => (
              <div key={c.rotulo} className="painel border-rust/15 bg-sand-light border p-6 text-center">
                <div className="text-rust text-3xl font-black tabular-nums">{c.valor}</div>
                <div className="text-ink-soft eyebrow mt-2">{c.rotulo}</div>
              </div>
            ))}
          </div>
        )}
      </section>
    </LandingPageChrome>
  )
}
