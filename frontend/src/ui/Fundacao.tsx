import { useEffect, useMemo, useRef, useState } from 'react'
import { api, ApiError } from '../api/client'
import type { MapaFundacao } from '../api/client'
import { CelulaSobOCursor, Faixas, Grade, Planeta, Reguas } from './Grade'
import {
  LADO_SVG,
  calhaDe,
  celulaEm,
  celulasNaJanela,
  comFolga,
  passoDaGrade,
  pontoNoSvg,
  projecaoAmpliada,
  projecaoDoPlaneta,
  totalComReguas,
  viewBoxComReguas,
} from './geometria'
import type { Caixa } from './geometria'

/**
 * O seletor de fundação (D-51).
 *
 * O sorteio do D-29 morreu: o colono **escolhe** a célula. O privilégio do founder — ficar perto
 * do Mercado Central e por isso viajar e pagar menos frete — é o desenho, não um bug. Esta tela
 * oferece as duas escolhas legítimas:
 *
 *  - **Perto do Mercado**: o disco de founders (0 < d ≤ 4), 48 células. Só as **populáveis livres**
 *    são clicáveis; as reservadas e as ocupadas aparecem, mas apagadas.
 *  - **Periferia** (d > 5): só as células que o admin liberou (D-147). Até o D-147 era "o planeta
 *    inteiro, clique em qualquer uma"; o usuário decidiu fechar isso — a periferia agora é curada
 *    do mesmo jeito que o disco de founders sempre foi, só que por marcação manual em vez de
 *    fórmula. As células liberadas aparecem marcadas; o resto do planeta é só chão, sem clique.
 *
 * O anel livre (4 < d ≤ 5) não é fundável em lugar nenhum — é respiro. Toda a geometria vem do
 * servidor (`GET /map`); o `POST /colony` confere de novo, então esta validação de cliente é só
 * para não oferecer o impossível.
 *
 * As duas abas desenham a grade, as linhas de X e de Y e as réguas de coordenadas do `Grade`
 * (D-64). Aqui não há "seu slot" para enquadrar — você ainda não fundou —, mas há coordenada para
 * ler: escolher onde morar era clicar às cegas num borrão.
 *
 * A Periferia tem zoom e arraste (o mesmo par zoom/`Vista` que `Mapa.tsx` já usa, autocontido
 * aqui): abre no planeta inteiro, como sempre abriu, mas agora dá pra aproximar — sem isso, achar
 * uma das poucas células liberadas (D-147) espalhadas por um planeta de 101×101 dependia de mirar
 * um alvo de poucos pixels.
 */

export function Fundacao({ aoFundar }: { aoFundar: () => Promise<void> }) {
  const [mapa, setMapa] = useState<MapaFundacao | null>(null)
  const [nome, setNome] = useState('')
  const [escolha, setEscolha] = useState<{ x: number; y: number } | null>(null)
  const [aba, setAba] = useState<'founder' | 'periferia'>('founder')
  const [erro, setErro] = useState<string | null>(null)
  const [enviando, setEnviando] = useState(false)
  // A célula sob o cursor na aba Periferia: só realce e leitura da coordenada.
  const [cursor, setCursor] = useState<{ x: number; y: number } | null>(null)

  useEffect(() => {
    api
      .mapaDeFundacao()
      .then(setMapa)
      .catch((e: unknown) => setErro(e instanceof ApiError ? e.message : 'Falha ao carregar o mapa.'))
  }, [])

  const ocupadas = useMemo(
    () => new Set((mapa?.colonias ?? []).map((c) => `${c.x}:${c.y}`)),
    [mapa],
  )

  async function fundar() {
    if (!escolha || enviando) return
    setErro(null)
    setEnviando(true)
    try {
      await api.fundarColonia(nome, escolha.x, escolha.y)
      await aoFundar()
    } catch (e) {
      setErro(e instanceof ApiError ? e.message : 'Falha ao fundar.')
      // A célula pode ter sido tomada entre carregar o mapa e fundar: recarrega para a UI não
      // insistir num slot que já não existe.
      api.mapaDeFundacao().then(setMapa).catch(() => {})
      setEscolha(null)
    } finally {
      setEnviando(false)
    }
  }

  return (
    <div className="flex min-h-screen items-center justify-center p-4">
      <div className="painel bg-sand-light max-h-[95vh] w-full max-w-4xl overflow-y-auto p-6">
        <div className="text-rust eyebrow">Fundação</div>
        <h1 className="text-ink text-2xl font-black">Escolha onde fundar.</h1>
        <p className="text-ink-soft mt-1 text-sm">
          Você chega com 100 Fert$, um kit de recursos e um Furgão de Comércio. Perto do Mercado
          se viaja menos; na periferia, só as células já liberadas pela administração.
        </p>

        {erro && <p className="text-rust mt-3 text-sm">{erro}</p>}

        {mapa && (
          <>
            <div className="mt-4 flex gap-2">
              <Aba ativa={aba === 'founder'} aoClicar={() => setAba('founder')}>
                Perto do Mercado
              </Aba>
              <Aba ativa={aba === 'periferia'} aoClicar={() => setAba('periferia')}>
                Periferia
              </Aba>
            </div>

            <div className="mt-4 grid gap-6 md:grid-cols-[1fr_16rem]">
              <div className="border-rust/20 bg-sand relative border p-2">
                {aba === 'founder' ? (
                  <DiscoDeFounders mapa={mapa} escolha={escolha} aoEscolher={setEscolha} />
                ) : (
                  <>
                    {mapa.periferia_liberada.length === 0 && (
                      <p
                        data-periferia-vazia
                        className="text-ink-soft absolute inset-x-2 top-2 z-10 bg-sand-light/90 border-rust/25 border p-2 text-center text-xs"
                      >
                        Nenhuma célula de periferia foi liberada ainda — só o disco de founders,
                        por enquanto.
                      </p>
                    )}
                    <MapaPeriferia
                      mapa={mapa}
                      ocupadas={ocupadas}
                      escolha={escolha}
                      aoEscolher={setEscolha}
                      cursor={cursor}
                      aoMoverCursor={setCursor}
                    />
                    {cursor && (
                      <div
                        data-coordenada-cursor
                        className="bg-sand-light/90 border-rust/25 text-ink absolute bottom-3 left-3 border px-2 py-0.5 text-xs tabular-nums"
                      >
                        ({cursor.x}, {cursor.y})
                      </div>
                    )}
                  </>
                )}
              </div>

              <div>
                {escolha ? (
                  <div className="border-rust/25 bg-sand border p-3" data-celula-escolhida>
                    <div className="text-rust eyebrow">Célula escolhida</div>
                    <div className="text-ink text-lg font-black tabular-nums">
                      ({escolha.x}, {escolha.y})
                    </div>
                    <p className="text-ink-soft mt-1 text-sm">
                      {faixaLabel(escolha, mapa)} · {distancia(escolha, mapa.capital)} slots do Mercado
                    </p>
                  </div>
                ) : (
                  <p className="text-ink-soft text-sm">
                    {aba === 'founder'
                      ? 'Clique num slot livre do disco para escolhê-lo.'
                      : 'Clique numa célula liberada da periferia.'}
                  </p>
                )}

                <label className="text-ink eyebrow mt-5 block">Nome da colônia</label>
                <input
                  className="border-rust/25 bg-sand focus:border-rust mt-1 w-full border px-3 py-2 outline-none"
                  placeholder="Nova Aurora"
                  value={nome}
                  onChange={(e) => setNome(e.target.value)}
                  minLength={3}
                />

                <button
                  onClick={() => void fundar()}
                  disabled={!escolha || nome.trim().length < 3 || enviando}
                  data-fundar
                  className="bg-rust text-sand-light hover:bg-rust-bright mt-3 w-full py-3 font-bold disabled:cursor-not-allowed disabled:opacity-40"
                >
                  {enviando ? 'Fundando…' : 'Fundar'}
                </button>

                <Legenda aba={aba} />
              </div>
            </div>
          </>
        )}
      </div>
    </div>
  )
}

function Aba({
  ativa,
  aoClicar,
  children,
}: {
  ativa: boolean
  aoClicar: () => void
  children: React.ReactNode
}) {
  return (
    <button
      onClick={aoClicar}
      className={`px-3 py-1.5 text-sm font-bold ${
        ativa ? 'bg-rust text-sand-light' : 'text-ink-soft hover:bg-sand border-rust/20 border'
      }`}
    >
      {children}
    </button>
  )
}

/**
 * O disco de founders, ampliado para os 48 slots ficarem clicáveis. Escala fixa: cada célula do
 * jogo vale `PASSO` unidades do SVG, e a Capital fica no centro.
 *
 * O enquadramento vai até o anel (d ≤ 5), e não até o disco (d ≤ 4): o anel é justamente onde
 * **não** se funda, e mostrá-lo é o que explica por que os slots param onde param.
 */
function DiscoDeFounders({
  mapa,
  escolha,
  aoEscolher,
}: {
  mapa: MapaFundacao
  escolha: { x: number; y: number } | null
  aoEscolher: (c: { x: number; y: number } | null) => void
}) {
  const R = mapa.raio_anel
  const PASSO = 42
  const centro = (R + 0.5) * PASSO // meia célula de folga em cada ponta
  const proj = projecaoAmpliada(PASSO, centro)
  const caixa: Caixa = { x0: 0, y0: 0, largura: centro * 2, altura: centro * 2 }
  const faixa = { xDe: -R, xAte: R, yDe: -R, yAte: R }

  return (
    <svg data-disco-founder viewBox={viewBoxComReguas(caixa)} className="block h-auto w-full">
      <Reguas proj={proj} caixa={caixa} faixa={faixa} passo={1} />
      <Faixas proj={proj} raioFounder={mapa.raio_founder} raioAnel={mapa.raio_anel} />
      <Grade proj={proj} faixa={faixa} passo={1} />

      {/* A Capital, losango no centro. Sem rótulo escrito ao lado: agora que a grade existe, o
          texto caía dentro da célula (0,-1) e brigava com o slot de founder que mora nela. Quem
          nomeia o losango é a legenda, à direita, e o `title` de quem passa o mouse. */}
      <rect
        x={centro - 10}
        y={centro - 10}
        width={20}
        height={20}
        transform={`rotate(45 ${centro} ${centro})`}
        fill="var(--color-rust)"
      >
        <title>Mercado Central (0, 0) — a Capital</title>
      </rect>

      {mapa.founder_slots.map((s) => {
        const livre = !s.reservado && !s.ocupado
        const escolhido = escolha?.x === s.x && escolha?.y === s.y

        return (
          <circle
            key={`${s.x}:${s.y}`}
            data-founder-slot={livre ? `${s.x},${s.y}` : undefined}
            cx={proj.px(s.x)}
            cy={proj.py(s.y)}
            r={escolhido ? 15 : 11}
            fill={escolhido ? 'var(--color-rust-bright)' : 'var(--color-ink-soft)'}
            fillOpacity={livre || escolhido ? 1 : 0.25}
            stroke={escolhido ? 'var(--color-ink)' : 'none'}
            strokeWidth={2}
            className={livre ? 'cursor-pointer' : 'cursor-not-allowed'}
            onClick={() => livre && aoEscolher(escolhido ? null : { x: s.x, y: s.y })}
          >
            <title>
              ({s.x}, {s.y}) — {s.ocupado ? 'ocupado' : s.reservado ? 'reservado' : 'livre'}
            </title>
          </circle>
        )
      })}
    </svg>
  )
}

/** Zoom e centro da Periferia, em unidades do SVG — mesmo par que `Mapa.tsx:Vista` usa. */
type VistaPeriferia = { cx: number; cy: number; scale: number }

const ZOOM_MIN = 1
const ZOOM_MAX = 12
const limitarEscala = (s: number) => Math.min(ZOOM_MAX, Math.max(ZOOM_MIN, s))
const limitarCentro = (v: number) => Math.min(LADO_SVG, Math.max(0, v))

/**
 * O planeta inteiro, para escolher na periferia. Clicar numa célula a seleciona se ela for livre
 * e de fato periferia (d > 5); founders e anel ficam de fora — para os founders, a outra aba.
 *
 * Abre no planeta inteiro (escala 1) e dá pra aproximar: roda do mouse, botões +/− e arrastar —
 * mesmo mecanismo de `Mapa.tsx`, autocontido aqui porque nada mais nesta tela precisa de zoom.
 *
 * A célula sob o cursor acende e a sua coordenada aparece: com 101 colunas, uma célula é pequena
 * demais no planeta inteiro pra saber em qual está prestes a passar o resto do jogo sem o realce.
 */
function MapaPeriferia({
  mapa,
  ocupadas,
  escolha,
  aoEscolher,
  cursor,
  aoMoverCursor,
}: {
  mapa: MapaFundacao
  ocupadas: Set<string>
  escolha: { x: number; y: number } | null
  aoEscolher: (c: { x: number; y: number } | null) => void
  cursor: { x: number; y: number } | null
  aoMoverCursor: (c: { x: number; y: number } | null) => void
}) {
  const proj = projecaoDoPlaneta(mapa.side)
  const svgRef = useRef<SVGSVGElement>(null)
  const [vista, setVista] = useState<VistaPeriferia>({ cx: LADO_SVG / 2, cy: LADO_SVG / 2, scale: 1 })
  const [pegando, setPegando] = useState(false)
  // Marca que o último gesto foi um arraste, para o pointerup não virar seleção de célula.
  const arrastou = useRef(false)

  const lado = LADO_SVG / vista.scale
  const caixa: Caixa = { x0: vista.cx - lado / 2, y0: vista.cy - lado / 2, largura: lado, altura: lado }
  const numeradas = celulasNaJanela(caixa, mapa.side)
  const riscadas = comFolga(numeradas, mapa.side)
  const passo = passoDaGrade(mapa.side / vista.scale)
  const k = 1 / vista.scale

  // Só estas células aceitam clique na periferia (D-147) — o resto do planeta é chão, sem convite.
  const liberadas = new Set(mapa.periferia_liberada.map((c) => `${c.x}:${c.y}`))

  const zoom = (fator: number) =>
    setVista((v) => ({ ...v, scale: limitarEscala(v.scale * fator) }))

  // Zoom pela roda do mouse, ancorado no cursor — mesma conta de `Mapa.tsx`.
  useEffect(() => {
    const svg = svgRef.current
    if (!svg) return

    const aoRolar = (e: WheelEvent) => {
      e.preventDefault()
      const p = pontoNoSvg(svg, e)
      if (!p) return
      const r = svg.getBoundingClientRect()
      const fx = (e.clientX - r.left) / r.width
      const fy = (e.clientY - r.top) / r.height
      const fator = e.deltaY < 0 ? 1.2 : 1 / 1.2

      setVista((v) => {
        const escala = limitarEscala(v.scale * fator)
        if (escala === v.scale) return v

        const ladoNovo = LADO_SVG / escala
        const g = calhaDe(ladoNovo)
        const total = totalComReguas(ladoNovo)
        const x0 = p.x - fx * total + g
        const y0 = p.y - fy * total + g

        return { cx: limitarCentro(x0 + ladoNovo / 2), cy: limitarCentro(y0 + ladoNovo / 2), scale: escala }
      })
    }

    svg.addEventListener('wheel', aoRolar, { passive: false })

    return () => svg.removeEventListener('wheel', aoRolar)
  }, [])

  // Arrastar para mover o mapa — mesmo padrão de `Mapa.tsx`, sem setPointerCapture (desviaria o
  // `click` da célula para o SVG).
  const iniciarArrasto = (e: React.PointerEvent<SVGSVGElement>) => {
    if (e.button !== 0) return
    const r = e.currentTarget.getBoundingClientRect()
    const ladoAtual = LADO_SVG / vista.scale
    const largura = totalComReguas(ladoAtual)
    const inicio = { cx: vista.cx, cy: vista.cy, clientX: e.clientX, clientY: e.clientY }
    arrastou.current = false
    setPegando(true)

    const mover = (ev: PointerEvent) => {
      const dx = ((ev.clientX - inicio.clientX) / r.width) * largura
      const dy = ((ev.clientY - inicio.clientY) / r.height) * largura
      if (!arrastou.current && Math.hypot(ev.clientX - inicio.clientX, ev.clientY - inicio.clientY) > 4) {
        arrastou.current = true
      }
      setVista((v) => ({ ...v, cx: limitarCentro(inicio.cx - dx), cy: limitarCentro(inicio.cy - dy) }))
    }

    const soltar = () => {
      window.removeEventListener('pointermove', mover)
      window.removeEventListener('pointerup', soltar)
      setPegando(false)
      setTimeout(() => {
        arrastou.current = false
      }, 0)
    }

    window.addEventListener('pointermove', mover)
    window.addEventListener('pointerup', soltar)
  }

  /** A célula sob o ponteiro, ou null se ele caiu fora do planeta (ou na calha das réguas). */
  function celulaDoEvento(e: React.MouseEvent<SVGSVGElement>): { x: number; y: number } | null {
    const p = pontoNoSvg(e.currentTarget, e)
    if (!p) return null
    const c = celulaEm(p, mapa.side)

    return Math.abs(c.x) > mapa.raio || Math.abs(c.y) > mapa.raio ? null : c
  }

  function aoClicarNoMapa(e: React.MouseEvent<SVGSVGElement>) {
    if (arrastou.current) return // a ponta de um arraste não seleciona
    const c = celulaDoEvento(e)
    if (!c) return
    if (!liberadas.has(`${c.x}:${c.y}`)) return // não liberada: nem founder/anel chegam a ser liberados
    if (ocupadas.has(`${c.x}:${c.y}`)) return
    aoEscolher(c)
  }

  return (
    <div className="relative">
      <div className="absolute right-1 top-1 z-10 flex flex-col gap-1">
        <BotaoZoom aoClicar={() => zoom(1.5)} rotulo="Aproximar">
          +
        </BotaoZoom>
        <BotaoZoom aoClicar={() => zoom(1 / 1.5)} rotulo="Afastar">
          −
        </BotaoZoom>
        <BotaoZoom
          aoClicar={() => setVista({ cx: LADO_SVG / 2, cy: LADO_SVG / 2, scale: 1 })}
          rotulo="Ver o planeta inteiro"
        >
          <span className="text-xs">tudo</span>
        </BotaoZoom>
      </div>

      <svg
        ref={svgRef}
        data-seletor-mapa
        viewBox={viewBoxComReguas(caixa)}
        className={`block h-auto w-full touch-none select-none ${pegando ? 'cursor-grabbing' : 'cursor-crosshair'}`}
        onPointerDown={iniciarArrasto}
        onClick={aoClicarNoMapa}
        onMouseMove={(e) => aoMoverCursor(celulaDoEvento(e))}
        onMouseLeave={() => aoMoverCursor(null)}
      >
        <Reguas proj={proj} caixa={caixa} faixa={numeradas} passo={passo} />
        <Planeta k={k} />
        <Faixas proj={proj} raioFounder={mapa.raio_founder} raioAnel={mapa.raio_anel} />
        <Grade proj={proj} faixa={riscadas} passo={passo} k={k} />

        {cursor && <CelulaSobOCursor proj={proj} celula={cursor} k={k} />}

        {/* A Capital. */}
        <rect
          x={proj.px(0) - 8}
          y={proj.py(0) - 8}
          width={16}
          height={16}
          transform={`rotate(45 ${proj.px(0)} ${proj.py(0)})`}
          fill="var(--color-rust)"
        />

        {/* As células de periferia liberadas pelo admin (D-147) — só elas aceitam clique. */}
        {mapa.periferia_liberada.map((c) => (
          <circle
            key={`liberada:${c.x}:${c.y}`}
            cx={proj.px(c.x)}
            cy={proj.py(c.y)}
            r={4}
            fill="var(--color-ember)"
            fillOpacity={ocupadas.has(`${c.x}:${c.y}`) ? 0.3 : 1}
          />
        ))}

        {/* Colônias já instaladas. */}
        {mapa.colonias.map((c) => (
          <circle
            key={`${c.x}:${c.y}`}
            cx={proj.px(c.x)}
            cy={proj.py(c.y)}
            r={5}
            fill="var(--color-ink-soft)"
          />
        ))}

        {/* A célula escolhida, se for de periferia. */}
        {escolha && Math.hypot(escolha.x, escolha.y) > mapa.raio_anel && (
          <circle
            cx={proj.px(escolha.x)}
            cy={proj.py(escolha.y)}
            r={9}
            fill="var(--color-rust-bright)"
            stroke="var(--color-ink)"
            strokeWidth={2}
          />
        )}
      </svg>
    </div>
  )
}

function BotaoZoom({
  aoClicar,
  rotulo,
  children,
}: {
  aoClicar: () => void
  rotulo: string
  children: React.ReactNode
}) {
  return (
    <button
      type="button"
      onClick={aoClicar}
      title={rotulo}
      aria-label={rotulo}
      className="bg-sand-light/90 border-rust/25 text-ink hover:bg-rust hover:text-sand-light flex h-8 w-8 items-center justify-center border text-base leading-none font-bold"
    >
      {children}
    </button>
  )
}

function Legenda({ aba }: { aba: 'founder' | 'periferia' }) {
  return (
    <ul className="text-ink-soft mt-5 space-y-1 text-xs">
      <li>
        <span className="bg-rust mr-2 inline-block h-3 w-3 rotate-45 align-middle" /> Mercado Central
      </li>
      {aba === 'founder' ? (
        <>
          <li>
            <span className="bg-ink-soft mr-2 inline-block h-3 w-3 rounded-full align-middle" /> Slot
            livre — clicável
          </li>
          <li>
            <span className="bg-ink-soft/25 mr-2 inline-block h-3 w-3 rounded-full align-middle" />{' '}
            Reservado ou ocupado
          </li>
        </>
      ) : (
        <>
          <li>
            <span className="bg-ember mr-2 inline-block h-3 w-3 rounded-full align-middle" /> Célula
            liberada — clicável
          </li>
          <li>
            <span className="bg-ink-soft mr-2 inline-block h-3 w-3 rounded-full align-middle" />{' '}
            Colônia existente
          </li>
        </>
      )}
    </ul>
  )
}

function faixaLabel(c: { x: number; y: number }, mapa: MapaFundacao): string {
  const d = Math.hypot(c.x, c.y)
  if (d <= mapa.raio_founder) return 'Slot de founder'
  return 'Periferia'
}

/** A mesma métrica do frete: euclidiana, meio-para-cima (§25.6, `MapaFertways::distancia`). */
function distancia(a: { x: number; y: number }, b: { x: number; y: number }): number {
  return Math.floor(Math.hypot(a.x - b.x, a.y - b.y) + 0.5)
}
