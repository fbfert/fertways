import Phaser from 'phaser'
import type { Spec } from '../api/client'
import { transformar, VISTA_INICIAL, type Vista } from './vista'

/** Tokens amostrados do deck. Repetidos aqui porque Phaser não lê CSS. */
export const CORES = {
  rust: 0xb4450b,
  rustBright: 0xcd5512,
  ember: 0xeaae65,
  sand: 0xf8e7d6,
  sandLight: 0xfdf0e2,
  ink: 0x1e1c17,
  inkSoft: 0x372f27,
}

/**
 * O raio e o centro de cada slot da colmeia, em pixels do canvas.
 *
 * **Fonte única da geometria**, usada por dois consumidores: a cena, que desenha os hexágonos, e a
 * camada de botões do `ColonyCanvas`, que os torna clicáveis. Fossem duas contas, elas divergiriam
 * — e o colono clicaria num buraco para acertar o vizinho.
 *
 * **O zoom entra aqui, e não na câmera do Phaser** (D-63). Se ele fosse `camera.setZoom()`, o desenho
 * aproximaria e os botões de DOM ficariam onde estavam — o colono veria a Oficina grande no meio da
 * tela e clicaria nela para acertar o vizinho. Passando a vista pela geometria, os dois consumidores
 * continuam lendo os mesmos números, que é a razão de esta função existir. Ver `vista.ts`.
 */
export function colmeia(
  linhas: number[],
  largura: number,
  altura: number,
  vista: Vista = VISTA_INICIAL,
) {
  const folga = 1.14
  const base = Math.min(largura / 13.5, altura / 10.5)
  const passoX = Math.sqrt(3) * base * folga
  const passoY = 1.5 * base * folga
  const meioY = ((linhas.length - 1) * passoY) / 2

  const centros: [number, number][] = []

  linhas.forEach((quantas, linha) => {
    // Cada linha é centrada em si mesma: as de 4 ficam meio passo para dentro das de 5, e é isso
    // que encaixa a colmeia.
    const inicio = -((quantas - 1) * passoX) / 2

    for (let i = 0; i < quantas; i++) {
      centros.push(
        transformar(
          largura / 2 + inicio + i * passoX,
          altura / 2 + linha * passoY - meioY,
          largura,
          altura,
          vista,
        ),
      )
    }
  })

  // O raio escala junto: um hexágono que não cresce com o zoom não é zoom, é panorâmica.
  return { r: base * vista.escala, centros }
}

const NOMES: Record<string, string> = {
  gerador_de_atmosfera: 'Gerador de Atmosfera',
  estrutura_de_sobrevivencia: 'Estrutura de Sobrevivência',
  fazenda: 'Fazenda',
  reator_de_energia: 'Reator de Energia',
  captacao_de_agua: 'Captação de Água',
  oficina: 'Oficina',
  refinaria_quimica: 'Refinaria Química',
  laboratorio: 'Laboratório',
  antena_de_comunicacao: 'Antena de Comunicação',
  torre_de_defesa: 'Torre de Defesa',
  mercado_local: 'Mercado Local',
  quartel: 'Quartel',
  plataforma_de_pouso: 'Plataforma de Pouso',
  central_de_transportes: 'Central de Transportes',
  mina_local: 'Mina Local',
  destilaria: 'Destilaria',
  tanque_de_combustivel: 'Tanque de Combustível',
}

export function rotulo(tipo: string): string {
  return NOMES[tipo] ?? tipo
}

/**
 * O slot principal do colono: a colmeia de 21 posições (D-59).
 *
 * Antes, a cena desenhava uma construção por hexágono, em anéis, e as 16 estavam sempre lá — as
 * não erguidas apareciam vazadas. Agora o buraco vem primeiro: **os 21 slots são desenhados
 * sempre**, e cada um pode estar vazio ou ter uma construção dentro.
 *
 * **A cena não escuta cliques.** Os alvos são botões de DOM, sobrepostos ao canvas pelo
 * `ColonyCanvas` — e não é detalhe de implementação, é a diferença entre a colônia ser navegável
 * ou não. Desde o D-59 (item 6) todo caminho do jogo passa por uma construção: a Frota vive na
 * Central de Transportes, os Acordos no Mercado Local. Se o único alvo de clique fosse um pixel de
 * canvas, o jogo inteiro ficaria fora do alcance de quem usa teclado ou leitor de tela — e fora do
 * alcance do e2e, que também não consegue clicar num canvas. A cena pinta; o DOM recebe o clique.
 *
 * As `linhas` (4/4/5/4/4) vêm da API, não daqui: o layout é decisão de domínio
 * (`Domain/Colony/Slots`), e um número copiado para o React sobreviveria mentindo a uma mudança.
 * É a lição do D-54, aplicada de novo.
 */
export class ColonyScene extends Phaser.Scene {
  private specs: Spec[] = []
  private linhas: number[] = []
  private realcado: number | null = null
  private vista: Vista = VISTA_INICIAL
  private raiz!: Phaser.GameObjects.Container

  constructor() {
    super('colonia')
  }

  create() {
    this.cameras.main.setBackgroundColor(CORES.sand)
    this.raiz = this.add.container(0, 0)
    this.desenharTerreno()
    this.desenhar()
    this.scale.on('resize', () => this.desenhar())
  }

  atualizar(specs: Spec[], linhas: number[], vista: Vista) {
    this.specs = specs
    this.linhas = linhas
    this.vista = vista
    if (this.raiz) this.desenhar()
  }

  /** O hexágono sob o cursor, apontado pelo botão de DOM que está por cima dele. */
  realcar(slot: number | null) {
    if (this.realcado === slot) return
    this.realcado = slot
    if (this.raiz) this.desenhar()
  }

  private desenharTerreno() {
    const g = this.add.graphics()
    const { width, height } = this.scale
    // Faixas de poeira: o deck é sempre quente, nunca cinza.
    for (let i = 0; i < 6; i++) {
      g.fillStyle(i % 2 ? CORES.sandLight : CORES.sand, 0.6)
      g.fillEllipse(width / 2, height * 0.62 + i * 26, width * (1.1 - i * 0.12), 120 - i * 12)
    }
    g.setDepth(-1)
  }

  private hexPontos(cx: number, cy: number, r: number): Phaser.Math.Vector2[] {
    // Hexágono "pontudo em cima", como o do logo.
    return Array.from({ length: 6 }, (_, i) => {
      const a = Phaser.Math.DegToRad(60 * i - 90)
      return new Phaser.Math.Vector2(cx + r * Math.cos(a), cy + r * Math.sin(a))
    })
  }

  private desenhar() {
    this.raiz.removeAll(true)
    if (!this.linhas.length) return

    const { width, height } = this.scale
    // A MESMA chamada que o `ColonyCanvas` faz para pôr os botões. É o que impede o desenho e o
    // alvo de clique de divergirem quando o colono aproxima.
    const { r, centros } = colmeia(this.linhas, width, height, this.vista)
    const porSlot = new Map(this.specs.map((s) => [s.slot, s]))

    centros.forEach(([x, y], slot) => {
      const spec = porSlot.get(slot)
      const realce = this.realcado === slot

      this.raiz.add(
        spec
          ? this.desenharConstrucao(spec, x, y, r, realce)
          : this.desenharVazio(x, y, r, realce),
      )
    })
  }

  /**
   * O slot vazio. Não é decoração: é o convite. Um traço interrompido e um "+" — nada de rótulo,
   * porque um buraco não tem nome ainda.
   */
  private desenharVazio(x: number, y: number, r: number, realce: boolean) {
    const c = this.add.container(x, y)
    const pontos = this.hexPontos(0, 0, r)

    const g = this.add.graphics()
    if (realce) {
      g.fillStyle(CORES.ember, 0.35)
      g.fillPoints(pontos, true, true)
      g.lineStyle(2.5, CORES.rustBright, 1)
    } else {
      g.fillStyle(CORES.sandLight, 0.55)
      g.fillPoints(pontos, true, true)
      g.lineStyle(1.5, CORES.rust, 0.28)
    }
    g.strokePoints(pontos, true)
    c.add(g)

    c.add(
      this.add
        .text(0, 0, '+', {
          fontFamily: 'Archivo, Inter, sans-serif',
          fontSize: `${Math.round(r * 0.5)}px`,
          fontStyle: 'bold',
          color: '#b4450b',
        })
        .setOrigin(0.5)
        .setAlpha(realce ? 1 : 0.45),
    )

    return c
  }

  private desenharConstrucao(spec: Spec, x: number, y: number, r: number, realce: boolean) {
    const c = this.add.container(x, y)
    // Nível 0 = está em obra: a linha já existe e ocupa o slot, mas a construção não subiu.
    const erguida = spec.level > 0
    const pontos = this.hexPontos(0, 0, r)

    const g = this.add.graphics()
    /*
     * `fillPoints(pontos, true, true)`: o segundo argumento (`closeShape`) une o último ponto ao
     * primeiro; o terceiro (`closePath`) fecha o caminho **antes de preencher**. Sem o terceiro,
     * o Phaser triangula um caminho aberto e o hexágono sai como uma gravata-borboleta. O defeito
     * só aparecia nas construções erguidas: nas vazias o preenchimento é quase da cor do fundo.
     */
    if (erguida) {
      g.fillStyle(CORES.rust, 1)
      g.fillPoints(pontos, true, true)
      g.lineStyle(realce ? 3 : 2, realce ? CORES.rustBright : CORES.ember, 1)
    } else {
      g.fillStyle(CORES.ember, 0.5)
      g.fillPoints(pontos, true, true)
      g.lineStyle(realce ? 3 : 2, CORES.rust, realce ? 1 : 0.8)
    }
    g.strokePoints(pontos, true)
    c.add(g)

    c.add(
      this.add
        .text(0, -r * 0.3, erguida ? String(spec.level) : '⏳', {
          fontFamily: 'Archivo, Inter, sans-serif',
          fontSize: `${Math.round(r * 0.5)}px`,
          fontStyle: 'bold',
          color: erguida ? '#fdf0e2' : '#b4450b',
        })
        .setOrigin(0.5),
    )

    /*
     * O nome vai DENTRO do hexágono. Numa colmeia de hexágonos pontudos as linhas distam `1,5·r`,
     * então não sobra faixa livre abaixo de um hexágono: o da linha seguinte é desenhado depois e
     * pinta por cima do rótulo. Não era truncamento de texto, era oclusão.
     */
    c.add(
      this.add
        .text(0, r * 0.12, rotulo(spec.type), {
          fontFamily: 'Archivo, Inter, sans-serif',
          fontSize: `${Math.max(9, Math.round(r * 0.14))}px`,
          color: erguida ? '#fdf0e2' : '#372f27',
          align: 'center',
          wordWrap: { width: r * 1.15, useAdvancedWrap: true },
        })
        .setOrigin(0.5, 0),
    )

    return c
  }
}
