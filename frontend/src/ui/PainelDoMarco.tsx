import type { Marco } from '../api/client'
import { Popup } from './Popup'
import { Botao } from './sistema'

/**
 * O que o Marco abre, e de onde vem XP (D-247).
 *
 * ## ⚠️ O jogo cobrava o Marco e nunca disse como se sobe nem para quê
 *
 * O cabeçalho mostra `Marco 12 · Pioneiro · 2.600 / 3.000 XP` desde o D-75, e era tudo. Nada dizia
 * que existe XP por comerciar, nem que ocupar território pede o marco 20 — o jogador descobria o
 * portão ao esbarrar nele, e a fonte, nunca.
 *
 * Deixou de ser detalhe quando o D-241 mediu a torneira: **7 das 9 colônias humanas travadas no
 * marco**, com o planeta inteiro fazendo 900 XP por semana. Parte da seca é de informação — não
 * adianta pagar XP por comércio se ninguém sabe que ele paga.
 *
 * ## Os números vêm todos do servidor
 *
 * Nenhuma frase escrita à mão aqui: as fontes saem do painel do operador e os portões saem de onde
 * eles são **cobrados** — inclusive o do território, que um evento pode abaixar (D-232). É a lição
 * do D-224 e do D-240: tela que reescreve a regra envelhece sozinha e passa meses mentindo.
 */
export function PainelDoMarco({ marco, aoFechar }: { marco: Marco; aoFechar: () => void }) {
  const falta = marco.xp_do_proximo !== null ? marco.xp_do_proximo - marco.xp : null

  return (
    <Popup
      titulo={`Marco ${marco.numero} — ${marco.titulo}`}
      eyebrow={
        falta !== null
          ? `faltam ${falta.toLocaleString('pt-BR')} XP para o marco ${marco.numero + 1}`
          : 'o topo da curva'
      }
      aoFechar={aoFechar}
    >
      <div className="space-y-4" data-painel-marco>
        {/*
         * O que ABRE vem primeiro. O jogador não quer saber o que é XP — quer saber o que ganha, e
         * é isso que transforma um número no cabeçalho em motivo para jogar.
         */}
        <section data-marco-desbloqueios>
          <h3 className="text-rust eyebrow mb-2">O que ainda está fechado</h3>
          {marco.proximos_desbloqueios.length === 0 ? (
            <p className="text-ink-soft text-sm">
              Nada — tudo o que o Marco governa já está aberto para esta colônia.
            </p>
          ) : (
            <ul className="space-y-1">
              {marco.proximos_desbloqueios.map((d, i) => (
                <li key={`${d.marco}-${i}`} className="flex items-baseline gap-2 text-sm">
                  <span className="text-ink shrink-0 font-black tabular-nums">
                    Marco {d.marco}
                  </span>
                  <span className="text-ink-soft flex-1">{d.o_que}</span>
                  <span className="text-ink-soft shrink-0 text-xs tabular-nums">
                    {d.xp.toLocaleString('pt-BR')} XP
                  </span>
                </li>
              ))}
            </ul>
          )}
        </section>

        <section data-marco-fontes>
          <h3 className="text-rust eyebrow mb-2">De onde vem XP</h3>
          <ul className="space-y-1">
            {marco.fontes_de_xp.map((f) => (
              <li key={f.acao} className="flex items-baseline gap-2 text-sm">
                <span className="text-ink-soft flex-1">
                  {f.rotulo}
                  {f.nota ? <span className="text-ink-soft/70 text-xs"> ({f.nota})</span> : null}
                </span>
                {/*
                 * A missão vem sem número de propósito: cada catálogo paga o seu (D-78), e imprimir
                 * "0 XP" ali diria o contrário do que é verdade.
                 */}
                {f.xp > 0 && (
                  <span className="text-ink shrink-0 font-bold tabular-nums">
                    +{f.xp.toLocaleString('pt-BR')}
                  </span>
                )}
              </li>
            ))}
          </ul>
        </section>

        <Botao onClick={aoFechar} data-marco-fechar className="w-full" tamanho="grande">
          Fechar
        </Botao>
      </div>
    </Popup>
  )
}
