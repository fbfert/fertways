import { useEffect, useState } from 'react'
import { api } from '../api/client'
import type { ConcentracaoDaFederacao as Dados } from '../api/client'
import { Selo } from './sistema'

/**
 * O teto antimonopólio, **visível antes de bater** (A2.5).
 *
 * ## O defeito que esta tela corrige
 *
 * O limite existe desde o D-119 e funciona: ocupar zona é recusado quando a federação já tem 20% de
 * todas as zonas do jogo. Mas ele **bloqueava sem avisar** — o colono descobria o teto no instante
 * em que batia nele, depois de já ter levado tropa e material até a zona.
 *
 * O roadmap da A2.5 nomeia isso com precisão: *"criar proteções antimonopólio **observáveis**"*. A
 * proteção já era; o que faltava era poder vê-la chegando.
 *
 * ## "Quantas ainda cabem" é o número que importa
 *
 * Percentual sozinho não ajuda a decidir: saber que se está em 17% não diz se vale a pena mandar
 * uma expedição. **"Cabem mais 2"** diz. E essa conta não é regra de três — cada zona ocupada também
 * aumenta o total de zonas do jogo, então o denominador cresce junto. Quem calcula isso é o
 * servidor, com a mesma expressão que o domínio usa para bloquear; há teste amarrando as duas.
 */
export function ConcentracaoDaFederacao() {
  const [d, setD] = useState<Dados | null>(null)

  useEffect(() => {
    let vivo = true
    api.concentracaoDaFederacao().then((r) => vivo && setD(r)).catch(() => {})

    return () => {
      vivo = false
    }
  }, [])

  if (!d?.tem_federacao) return null

  const pct = ((d.ocupacao_bps ?? 0) / 100).toFixed(1)
  const teto = ((d.teto_bps ?? 0) / 100).toFixed(0)

  return (
    <section data-secao="concentracao" className="painel bg-sand mt-4 p-4">
      <h3 className="eyebrow text-rust mb-2">Limite antimonopólio (§04)</h3>

      <div className="flex flex-wrap items-center gap-3">
        <div>
          <div className="text-ink text-lg font-black" data-concentracao-pct>
            {pct}% <span className="text-ink-soft text-sm font-normal">de {teto}%</span>
          </div>
          <div className="text-ink-soft text-sm">
            {d.zonas_da_federacao} de {d.zonas_do_jogo} zonas ocupadas do jogo
            {/*
              ⚠️ Com aliança, o número acima é o do BLOCO — o teto do §04 passou a somar as zonas de
              todas as aliadas (A2.5). Sem esta frase, "17%" pareceria errado para quem só contou as
              zonas da própria federação, e a tela perderia a confiança do jogador justamente onde
              ela mais precisa dela.
            */}
            {(d.federacoes_no_bloco ?? 1) > 1 && (
              <span data-bloco={d.federacoes_no_bloco}>
                {' '}— somando as {d.federacoes_no_bloco} federações aliadas
              </span>
            )}
          </div>
        </div>

        {/*
          O selo carrega o sentido no RÓTULO, não só na cor — a paleta é quente e o vermelho fica a
          14° do laranja da marca, então cor nunca é o único sinal (ver docs/design-tokens.md).
        */}
        {d.no_teto ? (
          <Selo estado="perigo" tom="forte" data-concentracao-estado="no-teto">
            no teto — não é possível ocupar mais
          </Selo>
        ) : (
          <Selo estado="sucesso" data-concentracao-estado="folga">
            cabem mais {d.zonas_ate_o_teto}
          </Selo>
        )}
      </div>

      <p className="text-ink-soft/70 text-micro mt-3">
        O limite é sobre a fatia de <strong>todas</strong> as zonas do jogo, não sobre um número
        fixo: quando outras federações ocupam zonas, a sua fatia cai e volta a caber mais.
      </p>
    </section>
  )
}
