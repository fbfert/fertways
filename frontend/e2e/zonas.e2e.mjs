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
  clicar,
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
  checar(await esperarTexto(page, /Grade \d+×\d+/), 'o painel do Mapa abre')

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
  await clicar(page, '[data-salvar-nome-zona]')
  await assentar()
  checar(
    await esperarTexto(page, /Zona renomeada para "Posto Sentinela"/),
    'salvar o nome confirma com o nome escolhido',
  )
  checar(
    (await page.$eval('[data-nome-zona]', (el) => el.value)) === 'Posto Sentinela',
    'e o campo mostra o nome salvo, não mais o placeholder das coordenadas',
  )

  /*
   * A colmeia (D-144): 22 slots hexagonais, mesma geometria da colônia. Uma zona recém-ocupada
   * só tem o Posto (fixo, centro) e o Depósito erguidos — o resto dos slots do nível 1 está vazio
   * e desbloqueado, e os do nível 2+ estão trancados.
   */
  checar((await page.$$eval('[data-hex]', (n) => n.length)) === 22, 'a colmeia tem 22 slots')
  checar(!!(await page.$('[data-hex-estado="posto"]')), 'o Posto de Comando ocupa o centro fixo')
  checar(!!(await page.$('[data-hex-estado="erguida"]')), 'o Depósito já nasce erguido')
  checar(!!(await page.$('[data-hex-estado="vazio"]')), 'há slots vazios e desbloqueados no nível 1')
  checar(!!(await page.$('[data-hex-estado="trancado"]')), 'e slots ainda trancados, acima do nível 1')

  // ═══════════════════════════════════════════════════ as abas (D-86)
  console.log('\nA zona virou cinco abas')
  for (const aba of ['zona', 'deposito', 'canteiro', 'guarnicao', 'historico']) {
    checar(!!(await page.$(`[data-aba-zona="${aba}"]`)), `a aba "${aba}" existe`)
  }

  console.log('\nO nível da zona e o upgrade aparecem na aba Zona Neutra')
  checar(await esperarTexto(page, /Nível da zona/), 'a seção de upgrade de nível existe')
  checar(!!(await page.$('[data-upar-zona]')), 'o botão de upgrade existe')

  console.log('\nA equipe da zona (A2.6): operadores visíveis, e o retorno')
  /*
   * ⚠️ Esta seção só existe com a população LIGADA — por isso `tools/e2e.sh` a liga e povoa a
   * colônia. Sem isso a fase inteira ficaria sem cobertura de ponta a ponta, que foi exatamente
   * como publiquei uma rota sem tela no D-180.
   */
  await page.waitForSelector('[data-secao="operadores"]', { timeout: 8000 })
  checar(true, 'a seção de equipe da zona aparece')
  checar(
    await esperarTexto(page, /operador\(es\)/),
    'a tela diz quantos operadores há e quantos a zona pede',
  )
  checar(
    await esperarTexto(page, /Equipe completa/),
    'a zona recém-ocupada já nasce com a equipe — a transferência acontece junto da ocupação',
  )

  // O "retorno" das entregas da fase, e a promessa do §6.6 junto: degrada, não se perde.
  await clicar(page, '[data-devolver-operadores]')
  checar(
    await esperarTexto(page, /de volta à colônia/),
    'trazer os operadores de volta funciona',
  )
  checar(
    await esperarTexto(page, /Desfalcada: extrai \d+%/),
    'e a zona passa a extrair menos, dizendo QUANTO — penalidade invisível é indistinguível de defeito',
  )
  checar(
    await esperarTexto(page, /não se perde/),
    'a tela promete o §6.6 na cara do jogador: a zona continua sendo dele',
  )

  console.log('\nA aba Depósito mostra o bruto extraído')
  await clicar(page, '[data-aba-zona="deposito"]')
  await assentar()
  checar(!!(await page.$('[data-bruto]')), 'o Depósito mostra o saldo bruto')

  console.log('\nA aba Canteiro pergunta a obra antes do recurso')
  await clicar(page, '[data-aba-zona="canteiro"]')
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
   *
   * Sem slot escolhido na colmeia (D-144), o Canteiro pede para escolher um antes de qualquer
   * coisa — é o slot, não mais um `<select>` de tipo, que decide qual obra este formulário paga.
   */
  const semVeiculoOcioso = await esperarTexto(page, /Nenhum veículo ocioso para levar material/, 1500)
  if (!semVeiculoOcioso) {
    checar(
      await esperarTexto(page, /Nenhum slot escolhido/),
      'sem slot escolhido, o Canteiro manda voltar à colmeia',
    )
  } else {
    checar(true, 'sem veículo ocioso agora (outro teste o despachou antes), a tela diz isso em vez de sumir')
  }

  console.log('\nA aba Guarnição mostra a defesa e o formulário de reforço')
  await clicar(page, '[data-aba-zona="guarnicao"]')
  await assentar()
  checar(await esperarTexto(page, /pontos de defesa/), 'a guarnição mostra os pontos de defesa')
  checar(await esperarTexto(page, /Reforçar com Sentinelas/), 'o reforço mora dentro da própria zona agora')

  console.log('\nA aba Histórico já mostra a ocupação que acabou de acontecer')
  await clicar(page, '[data-aba-zona="historico"]')
  await assentar()
  checar(await esperarTexto(page, /Ocupada/), 'a ocupação vira a primeira linha do histórico')

  await clicar(page, '[data-aba-zona="zona"]')
  await assentar()

  /*
   * Clicar num slot vazio e desbloqueado abre o painel de escolha (D-144) — um `<select>` do
   * catálogo, em vez de uma área fixa por estrutura. É o mesmo slot para as quatro checagens
   * abaixo: nada é de fato construído (sem material no canteiro), então trocar a escolha no
   * `<select>` não precisa de um slot novo a cada vez.
   */
  await clicar(page, '[data-hex-estado="vazio"]')
  await assentar()
  checar(await esperarTexto(page, /Slot vazio/), 'clicar num slot vazio abre o painel de escolha')

  await page.select('[data-escolher-tipo-slot]', 'muralha_de_perimetro')
  await assentar()
  checar(
    await esperarTexto(page, /Muralha de Perímetro/),
    'escolher a Muralha no catálogo mostra o painel dela',
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
  await page.select('[data-escolher-tipo-slot]', 'cemiterio_de_robos')
  await assentar()
  checar(
    await esperarTexto(page, /apenas visual|não faz nada/),
    'o Cemitério de Robôs diz que não faz nada — o próprio GDD o declara decorativo',
  )

  // A Central de Comunicação SAIU do grupo inerte no D-116/D-118: a Federação existe, e ela
  // avisa e mostra a zona ao vivo pros aliados (o efeito é todo pro lado deles, não do dono).
  await page.select('[data-escolher-tipo-slot]', 'central_de_comunicacao')
  await assentar()
  checar(
    await esperarTexto(page, /aliados|federação/i),
    'a Central de Comunicação diz o que faz pelos aliados, não mais "não faz nada"',
  )
  checar(
    !!(await page.$('[data-construir="central_de_comunicacao"]')),
    'e oferece o botão de construir, como qualquer estrutura de zona',
  )

  // E não sobra nenhum "buraco" do §17.4: as 12 estruturas de zona têm custo e função declarados.
  checar(
    !(await page.$('[data-secao="ausentes"]')),
    'a seção "o que ainda não existe" some — o D-79 fechou a última pendência de custo/tempo',
  )

  // A Indústria Siderúrgica (D-82): construção nova, não está no GDD, mas É FUNCIONAL.
  await page.select('[data-escolher-tipo-slot]', 'industria_siderurgica')
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
