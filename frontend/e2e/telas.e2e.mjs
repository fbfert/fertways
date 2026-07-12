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
  clicarNaConstrucao,
  entrar,
  esperarTexto,
  falhas,
  relatar,
  textoDaPagina,
  todosPorTexto,
} from './comum.mjs'

const { navegador, page } = await abrirNavegador()

try {
  console.log('\nLogin')
  await entrar(page)
  checar(await esperarTexto(page, /Fert\$/), 'o HUD carrega e mostra Fert$')

  console.log('\nOs botões que faltavam existem no HUD')
  checar(!!(await acharPorTexto(page, 'button', /^Mapa$/)), 'há botão para o Mapa')
  checar(!!(await acharPorTexto(page, 'button', /^Mapa$/)), 'há botão para o Mapa, ao lado da marca')
  checar(
    !(await todosPorTexto(page, 'button', 'Frota')).length,
    'o HUD não tem mais botão de Frota: ela mudou para dentro da Central de Transportes (D-59)',
  )

  // ---------------------------------------------------------------- Mapa
  console.log('\nAbre o Mapa')
  await (await acharPorTexto(page, 'button', /^Mapa$/)).click()
  checar(await esperarTexto(page, /Fertways/), 'o painel do Mapa abre')

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

  console.log('\nA vista abre em 15×15, centrada em você (D-64)')
  /*
   * Esta é a prova do enquadramento, e não dá para tirá-la do desenho: um SVG que ninguém lê pode
   * estar em qualquer zoom. As RÉGUAS, sim, dizem que células o jogador está vendo.
   *
   * O seeder põe o colono em (0,3). Uma janela de 15 colunas centrada nele vai de -7 a 7; a de 15
   * linhas, de -4 a 10. Se o mapa abrisse no planeta inteiro (o de antes), seriam 11 números de 10
   * em 10; se abrisse centrado na Capital, o Y iria de -7 a 7.
   */
  const rotulos = (n) => n.map((t) => t.textContent)
  const reguaX = await page.$$eval('svg[data-mapa] [data-regua-x] text', rotulos)
  const reguaY = await page.$$eval('svg[data-mapa] [data-regua-y] text', rotulos)
  checar(
    reguaX.length === 15 && reguaX[0] === '-7' && reguaX[14] === '7',
    `a régua de X numera 15 colunas, de -7 a 7 (achou ${reguaX.length}: ${reguaX[0]}…${reguaX.at(-1)})`,
  )
  checar(
    reguaY.length === 15 && reguaY[0] === '-4' && reguaY[14] === '10',
    `a régua de Y numera 15 linhas, de -4 a 10 (achou ${reguaY.length}: ${reguaY[0]}…${reguaY.at(-1)})`,
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

  console.log('\nFecha o Mapa')
  await (await acharPorTexto(page, 'button', /^×$/)).click()
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

  await page.click('[data-zoom-mais]')
  await page.click('[data-zoom-mais]')

  const depois = await page.$eval(alvo, (el) => el.getBoundingClientRect().width)
  checar(
    depois > antes,
    `o alvo de clique cresce junto com o hexágono (${Math.round(antes)} → ${Math.round(depois)} px)`,
  )

  await page.click('[data-zoom-centralizar]')
  const voltou = await page.$eval(alvo, (el) => el.getBoundingClientRect().width)
  checar(Math.abs(voltou - antes) < 2, 'e "centralizar" devolve o enquadramento')

  // Aproxima de novo e clica JÁ COM ZOOM: é este o teste que importa.
  await page.click('[data-zoom-mais]')

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

  console.log('\nFecha a Frota')
  await (await acharPorTexto(page, 'button', /^×$/)).click()
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
