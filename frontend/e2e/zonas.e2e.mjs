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
  assentar,
  checar,
  entrar,
  esperarTexto,
  falhas,
  irPara,
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

  // ═══════════════════════════════════════════════════ a zona é um LUGAR (D-67)
  console.log('\nA zona ocupada abre como tela própria, com planta')

  /*
   * Isto só é testável porque a planta da zona é **SVG**, e não Phaser (D-67).
   *
   * As cenas de canvas da colônia e da Capital não têm DOM: o e2e não as vê, não as clica, e não lê
   * o que está escrito nelas. Foi por isso que a receita da Oficina (D-54) e a demolição (D-59)
   * ficaram sem cobertura, e foi por isso que os sete ministérios da Capital saíram PÁLIDOS na tela
   * com os sete e2e verdes (D-63). Um SVG é DOM. Este teste é a prova de que a escolha se paga.
   */
  const abrir = await page.$('[data-abrir-zona]')
  checar(!!abrir, 'a zona sua oferece a porta de entrada')
  await abrir.click()
  await assentar()

  checar(await esperarTexto(page, /Zona Neutra/), 'a zona abre como tela própria')
  checar(!!(await page.$('[data-tela="zona"]')), 'e é uma TELA, não um popup por cima do jogo')

  // O colono pode nomear a zona, como já nomeia a colônia. Sem botão de salvar até digitar algo.
  console.log('\nNomear a zona')
  checar(!(await page.$('[data-salvar-nome-zona]')), 'sem mudança no campo, não há botão de salvar')
  await page.type('[data-nome-zona]', 'Posto Sentinela')
  await page.click('[data-salvar-nome-zona]')
  await assentar()
  checar(
    await esperarTexto(page, /Zona renomeada para "Posto Sentinela"/),
    'salvar o nome confirma com o nome escolhido',
  )
  checar(
    (await page.$eval('[data-nome-zona]', (el) => el.value)) === 'Posto Sentinela',
    'e o campo mostra o nome salvo, não mais o placeholder das coordenadas',
  )

  // A planta: as áreas existem e são clicáveis.
  for (const area of ['muralha_de_perimetro', 'torre_de_vigia', 'deposito_de_zona_neutra']) {
    checar(!!(await page.$(`[data-area="${area}"]`)), `a planta tem a área "${area}"`)
  }

  // ═══════════════════════════════════════════════════ as abas (D-86)
  console.log('\nA zona virou cinco abas')
  for (const aba of ['zona', 'deposito', 'canteiro', 'guarnicao', 'historico']) {
    checar(!!(await page.$(`[data-aba-zona="${aba}"]`)), `a aba "${aba}" existe`)
  }

  console.log('\nO nível da zona e o upgrade aparecem na aba Zona Neutra')
  checar(await esperarTexto(page, /Nível da zona/), 'a seção de upgrade de nível existe')
  checar(!!(await page.$('[data-upar-zona]')), 'o botão de upgrade existe')

  console.log('\nA aba Depósito mostra o bruto extraído')
  await page.click('[data-aba-zona="deposito"]')
  await assentar()
  checar(!!(await page.$('[data-bruto]')), 'o Depósito mostra o saldo bruto')

  console.log('\nA aba Canteiro pergunta a obra antes do recurso')
  await page.click('[data-aba-zona="canteiro"]')
  await assentar()
  // O canteiro nasce vazio: o material das obras chega de VEÍCULO, não do estoque de casa.
  checar(
    await esperarTexto(page, /Despache um veículo com material/),
    'o canteiro nasce vazio, e a tela diz que o material chega de veículo',
  )
  /*
   * O formulário de envio só existe com veículo ocioso — e este arquivo, de propósito (ver o
   * comentário do topo), não pode CONTAR com um: Mercado, Acordo e Ministério, rodados antes,
   * despacham o Furgão do e2e e ele pode não ter voltado ainda. Testa o formulário se houver
   * veículo; senão, confirma a mensagem de "nenhum ocioso" — as duas são o comportamento certo.
   */
  const obraDoCanteiro = await page.$('[data-obra-do-canteiro]')
  if (obraDoCanteiro) {
    checar(true, 'o formulário pergunta para qual obra, antes de pedir recurso nenhum')
    await page.select('[data-obra-do-canteiro]', 'muralha_de_perimetro')
    await assentar()
    checar(
      await esperarTexto(page, /falta \d+ de \d+/),
      'escolhida a obra, os campos já mostram o que falta — não mais três recursos fixos adivinhados',
    )
  } else {
    checar(
      await esperarTexto(page, /Nenhum veículo ocioso para levar material/),
      'sem veículo ocioso agora (outro teste o despachou antes), a tela diz isso em vez de sumir',
    )
  }

  console.log('\nA aba Guarnição mostra a defesa e o formulário de reforço')
  await page.click('[data-aba-zona="guarnicao"]')
  await assentar()
  checar(await esperarTexto(page, /pontos de defesa/), 'a guarnição mostra os pontos de defesa')
  checar(await esperarTexto(page, /Reforçar com Sentinelas/), 'o reforço mora dentro da própria zona agora')

  console.log('\nA aba Histórico já mostra a ocupação que acabou de acontecer')
  await page.click('[data-aba-zona="historico"]')
  await assentar()
  checar(await esperarTexto(page, /Ocupada/), 'a ocupação vira a primeira linha do histórico')

  await page.click('[data-aba-zona="zona"]')
  await assentar()

  // Clicar na Muralha abre o painel dela, com o que o GDD promete e o que o jogo entrega.
  await page.click('[data-area="muralha_de_perimetro"]')
  await assentar()
  checar(
    await esperarTexto(page, /Muralha de Perímetro/),
    'clicar no perímetro abre o painel da Muralha',
  )
  checar(
    await esperarTexto(page, /Dificulta a Invasão Direta/),
    'e ele diz o que o GDD promete, verbatim',
  )

  // ⚠️ O buraco que o D-66 abriu e o D-67 fechou: até aqui, NADA no jogo erguia isto.
  checar(!!(await page.$('[data-construir="muralha_de_perimetro"]')), 'e oferece o botão de construir')

  /*
   * Sem material no canteiro, o botão está DESABILITADO — e não é detalhe de UX.
   *
   * A primeira versão clicava e esperava o erro do servidor. Funcionava, e o andaime do e2e
   * reprovou com razão: ele vigia erros de rede, e aquilo era um 422 de verdade. O erro era do
   * DESENHO — eu oferecia um botão que a própria tela já sabia que ia falhar. A guarda do domínio
   * continua lá (é ela que vale contra requisição forjada); a tela só deixou de prometer o que não
   * pode cumprir.
   */
  const construir = await page.$('[data-construir="muralha_de_perimetro"]')
  checar(
    await page.evaluate((b) => b.disabled, construir),
    'sem material no canteiro, o botão de construir nem se oferece',
  )
  checar(
    await esperarTexto(page, /Falta material no canteiro/),
    'e a tela diz por quê, em vez de deixar o colono adivinhar',
  )

  // O Cemitério é declarado INERTE pelo próprio GDD, e a tela tem de dizê-lo.
  await page.click('[data-area="cemiterio_de_robos"]')
  await assentar()
  checar(
    await esperarTexto(page, /apenas visual|não faz nada/),
    'o Cemitério de Robôs diz que não faz nada — o próprio GDD o declara decorativo',
  )

  // As três últimas do §17.4 (D-79): custeadas, mas também INERTES — sem sistema que as acione.
  await page.click('[data-area="central_de_comunicacao"]')
  await assentar()
  checar(
    await esperarTexto(page, /apenas visual|não faz nada/),
    'a Central de Comunicação também diz que não faz nada — sem Federação, não há o que avisar',
  )
  checar(
    !!(await page.$('[data-construir="central_de_comunicacao"]')),
    'e mesmo inerte, oferece o botão de construir — é gosto e futuro, não função (D-79)',
  )

  // E não sobra nenhum "buraco" do §17.4: as 12 estruturas de zona têm custo e função declarados.
  checar(
    !(await page.$('[data-secao="ausentes"]')),
    'a seção "o que ainda não existe" some — o D-79 fechou a última pendência de custo/tempo',
  )

  // A Indústria Siderúrgica (D-82): construção nova, não está no GDD, mas É FUNCIONAL.
  await page.click('[data-area="industria_siderurgica"]')
  await assentar()
  checar(
    await esperarTexto(page, /Não está no GDD/),
    'a Indústria Siderúrgica admite que é construção nova, fora do GDD',
  )
  checar(
    await esperarTexto(page, /350 Ligas.*35 Alumínio|Alumínio.*Cobre.*Estanho/),
    'e diz a receita: 1000 Metal Bruto vira Ligas e os minerais eletrônicos',
  )
  checar(
    !!(await page.$('[data-construir="industria_siderurgica"]')),
    'oferece o botão de construir — ao contrário do Cemitério, ela FAZ algo',
  )

  // ═══════════════════════════════════════════════════ o LINK DIRETO (D-67)
  console.log('\nA URL da zona funciona sozinha — é metade do motivo do D-67')
  const url = page.url()
  checar(/\/zona\/\d+$/.test(url), `a zona tem URL própria (${url})`)

  await irPara(page, new URL(url).pathname)
  checar(
    await esperarTexto(page, /Zona Neutra/),
    'recarregar a página na URL da zona devolve a ZONA, e não a colônia',
  )
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
