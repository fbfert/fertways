import { useCallback, useEffect, useRef, useState } from 'react'
import { api, ApiError } from '../api/client'
import type { ColoniaVizinha, Diretorio, Veiculo, ZonaNeutra } from '../api/client'

/**
 * O mapa de Fertways: a Capital, a sua colônia, as vizinhas e as 120 zonas neutras (D-52).
 *
 * Toda a geometria — lado da grade e posição da Capital — vem da API (`GET /colonies`), nunca de
 * constante daqui (D-51). Não há névoa de guerra: o diretório e as zonas listam tudo (D-37).
 *
 * As zonas moram nos cantos e são células únicas num mapa 101×101, então o mapa navega: **arrastar**
 * para mover, **roda do mouse** e botões +/− para o **zoom**, e um botão para **centralizar na sua
 * colônia** — sem isso não dá para clicar numa zona.
 */

/** Lado do desenho, em px do sistema de coordenadas do SVG. */
const LADO_SVG = 1000

/** Limites do zoom. 1 mostra o mapa inteiro; ZOOM_MAX aproxima o bastante para clicar numa zona. */
const ZOOM_MIN = 1
const ZOOM_MAX = 12
const limitarEscala = (s: number) => Math.min(ZOOM_MAX, Math.max(ZOOM_MIN, s))

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

type Selecao =
  | { tipo: 'colonia'; c: ColoniaVizinha }
  | { tipo: 'zona'; z: ZonaNeutra }
  | null

export function Mapa({ aoFechar }: { aoFechar: () => void }) {
  const [dir, setDir] = useState<Diretorio | null>(null)
  const [zonas, setZonas] = useState<ZonaNeutra[]>([])
  const [frota, setFrota] = useState<Veiculo[]>([])
  const [erro, setErro] = useState<string | null>(null)
  const [selecao, setSelecao] = useState<Selecao>(null)

  // Zoom e centro do viewBox, em px do SVG. `scale` 1 mostra o mapa inteiro.
  const [vista, setVista] = useState({ cx: LADO_SVG / 2, cy: LADO_SVG / 2, scale: 1 })
  const [pegando, setPegando] = useState(false)
  const svgRef = useRef<SVGSVGElement>(null)
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

  // Zoom pela roda do mouse, ancorado no cursor. Nativo e não-passivo para segurar o scroll do
  // modal enquanto se aproxima o mapa; o React registra onWheel como passivo e não deixaria.
  useEffect(() => {
    const svg = svgRef.current
    if (!svg) return
    const aoRolar = (e: WheelEvent) => {
      e.preventDefault()
      const r = svg.getBoundingClientRect()
      const fx = (e.clientX - r.left) / r.width
      const fy = (e.clientY - r.top) / r.height
      const fator = e.deltaY < 0 ? 1.2 : 1 / 1.2
      setVista((v) => {
        const escala = limitarEscala(v.scale * fator)
        if (escala === v.scale) return v
        const w = LADO_SVG / v.scale
        const vx = Math.max(0, Math.min(v.cx - w / 2, LADO_SVG - w))
        const vy = Math.max(0, Math.min(v.cy - w / 2, LADO_SVG - w))
        // O ponto sob o cursor tem de ficar parado: resolvo o novo centro por ele.
        const sx = vx + fx * w
        const sy = vy + fy * w
        const w2 = LADO_SVG / escala
        return { cx: sx - fx * w2 + w2 / 2, cy: sy - fy * w2 + w2 / 2, scale: escala }
      })
    }
    svg.addEventListener('wheel', aoRolar, { passive: false })
    return () => svg.removeEventListener('wheel', aoRolar)
  }, [dir])

  const px = (v: number, side: number) => ((v + Math.floor(side / 2) + 0.5) / side) * LADO_SVG
  const py = (v: number, side: number) => LADO_SVG - ((v + Math.floor(side / 2) + 0.5) / side) * LADO_SVG

  // viewBox a partir do centro e do zoom, preso às bordas do desenho.
  const w = LADO_SVG / vista.scale
  const vx = Math.max(0, Math.min(vista.cx - w / 2, LADO_SVG - w))
  const vy = Math.max(0, Math.min(vista.cy - w / 2, LADO_SVG - w))
  const viewBox = `${vx} ${vy} ${w} ${w}`
  // Marcadores em tamanho de tela ~constante: encolhem no SVG conforme o zoom aumenta.
  const k = 1 / vista.scale

  const zoom = (fator: number) => setVista((v) => ({ ...v, scale: limitarEscala(v.scale * fator) }))

  const centrarNaColonia = () => {
    if (!dir) return
    setVista({ cx: px(dir.me.x, dir.side), cy: py(dir.me.y, dir.side), scale: Math.max(vista.scale, 4) })
  }

  // Arrastar para mover o mapa. Sem setPointerCapture (ele desviaria o `click` da zona para o SVG):
  // ouço o move/up na janela e desligo no fim. O limiar de 4 px separa clique de arraste.
  const iniciarArrasto = (e: React.PointerEvent<SVGSVGElement>) => {
    if (e.button !== 0) return
    const r = e.currentTarget.getBoundingClientRect()
    const largura = LADO_SVG / vista.scale
    const inicio = { cx: vista.cx, cy: vista.cy, clientX: e.clientX, clientY: e.clientY }
    arrastou.current = false
    setPegando(true)
    const mover = (ev: PointerEvent) => {
      const dx = ((ev.clientX - inicio.clientX) / r.width) * largura
      const dy = ((ev.clientY - inicio.clientY) / r.height) * largura
      if (!arrastou.current && Math.hypot(ev.clientX - inicio.clientX, ev.clientY - inicio.clientY) > 4) {
        arrastou.current = true
      }
      setVista((v) => ({ ...v, cx: inicio.cx - dx, cy: inicio.cy - dy }))
    }
    const soltar = () => {
      window.removeEventListener('pointermove', mover)
      window.removeEventListener('pointerup', soltar)
      setPegando(false)
      // Zera só depois que o `click` deste gesto já correu, para o arraste não selecionar nada.
      setTimeout(() => {
        arrastou.current = false
      }, 0)
    }
    window.addEventListener('pointermove', mover)
    window.addEventListener('pointerup', soltar)
  }

  // Seleciona só se o gesto foi um clique de verdade, não a ponta de um arraste.
  const selecionar = (fn: () => void) => {
    if (!arrastou.current) fn()
  }

  const focar = (x: number, y: number) => {
    if (!dir) return
    setVista({ cx: px(x, dir.side), cy: py(y, dir.side), scale: Math.max(vista.scale, 5) })
  }

  return (
    <div className="fixed inset-0 z-20 flex items-center justify-center bg-ink/70 p-4">
      <div className="painel bg-sand-light max-h-[92vh] w-full max-w-5xl overflow-y-auto p-6">
        <header className="flex items-start justify-between">
          <div>
            <div className="text-rust eyebrow">Mapa</div>
            <h2 className="text-ink text-2xl font-black">Fertways</h2>
            {dir && (
              <p className="text-ink-soft mt-1 text-sm">
                Grade {dir.side}×{dir.side}. Capital em ({dir.capital.x}, {dir.capital.y}). Você em (
                {dir.me.x}, {dir.me.y}), a {distancia(dir.me, dir.capital)} slots dela.
              </p>
            )}
          </div>
          <button onClick={aoFechar} className="text-ink-soft hover:text-rust text-2xl leading-none">
            ×
          </button>
        </header>

        {erro && <p className="text-rust mt-4 text-sm">{erro}</p>}

        {dir && (
          <div className="mt-5 grid gap-6 md:grid-cols-[1fr_18rem]">
            <div className="relative border-rust/20 bg-sand border">
              {/* Ferramentas de zoom e foco. Ficam sobre o mapa, no canto. */}
              <div className="absolute right-2 top-2 z-10 flex flex-col gap-1">
                <BotaoMapa aoClicar={() => zoom(1.5)} rotulo="Aproximar" data-zoom-in>
                  +
                </BotaoMapa>
                <BotaoMapa aoClicar={() => zoom(1 / 1.5)} rotulo="Afastar" data-zoom-out>
                  −
                </BotaoMapa>
                <BotaoMapa aoClicar={centrarNaColonia} rotulo="Centralizar na sua colônia" data-centrar>
                  ⌖
                </BotaoMapa>
              </div>

              <svg
                ref={svgRef}
                data-mapa
                viewBox={viewBox}
                onPointerDown={iniciarArrasto}
                className={`block h-auto w-full touch-none select-none ${
                  pegando ? 'cursor-grabbing' : 'cursor-grab'
                }`}
              >
                <GradeDeFundo />

                {/* As zonas neutras: quadradinhos nos cantos, coloridos por dono. */}
                {zonas.map((z) => (
                  <rect
                    key={`z${z.id}`}
                    data-zona={z.id}
                    x={px(z.x, dir.side) - 6 * k}
                    y={py(z.y, dir.side) - 6 * k}
                    width={12 * k}
                    height={12 * k}
                    fill={corDaZona(z, selecao)}
                    stroke={selecao?.tipo === 'zona' && selecao.z.id === z.id ? 'var(--color-ink)' : 'none'}
                    strokeWidth={2 * k}
                    className="cursor-pointer"
                    onClick={() => selecionar(() => setSelecao({ tipo: 'zona', z }))}
                  >
                    <title>
                      Zona {DISTRITO[z.district]} ({z.x}, {z.y}) — {MINERAL[z.mineral] ?? z.mineral}
                      {z.owner ? ` · ${z.owner.name}` : ' · livre'}
                    </title>
                  </rect>
                ))}

                {/* A Capital. Losango, para não se confundir com colônia nenhuma. */}
                <rect
                  x={px(dir.capital.x, dir.side) - 9 * k}
                  y={py(dir.capital.y, dir.side) - 9 * k}
                  width={18 * k}
                  height={18 * k}
                  transform={`rotate(45 ${px(dir.capital.x, dir.side)} ${py(dir.capital.y, dir.side)})`}
                  fill="var(--color-rust)"
                >
                  <title>Capital — Mercado Central</title>
                </rect>

                {dir.colonies.map((c) => (
                  <circle
                    key={c.id}
                    cx={px(c.x, dir.side)}
                    cy={py(c.y, dir.side)}
                    r={(selecao?.tipo === 'colonia' && selecao.c.id === c.id ? 11 : 7) * k}
                    fill={
                      selecao?.tipo === 'colonia' && selecao.c.id === c.id
                        ? 'var(--color-rust-bright)'
                        : 'var(--color-ink-soft)'
                    }
                    className="cursor-pointer"
                    onClick={() => selecionar(() => setSelecao({ tipo: 'colonia', c }))}
                  >
                    <title>
                      {c.name} ({c.nickname}) — {c.distance} slots
                    </title>
                  </circle>
                ))}

                {/* A sua colônia por último: fica por cima de qualquer vizinha sobreposta. */}
                <circle
                  cx={px(dir.me.x, dir.side)}
                  cy={py(dir.me.y, dir.side)}
                  r={9 * k}
                  fill="var(--color-ember)"
                  stroke="var(--color-ink)"
                  strokeWidth={2 * k}
                >
                  <title>{dir.me.name} — você</title>
                </circle>

                {/* A reta até o alvo escolhido: o frete cobra por esta distância. */}
                {selecao && (
                  <line
                    x1={px(dir.me.x, dir.side)}
                    y1={py(dir.me.y, dir.side)}
                    x2={px(alvoX(selecao), dir.side)}
                    y2={py(alvoY(selecao), dir.side)}
                    stroke="var(--color-rust)"
                    strokeWidth={2 * k}
                    strokeDasharray={`${8 * k} ${6 * k}`}
                  />
                )}
              </svg>
            </div>

            <div>
              <Legenda />

              {selecao?.tipo === 'colonia' && <PainelColonia c={selecao.c} />}

              {selecao?.tipo === 'zona' && (
                <PainelZona
                  // A versão fresca da zona (após ocupar/retirar, a lista já veio recarregada).
                  z={zonas.find((zz) => zz.id === selecao.z.id) ?? selecao.z}
                  me={dir.me}
                  frota={frota}
                  aoAgir={recarregar}
                  aoFocar={() => focar(selecao.z.x, selecao.z.y)}
                />
              )}

              {!selecao && (
                <p className="text-ink-soft mt-4 text-sm">
                  Clique numa colônia ou numa zona neutra. Arraste para mover, role o mouse ou use + /
                  − para o zoom, e o ⌖ centraliza na sua colônia. As zonas ficam nos cantos.
                </p>
              )}

              <h3 className="text-ink eyebrow mt-5">Zonas ({zonas.filter((z) => z.mine).length} suas)</h3>
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
                      onClick={() => setSelecao({ tipo: 'colonia', c })}
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
        )}
      </div>
    </div>
  )
}

function alvoX(s: Exclude<Selecao, null>): number {
  return s.tipo === 'colonia' ? s.c.x : s.z.x
}
function alvoY(s: Exclude<Selecao, null>): number {
  return s.tipo === 'colonia' ? s.c.y : s.z.y
}

function corDaZona(z: ZonaNeutra, selecao: Selecao): string {
  if (selecao?.tipo === 'zona' && selecao.z.id === z.id) return 'var(--color-rust-bright)'
  if (z.mine) return 'var(--color-ember)'
  if (z.owner) return 'var(--color-rust)'
  return 'var(--color-ink-soft)'
}

function PainelColonia({ c }: { c: ColoniaVizinha }) {
  return (
    <div className="border-rust/25 bg-sand mt-4 border p-3">
      <div className="text-rust eyebrow">{c.nickname}</div>
      <div className="text-ink font-black">{c.name}</div>
      <dl className="text-ink-soft mt-2 space-y-1 text-sm">
        <Linha termo="Posição" valor={`(${c.x}, ${c.y})`} />
        <Linha termo="Distância" valor={`${c.distance} slots`} />
        <Linha termo="Porte" valor={`${c.building_levels_sum} níveis`} />
      </dl>
      <p className="text-ink-soft/70 mt-2 text-xs">
        Porte é a soma dos níveis das construções — não é o Marco do GDD.
      </p>
    </div>
  )
}

function PainelZona({
  z,
  me,
  frota,
  aoAgir,
  aoFocar,
}: {
  z: ZonaNeutra
  me: { x: number; y: number }
  frota: Veiculo[]
  aoAgir: () => Promise<void>
  aoFocar: () => void
}) {
  const [erro, setErro] = useState<string | null>(null)
  const [enviando, setEnviando] = useState(false)
  const ociosos = frota.filter((v) => v.status === 'ocioso')
  const [qtd, setQtd] = useState(0)

  const dist = distancia(me, z)
  const produtiva = z.productive_at ? new Date(z.productive_at).getTime() <= Date.now() : false

  useEffect(() => {
    const cap = ociosos[0]?.capacity ?? 0
    setQtd(Math.min(z.deposit_amount, cap))
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
      <div className="text-rust eyebrow">Zona {DISTRITO[z.district]}</div>
      <div className="text-ink font-black">{MINERAL[z.mineral] ?? z.mineral}</div>
      <dl className="text-ink-soft mt-2 space-y-1 text-sm">
        <Linha termo="Posição" valor={`(${z.x}, ${z.y})`} />
        <Linha termo="Distância" valor={`${dist} slots`} />
        <Linha termo="Dono" valor={z.owner ? (z.mine ? 'você' : z.owner.name) : 'livre'} />
        {z.mine && <Linha termo="Depósito" valor={`${z.deposit_amount} / ${z.deposit_cap}`} />}
        {z.mine && <Linha termo="Extração" valor={`${z.extraction_per_hour}/h`} />}
      </dl>

      <button onClick={aoFocar} className="text-rust hover:text-rust-bright mt-2 text-xs">
        Focar no mapa
      </button>

      {erro && <p className="text-rust mt-2 text-sm">{erro}</p>}

      {/* Livre: ocupar. Pesada (D-52): Posto + 20 Robôs + tempo. */}
      {!z.owner && (
        <div className="mt-3">
          <p className="text-ink-soft/80 text-xs">
            Ocupar custa 800 Metal Bruto + 300 Fert$ (Posto de Comando) e 20 Robôs Mineradores, e leva
            20 h para produzir.
          </p>
          <button
            onClick={() => void ocupar()}
            disabled={enviando}
            data-ocupar
            className="bg-rust text-sand-light hover:bg-rust-bright mt-2 w-full py-2 text-sm font-bold disabled:opacity-40"
          >
            {enviando ? 'Ocupando…' : 'Ocupar'}
          </button>
        </div>
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
          ) : z.deposit_amount === 0 ? (
            <p className="text-ink-soft/80 text-xs">O Depósito está vazio; a extração corre no tick.</p>
          ) : (
            <>
              <label className="text-ink eyebrow block">Retirar ({MINERAL[z.mineral] ?? z.mineral})</label>
              <input
                type="number"
                min={1}
                max={Math.min(z.deposit_amount, ociosos[0].capacity)}
                value={qtd}
                onChange={(e) => setQtd(Math.max(0, Number(e.target.value)))}
                className="border-rust/25 bg-sand-light focus:border-rust mt-1 w-full border px-2 py-1 text-sm outline-none"
              />
              <button
                onClick={() => void retirar()}
                disabled={enviando || qtd <= 0 || qtd > Math.min(z.deposit_amount, ociosos[0].capacity)}
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
      className="bg-sand-light/90 border-rust/25 text-ink hover:bg-rust hover:text-sand-light h-8 w-8 border text-lg leading-none font-bold"
      {...rest}
    >
      {children}
    </button>
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

function Legenda() {
  return (
    <ul className="text-ink-soft space-y-1 text-xs">
      <li>
        <span className="bg-rust mr-2 inline-block h-3 w-3 rotate-45 align-middle" /> Capital
      </li>
      <li>
        <span className="bg-ember border-ink mr-2 inline-block h-3 w-3 rounded-full border align-middle" />{' '}
        Você / suas zonas
      </li>
      <li>
        <span className="bg-ink-soft mr-2 inline-block h-3 w-3 rounded-full align-middle" /> Vizinhas /
        zonas livres
      </li>
      <li>
        <span className="bg-rust mr-2 inline-block h-3 w-3 align-middle" /> Zonas de outros
      </li>
    </ul>
  )
}

/** Dez linhas por eixo, só para dar noção de escala. Não são as células: são ~10 delas por linha. */
function GradeDeFundo() {
  const passos = Array.from({ length: 9 }, (_, i) => ((i + 1) * LADO_SVG) / 10)

  return (
    <g stroke="var(--color-rust)" strokeOpacity={0.12} strokeWidth={1}>
      {passos.map((p) => (
        <line key={`v${p}`} x1={p} y1={0} x2={p} y2={LADO_SVG} />
      ))}
      {passos.map((p) => (
        <line key={`h${p}`} x1={0} y1={p} x2={LADO_SVG} y2={p} />
      ))}
    </g>
  )
}

/**
 * A mesma métrica do servidor: euclidiana, arredondada meio-para-cima (`MapaFertways::distancia`).
 * Duplicada aqui só para a tela; o frete e o tributo continuam sendo cobrados no servidor.
 */
function distancia(a: { x: number; y: number }, b: { x: number; y: number }): number {
  return Math.floor(Math.hypot(a.x - b.x, a.y - b.y) + 0.5)
}
