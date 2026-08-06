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

  /*
   * ⚠️ A faixa é CHROME, e não conteúdo — e não era (achado ao fotografar a colônia, D-215).
   *
   * Ela nascia em fluxo, como primeiro elemento do documento. Só que as duas barras de navegação
   * (`Header` no desktop, `MobileNav` no mobile) são `absolute top-0`, fora do fluxo, e pintavam
   * **por cima** dela: o texto saía picado atrás do cabeçalho, ilegível. E, por estar em fluxo, ela
   * ainda empurrava a colônia inteira para baixo — a colônia é `h-screen`, então o que sobrava era
   * uma tela maior que a janela, deslocada.
   *
   * ⚠️ E o e2e passava. Ele afirma que o texto **existe no DOM** (`esperarTexto`), e existia; nada
   * num teste de texto ou de clique alcança o que está visualmente coberto. É o falso-verde do D-63,
   * e a razão de `e2e/foto.mjs` existir: quando mexer em tela, fotografe e olhe.
   *
   * Agora ela mora na mesma camada das barras, logo abaixo delas:
   *
   * - `absolute` e não `fixed`: um aviso que acompanha a rolagem para sempre vira moldura, e o
   *   evento não é urgente a ponto de perseguir o jogador tela abaixo;
   * - `z-20` contra o `z-[25]` das barras: nunca cobre a navegação, que é o caminho de saída;
   * - `pointer-events-none`: na colônia ela flutua sobre a colmeia, e um aviso que rouba clique de
   *   um slot seria pior do que um aviso invisível;
   * - `md:pr-72` reserva a coluna do HUD (`right-5 w-64`), que começa no mesmo `top-24`.
   */
  return (
    <div
      className="pointer-events-none absolute inset-x-0 top-20 z-20 flex justify-center px-4 md:top-24 md:pr-72"
      data-eventos-faixa
    >
      <div
        className="border-rust/30 bg-sand max-w-4xl border-l-4 px-3 py-2 shadow-sm"
        data-eventos
      >
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
    </div>
  )
}
