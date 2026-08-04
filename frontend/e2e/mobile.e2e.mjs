/**
 * A navegação mobile — reforma mobile-first do HUD (D-103) e reforma de navegação global (pedido
 * do usuário): a barra inferior deixou de existir só na rota `/` e agora acompanha qualquer tela.
 *
 * O resto da suíte roda a 1400×900 e nunca alcançaria este código: a barra mobile é `md:hidden`, e
 * o header de sempre é `hidden md:flex`. Sem um teste num viewport estreito, nada prova que os
 * ícones da barra inferior realmente abrem/navegam para o que prometem — só a checagem visual
 * manual provaria, e essa não roda sozinha no CI.
 */
import {
  clicar,
  abrirNavegador, assentar, checar, entrar, esperarSumir, esperarTexto, falhas, relatar, fecharNavegador } from './comum.mjs'

const { navegador, page } = await abrirNavegador({ width: 390, height: 844 })

/**
 * `true` se o botão daquele destino está com o destaque de rota ativa (texto na cor rust).
 *
 * Confere o TOKEN exato `text-rust` entre as classes — `.includes('text-rust')` sozinho pegaria
 * falso-positivo em `hover:text-rust`/`active:text-rust`, que o estado inativo também tem.
 */
const estaAtivo = (marcador) =>
  page.$eval(`[data-nav="${marcador}"]`, (b) => b.className.split(/\s+/).includes('text-rust'))

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

  console.log('\nNa colônia, "Colônia" começa destacada (reforma de navegação: o destaque segue a rota)')
  checar(await estaAtivo('colonia'), 'Colônia está ativa em "/"')
  checar(!(await estaAtivo('mapa')), 'Mapa não está ativo')

  console.log('\nChat: abre pela barra, fecha pelo × do próprio painel')
  await clicar(page, '[data-nav="chat"]')
  await assentar()
  checar(!!(await page.$('[data-tela="chat"]')), 'o painel do Chat abre')
  await clicar(page, '[data-fechar-chat]')
  // ⚠️ Espera SUMIR: `assentar()` sozinho é uma aposta de 300 ms, e o painel ainda aberto cobre o
  // botão que o passo seguinte vai procurar. Ver `esperarSumir` em comum.mjs.
  await esperarSumir(page, '[data-tela="chat"]')

  console.log('\nMapa: navega de verdade (rota própria, D-67) — e a barra continua na tela')
  await clicar(page, '[data-nav="mapa"]')
  await assentar()
  checar(page.url().endsWith('/mapa'), 'a URL vira /mapa')
  checar(await esperarTexto(page, /Grade \d+×\d+/), 'o Mapa carrega')
  checar(!!(await page.$('[data-nav-mobile]')), 'a barra inferior continua visível fora da colônia')
  checar(await estaAtivo('mapa'), 'Mapa passa a estar ativo')
  checar(!(await estaAtivo('colonia')), 'Colônia deixa de estar ativa')

  console.log('\nDa Mapa direto para a Capital — sem precisar voltar à colônia primeiro')
  await clicar(page, '[data-nav="capital"]')
  await assentar()
  checar(page.url().endsWith('/capital'), 'a URL vira /capital')
  checar(await esperarTexto(page, /Governo de Fertways/), 'a Capital carrega')
  checar(await estaAtivo('capital'), 'Capital passa a estar ativa')

  console.log('\n"Colônia" agora navega para "/" — não abre mais o antigo sheet de recursos')
  await clicar(page, '[data-nav="colonia"]')
  await assentar()
  checar(new URL(page.url()).pathname === '/', 'a URL volta para "/"')
  checar(await estaAtivo('colonia'), 'Colônia volta a estar ativa')

  console.log('\n"Mais" reúne o que sobrou do header: Marco, Missões, Obras e zonas, Bugs/Melhorias, Perfil, Sair')
  await clicar(page, '[data-nav="mais"]')
  await assentar()
  checar(await esperarTexto(page, /Marco \d/), 'mostra o Marco')
  checar(!!(await page.$('[data-abrir-missoes-mobile]')), 'tem o link de Missões')
  checar(!!(await page.$('[data-abrir-obras-e-zonas-mobile]')), 'tem o link de Obras e zonas')
  checar(!!(await page.$('[data-abrir-bugs-melhorias-mobile]')), 'tem o link de Bugs/Melhorias')
  checar(!!(await page.$('[data-abrir-perfil-mobile]')), 'tem o link do Perfil')
  checar(!!(await page.$('[data-sair-mobile]')), 'tem o botão Sair')

  console.log('\nObras e zonas abre por cima do "Mais" — o que sobrou do antigo sheet de Colônia')
  await clicar(page, '[data-abrir-obras-e-zonas-mobile]')
  await assentar()
  checar(!!(await page.$('[data-tela="obras-e-zonas"]')), 'o painel de Obras e zonas abre')
  checar(await esperarTexto(page, /Fila de construção/), 'mostra a fila')
  await clicar(page, '[data-fechar-obras-e-zonas]')
  // ⚠️ Espera SUMIR: `assentar()` sozinho é uma aposta de 300 ms, e o painel ainda aberto cobre o
  // botão que o passo seguinte vai procurar. Ver `esperarSumir` em comum.mjs.
  await esperarSumir(page, '[data-tela="obras-e-zonas"]')

  await clicar(page, '[data-nav="mais"]')
  await assentar()

  console.log('\nMissões abre por cima do "Mais", como Bugs/Melhorias')
  await clicar(page, '[data-abrir-missoes-mobile]')
  await assentar()
  checar(!!(await page.$('[data-tela="missoes"]')), 'o painel de Missões abre')
  await clicar(page, '[data-fechar-missoes]')
  // ⚠️ Espera SUMIR: `assentar()` sozinho é uma aposta de 300 ms, e o painel ainda aberto cobre o
  // botão que o passo seguinte vai procurar. Ver `esperarSumir` em comum.mjs.
  await esperarSumir(page, '[data-tela="missoes"]')

  await clicar(page, '[data-nav="mais"]')
  await assentar()

  console.log('\nBugs/Melhorias abre por cima do "Mais" (fecha o overflow, abre o painel)')
  await clicar(page, '[data-abrir-bugs-melhorias-mobile]')
  await assentar()
  checar(!!(await page.$('[data-tela="bugs-melhorias"]')), 'o painel de Bugs/Melhorias abre')
  await clicar(page, '[data-fechar-bugs-melhorias]')
  // ⚠️ Espera SUMIR: `assentar()` sozinho é uma aposta de 300 ms, e o painel ainda aberto cobre o
  // botão que o passo seguinte vai procurar. Ver `esperarSumir` em comum.mjs.
  await esperarSumir(page, '[data-tela="bugs-melhorias"]')

  console.log('\nSair: de dentro do Mapa (uma tela sem × próprio agora) — prova que o header é mesmo global')
  await clicar(page, '[data-nav="mapa"]')
  await assentar()
  await clicar(page, '[data-nav="mais"]')
  await assentar()
  await clicar(page, '[data-sair-mobile]')
  await assentar()
  checar(await esperarTexto(page, /Sair da conta\?/), 'pede confirmação antes de sair')
  await clicar(page, '[data-confirmar-sair-mobile]')
  await assentar()
  checar(await esperarTexto(page, /entrar|cadastr/i), 'confirmar devolve à landing/login')
} catch (e) {
  falhas.push(`exceção: ${e.message}`)
  try {
    await page.screenshot({ path: '/tmp/e2e-mobile-falha.png' })
    console.log('\nscreenshot em /tmp/e2e-mobile-falha.png')
  } catch {}
} finally {
  await fecharNavegador(navegador)
}

process.exit(relatar('Mobile'))
