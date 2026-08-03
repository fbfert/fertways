import { useCallback, useEffect, useState } from 'react'
import { api } from '../api/client'
import type { ArvoreDePesquisa } from '../api/client'
import { Botao, Selo } from './sistema'
import { nomeRecurso } from './recursos'

/**
 * A árvore de pesquisa (A2.3).
 *
 * ## ⚠️ Por que esta tela não existia
 *
 * A A2.3 entregou o modelo — catálogo, trilhas, custos, vagas, vocabulário de efeitos — e parou aí.
 * Sem rota e sem tela, ligar `research_settings.ativo` não teria feito **nada**: era o mesmo defeito
 * da população no D-178, onde a chave-mestra também não estava ligada em coisa alguma.
 *
 * ## Os efeitos ATIVOS aparecem no topo, e não só a lista do que dá para pesquisar
 *
 * Sem isso o jogador concluiria uma tecnologia e não teria como saber se ela está valendo — a mesma
 * armadilha da penalidade invisível que a A2.6 evitou. Progressão que não se vê é indistinguível de
 * progressão que não aconteceu.
 */
export function Pesquisa() {
  const [d, setD] = useState<ArvoreDePesquisa | null>(null)
  const [ocupado, setOcupado] = useState(false)
  const [recado, setRecado] = useState<string | null>(null)

  const carregar = useCallback(() => {
    api
      .arvoreDePesquisa()
      .then(setD)
      .catch(() => {})
  }, [])

  useEffect(carregar, [carregar])

  if (!d) return null

  if (!d.ativo) {
    return (
      <section className="painel bg-sand p-4" data-tela="pesquisa">
        <h3 className="eyebrow text-rust mb-2">Pesquisa</h3>
        <p className="text-ink-soft text-sm">A pesquisa ainda não está aberta neste servidor.</p>
      </section>
    )
  }

  const pesquisar = async (id: number, nome: string) => {
    setOcupado(true)
    try {
      await api.pesquisar(id)
      setRecado(`«${nome}» entrou em pesquisa.`)
      carregar()
    } catch (e) {
      setRecado(e instanceof Error ? e.message : 'Não deu.')
    } finally {
      setOcupado(false)
    }
  }

  return (
    <section className="painel bg-sand p-4" data-tela="pesquisa">
      <h3 className="eyebrow text-rust mb-2">Pesquisa</h3>

      <p className="text-ink-soft mb-3 text-sm" data-vagas-pesquisa={d.vagas.livres}>
        Laboratório nível <strong>{d.laboratorio}</strong> · <strong>{d.vagas.livres}</strong> de{' '}
        {d.vagas.total} vaga(s) livre(s).
      </p>

      {/*
        Os efeitos que já estão valendo. Ver o docblock: progressão que não se vê é indistinguível
        de progressão que não aconteceu.
      */}
      {(d.meus_efeitos.desconto_tributo_pct > 0 ||
        d.meus_efeitos.desconto_duracao_pct > 0 ||
        Object.keys(d.meus_efeitos.producao_por_alvo).length > 0) && (
        <p className="text-sucesso border-sucesso/30 mb-3 border-l-2 pl-2 text-xs" data-efeitos-ativos>
          Valendo agora:
          {d.meus_efeitos.desconto_tributo_pct > 0 && (
            <> tributo −{d.meus_efeitos.desconto_tributo_pct}%</>
          )}
          {d.meus_efeitos.desconto_duracao_pct > 0 && (
            <> · pesquisa −{d.meus_efeitos.desconto_duracao_pct}% de duração</>
          )}
          {Object.entries(d.meus_efeitos.producao_por_alvo).map(([alvo, bps]) => (
            <span key={alvo}>
              {' '}
              · produção +{bps / 100}% em {alvo}
            </span>
          ))}
        </p>
      )}

      <ul className="flex flex-col gap-2">
        {d.tecnologias.map((t) => (
          <li key={t.id} className="border-ink/10 border-t pt-2" data-tecnologia={t.chave}>
            <div className="flex flex-wrap items-baseline gap-2">
              <strong className="text-ink text-sm">{t.nome}</strong>
              <span className="text-ink-soft/60 eyebrow text-xs">{t.trilha}</span>

              {t.status === 'concluida' && <Selo estado="sucesso">nível {t.nivel}</Selo>}
              {t.status === 'pesquisando' && <Selo estado="info">em pesquisa</Selo>}
            </div>

            <p className="text-ink-soft/80 mt-0.5 text-xs">{t.descricao}</p>

            <div className="mt-1.5 flex flex-wrap items-center gap-2">
              <Botao
                variante="secundaria"
                tamanho="pequeno"
                onClick={() => pesquisar(t.id, t.nome)}
                disabled={ocupado || t.bloqueio !== null || d.vagas.livres < 1}
                data-pesquisar={t.id}
              >
                Pesquisar {t.nivel > 0 ? `nível ${t.nivel + 1}` : ''}
              </Botao>

              <span className="text-ink-soft/60 text-xs">
                {Object.entries(t.custo)
                  .map(([r, q]) => `${q} ${nomeRecurso(r)}`)
                  .join(' · ')}{' '}
                · {Math.round(t.duracao_segundos / 3600)} h
              </span>

              {/* O porquê de não poder, para a tela não oferecer o que a regra recusaria. */}
              {t.bloqueio === 'laboratorio' && (
                <span className="text-ink-soft/60 text-xs">
                  Exige Laboratório nível {t.laboratorio_minimo}.
                </span>
              )}
              {t.bloqueio === 'no_maximo' && (
                <span className="text-ink-soft/60 text-xs">Já está no nível máximo.</span>
              )}
              {t.bloqueio === null && d.vagas.livres < 1 && (
                <span className="text-ink-soft/60 text-xs">Sem vaga livre no Laboratório.</span>
              )}
            </div>
          </li>
        ))}
      </ul>

      {recado && (
        <p className="text-ink mt-3 text-sm" data-recado-pesquisa>
          {recado}
        </p>
      )}
    </section>
  )
}
