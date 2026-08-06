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

  await page.goto(`${BASE}/capital`, { waitUntil: 'domcontentloaded' })
  await assentar()
  await new Promise((r) => setTimeout(r, 2500))
  await page.screenshot({ path: '/tmp/foto-capital.png' })
  console.log('capital → /tmp/foto-capital.png')
} finally {
  await fecharNavegador(navegador)
}
