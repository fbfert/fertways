/**
 * Teste de ponta a ponta do seletor de fundação (D-51).
 *
 * O sorteio do D-29 morreu: o colono **escolhe** a célula. Esta prova registra um colono novo,
 * que cai no seletor, clica num slot de founder livre, dá um nome e funda — e só então o HUD
 * carrega. Exercita o disco de founders e o `POST /colony` com coordenada.
 *
 * Roda **por último** no `tools/e2e.sh`: funda uma quinta colônia, e rodar antes bagunçaria as
 * contagens de colônias das outras telas (o diretório do D-37 não tem névoa — todos se veem).
 */
import {
  abrirNavegador,
  BASE,
  acharPorTexto,
  checar,
  esperarTexto,
  falhas,
  janela,
  relatar, fecharNavegador } from './comum.mjs'

const { navegador, page } = await abrirNavegador()

try {
  // Enquanto o colono não fundou, `carregar()` chama /colony, /buildings e /queue e leva 404/422
  // de propósito — é assim que o app detecta que ainda não há colônia. São falhas esperadas, não
  // regressões; as asserções abaixo é que guardam o caminho feliz.
  janela.esperandoFalha = true

  console.log('\nRegistra um colono novo')
  await page.goto(BASE, { waitUntil: 'networkidle2' })
  await (await acharPorTexto(page, 'button', /Ainda não tenho conta/)).click()

  await page.type('input[placeholder="Nome"]', 'Fundador E2E')
  await page.type('input[placeholder^="Nickname"]', 'fundador-e2e')
  await page.type('input[type=email]', 'fundador-e2e@fertways.test')
  await page.type('input[type=password]', 'SenhaForte#2026')
  await Promise.all([
    (await acharPorTexto(page, 'button', /Criar colono/)).click(),
    page.waitForNetworkIdle({ idleTime: 800 }),
  ])

  console.log('\nCai no seletor de fundação')
  checar(await esperarTexto(page, /Escolha onde fundar/), 'o colono sem colônia vê o seletor')
  checar(!!(await page.$('svg[data-disco-founder]')), 'o disco de founders renderiza')

  console.log('\nAs duas abas desenham')
  await (await acharPorTexto(page, 'button', /^Periferia$/)).click()
  checar(!!(await page.$('svg[data-seletor-mapa]')), 'a aba Periferia desenha o planeta inteiro')
  await (await acharPorTexto(page, 'button', /Perto do Mercado/)).click()

  console.log('\nEscolhe um slot de founder livre')
  const slot = await page.$('[data-founder-slot]')
  checar(!!slot, 'há ao menos um slot de founder livre e clicável')
  await slot.click()
  checar(!!(await page.$('[data-celula-escolhida]')), 'a célula escolhida aparece no painel')

  console.log('\nFunda')
  await page.type('input[placeholder="Nova Aurora"]', 'Colônia do Fundador')
  await Promise.all([
    page.click('[data-fundar]'),
    page.waitForNetworkIdle({ idleTime: 800 }),
  ])

  checar(await esperarTexto(page, /Fert\$/), 'fundada a colônia, o HUD carrega e mostra Fert$')
  checar(!(await page.$('svg[data-disco-founder]')), 'o seletor some depois de fundar')
} catch (e) {
  falhas.push(`exceção: ${e.message}`)
  try {
    await page.screenshot({ path: '/tmp/e2e-fundacao-falha.png' })
    console.log('\nscreenshot em /tmp/e2e-fundacao-falha.png')
  } catch {}
} finally {
  await fecharNavegador(navegador)
}

process.exit(relatar('Seletor de Fundação'))
