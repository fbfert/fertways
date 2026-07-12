import Phaser from 'phaser'
import { api } from '../api/client'

/**
 * A arte das construções (docs/decisoes.md D-68).
 *
 * O servidor devolve um mapa `chave da coisa → URLs`, e **só o que TEM imagem aparece nele**. Quem
 * não tem simplesmente não vem — e o desenho cai no **hexágono colorido**, que continua sendo o
 * fallback. É isso que faz o jogo nunca ficar com buraco enquanto a arte não chega, e que permite ao
 * operador ir preenchendo aos poucos pelo painel.
 *
 * ⚠️ **O Phaser não desenha uma textura que não está na cache**, e carregar é assíncrono. Se a cena
 * pedisse `this.add.image(...)` com uma chave que ainda não chegou, ela desenharia um quadrado verde
 * de "textura ausente" — que é pior que o hexágono. Por isso o carregamento vem ANTES do desenho, e a
 * cena só usa o que já está garantidamente na cache (`textures.exists`).
 */

export type Arte = Record<string, { pequena: string; grande: string }>

let cache: Arte | null = null
let voando: Promise<Arte> | null = null

/**
 * Busca o mapa uma vez e o guarda. Várias cenas o pedem (a colônia, a Capital, a zona) e não faz
 * sentido bater na API três vezes por uma tabela que muda quando um operador clica num botão.
 */
export function carregarArte(): Promise<Arte> {
  if (cache) return Promise.resolve(cache)
  if (voando) return voando

  voando = api
    .imagens()
    .then((r) => {
      cache = r.images

      return cache
    })
    .catch(() => {
      // Sem arte, o jogo desenha hexágonos — como sempre desenhou. Uma falha aqui não pode
      // derrubar a colônia inteira.
      cache = {}

      return cache
    })
    .finally(() => {
      voando = null
    })

  return voando
}

/** A chave de textura do Phaser para uma coisa do jogo. Prefixada, para não colidir com nada. */
export const chaveDeTextura = (entidade: string) => `arte:${entidade}`

/**
 * Põe na cache do Phaser as texturas que faltam, e resolve quando terminarem.
 *
 * Resolve **na hora** se não houver nada a carregar — é o caso comum, depois da primeira vez. Sem
 * isso, todo redesenho pagaria um `load.start()` inútil.
 */
export function carregarTexturas(cena: Phaser.Scene, arte: Arte): Promise<void> {
  const faltando = Object.entries(arte).filter(
    ([chave]) => !cena.textures.exists(chaveDeTextura(chave)),
  )

  if (faltando.length === 0) return Promise.resolve()

  return new Promise((resolve) => {
    for (const [chave, urls] of faltando) {
      cena.load.image(chaveDeTextura(chave), urls.pequena)
    }

    // `once` e não `on`: o loader vive enquanto a cena viver, e um `on` acumularia um handler a
    // cada carga — cem redesenhos, cem resoluções da mesma promessa.
    cena.load.once(Phaser.Loader.Events.COMPLETE, () => resolve())

    /*
     * Se um PNG não existir mais (o operador o apagou entre o mapa chegar e a textura carregar), o
     * loader dispara FILE_LOAD_ERROR e o COMPLETE **ainda assim** vem depois — o Phaser não trava
     * por um arquivo faltando. A cena então não acha a textura e desenha o hexágono. É o fallback
     * funcionando, e não um caso de erro.
     */
    cena.load.start()
  })
}
