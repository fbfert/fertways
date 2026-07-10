/**
 * Teste de ponta a ponta da tela do Ministério das Reputações (§9.1–9.4, §26.6–26.8).
 *
 * O que este teste guarda é a frase do §26.8: a tabela de punições "elimina decisão totalmente
 * subjetiva". A tela mostra a pena **antes** de o conciliador julgar, e os botões dele dizem apenas
 * "Procedente" e "Improcedente". Se um dia aparecer um seletor de punição ali, este teste cai.
 *
 * Roda por último, contra o mesmo banco efêmero: `tools/e2e.sh` semeia um acordo quebrado (a
 * evidência do §26.8), nomeia o colono do e2e conciliador, e abre um caso entre duas colônias
 * distantes para ele julgar.
 */
import {
  acharPorTexto,
  abrirNavegador,
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

  console.log('\nAbre o Ministério')
  await (await acharPorTexto(page, 'button', /^Ministério$/)).click()
  checar(await esperarTexto(page, /Slot 7 da Capital/), 'o painel do Ministério abre (§9.1)')

  console.log('\nMinha reputação: os quatro índices do §26.2')
  for (const indice of ['Confiança Comercial', 'Conduta Social', 'Status Cívico', 'Honra Militar']) {
    checar(await esperarTexto(page, new RegExp(indice)), `o índice "${indice}" aparece`)
  }

  const texto = await textoDaPagina(page)
  const quinhentos = (texto.match(/500\s*\/\s*1000/g) ?? []).length
  checar(quinhentos === 4, `os quatro índices nascem em 500 de 1000 (viu ${quinhentos})`)

  checar(
    await esperarTexto(page, /cumprir missões não recupera confiança perdida num calote/),
    'a tela diz que os índices são isolados (§26.9)',
  )
  checar(await esperarTexto(page, /Nenhuma\. Você está limpo/), 'nenhuma punição vigente')

  console.log('\nO cargo de Conciliador (§26.7)')
  checar(await esperarTexto(page, /Você é Conciliador/), 'o e2e foi nomeado conciliador')
  checar(
    await esperarTexto(page, /50 Fert\$ por dia, independente do volume de casos/),
    'o salário do §26.7 é dito com a razão dele',
  )
  checar(await esperarTexto(page, /Reversões: 0 de 5/), 'o contador de reversões do §26.7 aparece')

  console.log('\nCasos a julgar')
  await (await acharPorTexto(page, 'button', /^Casos/)).click()
  checar(await esperarTexto(page, /A julgar/), 'a fila do conciliador existe')
  checar(
    await esperarTexto(page, /Calote deliberado e reincidente/),
    'o caso semeado aparece na fila',
  )
  checar(await esperarTexto(page, /autora contra ré/), 'a tela nomeia as partes, não o id delas')

  console.log('\nA pena está escrita antes do julgamento (§26.8)')
  checar(
    await esperarTexto(page, /Se procedente: Restrição comercial \+ Redução de reputação/),
    'a tela publica a punição tabelada do D-49',
  )
  checar(
    await esperarTexto(page, /-100 em Confiança Comercial/),
    'a tela publica os pontos e o índice que eles atingem',
  )
  checar(
    await esperarTexto(page, /A punição está na tabela acima, e não é sua escolha/),
    'a tela diz ao conciliador que ele julga o fato, não a pena',
  )
  checar(await esperarTexto(page, /Restam .* para decidir/), 'o relógio das 48 h do §26.8 corre')

  const botoes = await page.$$eval('button', (bs) => bs.map((b) => b.textContent.trim()))
  checar(
    botoes.includes('Procedente') && botoes.includes('Improcedente') && !botoes.some((b) => /Silêncio|Advertência/.test(b)),
    'o conciliador só escolhe entre procedente e improcedente',
  )

  console.log('\nJulgar procedente')
  await (await acharPorTexto(page, 'button', /^Procedente$/)).click()
  await page.waitForNetworkIdle({ idleTime: 800 })

  /*
   * O conciliador não é parte no caso que julga: se a tela só listasse "minhas denúncias", julgar
   * faria o caso sumir sem confirmação nenhuma. Ele tem de ver o que decidiu, e o bônus pendente.
   */
  const julgado = await esperarTexto(page, /Decididos por você/)
  checar(julgado, 'o caso julgado continua visível para quem o julgou')
  checar(await esperarTexto(page, /Decidida · procedente/), 'a decisão é registrada')
  checar(
    await esperarTexto(page, /O bônus de cada decisão cai quando a janela de apelação fecha/),
    'a tela explica quando o bônus do §26.7 cai',
  )

  if (!julgado) {
    console.log('\n--- texto da tela no momento da falha ---')
    console.log((await textoDaPagina(page)).slice(0, 1500))
  }

  console.log('\nDenunciar exige a evidência mínima do §26.8')
  await (await acharPorTexto(page, 'button', /^Denunciar$/)).click()

  // Os selects da aba, em ordem: [0] denunciado, [1] violação, [2] evidência.
  const selects = await page.$$('select')
  checar(selects.length === 3, `denunciado, violação e evidência (achou ${selects.length} selects)`)

  await selects[1].select('calote_reincidente')
  checar(
    await esperarTexto(page, /Se procedente: Restrição comercial \+ Redução de reputação/),
    'a pena da violação escolhida aparece antes de denunciar',
  )

  const evidencias = await selects[2].$$eval('option', (os) => os.map((o) => o.textContent.trim()))
  checar(
    evidencias.some((e) => /^Acordo #\d+, vencido em/.test(e)),
    `só o Acordo quebrado entre os dois serve de evidência (viu: ${evidencias.join(' | ')})`,
  )

  const abrir = await acharPorTexto(page, 'button', /^Abrir denúncia$/)
  checar(
    await abrir.evaluate((b) => b.disabled),
    'sem o relato, o botão não habilita',
  )

  await page.type('textarea', 'Ele aceitou o acordo e não entregou nada, de novo.')

  const abrirDeNovo = await acharPorTexto(page, 'button', /^Abrir denúncia$/)
  checar(await abrirDeNovo.evaluate((b) => !b.disabled), 'com relato e evidência, o botão habilita')
  await abrirDeNovo.click()
  await page.waitForNetworkIdle({ idleTime: 800 })

  console.log('\nO conciliador não julga o próprio caso (§26.8)')
  const minha = await esperarTexto(page, /Você denunciou vizinha/)
  checar(minha, 'a denúncia aberta aparece entre as minhas')
  checar(
    await esperarTexto(page, /Com a equipe/),
    'sendo parte, o conciliador não recebe o próprio caso: ele sobe à equipe',
  )

  if (!minha) {
    console.log('\n--- texto da tela no momento da falha ---')
    console.log((await textoDaPagina(page)).slice(0, 1500))
  }
} catch (e) {
  falhas.push(`exceção: ${e.message}`)
  try {
    await page.screenshot({ path: '/tmp/e2e-ministerio-falha.png' })
    console.log('\nscreenshot em /tmp/e2e-ministerio-falha.png')
  } catch {}
} finally {
  await navegador.close()
}

process.exit(relatar('Ministério das Reputações'))
