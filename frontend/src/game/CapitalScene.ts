import Phaser from 'phaser'
import { carregarArte, carregarTexturas, chaveDeTextura } from './arte'
import { CORES } from './ColonyScene'
import { transformar, VISTA_INICIAL, type Vista } from './vista'

/**
 * A Capital, como **lugar** e não como menu (D-63).
 *
 * ---
 *
 * **A planta não é do GDD.** O documento trata a Capital como uma lista plana de 20 slots (§2.1), sem
 * geografia nenhuma — não há praça, não há bairros, não há norte nem sul. As quatro áreas e a praça
 * são **arbitragem do usuário**, e é bom que um leitor futuro saiba disso antes de procurar no
 * documento uma planta que não existe.
 *
 * **O Leste é o slot 6 inteiro.** No GDD, o slot 6 *é* o Estacionamento de Caminhões ("20 vagas.
 * Cobrança por hora.") — que a versão sanitizada rebatiza de Pátio Logístico Público, e é dentro dele
 * que o Mercado Central mora desde o D-55. O desenho original punha o Mercado no Leste e o Pátio
 * entre o Leste e o Sul: **dois lugares para a mesma coisa**. O usuário juntou os dois. Os caminhões
 * estacionados são **desenho**, não uma segunda porta.
 *
 * **O Norte mostra 19 slots, não 20**, e o buraco é de propósito: o 6 não aparece lá porque ele *é* o
 * Leste. Uma coisa, um lugar.
 *
 * ---
 *
 * **Esta cena não escuta cliques**, como a da colônia: os alvos são botões de DOM sobrepostos pelo
 * `CapitalCanvas`. E pela mesma razão — um canvas não tem foco, não responde a Tab e o e2e não clica
 * nele. A cena pinta; o DOM recebe o clique. A geometria sai de `plantaDaCapital()`, que os dois
 * consomem: fossem duas contas, elas divergiriam.
 */

/** Os slots institucionais do §2.1. O 6 não está aqui: ele é a área do Leste. */
export const SLOTS_DO_NORTE = [1, 2, 3, 4, 5, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20]

export type AreaId = 'norte' | 'oeste' | 'leste' | 'sul' | 'praca'

export type Area = {
  id: AreaId
  nome: string
  /** Retângulo em coordenadas de planta (0..1), antes da vista. */
  cx: number
  cy: number
  w: number
  h: number
}

/**
 * A planta, em coordenadas normalizadas (0..1) sobre o canvas.
 *
 * Escrita uma vez e transformada pela vista — a mesma que o zoom da colônia usa. As áreas não se
 * tocam: a folga entre elas é o que faz a Capital parecer uma cidade e não um mosaico.
 */
export const AREAS: Area[] = [
  { id: 'norte', nome: 'Governo Central', cx: 0.5, cy: 0.235, w: 0.66, h: 0.34 },
  { id: 'oeste', nome: 'Destroços da Endurance', cx: 0.16, cy: 0.55, w: 0.24, h: 0.28 },
  { id: 'leste', nome: 'Mercado Central e Pátio Logístico', cx: 0.84, cy: 0.55, w: 0.24, h: 0.28 },
  { id: 'praca', nome: 'Praça Central', cx: 0.5, cy: 0.55, w: 0.14, h: 0.16 },
  { id: 'sul', nome: 'Espaçoporto', cx: 0.5, cy: 0.845, w: 0.44, h: 0.22 },
]

/**
 * A geometria da Capital, já com a vista aplicada. **Fonte única**, como a `colmeia`.
 *
 * Devolve os retângulos das áreas e os centros dos 19 hexágonos do Norte — que a cena desenha e o
 * `CapitalCanvas` cobre com botões.
 */
export function plantaDaCapital(largura: number, altura: number, vista: Vista = VISTA_INICIAL) {
  const areas = AREAS.map((a) => {
    const [x, y] = transformar(a.cx * largura, a.cy * altura, largura, altura, vista)

    return {
      ...a,
      x,
      y,
      w: a.w * largura * vista.escala,
      h: a.h * altura * vista.escala,
    }
  })

  // Os 19 slots do Norte, numa grade que cabe dentro da área. 7 por linha dá 3 linhas (7+7+5).
  const norte = areas.find((a) => a.id === 'norte')!
  const porLinha = 7
  const linhas = Math.ceil(SLOTS_DO_NORTE.length / porLinha)

  /*
   * A grade cede o alto da área ao rótulo. Sem esta folga, a primeira fila de hexágonos passava por
   * cima de "GOVERNO CENTRAL" — o rótulo ficava ilegível atrás dos números 1 a 8.
   */
  const folgaDoRotulo = norte.h * 0.16
  const util = norte.h - folgaDoRotulo

  const r = Math.min((norte.w / porLinha) * 0.42, (util / linhas) * 0.42)
  const passoX = norte.w / porLinha
  const passoY = util / linhas

  const centros: [number, number][] = SLOTS_DO_NORTE.map((_, i) => {
    const linha = Math.floor(i / porLinha)
    const col = i % porLinha
    // A última linha tem menos e é centrada em si mesma, senão ficaria encostada à esquerda.
    const nestaLinha = Math.min(porLinha, SLOTS_DO_NORTE.length - linha * porLinha)
    const inicio = -((nestaLinha - 1) * passoX) / 2

    return [
      norte.x + inicio + col * passoX,
      norte.y + folgaDoRotulo / 2 - ((linhas - 1) * passoY) / 2 + linha * passoY,
    ]
  })

  return { areas, hexR: r, hexCentros: centros }
}

/** O que cada slot do Norte é, e se já existe. Espelha o §2.1 e o que o jogo entrega. */
export type SlotDaCapital = {
  n: number
  nome: string
  /** `null` = ainda não abre nada. */
  abre: string | null
  estado: 'ativo' | 'em_breve' | 'reservado' | 'vago'
}

export class CapitalScene extends Phaser.Scene {
  private slots: SlotDaCapital[] = []
  private vista: Vista = VISTA_INICIAL
  private realcado: string | null = null
  private raiz!: Phaser.GameObjects.Container

  constructor() {
    super('capital')
  }

  create() {
    this.cameras.main.setBackgroundColor(CORES.sand)
    this.raiz = this.add.container(0, 0)
    this.desenhar()
    this.scale.on('resize', () => this.desenhar())

    /*
     * A arte (D-68). **A Capital ficou sem ela na primeira entrega**, e o defeito foi exatamente o
     * do D-63: os vínculos existiam no banco, a API os devolvia, e a CENA nunca era avisada. Os
     * sete e2e passavam — porque os cliques funcionavam e só o desenho estava vazio.
     */
    void carregarArte()
      .then((arte) => (this.viva() ? carregarTexturas(this, arte) : undefined))
      .then(() => this.viva() && this.desenhar())
  }

  /** A cena ainda existe? O React em modo estrito a destrói e a arte chega depois. Ver ColonyScene. */
  private viva(): boolean {
    return Boolean(this.raiz?.scene && this.sys?.isActive())
  }

  atualizar(slots: SlotDaCapital[], vista: Vista) {
    this.slots = slots
    this.vista = vista
    if (this.raiz) this.desenhar()
  }

  /** A chave do alvo sob o cursor: `area:leste` ou `slot:3`. */
  realcar(chave: string | null) {
    if (this.realcado === chave) return
    this.realcado = chave
    if (this.raiz) this.desenhar()
  }

  private desenhar() {
    this.raiz.removeAll(true)

    const { width, height } = this.scale
    const { areas, hexR, hexCentros } = plantaDaCapital(width, height, this.vista)

    for (const a of areas) {
      this.raiz.add(this.desenharArea(a, this.realcado === `area:${a.id}`))
    }

    SLOTS_DO_NORTE.forEach((n, i) => {
      const [x, y] = hexCentros[i]
      const slot = this.slots.find((s) => s.n === n)
      this.raiz.add(this.desenharSlot(n, slot, x, y, hexR, this.realcado === `slot:${n}`))
    })
  }

  private desenharArea(a: Area & { x: number; y: number }, realce: boolean) {
    const c = this.add.container(a.x, a.y)
    const g = this.add.graphics()

    const praca = a.id === 'praca'
    const clicavel = a.id !== 'norte' && !praca

    /*
     * A praça não realça e não tem borda viva: ela é o coração visual e **não clica**. Se piscasse
     * sob o cursor, o jogador tentaria clicá-la e não aconteceria nada — o pior tipo de convite.
     */
    if (realce && clicavel) {
      g.fillStyle(CORES.ember, 0.4)
      g.lineStyle(3, CORES.rustBright, 1)
    } else if (praca) {
      g.fillStyle(CORES.ember, 0.28)
      g.lineStyle(2, CORES.rust, 0.4)
    } else {
      g.fillStyle(CORES.sandLight, 0.75)
      g.lineStyle(1.5, CORES.rust, a.id === 'norte' ? 0.3 : 0.5)
    }

    g.fillRoundedRect(-a.w / 2, -a.h / 2, a.w, a.h, 8)
    g.strokeRoundedRect(-a.w / 2, -a.h / 2, a.w, a.h, 8)
    c.add(g)

    /*
     * A arte da área (D-68). O Oeste é a Endurance, o Leste o Mercado e o Pátio, o Sul o Espaçoporto.
     *
     * `contain` e não `cover`: as áreas são retângulos largos e o sprite é quadrado. Esticá-lo para
     * preencher deformaria o prédio; recortá-lo cortaria a antena. Cabe inteiro, centrado, e o resto
     * é o terreno da área — que é o que ele é.
     */
    const chaveArea = chaveDeTextura(`capital:area:${a.id}`)

    if (this.textures.exists(chaveArea)) {
      const img = this.add.image(0, a.h * 0.06, chaveArea)
      const lado = Math.min(a.w * 0.86, a.h * 0.82)
      img.setDisplaySize(lado, lado)
      img.setOrigin(0.5, 0.5)
      c.add(img)
    }

    // O rótulo da área vai no alto dela; o do Norte, acima dos hexágonos.
    c.add(
      this.add
        .text(0, -a.h / 2 + 10, a.nome.toUpperCase(), {
          fontFamily: 'Archivo, Inter, sans-serif',
          fontSize: `${Math.max(8, Math.round(Math.min(a.w, a.h) * 0.09))}px`,
          fontStyle: 'bold',
          color: praca ? '#372f27' : '#b4450b',
          align: 'center',
          wordWrap: { width: a.w * 0.9, useAdvancedWrap: true },
        })
        .setOrigin(0.5, 0)
        .setAlpha(0.85),
    )

    /*
     * Os croquis vetoriais — a carcaça da Endurance, os caminhões do Pátio, a torre do Espaçoporto —
     * eram o que a área tinha para mostrar antes de haver arte. **Com imagem de verdade eles somem**:
     * senão seriam riscos desenhados POR CIMA do prédio, e a área ficaria pior do que estava.
     *
     * São o mesmo fallback do hexágono da colônia. A área sem arte continua exatamente como era.
     */
    if (!this.textures.exists(chaveArea)) {
      if (a.id === 'oeste') this.desenharEndurance(c, a.w, a.h)
      if (a.id === 'leste') this.desenharPatio(c, a.w, a.h)
      if (a.id === 'sul') this.desenharEspacoporto(c, a.w, a.h)
    }

    return c
  }

  /** Os destroços: uma carcaça caída, não uma nave. Ela nunca mais voa (§01). */
  private desenharEndurance(c: Phaser.GameObjects.Container, w: number, h: number) {
    const g = this.add.graphics()
    g.fillStyle(CORES.inkSoft, 0.55)
    g.fillEllipse(0, h * 0.12, w * 0.62, h * 0.2)
    g.fillStyle(CORES.ink, 0.75)
    g.fillTriangle(-w * 0.3, h * 0.16, w * 0.28, h * 0.02, w * 0.06, h * 0.22)
    g.lineStyle(2, CORES.rust, 0.5)
    g.strokeTriangle(-w * 0.3, h * 0.16, w * 0.28, h * 0.02, w * 0.06, h * 0.22)
    c.add(g)
  }

  /** O pátio: caminhões parados. **Desenho, não mecânica** — o GDD nunca publica o preço da hora. */
  private desenharPatio(c: Phaser.GameObjects.Container, w: number, h: number) {
    const g = this.add.graphics()
    g.fillStyle(CORES.rust, 0.85)

    for (let i = 0; i < 4; i++) {
      const x = -w * 0.3 + (i % 2) * w * 0.34
      const y = h * 0.02 + Math.floor(i / 2) * h * 0.18
      g.fillRoundedRect(x, y, w * 0.26, h * 0.11, 2)
    }

    c.add(g)
  }

  /** O Espaçoporto: uma pista vazia. As rotas não abriram. */
  private desenharEspacoporto(c: Phaser.GameObjects.Container, w: number, h: number) {
    const g = this.add.graphics()
    g.lineStyle(2, CORES.rust, 0.35)

    for (let i = -2; i <= 2; i++) {
      g.beginPath()
      g.moveTo(i * w * 0.16, -h * 0.06)
      g.lineTo(i * w * 0.16, h * 0.3)
      g.strokePath()
    }

    c.add(g)
  }

  private hexPontos(r: number): Phaser.Math.Vector2[] {
    return Array.from({ length: 6 }, (_, i) => {
      const a = Phaser.Math.DegToRad(60 * i - 90)
      return new Phaser.Math.Vector2(r * Math.cos(a), r * Math.sin(a))
    })
  }

  private desenharSlot(
    n: number,
    slot: SlotDaCapital | undefined,
    x: number,
    y: number,
    r: number,
    realce: boolean,
  ) {
    const c = this.add.container(x, y)
    const pontos = this.hexPontos(r)
    const g = this.add.graphics()

    const estado = slot?.estado ?? 'vago'

    // A arte do slot (D-68). Só os ativos a mostram: um slot vago com prédio seria uma promessa falsa.
    const chaveSlot = chaveDeTextura(`capital:slot:${n}`)
    const temArte = estado === 'ativo' && this.textures.exists(chaveSlot)

    if (temArte) {
      // Com arte, o hexágono vira só o contorno do lugar — o prédio é o que se vê.
      g.fillStyle(CORES.sandLight, 0.4)
      g.lineStyle(realce ? 3 : 1.5, realce ? CORES.rustBright : CORES.rust, realce ? 1 : 0.4)
    } else if (estado === 'ativo') {
      g.fillStyle(realce ? CORES.rustBright : CORES.rust, 1)
      g.lineStyle(realce ? 3 : 2, CORES.ember, 1)
    } else if (estado === 'em_breve') {
      g.fillStyle(CORES.ember, 0.5)
      g.lineStyle(2, CORES.rust, 0.7)
    } else {
      // Vago e reservado: visíveis, mas apagados. É o que faz a Capital parecer um lugar que vai
      // crescer, e não um menu — e o vago não engana, porque não acende sob o cursor.
      g.fillStyle(CORES.sandLight, 0.6)
      g.lineStyle(1, CORES.rust, 0.22)
    }

    g.fillPoints(pontos, true, true)
    g.strokePoints(pontos, true)
    c.add(g)

    if (temArte) {
      // Transborda o hexágono, como na colônia: um prédio pousado no terreno, não um selo colado.
      const img = this.add.image(0, r * 0.12, chaveSlot)
      img.setDisplaySize(r * 1.7, r * 1.7)
      img.setOrigin(0.5, 0.58)
      c.add(img)
    }

    /*
     * ⚠️ **Com arte, o número vai para o TOPO** — a mesma correção da colônia, pela mesma razão: no
     * centro do hexágono ele cairia em cima da cúpula do prédio. Isso só se descobre **olhando**;
     * nenhum e2e o pegaria, porque o clique funciona e o texto está lá (D-63).
     */
    c.add(
      this.add
        .text(0, temArte ? -r * 0.62 : 0, String(n), {
          fontFamily: 'Archivo, Inter, sans-serif',
          fontSize: `${Math.max(9, Math.round(r * (temArte ? 0.4 : 0.62)))}px`,
          fontStyle: 'bold',
          color: temArte ? '#b4450b' : estado === 'ativo' ? '#fdf0e2' : '#372f27',
          stroke: temArte ? '#fdf0e2' : undefined,
          strokeThickness: temArte ? 4 : 0,
        })
        .setOrigin(0.5)
        .setAlpha(estado === 'ativo' || estado === 'em_breve' ? 1 : 0.4),
    )

    return c
  }
}
