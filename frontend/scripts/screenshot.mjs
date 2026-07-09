/**
 * Dirige o front num Chromium headless e tira prints. Não é build nem teste unitário:
 * serve para VER a cena Phaser renderizar, coisa que `tsc` e `vite build` não provam.
 *
 *   node tools/screenshot.mjs <url> <token> <destino>
 */
import puppeteer from 'puppeteer-core'

const [, , url, token, destino] = process.argv

const browser = await puppeteer.launch({
  executablePath: '/usr/bin/chromium-browser',
  headless: 'new',
  args: ['--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage'],
})

const page = await browser.newPage()
await page.setViewport({ width: 1600, height: 900 })

const erros = []
page.on('console', (m) => m.type() === 'error' && erros.push(m.text()))
page.on('pageerror', (e) => erros.push(`pageerror: ${e.message}`))

if (token) {
  await page.goto(url, { waitUntil: 'domcontentloaded' })
  await page.evaluate((t) => localStorage.setItem('fertways.token', t), token)
}

await page.goto(url, { waitUntil: 'networkidle0' })
await new Promise((r) => setTimeout(r, 1500))   // deixa o Phaser desenhar
await page.screenshot({ path: destino })

// O canvas existe e tem pixels?
const info = await page.evaluate(() => {
  const c = document.querySelector('canvas')
  return c ? { canvas: true, w: c.width, h: c.height } : { canvas: false }
})

console.log(JSON.stringify({ destino, ...info, erros }, null, 2))
await browser.close()
