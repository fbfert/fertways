/**
 * "Desde sua última visita" (A2.0.3; janela do GDD ALPHA 2 §5.1).
 *
 * **Roda PRIMEIRO, e não por acaso.** O resumo é um popup `fixed inset-0` que se convida no login;
 * se ficasse aberto, interceptaria os cliques de todas as outras suítes. Ao ser fechado ele avança
 * o marcador, e o piso de uma hora do §5.1 o silencia pelo resto da execução — o que este arquivo
 * prova de quebra, recarregando a página e conferindo que ele não volta.
 *
 * O que só um DOM de verdade prova aqui: que o modal aparece sozinho depois do login, que mostra a
 * produção da janela, e que **fechar não é cosmético** — a janela realmente se fecha do lado do
 * servidor, e o resumo não reaparece na recarga seguinte.
 */
import {
  abrirNavegador,
  assentar,
  checar,
  entrar,
  esperarTexto,
  falhas,
  relatar,
  BASE, fecharNavegador } from './comum.mjs'

const { navegador, page } = await abrirNavegador()

try {
  console.log('\nO resumo se convida no login')
  await entrar(page)

  // O modal chega depois de uma ida ao servidor, e não junto com o HUD: espera por ele, em vez de
  // olhar uma vez e desistir.
  const resumo = await page
    .waitForSelector('[data-resumo]', { timeout: 8000 })
    .catch(() => null)
  checar(!!resumo, 'o resumo aparece sozinho depois do login')

  checar(
    await esperarTexto(page, /Desde sua última visita/),
    'com o título que diz de que período se trata',
  )

  console.log('\nMostra o que aconteceu na janela')
  checar(await esperarTexto(page, /Produziu/), 'a seção de produção aparece')
  // 250 de Metal Bruto, semeados dentro da janela pelo tools/e2e.sh.
  checar(await esperarTexto(page, /Metal Bruto/), 'o recurso produzido aparece pelo nome, traduzido')
  checar(await esperarTexto(page, /250/), 'com a quantidade agregada da janela')

  console.log('\nFechar move a janela — e isso é do servidor, não da tela')
  const continuar = await page.$('[data-resumo-fechar]')
  checar(!!continuar, 'o botão Continuar existe')
  await continuar.click()

  /*
   * Espera o elemento SUMIR, em vez de dormir 300 ms e olhar uma vez — o mesmo erro que já custou
   * um vermelho em `chat.e2e.mjs`. Se o resumo não sumir mesmo, os 8 s se esgotam e isto reprova.
   */
  const sumiu = await page
    .waitForSelector('[data-resumo]', { hidden: true, timeout: 8000 })
    .then(() => true)
    .catch(() => false)
  checar(sumiu, 'o resumo some ao ser fechado')

  /*
   * A prova de que o fechamento foi ao servidor. Se o `POST /resumo/visto` não tivesse ido, o
   * marcador continuaria 5 h atrás e o modal voltaria na recarga — que é exatamente o bug que este
   * teste existe para pegar.
   */
  console.log('\nRecarregar não traz o resumo de volta (piso de uma hora do §5.1)')
  /*
   * `domcontentloaded`, e **não** `networkidle2` — a diferença já custou um vermelho.
   *
   * O `entrar()` de `comum.mjs` usa `networkidle2` e funciona porque, antes do login, não há
   * polling nenhum. Depois de logado o jogo consulta o servidor a cada poucos segundos (HUD, chat),
   * então a rede NUNCA fica ociosa e o `goto` espera até estourar os 30 s. O sintoma é um timeout
   * que parece problema de desempenho e é só a condição de espera errada.
   */
  await page.goto(BASE, { waitUntil: 'domcontentloaded' })
  await esperarTexto(page, /Fert\$/)
  await assentar()

  const voltou = await page
    .waitForSelector('[data-resumo]', { timeout: 2500 })
    .catch(() => null)
  checar(!voltou, 'o resumo NÃO reaparece: a janela foi fechada de verdade')
} catch (e) {
  falhas.push(`exceção: ${e.message}`)
  await page.screenshot({ path: '/tmp/e2e-resumo-falha.png' }).catch(() => {})
  console.log('\nscreenshot em /tmp/e2e-resumo-falha.png')
} finally {
  await fecharNavegador(navegador)
  relatar('Resumo de retorno')
}
