import Phaser from 'phaser'
import type { Spec } from '../api/client'

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

export const EVENTOS = {
  selecionou: 'construcao:selecionada',
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
}

export function rotulo(tipo: string): string {
  return NOMES[tipo] ?? tipo
}

/**
 * Slot principal do colono. O GDD não define uma grade de posições (D-05), então a cena
 * não desenha "slots" numerados: cada construção é um hexágono, disposto em anéis.
 * Uma construção no nível 0 aparece como contorno vazado; erguida, preenchida.
 */
export class ColonyScene extends Phaser.Scene {
  private specs: Spec[] = []
  private raiz!: Phaser.GameObjects.Container

  private readonly aoSelecionar?: (spec: Spec) => void

  /** `this.events` só existe depois do boot da cena, então o ouvinte é registrado no `create()`. */
  constructor(aoSelecionar?: (spec: Spec) => void) {
    super('colonia')
    this.aoSelecionar = aoSelecionar
  }

  create() {
    this.cameras.main.setBackgroundColor(CORES.sand)
    this.raiz = this.add.container(0, 0)
    this.desenharTerreno()
    this.desenhar()
    this.scale.on('resize', () => this.desenhar())

    if (this.aoSelecionar) this.events.on(EVENTOS.selecionou, this.aoSelecionar)
  }

  atualizar(specs: Spec[]) {
    this.specs = specs
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
    if (!this.specs.length) return

    const { width, height } = this.scale
    const cx = width / 2
    const cy = height / 2
    const r = Math.min(width, height) / 11

    // Anéis concêntricos: 1 no centro, 6 no primeiro anel, o resto no segundo.
    const posicoes: [number, number][] = [[cx, cy]]
    for (const [anel, qtd] of [
      [1, 6],
      [2, 12],
    ] as const) {
      const raio = anel * r * 1.9
      for (let i = 0; i < qtd; i++) {
        const a = Phaser.Math.DegToRad((360 / qtd) * i - 90)
        posicoes.push([cx + raio * Math.cos(a), cy + raio * Math.sin(a) * 0.72])
      }
    }

    this.specs.forEach((spec, i) => {
      const [x, y] = posicoes[i] ?? posicoes[posicoes.length - 1]
      this.raiz.add(this.desenharConstrucao(spec, x, y, r))
    })
  }

  private desenharConstrucao(spec: Spec, x: number, y: number, r: number) {
    const c = this.add.container(x, y)
    const erguida = spec.level > 0
    const pontos = this.hexPontos(0, 0, r)

    const g = this.add.graphics()
    if (erguida) {
      g.fillStyle(CORES.rust, 1)
      g.fillPoints(pontos, true)
      g.lineStyle(2, CORES.ember, 1)
    } else {
      g.fillStyle(CORES.sandLight, 1)
      g.fillPoints(pontos, true)
      g.lineStyle(2, CORES.rust, 0.45)
    }
    g.strokePoints(pontos, true)
    c.add(g)

    const nivel = this.add
      .text(0, -6, erguida ? String(spec.level) : '—', {
        fontFamily: 'Archivo, Inter, sans-serif',
        fontSize: `${Math.round(r * 0.62)}px`,
        fontStyle: 'bold',
        color: erguida ? '#fdf0e2' : '#b4450b',
      })
      .setOrigin(0.5)
    c.add(nivel)

    const nome = this.add
      .text(0, r + 12, rotulo(spec.type), {
        fontFamily: 'Archivo, Inter, sans-serif',
        fontSize: '11px',
        color: '#372f27',
        align: 'center',
        wordWrap: { width: r * 2.6 },
      })
      .setOrigin(0.5, 0)
    c.add(nome)

    // O hexágono inteiro é o alvo de clique, não a caixa do container.
    const hit = new Phaser.Geom.Polygon(pontos)
    c.setSize(r * 2, r * 2)
    c.setInteractive(hit, Phaser.Geom.Polygon.Contains)

    c.on('pointerover', () => {
      g.lineStyle(3, CORES.rustBright, 1)
      g.strokePoints(pontos, true)
      this.input.setDefaultCursor('pointer')
    })
    c.on('pointerout', () => {
      this.input.setDefaultCursor('default')
      this.desenhar()
    })
    c.on('pointerdown', () => this.events.emit(EVENTOS.selecionou, spec))

    return c
  }
}
