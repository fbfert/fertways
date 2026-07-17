/**
 * A navegação mobile da colônia (`MobileNav.tsx`) — reforma mobile-first do HUD, pedido do usuário.
 *
 * O resto da suíte roda a 1400×900 e nunca alcançaria este código: a barra mobile é `md:hidden`, e
 * o header de sempre é `hidden md:flex`. Sem um teste num viewport estreito, nada prova que os cinco
 * ícones da barra inferior realmente abrem o que prometem — só a checagem visual manual provaria, e
 * essa não roda sozinha no CI.
 */
import { abrirNavegador, assentar, checar, entrar, esperarTexto, falhas, relatar } from './comum.mjs'

const { navegador, page } = await abrirNavegador({ width: 390, height: 844 })

try {
  console.log('\nLogin, num viewport de telefone (390×844)')
  await entrar(page)
  checar(await esperarTexto(page, /Fert\$/), 'o HUD carrega e mostra Fert$')

  console.log('\nSó a barra mobile aparece — o header de desktop fica oculto')
  const headers = await page.$$('header')
  let headersVisiveis = 0
  for (const h of headers) {
    const caixa = await h.boundingBox()
    if (caixa && caixa.width > 0 && caixa.height > 0) headersVisiveis++
  }
  checar(headersVisiveis === 1, 'exatamente um header visível — o desktop (hidden md:flex) some')
  checar(!!(await page.$('[data-nav-mobile]')), 'a barra inferior existe')

  console.log('\nSem estouro horizontal — a razão de ser desta reforma')
  const semEstouro = await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)
  checar(semEstouro, 'document.documentElement.scrollWidth não passa de innerWidth')

  console.log('\nChat: abre pela barra, fecha pelo × do próprio painel')
  await page.click('[data-nav="chat"]')
  await assentar()
  checar(!!(await page.$('[data-tela="chat"]')), 'o painel do Chat abre')
  await page.click('[data-fechar-chat]')
  await assentar()
  checar(!(await page.$('[data-tela="chat"]')), 'e fecha')

  console.log('\nColônia: o sheet com abas que substitui as duas barras laterais do desktop')
  await page.click('[data-nav="colonia"]')
  await assentar()
  checar(!!(await page.$('[data-tela="colonia-sheet"]')), 'o sheet abre')
  checar(await esperarTexto(page, /Recursos primários/), 'começa na aba Recursos, com a lista de sempre')
  await page.click('[data-aba-colonia="obras"]')
  await assentar()
  checar(await esperarTexto(page, /Fila de construção/), 'a aba Obras e zonas mostra a fila')
  await page.click('[data-fechar-colonia-sheet]')
  await assentar()
  checar(!(await page.$('[data-tela="colonia-sheet"]')), 'e fecha')

  console.log('\n"Mais" reúne o que sobrou do header: Marco, Missões, Bugs/Melhorias, Perfil, Sair')
  await page.click('[data-nav="mais"]')
  await assentar()
  checar(await esperarTexto(page, /Marco \d/), 'mostra o Marco')
  checar(!!(await page.$('[data-abrir-missoes-mobile]')), 'tem o link de Missões')
  checar(!!(await page.$('[data-abrir-bugs-melhorias-mobile]')), 'tem o link de Bugs/Melhorias')
  checar(!!(await page.$('[data-abrir-perfil-mobile]')), 'tem o link do Perfil')
  checar(!!(await page.$('[data-sair-mobile]')), 'tem o botão Sair')

  console.log('\nMissões abre por cima do "Mais", como Bugs/Melhorias')
  await page.click('[data-abrir-missoes-mobile]')
  await assentar()
  checar(!!(await page.$('[data-tela="missoes"]')), 'o painel de Missões abre')
  await page.click('[data-fechar-missoes]')
  await assentar()

  await page.click('[data-nav="mais"]')
  await assentar()

  console.log('\nBugs/Melhorias abre por cima do "Mais" (fecha o overflow, abre o painel)')
  await page.click('[data-abrir-bugs-melhorias-mobile]')
  await assentar()
  checar(!!(await page.$('[data-tela="bugs-melhorias"]')), 'o painel de Bugs/Melhorias abre')
  await page.click('[data-fechar-bugs-melhorias]')
  await assentar()

  console.log('\nMapa: navega de verdade (rota própria, D-67)')
  await page.click('[data-nav="mapa"]')
  await assentar()
  checar(page.url().endsWith('/mapa'), 'a URL vira /mapa')
  checar(await esperarTexto(page, /Fertways/), 'o Mapa carrega')

  console.log('\nVolta e vai à Capital pela barra')
  await page.goBack()
  await assentar()
  await page.click('[data-nav="capital"]')
  await assentar()
  checar(page.url().endsWith('/capital'), 'a URL vira /capital')
  checar(await esperarTexto(page, /Governo de Fertways/), 'a Capital carrega')

  console.log('\nSair: confirma Sim/Não, como no desktop')
  await page.goBack()
  await assentar()
  await page.click('[data-nav="mais"]')
  await assentar()
  await page.click('[data-sair-mobile]')
  await assentar()
  checar(await esperarTexto(page, /Sair da conta\?/), 'pede confirmação antes de sair')
  await page.click('[data-confirmar-sair-mobile]')
  await assentar()
  checar(await esperarTexto(page, /entrar|cadastr/i), 'confirmar devolve à landing/login')
} catch (e) {
  falhas.push(`exceção: ${e.message}`)
  try {
    await page.screenshot({ path: '/tmp/e2e-mobile-falha.png' })
    console.log('\nscreenshot em /tmp/e2e-mobile-falha.png')
  } catch {}
} finally {
  await navegador.close()
}

process.exit(relatar('Mobile'))
