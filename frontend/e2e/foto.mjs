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
  /*
   * ⚠️ Mas ANTES de dispensá-lo, fotografe-o (A2.V6, D-236).
   *
   * O resumo é a tela onde a cesta de evento vira notícia (D-235), e ela nunca tinha sido
   * fotografada — o script sempre fechou o popup como estorvo. Enquanto ele mostrava só produção e
   * obras isso passava; agora ele carrega uma lista de recursos que pode ser longa, dentro de um
   * `Popup`, e "cabe na tela?" é pergunta que só a foto responde.
   */
  const temResumo = await page.$('[data-resumo]')
  if (temResumo) {
    await page.screenshot({ path: '/tmp/foto-resumo.png' })
    console.log('resumo → /tmp/foto-resumo.png')
    console.log('  presentes na tela:', JSON.stringify(await medirResumo(page)))
  } else {
    console.log('⚠️ o resumo não apareceu — sem ele a seção de presentes não é conferível')
  }

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
   * ⚠️ A faixa de eventos NO TELEFONE, com os três eventos que a produção tem (A2.V6, D-236).
   *
   * No desktop a faixa é uma linha por evento e ninguém repara. Em 390 px cada frase quebra em
   * várias linhas, e três delas empilhadas podem virar uma parede sobre a colmeia — que é
   * `pointer-events-none` e portanto nem se fecha. É a terceira vez que uma barra flutuante desta
   * tela cobre conteúdo (D-215, D-217), e as duas primeiras passaram por todos os testes.
   */
  await page.setViewport({ width: 390, height: 844 })
  await new Promise((r) => setTimeout(r, 1800))
  await page.screenshot({ path: '/tmp/foto-colonia-mobile.png' })
  console.log('colônia mobile → /tmp/foto-colonia-mobile.png')
  console.log('  faixa mobile (fechada):', JSON.stringify(await medirFaixa(page)))

  /*
   * ABERTA e DISPENSADA (D-236). Os três estados da faixa importam por motivos diferentes: fechada
   * é o que o jogador vê sempre, aberta é o pior caso de altura, e dispensada tem de devolver a
   * colmeia inteira — se o × não sumir com ela, o conserto não consertou nada.
   */
  const abriuFaixa = await page.evaluate(() => {
    const b = document.querySelector('[data-eventos-detalhes]')
    b?.click()

    return Boolean(b)
  })
  if (abriuFaixa) {
    await new Promise((r) => setTimeout(r, 700))
    await page.screenshot({ path: '/tmp/foto-colonia-mobile-aberta.png' })
    console.log('colônia mobile, faixa aberta → /tmp/foto-colonia-mobile-aberta.png')
    console.log('  faixa mobile (aberta):', JSON.stringify(await medirFaixa(page)))
  } else {
    console.log('⚠️ não achei o botão de detalhes da faixa')
  }

  await page.evaluate(() => document.querySelector('[data-eventos-fechar]')?.click())
  await new Promise((r) => setTimeout(r, 700))
  await page.screenshot({ path: '/tmp/foto-colonia-mobile-sem-faixa.png' })
  console.log('colônia mobile, faixa dispensada → /tmp/foto-colonia-mobile-sem-faixa.png')
  console.log('  faixa depois do ×:', JSON.stringify(await medirFaixa(page)))

  await page.setViewport({ width: 1400, height: 900 })
  await page.reload({ waitUntil: 'domcontentloaded' })
  await assentar()
  await new Promise((r) => setTimeout(r, 2000))

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

  /*
   * O MERCADO CENTRAL — o sistema mais exercitado do jogo (1.448 ordens executadas em produção,
   * medido no D-226) e, até aqui, o único que nunca tinha sido fotografado.
   */
  await page.goto(`${BASE}/mercado/central`, { waitUntil: 'domcontentloaded' })
  await assentar()
  await new Promise((r) => setTimeout(r, 2500))
  await page.screenshot({ path: '/tmp/foto-mercado.png' })
  console.log('mercado → /tmp/foto-mercado.png')

  // A aba das OFERTAS — é ali que moram as 1.448 execuções, e é a superfície mais usada do jogo.
  const abriu = await page.evaluate(() => {
    const aba = [...document.querySelectorAll('button')].find((b) =>
      /ofertas globais/i.test(b.textContent ?? ''),
    )
    aba?.click()

    return Boolean(aba)
  })
  if (abriu) {
    await new Promise((r) => setTimeout(r, 2000))
    await page.screenshot({ path: '/tmp/foto-mercado-ofertas.png' })
    console.log('ofertas → /tmp/foto-mercado-ofertas.png')
  } else {
    console.log('⚠️ não achei a aba de ofertas globais')
  }
} finally {
  await fecharNavegador(navegador)
}

/**
 * A seção de presentes cabe no popup, ou transborda? (A2.V6, D-236)
 *
 * Não afirma nada sobre beleza. Diz quantos itens há, onde a seção começa e acaba, e se o fundo dela
 * passou da janela — que é a única forma de o jogador não conseguir ler o que recebeu.
 */
async function medirResumo(page) {
  return page.evaluate(() => {
    const sec = document.querySelector('[data-resumo-presentes]')
    if (!sec) return { erro: 'sem seção de presentes no DOM' }

    const b = sec.getBoundingClientRect()
    const popup = document.querySelector('[data-resumo]')?.getBoundingClientRect()

    return {
      itens: sec.querySelectorAll('li').length,
      eventos: sec.querySelectorAll('ul').length,
      topo: Math.round(b.top),
      base: Math.round(b.bottom),
      janela: window.innerHeight,
      popup_base: popup ? Math.round(popup.bottom) : null,
      transborda_a_janela: b.bottom > window.innerHeight,
    }
  })
}

/**
 * A faixa de eventos cobre quanto da tela? (A2.V6, D-236)
 *
 * Três eventos empilhados num telefone é o caso que ninguém olhou. `pointer-events-none` significa
 * que o jogador não consegue tirá-la do caminho — então a altura dela é o número que importa.
 */
async function medirFaixa(page) {
  return page.evaluate(() => {
    const f = document.querySelector('[data-eventos]')
    if (!f) return { erro: 'faixa não está no DOM' }

    const b = f.getBoundingClientRect()

    /*
     * ⚠️ E o × precisa ser ALCANÇÁVEL, não só existir.
     *
     * Um botão de fechar coberto por outro controle é pior do que nenhum: o jogador vê a saída e
     * clica no zoom. `elementFromPoint` no centro dele responde quem de fato recebe o toque — é a
     * única medida que alcança oclusão, e a lição do D-217.
     */
    const x = f.querySelector('[data-eventos-fechar]')
    const xb = x?.getBoundingClientRect()
    const noCentro = xb
      ? document.elementFromPoint((xb.left + xb.right) / 2, (xb.top + xb.bottom) / 2)
      : null

    return {
      eventos: f.querySelectorAll('[data-evento]').length,
      topo: Math.round(b.top),
      base: Math.round(b.bottom),
      altura: Math.round(b.height),
      janela: window.innerHeight,
      // Quanto da altura útil da tela a faixa ocupa. É o número que decide se virou parede.
      por_cento_da_tela: Math.round((b.height / window.innerHeight) * 100),
      fechar_recebe_o_toque: noCentro ? noCentro.closest('[data-eventos-fechar]') !== null : null,
      fechar_coberto_por: noCentro && !noCentro.closest('[data-eventos-fechar]')
        ? (noCentro.getAttribute('aria-label') ?? noCentro.className ?? noCentro.tagName)
        : null,
    }
  })
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
