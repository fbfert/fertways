import { useEffect, useState } from 'react'
import { api } from '../api/client'
import type { PerfilDaColonia } from '../api/client'
import { IconeRecurso } from './IconeRecurso'
import { nomeRecurso } from './recursos'
import { Selo } from './sistema'

/**
 * A vocação da colônia (A2.4) — **a contrapartida obrigatória do GDD ALPHA 2 §8.1**.
 *
 * Aquela seção decide que não existe escolha declarada de perfil: o colono não seleciona "sou
 * agrícola", ele se torna agrícola pelo que pesquisou e construiu. E impõe o preço disso por
 * escrito: *"como a especialização não é declarada, ela precisa ser **exibida**"*.
 *
 * Esta tela é esse preço sendo pago. Sem ela, o jogo teria uma especialização que existe, decide
 * economia e **ninguém consegue ver** — o pior dos dois mundos, porque o jogador sofreria a
 * consequência de uma escolha que nunca soube que estava fazendo.
 *
 * ## Mostra os dois lados, e o segundo é o que importa
 *
 * O §8.1 pede "o que ele ganha **e do que passa a depender**". A lista de dependências não é
 * enfeite: é o que transforma especialização em decisão. Uma tela que só mostrasse a vocação diria
 * ao colono que ele é bom em metal; é a lista ao lado que lhe diz que, por isso, ele **precisa** de
 * alguém que faça biomassa.
 */
export function VocacaoDaColonia() {
  const [p, setP] = useState<PerfilDaColonia | null>(null)

  useEffect(() => {
    let vivo = true
    api.perfilDaColonia().then((r) => vivo && setP(r)).catch(() => {})

    return () => {
      vivo = false
    }
  }, [])

  // Sem colônia não há vocação, e nada aqui faz sentido. Silêncio é melhor do que uma seção vazia.
  if (!p?.tem_colonia) return null

  const producao = Object.entries(p.producao ?? {}).filter(([, q]) => q > 0)

  return (
    <section data-secao="vocacao">
      <h3 className="eyebrow text-rust mt-6 mb-2">Vocação da colônia</h3>

      <div className="painel bg-sand space-y-4 p-4" data-vocacao>
        {p.vocacao ? (
          <div className="flex items-center gap-3">
            <IconeRecurso codigo={p.vocacao} />
            <div>
              <div className="text-ink text-lg font-black" data-vocacao-nome>
                {nomeRecurso(p.vocacao)}
              </div>
              <div className="text-ink-soft text-sm">
                {p.forca_pct}% do valor que a colônia produz
              </div>
            </div>
          </div>
        ) : (
          <p className="text-ink-soft text-sm">
            A colônia ainda não produz nada de valor — a vocação aparece quando houver produção.
          </p>
        )}

        {/*
          Ninguém escolheu isto, e o colono precisa saber disso — senão a tela parece um formulário
          que alguém preencheu por ele.
        */}
        <p className="text-ink-soft/70 text-micro">
          A vocação não é escolhida: ela é calculada pelo que você construiu e pesquisou.
        </p>

        {producao.length > 0 && (
          <div>
            <h4 className="eyebrow text-ink-soft mb-1">Produz por hora</h4>
            <ul className="space-y-1">
              {producao.map(([recurso, qtd]) => (
                <li key={recurso} className="flex items-center gap-2 text-sm">
                  <IconeRecurso codigo={recurso} />
                  <span className="text-ink-soft flex-1">{nomeRecurso(recurso)}</span>
                  <span className="text-ink font-bold">{qtd.toLocaleString('pt-BR')}</span>
                </li>
              ))}
            </ul>
          </div>
        )}

        {(p.depende_de ?? []).length > 0 && (
          <div data-vocacao-depende>
            <h4 className="eyebrow text-ink-soft mb-1">Depende de outros</h4>
            <div className="flex flex-wrap gap-1.5">
              {(p.depende_de ?? []).map((r) => (
                <Selo key={r} estado="aviso">
                  {nomeRecurso(r)}
                </Selo>
              ))}
            </div>
            <p className="text-ink-soft/70 text-micro mt-2">
              A colônia consome estes recursos e não os produz. É por isto que especializar-se
              obriga a negociar.
            </p>
          </div>
        )}

        {(p.trilhas ?? []).length > 0 && (
          <div>
            <h4 className="eyebrow text-ink-soft mb-1">Trilhas pesquisadas</h4>
            <div className="flex flex-wrap gap-1.5">
              {(p.trilhas ?? []).map((t) => (
                <Selo key={t} estado="info">
                  {t}
                </Selo>
              ))}
            </div>
          </div>
        )}
      </div>
    </section>
  )
}
