/**
 * Teste de ponta a ponta das zonas neutras no mapa (D-52, Fatia 1).
 *
 * Abre o Mapa, confere que as 120 zonas desenham, exercita o zoom e o "centralizar", e ocupa uma
 * zona livre — o custo pesado (Posto + 20 Robôs) é debitado no servidor e a zona passa a ser sua.
 *
 * Roda depois de Mapa/Mercado/Acordo/Ministério (que dependem da colônia do e2e num estado
 * conhecido) e antes da Fundação: ocupar só gasta recursos, não mexe em contagem de veículo.
 */
import {
  abrirNavegador,
  acharPorTexto,
  checar,
  entrar,
  esperarTexto,
  falhas,
  relatar,
} from './comum.mjs'

const { navegador, page } = await abrirNavegador()

try {
  console.log('\nLogin')
  await entrar(page)
  checar(await esperarTexto(page, /Fert\$/), 'o HUD carrega e mostra Fert$')

  console.log('\nAbre o Mapa')
  await (await acharPorTexto(page, 'button', /^Mapa$/)).click()
  checar(await esperarTexto(page, /Fertways/), 'o painel do Mapa abre')

  console.log('\nAs 120 zonas desenham')
  // O mapa carrega colônias, zonas e frota de forma assíncrona; espera as zonas aparecerem.
  await page.waitForSelector('[data-zona]', { timeout: 8000 })
  const zonas = await page.$$eval('[data-zona]', (n) => n.length)
  checar(zonas === 120, `desenha as 120 zonas neutras (achou ${zonas})`)

  console.log('\nAs ferramentas de zoom e foco existem e respondem')
  for (const attr of ['data-zoom-in', 'data-zoom-out', 'data-centrar']) {
    const botao = await page.$(`[${attr}]`)
    checar(!!botao, `o botão ${attr} existe`)
    await botao.click()
  }

  console.log('\nOcupa uma zona livre')
  // A zona é célula única num canto, clipada fora do viewBox no zoom 1: dispara o clique do React
  // direto, sem depender de coordenada de tela.
  await page.$eval('[data-zona]', (el) => el.dispatchEvent(new MouseEvent('click', { bubbles: true })))
  checar(await esperarTexto(page, /Ocupar custa/), 'o painel da zona abre com o custo da ocupação')

  const ocupar = await page.$('[data-ocupar]')
  checar(!!ocupar, 'a zona livre oferece o botão Ocupar')
  await ocupar.click()

  // Ocupada: a lista lateral passa a contar uma zona sua, e o painel diz que está estabelecendo.
  checar(await esperarTexto(page, /Zonas \(1 suas\)/), 'a zona passa a ser sua')
  checar(await esperarTexto(page, /Estabelecendo/), 'a zona recém-ocupada está estabelecendo')
} catch (e) {
  falhas.push(`exceção: ${e.message}`)
  try {
    await page.screenshot({ path: '/tmp/e2e-zonas-falha.png' })
    console.log('\nscreenshot em /tmp/e2e-zonas-falha.png')
  } catch {}
} finally {
  await navegador.close()
}

process.exit(relatar('Zonas Neutras'))
