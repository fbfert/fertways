/**
 * Teste de ponta a ponta dos DOIS mercados, com o Chromium do sistema.
 *
 * A tela foi ao ar sem nunca ter sido aberta num navegador: `tsc` e `oxlint` pegam erro de tipo
 * e de import, mas não pegam painel que não renderiza, `select` que não popula, nem campo de
 * preço que rejeita vírgula. Este teste abre a tela de verdade.
 *
 * Desde o D-58 ele cobre o que originou aquela mudança: **a oferta de outro colono tem de
 * aparecer**. E desde o D-65 cobre a fronteira que o usuário mandou traçar:
 *
 *  - o **Mercado Local** (a construção da colônia) manda carga para o depósito da Capital e para
 *    outros colonos, e é onde se oferta e se veem as ofertas dos colonos;
 *  - o **Mercado Central** (a Capital) tem o Pátio, o depósito e as ofertas com escrow — e **não**
 *    tem nada de colono para colono.
 *
 * Cada tela tem de provar também o que **não** mostra: uma aba que vazasse para o lado errado
 * desfaria a separação sem que nenhum teste de tipo percebesse.
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
  clicarNaConstrucao,
  entrar,
  esperarTexto,
  irParaColonia,
  falhas,
  janela,
  relatar,
  textoDaPagina,
} from './comum.mjs'

const { navegador, page } = await abrirNavegador()

/**
 * Preenche uma linha da carroceria, dentro de um formulário de carga (escopado pelo título).
 *
 * ⚠️ **Espera o seletor existir.** Ele só aparece depois de `GET /vehicles` responder, e o formulário
 * mostra "Nenhum veículo ocioso" até lá. Sem a espera, uma resposta um pouco mais lenta fazia
 * `selects[linha]` vir `undefined` e a suíte morrer com um críptico *"Cannot read properties of
 * undefined (reading 'select')"* — que não diz nada sobre veículos.
 *
 * Falhou uma vez em duas ao acrescentar a arte (D-68), que pôs mais uma requisição na fila inicial.
 * A corrida já existia; a arte só a tornou provável. **Espere o que você vai usar.**
 */
async function carregar(page, form, linha, codigo, qtd) {
  const seletor = `[data-carga="${form}"] select[aria-label="Recurso"]`
  await page.waitForSelector(seletor)

  const selects = await page.$$(seletor)
  await selects[linha].select(codigo)

  const campos = await page.$$(`[data-carga="${form}"] input[aria-label="Quantidade"]`)
  await campos[linha].type(String(qtd))
}

/** Abre o Mercado Local: a construção da colônia, e o botão que o D-65 renomeou. */
async function abrirMercadoLocal(page) {
  await clicarNaConstrucao(page, 'Mercado Local')
  await (await acharPorTexto(page, 'button', /Abrir o Mercado/)).click()
  await assentar()
}

try {
  console.log('\nLogin')
  await entrar(page)
  checar(await esperarTexto(page, /Fert\$/), 'o HUD carrega e mostra Fert$')

  // ═══════════════════════════════════════════════════════ o Mercado Central, na Capital
  console.log('\nAbre o Mercado Central pelo Leste da Capital')
  /*
   * D-63: a Capital deixou de ser um menu. O Mercado Central **é o Leste** — a área do slot 6, que
   * no GDD é o Estacionamento de Caminhões / Pátio Logístico.
   */
  await abrirCapital(page)
  await page.waitForSelector('[data-area="leste"]')
  await page.click('[data-area="leste"]')
  checar(await esperarTexto(page, /Mercado Central/), 'o painel do Mercado Central abre pelo Leste')

  console.log('\nAs três abas do Mercado Central — e as que NÃO estão aqui (D-65)')
  for (const rotulo of [/Pátio e depósito/, /Ofertar no Mercado Central/, /Ofertas globais/]) {
    checar(await esperarTexto(page, rotulo), `a aba "${rotulo.source}" existe`)
  }
  const naCapital = await textoDaPagina(page)
  checar(
    !/Ofertar a colonos/.test(naCapital) && !/Ver ofertas de colonos/.test(naCapital),
    'o que é entre colonos NÃO aparece na Capital: ele mudou para o Mercado Local',
  )
  checar(
    !/Veículos usados/.test(naCapital),
    'os usados também saíram do Mercado: veículo é assunto do Ministério dos Transportes',
  )

  console.log('\nPátio e depósito')
  checar(await esperarTexto(page, /No seu depósito na Capital/), 'o depósito aparece')
  checar(await esperarTexto(page, /500/), 'o depósito mostra o saldo de 500 de Metal Bruto')
  checar(await esperarTexto(page, /No Pátio \(0\)/), 'nenhum veículo estacionado na Capital ainda')
  checar(
    await esperarTexto(page, /0,005 Fert\$ por hora/),
    'a tela diz o preço da hora do Pátio — o GDD publica a cobrança e nunca o preço (D-65)',
  )
  checar(
    await esperarTexto(page, /Nenhum veículo seu no Pátio/),
    'e o formulário do Pátio explica como se põe um veículo lá',
  )

  /*
   * O CORAÇÃO DO D-58: a oferta da vizinha (300 de Água) tem de estar visível, com o nome dela e
   * sem eu filtrar nada.
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

  await (await acharPorTexto(page, 'button', /Pátio e depósito/)).click()
  const aguaNoDeposito = await esperarTexto(page, /Água/)
  checar(aguaNoDeposito, 'a Água comprada entrou no MEU depósito, sem viagem nenhuma')

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
  await (await acharPorTexto(page, 'button', /Pátio e depósito/)).click()
  checar(await esperarTexto(page, /400/), 'o escrow saiu do saldo no ato')

  console.log('\nCancelar devolve o escrow ao depósito')
  await (await acharPorTexto(page, 'button', /Ofertar no Mercado Central/)).click()
  await (await acharPorTexto(page, 'button', /^Cancelar$/)).click()
  await page.waitForNetworkIdle({ idleTime: 800 })
  checar(await esperarTexto(page, /Nenhuma oferta sua/), 'a oferta some ao cancelar')

  await (await acharPorTexto(page, 'button', /Pátio e depósito/)).click()
  checar(await esperarTexto(page, /500/), 'o recurso volta ao depósito, não ao estoque')

  // ═══════════════════════════════════════════════════════ o Mercado Local, na colônia
  console.log('\nVolta à colônia e abre o Mercado Local, pela construção')
  /*
   * `irParaColonia` e não um clique no ×: desde o D-67 fechar uma tela devolve à ANTERIOR (aqui, a
   * Capital), e não à colônia. É o comportamento certo — voltou-se de onde se veio —, e é por isso
   * que a volta à colônia passou a ser um endereço, e não uma sequência de fechamentos.
   */
  await irParaColonia(page)
  await abrirMercadoLocal(page)

  checar(await esperarTexto(page, /A sua colônia/), 'o Mercado Local é uma tela própria (D-65)')
  for (const rotulo of [/Enviar carga/, /Ofertar a colonos/, /Ver ofertas de colonos/]) {
    checar(await esperarTexto(page, rotulo), `a aba "${rotulo.source}" existe no Mercado Local`)
  }
  const noLocal = await textoDaPagina(page)
  checar(
    !/Ofertas globais/.test(noLocal) && !/Ofertar no Mercado Central/.test(noLocal),
    'e as ofertas do governo NÃO aparecem aqui: elas são só da Capital',
  )

  /*
   * O outro pedido do D-65: **vários recursos na mesma carroceria**. A tela mandava um recurso por
   * viagem desde sempre; o servidor nunca exigiu isso — o §25.4 mede a capacidade em unidades
   * SOMADAS. Aqui a carga leva dois recursos, e o veículo parte com os dois.
   */
  console.log('\nLevar carga ao depósito — com DOIS recursos na mesma carroceria')
  const LEVAR = 'Levar ao seu depósito na Capital'
  await carregar(page, LEVAR, 0, 'metal_bruto', 10)

  await page.click(`[data-carga="${LEVAR}"] [data-adicionar-recurso]`)
  await carregar(page, LEVAR, 1, 'ligas_metalicas', 5)

  checar(await esperarTexto(page, /15 \/ 6\.000/), 'a carroceria soma os dois recursos contra a capacidade')

  // Reachar o botão **depois** de digitar: o React re-renderiza o formulário a cada tecla, e o
  // handle antigo pode apontar para um nó já descartado.
  const despachar = await acharPorTexto(page, 'button', /^Despachar$/)
  checar(await despachar.evaluate((b) => !b.disabled), 'o botão de despacho habilita')
  await despachar.click()
  await page.waitForNetworkIdle({ idleTime: 800 })

  const emRota = await esperarTexto(page, /levando carga para a Capital/)
  checar(emRota, 'o veículo entra em rota rumo à Capital')

  const cargaDupla = await textoDaPagina(page)
  checar(
    /10 de Metal Bruto/.test(cargaDupla) && /5 de Ligas/.test(cargaDupla),
    'e a carroceria leva os DOIS recursos — o que a tela antiga não sabia fazer',
  )

  if (!emRota) {
    console.log('\n--- texto da tela no momento da falha ---')
    console.log(cargaDupla.slice(0, 1500))
  }

  console.log('\nEnviar carga a outro colono, que o diretório nomeia')
  const ENVIAR = 'Enviar a outro colono'
  const destino = await page.$(`[data-carga="${ENVIAR}"] select[aria-label="Destino"]`)
  const rotulos = await destino.$$eval('option', (os) => os.map((o) => o.textContent.trim()))
  checar(
    rotulos.includes('vizinha · 3 slots'),
    `o diretório lista a vizinha e a distância até ela (viu: ${rotulos.join(' | ')})`,
  )

  await carregar(page, ENVIAR, 0, 'metal_bruto', 7)

  const enviar = await acharPorTexto(page, 'button', /^Enviar$/)
  checar(await enviar.evaluate((b) => !b.disabled), 'o botão de envio habilita')
  await enviar.click()
  await page.waitForNetworkIdle({ idleTime: 800 })

  const rumoAVizinha = await esperarTexto(page, /levando carga para a colônia de vizinha/)
  checar(rumoAVizinha, 'o veículo entra em rota rumo ao slot da vizinha, que o diretório nomeia')

  if (!rumoAVizinha) {
    console.log('\n--- texto da tela no momento da falha ---')
    console.log((await textoDaPagina(page)).slice(0, 1200))
  }

  console.log('\nLogout')
  const guardado = await page.evaluate(() => localStorage.getItem('fertways.token'))
  checar(!!guardado, 'o token está no localStorage enquanto a sessão vive')

  // Sair virou ícone ao lado do perfil, com confirmação (D-88): abre o "Sim/Não" antes de sair.
  // O header é global agora (reforma de navegação) — não precisa fechar a tela do Mercado antes.
  await page.click('[data-sair]')
  await page.click('[data-confirmar-sair]')
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
