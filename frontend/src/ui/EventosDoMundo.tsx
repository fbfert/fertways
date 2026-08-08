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
   * ⚠️ O que o evento faz, em português — e nunca "produção ou consumo" (D-232).
   *
   * A faixa nasceu com dois modificadores e escrevia `modificador === 'producao' ? 'produção' :
   * 'consumo'`. São seis desde então, e o ternário transformava trégua, custo de guerra e os dois
   * portões de território em "consumo". Um evento que abre o território ao mundo inteiro teria
   * chegado ao jogador como "consumo −95% em tudo" — pior do que não avisar, porque parece
   * informação.
   *
   * A cesta vem primeiro: quando um evento entrega alguma coisa, é isso que o jogador quer ler.
   */
  const oQueFaz = (e: EventoDoMundo): string | null => {
    /*
     * ⚠️ A cesta entra ANTES do modificador, e some junto com ele (A2.V6, D-235).
     *
     * `cesta_de_presente` carrega as duas coisas: entregou 27.400 unidades a cada colônia E abriu o
     * portão do território. A faixa dizia só o portão — a maior coisa que já aconteceu ao jogador
     * ficava sem uma palavra, porque a cesta só era mencionada quando NÃO havia modificador.
     *
     * O detalhe do que veio na cesta é do "Desde sua última visita", que tem espaço para a lista.
     * Aqui cabe o fato, e o fato é que o Governo mandou alguma coisa.
     */
    const presente = e.cesta ? 'um presente do Governo, já entregue' : null

    if (e.modificador == null) return presente

    const junta = (s: string) => (presente ? `${presente}; ${s}` : s)

    const pct = `${(e.efeito ?? 0) > 0 ? '+' : ''}${e.efeito}%`

    switch (e.modificador) {
      case 'producao':
      case 'consumo':
        return junta(
          `${e.modificador === 'producao' ? 'produção' : 'consumo'} ${pct}${
            e.recurso ? ` em ${e.recurso}` : ' em tudo'
          }`,
        )
      case 'guerra_declaracao':
        return junta(
          (e.efeito ?? 0) <= -100 ? 'trégua imposta: ninguém declara guerra' : `guerra ${pct}`,
        )
      case 'guerra_custo':
        return junta(`declarar e mobilizar ${pct}`)
      case 'ocupacao_marco':
        return junta(`ocupar zona neutra pede ${100 + (e.efeito ?? 0)}% do XP de sempre`)
      case 'ocupacao_populacao':
        return junta(
          (e.efeito ?? 0) <= -100
            ? 'ocupar zona neutra não exige colonos livres'
            : `colonos para ocupar ${pct}`,
        )
    }
  }

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
              {oQueFaz(e) ? <span className="text-ink-soft">({oQueFaz(e)})</span> : null}
              </>
            )}
          </p>
        ))}
      </div>
    </div>
  )
}
