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

  /**
   * Coordenadas axiais dos anéis de uma grade hexagonal, do centro para fora.
   * Anel 0 é o centro; o anel `k` tem `6k` células. Três anéis cobrem 19 posições, folgado
   * para as 16 construções do MVP.
   */
  private static anelAxial(k: number): [number, number][] {
    if (k === 0) return [[0, 0]]
    /*
     * A ordem das direções tem que casar com o canto de partida: começando em `[-k, k]`, que é
     * `k` passos na direção de índice 4, o percurso segue as seis direções a partir do índice 0.
     * Uma ordem rotacionada gera células a distâncias 0 a 4 do centro em vez de todas a `k` —
     * inclusive a própria origem, o que empilha construções no mesmo pixel.
     */
    const dir: [number, number][] = [
      [1, 0],
      [1, -1],
      [0, -1],
      [-1, 0],
      [-1, 1],
      [0, 1],
    ]
    const saida: [number, number][] = []
    let [q, r] = [-k, k]
    for (const [dq, dr] of dir) {
      for (let i = 0; i < k; i++) {
        saida.push([q, r])
        q += dq
        r += dr
      }
    }
    return saida
  }

  private desenhar() {
    this.raiz.removeAll(true)
    if (!this.specs.length) return

    const { width, height } = this.scale

    /*
     * Numa grade de hexágonos pontudos em cima, com circunraio `r`, o passo horizontal entre
     * centros é `√3·r` e o vertical é `1,5·r`.
     *
     * A versão anterior usava anéis circulares com o eixo Y comprimido em 0,72, o que punha o
     * primeiro anel a 1,37·r do centro — menos que os 2·r de dois hexágonos encostados. Eles se
     * sobrepunham.
     */
    const folga = 1.14
    const SQRT3 = Math.sqrt(3)
    /*
     * Com três anéis, a coluna axial vai de -2 a +2 e a linha também. Em largura isso ocupa
     * `4·passoX` entre os centros extremos mais a largura de um hexágono (`√3·r`); em altura,
     * `4·passoY` mais a altura de um (`2·r`). Os divisores abaixo são esses totais com uma
     * margem, para nada encostar na borda nem nos painéis do HUD.
     *
     * `folga` afasta os centros o suficiente para os hexágonos não se tocarem. Como o rótulo
     * agora vive dentro do hexágono, ela pode ser pequena e a colmeia fica justa.
     */
    const r = Math.min(width / 12.5, height / 9.6)
    const passoX = SQRT3 * r * folga
    const passoY = 1.5 * r * folga

    const posicoes = [0, 1, 2].flatMap((k) => ColonyScene.anelAxial(k)).slice(0, this.specs.length)

    // Axial -> pixel, com o deslocamento de meia coluna que dá o encaixe da colmeia.
    const aPixel = ([q, linha]: [number, number]) => [passoX * (q + linha / 2), passoY * linha]

    /*
     * O último anel quase nunca fecha — são 16 construções para 19 células —, então centralizar
     * na origem axial deixaria a colmeia visivelmente torta. Centralizamos na caixa que os
     * hexágonos de fato ocupam.
     */
    const xs = posicoes.map((p) => aPixel(p)[0])
    const ys = posicoes.map((p) => aPixel(p)[1])
    const meioX = (Math.min(...xs) + Math.max(...xs)) / 2
    const meioY = (Math.min(...ys) + Math.max(...ys)) / 2

    this.specs.forEach((spec, i) => {
      const [dx, dy] = aPixel(posicoes[i])
      this.raiz.add(
        this.desenharConstrucao(spec, width / 2 + dx - meioX, height / 2 + dy - meioY, r),
      )
    })
  }

  private desenharConstrucao(spec: Spec, x: number, y: number, r: number) {
    const c = this.add.container(x, y)
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
      g.lineStyle(2, CORES.ember, 1)
    } else {
      g.fillStyle(CORES.sandLight, 1)
      g.fillPoints(pontos, true, true)
      g.lineStyle(2, CORES.rust, 0.45)
    }
    g.strokePoints(pontos, true)
    c.add(g)

    const nivel = this.add
      .text(0, -r * 0.3, erguida ? String(spec.level) : '—', {
        fontFamily: 'Archivo, Inter, sans-serif',
        fontSize: `${Math.round(r * 0.5)}px`,
        fontStyle: 'bold',
        color: erguida ? '#fdf0e2' : '#b4450b',
      })
      .setOrigin(0.5)
    c.add(nivel)

    /*
     * O nome vai DENTRO do hexágono. Numa colmeia de hexágonos pontudos as linhas distam
     * `1,5·r`, então não sobra faixa livre abaixo de um hexágono: o da linha seguinte é
     * desenhado depois e pinta por cima do rótulo. Não era truncamento de texto, era oclusão.
     *
     * Dentro, a largura útil na altura do texto é confortável (a largura do hexágono é `√3·r`),
     * e nada de fora encosta.
     */
    const nome = this.add
      .text(0, r * 0.12, rotulo(spec.type), {
        fontFamily: 'Archivo, Inter, sans-serif',
        fontSize: `${Math.max(9, Math.round(r * 0.14))}px`,
        color: erguida ? '#fdf0e2' : '#372f27',
        align: 'center',
        wordWrap: { width: r * 1.15, useAdvancedWrap: true },
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
