import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { api, ApiError } from '../api/client'
import type {
  ColoniaVizinha,
  Diretorio,
  RequisitosDeOcupacao,
  EstadoDaGuerra,
  TipoDeAtaque,
  Veiculo,
  ZonaNeutra,
} from '../api/client'
import { CelulaSobOCursor, Faixas, Grade, Planeta, Reguas } from './Grade'
import { InfoJogador } from './InfoJogador'
import { nomeRecurso } from './recursos'
import {
  JANELA_PADRAO,
  LADO_SVG,
  CALHA,
  calhaDe,
  celulaEm,
  celulasNaJanela,
  comFolga,
  passoDaGrade,
  pontoNoSvg,
  projecaoDoPlaneta,
  viewBoxComReguas,
} from './geometria'
import type { Caixa, Projecao } from './geometria'

/**
 * O mapa de Fertways: a Capital, a sua colônia, as vizinhas e as 120 zonas neutras (D-52).
 *
 * Toda a geometria — lado da grade, posição da Capital, raios das faixas — vem da API
 * (`GET /colonies`), nunca de constante daqui (D-51). Não há névoa de guerra: o diretório e as
 * zonas listam tudo (D-37).
 *
 * **A vista abre em 15×15, centrada na sua colônia** (D-64): num planeta 101×101 não se lia
 * coordenada nenhuma de tão longe, e o que o colono quer ver primeiro é a própria vizinhança. A
 * grade risca as linhas de X e de Y, e os números moram numa calha fora do mapa, que não
 * escorrega com o arraste. Daí em diante o mapa navega: **arrastar** para mover, **roda do mouse**
 * e botões +/− para o **zoom** — até o planeta inteiro, sem o qual não se chega às zonas dos
 * cantos —, e o ⌖ devolve o enquadramento de 15×15.
 *
 * Perto da borda do planeta a vista **passa da grade** em vez de se prender a ela: você fica
 * sempre no meio da tela, e o que sobra é o vazio de fora do mundo — que o `Planeta` deixa
 * visível. Presa à borda, uma colônia em (50,50) nunca se veria no centro.
 */

/** Limites do zoom. 1 mostra o planeta inteiro; ZOOM_MAX aproxima o bastante para clicar numa zona. */
const ZOOM_MIN = 1
const ZOOM_MAX = 12
const limitarEscala = (s: number) => Math.min(ZOOM_MAX, Math.max(ZOOM_MIN, s))

/** O centro da vista anda sobre o planeta — nunca para fora dele, ou o mapa sumiria da tela. */
const limitarCentro = (v: number) => Math.min(LADO_SVG, Math.max(0, v))

const MINERAL: Record<string, string> = {
  metal_bruto: 'Metal Bruto',
  agua: 'Água',
  oxigenio: 'Oxigênio',
  biomassa: 'Biomassa',
}

const DISTRITO: Record<string, string> = {
  nordeste: 'Nordeste',
  sudeste: 'Sudeste',
  sudoeste: 'Sudoeste',
  noroeste: 'Noroeste',
}

/** Zoom e centro do que se vê, em unidades do SVG. */
type Vista = { cx: number; cy: number; scale: number }

/** A distância e o ponto médio entre dois pontos de TELA — usada pela pinça de dois dedos (D-154). */
function distanciaEMeio(a: { x: number; y: number }, b: { x: number; y: number }) {
  return { dist: Math.hypot(a.x - b.x, a.y - b.y), meio: { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 } }
}

/**
 * As dimensões (em unidades do SVG) da janela visível, a partir da escala e da proporção real do
 * contêiner (D-156) — a mesma conta que o `viewBox` e a conversão de pixel-de-tela-em-unidade-do-
 * jogo (zoom e arraste) usam. Uma função só, pro mesmo motivo de sempre: duas contas divergem.
 *
 * A MENOR dimensão sempre vale `LADO_SVG / escala`, o de sempre (D-64: `scale = side/JANELA_PADRAO`
 * mostra exatamente 15 células nela) — só a MAIOR cresce, na proporção do contêiner, pra mostrar
 * mais colunas (ou linhas) numa tela larga (ou alta) em vez de esticar a grade numa elipse.
 *
 * **A proporção final tem que ser a do `viewBox` COM a calha, não a da janela sozinha** — senão o
 * `preserveAspectRatio` padrão do SVG faz o mesmo letterboxing que este card inteiro existe pra
 * evitar. `viewBoxComReguas` soma a MESMA calha `g` (absoluta, tirada da menor dimensão) aos dois
 * eixos — e somar um valor absoluto igual a dois números diferentes muda a razão entre eles. A
 * conta abaixo já nasce compensando isso: resolve pra proporção final (largura+2g)/(altura+2g)
 * bater exatamente com a proporção do contêiner, não só a proporção largura/altura sem calha.
 */
function dimensoesDaJanela(
  escala: number,
  tamanhoSvg: { largura: number; altura: number },
): { largura: number; altura: number } {
  const ladoBase = LADO_SVG / escala

  if (tamanhoSvg.largura <= 0 || tamanhoSvg.altura <= 0) {
    return { largura: ladoBase, altura: ladoBase } // antes da primeira medição — quadrado, provisório
  }

  const aspecto = tamanhoSvg.largura / tamanhoSvg.altura
  const k = 1 + 2 * CALHA

  return aspecto >= 1
    ? { largura: ladoBase * (aspecto * k - 2 * CALHA), altura: ladoBase }
    : { largura: ladoBase, altura: ladoBase * (k / aspecto - 2 * CALHA) }
}

type Selecao =
  | { tipo: 'colonia'; c: ColoniaVizinha }
  | { tipo: 'zona'; z: ZonaNeutra }
  | null

export function Mapa({
  aoAbrirCapital,
  aoAbrirChatPrivado,
}: {
  aoAbrirCapital?: () => void
  /** Sai do mapa e abre o Chat já na privada com este jogador (D-86, via a ficha do InfoJogador). */
  aoAbrirChatPrivado?: (id: number, nickname: string) => void
}) {
  const [dir, setDir] = useState<Diretorio | null>(null)
  const [zonas, setZonas] = useState<ZonaNeutra[]>([])
  const [frota, setFrota] = useState<Veiculo[]>([])
  const [erro, setErro] = useState<string | null>(null)
  const [selecao, setSelecao] = useState<Selecao>(null)
  const [infoAberta, setInfoAberta] = useState<number | null>(null)
  /**
   * O painel lateral, no MOBILE e **sem seleção** (A2.V4).
   *
   * Medido numa tela de 390px: o painel tem 288px de largura e cobre o mapa quase inteiro. Com uma
   * zona ou colônia selecionada isso é o certo — o painel É a resposta ao clique, e foi por isso que
   * ele nasceu visível em toda largura. Sem seleção ele é legenda e listas, e legenda que tapa o
   * mapa inverte a tela: o acessório vira o conteúdo.
   *
   * Só o caso sem seleção recolhe, e só no mobile. No desktop nada muda.
   */
  const [legendaAberta, setLegendaAberta] = useState(false)

  // Nasce nula: o enquadramento inicial depende de onde é a sua colônia, e isso só se sabe quando
  // o diretório chega.
  const [vista, setVista] = useState<Vista | null>(null)
  const [pegando, setPegando] = useState(false)
  // A célula sob o cursor. Só realce e leitura: célula vazia não é alvo de clique (D-64).
  const [cursor, setCursor] = useState<{ x: number; y: number } | null>(null)
  const svgRef = useRef<SVGSVGElement | null>(null)
  // Dispara de novo os efeitos que precisam do nó do SVG assim que ele existe de verdade — um
  // `useEffect` que dependesse de `dir`/`vista` chegaria tarde demais: `dir` vira não-nulo ANTES
  // de `vista` (que só nasce num efeito separado, um render depois), e o `<svg>` só existe quando
  // os DOIS já são verdadeiros. Um ref callback dispara exatamente quando o nó monta/desmonta,
  // sem depender de acertar a sequência de renders.
  const [svgPronto, setSvgPronto] = useState(false)
  const anexarSvgRef = useCallback((node: SVGSVGElement | null) => {
    svgRef.current = node
    setSvgPronto(node !== null)
  }, [])
  // O tamanho de tela do SVG (D-156) — dois lugares pro mesmo número: a `ref` pra leitura sem
  // closure velha dentro de `zoomAncoradoEm`/do arraste (que não podem depender de re-render pra
  // enxergar o valor novo), o `useState` pra fazer o `<Desenho>` recalcular o viewBox quando o
  // contêiner muda de tamanho (redimensionar a janela, girar o celular).
  const tamanhoSvgRef = useRef({ largura: 0, altura: 0 })
  const [tamanhoSvg, setTamanhoSvg] = useState({ largura: 0, altura: 0 })

  useEffect(() => {
    const svg = svgRef.current
    if (!svg) return

    const medir = () => {
      const r = svg.getBoundingClientRect()
      tamanhoSvgRef.current = { largura: r.width, altura: r.height }
      setTamanhoSvg({ largura: r.width, altura: r.height })
    }

    medir()
    const observador = new ResizeObserver(medir)
    observador.observe(svg)

    return () => observador.disconnect()
  }, [svgPronto])
  // Marca que o último gesto foi um arraste, para o pointerup não virar seleção de zona/colônia.
  const arrastou = useRef(false)

  const recarregar = useCallback(async () => {
    try {
      const [d, z, f] = await Promise.all([api.colonias(), api.zonas(), api.frota()])
      setDir(d)
      setZonas(z.zones)
      setFrota(f.vehicles)
    } catch (e) {
      setErro(e instanceof ApiError ? e.message : 'Falha ao carregar o mapa.')
    }
  }, [])

  useEffect(() => {
    void recarregar()
  }, [recarregar])

  const proj = useMemo(() => projecaoDoPlaneta(dir?.side ?? 1), [dir?.side])

  /** O enquadramento de abertura, e o do botão ⌖: a janela padrão, centrada na sua colônia. */
  const enquadrarEmMim = useCallback((d: Diretorio): Vista => {
    const p = projecaoDoPlaneta(d.side)

    return { cx: p.px(d.me.x), cy: p.py(d.me.y), scale: d.side / JANELA_PADRAO }
  }, [])

  // Assim que o diretório chega, enquadra. Só na primeira vez: um `recarregar` depois de ocupar
  // uma zona não pode arrancar o mapa de onde o jogador o deixou.
  useEffect(() => {
    if (dir && !vista) setVista(enquadrarEmMim(dir))
  }, [dir, vista, enquadrarEmMim])

  /**
   * Zoom ancorado num ponto de TELA — a roda do mouse ancora no cursor, a pinça ancora no ponto
   * médio dos dois dedos. É a mesma conta pros dois gestos, extraída daqui: o ponto sob a âncora
   * tem de ficar parado; resolvo por ele o novo canto do viewBox, e daí o centro. A conta inclui a
   * calha — o viewBox é maior que o mapa, e ignorá-la desviaria o zoom.
   */
  const zoomAncoradoEm = useCallback((ancoraX: number, ancoraY: number, fator: number) => {
    const svg = svgRef.current
    if (!svg) return
    const p = pontoNoSvg(svg, { clientX: ancoraX, clientY: ancoraY })
    if (!p) return
    const r = svg.getBoundingClientRect()
    const fx = (ancoraX - r.left) / r.width
    const fy = (ancoraY - r.top) / r.height

    setVista((v) => {
      if (!v) return v
      const escala = limitarEscala(v.scale * fator)
      if (escala === v.scale) return v

      const { largura, altura } = dimensoesDaJanela(escala, tamanhoSvgRef.current)
      const g = calhaDe(Math.min(largura, altura))
      const totalLargura = largura + 2 * g
      const totalAltura = altura + 2 * g
      const x0 = p.x - fx * totalLargura + g
      const y0 = p.y - fy * totalAltura + g

      return { cx: limitarCentro(x0 + largura / 2), cy: limitarCentro(y0 + altura / 2), scale: escala }
    })
  }, [])

  // Zoom pela roda do mouse, ancorado no cursor. Nativo e não-passivo para segurar o scroll da
  // página enquanto se aproxima o mapa; o React registra onWheel como passivo e não deixaria.
  useEffect(() => {
    const svg = svgRef.current
    if (!svg) return

    const aoRolar = (e: WheelEvent) => {
      e.preventDefault()
      zoomAncoradoEm(e.clientX, e.clientY, e.deltaY < 0 ? 1.2 : 1 / 1.2)
    }

    svg.addEventListener('wheel', aoRolar, { passive: false })

    return () => svg.removeEventListener('wheel', aoRolar)
    // `svgPronto` não é lido no corpo — é o gatilho: dispara de novo assim que `anexarSvgRef`
    // confirma que o nó existe (ver o comentário dele, acima). Sem isto o efeito prende pra
    // sempre no `if (!svg) return` do primeiro render e a roda do mouse nunca liga (regressão do
    // D-154, corrigida aqui).
  }, [svgPronto, zoomAncoradoEm])

  const zoom = (fator: number) =>
    setVista((v) => (v ? { ...v, scale: limitarEscala(v.scale * fator) } : v))

  // Um dedo (ou o mouse) arrasta; dois fazem pinça, ancorada no ponto médio deles (D-154). A
  // conta compara sempre com o frame ANTERIOR de `pointermove`, nunca com o início do gesto — e
  // por isso soltar um dos dois dedos no meio da pinça retoma o arraste sem salto nenhum: o
  // ponteiro que sobrou já está com a posição certa no mapa.
  const ponteiros = useRef<Map<number, { x: number; y: number }>>(new Map())

  // Arrastar/pinçar para mover e ampliar o mapa. Sem setPointerCapture (ele desviaria o `click` da
  // zona/colônia para o SVG): ouço o move/up na janela e desligo no fim. O limiar de 4 px separa
  // clique de arraste.
  const iniciarArrasto = (e: React.PointerEvent<SVGSVGElement>) => {
    if (e.button !== 0 || !vista) return

    const jaTinhaAlgumPonteiro = ponteiros.current.size > 0
    ponteiros.current.set(e.pointerId, { x: e.clientX, y: e.clientY })

    // Uma segunda ponta chegando no meio de um gesto já em curso: só registra a posição dela — os
    // listeners de window do primeiro dedo já ouvem TODO ponteiro, não só o que os criou.
    if (jaTinhaAlgumPonteiro) return

    const r = e.currentTarget.getBoundingClientRect()
    arrastou.current = false
    setPegando(true)

    const mover = (ev: PointerEvent) => {
      if (!ponteiros.current.has(ev.pointerId)) return

      const antes = [...ponteiros.current.values()]
      ponteiros.current.set(ev.pointerId, { x: ev.clientX, y: ev.clientY })
      const agora = [...ponteiros.current.values()]

      if (agora.length >= 2) {
        // Pinça: só os dois primeiros ponteiros contam — uma eventual terceira ponta é ignorada.
        const p = distanciaEMeio(antes[0], antes[1])
        const q = distanciaEMeio(agora[0], agora[1])
        if (p.dist > 0 && q.dist > 0) zoomAncoradoEm(q.meio.x, q.meio.y, q.dist / p.dist)
        arrastou.current = true // pinça nunca é clique

        return
      }

      const a = antes[0]
      const b = agora[0]
      if (!arrastou.current && Math.hypot(b.x - a.x, b.y - a.y) > 4) {
        arrastou.current = true
      }

      // A conversão de pixel de tela pra unidade do SVG depende da escala ATUAL — lida de dentro
      // do updater pra nunca ficar presa à escala de quando o gesto começou (a pinça pode ter
      // mudado a escala no meio do caminho). Largura e altura em separado (D-156): numa janela
      // retangular elas não são mais o mesmo número, e cada eixo converte contra o seu par.
      setVista((v) => {
        if (!v) return v
        const { largura, altura } = dimensoesDaJanela(v.scale, tamanhoSvgRef.current)
        const g = calhaDe(Math.min(largura, altura))
        const dx = ((b.x - a.x) / r.width) * (largura + 2 * g)
        const dy = ((b.y - a.y) / r.height) * (altura + 2 * g)

        return { ...v, cx: limitarCentro(v.cx - dx), cy: limitarCentro(v.cy - dy) }
      })
    }

    const soltar = (ev: PointerEvent) => {
      ponteiros.current.delete(ev.pointerId)
      if (ponteiros.current.size > 0) return // ainda tem dedo: o gesto continua

      window.removeEventListener('pointermove', mover)
      window.removeEventListener('pointerup', soltar)
      window.removeEventListener('pointercancel', soltar)
      setPegando(false)
      // Zera só depois que o `click` deste gesto já correu, para o arraste não selecionar nada.
      setTimeout(() => {
        arrastou.current = false
      }, 0)
    }

    window.addEventListener('pointermove', mover)
    window.addEventListener('pointerup', soltar)
    window.addEventListener('pointercancel', soltar)
  }

  // A célula sob o cursor, para o realce e para a leitura da coordenada. Só reage quando a célula
  // muda: um setState por pixel de mouse redesenharia o mapa inteiro à toa.
  const aoMoverCursor = (e: React.PointerEvent<SVGSVGElement>) => {
    if (!dir) return
    const p = pontoNoSvg(e.currentTarget, e)
    if (!p) return
    const c = celulaEm(p, dir.side)
    const meia = Math.floor(dir.side / 2)
    const dentro = Math.abs(c.x) <= meia && Math.abs(c.y) <= meia

    setCursor((antes) => {
      const agora = dentro ? c : null
      if (antes?.x === agora?.x && antes?.y === agora?.y) return antes

      return agora
    })
  }

  // Seleciona só se o gesto foi um clique de verdade, não a ponta de um arraste.
  const selecionar = (fn: () => void) => {
    if (!arrastou.current) fn()
  }

  const focar = (x: number, y: number) => {
    if (!dir) return
    setVista((v) => ({
      cx: proj.px(x),
      cy: proj.py(y),
      scale: Math.max(v?.scale ?? 1, dir.side / JANELA_PADRAO),
    }))
  }

  return (
    <>
      {/* Tela cheia, como a colônia abre (`App.tsx`: `<div className="relative h-screen w-screen
          overflow-hidden">` em volta do `ColonyCanvas`) — mesmas classes, de propósito, e não
          `fixed inset-0`: `position:fixed` cria um contexto de empilhamento PRÓPRIO mesmo sem
          z-index nenhum, e prenderia os botões de zoom (`z-[26]`, abaixo) lá dentro — por mais
          alto que fosse o z deles, nunca escapariam pra vencer o header global (`z-[25]`), porque
          a COMPARAÇÃO que decide a pintura final é entre este contêiner (sem z, perde do header)
          e o header, não entre o botão e o header diretamente. Foi exatamente isto que aconteceu
          (os botões +/− sumiam atrás do header) até esta troca pra `relative`. */}
      {/*
        ⚠️ No MOBILE o mapa termina acima da barra de baixo, e não embaixo dela (A2.V4).

        Medido: a barra fixa começa em **780** numa janela de 844 — 64px opacos. O mapa é
        `h-screen` de propósito (D-154/D-156), mas o que fica debaixo de uma barra opaca não é mapa
        visível, é mapa desperdiçado. E era ali que a régua do X ia parar depois de fugir do
        cabeçalho: sair de uma barra para cair na outra não é conserto.

        `dvh` e não `vh`: no mobile a barra de endereço entra e sai, e `100vh` mede a janela maior,
        que é justamente a que não está na tela. O `env(safe-area-inset-bottom)` acompanha o recorte
        do aparelho — no navegador de teste ele é zero, e a conta dá os 780 medidos.
      */}
      <div className="bg-sand relative h-[calc(100dvh-4rem-env(safe-area-inset-bottom))] w-screen overflow-hidden md:h-screen">
        {erro && (
          <p
            data-erro-mapa
            className="text-rust bg-sand-light border-rust/25 absolute left-1/2 top-24 z-30 -translate-x-1/2 border px-4 py-2 text-sm"
          >
            {erro}
          </p>
        )}

        {dir && vista && (
          <div className="absolute inset-0">
            <div className="absolute inset-0">
              <Desenho
                svgRef={anexarSvgRef}
                dir={dir}
                proj={proj}
                vista={vista}
                tamanhoSvg={tamanhoSvg}
                zonas={zonas}
                selecao={selecao}
                cursor={cursor}
                pegando={pegando}
                aoArrastar={iniciarArrasto}
                aoMoverCursor={aoMoverCursor}
                aoSairCursor={() => setCursor(null)}
                aoSelecionar={selecionar}
                aoEscolher={setSelecao}
                aoAbrirCapital={aoAbrirCapital}
              />
            </div>

            {/* Ferramentas de zoom e foco — mesma posição/z-index que `ControlesDeZoom` já usa
                na colônia: acima do header global (z-[25]). */}
            <div className="absolute top-3 right-3 z-[26] flex flex-col gap-1">
              <BotaoMapa aoClicar={() => zoom(1.5)} rotulo="Aproximar" data-zoom-in>
                +
              </BotaoMapa>
              <BotaoMapa aoClicar={() => zoom(1 / 1.5)} rotulo="Afastar" data-zoom-out>
                −
              </BotaoMapa>
              <BotaoMapa
                aoClicar={() => setVista(enquadrarEmMim(dir))}
                rotulo="Centralizar na sua colônia"
                data-centrar
              >
                <Alvo />
              </BotaoMapa>
            </div>

            {/* A coordenada da célula sob o cursor. */}
            {cursor && (
              <div
                data-coordenada-cursor
                className="bg-sand-light/90 border-rust/25 text-ink absolute bottom-2 left-2 z-10 border px-2 py-0.5 text-xs tabular-nums"
              >
                ({cursor.x}, {cursor.y})
              </div>
            )}

            {/*
              No mobile e SEM seleção, o painel vira um botão (A2.V4). Com seleção, nada muda: ele
              continua abrindo em qualquer largura, porque aí é a resposta direta ao clique.
            */}
            {!selecao && (
              <button
                type="button"
                onClick={() => setLegendaAberta((v) => !v)}
                data-legenda-mapa
                aria-expanded={legendaAberta}
                /*
                 * Embaixo à esquerda, e as três alternativas foram descartadas por medida: em cima
                 * à esquerda ele cai sobre a marca; em cima à direita, sobre os controles de zoom;
                 * e na faixa de `top-20` mora o aviso de evento de mundo. Aqui não disputa com nada,
                 * e ainda fica onde o polegar alcança. Acima da régua do X, que vive nos últimos 30px.
                 */
                className="painel bg-sand-light text-rust absolute bottom-14 left-9 z-[26] px-3 py-1.5 text-xs font-bold md:hidden"
              >
                {legendaAberta ? 'Fechar legenda' : 'Legenda e zonas'}
              </button>
            )}

            {/* Os cards flutuantes — em qualquer largura de tela (não só desktop): aqui o
                painel de seleção não é acessório como a fila de obras da colônia, é a resposta
                direta ao clique numa zona/colônia. */}
            <div
              className={`absolute top-24 right-5 z-20 max-h-[calc(100vh-7rem)] w-72 space-y-4 overflow-y-auto ${
                selecao || legendaAberta ? '' : 'hidden md:block'
              }`}
            >
              <div className="painel bg-sand-light p-4">
                <p className="text-ink-soft text-sm">
                  Grade {dir.side}×{dir.side}. Capital em ({dir.capital.x}, {dir.capital.y}). Você
                  em ({dir.me.x}, {dir.me.y}), a {distancia(dir.me, dir.capital)} slots dela.
                </p>

                {!selecao && <Legenda />}

                {selecao?.tipo === 'colonia' && (
                  <PainelColonia
                    c={selecao.c}
                    aoVerInfo={() => setInfoAberta(selecao.c.user_id)}
                    aoConversar={aoAbrirChatPrivado}
                  />
                )}

                {selecao?.tipo === 'zona' && (
                  <PainelZona
                    // A versão fresca da zona (após ocupar/retirar, a lista já veio recarregada).
                    z={zonas.find((zz) => zz.id === selecao.z.id) ?? selecao.z}
                    me={dir.me}
                    frota={frota}
                    aoAgir={recarregar}
                    aoFocar={() => focar(selecao.z.x, selecao.z.y)}
                    aoVerInfo={setInfoAberta}
                  />
                )}
              </div>

              <div className="painel bg-sand-light p-4">
                <h3 className="text-ink eyebrow">Zonas ({zonas.filter((z) => z.mine).length} suas)</h3>
                <ul className="mt-2 max-h-32 space-y-1 overflow-y-auto">
                  {zonas
                    .filter((z) => z.mine)
                    .map((z) => (
                      <li key={z.id}>
                        <button
                          onClick={() => {
                            setSelecao({ tipo: 'zona', z })
                            focar(z.x, z.y)
                          }}
                          className="text-ink-soft hover:bg-sand flex w-full items-baseline justify-between px-2 py-1 text-left text-sm"
                        >
                          <span className="truncate">
                            {DISTRITO[z.district]} ({z.x}, {z.y})
                          </span>
                          <span className="ml-2 shrink-0 tabular-nums">{z.deposit_amount}</span>
                        </button>
                      </li>
                    ))}
                  {zonas.filter((z) => z.mine).length === 0 && (
                    <li className="text-ink-soft/70 px-2 text-xs">Nenhuma zona ocupada ainda.</li>
                  )}
                </ul>

                <h3 className="text-ink eyebrow mt-5">
                  {dir.colonies.length === 0
                    ? 'Nenhuma vizinha'
                    : `${dir.colonies.length} ${dir.colonies.length === 1 ? 'vizinha' : 'vizinhas'}`}
                </h3>
                <ul className="mt-2 max-h-32 space-y-1 overflow-y-auto">
                  {dir.colonies.map((c) => (
                    <li key={c.id}>
                      <button
                        onClick={() => {
                          setSelecao({ tipo: 'colonia', c })
                          focar(c.x, c.y)
                        }}
                        className={`flex w-full items-baseline justify-between px-2 py-1 text-left text-sm ${
                          selecao?.tipo === 'colonia' && selecao.c.id === c.id
                            ? 'bg-rust text-sand-light'
                            : 'text-ink-soft hover:bg-sand'
                        }`}
                      >
                        <span className="truncate">{c.name}</span>
                        <span className="ml-2 shrink-0 tabular-nums">{c.distance}</span>
                      </button>
                    </li>
                  ))}
                </ul>
              </div>
            </div>
          </div>
        )}
      </div>

      {infoAberta !== null && (
        <InfoJogador
          userId={infoAberta}
          aoFechar={() => setInfoAberta(null)}
          aoConversar={aoAbrirChatPrivado}
        />
      )}
    </>
  )
}

/**
 * O desenho em si. Separado do painel porque é aqui que mora a conta do enquadramento: a janela
 * visível (a `caixa`), a faixa de células que ela alcança, e o passo com que a grade se rareia
 * conforme o jogador se afasta.
 */
function Desenho({
  svgRef,
  dir,
  proj,
  vista,
  tamanhoSvg,
  zonas,
  selecao,
  cursor,
  pegando,
  aoArrastar,
  aoMoverCursor,
  aoSairCursor,
  aoSelecionar,
  aoEscolher,
  aoAbrirCapital,
}: {
  svgRef: (node: SVGSVGElement | null) => void
  dir: Diretorio
  proj: Projecao
  vista: Vista
  /** O tamanho de tela do SVG (D-156) — antes da primeira medição, {0,0} (janela quadrada). */
  tamanhoSvg: { largura: number; altura: number }
  zonas: ZonaNeutra[]
  selecao: Selecao
  cursor: { x: number; y: number } | null
  pegando: boolean
  aoArrastar: (e: React.PointerEvent<SVGSVGElement>) => void
  aoMoverCursor: (e: React.PointerEvent<SVGSVGElement>) => void
  aoSairCursor: () => void
  aoSelecionar: (fn: () => void) => void
  aoEscolher: (s: Selecao) => void
  aoAbrirCapital?: () => void
}) {
  const { largura, altura } = dimensoesDaJanela(vista.scale, tamanhoSvg)
  const caixa: Caixa = { x0: vista.cx - largura / 2, y0: vista.cy - altura / 2, largura, altura }

  // Duas faixas, e não uma: os números são das células inteiras na janela; as linhas ganham uma
  // célula de folga em cada ponta, para a da beirada não ficar sem risco. O recorte apara o resto.
  const numeradas = celulasNaJanela(caixa, dir.side)
  const riscadas = comFolga(numeradas, dir.side)

  const passo = passoDaGrade(dir.side / vista.scale)

  // Os marcadores têm tamanho de tela ~constante (o `k`), mas nunca menor que um bom pedaço da
  // célula: aproximado em 15×15, um ponto de 7 px dentro de uma célula de 37 px se perderia.
  const k = 1 / vista.scale
  const tam = (px: number, daCelula: number) => Math.max(px * k, proj.passo * daCelula)

  const rZona = tam(6, 0.35)
  const rCapital = tam(9, 0.4)
  const recorte = 'recorte-do-mapa'

  return (
    <svg
      ref={svgRef}
      data-mapa
      viewBox={viewBoxComReguas(caixa)}
      onPointerDown={aoArrastar}
      onPointerMove={aoMoverCursor}
      onPointerLeave={aoSairCursor}
      // `h-full`, não `h-auto`: em tela cheia (D-154) o contêiner raramente é quadrado. Ao
      // contrário da primeira versão desta tela cheia, o `viewBox` agora SEGUE a proporção real do
      // contêiner (`dimensoesDaJanela`, D-156) — sem isto a grade ficava presa num quadrado
      // centrado, com barras vazias nas laterais de telas largas (o mapa "não preenchia a tela").
      className={`block h-full w-full touch-none select-none ${
        pegando ? 'cursor-grabbing' : 'cursor-grab'
      }`}
    >
      <defs>
        {/* Recorta o desenho na janela: sem isto, o planeta invadiria a calha das réguas. */}
        <clipPath id={recorte}>
          <rect x={caixa.x0} y={caixa.y0} width={caixa.largura} height={caixa.altura} />
        </clipPath>
      </defs>

      <Reguas proj={proj} caixa={caixa} faixa={numeradas} passo={passo} />

      <g clipPath={`url(#${recorte})`}>
        <Planeta k={k} />
        <Faixas proj={proj} raioFounder={dir.raio_founder} raioAnel={dir.raio_anel} />
        <Grade proj={proj} faixa={riscadas} passo={passo} k={k} />

        {cursor && <CelulaSobOCursor proj={proj} celula={cursor} k={k} />}

        {/* As zonas neutras: quadradinhos nos cantos, coloridos por dono. */}
        {zonas.map((z) => (
          <rect
            key={`z${z.id}`}
            data-zona={z.id}
            x={proj.px(z.x) - rZona}
            y={proj.py(z.y) - rZona}
            width={rZona * 2}
            height={rZona * 2}
            fill={corDaZona(z, selecao)}
            stroke={selecao?.tipo === 'zona' && selecao.z.id === z.id ? 'var(--color-ink)' : 'none'}
            strokeWidth={2 * k}
            className="cursor-pointer"
            onClick={() => aoSelecionar(() => aoEscolher({ tipo: 'zona', z }))}
          >
            <title>
              {z.name ?? `Zona ${DISTRITO[z.district]}`} ({z.x}, {z.y}) — {MINERAL[z.mineral] ?? z.mineral}
              {z.owner ? ` · ${z.owner.name}` : ' · livre'}
            </title>
          </rect>
        ))}

        {/* A Capital. Losango, para não se confundir com colônia nenhuma. */}
        <rect
          x={proj.px(dir.capital.x) - rCapital}
          y={proj.py(dir.capital.y) - rCapital}
          width={rCapital * 2}
          height={rCapital * 2}
          transform={`rotate(45 ${proj.px(dir.capital.x)} ${proj.py(dir.capital.y)})`}
          fill="var(--color-rust)"
          className={aoAbrirCapital ? 'cursor-pointer' : undefined}
          onClick={aoAbrirCapital ? () => aoSelecionar(aoAbrirCapital) : undefined}
          // Desde o D-59 o losango é o ÚNICO caminho para a Capital — e, por dentro dela,
          // para o Ministério e o Mercado Central. Um alvo de clique que só existe como
          // desenho não tem nome para quem usa leitor de tela nem para o e2e; este tem.
          role={aoAbrirCapital ? 'button' : undefined}
          aria-label={aoAbrirCapital ? 'Capital' : undefined}
        >
          <title>Capital — Governo de Fertways{aoAbrirCapital ? ' (abrir)' : ''}</title>
        </rect>

        {dir.colonies.map((c) => (
          <circle
            key={c.id}
            cx={proj.px(c.x)}
            cy={proj.py(c.y)}
            r={tam(selecao?.tipo === 'colonia' && selecao.c.id === c.id ? 11 : 7, 0.3)}
            fill={
              selecao?.tipo === 'colonia' && selecao.c.id === c.id
                ? 'var(--color-rust-bright)'
                : 'var(--color-ink-soft)'
            }
            className="cursor-pointer"
            onClick={() => aoSelecionar(() => aoEscolher({ tipo: 'colonia', c }))}
          >
            <title>
              {c.name} ({c.nickname}) — {c.distance} slots
            </title>
          </circle>
        ))}

        {/* A sua colônia por último: fica por cima de qualquer vizinha sobreposta. */}
        <circle
          cx={proj.px(dir.me.x)}
          cy={proj.py(dir.me.y)}
          r={tam(9, 0.34)}
          fill="var(--color-ember)"
          stroke="var(--color-ink)"
          strokeWidth={2 * k}
        >
          <title>{dir.me.name} — você</title>
        </circle>

        {/* A reta até o alvo escolhido: o frete cobra por esta distância. */}
        {selecao && (
          <line
            x1={proj.px(dir.me.x)}
            y1={proj.py(dir.me.y)}
            x2={proj.px(alvoX(selecao))}
            y2={proj.py(alvoY(selecao))}
            stroke="var(--color-rust)"
            strokeWidth={2 * k}
            strokeDasharray={`${8 * k} ${6 * k}`}
          />
        )}
      </g>
    </svg>
  )
}

function alvoX(s: Exclude<Selecao, null>): number {
  return s.tipo === 'colonia' ? s.c.x : s.z.x
}
function alvoY(s: Exclude<Selecao, null>): number {
  return s.tipo === 'colonia' ? s.c.y : s.z.y
}

/**
 * A conta de ocupar, por extenso, a partir do que o servidor mandou (D-224).
 *
 * Os recursos primeiro e o Fert$ por último, porque é a ordem em que doem: o material é o que o
 * jogador precisa **produzir**, e o Fert$ ele costuma ter.
 */
function listarCusto(r: RequisitosDeOcupacao): string {
  const partes = Object.entries(r.recursos).map(
    ([recurso, qtd]) => `${qtd.toLocaleString('pt-BR')} ${nomeRecurso(recurso)}`,
  )
  partes.push(`${r.fert.toLocaleString('pt-BR')} Fert$`)

  return partes.join(' + ')
}

/** O nome legível do que falta — recurso vira nome de recurso; o resto já vem em português. */
function rotuloDoQueFalta(f: RequisitosDeOcupacao['falta'][number]): string {
  if (f.tipo === 'recurso') return nomeRecurso(f.o_que)
  if (f.tipo === 'marco') return `${f.o_que}, em XP`
  if (f.tipo === 'teto') return 'você já ocupa o máximo de zonas'

  return f.o_que
}

function corDaZona(z: ZonaNeutra, selecao: Selecao): string {
  if (selecao?.tipo === 'zona' && selecao.z.id === z.id) return 'var(--color-rust-bright)'
  if (z.mine) return 'var(--color-ember)'
  if (z.owner) return 'var(--color-rust)'

  return 'var(--color-ink-soft)'
}

function PainelColonia({
  c,
  aoVerInfo,
  aoConversar,
}: {
  c: ColoniaVizinha
  aoVerInfo: () => void
  /** Abre o chat privado direto daqui — sem passar pela ficha do jogador primeiro. */
  aoConversar?: (id: number, nickname: string) => void
}) {
  return (
    <div className="border-rust/25 bg-sand mt-4 border p-3">
      <button
        onClick={aoVerInfo}
        data-abrir-info={c.user_id}
        className="text-rust eyebrow hover:text-rust-bright"
      >
        {c.nickname}
      </button>
      <div className="text-ink font-black">{c.name}</div>
      <dl className="text-ink-soft mt-2 space-y-1 text-sm">
        <Linha termo="Posição" valor={`(${c.x}, ${c.y})`} />
        <Linha termo="Distância" valor={`${c.distance} slots`} />
        <Linha termo="Porte" valor={`${c.building_levels_sum} níveis`} />
      </dl>
      <p className="text-ink-soft/70 mt-2 text-xs">
        Porte é a soma dos níveis das construções — não é o Marco do GDD.
      </p>
      <button onClick={aoVerInfo} data-ver-info={c.user_id} className="text-rust hover:text-rust-bright mt-2 text-xs">
        Ver zonas neutras ocupadas
      </button>
      {aoConversar && (
        <button
          onClick={() => aoConversar(c.user_id, c.nickname)}
          // "-direto": não é a mesma marca do botão de `InfoJogador.tsx` (`data-conversar`) — os
          // dois convivem na tela ao mesmo tempo (o painel da colônia fica atrás da ficha aberta),
          // e um seletor `[data-conversar]` ambíguo pegava o elemento errado.
          data-conversar-direto={c.user_id}
          className="botao mt-2 w-full"
        >
          Conversar
        </button>
      )}
    </div>
  )
}

/** "vista há 3 h" — a idade é o que torna a foto honesta: o número pode estar velho, e a tela diz quanto. */
function idadeDaFoto(iso: string): string {
  const minutos = Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 60_000))
  if (minutos < 1) return 'vista agora'
  if (minutos < 60) return `vista há ${minutos} min`
  const horas = Math.floor(minutos / 60)
  if (horas < 48) return `vista há ${horas} h`
  return `vista há ${Math.floor(horas / 24)} dias`
}

function PainelZona({
  z,
  me,
  frota,
  aoAgir,
  aoFocar,
  aoVerInfo,
}: {
  z: ZonaNeutra
  me: { x: number; y: number }
  frota: Veiculo[]
  aoAgir: () => Promise<void>
  aoFocar: () => void
  aoVerInfo?: (userId: number) => void
}) {
  const [erro, setErro] = useState<string | null>(null)
  const [enviando, setEnviando] = useState(false)
  // O Drone é veículo, mas não é carga (capacity 0): sem este filtro, o `ociosos[0]` da retirada
  // poderia ser um drone, e o botão de despachar nasceria travado em 0 sem dizer por quê (D-74).
  const ociosos = frota.filter((v) => v.status === 'ocioso' && v.type !== 'drone_de_exploracao')
  const drones = frota.filter((v) => v.status === 'ocioso' && v.type === 'drone_de_exploracao')
  const [qtd, setQtd] = useState(0)

  const dist = distancia(me, z)
  const produtiva = z.productive_at ? new Date(z.productive_at).getTime() <= Date.now() : false

  const deposito = z.deposit_amount ?? 0

  /*
   * Os requisitos de ocupação (A2.V4, D-224). Buscados **só quando a zona é livre** — para zona com
   * dono não existe botão de ocupar, e pedir isso a cada seleção seria uma chamada por nada.
   */
  const [requisitos, setRequisitos] = useState<RequisitosDeOcupacao | null>(null)
  useEffect(() => {
    if (z.owner) {
      setRequisitos(null)

      return
    }

    let vivo = true
    api
      .requisitosDeOcupacao()
      .then((r) => vivo && setRequisitos(r))
      // Sem requisitos a tela cai no comportamento antigo: botão habilitado, servidor decide. Pior
      // do que saber, melhor do que travar o único caminho para ocupar por causa de uma chamada.
      .catch(() => vivo && setRequisitos(null))

    return () => {
      vivo = false
    }
  }, [z.id, z.owner])

  useEffect(() => {
    const cap = ociosos[0]?.capacity ?? 0
    setQtd(Math.min(deposito, cap))
    setErro(null)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [z.id, z.deposit_amount])

  async function ocupar() {
    setErro(null)
    setEnviando(true)
    try {
      await api.ocuparZona(z.id)
      await aoAgir()
    } catch (e) {
      setErro(e instanceof ApiError ? e.message : 'Falha ao ocupar.')
    } finally {
      setEnviando(false)
    }
  }

  async function upar() {
    setErro(null)
    setEnviando(true)
    try {
      await api.upgradeZona(z.id)
      await aoAgir()
    } catch (e) {
      setErro(e instanceof ApiError ? e.message : 'Falha ao investir no upgrade.')
    } finally {
      setEnviando(false)
    }
  }

  async function reconhecer(modo: 'foto' | 'vigilancia') {
    if (!drones[0]) return
    setErro(null)
    setEnviando(true)
    try {
      // O drone de MAIOR nível vai primeiro: raio e bateria maiores. A lista vem ordenada da frota.
      const melhor = [...drones].sort((a, b) => b.level - a.level)[0]
      await api.enviarDrone(melhor.id, z.id, modo)
      await aoAgir()
    } catch (e) {
      setErro(e instanceof ApiError ? e.message : 'Falha ao despachar o Drone.')
    } finally {
      setEnviando(false)
    }
  }

  async function retirar() {
    if (!ociosos[0] || qtd <= 0) return
    setErro(null)
    setEnviando(true)
    try {
      await api.retirarDeZona(z.id, ociosos[0].id, { [z.mineral]: qtd })
      await aoAgir()
    } catch (e) {
      setErro(e instanceof ApiError ? e.message : 'Falha ao retirar.')
    } finally {
      setEnviando(false)
    }
  }

  return (
    <div className="border-rust/25 bg-sand mt-4 border p-3" data-painel-zona>
      <div className="text-rust eyebrow">{z.name ?? `Zona ${DISTRITO[z.district]}`}</div>
      <div className="text-ink font-black">{MINERAL[z.mineral] ?? z.mineral}</div>
      <dl className="text-ink-soft mt-2 space-y-1 text-sm">
        <Linha termo="Posição" valor={`(${z.x}, ${z.y})`} />
        <Linha termo="Distância" valor={`${dist} slots`} />
        <div className="flex justify-between">
          <dt>Dono</dt>
          <dd className="text-ink tabular-nums">
            {!z.owner ? (
              'livre'
            ) : z.mine ? (
              'você'
            ) : aoVerInfo ? (
              <button
                onClick={() => aoVerInfo(z.owner!.user_id)}
                data-abrir-info={z.owner.user_id}
                className="text-rust hover:text-rust-bright"
              >
                {z.owner.name}
              </button>
            ) : (
              z.owner.name
            )}
          </dd>
        </div>
        {z.mine && <Linha termo="Nível" valor={`${z.level}${z.upgrade?.target ? ` → ${z.upgrade.target}` : ''}`} />}
        {z.mine && <Linha termo="Depósito" valor={`${deposito} / ${z.deposit_cap}`} />}
        {z.mine && <Linha termo="Extração" valor={`${z.extraction_per_hour}/h`} />}
      </dl>

      <button onClick={aoFocar} className="text-rust hover:text-rust-bright mt-2 text-xs">
        Focar no mapa
      </button>

      {erro && <p className="text-rust mt-2 text-sm">{erro}</p>}

      {/*
        Livre: ocupar. Pesada (D-52): Posto + 20 Robôs + tempo.

        ⚠️ O custo vem do SERVIDOR (D-224). A frase daqui era escrita à mão e mentia: dizia "800
        Metal Bruto ... e 20 Robôs Mineradores" para uma cobrança de **1.020 Metal Bruto, 1.200 Ligas
        e 400 Componentes** — os 220 de metal dos robôs escondidos atrás da palavra "robôs", e as
        duas maiores parcelas não citadas. Custo escrito à mão nasce certo e envelhece sozinho.
      */}
      {!z.owner && (
        <div className="mt-3">
          <p className="text-ink-soft/80 text-xs">
            Ocupar custa {requisitos ? listarCusto(requisitos) : '…'} e leva 20 h para produzir.
          </p>

          {/*
            O que falta, TUDO de uma vez. O servidor confere em ordem e para no primeiro erro — o
            certo para uma transação, péssimo para uma tela: o jogador conseguiria Fert$, clicaria de
            novo, e só então descobriria que faltam colonos.
          */}
          {requisitos && !requisitos.pode && (
            <div className="border-perigo text-perigo bg-sand mt-2 border-l-4 px-2 py-1.5 text-xs" data-falta-ocupar>
              <strong>Ainda não dá para ocupar:</strong>
              <ul className="mt-1 space-y-0.5">
                {requisitos.falta.map((f) => (
                  <li key={f.tipo + f.o_que}>
                    {rotuloDoQueFalta(f)}{' '}
                    <span className="text-ink-soft tabular-nums">
                      ({f.tem.toLocaleString('pt-BR')} de {f.precisa.toLocaleString('pt-BR')})
                    </span>
                  </li>
                ))}
              </ul>
            </div>
          )}

          <button
            onClick={() => void ocupar()}
            /*
             * Desabilitado quando o servidor já disse que vai recusar. Enquanto os requisitos não
             * chegam (`null`), o botão fica ativo: o servidor continua sendo quem decide, e travar
             * o único caminho para ocupar por causa de uma chamada pendente seria pior.
             */
            disabled={enviando || requisitos?.pode === false}
            data-ocupar
            className="bg-rust text-sand-light hover:bg-rust-bright mt-2 w-full py-2 text-sm font-bold disabled:opacity-40"
          >
            {enviando ? 'Ocupando…' : 'Ocupar'}
          </button>
        </div>
      )}

      {/* De outro colono: o interior é névoa (D-74) — o Drone é o único olho que a atravessa. */}
      {z.owner && !z.mine && (
        <div className="border-rust/20 mt-3 border-t pt-2" data-intel={z.intel}>
          <dl className="text-ink-soft space-y-1 text-sm">
            <Linha termo="Guarnição" valor={z.garrison === null ? '?' : `${z.garrison} robôs`} />
            <Linha termo="Depósito" valor={z.deposit_amount === null ? '?' : `${z.deposit_amount}`} />
          </dl>
          <p className="text-ink-soft/70 mt-1 text-xs">
            {z.intel === 'ao_vivo' && 'Um Drone seu sobrevoa a região: transmissão ao vivo.'}
            {z.intel === 'federacao' && 'Zona de um aliado da sua federação: a Central de Comunicação transmite ao vivo.'}
            {z.intel === 'foto' && z.intel_em &&
              `Foto de Drone — ${idadeDaFoto(z.intel_em)}. O de lá de agora pode ser outro.`}
            {z.intel === 'nenhuma' &&
              'Interior desconhecido. Só um Drone de Exploração revela a guarnição e o depósito (§16.1).'}
          </p>
          {drones.length > 0 && (
            <div className="mt-2 flex gap-2">
              <button
                onClick={() => void reconhecer('foto')}
                disabled={enviando}
                data-drone-foto
                className="border-rust/40 text-rust hover:border-rust flex-1 border py-1.5 text-xs font-bold disabled:opacity-40"
              >
                Drone: foto
              </button>
              <button
                onClick={() => void reconhecer('vigilancia')}
                disabled={enviando}
                data-drone-vigiar
                className="border-rust/40 text-rust hover:border-rust flex-1 border py-1.5 text-xs font-bold disabled:opacity-40"
              >
                Drone: vigiar
              </button>
            </div>
          )}
          {drones.length === 0 && z.intel === 'nenhuma' && (
            <p className="text-ink-soft/60 mt-1 text-xs">Nenhum Drone ocioso no hangar — fabrique um na Oficina, pelo Quartel.</p>
          )}
        </div>
      )}

      {/* De outro colono: atacar (§27, §28.10; D-66). É daqui que a guerra parte. */}
      {z.owner && !z.mine && <Atacar zona={z} aoFeito={aoAgir} />}

      {/* Sua: a zona é um LUGAR desde o D-67 — entra-se nela como se entra na colônia. */}
      {z.mine && (
        <Link
          to={`/zona/${z.id}`}
          data-abrir-zona={z.id}
          className="bg-rust text-sand-light hover:bg-rust-bright mt-3 block w-full py-2 text-center text-sm font-bold"
        >
          Abrir a zona
        </Link>
      )}

      {/* Sua: upgrade de nível (D-84) — custo e guarnição na hora, o nível sobe no tick. */}
      {z.mine && z.upgrade && (
        <div className="border-rust/20 mt-3 border-t pt-2" data-upgrade-zona>
          {z.upgrade.target ? (
            <p className="text-ink-soft text-xs">
              Upgrade para o nível {z.upgrade.target} em curso
              {z.upgrade.finishes_at && `, pronto em ${new Date(z.upgrade.finishes_at).toLocaleString('pt-BR')}`}.
            </p>
          ) : z.upgrade.proximo_custo ? (
            <>
              <p className="text-ink-soft/80 text-xs">
                Subir ao nível {z.level + 1} custa {z.upgrade.proximo_custo.metal_bruto} Metal Bruto +{' '}
                {z.upgrade.proximo_custo.fert} Fert$, guarnição até {z.upgrade.proxima_guarnicao} robôs.
              </p>
              <button
                onClick={() => void upar()}
                disabled={enviando}
                data-upar
                className="border-rust/40 text-rust hover:border-rust mt-2 w-full border py-1.5 text-xs font-bold disabled:opacity-40"
              >
                {enviando ? 'Investindo…' : `Upgrade para o nível ${z.level + 1}`}
              </button>
            </>
          ) : (
            <p className="text-ink-soft/60 text-xs">Nível máximo (5).</p>
          )}
        </div>
      )}

      {/*
        Sua: a fila de construção (D-125) — antes só a ficha inteira da zona (`/zona/{id}`)
        mostrava isso; quem só abria o painel do mapa não via nada em obra.
      */}
      {z.mine && z.obras && z.obras.length > 0 && (
        <div className="border-rust/20 mt-3 border-t pt-2" data-fila-de-obras>
          <p className="text-ink eyebrow">
            Fila de obras ({z.obras.length}/{z.obras_vagas})
          </p>
          <ul className="text-ink-soft mt-1 space-y-0.5 text-xs">
            {z.obras.map((o, i) => (
              <li key={i} data-obra-em-curso={o.structure}>
                {o.nome} nível {o.target_level} — pronta {new Date(o.finishes_at).toLocaleString('pt-BR')}.
              </li>
            ))}
          </ul>
        </div>
      )}

      {/* Sua: manutenção territorial (§27.12, D-84) — sem pagar, a defesa decai; 72h, abandona. */}
      {z.mine && z.manutencao && z.manutencao.inadimplente_desde && (
        <p className="text-rust mt-2 text-xs font-bold" data-manutencao-atrasada>
          Manutenção em atraso desde {new Date(z.manutencao.inadimplente_desde).toLocaleString('pt-BR')}
          {z.manutencao.penalidade_bps > 0 && ` — defesa reduzida em ${z.manutencao.penalidade_bps / 100}%`}.
          Sem pagar por 72 h a zona é abandonada.
        </p>
      )}

      {/* Sua: estabelecendo, ou produzindo e pronta para retirar. */}
      {z.mine && !produtiva && (
        <p className="text-ink-soft/80 mt-3 text-xs">
          Estabelecendo. Começa a extrair em breve; volte depois para retirar.
        </p>
      )}

      {z.mine && produtiva && (
        <div className="mt-3">
          {ociosos.length === 0 ? (
            <p className="text-ink-soft/80 text-xs">Nenhum veículo ocioso para buscar a carga.</p>
          ) : deposito === 0 ? (
            <p className="text-ink-soft/80 text-xs">O Depósito está vazio; a extração corre no tick.</p>
          ) : (
            <>
              <label className="text-ink eyebrow block">Retirar ({MINERAL[z.mineral] ?? z.mineral})</label>
              <input
                type="number"
                min={1}
                max={Math.min(deposito, ociosos[0].capacity)}
                value={qtd}
                onChange={(e) => setQtd(Math.max(0, Number(e.target.value)))}
                className="border-rust/25 bg-sand-light focus:border-rust mt-1 w-full border px-2 py-1 text-sm outline-none"
              />
              <button
                onClick={() => void retirar()}
                disabled={enviando || qtd <= 0 || qtd > Math.min(deposito, ociosos[0].capacity)}
                data-retirar
                className="bg-rust text-sand-light hover:bg-rust-bright mt-2 w-full py-2 text-sm font-bold disabled:opacity-40"
              >
                {enviando ? 'Despachando…' : `Despachar (${ociosos.length} ocioso)`}
              </button>
            </>
          )}
        </div>
      )}
    </div>
  )
}

function BotaoMapa({
  aoClicar,
  rotulo,
  children,
  ...rest
}: {
  aoClicar: () => void
  rotulo: string
  children: React.ReactNode
} & React.HTMLAttributes<HTMLButtonElement>) {
  return (
    <button
      onClick={aoClicar}
      title={rotulo}
      aria-label={rotulo}
      className="bg-sand-light/90 border-rust/25 text-ink hover:bg-rust hover:text-sand-light flex h-8 w-8 items-center justify-center border text-lg leading-none font-bold"
      {...rest}
    >
      {children}
    </button>
  )
}

/**
 * A mira do botão de centralizar. Desenhada, e não o caractere ⌖ (U+2316): a fonte do jogo não o
 * tem, e o botão exibia o retângulo vazio do glifo que falta.
 */
function Alvo() {
  return (
    <svg viewBox="0 0 16 16" className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth={1.5}>
      <circle cx="8" cy="8" r="3.5" />
      <path d="M8 0.5v3M8 12.5v3M0.5 8h3M12.5 8h3" />
    </svg>
  )
}

function Linha({ termo, valor }: { termo: string; valor: string }) {
  return (
    <div className="flex justify-between">
      <dt>{termo}</dt>
      <dd className="text-ink tabular-nums">{valor}</dd>
    </div>
  )
}

/**
 * Cada linha tem o ícone da FORMA de verdade que aparece no mapa — círculo pra colônia, quadrado
 * pra zona (o `<rect data-zona>` de `Desenho`, `corDaZona()`) — não um círculo genérico pra tudo
 * da mesma cor. "Você" e "suas zonas" são cores iguais (ember) mas FORMAS diferentes; "Vizinhas" e
 * "zona neutra livre" também (ink-soft) — juntar as duas num swatch redondo só escondia o quadrado
 * de quem procurava a zona disponível pra ocupar no mapa e não achava o ícone dela na legenda.
 */
function Legenda() {
  return (
    <ul className="text-ink-soft space-y-1 text-xs">
      <li>
        <span className="bg-rust mr-2 inline-block h-3 w-3 rotate-45 align-middle" /> Capital
      </li>
      <li>
        <span className="bg-ember border-ink mr-2 inline-block h-3 w-3 rounded-full border align-middle" />{' '}
        Você
      </li>
      <li>
        <span className="bg-ember border-ink mr-2 inline-block h-3 w-3 border align-middle" /> Suas
        zonas
      </li>
      <li>
        <span className="bg-ink-soft mr-2 inline-block h-3 w-3 rounded-full align-middle" /> Vizinhas
      </li>
      <li>
        <span className="bg-ink-soft mr-2 inline-block h-3 w-3 align-middle" /> Zona neutra livre —
        disponível pra ocupar
      </li>
      <li>
        <span className="bg-rust mr-2 inline-block h-3 w-3 align-middle" /> Zonas de outros
      </li>
      <li>
        <span className="bg-rust/12 border-rust/40 mr-2 inline-block h-3 w-3 border align-middle" />{' '}
        Disco de founders
      </li>
      <li>
        <span className="bg-ink-soft/10 border-ink-soft/25 mr-2 inline-block h-3 w-3 border align-middle" />{' '}
        Anel livre — ninguém funda
      </li>
    </ul>
  )
}

/**
 * A mesma métrica do servidor: euclidiana, arredondada meio-para-cima (`MapaFertways::distancia`).
 * Duplicada aqui só para a tela; o frete e o tributo continuam sendo cobrados no servidor.
 */
function distancia(a: { x: number; y: number }, b: { x: number; y: number }): number {
  return Math.floor(Math.hypot(a.x - b.x, a.y - b.y) + 0.5)
}

/**
 * O ataque a uma zona alheia (GDD §27, §28.10; docs/decisoes.md D-66).
 *
 * Os quatro tipos partem daqui. A tela diz o que o GDD **não** diz em lugar nenhum e sem o que o
 * jogador não consegue decidir nada:
 *
 *  - **O que cada ataque leva e o que cada um ganha.** Só a Invasão toma a zona. O Cerco leva 30%
 *    e não conquista; a Sabotagem e a Apreensão não levam recurso nenhum — desligam uma estrutura.
 *  - **Que só o EXPOSTO é saqueável.** O que cabe no Depósito está protegido, e uma zona bem
 *    cuidada não tem butim nenhum. Atacá-la é gastar exército de graça — **fora de guerra
 *    federativa**, em que a invasão leva o estoque inteiro (D-205).
 *  - **Que Infiltrador e Predador morrem se forem vistos.** Não é batalha, é aposta.
 */
function Atacar({ zona, aoFeito }: { zona: ZonaNeutra; aoFeito: () => Promise<void> | void }) {
  const [guerra, setGuerra] = useState<EstadoDaGuerra | null>(null)
  const [tipo, setTipo] = useState<TipoDeAtaque>('invasao')
  const [quantas, setQuantas] = useState(1)
  const [alvo, setAlvo] = useState('deposito')
  const [erro, setErro] = useState<string | null>(null)
  const [enviando, setEnviando] = useState(false)
  const [aberto, setAberto] = useState(false)

  useEffect(() => {
    if (aberto && !guerra) void api.guerra().then(setGuerra).catch(() => setGuerra(null))
  }, [aberto, guerra])

  const emCasa = (t: string) => (guerra?.unidades ?? []).filter((u) => u.type === t)

  // Sentinelas para invadir e cercar; uma unidade só para infiltrar ou apreender (§28.10).
  const exercito = tipo === 'sabotagem' ? emCasa('infiltrador')
    : tipo === 'apreensao' ? emCasa('predador')
    : emCasa('sentinela')

  const sozinha = tipo === 'sabotagem' || tipo === 'apreensao'
  const usadas = sozinha ? 1 : Math.min(quantas, exercito.length)
  const forca = exercito.slice(0, usadas).reduce((s, u) => s + u.ataque, 0)

  async function despachar() {
    setEnviando(true)
    setErro(null)
    try {
      await api.atacar(
        zona.id,
        tipo,
        exercito.slice(0, usadas).map((u) => u.id),
        sozinha ? alvo : undefined,
      )
      await aoFeito()
      setAberto(false)
    } catch (e) {
      setErro(e instanceof ApiError ? e.message : 'Falha ao despachar o ataque.')
    } finally {
      setEnviando(false)
    }
  }

  if (!aberto) {
    return (
      <button
        onClick={() => setAberto(true)}
        data-abrir-ataque
        className="border-rust text-rust hover:bg-rust hover:text-sand-light mt-3 w-full border py-2 text-sm font-bold"
      >
        Atacar esta zona
      </button>
    )
  }

  return (
    <div className="border-rust/30 mt-3 border-t pt-3" data-painel-ataque>
      <label className="text-ink eyebrow block">Tipo de ataque</label>
      <select
        value={tipo}
        onChange={(e) => setTipo(e.target.value as TipoDeAtaque)}
        data-tipo-ataque
        className="border-rust/25 bg-sand-light focus:border-rust mt-1 w-full border px-2 py-1 text-sm outline-none"
      >
        {/* Em guerra federativa a invasão leva o estoque inteiro (D-205). A tela não sabe se HÁ
            guerra com o dono desta zona, então diz as duas regras em vez de afirmar a errada. */}
        <option value="invasao">
          Invasão — toma a zona e saqueia 50% do exposto (tudo, em guerra federativa)
        </option>
        <option value="cerco">Cerco — fecha a zona 48 h e leva 30%. Não conquista</option>
        <option value="sabotagem">Sabotagem — desliga uma estrutura. Um Infiltrador</option>
        <option value="apreensao">Apreensão — leva um módulo. Um Predador</option>
      </select>

      {sozinha && (
        <>
          <label className="text-ink eyebrow mt-2 block">Estrutura-alvo</label>
          <select
            value={alvo}
            onChange={(e) => setAlvo(e.target.value)}
            data-alvo
            className="border-rust/25 bg-sand-light focus:border-rust mt-1 w-full border px-2 py-1 text-sm outline-none"
          >
            {['deposito', 'muralha', 'torre_de_vigia', 'bastiao', 'abrigo_de_robos', 'posto_de_comando'].map(
              (a) => (
                <option key={a} value={a}>
                  {a.replace(/_/g, ' ')}
                </option>
              ),
            )}
          </select>
          <p className="text-ink-soft/80 mt-1 text-xs">
            Se for detectado, ele morre — não é batalha, é aposta.
          </p>
        </>
      )}

      {!sozinha && (
        <>
          <label className="text-ink eyebrow mt-2 block">
            Sentinelas ({exercito.length} no pátio)
          </label>
          <input
            type="number"
            min={1}
            max={Math.max(1, exercito.length)}
            value={quantas}
            onChange={(e) => setQuantas(Math.max(1, Number(e.target.value)))}
            data-quantas-sentinelas
            className="border-rust/25 bg-sand-light focus:border-rust mt-1 w-full border px-2 py-1 text-sm outline-none"
          />
          <p className="text-ink-soft/80 mt-1 text-xs">
            Força ofensiva: <strong>{forca}</strong>. A marcha de combate é 1,3× mais lenta que a
            civil, e o defensor vê você chegando.
          </p>
        </>
      )}

      {erro && <p className="text-rust mt-2 text-sm">{erro}</p>}

      <div className="mt-3 flex gap-2">
        <button
          onClick={() => void despachar()}
          disabled={enviando || exercito.length === 0}
          data-despachar-ataque
          className="bg-rust text-sand-light hover:bg-rust-bright flex-1 py-2 text-sm font-bold disabled:opacity-40"
        >
          {enviando ? 'Marchando…' : exercito.length === 0 ? 'Sem unidades no pátio' : 'Despachar'}
        </button>
        <button onClick={() => setAberto(false)} className="text-ink-soft hover:text-rust px-3 text-sm">
          Cancelar
        </button>
      </div>

      {exercito.length === 0 && (
        <p className="text-ink-soft/80 mt-2 text-xs">
          Fabrique no Quartel. A Sentinela exige Nióbio, e só o governo o vende.
        </p>
      )}
    </div>
  )
}
