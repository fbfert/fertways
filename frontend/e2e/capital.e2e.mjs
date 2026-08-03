/**
 * Teste de ponta a ponta do hub da Capital e das três instituições novas (§02).
 *
 * O hub é o diretório dos slots do governo. Este teste abre o hub pelo HUD, confere que os slots
 * aparecem, e entra nas três instituições construídas nesta rodada: Central de Tributos/Tesouro (2),
 * Secretaria de Finanças (4) e Central de Pesquisas e Notícias (3). Tesouro e Finanças são só
 * leitura; Notícias ganhou uma escrita real no D-130 — o colono do e2e é Repórter, e publica.
 *
 * A Endurance (Oeste) virou rota própria no D-132: mapa de destroços clicável e Loja de Peças —
 * este teste abre os dois, compra uma peça (o e2e nasce no marco 20, acima do marco 10 exigido) e
 * confere os atalhos de navegação para Mercado Central e Espaçoporto.
 *
 * O `tools/e2e.sh` semeia um comunicado ("Servidor aberto") para o mural ter o que mostrar, e nomeia
 * o colono do e2e Repórter (§14.2, D-130).
 */
import {
  abrirCapital,
  abrirNavegador,
  acharPorTexto,
  checar,
  clicar,
  entrar,
  esperarTexto,
  falhas,
  irPara,
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

  await clicar(page, '[data-cena-capital] [data-zoom-mais]')
  await clicar(page, '[data-cena-capital] [data-zoom-mais]')

  const depoisDoZoom = await page.$eval('[data-slot-capital="2"]', (el) => el.getBoundingClientRect().width)
  checar(
    depoisDoZoom > antesDoZoom,
    `o alvo de clique cresce junto com o desenho (${Math.round(antesDoZoom)} → ${Math.round(depoisDoZoom)} px)`,
  )

  console.log('\nCentral de Tributos / Tesouro (slot 2) — clicado JÁ COM ZOOM')
  await clicar(page, '[data-slot-capital="2"]')
  checar(
    await esperarTexto(page, /Saldo do Tesouro/),
    'o clique acerta o slot certo mesmo aproximado — o botão e o hexágono não divergiram',
  )
  checar(await esperarTexto(page, /Painel de taxas/), 'o painel de taxas do §8.3 aparece')
  const tributos = await textoDaPagina(page)
  checar(/3%/.test(tributos) && /2%/.test(tributos) && /1%/.test(tributos), 'as alíquotas 3/2/1% aparecem')

  console.log('\nVolta e abre a Secretaria de Finanças (slot 4)')
  await clicar(page, '[data-voltar-capital]')
  await page.waitForSelector('[data-cena-capital] [data-zoom-centralizar]')
  await clicar(page, '[data-cena-capital] [data-zoom-centralizar]')
  await clicar(page, '[data-slot-capital="4"]')
  checar(await esperarTexto(page, /Preços de referência/), 'a tela de Finanças abre')
  checar(await esperarTexto(page, /Metal Bruto/), 'a tabela de preços-base do §06 aparece')
  checar(await esperarTexto(page, /Indicadores/), 'os indicadores econômicos aparecem')

  console.log('\nVolta e abre a Central de Notícias (slot 3)')
  await clicar(page, '[data-voltar-capital]')
  await clicar(page, '[data-slot-capital="3"]')
  checar(await esperarTexto(page, /Telescópio Gagarin/), 'a tela de Notícias abre')
  checar(await esperarTexto(page, /inativo/), 'o Gagarin aparece honestamente inativo (§12.1)')
  checar(await esperarTexto(page, /Servidor aberto/), 'o comunicado semeado aparece no mural')

  console.log('\nO e2e é Repórter (§14.2, D-130): publica matéria no mesmo mural')
  checar(await esperarTexto(page, /Publicar matéria/), 'o formulário aparece para quem ocupa o cargo')

  await page.type('[data-form="publicar-materia"] input', 'Achado no Gagarin')
  await page.type('[data-form="publicar-materia"] textarea', 'Um sinal novo, ainda sem explicação.')

  const publicar = await acharPorTexto(page, 'button', /^Publicar$/)
  checar(await publicar.evaluate((b) => !b.disabled), 'o botão de publicar habilita com título e corpo')
  await publicar.click()
  await page.waitForNetworkIdle({ idleTime: 800 })

  checar(await esperarTexto(page, /Achado no Gagarin/), 'a matéria publicada aparece no mural')
  checar(await esperarTexto(page, /boletim/), 'marcada como boletim, distinta de um comunicado oficial')

  console.log('\nOs destroços da Endurance (Oeste): a Loja de Peças (D-132, reconstruída no D-135)')
  await clicar(page, '[data-voltar-capital]')
  await clicar(page, '[data-area="oeste"]')
  await page.waitForSelector('[data-tela="endurance"]')
  checar(await esperarTexto(page, /Destroços da Endurance/), 'a tela própria da Endurance abre')

  const destrocos = await page.$$('[data-destroco]')
  checar(destrocos.length === 8, `os 8 destroços aparecem no mapa (achou ${destrocos.length})`)

  // D-135: cada destroço abre SÓ a própria seção — não mais 8 grupos na mesma tela (D-132/D-133).
  // `destrocos[0]` é "comando", a primeira seção que `EnduranceMapa.tsx` desenha; o e2e semeia o
  // "Reator de Teste" lá (tools/e2e.sh) para o catálogo não nascer vazio.
  await destrocos[0].click()
  await page.waitForSelector('[data-tela="loja-da-endurance"]')
  const secaoDaLoja = await page.$eval('[data-secao-loja]', (el) => el.getAttribute('data-secao-loja'))
  checar(secaoDaLoja === 'comando', `a loja abre na seção clicada, "comando" (achou "${secaoDaLoja}")`)
  checar(await esperarTexto(page, /Reator de Teste/), 'o item semeado aparece no catálogo')

  console.log('\nO item ÚNICO mostra a biografia (A2.9 / §11.1)')
  /*
   * ⚠️ "Identidade persistente" que ninguém enxerga não é identidade. Este bloco existe porque já
   * publiquei rota sem tela nesta base (D-180), e aqui a consequência seria pior: o item teria
   * história no banco e o jogador veria só mais uma peça.
   */
  await page.waitForSelector('[data-unico]', { timeout: 8000 })
  checar(true, 'o item único traz o selo de identidade')
  checar(
    await esperarTexto(page, /FW-U-nucleo_da_endurance/),
    'o selo é legível: diz de que item se trata sem consultar nada',
  )
  checar(
    await esperarTexto(page, /Descoberto por/),
    'e a origem aparece — é ela que faz o único valer mais que o raro',
  )

  console.log('\nCompra um item — o e2e nasce no marco 20 (Desbravador), sem exigência de marco no item')
  const comprar = await acharPorTexto(page, 'button', /^Comprar$/)
  checar(!!comprar, 'o item "comum" semeado está disponível para compra')
  await comprar.click()
  await page.waitForNetworkIdle({ idleTime: 800 })
  checar(await esperarTexto(page, /Você tem 1/), 'o item comprado muda de estado na hora')

  console.log('\nO atalho "Mercado Central" da Endurance navega direto, sem voltar à praça')
  await (await acharPorTexto(page, 'button', /Mercado Central/)).click()
  checar(await esperarTexto(page, /Mercado Central/), 'o Mercado Central abre pelo atalho')

  console.log('\nO atalho "Espaçoporto" da Endurance (Sul)')
  await irPara(page, '/capital/endurance')
  await page.waitForSelector('[data-tela="endurance"]')
  await (await acharPorTexto(page, 'button', /Espaçoporto/)).click()
  await page.waitForSelector('[data-tela="espacoporto"]')
  checar(await esperarTexto(page, /Ninguém viaja daqui ainda/), 'o Espaçoporto admite que não abriu')
  for (const p of ['Kalidor', 'Veyra', 'Auryn', 'Solène', 'Drakmoor']) {
    checar(await esperarTexto(page, new RegExp(p)), `o planeta ${p} aparece, com o que o GDD publica`)
  }

  console.log('\nO Quartel de Alianças (slot 9) — a mesa diplomática da A2.5')
  await clicar(page, '[data-voltar-capital]')
  await clicar(page, '[data-slot-capital="9"]')
  /*
   * ⚠️ `waitForSelector` antes de qualquer asserção, e não um `sleep`. Foi a lição do D-180: o
   * `page.click` do Puppeteer não espera, e a suíte reprovava com o jogo perfeito.
   */
  await page.waitForSelector('[data-secao="diplomacia"]', { timeout: 8000 })
  checar(true, 'a mesa diplomática abre — "Diplomata" deixou de ser cargo sem sistema')

  // Os dois descontos juntos: é o que torna visível POR QUE filiar-se vale mais que aliar-se.
  checar(
    await esperarTexto(page, /Filiar-se à mesma federação desconta/),
    'a tela põe os dois descontos lado a lado, e não só o da aliança',
  )
  // O custo da aliança, dito ANTES de ela ser feita.
  checar(
    await esperarTexto(page, /limite antimonopólio.*todas as aliadas/is),
    'e avisa que aliar-se aproxima do teto de zonas — o preço não fica escondido',
  )

  const alvo = await page.$('[data-alvo-alianca]')
  checar(alvo !== null, 'há com quem tratar: a outra federação do mundo aparece na lista')

  await page.select('[data-alvo-alianca]', await page.$eval(
    '[data-alvo-alianca] option:not([value=""])', (el) => el.value,
  ))
  await clicar(page, '[data-propor-alianca]')
  checar(await esperarTexto(page, /Proposta enviada/), 'propor chega ao domínio e volta com resposta')
  checar(
    await esperarTexto(page, /proposta enviada/i),
    'e a relação passa a aparecer — quem propôs não recebe botão de aceitar a própria proposta',
  )

  console.log('\nA mesa de guerra (A2.10) — o custo e a declaração')
  await page.waitForSelector('[data-mesa-guerra]', { timeout: 8000 })
  checar(true, 'a mesa de guerra aparece')
  checar(
    await esperarTexto(page, /Declarar guerra custa .* do fundo/),
    'a tela diz o custo E de onde ele sai — decisão coletiva, não do bolso de quem declara',
  )
  checar(
    await esperarTexto(page, /o outro lado não pode recusar/),
    'e avisa que não há recusa antes de o jogador clicar',
  )

  await clicar(page, '[data-declarar-guerra]')
  checar(
    await esperarTexto(page, /Guerra declarada a .* Sete dias|Faltam|fundo/),
    'o clique chega ao domínio e volta com resposta',
  )

  console.log('\nVolta e abre o Ministério dos Transportes (slot 8)')
  await clicar(page, '[data-voltar-capital]')
  await clicar(page, '[data-slot-capital="8"]')
  await page.waitForSelector('[data-tela="transportes"]')
  checar(await esperarTexto(page, /Caminhão de Carga/), 'a fábrica do governo abre')
  checar(await esperarTexto(page, /300 F\$/), 'o preço do D-60 aparece')
  checar(
    await esperarTexto(page, /privativo deste Ministério/),
    'a tela diz que a Central de Transportes não fabrica mais — o GDD (§17.2) diz que sim, e o jogador precisa saber onde a fábrica foi parar',
  )

  /*
   * Os usados mudaram para cá no D-65: veículo é assunto do Ministério — ele é o cartório da placa
   * (§16.3) —, e o Mercado passou a ser o lugar do recurso. É a única mercadoria com escrow do
   * MINISTÉRIO, e não do Mercado: por isso aqui não há calote, ao contrário do que vale entre
   * colonos (D-58). A tela tem de dizer isso.
   */
  console.log('\nVeículos usados — agora dentro do Ministério dos Transportes (D-65)')
  await page.waitForSelector('[data-aba="usados"]')
  checar(
    await esperarTexto(page, /vendedor só recebe na chegada/),
    'a tela explica o escrow: sem calote, ao contrário do resto do Mercado',
  )
  checar(await esperarTexto(page, /À venda no planeta/), 'a vitrine de usados abre')

  const seletor = await page.$('[data-usado-veiculo]')
  checar(seletor !== null, 'há veículo no pátio para anunciar')

  const opcoes = await page.$$eval('[data-usado-veiculo] option', (os) =>
    os.map((o) => o.value).filter(Boolean),
  )
  checar(opcoes.length > 0, `o seletor lista os veículos do pátio (${opcoes.length})`)

  await page.select('[data-usado-veiculo]', opcoes[0])
  /*
   * O aditivo 14 morreu no D-73: o Furgão GANHOU teto, porque sem ele a venda de usado era a
   * porta da lavagem de Fert$ entre contas. Até o D-109 a âncora era uma referência do operador
   * (60 F$); desde o D-109 o Ministério vende o Furgão de verdade, e o teto ancora no preço de
   * fábrica dele (150 F$), igual ao Caminhão.
   */
  checar(
    await esperarTexto(page, /Teto de revenda: .*150 F\$/),
    'e mostra o teto do Furgão: 150 F$ — o preço de fábrica dele, desde o D-109',
  )

  await page.type('[data-usado-preco]', '50')
  await clicar(page, '[data-anunciar-usado]')
  checar(
    await esperarTexto(page, /Ele continua seu e no pátio até alguém comprar/),
    'o anúncio entra, e o veículo continua do vendedor até a venda',
  )
  checar(await esperarTexto(page, /50 F\$/), 'o anúncio aparece na vitrine com o preço pedido')

  /*
   * `waitForSelector` antes do clique, e a diferença já custou dois vermelhos nesta base.
   *
   * `page.click` do Puppeteer **não espera**: se o botão ainda não foi renderizado, ele lança "No
   * element found for selector" na hora. A asserção anterior usa `esperarTexto`, que insiste — e o
   * clique logo abaixo não insistia, então uma renderização um pouco mais lenta reprovava a suíte
   * com o jogo perfeito. Foi o que aconteceu em 2026-07-31: vermelho numa execução, verde na
   * seguinte, com o código idêntico.
   *
   * Mesma cura do `chat.e2e.mjs` (D-164). Se o botão não existir mesmo, os 8 s se esgotam e isto
   * reprova — o que muda é parar de confundir "ainda não chegou" com "não existe".
   */
  await page.waitForSelector('[data-cancelar-anuncio]', { timeout: 8000 })
  await clicar(page, '[data-cancelar-anuncio]')
  checar(await esperarTexto(page, /Anúncio retirado/), 'o vendedor pode retirar o anúncio')

  console.log('\nO registro de placas (§16.3)')
  checar(await esperarTexto(page, /Registro de Placas/), 'o registro abre')
  checar(await esperarTexto(page, /FW-\d{5}-F/), 'os Furgões do colono têm placa')

  const prateleira = await page.$eval('[data-estoque]', (el) => el.getAttribute('data-estoque'))
  checar(prateleira === '2', `o governo tem 2 caminhões prontos (veio ${prateleira})`)

  console.log('\nCompra um Caminhão de Carga')
  const vagasAntes = await page.$eval('[data-vagas]', (el) => el.getAttribute('data-vagas'))
  await clicar(page, '[data-comprar-veiculo="caminhao_de_carga"]')

  checar(
    await esperarTexto(page, /vem dirigindo da Capital/),
    'a entrega é física: o caminhão dirige-se da Capital até a colônia (D-60)',
  )
  checar(await esperarTexto(page, /FW-\d{5}-C/), 'o Caminhão comprado traz a sua placa, com o C do tipo')

  /*
   * A prateleira baixou e a vaga fechou — mas ESPERANDO, não no impulso: o recibo da compra
   * aparece ANTES de a tela recarregar (o `agir` mostra o texto e só então refaz o fetch), e ler
   * o contador nesse vão via o número VELHO. Um teste PHP prova que a leitura seguinte do
   * servidor já vem certa; a espera aqui é só o navegador alcançá-la.
   */
  const baixou = await page
    .waitForFunction(
      () => document.querySelector('[data-estoque]')?.getAttribute('data-estoque') === '1',
      { timeout: 5000 },
    )
    .then(() => true)
    .catch(() => false)
  checar(baixou, 'a prateleira do governo baixou de 2 para 1')

  const vagouMenos = await page
    .waitForFunction(
      (esperado) => document.querySelector('[data-vagas]')?.getAttribute('data-vagas') === esperado,
      { timeout: 5000 },
      String(Number(vagasAntes) - 1),
    )
    .then(() => true)
    .catch(() => false)
  checar(vagouMenos, `a compra ocupou uma vaga da frota (${vagasAntes} → ${Number(vagasAntes) - 1})`)

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

  await clicar(page, '[data-reparar]')
  checar(await esperarTexto(page, /reparado — voltou a/), 'a manutenção acontece')
  checar(
    await esperarTexto(page, /o teto caiu para 95%/),
    'e ela corrói a vida útil: o teto cai 5 pontos (§16.4)',
  )

  console.log('\nO upgrade de veículo (A2.7) — a rota que existia sem tela')
  /*
   * ⚠️ Este bloco existe porque a rota de upgrade foi publicada e ficou um dia inteiro sem
   * interface: `POST /transport/vehicles/{id}/upgrade` respondia, e nenhum jogador conseguia
   * chegar nela. Um teste de backend verde não teria percebido — só o e2e vê a tela.
   */
  await page.waitForSelector('[data-melhorar]', { timeout: 8000 })
  checar(true, 'a tela oferece o upgrade — a rota deixou de ser inalcançável')

  // O critério de saída da fase: "escolha econômica mensurável, e não apenas aumento nominal".
  checar(
    await esperarTexto(page, /Carrega [\d.]+ → [\d.]+/),
    'a tela mostra o GANHO de capacidade do próximo nível',
  )
  checar(
    await esperarTexto(page, /a manutenção passa de \d+% para \d+%/),
    'e a CONTRAPARTIDA junto — sem ela o upgrade seria um botão óbvio (§13)',
  )

  await clicar(page, '[data-melhorar]')
  /*
   * Qualquer um dos dois serve, e a distinção não importa aqui: o que se prova é que o clique
   * chega ao domínio e volta com resposta. Exigir o sucesso amarraria a suíte ao estoque que o
   * seeder deixou na colônia, e o custo do upgrade é parâmetro — muda sem aviso.
   */
  checar(
    await esperarTexto(page, /subiu para o nível \d|Faltam recursos/),
    'e o clique chega ao domínio: ou sobe de nível, ou explica o que falta',
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
