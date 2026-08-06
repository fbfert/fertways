import Phaser from 'phaser'
import { carregarArte, carregarTexturas, chaveDeTextura } from './arte'
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
  // Paleta de estado (D-161, docs/design-tokens.md). As duas são escuras de propósito — a superfície
  // do jogo é clara, e cor de estado só vira legível se for mais escura que o fundo.
  info: 0x243c48,
  perigo: 0x78180c,
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

  /*
   * O centro (e por tabela o zoom, que ancora no centro da tela — `transformar()`) continua
   * calculado só com a colmeia ORIGINAL de 5 linhas (D-59), não com `linhas.length` inteiro.
   *
   * O Depósito Local (D-105) acrescenta uma 6ª linha sozinha no final. Se `meioY` entrasse a
   * contar essa linha, o centro desceria e a linha do TOPO (onde mora a Central de Transportes)
   * se deslocaria na tela a cada zoom — mesmo ela não tendo mudado de posição real nenhuma.
   * Fixando o centro nas 5 linhas de sempre, os 21 slots originais não mudam nem um pixel de
   * posição ou zoom; a linha extra só pendura por baixo deles, sem puxar ninguém.
   */
  const LINHAS_ORIGINAIS = 5
  const meioY = ((Math.min(linhas.length, LINHAS_ORIGINAIS) - 1) * passoY) / 2

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
  deposito_local: 'Depósito Local',
}

export function rotulo(tipo: string): string {
  return NOMES[tipo] ?? tipo
}

/**
 * O slot principal do colono: a colmeia de 22 posições (D-59, D-105).
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

  /**
   * Os objetos que pulsam — ver `update()`.
   *
   * É lista, e não um objeto só: uma colônia pode ter duas obras ao mesmo tempo (o teto da fila),
   * e as duas precisam pulsar **em fase**, porque estão contando o mesmo tempo.
   */
  private pulsantes: (Phaser.GameObjects.Container | Phaser.GameObjects.Text)[] = []

  constructor() {
    super('colonia')
  }

  create() {
    this.cameras.main.setBackgroundColor(CORES.sand)
    this.raiz = this.add.container(0, 0)
    this.desenharTerreno()
    this.desenhar()
    this.scale.on('resize', () => this.desenhar())

    /*
     * A arte chega depois (D-68), e a cena já desenhou hexágonos — que é o certo: o jogo aparece
     * na hora, e a arte entra quando pode. **O redesenho é obrigatório**: sem ele, a colônia ficaria
     * hexagonal para sempre e ninguém saberia por quê. Foi exatamente esse o defeito do D-63, em que
     * os ministérios da Capital saíram pálidos porque a cena nunca era reavisada.
     */
    void carregarArte()
      .then((arte) => (this.viva() ? carregarTexturas(this, arte) : undefined))
      .then(() => this.viva() && this.desenhar())
  }

  /**
   * A cena ainda existe?
   *
   * ⚠️ **A arte chega de forma assíncrona, e a cena pode ter morrido no meio.** O React em modo
   * estrito monta, desmonta e remonta os componentes de propósito — e o `ColonyCanvas` destrói o
   * jogo Phaser ao desmontar. A promessa da arte, disparada pela cena antiga, resolve depois, chama
   * `desenhar()` num objeto cujos sistemas o Phaser já derrubou, e o erro sai lá de dentro:
   * `Cannot read properties of null (reading 'forEach')`.
   *
   * Isso derrubou a colônia inteira e o e2e o pegou na primeira tentativa. A guarda é esta.
   */
  private viva(): boolean {
    return Boolean(this.raiz?.scene && this.sys?.isActive())
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

  /**
   * O selo de estado, no vértice superior direito do hexágono (A2.V3).
   *
   * ## Por que um selo, e por que ele tem glifo
   *
   * O deck proíbe anunciar estado **só por cor** (`docs/design-tokens.md`): a paleta do FERTWAYS é
   * quente por identidade, o vermelho fica a 14° de matiz do `rust` da marca, e num relance — ou para
   * quem tem deficiência de visão de cor — dois estados viram o mesmo. O glifo é o **segundo canal**,
   * e não é enfeite: é o que carrega o aviso quando a cor falha. Mesma regra do `Botao` de variante
   * `perigo`, que desenha um triângulo antes do rótulo.
   *
   * ⚠️ E `ember` **não é cor de texto** — sobre areia dá 1,62:1, ilegível. Como *fundo*, com letra
   * `ink`, dá 8,71:1. Por isso o selo de aviso pinta o fundo, ao contrário de tudo o mais na cena.
   *
   * O vértice superior direito fica a -30°; o selo é posto um pouco para dentro dele, para não
   * brigar com o nível (que mora no topo, no centro) nem com o nome (que mora embaixo).
   */
  private desenharSelo(r: number, glifo: string, fundo: number, tinta: string) {
    const c = this.add.container(r * 0.62, -r * 0.36)
    const raio = Math.max(7, r * 0.26)

    const g = this.add.graphics()
    g.fillStyle(fundo, 1)
    g.fillCircle(0, 0, raio)
    // Aro claro: o selo cai sobre a arte do prédio, que é escura e irregular.
    g.lineStyle(Math.max(1.5, raio * 0.16), CORES.sandLight, 1)
    g.strokeCircle(0, 0, raio)
    c.add(g)

    c.add(
      this.add
        .text(0, 0, glifo, {
          fontFamily: 'Archivo, Inter, sans-serif',
          fontSize: `${Math.round(raio * 1.25)}px`,
          fontStyle: 'bold',
          color: tinta,
        })
        .setOrigin(0.5),
    )

    return c
  }

  private hexPontos(cx: number, cy: number, r: number): Phaser.Math.Vector2[] {
    // Hexágono "pontudo em cima", como o do logo.
    return Array.from({ length: 6 }, (_, i) => {
      const a = Phaser.Math.DegToRad(60 * i - 90)
      return new Phaser.Math.Vector2(cx + r * Math.cos(a), cy + r * Math.sin(a))
    })
  }

  /**
   * A pulsação sutil do que está EM CURSO (A2.V3), quadro a quadro.
   *
   * ## ⚠️ Por que aqui, e não num tween
   *
   * `desenhar()` reconstrói a árvore inteira (`removeAll(true)`) a cada hover, resize e atualização
   * de specs. Um tween guardaria referência a um objeto que o próximo redesenho destrói — e tween
   * sobre alvo destruído é **exatamente** a classe de defeito que obrigou a guarda `viva()` a
   * existir. Foi por isso que o D-215 adiou a animação, por escrito.
   *
   * A saída é não ter estado nenhum: a escala sai de uma **função do relógio da cena**. O objeto
   * recriado no meio do ciclo pega a fase corrente e continua liso, porque a fase nunca morava nele.
   *
   * ## O que pulsa, e a regra que decide
   *
   * **Anima-se evento, não condição.** `erguendo` e `melhorando` são coisas que estão acontecendo e
   * têm hora para acabar — no máximo duas por colônia, pelo teto da fila. `travada` e `sem_insumo`
   * são **condições**: numa colônia com quatro fábricas paradas, quatro selos pulsando viram ruído,
   * e um mundo com 58 delas viraria um pisca-pisca. Condição se lê; evento se nota.
   */
  update(tempo: number) {
    if (this.pulsantes.length === 0) return

    // ~2,8 s por ciclo, 6% de amplitude: perto do limiar de percepção, que é o ponto de "sutil".
    const fase = (Math.sin(tempo / 450) + 1) / 2
    const escala = 0.94 + fase * 0.12

    this.pulsantes.forEach((o) => o.setScale(escala))
  }

  private desenhar() {
    this.raiz.removeAll(true)
    /*
     * Zerado JUNTO com a árvore, e não depois: os objetos que estavam aqui acabaram de ser
     * destruídos por `removeAll(true)`, e `update()` roda no laço de quadros. Deixar a lista velha
     * de pé por um instante seria pedir um `setScale` num objeto morto.
     */
    this.pulsantes = []
    if (!this.linhas.length) return

    const { width, height } = this.scale
    // A MESMA chamada que o `ColonyCanvas` faz para pôr os botões. É o que impede o desenho e o
    // alvo de clique de divergirem quando o colono aproxima.
    const { r, centros } = colmeia(this.linhas, width, height, this.vista)
    const porSlot = new Map(this.specs.map((s) => [s.slot, s]))

    /*
     * ⚠️ DUAS PASSADAS: primeiro todo o terreno e todos os prédios, e só depois toda a rotulagem.
     *
     * Numa passada só — um contêiner completo por slot, na ordem dos slots — o **sprite da linha de
     * baixo pintava por cima do rótulo da linha de cima**. E não por descuido: o sprite transborda o
     * hexágono em `1,72·r` de propósito (é o que o faz parecer um prédio pousado no terreno, e não um
     * selo colado), então ele invade o espaço do vizinho de cima por desenho.
     *
     * A conta: o topo do sprite fica a `0,86·r` acima do centro dele, as linhas distam `1,71·r`, e o
     * nome da linha de cima ocupa de `0,52·r` a `0,86·r` abaixo do centro dela. Os dois se encontram
     * exatamente na borda — e em nome de duas linhas ("Central de Transportes", "Estrutura de
     * Sobrevivência") o rótulo perdia a disputa, porque quem desenha depois vence.
     *
     * Separar as passadas resolve sem mexer em posição nenhuma: nada do que **informa** (nível, nome,
     * selo de estado) pode ser coberto pelo que **ilustra**.
     */
    const frente: Phaser.GameObjects.Container[] = []

    centros.forEach(([x, y], slot) => {
      const spec = porSlot.get(slot)
      const realce = this.realcado === slot

      if (!spec) {
        this.raiz.add(this.desenharVazio(x, y, r, realce))

        return
      }

      const { base, rotulos } = this.desenharConstrucao(spec, x, y, r, realce)
      this.raiz.add(base)
      frente.push(rotulos)
    })

    frente.forEach((c) => this.raiz.add(c))
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

  /**
   * Uma construção, em **duas camadas**: o que ilustra e o que informa.
   *
   * `base` é o terreno e o prédio; `rotulos` é o nível, o nome e o selo de estado. A cena desenha
   * todas as `base` primeiro e todos os `rotulos` depois — ver `desenhar()`, que explica por quê.
   * As duas ficam na mesma posição `(x, y)`, então nada aqui precisa saber da separação.
   */
  private desenharConstrucao(spec: Spec, x: number, y: number, r: number, realce: boolean) {
    const c = this.add.container(x, y)
    const rotulos = this.add.container(x, y)
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
    /*
     * A arte (D-68). **Só se a textura já estiver na cache** — pedir uma que não chegou faria o
     * Phaser desenhar o quadrado verde de "textura ausente", que é pior do que o hexágono.
     *
     * Com arte, o hexágono deixa de ser preenchido e vira só o contorno do slot: o prédio é o que se
     * vê, e um preenchimento cor de ferrugem por baixo o sujaria. Sem arte, tudo continua como
     * sempre foi — e é isso que faz o jogo nunca ficar com buraco enquanto a arte não chega.
     */
    const temArte = erguida && this.textures.exists(chaveDeTextura(spec.type))

    if (temArte) {
      g.fillStyle(CORES.sandLight, 0.35)
      g.fillPoints(pontos, true, true)
      g.lineStyle(realce ? 3 : 1.5, realce ? CORES.rustBright : CORES.rust, realce ? 1 : 0.35)
    } else if (erguida) {
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

    if (temArte) {
      /*
       * `1,72 · r`: o sprite transborda o hexágono, de propósito. Uma arte contida dentro dele
       * pareceria um selo colado; transbordando, parece um prédio **pousado no terreno** — que é o
       * que ela é. O `setOrigin(0.5, 0.58)` puxa a base para baixo do centro, porque o sprite é
       * isométrico e a sombra dele mora no rodapé da imagem.
       */
      const img = this.add.image(0, r * 0.14, chaveDeTextura(spec.type))
      img.setDisplaySize(r * 1.72, r * 1.72)
      img.setOrigin(0.5, 0.58)
      c.add(img)
    }

    /*
     * ⚠️ **Com arte, o nível vai para o TOPO — e isso saiu de OLHAR a tela, não de um teste.**
     *
     * A primeira versão o deixava no centro do hexágono, como sempre esteve. Sobre um hexágono
     * chapado isso era certo; sobre um prédio isométrico, o dígito caía **em cima da cúpula**, e o
     * nome caía **em cima da base**. Ficava sujo, e nenhum e2e reclamaria: os cliques funcionavam e
     * o texto estava lá. É a lição do D-63 outra vez — **fotografe e olhe**.
     */
    const nivelY = temArte ? -r * 0.66 : -r * 0.3
    const nivelTam = temArte ? Math.round(r * 0.3) : Math.round(r * 0.5)

    const nivel = this.add
      .text(0, nivelY, erguida ? String(spec.level) : '⏳', {
        fontFamily: 'Archivo, Inter, sans-serif',
        fontSize: `${nivelTam}px`,
        fontStyle: 'bold',
        color: temArte ? '#b4450b' : erguida ? '#fdf0e2' : '#b4450b',
        // Contorno claro: mesmo no topo, o dígito pode cair sobre uma antena ou uma chaminé.
        stroke: temArte ? '#fdf0e2' : undefined,
        strokeThickness: temArte ? 4 : 0,
      })
      .setOrigin(0.5)

    rotulos.add(nivel)

    // A ampulheta da obra pulsa: é o único sinal de que o relógio corre numa cena que, fora isso,
    // é estática. Ver `update()` — e a regra de anima-se evento, não condição.
    if (!erguida) {
      this.pulsantes.push(nivel)
    }

    /*
     * O nome vai DENTRO do hexágono. Numa colmeia de hexágonos pontudos as linhas distam `1,5·r`,
     * então não sobra faixa livre abaixo de um hexágono — a linha seguinte ocuparia o rótulo.
     *
     * ⚠️ E ele ganhou uma PLACA no lugar do contorno.
     *
     * O contorno claro em volta de cada glifo resolvia o contraste e criava outro problema: sobre um
     * telhado irregular, letra contornada vira renda — legível de perto, ruído a olho corrido, e a
     * colmeia inteira ficava suja de texto. Foi o que a foto do D-215 mostrou em "Central de
     * Transportes" e "Estrutura de Sobrevivência", os dois nomes de duas linhas.
     *
     * A placa é o mesmo remédio que o deck usa no estado `aviso`: quando a cor de texto não passa
     * sobre um fundo qualquer, **pinte o fundo**. Uma faixa `sandLight` atrás do nome dá contraste
     * constante, independente do que houver embaixo — e lê como uma placa de identificação no
     * terreno, que é o que ela é.
     */
    const nome = this.add
      .text(0, temArte ? r * 0.52 : r * 0.12, rotulo(spec.type), {
        fontFamily: 'Archivo, Inter, sans-serif',
        fontSize: `${Math.max(9, Math.round(r * 0.14))}px`,
        color: temArte ? '#1e1c17' : erguida ? '#fdf0e2' : '#372f27',
        align: 'center',
        wordWrap: { width: r * 1.15, useAdvancedWrap: true },
      })
      .setOrigin(0.5, 0)

    if (temArte) {
      const folgaX = Math.max(3, r * 0.06)
      const folgaY = Math.max(1, r * 0.02)
      const placa = this.add.graphics()
      placa.fillStyle(CORES.sandLight, 0.92)
      placa.fillRoundedRect(
        -nome.width / 2 - folgaX,
        nome.y - folgaY,
        nome.width + folgaX * 2,
        nome.height + folgaY * 2,
        Math.max(2, r * 0.04),
      )
      rotulos.add(placa)
    }

    rotulos.add(nome)

    /*
     * O selo de estado (A2.V3), por último: ele fica POR CIMA da arte e do texto de propósito.
     *
     * Os dois estados que ganham selo são os que o servidor sabia e a tela calava — ver
     * `App\Domain\Building\EstadoDaConstrucao`:
     *
     * - **melhorando**: subir do 3 para o 4 era idêntico a estar parado no 3. O `finishes_at` já
     *   vinha no payload desde sempre; a cena só olhava `level > 0`.
     * - **travada**: o §14 promete que *"o jogador perde oportunidade, nunca estoque"*. Perder
     *   oportunidade sem saber é perder as duas — e no dia da medição havia 5 recursos no teto em 2
     *   colônias, com o prédio rodando e rendendo zero.
     *
     * `erguendo` não ganha selo: o ⏳ no lugar do nível já ocupa a construção inteira, e um segundo
     * sinal para o mesmo fato só polui.
     *
     * ⚠️ **Sem tween aqui, e de propósito.** A A2.V3 pede "animações sutis", e uma pulsação no selo
     * de travada seria a candidata óbvia — mas `desenhar()` reconstrói a árvore inteira
     * (`removeAll(true)`) a cada hover, resize e atualização de specs, e tween sobre alvo destruído é
     * exatamente a classe de defeito que obrigou a guarda `viva()` a existir. A animação entra quando
     * tiver ciclo de vida próprio; o estado não precisa esperar por ela.
     */
    if (spec.estado === 'melhorando') {
      const selo = this.desenharSelo(r, '↑', CORES.info, '#fdf0e2')
      rotulos.add(selo)
      // Subir de nível está ACONTECENDO e tem hora para acabar — evento, então pulsa.
      this.pulsantes.push(selo)
    } else if (spec.estado === 'travada') {
      rotulos.add(this.desenharSelo(r, '!', CORES.ember, '#1e1c17'))
    } else if (spec.estado === 'sem_insumo') {
      /*
       * A fábrica de boca fechada (D-219). Glifo próprio, e não o mesmo `!` da travada: os dois são
       * "parada", mas pedem ações **opostas** — a travada quer que o jogador GASTE o que ela fez, e
       * esta quer que ele TRAGA o que falta. Um único símbolo para as duas mandaria metade dos
       * jogadores para o lado errado.
       *
       * Eram 58 das 66 fábricas do mundo nesta situação, todas desenhadas como se produzissem.
       */
      rotulos.add(this.desenharSelo(r, '×', CORES.perigo, '#fdf0e2'))
    }

    return { base: c, rotulos }
  }
}
