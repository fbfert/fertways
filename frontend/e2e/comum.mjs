/**
 * Andaime compartilhado dos testes de ponta a ponta.
 *
 * Sobe o Chromium do sistema, vigia o console e a rede, e reprova a suíte se o navegador acusar
 * qualquer erro de runtime ou qualquer resposta 4xx/5xx que o teste não tenha pedido. Uma tela em
 * branco é silenciosa sem isto.
 *
 * Roda contra a pilha efêmera que `tools/e2e.sh` levanta. Nunca contra produção.
 */
import puppeteer from 'puppeteer-core'

const CHROMIUM = process.env.E2E_CHROMIUM ?? '/bin/chromium-browser'

export const BASE = process.env.E2E_URL ?? 'http://127.0.0.1:5199'
export const EMAIL = 'e2e@fertways.test'
export const SENHA = 'segredo-forte-123'

export const falhas = []
export const erros = []

/**
 * Alguns testes provocam um 4xx de propósito — o logout, por exemplo, prova que o token morreu no
 * servidor. `janela.esperandoFalha` marca esse intervalo; fora dele, qualquer 4xx reprova.
 */
export const janela = { esperandoFalha: false }

export function checar(condicao, descricao) {
  if (condicao) {
    console.log(`  ✓ ${descricao}`)
  } else {
    console.log(`  ✗ ${descricao}`)
    falhas.push(descricao)
  }
}

/** Espera um elemento cujo texto case com `regex`, e devolve o handle. */
export async function acharPorTexto(page, seletor, regex, timeout = 8000) {
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

/** Todos os elementos cujo texto, aparado, seja exatamente `texto`. */
export async function todosPorTexto(page, seletor, texto) {
  const alvos = await page.$$(seletor)
  const casam = []

  for (const a of alvos) {
    if ((await a.evaluate((n) => (n.textContent ?? '').trim())) === texto) casam.push(a)
  }

  return casam
}

/*
 * `innerText` aplica o `text-transform: uppercase` da classe `.eyebrow`, então "Mercado Central"
 * chega como "MERCADO CENTRAL". Comparar sem diferenciar caixa — o que interessa é o texto que o
 * colono lê, e ele lê o transformado.
 */
export const textoDaPagina = (page) => page.evaluate(() => document.body.innerText)

export async function esperarTexto(page, regex, timeout = 8000) {
  const semCaixa = new RegExp(regex.source, regex.flags.includes('i') ? regex.flags : regex.flags + 'i')
  const fim = Date.now() + timeout
  while (Date.now() < fim) {
    if (semCaixa.test(await textoDaPagina(page))) return true
    await new Promise((r) => setTimeout(r, 150))
  }
  return false
}

/*
 * Os eventos de console e de resposta chegam pelo CDP, e não necessariamente antes de o `fetch` do
 * navegador resolver. Baixar a flag no instante seguinte à chamada abriria uma corrida: o evento
 * chegaria com a janela já fechada, e o 4xx esperado reprovaria a suíte de forma intermitente.
 */
export const assentar = () => new Promise((r) => setTimeout(r, 300))

const ignoravel = (url) => (url ?? '').endsWith('/favicon.ico')

export async function abrirNavegador() {
  const navegador = await puppeteer.launch({
    executablePath: CHROMIUM,
    headless: true,
    // Este ambiente roda como root; sem isto o Chromium recusa subir.
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
  })

  const page = await navegador.newPage()
  await page.setViewport({ width: 1400, height: 900 })

  page.on('pageerror', (e) => erros.push(`pageerror: ${e.message}`))

  page.on('console', (m) => {
    // O 404 do favicon também chega aqui, e sem a URL no texto: ela vive em `location`.
    if (m.type() === 'error' && !ignoravel(m.location()?.url) && !janela.esperandoFalha) {
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
    if (r.status() >= 400 && !ignoravel(r.url()) && !janela.esperandoFalha) {
      erros.push(`HTTP ${r.status()} em ${r.url()}`)
    }
  })

  return { navegador, page }
}

export async function entrar(page) {
  await page.goto(BASE, { waitUntil: 'networkidle2' })
  await page.type('input[type=email]', EMAIL)
  await page.type('input[type=password]', SENHA)
  await Promise.all([page.click('button[type=submit]'), page.waitForNetworkIdle({ idleTime: 800 })])
}

/** Imprime o veredito e devolve o código de saída do processo. */
export function relatar(nome) {
  if (erros.length) {
    console.log('\nErros de runtime no navegador:')
    for (const e of new Set(erros)) console.log(`  ! ${e}`)
  }

  const verde = falhas.length === 0 && erros.length === 0
  console.log(`\n${nome}: ${verde ? 'E2E VERDE' : 'E2E VERMELHO'}`)

  return verde ? 0 : 1
}
