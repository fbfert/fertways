/**
 * Teste de ponta a ponta da tela do Mercado, com o Chromium do sistema.
 *
 * A tela foi ao ar sem nunca ter sido aberta num navegador: `tsc` e `oxlint` pegam erro de tipo
 * e de import, mas não pegam painel que não renderiza, `select` que não popula, nem campo de
 * preço que rejeita vírgula. Este teste abre a tela de verdade.
 *
 * Desde o D-58 ele cobre o que originou a mudança: **a oferta de outro colono tem de aparecer**.
 * O livro antigo casava as ordens no ato, então uma oferta que cruzava era executada antes de
 * qualquer um a ver — e a vitrine parecia deserta. Aqui a oferta da vizinha é vista e executada.
 *
 * Roda contra a pilha efêmera que `tools/e2e.sh` levanta (SQLite + artisan serve + vite dev).
 * Nunca contra produção: ele põe ofertas na vitrine e mexe em saldo.
 */
import {
  abrirCapital,
  abrirNavegador,
  acharPorTexto,
  assentar,
  checar,
  entrar,
  esperarTexto,
  falhas,
  janela,
  relatar,
  textoDaPagina,
} from './comum.mjs'

const { navegador, page } = await abrirNavegador()

try {
  console.log('\nLogin')
  await entrar(page)
  checar(await esperarTexto(page, /Fert\$/), 'o HUD carrega e mostra Fert$')

  console.log('\nAbre o Mercado')
  // D-59: o Mercado Central é o Pátio Logístico da Capital (slot 6), alcançado pelo mapa.
  await abrirCapital(page)
  await page.click('[data-abrir="mercado"]')
  checar(await esperarTexto(page, /Mercado Central/), 'o painel do Mercado abre')
  checar(await esperarTexto(page, /Doca e frota/), 'a aba da doca aparece')

  console.log('\nAs cinco abas do D-58')
  for (const rotulo of [
    /Ofertar entre colonos/,
    /Ver ofertas de colonos/,
    /Ofertar no Mercado Central/,
    /Ofertas globais/,
  ]) {
    checar(await esperarTexto(page, rotulo), `a aba "${rotulo.source}" existe`)
  }

  console.log('\nDoca e frota')
  checar(await esperarTexto(page, /Furgão de Comércio/), 'o veículo aparece com nome legível')
  checar(await esperarTexto(page, /ocioso/), 'o veículo está ocioso')
  checar(
    await esperarTexto(page, /No seu depósito na Capital/),
    'a "doca" passou a se chamar depósito na Capital',
  )
  checar(await esperarTexto(page, /500/), 'o depósito mostra o saldo de 500 de Metal Bruto')

  /*
   * O CORAÇÃO DO D-58: a oferta da vizinha (300 de Água) tem de estar visível, com o nome dela e
   * sem eu filtrar nada. Antes, era preciso adivinhar o recurso no seletor — e, mesmo acertando,
   * a linha vinha sem dono, indistinguível das próprias.
   */
  console.log('\nOfertas globais: a vitrine mostra a oferta de OUTRO colono')
  await (await acharPorTexto(page, 'button', /Ofertas globais/)).click()
  checar(await esperarTexto(page, /VENDE/), 'a vitrine mostra uma oferta de venda')
  checar(await esperarTexto(page, /Colônia vizinha/), 'a oferta traz o nome de quem a anunciou')
  checar(await esperarTexto(page, /Água/), 'a oferta da vizinha aparece sem eu ter filtrado nada')

  console.log('\nExecutar a oferta alheia move recurso e Fert$ sem veículo nenhum')
  const comprar = await acharPorTexto(page, 'button', /^Comprar$/)
  checar(await comprar.evaluate((b) => !b.disabled), 'o botão de comprar habilita')
  await comprar.click()
  await page.waitForNetworkIdle({ idleTime: 1000 })

  const aindaLa = await esperarTexto(page, /Colônia vizinha/, 1500)
  checar(!aindaLa, 'a oferta executada sai da vitrine')

  await (await acharPorTexto(page, 'button', /Doca e frota/)).click()
  const aguaNoDeposito = await esperarTexto(page, /Água/)
  checar(aguaNoDeposito, 'a Água comprada entrou no MEU depósito, sem viagem nenhuma')

  if (!aguaNoDeposito) {
    console.log('\n--- texto da tela no momento da falha ---')
    console.log((await textoDaPagina(page)).slice(0, 1500))
  }

  console.log('\nAnunciar no Mercado Central, com preço decimal em vírgula')
  await (await acharPorTexto(page, 'button', /Ofertar no Mercado Central/)).click()
  checar(await esperarTexto(page, /Na colônia/), 'o painel mostra o estoque da colônia')
  checar(
    await esperarTexto(page, /No depósito da Capital/),
    'e o do depósito, lado a lado — a regra dos dois estoques',
  )
  checar(await esperarTexto(page, /teto 10\.000/), 'o teto do recurso primário aparece')

  const [campoQtd, campoPreco] = await page.$$('input[inputmode]')
  await campoQtd.type('100')
  await campoPreco.type('0,05')

  checar(await esperarTexto(page, /Total: 5,00 Fert\$/), 'o total sai de "0,05" com vírgula')

  const anunciar = await acharPorTexto(page, 'button', /Anunciar venda/)
  checar(await anunciar.evaluate((b) => !b.disabled), 'o botão habilita com quantidade e preço')
  await anunciar.click()
  await page.waitForNetworkIdle({ idleTime: 800 })

  checar(await esperarTexto(page, /Suas ofertas na vitrine/), 'a seção das ofertas do colono existe')
  checar(
    await esperarTexto(page, /Venda de 100 de Metal Bruto a 0,0500 Fert\$/),
    'a oferta aparece como sua',
  )

  console.log('\nA oferta repousa, e o escrow sai do saldo do depósito')
  await (await acharPorTexto(page, 'button', /Doca e frota/)).click()
  checar(await esperarTexto(page, /400/), 'o escrow saiu do saldo no ato')

  console.log('\nCancelar devolve o escrow ao depósito')
  await (await acharPorTexto(page, 'button', /Ofertar no Mercado Central/)).click()
  await (await acharPorTexto(page, 'button', /^Cancelar$/)).click()
  await page.waitForNetworkIdle({ idleTime: 800 })
  checar(await esperarTexto(page, /Nenhuma oferta sua/), 'a oferta some ao cancelar')

  await (await acharPorTexto(page, 'button', /Doca e frota/)).click()
  checar(await esperarTexto(page, /500/), 'o recurso volta ao depósito, não ao estoque')

  console.log('\nDespacho: levar carga ao depósito da Capital')
  /*
   * O kit inicial traz raros, e as opções saem na ordem de `Object.entries`: o primeiro é um
   * raro do qual o colono tem punhados, não o Metal Bruto. Escolher o recurso, como o colono
   * faria — a primeira versão deste teste digitava 10 sobre o raro selecionado por padrão e o
   * botão desabilitava, corretamente, por falta de estoque.
   */
  const [selLevar] = await page.$$('select')
  await selLevar.select('metal_bruto')

  const numericos = await page.$$('input[inputmode=numeric]')
  await numericos[0].type('10')

  // Reachar o botão **depois** de digitar: o React re-renderiza o formulário a cada tecla, e o
  // handle antigo pode apontar para um nó já descartado.
  const despachar = await acharPorTexto(page, 'button', /^Despachar$/)
  checar(await despachar.evaluate((b) => !b.disabled), 'o botão de despacho habilita')
  await despachar.click()
  await page.waitForNetworkIdle({ idleTime: 800 })

  const emRota = await esperarTexto(page, /levando carga para a Capital/)
  checar(emRota, 'o veículo entra em rota rumo à Capital')

  if (!emRota) {
    console.log('\n--- texto da tela no momento da falha ---')
    console.log((await textoDaPagina(page)).slice(0, 1200))
  }

  console.log('\nDiretório: enviar carga a outro colono')
  /*
   * Os `select` da aba, em ordem: [0] recurso de "Levar ao depósito", [1] destino e [2] recurso
   * de "Enviar a outro colono", [3] recurso de "Buscar no depósito".
   */
  const selects = await page.$$('select')
  const rotulos = await selects[1].$$eval('option', (os) => os.map((o) => o.textContent.trim()))
  checar(
    rotulos.includes('vizinha · 3 slots'),
    `o diretório lista a vizinha e a distância até ela (viu: ${rotulos.join(' | ')})`,
  )

  await selects[2].select('metal_bruto')

  // O segundo furgão: o teste anterior deixou o primeiro em rota para a Capital.
  const camposDeQtd = await page.$$('input[inputmode=numeric]')
  await camposDeQtd[1].type('7')

  const enviar = await acharPorTexto(page, 'button', /^Enviar$/)
  checar(await enviar.evaluate((b) => !b.disabled), 'o botão de envio habilita')
  await enviar.click()
  await page.waitForNetworkIdle({ idleTime: 800 })

  // O pagamento do diretório: a rota deixa de dizer "a colônia #2" e nomeia o colono.
  const rumoAVizinha = await esperarTexto(page, /levando carga para a colônia de vizinha/)
  checar(rumoAVizinha, 'o veículo entra em rota rumo ao slot da vizinha, que o diretório nomeia')

  if (!rumoAVizinha) {
    console.log('\n--- texto da tela no momento da falha ---')
    console.log((await textoDaPagina(page)).slice(0, 1200))
  }

  // ─────────────────────────────────────────────────────────────────────────
  // A 6ª aba: veículos usados (D-60, fatia 3). É a única mercadoria do Mercado com escrow do
  // MINISTÉRIO — ele é o cartório da placa, e por isso aqui não há calote, ao contrário do que
  // vale para recursos entre colonos (D-58). A tela tem de dizer isso.
  console.log('\nVeículos usados — a 6ª aba, com escrow do Ministério')
  await (await acharPorTexto(page, 'button', /Veículos usados/)).click()
  await page.waitForSelector('[data-aba="usados"]')

  checar(
    await esperarTexto(page, /vendedor só recebe na chegada/),
    'a tela explica o escrow: sem calote, ao contrário do resto do Mercado',
  )
  checar(await esperarTexto(page, /À venda no planeta/), 'a vitrine de usados abre')

  // O vendedor anuncia um furgão do pátio.
  const seletor = await page.$('[data-usado-veiculo]')
  checar(seletor !== null, 'há veículo no pátio para anunciar')

  const opcoes = await page.$$eval('[data-usado-veiculo] option', (os) =>
    os.map((o) => o.value).filter(Boolean),
  )
  checar(opcoes.length > 0, `o seletor lista os veículos do pátio (${opcoes.length})`)

  await page.select('[data-usado-veiculo]', opcoes[0])
  checar(
    await esperarTexto(page, /Furgão não tem teto de revenda/),
    'e explica por que o Furgão não tem teto: ele não tem preço de fábrica (D-60, aditivo 14)',
  )

  await page.type('[data-usado-preco]', '80')
  await page.click('[data-anunciar-usado]')
  checar(
    await esperarTexto(page, /Ele continua seu e no pátio até alguém comprar/),
    'o anúncio entra, e o veículo continua do vendedor até a venda',
  )
  checar(await esperarTexto(page, /80 F\$/), 'o anúncio aparece na vitrine com o preço pedido')

  // E o dono pode desistir enquanto ninguém comprou.
  await page.click('[data-cancelar-anuncio]')
  checar(await esperarTexto(page, /Anúncio retirado/), 'o vendedor pode retirar o anúncio')

  console.log('\nLogout')
  const guardado = await page.evaluate(() => localStorage.getItem('fertways.token'))
  checar(!!guardado, 'o token está no localStorage enquanto a sessão vive')

  await (await acharPorTexto(page, 'button', /^×$/)).click()
  await (await acharPorTexto(page, 'button', /^Sair$/)).click()
  await page.waitForNetworkIdle({ idleTime: 800 })

  checar(await esperarTexto(page, /entrar|login|e-mail/i), 'sair devolve à tela de login')
  checar(
    (await page.evaluate(() => localStorage.getItem('fertways.token'))) === null,
    'o token some do localStorage',
  )

  // O ponto do logout: o token velho tem de morrer **no servidor**. Apagá-lo do navegador
  // sozinho deixaria uma credencial válida para sempre — token do Sanctum não expira.
  janela.esperandoFalha = true
  const status = await page.evaluate(async (t) => {
    const r = await fetch('/central/colony', {
      headers: { Accept: 'application/json', Authorization: `Bearer ${t}` },
    })
    return r.status
  }, guardado)
  await assentar()
  janela.esperandoFalha = false

  checar(status === 401, `o token revogado já não autentica (recebeu ${status})`)
} catch (e) {
  falhas.push(`exceção: ${e.message}`)
  try {
    await page.screenshot({ path: '/tmp/e2e-falha.png' })
    console.log('\nscreenshot em /tmp/e2e-falha.png')
  } catch {}
} finally {
  await navegador.close()
}

process.exit(relatar('Mercado'))
