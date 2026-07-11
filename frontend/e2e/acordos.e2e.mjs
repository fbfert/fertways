/**
 * Teste de ponta a ponta da tela do Acordo de Troca (§26.5), com o Chromium do sistema.
 *
 * O backend do Acordo foi ao ar sem tela nenhuma. O que este teste guarda, acima de tudo, é o
 * número **bruto** do formulário de entrega: quem promete 100 e despacha 100 entrega 97, porque o
 * tributo come a carga na chegada (D-41). Um colono que caloteia por três unidades de tributo é um
 * bug de interface, não uma escolha dele.
 *
 * Roda depois do teste do Mercado, contra o mesmo banco efêmero: `tools/e2e.sh` semeia um acordo
 * proposto pela vizinha e um terceiro furgão ocioso.
 */
import {
  abrirNavegador,
  acharPorTexto,
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

  /*
   * D-58: o Acordo deixou de ser tela de topo. Ele virou aba do Mercado — negócio é assunto do
   * Mercado —, e o botão "Acordos" saiu do HUD.
   */
  console.log('\nAbre o Mercado e vai à aba de ofertar entre colonos')
  // D-59: os Acordos entre colonos vivem dentro do Mercado Local — "comércio direto com
  // vizinhos" (§17.2). Clica-se na construção, e ela abre a tela já na aba certa.
  await clicarNaConstrucao(page, 'Mercado Local')
  await (await acharPorTexto(page, 'button', /Abrir os Acordos/)).click()
  checar(await esperarTexto(page, /Mercado Central/), 'o painel do Mercado abre')

  await (await acharPorTexto(page, 'button', /Ofertar entre colonos/)).click()
  checar(
    await esperarTexto(page, /Aqui se negocia o que está na sua colônia/),
    'a aba diz de que estoque este canal vive',
  )

  console.log('\nO mural: a oferta aberta da vizinha, sem contraparte')
  await (await acharPorTexto(page, 'button', /Ver ofertas de colonos/)).click()
  checar(await esperarTexto(page, /Colônia vizinha/), 'a oferta aberta da vizinha aparece no mural')
  checar(await esperarTexto(page, /50 de Biomassa/), 'o mural diz o que ela dá')
  checar(await esperarTexto(page, /50 de Metal Bruto/), 'e o que ela quer em troca')

  const aceitar = await acharPorTexto(page, 'button', /^Aceitar$/)
  await aceitar.click()
  await page.waitForNetworkIdle({ idleTime: 1000 })
  checar(
    !(await esperarTexto(page, /Colônia vizinha/, 1500)),
    'aceita, a oferta sai do mural: quem chega primeiro leva',
  )

  console.log('\nMeus acordos')
  await (await acharPorTexto(page, 'button', /Ofertar entre colonos/)).click()
  await (await acharPorTexto(page, 'button', /Meus acordos/)).click()

  console.log('\nConfiança Comercial')
  checar(await esperarTexto(page, /Confiança Comercial/), 'o índice do §26.2 aparece na tela')
  checar(await esperarTexto(page, /500\s*\/\s*1000/), 'o colono nasce em 500 de 1000 (D-43)')
  checar(
    await esperarTexto(page, /Cumprir um acordo rende 10\. Caloteirar custa 50/),
    'a tela publica os números arbitrados no D-43',
  )

  console.log('\nO acordo que a vizinha propôs')
  checar(await esperarTexto(page, /Proposta de vizinha/), 'o acordo proposto pela vizinha aparece')
  checar(await esperarTexto(page, /Você promete/), 'a tela separa o que cada lado promete')
  // Água 100 (0,0062) + Metal Bruto 100 (0,0333) = 3,95 Fert$, abaixo do piso de 500 do §26.3.
  checar(
    await esperarTexto(page, /Vale 3,95 Fert\$ somando os dois lados/),
    'o piso anti-farming do D-43 é dito ao colono, não escondido',
  )

  console.log('\nAceitar: só a contraparte fecha o aperto de mão')
  await (await acharPorTexto(page, 'button', /^Aceitar$/)).click()
  await page.waitForNetworkIdle({ idleTime: 800 })
  checar(await esperarTexto(page, /Aceito/), 'o acordo passa a aceito')

  console.log('\nEntrega: o bruto, não o prometido')
  checar(await esperarTexto(page, /Entregar a vizinha/), 'o formulário de entrega aparece ao aceitar')

  const despachar = await acharPorTexto(page, 'button', /^Despachar/)
  const rotulo = await despachar.evaluate((b) => b.textContent.trim())
  checar(
    rotulo === 'Despachar 103',
    `o botão embarca o bruto de 103, não os 100 prometidos (leu: "${rotulo}")`,
  )
  checar(
    await esperarTexto(page, /o tributo da entrega come a diferença no caminho/),
    'a tela explica por que são 103 e não 100',
  )
  checar(
    await esperarTexto(page, /Vai o Furgão de Comércio, que leva 6\.000/),
    'a tela nomeia o veículo que vai partir e sua capacidade',
  )

  await despachar.click()
  await page.waitForNetworkIdle({ idleTime: 800 })

  /*
   * A promessa só é abatida quando a carga **chega** — o acordo não move um grama (D-40). O que se
   * pode provar aqui é que o veículo partiu: era o último ocioso.
   */
  const partiu = await esperarTexto(page, /Nenhum veículo ocioso/)
  checar(partiu, 'o despacho consome o furgão: não sobra veículo ocioso')

  if (!partiu) {
    console.log('\n--- texto da tela no momento da falha ---')
    console.log((await textoDaPagina(page)).slice(0, 1500))
  }

  console.log('\nPropor um acordo dirigido')
  await (await acharPorTexto(page, 'button', /^Nova oferta$/)).click()

  // D-58: as duas modalidades convivem. A dirigida é a que já existia; a aberta vai ao mural.
  checar(
    await esperarTexto(page, /Um colono específico/),
    'a tela deixa escolher entre oferta dirigida e oferta aberta',
  )
  checar(await esperarTexto(page, /Aberta no mural/), 'e a aberta é a outra opção')

  checar(await esperarTexto(page, /O mínimo é/), 'o prazo mínimo do D-42 vem do backend')

  const prazo = await page.$('input[type=datetime-local]')
  const valor = await prazo.evaluate((i) => i.value)
  checar(!!valor, `o campo de prazo nasce preenchido, com folga sobre o mínimo (leu: "${valor}")`)

  /*
   * Os `select` da aba, em ordem: [0] contraparte, [1] o recurso que eu prometo, [2] o que ela
   * promete. Os dois lados abrem em Metal Bruto, o primeiro dos negociáveis.
   */
  const selects = await page.$$('select')
  const contrapartes = await selects[0].$$eval('option', (os) => os.map((o) => o.textContent.trim()))
  checar(
    contrapartes.includes('vizinha · 3 slots'),
    `o diretório popula a contraparte com a distância (viu: ${contrapartes.join(' | ')})`,
  )

  // O item 1 do pedido: ao lado do recurso escolhido, quanto há na colônia. Ninguém deve prometer
  // o que não tem por engano — prometer o que não se tem continua permitido, e é o calote.
  checar(await esperarTexto(page, /Na sua colônia:/), 'o lado "Você promete" mostra o estoque')

  await selects[2].select('agua')

  const qtds = await page.$$('input[inputmode=numeric]')
  await qtds[0].type('50')
  await qtds[1].type('80')

  const somar = await todosPorTexto(page, 'button', 'Somar')
  checar(somar.length === 2, `há um "Somar" por lado da promessa (achou ${somar.length})`)
  await somar[0].click()
  await somar[1].click()

  const propor = await acharPorTexto(page, 'button', /^Propor acordo$/)
  checar(
    await propor.evaluate((b) => !b.disabled),
    'o botão de propor habilita quando os dois lados prometem algo',
  )
  await propor.click()
  await page.waitForNetworkIdle({ idleTime: 800 })

  const proposto = await esperarTexto(page, /Você propôs a vizinha/)
  checar(proposto, 'o acordo proposto aparece em "Em aberto", do lado de quem propôs')
  checar(
    await esperarTexto(page, /Esperando vizinha apertar a mão/),
    'a tela diz que quem propõe não confirma sozinho (§26.5)',
  )

  if (!proposto) {
    console.log('\n--- texto da tela no momento da falha ---')
    console.log((await textoDaPagina(page)).slice(0, 1500))
  }

  console.log('\nDesistir enquanto ninguém apertou a mão')
  await (await acharPorTexto(page, 'button', /^Desistir$/)).click()
  await page.waitForNetworkIdle({ idleTime: 800 })
  checar(await esperarTexto(page, /Cancelado/), 'a proposta desistida vira Cancelado no histórico')
} catch (e) {
  falhas.push(`exceção: ${e.message}`)
  try {
    await page.screenshot({ path: '/tmp/e2e-acordos-falha.png' })
    console.log('\nscreenshot em /tmp/e2e-acordos-falha.png')
  } catch {}
} finally {
  await navegador.close()
}

process.exit(relatar('Acordo de Troca'))
