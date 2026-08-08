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
  /** Ver a prosa dos operadores. Fechada por padrão quando há mais de um evento — ver o D-236. */
  const [aberta, setAberta] = useState(false)
  const [fechada, setFechada] = useState(false)

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

  if (eventos.length === 0 || fechada) return null

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
    // Curto de propósito: com quatro eventos, cada palavra a mais é uma linha a mais no telefone.
    const presente = e.cesta ? 'presente já entregue' : null

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
   *
   * ⚠️ **E `pr-14` no MOBILE, que faltava** (D-236). O desktop reservava a coluna do HUD e ninguém
   * reservou nada para os controles de cena do telefone (zoom e "Centralizar", no canto superior
   * direito). Enquanto a faixa não tinha botão, isso só encostava texto neles. Com o × do D-236
   * virou defeito de verdade: medido com `elementFromPoint`, **quem recebia o toque no centro do ×
   * era o "Centralizar"** — o jogador via a saída e mexia no zoom.
   *
   * ⚠️ E o e2e dizia que estava tudo bem: `element.click()` despacha no elemento e **não faz
   * hit-testing**, então ele fechava a faixa alegremente enquanto um dedo humano não conseguiria.
   * É o D-63 de novo, com outra fantasia — e a razão de a medida ser `elementFromPoint`, e não
   * "o clique funcionou".
   */
  /*
   * ⚠️ **Com mais de um evento a faixa vira parede, e fotografado é pior do que o número dizia**
   * (A2.V6, D-236).
   *
   * Medido em 390×844 com os quatro eventos que a produção tem: `altura 376 de 844` — **45% da
   * tela**, do topo até abaixo da metade, com a colmeia soterrada. E a faixa é `pointer-events-none`
   * desde o D-217, então o jogador **não consegue tirá-la do caminho**. É a terceira oclusão desta
   * mesma tela (D-215, D-217), e as duas primeiras também passaram por todos os testes.
   *
   * A causa não é o número de eventos: é que cada um imprimia a **prosa inteira** do operador. Com
   * um evento isso é uma linha; com quatro é um texto corrido de doze.
   *
   * As três regras que saíram da foto:
   *
   * 1. **A prosa é opcional; o mecanismo não.** O `(…)` é derivado do bps e não pode envelhecer; a
   *    `mensagem` é escrita à mão e é enfeite. Fechada, a faixa mostra nome + mecanismo, uma linha
   *    por evento. Aberta, mostra tudo. Ninguém perde informação — ela deixa de ser imposta.
   * 2. **Um evento continua aberto por padrão.** Era o caso que já funcionava, e encolher o que
   *    estava certo para resolver o que estava errado seria trocar um defeito por outro.
   * 3. **Dá para fechar.** O `pointer-events-none` do D-217 existia para a faixa não roubar o
   *    clique de um slot da colmeia, e essa razão continua boa — por isso ela fica no **contêiner**,
   *    e só a caixa recebe `pointer-events-auto`. O aviso para de ser uma parede que não se remove
   *    sem deixar de flutuar sobre a colônia.
   */
  const varios = eventos.length > 1
  const detalhado = !varios || aberta

  return (
    <div
      className="pointer-events-none absolute inset-x-0 top-20 z-20 flex justify-center py-0 pl-4 pr-14 md:top-24 md:pl-4 md:pr-72"
      data-eventos-faixa
    >
      <div
        className="border-rust/30 bg-sand pointer-events-auto max-w-4xl border-l-4 px-3 py-2 shadow-sm"
        data-eventos
      >
        <div className="flex items-start gap-3">
          <div className="min-w-0 flex-1">
            {eventos.map((e, i) => (
              <p
                key={i}
                className="text-ink text-sm"
                data-evento={e.parcial ? 'parcial' : 'anunciado'}
              >
                {e.parcial ? (
                  <>
                    <strong>Algo está afetando a produção no planeta.</strong>
                    {detalhado ? (
                      <span className="text-ink-soft">
                        {' '}
                        A Central de Notícias ainda não explicou o quê.
                      </span>
                    ) : null}
                  </>
                ) : (
                  <>
                    <strong>{e.nome}</strong>
                    {detalhado && e.mensagem ? ` — ${e.mensagem}` : ''}{' '}
                    {oQueFaz(e) ? <span className="text-ink-soft">({oQueFaz(e)})</span> : null}
                  </>
                )}
              </p>
            ))}

            {varios ? (
              <button
                type="button"
                className="text-rust mt-1 text-xs underline"
                onClick={() => setAberta((v) => !v)}
                data-eventos-detalhes
              >
                {aberta ? 'menos' : `ver o que dizem (${eventos.length})`}
              </button>
            ) : null}
          </div>

          {/*
           * Dispensar é da SESSÃO, e não persistido de propósito. Um evento de mundo é temporário e
           * muda a economia; lembrar "não me mostre" entre visitas faria o jogador voltar amanhã
           * para uma produção diferente e nenhuma explicação — que é o defeito exato que esta faixa
           * existe para não ter.
           */}
          <button
            type="button"
            className="text-ink-soft hover:text-ink shrink-0 text-lg leading-none"
            aria-label="Dispensar os avisos de evento"
            onClick={() => setFechada(true)}
            data-eventos-fechar
          >
            ×
          </button>
        </div>
      </div>
    </div>
  )
}
