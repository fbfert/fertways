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
  acharPorTexto,
  abrirNavegador,
  assentar,
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

  console.log('\nOs botões que faltavam existem no HUD')
  checar(!!(await acharPorTexto(page, 'button', /^Mapa$/)), 'há botão para o Mapa')
  checar(!!(await acharPorTexto(page, 'button', /^Frota$/)), 'há botão para a Frota')

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

  // ---------------------------------------------------------------- Frota
  console.log('\nAbre a Frota')
  await (await acharPorTexto(page, 'button', /^Frota$/)).click()
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
