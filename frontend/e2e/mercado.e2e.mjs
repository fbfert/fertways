/**
 * Teste de ponta a ponta da tela do Mercado, com o Chromium do sistema.
 *
 * A tela foi ao ar sem nunca ter sido aberta num navegador: `tsc` e `oxlint` pegam erro de tipo
 * e de import, mas não pegam painel que não renderiza, `select` que não popula, nem campo de
 * preço que rejeita vírgula. Este teste abre a tela de verdade.
 *
 * Roda contra a pilha efêmera que `tools/e2e.sh` levanta (SQLite + artisan serve + vite dev).
 * Nunca contra produção: ele põe ordens no livro e mexe em saldo.
 */
import puppeteer from 'puppeteer-core'

const BASE = process.env.E2E_URL ?? 'http://127.0.0.1:5199'
const CHROMIUM = process.env.E2E_CHROMIUM ?? '/bin/chromium-browser'
const EMAIL = 'e2e@fertways.test'
const SENHA = 'segredo-forte-123'

const falhas = []
const erros = []

function checar(condicao, descricao) {
  if (condicao) {
    console.log(`  ✓ ${descricao}`)
  } else {
    console.log(`  ✗ ${descricao}`)
    falhas.push(descricao)
  }
}

/** Espera um elemento cujo texto case com `regex`, e devolve o handle. */
async function acharPorTexto(page, seletor, regex, timeout = 8000) {
  const fim = Date.now() + timeout
  while (Date.now() < fim) {
    const alvos = await page.$$(seletor)
    for (const a of alvos) {
      const t = await a.evaluate((n) => n.textContent ?? '')
      if (regex.test(t)) return a
    }
    await new Promise((r) => setTimeout(r, 150))
  }
  throw new Error(`não achei ${seletor} casando com ${regex}`)
}

/*
 * `innerText` aplica o `text-transform: uppercase` da classe `.eyebrow`, então "Mercado Central"
 * chega como "MERCADO CENTRAL". Comparar sem diferenciar caixa — o que interessa é o texto que o
 * colono lê, e ele lê o transformado.
 */
const textoDaPagina = (page) => page.evaluate(() => document.body.innerText)

async function esperarTexto(page, regex, timeout = 8000) {
  const semCaixa = new RegExp(regex.source, regex.flags.includes('i') ? regex.flags : regex.flags + 'i')
  const fim = Date.now() + timeout
  while (Date.now() < fim) {
    if (semCaixa.test(await textoDaPagina(page))) return true
    await new Promise((r) => setTimeout(r, 150))
  }
  return false
}

const navegador = await puppeteer.launch({
  executablePath: CHROMIUM,
  headless: true,
  // Este ambiente roda como root; sem isto o Chromium recusa subir.
  args: ['--no-sandbox', '--disable-dev-shm-usage'],
})

const page = await navegador.newPage()
await page.setViewport({ width: 1400, height: 900 })

// Qualquer erro de runtime do React aparece aqui. Uma tela em branco é silenciosa sem isto.
page.on('pageerror', (e) => erros.push(`pageerror: ${e.message}`))
const ignoravel = (url) => (url ?? '').endsWith('/favicon.ico')

/*
 * O teste do logout provoca um 401 de propósito, para provar que o token morreu no servidor.
 * `esperandoFalha` marca essa janela; fora dela, qualquer 4xx/5xx reprova.
 *
 * Uma requisição falha chega por **dois** caminhos — o evento `response` e um "Failed to load
 * resource" no `console` —, então os dois consultam a flag. Guardar só um deixa o outro reprovar.
 */
let esperandoFalha = false

page.on('console', (m) => {
  // O 404 do favicon também chega aqui, e sem a URL no texto: ela vive em `location`.
  if (m.type() === 'error' && !ignoravel(m.location()?.url) && !esperandoFalha) {
    erros.push(`console: ${m.text()}`)
  }
})

/*
 * Um 404 no console não diz *o quê* faltou. Isto diz.
 *
 * `/favicon.ico` fica de fora: o navegador o pede sozinho, sempre, e o `index.html` declara um
 * `favicon.svg`. Não é o app pedindo coisa que não existe.
 */
page.on('response', (r) => {
  if (r.status() >= 400 && !ignoravel(r.url()) && !esperandoFalha) {
    erros.push(`HTTP ${r.status()} em ${r.url()}`)
  }
})

/*
 * Os eventos de console e de resposta chegam pelo CDP, e não necessariamente antes de o `fetch`
 * do navegador resolver. Baixar a flag no instante seguinte à chamada abriria uma corrida: o
 * evento chegaria com a janela já fechada, e o 401 esperado reprovaria a suíte de forma
 * intermitente. Este respiro deixa os dois assentarem.
 */
const assentar = () => new Promise((r) => setTimeout(r, 300))

try {
  console.log('\nLogin')
  await page.goto(BASE, { waitUntil: 'networkidle2' })
  await page.type('input[type=email]', EMAIL)
  await page.type('input[type=password]', SENHA)
  await Promise.all([page.click('button[type=submit]'), page.waitForNetworkIdle({ idleTime: 800 })])
  checar(await esperarTexto(page, /Fert\$/), 'o HUD carrega e mostra Fert$')

  console.log('\nAbre o Mercado')
  const botao = await acharPorTexto(page, 'button', /^Mercado$/)
  await botao.click()
  checar(await esperarTexto(page, /Mercado Central/), 'o painel do Mercado abre')
  checar(await esperarTexto(page, /Doca e frota/), 'a aba da doca aparece')

  console.log('\nDoca e frota')
  checar(await esperarTexto(page, /Furgão de Comércio/), 'o veículo aparece com nome legível')
  checar(await esperarTexto(page, /ocioso/), 'o veículo está ocioso')
  // O seeder do e2e põe 500 de Metal Bruto na doca.
  checar(await esperarTexto(page, /Metal Bruto/), 'a doca lista o Metal Bruto')
  checar(await esperarTexto(page, /500/), 'a doca mostra o saldo de 500')

  console.log('\nLivro de ofertas')
  const abaLivro = await acharPorTexto(page, 'button', /Livro de ofertas/)
  await abaLivro.click()
  checar(await esperarTexto(page, /refer[êe]ncia/i), 'a referência de preço aparece')
  checar(await esperarTexto(page, /0,0333/), 'a referência é 0,0333 Fert$ (§24.8 derivado)')
  checar(await esperarTexto(page, /taxa de venda 3%/), 'a taxa da categoria aparece')

  console.log('\nOrdem de venda com preço decimal em vírgula')
  const vender = await acharPorTexto(page, 'button', /^Vender$/)
  await vender.click()

  const [campoQtd, campoPreco] = await page.$$('input[inputmode]')
  await campoQtd.type('100')
  await campoPreco.type('0,05')

  checar(await esperarTexto(page, /Total: 5,00 Fert\$/), 'o total sai de "0,05" com vírgula')

  const colocar = await acharPorTexto(page, 'button', /Colocar ordem de venda/)
  checar(
    await colocar.evaluate((b) => !b.disabled),
    'o botão de ordem habilita com quantidade e preço válidos',
  )
  await colocar.click()
  await page.waitForNetworkIdle({ idleTime: 800 })

  checar(await esperarTexto(page, /Suas ordens abertas/), 'a seção das ordens do colono existe')
  checar(await esperarTexto(page, /Venda de 100 a 0,0500 Fert\$/), 'a ordem aparece como sua')
  // Vender reserva o recurso em escrow: a doca cai de 500 para 400.
  const abaDoca = await acharPorTexto(page, 'button', /Doca e frota/)
  await abaDoca.click()
  checar(await esperarTexto(page, /400/), 'o escrow saiu da doca no ato')

  console.log('\nCancelamento devolve o escrow')
  await (await acharPorTexto(page, 'button', /Livro de ofertas/)).click()
  await (await acharPorTexto(page, 'button', /^Cancelar$/)).click()
  await page.waitForNetworkIdle({ idleTime: 800 })
  checar(
    await esperarTexto(page, /Nenhuma ordem sua neste recurso/),
    'a ordem sai do livro ao cancelar',
  )
  await (await acharPorTexto(page, 'button', /Doca e frota/)).click()
  checar(await esperarTexto(page, /500/), 'o recurso volta à doca, não ao estoque')

  console.log('\nDespacho: levar carga à doca')
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
   * O despacho aceita `destino = colonia` desde a fatia de logística, mas até o diretório existir
   * não havia como o jogador descobrir o `id` de ninguém, e a tela só oferecia a Capital.
   *
   * Os `select` da aba, em ordem: [0] recurso de "Levar à doca", [1] destino e [2] recurso de
   * "Enviar a outro colono", [3] recurso de "Buscar na doca".
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
  esperandoFalha = true
  const status = await page.evaluate(async (t) => {
    const r = await fetch('/central/colony', {
      headers: { Accept: 'application/json', Authorization: `Bearer ${t}` },
    })
    return r.status
  }, guardado)
  await assentar()
  esperandoFalha = false

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

if (erros.length) {
  console.log('\nErros de runtime no navegador:')
  for (const e of new Set(erros)) console.log(`  ! ${e}`)
}

console.log(`\n${falhas.length === 0 && erros.length === 0 ? 'E2E VERDE' : 'E2E VERMELHO'}`)
process.exit(falhas.length === 0 && erros.length === 0 ? 0 : 1)
