/**
 * Teste de ponta a ponta do hub da Capital e das três instituições novas (§02).
 *
 * O hub é o diretório dos slots do governo. Este teste abre o hub pelo HUD, confere que os slots
 * aparecem, e entra nas três instituições construídas nesta rodada: Central de Tributos/Tesouro (2),
 * Secretaria de Finanças (4) e Central de Pesquisas e Notícias (3). É só leitura — não clica em
 * Mercado nem Ministério (slots 6/7), que apenas reusam as telas de topo.
 *
 * O `tools/e2e.sh` semeia um comunicado ("Servidor aberto") para o mural ter o que mostrar.
 */
import {
  abrirNavegador,
  acharPorTexto,
  checar,
  entrar,
  esperarTexto,
  falhas,
  relatar,
  textoDaPagina,
} from './comum.mjs'

const { navegador, page } = await abrirNavegador()

try {
  console.log('\nLogin')
  await entrar(page)
  checar(await esperarTexto(page, /Fert\$/), 'o HUD carrega e mostra Fert$')

  console.log('\nAbre a Capital')
  await (await acharPorTexto(page, 'button', /^Capital$/)).click()
  checar(await esperarTexto(page, /Governo de Fertways/), 'o hub da Capital abre')

  console.log('\nO diretório lista as instituições')
  for (const nome of [
    'Central de Tributos',
    'Secretaria de Finanças',
    'Central de Pesquisas e Notícias',
    'Ministério das Reputações',
    'Ministério da Segurança e Guerra',
  ]) {
    checar(await esperarTexto(page, new RegExp(nome)), `o slot "${nome}" aparece`)
  }
  checar(await esperarTexto(page, /em breve/), 'a Guerra (slot 5) aparece marcada "em breve"')

  console.log('\nCentral de Tributos / Tesouro (slot 2)')
  await page.waitForSelector('[data-abrir="tesouro"]')
  await page.click('[data-abrir="tesouro"]')
  checar(await esperarTexto(page, /Saldo do Tesouro/), 'a tela do Tesouro abre')
  checar(await esperarTexto(page, /Painel de taxas/), 'o painel de taxas do §8.3 aparece')
  const tributos = await textoDaPagina(page)
  checar(/3%/.test(tributos) && /2%/.test(tributos) && /1%/.test(tributos), 'as alíquotas 3/2/1% aparecem')

  console.log('\nVolta e abre a Secretaria de Finanças (slot 4)')
  await page.click('[data-voltar-capital]')
  await page.waitForSelector('[data-abrir="financas"]')
  await page.click('[data-abrir="financas"]')
  checar(await esperarTexto(page, /Preços de referência/), 'a tela de Finanças abre')
  checar(await esperarTexto(page, /Metal Bruto/), 'a tabela de preços-base do §06 aparece')
  checar(await esperarTexto(page, /Indicadores/), 'os indicadores econômicos aparecem')

  console.log('\nVolta e abre a Central de Notícias (slot 3)')
  await page.click('[data-voltar-capital]')
  await page.waitForSelector('[data-abrir="noticias"]')
  await page.click('[data-abrir="noticias"]')
  checar(await esperarTexto(page, /Telescópio Gagarin/), 'a tela de Notícias abre')
  checar(await esperarTexto(page, /inativo/), 'o Gagarin aparece honestamente inativo (§12.1)')
  checar(await esperarTexto(page, /Servidor aberto/), 'o comunicado semeado aparece no mural')
} catch (e) {
  falhas.push(`exceção: ${e.message}`)
  try {
    await page.screenshot({ path: '/tmp/e2e-capital-falha.png' })
    console.log('\nscreenshot em /tmp/e2e-capital-falha.png')
  } catch {}
} finally {
  await navegador.close()
}

process.exit(relatar('Capital'))
