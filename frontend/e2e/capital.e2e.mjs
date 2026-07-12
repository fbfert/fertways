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
  abrirCapital,
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
  // D-59: o botão do HUD morreu. A Capital é o losango do mapa.
  await abrirCapital(page)
  checar(await esperarTexto(page, /Governo de Fertways/), 'o hub da Capital abre')

  // ─────────────────────────────────────────────────────────────────────────
  // D-63: a Capital deixou de ser um menu e virou uma CENA. Os slots são hexágonos,
  // e as três áreas (Endurance, Mercado/Pátio, Espaçoporto) são alvos próprios.
  console.log('\nA Capital é um lugar, não um menu (D-63)')
  await page.waitForSelector('[data-cena-capital]')
  checar(true, 'a cena da Capital renderiza')

  const slot6 = await page.$('[data-slot-capital="6"]')
  checar(
    slot6 === null,
    'o slot 6 NÃO está no Governo Central: ele É o Leste (Mercado + Pátio são a mesma área)',
  )

  for (const n of [1, 2, 3, 4, 5, 7, 8, 9, 20]) {
    const h = await page.$(`[data-slot-capital="${n}"]`)
    checar(h !== null, `o slot ${n} está no Governo Central`)
  }

  const vago = await page.$eval('[data-slot-capital="20"]', (el) => el.disabled)
  checar(vago === true, 'os slots vagos aparecem, mas não clicam — a Capital vai crescer')

  // ─────────────────────────────────────────────────────────────────────────
  // O ZOOM (D-63). O que ele pode quebrar é o alinhamento: o desenho é do Phaser e o alvo de
  // clique é um botão de DOM. Se as duas contas divergirem, o jogador clica num hexágono e
  // acerta o vizinho. Este teste prova que, DEPOIS de aproximar, o clique ainda acerta.
  console.log('\nO zoom, e o alinhamento que ele pode quebrar')
  const antesDoZoom = await page.$eval('[data-slot-capital="2"]', (el) => el.getBoundingClientRect().width)

  await page.click('[data-cena-capital] [data-zoom-mais]')
  await page.click('[data-cena-capital] [data-zoom-mais]')

  const depoisDoZoom = await page.$eval('[data-slot-capital="2"]', (el) => el.getBoundingClientRect().width)
  checar(
    depoisDoZoom > antesDoZoom,
    `o alvo de clique cresce junto com o desenho (${Math.round(antesDoZoom)} → ${Math.round(depoisDoZoom)} px)`,
  )

  console.log('\nCentral de Tributos / Tesouro (slot 2) — clicado JÁ COM ZOOM')
  await page.click('[data-slot-capital="2"]')
  checar(
    await esperarTexto(page, /Saldo do Tesouro/),
    'o clique acerta o slot certo mesmo aproximado — o botão e o hexágono não divergiram',
  )
  checar(await esperarTexto(page, /Painel de taxas/), 'o painel de taxas do §8.3 aparece')
  const tributos = await textoDaPagina(page)
  checar(/3%/.test(tributos) && /2%/.test(tributos) && /1%/.test(tributos), 'as alíquotas 3/2/1% aparecem')

  console.log('\nVolta e abre a Secretaria de Finanças (slot 4)')
  await page.click('[data-voltar-capital]')
  await page.waitForSelector('[data-cena-capital] [data-zoom-centralizar]')
  await page.click('[data-cena-capital] [data-zoom-centralizar]')
  await page.click('[data-slot-capital="4"]')
  checar(await esperarTexto(page, /Preços de referência/), 'a tela de Finanças abre')
  checar(await esperarTexto(page, /Metal Bruto/), 'a tabela de preços-base do §06 aparece')
  checar(await esperarTexto(page, /Indicadores/), 'os indicadores econômicos aparecem')

  console.log('\nVolta e abre a Central de Notícias (slot 3)')
  await page.click('[data-voltar-capital]')
  await page.click('[data-slot-capital="3"]')
  checar(await esperarTexto(page, /Telescópio Gagarin/), 'a tela de Notícias abre')
  checar(await esperarTexto(page, /inativo/), 'o Gagarin aparece honestamente inativo (§12.1)')
  checar(await esperarTexto(page, /Servidor aberto/), 'o comunicado semeado aparece no mural')

  console.log('\nOs destroços da Endurance (Oeste)')
  await page.click('[data-voltar-capital]')
  await page.click('[data-area="oeste"]')
  await page.waitForSelector('[data-tela="endurance"]')
  checar(await esperarTexto(page, /nunca voltará a voar/), 'a Endurance conta a sua história')
  checar(
    await esperarTexto(page, /não repousa sobre o casco/),
    'e resolve a contradição do GDD: o Gagarin é satélite orbital, não está no casco (D-47)',
  )
  checar(
    await esperarTexto(page, /não há nada a fazer aqui ainda/),
    'e admite honestamente que as missões não existem',
  )

  console.log('\nO Espaçoporto (Sul)')
  await page.click('[data-voltar-capital]')
  await page.click('[data-area="sul"]')
  await page.waitForSelector('[data-tela="espacoporto"]')
  checar(await esperarTexto(page, /Ninguém viaja daqui ainda/), 'o Espaçoporto admite que não abriu')
  for (const p of ['Kalidor', 'Veyra', 'Auryn', 'Solène', 'Drakmoor']) {
    checar(await esperarTexto(page, new RegExp(p)), `o planeta ${p} aparece, com o que o GDD publica`)
  }

  console.log('\nVolta e abre o Ministério dos Transportes (slot 8)')
  await page.click('[data-voltar-capital]')
  await page.click('[data-slot-capital="8"]')
  await page.waitForSelector('[data-tela="transportes"]')
  checar(await esperarTexto(page, /Caminhão de Carga/), 'a fábrica do governo abre')
  checar(await esperarTexto(page, /300 F\$/), 'o preço do D-60 aparece')
  checar(
    await esperarTexto(page, /privativo deste Ministério/),
    'a tela diz que a Central de Transportes não fabrica mais — o GDD (§17.2) diz que sim, e o jogador precisa saber onde a fábrica foi parar',
  )

  console.log('\nO registro de placas (§16.3)')
  checar(await esperarTexto(page, /Registro de Placas/), 'o registro abre')
  checar(await esperarTexto(page, /FW-\d{5}-F/), 'os Furgões do colono têm placa')

  const prateleira = await page.$eval('[data-estoque]', (el) => el.getAttribute('data-estoque'))
  checar(prateleira === '2', `o governo tem 2 caminhões prontos (veio ${prateleira})`)

  console.log('\nCompra um Caminhão de Carga')
  const vagasAntes = await page.$eval('[data-vagas]', (el) => el.getAttribute('data-vagas'))
  await page.click('[data-comprar-caminhao]')

  checar(
    await esperarTexto(page, /vem dirigindo da Capital/),
    'a entrega é física: o caminhão dirige-se da Capital até a colônia (D-60)',
  )
  checar(await esperarTexto(page, /FW-\d{5}-C/), 'o Caminhão comprado traz a sua placa, com o C do tipo')

  // A prateleira do governo baixou e a vaga do colono foi ocupada: a venda mexeu nos dois lados.
  const depois = await page.$eval('[data-estoque]', (el) => el.getAttribute('data-estoque'))
  checar(depois === '1', `a prateleira do governo baixou de 2 para 1 (veio ${depois})`)

  const vagasDepois = await page.$eval('[data-vagas]', (el) => el.getAttribute('data-vagas'))
  checar(
    Number(vagasDepois) === Number(vagasAntes) - 1,
    `a compra ocupou uma vaga da frota (${vagasAntes} → ${vagasDepois})`,
  )

  console.log('\nA frota envelhece (§16.4) — a manutenção')
  // O seeder gastou um dos furgões de propósito: sem desgaste não há o que reparar.
  const gasto = await page.$('[data-reparar]')
  checar(gasto !== null, 'o veículo gasto oferece o botão de manutenção')

  const antes = await page.$eval('[data-conservacao]', (el) => Number(el.getAttribute('data-conservacao')))
  checar(antes < 100, `o furgão do seeder está desgastado (${antes}%)`)
  checar(
    await esperarTexto(page, /Anda a \d+% e carrega/),
    'a tela diz o que o desgaste FAZ — velocidade e capacidade, não só um número',
  )

  await page.click('[data-reparar]')
  checar(await esperarTexto(page, /reparado — voltou a/), 'a manutenção acontece')
  checar(
    await esperarTexto(page, /o teto caiu para 95%/),
    'e ela corrói a vida útil: o teto cai 5 pontos (§16.4)',
  )

  console.log('\nA sucata só acontece se o dono mandar')
  const sucatearBtn = await acharPorTexto(page, 'button', /^Sucatear$/)
  await sucatearBtn.click()
  checar(
    await esperarTexto(page, /Não volta, e nada é devolvido/),
    'ela pede confirmação e avisa que não há devolução (D-60)',
  )
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
