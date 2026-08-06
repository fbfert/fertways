/**
 * Fotografa a colônia e a Capital (docs/decisoes.md D-68).
 *
 * **O e2e prova que CLICA; não prova que está CERTO na tela.** É a lição do D-63, que custou caro: os
 * sete ministérios da Capital saíram **pálidos**, iguais aos slots vagos, e as sete suítes e2e
 * passaram — porque os cliques funcionavam (os alvos são botões de DOM) e só o **desenho** mentia. Um
 * canvas não tem DOM: nenhum teste de clique e nenhum teste de texto o alcança.
 *
 * Quando se mexe em cena de Phaser — cor, posição, rótulo, sprite —, **fotografe e olhe**.
 *
 *     node e2e/foto.mjs        # com a pilha do e2e.sh já de pé
 */
import { abrirNavegador, BASE, clicarNaConstrucao, entrar, assentar, fecharNavegador } from './comum.mjs'

const { navegador, page } = await abrirNavegador()

try {
  await entrar(page)
  await assentar()
  await new Promise((r) => setTimeout(r, 2500)) // a arte carrega depois da cena (D-68)

  /*
   * O resumo se convida sozinho (A2.0.3) e cobre metade da colmeia. Rodando a suíte inteira ele já
   * vinha dispensado — `resumo.e2e.mjs` o fecha —, mas com `E2E_SO_FOTOS=1` nenhuma suíte roda e a
   * foto saía com um popup por cima do que se queria olhar.
   */
  const fechou = await page.evaluate(() => {
    const botao = [...document.querySelectorAll('button')].find((b) => b.textContent?.trim() === 'Continuar')
    botao?.click()

    return Boolean(botao)
  })
  if (fechou) await new Promise((r) => setTimeout(r, 1200))

  /*
   * As caixas do chrome, impressas para PODEREM SER CONFERIDAS a olho junto com a foto. Duas
   * ocultações já nasceram de barra flutuante por cima de conteúdo (a faixa de eventos, e depois a
   * coluna do HUD), e as duas passaram por todos os testes de texto e de clique.
   */
  const caixas = await page.evaluate(() => {
    const r = (sel) => {
      const el = document.querySelector(sel)
      if (!el) return null
      const b = el.getBoundingClientRect()

      return { top: Math.round(b.top), bottom: Math.round(b.bottom), left: Math.round(b.left), right: Math.round(b.right) }
    }

    /*
     * ⚠️ O `<header>` é uma caixa VAZIA de largura inteira (`inset-x-0`, `pointer-events-none`): o
     * que se vê são os chips dentro dele. Medir o contêiner diria que tudo colide com tudo.
     */
    const filhos = [...(document.querySelector('header')?.children ?? [])].flatMap((c) =>
      [...c.children].map((n) => {
        const b = n.getBoundingClientRect()

        return { texto: (n.textContent ?? '').trim().slice(0, 18), top: Math.round(b.top), bottom: Math.round(b.bottom), left: Math.round(b.left), right: Math.round(b.right) }
      }),
    )

    return { barra: r('header'), chips: filhos, faixa: r('[data-eventos]'), avisos: r('[data-secao="avisos"]') }
  })
  console.log('caixas:', JSON.stringify(caixas))

  await page.screenshot({ path: '/tmp/foto-colonia.png' })
  console.log('colônia → /tmp/foto-colonia.png')

  /*
   * ⚠️ A pulsação (A2.V3) — e foto nenhuma prova movimento.
   *
   * Dois quadros do MESMO recorte, separados por meio ciclo (~1,4 s de um ciclo de 2,8 s). Se os
   * bytes forem idênticos, nada se moveu: ou a `update()` da cena parou de ser chamada, ou a lista
   * de pulsantes ficou vazia. É grosseiro de propósito — não afirma que a animação está bonita,
   * afirma que ela **existe**, que é o que um redesenho quebrado apagaria em silêncio.
   */
  const recorte = { x: 560, y: 200, width: 480, height: 480 }
  const a = await page.screenshot({ clip: recorte })
  await new Promise((r) => setTimeout(r, 1400))
  const b = await page.screenshot({ clip: recorte })
  console.log(
    a.equals(b)
      ? '⚠️ pulsação: NADA se moveu entre os dois quadros'
      : `pulsação: viva (${a.length} vs ${b.length} bytes)`,
  )

  // O cartão de detalhe: abre uma construção COM arte e fotografa a imagem grande.
  await clicarNaConstrucao(page, 'Reator de Energia')
  await new Promise((r) => setTimeout(r, 2500))
  await page.screenshot({ path: '/tmp/foto-detalhe.png' })
  console.log('detalhe → /tmp/foto-detalhe.png')

  /*
   * O painel de uma construção TRAVADA (A2.V3). O selo no hexágono diz que algo parou; é aqui que o
   * jogador lê **o quê e por quê**, e é texto que nenhum teste de clique confere.
   *
   * A Fazenda é a escolhida porque o `e2e.sh` enche a Biomassa até o teto logo antes destas fotos —
   * sem esse preparo, a colônia recém-semeada não tem nenhuma construção travada para fotografar, e
   * o cartão sairia igual ao de qualquer outra.
   */
  await page.keyboard.press('Escape')
  await new Promise((r) => setTimeout(r, 600))
  await clicarNaConstrucao(page, 'Fazenda')
  await new Promise((r) => setTimeout(r, 2000))
  await page.screenshot({ path: '/tmp/foto-travada.png' })
  console.log('travada → /tmp/foto-travada.png')

  /*
   * E o painel de uma fábrica de BOCA FECHADA (D-219) — o estado mais comum do jogo real, e o que
   * mais precisa de texto: "parada" e "parada" são a mesma palavra para duas ações opostas (gastar
   * o que sobrou, ou trazer o que falta).
   */
  await page.keyboard.press('Escape')
  await new Promise((r) => setTimeout(r, 600))
  await clicarNaConstrucao(page, 'Refinaria Química')
  await new Promise((r) => setTimeout(r, 2000))
  await page.screenshot({ path: '/tmp/foto-sem-insumo.png' })
  console.log('sem insumo → /tmp/foto-sem-insumo.png')

  // O MAPA (A2.V4). Vale a mesma regra do canvas: nenhum teste de clique alcança o que está coberto.
  await page.goto(`${BASE}/mapa`, { waitUntil: 'domcontentloaded' })
  await assentar()
  await new Promise((r) => setTimeout(r, 2500))
  await page.screenshot({ path: '/tmp/foto-mapa.png' })
  console.log('mapa → /tmp/foto-mapa.png')
  console.log('  régua x:', JSON.stringify(await medirRegua(page)))

  /*
   * O painel de uma zona LIVRE (A2.V4, D-224): o custo real e o que falta. É texto vindo do
   * servidor, e texto é justamente o que nenhum teste de clique confere.
   */
  const clicou = await page.evaluate(() => {
    const zona = document.querySelector('[data-zona]')
    if (!zona) return false
    zona.dispatchEvent(new MouseEvent('click', { bubbles: true }))

    return true
  })
  if (clicou) {
    await new Promise((r) => setTimeout(r, 1800))
    await page.screenshot({ path: '/tmp/foto-zona-livre.png' })
    console.log('zona livre → /tmp/foto-zona-livre.png')
  } else {
    console.log('⚠️ nenhuma zona no mapa para fotografar')
  }

  /*
   * E o mapa no MOBILE, num viewport próprio: lá as barras são DUAS (topo `p-3` e a fixa de baixo),
   * e uma régua que fugiu de uma pode ter caído na outra.
   */
  await page.setViewport({ width: 390, height: 844 })
  await new Promise((r) => setTimeout(r, 1500))
  await page.screenshot({ path: '/tmp/foto-mapa-mobile.png' })
  console.log('mapa mobile → /tmp/foto-mapa-mobile.png')
  console.log('  régua x:', JSON.stringify(await medirRegua(page)))
  await page.setViewport({ width: 1400, height: 900 })
  await new Promise((r) => setTimeout(r, 1200))

  await page.goto(`${BASE}/capital`, { waitUntil: 'domcontentloaded' })
  await assentar()
  await new Promise((r) => setTimeout(r, 2500))
  await page.screenshot({ path: '/tmp/foto-capital.png' })
  console.log('capital → /tmp/foto-capital.png')
} finally {
  await fecharNavegador(navegador)
}

/**
 * A régua do X está visível, ou caiu atrás de alguma barra?
 *
 * Compara a caixa dos números com a de **cada barra de navegação** — as duas, porque no mobile são
 * duas (a de cima `absolute` e a de baixo `fixed`) e a régua só tem dois lugares para morar.
 */
async function medirRegua(page) {
  return page.evaluate(() => {
    const regua = document.querySelector('[data-regua-x]')
    if (!regua) return { erro: 'sem régua no DOM' }

    const r = regua.getBoundingClientRect()
    if (r.width === 0 || r.height === 0) return { erro: 'régua com caixa zero' }

    const barras = [...document.querySelectorAll('header, nav')]
      .flatMap((b) => [...b.children])
      .map((n) => n.getBoundingClientRect())
      .filter((b) => b.width > 0 && b.height > 0)

    const cruza = barras.filter(
      (b) => r.left < b.right && r.right > b.left && r.top < b.bottom && r.bottom > b.top,
    )

    // O topo do que cobre: é a folga que a régua precisa ganhar para escapar.
    const barraDeBaixo = cruza.reduce((menor, b) => Math.min(menor, b.top), Infinity)

    return {
      topo: Math.round(r.top),
      base: Math.round(r.bottom),
      janela: window.innerHeight,
      coberta_por: cruza.length,
      barra_de_baixo_comeca: Number.isFinite(barraDeBaixo) ? Math.round(barraDeBaixo) : null,
    }
  })
}
