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
    //
    // ⚠️ E roda num servidor de 4 GB SEM SWAP, dividido com o MariaDB de produção: o resto das
    // flags é dieta de memória. O e2e já morreu de OOM (`exit 137`) por falta delas — e `exit 137`
    // não é teste reprovado, é o kernel escolhendo uma vítima.
    args: [
      '--no-sandbox',
      '--disable-dev-shm-usage',
      '--disable-gpu',
      '--disable-extensions',
      '--no-first-run',
      // O teto do heap do JS dentro do Chromium. O jogo inteiro cabe folgado em 256 MB.
      '--js-flags=--max-old-space-size=256',
      // Sem isto o Chromium abre um processo de renderização por origem, e cada um custa ~100 MB.
      '--renderer-process-limit=2',
    ],
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

/**
 * Clica numa construção da colônia, pelo nome.
 *
 * Os hexágonos são pintados pelo Phaser, mas **os alvos de clique são botões de DOM** sobrepostos
 * a eles (ver `ColonyCanvas`), cada um com o seu `aria-label`. É por isso que este helper procura
 * por rótulo, como todo o resto da suíte, em vez de calcular pixels — e é por isso que o e2e
 * consegue tocar a colônia, coisa que num canvas puro ele não conseguiria.
 */
export async function clicarNaConstrucao(page, nome) {
  const botao = await page.$(`button[aria-label^="${nome},"]`)

  if (!botao) throw new Error(`não achei a construção "${nome}" na colônia`)

  await botao.click()
  await assentar()
}

/** Clica num slot vazio, por número. */
export async function clicarNoSlotVazio(page, slot) {
  const botao = await page.$(`button[aria-label^="Slot ${slot},"]`)

  if (!botao) throw new Error(`o slot ${slot} não está vazio (ou não existe)`)

  await botao.click()
  await assentar()
}

/**
 * A Capital, que desde o D-59 só se alcança pelo mapa — e é lá dentro que ficam o Ministério e o
 * Mercado Central, instituições do governo (§2.1) e não construções do colono.
 */
/**
 * Vai a uma tela pela URL (D-67).
 *
 * **Isto só é possível porque as telas têm endereço.** Enquanto eram popups, a única forma de chegar
 * a uma tela era clicar o caminho inteiro até ela, e a única forma de sair era fechar na ordem
 * inversa. Agora um endereço basta — e usar isto no e2e **prova o link direto**, que é metade do
 * motivo de o D-67 existir: recarregar a página não pode largar o colono na colônia.
 *
 * Recarrega a aplicação de verdade (o token vive no `localStorage` e sobrevive), então serve também
 * de teste do `.htaccess`: se o servidor não devolvesse o `index.html` em `/zona/12`, isto quebraria.
 */
export async function irPara(page, rota) {
  /*
   * `domcontentloaded`, e **não** `networkidle2`. O `entrar()` usa `networkidle2` e funciona porque
   * a tela de login não fala com o servidor depois de carregar. Aqui o colono já está dentro, e o
   * App faz *polling* de `/colony`, `/buildings`, `/queue` e `/catalogo` **de 5 em 5 segundos**: a
   * rede nunca fica ociosa, e a espera estoura os 30 s sem que nada esteja errado.
   *
   * Custou uma rodada inteira de e2e descobrir isso.
   */
  await page.goto(`${BASE}${rota}`, { waitUntil: 'domcontentloaded' })
  await assentar()
}

/** Volta à colônia — a rota `/`. Fechar uma tela devolve à ANTERIOR, que nem sempre é a colônia. */
export const irParaColonia = (page) => irPara(page, '/')

export async function abrirCapital(page) {
  await (await acharPorTexto(page, 'button', /^Mapa$/)).click()

  /*
   * Espera o LOSANGO, não o texto — e a diferença já mordeu (2026-07-12, ao construir a guerra).
   *
   * "Capital em (0,0)" aparece no cabeçalho assim que o diretório chega. Mas o losango vive dentro
   * do SVG, e o SVG só desenha quando a **vista** existe — e ela é calculada num `useEffect` que
   * corre DEPOIS. Há, portanto, um instante em que o texto está na tela e o alvo do clique não
   * está.
   *
   * Esperar o texto era esperar por procuração. Passava quase sempre, e quebrava quando qualquer
   * mudança no Mapa deslocava o tempo dos renders — foi o que aconteceu ao acrescentar o painel de
   * ataque. **Espere o que você vai clicar.**
   */
  await page.waitForSelector('[aria-label="Capital"]')
  await page.click('[aria-label="Capital"]')
  await assentar()
}

/** Imprime o veredito e devolve o código de saída do processo. */
export function relatar(nome) {
  /*
   * As falhas também: uma exceção no meio do roteiro entra aqui e em lugar nenhum mais. Sem esta
   * lista, um teste que estoura no primeiro clique imprime "E2E VERMELHO" e nada que explique.
   */
  if (falhas.length) {
    console.log('\nFalhas:')
    for (const f of falhas) console.log(`  ✗ ${f}`)
  }

  if (erros.length) {
    console.log('\nErros de runtime no navegador:')
    for (const e of new Set(erros)) console.log(`  ! ${e}`)
  }

  const verde = falhas.length === 0 && erros.length === 0
  console.log(`\n${nome}: ${verde ? 'E2E VERDE' : 'E2E VERMELHO'}`)

  return verde ? 0 : 1
}
