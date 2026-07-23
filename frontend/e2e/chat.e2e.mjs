/**
 * O rádio do planeta (§10, D-77) e o card "quem é esse colono" (D-81).
 *
 * Prova o que só um DOM de verdade prova: clicar no nick de outro colono, no canal público, abre
 * uma privada com ele; clicar no nick dentro da privada abre as informações dele — colônia, posição
 * e as zonas que ocupa, sem vazar guarnição, depósito, recursos, saldo ou reputação (D-81).
 */
import {
  abrirNavegador,
  acharPorTexto,
  assentar,
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

  console.log('\nAbre o Chat')
  await page.click('[data-abrir-chat]')
  await assentar()
  checar(!!(await page.$('[data-tela="chat"]')), 'o painel do Chat abre')
  checar(await esperarTexto(page, /Rádio do planeta/), 'e mostra o cabeçalho')

  console.log('\nO Global mostra a fala semeada da vizinha, com nick clicável')
  checar(await esperarTexto(page, /Alguém compra Água/), 'a fala da vizinha aparece')
  const nickVizinha = await acharPorTexto(page, '[data-abrir-privada]', /^vizinha$/)
  checar(!!nickVizinha, 'o nick dela é um botão clicável — o seu próprio nick não seria')

  console.log('\nClicar no nick abre uma privada com ela')
  await nickVizinha.click()
  await assentar()
  checar(!!(await page.$('[data-ver-info]')), 'a conversa abre já com vizinha como destino')

  console.log('\nManda uma mensagem, e ela aparece')
  await page.type('input[placeholder^="Para vizinha"]', 'Vendo Ligas, quer trocar?')
  await page.keyboard.press('Enter')
  await assentar()
  checar(await esperarTexto(page, /Vendo Ligas, quer trocar/), 'a mensagem sai e aparece na conversa')

  console.log('\nClicar no nick da conversa abre as informações da vizinha')
  await page.click('[data-ver-info]')
  await assentar()
  checar(!!(await page.$('[data-popup]')), 'o popup de informações abre')
  checar(await esperarTexto(page, /Colônia vizinha/), 'mostra o nome da colônia dela')
  checar(await esperarTexto(page, /\(0, 6\)/), 'e a posição — pública, como no diretório')
  checar(await esperarTexto(page, /Zonas neutras ocupadas \(0\)/), 'e quantas zonas ocupa')

  // O que a névoa do Drone protege (D-74) não pode vazar aqui — nem para uma zona, nem para o
  // saldo/frota/reputação da colônia. Nada disso tem por que aparecer no popup.
  const corpo = await page.$eval('[data-info-jogador]', (n) => n.textContent ?? '')
  checar(!/guarni|depósito|fert\$|reputa/i.test(corpo), 'sem guarnição, depósito, saldo ou reputação')

  console.log('\nFecha o popup e volta pra conversa')
  await page.click('[data-fechar-popup]')
  await assentar()
  checar(!(await page.$('[data-popup]')), 'o popup fecha')
  checar(await esperarTexto(page, /Vendo Ligas, quer trocar/), 'e a conversa continua aberta atrás dele')

  // ═══════════════════════════════════════════════════ do MAPA para o chat (D-86)
  console.log('\nFecha o Chat e abre o Mapa')
  await page.click('[data-fechar-chat]')
  await assentar()
  await (await acharPorTexto(page, 'button', /^Mapa$/)).click()
  checar(await esperarTexto(page, /Grade \d+×\d+/), 'o painel do Mapa abre')

  console.log('\nClica na vizinha no diretório do mapa')
  const vizinhaNoMapa = await acharPorTexto(page, 'button', /Colônia vizinha/)
  checar(!!vizinhaNoMapa, 'a vizinha aparece no diretório')
  await vizinhaNoMapa.click()
  await assentar()

  const nickNoPainel = await page.$('[data-abrir-info]')
  checar(!!nickNoPainel, 'o nick dela no painel da colônia é clicável')
  await nickNoPainel.click()
  await assentar()
  checar(!!(await page.$('[data-popup]')), 'a ficha da vizinha abre')

  console.log('\n"Conversar" sai do mapa e abre o chat já na privada dela')
  const conversar = await page.$('[data-conversar]')
  checar(!!conversar, 'o botão "Conversar" existe na ficha')
  await conversar.click()
  await assentar()
  checar(!(await page.$('[data-tela="zona"], svg[data-mapa]')), 'saiu do mapa')
  checar(!!(await page.$('[data-tela="chat"]')), 'o Chat abriu')
  checar(
    !!(await page.$('input[placeholder^="Para vizinha"]')),
    'já na privada com a vizinha, sem precisar buscar de novo',
  )

  // ═══════════════════════════════════════════════════ a LUPA (D-81, aditivo)
  console.log('\nVolta às conversas e abre a busca de jogadores')
  await (await acharPorTexto(page, 'button', /← conversas/)).click()
  await assentar()

  await page.click('[data-buscar-jogador]')
  await assentar()
  checar(!!(await page.$('[data-busca-jogador]')), 'a lupa abre a busca, dentro de Privadas')

  console.log('\nBuscar por um pedaço do nick encontra a vizinha')
  await page.type('[data-buscar-texto]', 'vizi')
  await assentar()
  const resultado = await acharPorTexto(page, '[data-resultado-busca]', /vizinha/)
  checar(!!resultado, 'a vizinha aparece nos resultados')

  console.log('\nClicar no resultado abre as informações dela — não uma conversa nova')
  await resultado.click()
  await assentar()
  checar(!!(await page.$('[data-popup]')), 'o popup de informações abre direto da busca')
  checar(await esperarTexto(page, /Colônia vizinha/), 'com o nome certo')

  await page.click('[data-fechar-popup]')
  await assentar()
  checar(!!(await page.$('[data-busca-jogador]')), 'fechar o popup mantém a busca aberta')

  console.log('\nFechar a busca some com os resultados')
  await page.click('[data-fechar-busca]')
  await assentar()
  checar(!(await page.$('[data-busca-jogador]')), 'a busca fecha')

  // ═══════════════════════════════════════════════ o link de conversa no popup da busca
  console.log('\nO popup da busca também tem o link de mensagem privada — item 4 do pedido do usuário')
  await page.click('[data-buscar-jogador]')
  await assentar()
  await page.type('[data-buscar-texto]', 'vizi')
  await assentar()
  const resultadoDeNovo = await acharPorTexto(page, '[data-resultado-busca]', /vizinha/)
  await resultadoDeNovo.click()
  await assentar()

  const conversarDaBusca = await page.$('[data-conversar]')
  checar(!!conversarDaBusca, 'o botão "Conversar" existe no popup aberto pela busca')
  await conversarDaBusca.click()
  await assentar()
  checar(!!(await page.$('[data-tela="chat"]')), 'clicar abre o Chat')
  checar(
    !!(await page.$('input[placeholder^="Para vizinha"]')),
    'já na privada com quem foi buscado',
  )
} catch (e) {
  falhas.push(`exceção: ${e.message}`)
  try {
    await page.screenshot({ path: '/tmp/e2e-chat-falha.png' })
    console.log('\nscreenshot em /tmp/e2e-chat-falha.png')
  } catch {}
} finally {
  await navegador.close()
}

process.exit(relatar('Chat'))
