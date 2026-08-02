import { useEffect, useState } from 'react'
import { api } from '../api/client'
import type { EventoDoMundo } from '../api/client'

/**
 * A faixa dos eventos de mundo (A2.8).
 *
 * ## ⚠️ Por que ela existe
 *
 * Um motor que muda a economia sem que ninguém veja é **indistinguível de um defeito**. O jogador
 * veria a produção cair e concluiria que o jogo quebrou — e essa é uma desconfiança que não se
 * recupera depois.
 *
 * ## O evento "parcial" mostra a tensão, e esconde a explicação
 *
 * Ele diz que **algo** está mexendo na produção, sem dizer o quê nem quanto. É a única visibilidade
 * que cria mistério em vez de confusão: o jogador sabe que há uma causa e vai procurá-la, em vez de
 * achar que os números estão errados.
 *
 * O evento **secreto** não chega aqui — o servidor o filtra antes.
 */
export function EventosDoMundo() {
  const [eventos, setEventos] = useState<EventoDoMundo[]>([])

  useEffect(() => {
    let vivo = true
    api
      .eventosDoMundo()
      .then((r) => vivo && setEventos(r.eventos))
      .catch(() => {})

    return () => {
      vivo = false
    }
  }, [])

  if (eventos.length === 0) return null

  return (
    <div className="border-rust/30 bg-sand mx-auto mt-2 max-w-4xl border-l-4 px-3 py-2" data-eventos>
      {eventos.map((e, i) => (
        <p key={i} className="text-ink text-sm" data-evento={e.parcial ? 'parcial' : 'anunciado'}>
          {e.parcial ? (
            <>
              <strong>Algo está afetando a produção no planeta.</strong>{' '}
              <span className="text-ink-soft">A Central de Notícias ainda não explicou o quê.</span>
            </>
          ) : (
            <>
              <strong>{e.nome}</strong>
              {e.mensagem ? ` — ${e.mensagem}` : ''}{' '}
              <span className="text-ink-soft">
                ({e.modificador === 'producao' ? 'produção' : 'consumo'}{' '}
                {(e.efeito ?? 0) > 0 ? '+' : ''}
                {e.efeito}%{e.recurso ? ` em ${e.recurso}` : ' em tudo'})
              </span>
            </>
          )}
        </p>
      ))}
    </div>
  )
}
