/**
 * Teste de ponta a ponta das telas do Mapa e da Frota, com o Chromium do sistema.
 *
 * As duas nasceram de uma constatação: o backend tinha `GET /colonies` e `GET /vehicles`, e o HUD
 * não tinha botão nenhum para chegar neles. O diretório só era usado por dentro do Ministério, e
 * um Furgão despachado sumia da vista até voltar.
 *
 * **Roda primeiro**, antes de `mercado` e `acordos`: os três compartilham o mesmo banco efêmero, e
 * aqueles dois deixam furgões em rota. A Frota aqui espera encontrar os três ociosos, no pátio.
 *
 * A escolha de receita da Oficina (§24.5) **não** é coberta aqui: o painel vive atrás de um clique
 * num hexágono do Phaser, cuja posição depende da ordem dos anéis, e acertá-lo por coordenada
 * seria um teste que quebra ao primeiro ajuste de layout. A API dela tem teste em PHP
 * (`ComponentRecipesTest`).
 */
import {
  abrirNavegador,
  acharPorTexto,
  assentar,
  checar,
  clicar,
  clicarNaConstrucao,
  entrar,
  esperarTexto,
  falhas,
  relatar,
  textoDaPagina,
  todosPorTexto,
  irPara,
} from './comum.mjs'

const { navegador, page } = await abrirNavegador()

try {
  console.log('\nLogin')
  await entrar(page)
  checar(await esperarTexto(page, /Fert\$/), 'o HUD carrega e mostra Fert$')

  console.log('\nA faixa de eventos de mundo (A2.8)')
  await page.waitForSelector('[data-eventos]', { timeout: 8000 })
  checar(true, 'a faixa aparece quando há evento ativo — o motor não é invisível')
  checar(
    await esperarTexto(page, /Tempestade de poeira/),
    'o nome do evento anunciado aparece',
  )
  checar(
    await esperarTexto(page, /produção -20%/),
    'e o efeito também: o jogador vê POR QUE a produção caiu',
  )

  console.log('\nColônias inimigas no Quartel (A2.10) — o cerco que revoga o §01')
  await irPara(page, '/quartel')
  await page.waitForSelector('[data-secao="inimigos"]', { timeout: 8000 })
  checar(true, 'a seção de colônias inimigas aparece com guerra declarada')
  checar(
    await esperarTexto(page, /Fora de guerra a colônia é/),
    'a tela diz que fora de guerra a colônia é inviolável — os dois lados da revogação',
  )
  checar(
    await esperarTexto(page, /exposto:/),
    'e mostra o que está em risco: marchar sem saber o que se ganha é aposta',
  )
  checar(!!(await page.$('[data-atacar-colonia]')), 'o botão de marchar existe')

  console.log('\nOs botões que faltavam existem no HUD')
  checar(!!(await acharPorTexto(page, 'button', /^Mapa$/)), 'há botão para o Mapa')
  checar(!!(await acharPorTexto(page, 'button', /^Mapa$/)), 'há botão para o Mapa, ao lado da marca')
  checar(
    !(await todosPorTexto(page, 'button', 'Frota')).length,
    'o HUD não tem mais botão de Frota: ela mudou para dentro da Central de Transportes (D-59)',
  )

  // ---------------------------------------------------------------- o extrato bancário (D-94)
  console.log('\nClicar no Fert$ do HUD abre o extrato bancário')
  await clicar(page, '[data-abrir-extrato]')
  checar(await esperarTexto(page, /Extrato bancário/), 'o popup do extrato abre')
  checar(
    await esperarTexto(page, /Saldo inicial/),
    'o saldo inicial da fundação aparece, traduzido — não o slug cru',
  )
  checar(await esperarTexto(page, /\+100,00/), 'o saldo inicial mostra +100,00, positivo')
  await clicar(page, '[data-fechar-popup]')
  checar(!(await page.$('[data-popup]')), 'o extrato fecha')

  // ---------------------------------------------------------------- Bugs/Melhorias (D-95)
  console.log('\nBugs/Melhorias: manda uma mensagem')
  await clicar(page, '[data-abrir-bugs-melhorias]')
  checar(await esperarTexto(page, /Bugs\/Melhorias/), 'o painel abre')

  await page.select('[data-feedback-tipo]', 'bug')
  await page.type('[data-feedback-assunto]', 'A Muralha não constrói')
  await page.type('[data-feedback-mensagem]', 'Cliquei em construir e nada acontece, sem erro nenhum.')

  const enviar = await page.$('[data-enviar-feedback]')
  checar(await enviar.evaluate((b) => !b.disabled), 'o botão de enviar habilita com os campos preenchidos')
  await enviar.click()
  await page.waitForNetworkIdle({ idleTime: 800 })

  checar(await esperarTexto(page, /Enviado/), 'confirma o envio')
  checar(await esperarTexto(page, /um aviso pelo rádio/), 'e diz que a resposta chega pelo rádio, não aqui')

  await clicar(page, '[data-fechar-bugs-melhorias]')
  checar(!(await page.$('[data-tela="bugs-melhorias"]')), 'o painel fecha')

  // ---------------------------------------------------------------- Mapa
  console.log('\nAbre o Mapa')
  await (await acharPorTexto(page, 'button', /^Mapa$/)).click()
  checar(await esperarTexto(page, /Grade \d+×\d+/), 'o painel do Mapa abre')

  // O seeder põe o colono no slot de founder (0,3) e a Capital fica em (0,0): 3 slots (D-51).
  checar(await esperarTexto(page, /Grade 101×101/), 'o mapa diz o lado da grade, vindo da API')
  checar(await esperarTexto(page, /Capital em \(0, 0\)/), 'o mapa diz onde é a Capital')
  checar(await esperarTexto(page, /Você em \(0, 3\)/), 'o mapa diz onde você está')
  checar(await esperarTexto(page, /3 slots dela/), 'calcula a distância até a Capital')

  console.log('\nO mapa desenha')
  const svg = await page.$('svg[data-mapa]')
  checar(!!svg, 'o SVG do mapa renderiza')
  // Escopado ao `data-mapa`: `svg circle` casaria com outros SVGs do HUD.
  const circulos = await page.$$eval('svg[data-mapa] circle', (n) => n.length)
  // O seeder cria quatro colônias — e2e, vizinha, ré e autora. O diretório omite a própria, e o
  // mapa desenha as três vizinhas mais você. A Capital é losango, não círculo.
  checar(circulos === 4, `desenha as três vizinhas e você (achou ${circulos} círculos)`)

  console.log('\nA vista abre em 15×15 na altura, centrada em você (D-64/D-156)')
  /*
   * Esta é a prova do enquadramento, e não dá para tirá-la do desenho: um SVG que ninguém lê pode
   * estar em qualquer zoom. As RÉGUAS, sim, dizem que células o jogador está vendo.
   *
   * O seeder põe o colono em (0,3). A ALTURA da janela é sempre 15 linhas (D-64) — o eixo Y não
   * muda com a tela cheia (D-156): de -4 a 10. A LARGURA, essa, segue a proporção real do
   * contêiner (D-156) em vez de ficar presa em 15 colunas — o viewport do e2e é 1400×900 (`comum.
   * mjs`), mais largo que alto, e por isso mostra MAIS de 15 colunas, sempre simétrico em volta de
   * você (0,3 → x=0). Se o mapa abrisse no planeta inteiro (o de antes), seriam 11 números de 10 em
   * 10; se abrisse centrado na Capital, o Y iria de -7 a 7.
   */
  const rotulos = (n) => n.map((t) => t.textContent)
  const reguaX = await page.$$eval('svg[data-mapa] [data-regua-x] text', rotulos)
  const reguaY = await page.$$eval('svg[data-mapa] [data-regua-y] text', rotulos)
  checar(
    reguaX.length > 15 && Number(reguaX[0]) === -Number(reguaX.at(-1)),
    `a régua de X numera mais que 15 colunas (a tela do e2e é mais larga que alta), simétrica em torno de você (achou ${reguaX.length}: ${reguaX[0]}…${reguaX.at(-1)})`,
  )
  checar(
    reguaY.length === 15 && reguaY[0] === '-4' && reguaY[14] === '10',
    `a régua de Y numera 15 linhas, de -4 a 10 — o eixo que não cresce com a tela cheia (achou ${reguaY.length}: ${reguaY[0]}…${reguaY.at(-1)})`,
  )

  // Uma linha por borda de célula, mais os dois eixos da Capital. O mapa antigo tinha 18 linhas
  // decorativas fixas (9 por eixo) que não eram células nenhumas: o piso aqui as descarta.
  const grade = await page.$$eval('svg[data-mapa] line:not([stroke-dasharray])', (n) => n.length)
  checar(grade >= 30, `risca as linhas de X e de Y, uma por célula (achou ${grade})`)

  console.log('\nA vizinha aparece e é clicável')
  checar(await esperarTexto(page, /Colônia vizinha/), 'a vizinha aparece na lista lateral')

  const naLista = await acharPorTexto(page, 'button', /Colônia vizinha/)
  await naLista.click()
  await assentar()

  const texto = await textoDaPagina(page)
  checar(/\(0, 6\)/.test(texto), 'clicar mostra a posição da vizinha')
  // (0,3) até (0,6) são 3 slots exatos.
  checar(/3 slots/.test(texto), 'clicar mostra a distância até a vizinha')
  checar(/não é o Marco do GDD/.test(texto), 'o porte é rotulado como porte, não como Marco (D-38)')

  const linhas = await page.$$eval('svg[data-mapa] line[stroke-dasharray]', (n) => n.length)
  checar(linhas === 1, 'a reta até a colônia escolhida é traçada')

  console.log('\nVolta à colônia pelo botão Colônia do header (navegação global)')
  await clicar(page, '[data-nav-desktop="colonia"]')
  await assentar()
  checar(!(await textoDaPagina(page)).includes('Grade 101×101'), 'o Mapa fecha')

  // ---------------------------------------------------------------- Zoom (D-63)
  /*
   * O que o zoom pode quebrar é o ALINHAMENTO. A cena é do Phaser; o alvo de clique é um botão de
   * DOM sobreposto. Se o desenho aproximasse pela câmera do Phaser e os botões ficassem onde
   * estavam, o colono veria a Oficina grande no meio da tela e clicaria nela para acertar o
   * vizinho — um bug silencioso e cruel.
   *
   * Este teste aproxima e DEPOIS clica numa construção pelo nome. Se as duas contas divergirem,
   * o clique abre o painel errado (ou nenhum) e o teste cai.
   */
  console.log('\nO zoom da colônia, e o alinhamento que ele pode quebrar')
  const alvo = '[aria-label^="Central de Transportes"]'
  const antes = await page.$eval(alvo, (el) => el.getBoundingClientRect().width)

  await clicar(page, '[data-zoom-mais]')
  await clicar(page, '[data-zoom-mais]')

  const depois = await page.$eval(alvo, (el) => el.getBoundingClientRect().width)
  checar(
    depois > antes,
    `o alvo de clique cresce junto com o hexágono (${Math.round(antes)} → ${Math.round(depois)} px)`,
  )

  await clicar(page, '[data-zoom-centralizar]')
  const voltou = await page.$eval(alvo, (el) => el.getBoundingClientRect().width)
  checar(Math.abs(voltou - antes) < 2, 'e "centralizar" devolve o enquadramento')

  // Aproxima de novo e clica JÁ COM ZOOM: é este o teste que importa.
  await clicar(page, '[data-zoom-mais]')

  // ---------------------------------------------------------------- Frota
  console.log('\nAbre a Frota — com a colônia aproximada')
  // D-59: a Frota vive dentro da Central de Transportes, a construção que o GDD diz gerir os
  // veículos (§17.2, §28.5).
  await clicarNaConstrucao(page, 'Central de Transportes')
  checar(await esperarTexto(page, /Produção e gestão de Caminhões/), 'o painel diz o que a construção faz')
  await (await acharPorTexto(page, 'button', /Ver a Frota/)).click()
  checar(await esperarTexto(page, /Sua frota/), 'o painel da Frota abre')

  // O seeder dá um furgão no kit e cria mais dois.
  checar(await esperarTexto(page, /3 veículos/), 'conta os três veículos')
  checar(await esperarTexto(page, /3 ocioso/), 'os três estão ociosos antes dos outros testes')
  checar(await esperarTexto(page, /Furgão de Comércio/), 'o veículo aparece com nome legível')
  checar(await esperarTexto(page, /No pátio/), 'veículo ocioso é descrito como no pátio')
  checar(await esperarTexto(page, /6\.000 unidades/), 'mostra a capacidade do Furgão (§21.2)')

  console.log('\nVolta à colônia pelo botão Colônia do header')
  await clicar(page, '[data-nav-desktop="colonia"]')
  await assentar()
  checar(!(await textoDaPagina(page)).includes('Sua frota'), 'a Frota fecha')
} catch (e) {
  falhas.push(`exceção: ${e.message}`)
  try {
    await page.screenshot({ path: '/tmp/e2e-telas-falha.png' })
    console.log('\nscreenshot em /tmp/e2e-telas-falha.png')
  } catch {}
} finally {
  await navegador.close()
}

process.exit(relatar('Mapa e Frota'))
