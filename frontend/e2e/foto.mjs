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

  await page.screenshot({ path: '/tmp/foto-colonia.png' })
  console.log('colônia → /tmp/foto-colonia.png')

  // O cartão de detalhe: abre uma construção COM arte e fotografa a imagem grande.
  await clicarNaConstrucao(page, 'Reator de Energia')
  await new Promise((r) => setTimeout(r, 2500))
  await page.screenshot({ path: '/tmp/foto-detalhe.png' })
  console.log('detalhe → /tmp/foto-detalhe.png')

  await page.goto(`${BASE}/capital`, { waitUntil: 'domcontentloaded' })
  await assentar()
  await new Promise((r) => setTimeout(r, 2500))
  await page.screenshot({ path: '/tmp/foto-capital.png' })
  console.log('capital → /tmp/foto-capital.png')
} finally {
  await fecharNavegador(navegador)
}
