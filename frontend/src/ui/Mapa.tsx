import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { api, ApiError } from '../api/client'
import type {
  ColoniaVizinha,
  Diretorio,
  EstadoDaGuerra,
  TipoDeAtaque,
  Veiculo,
  ZonaNeutra,
} from '../api/client'
import { CelulaSobOCursor, Faixas, Grade, Planeta, Reguas } from './Grade'
import { InfoJogador } from './InfoJogador'
import {
  JANELA_PADRAO,
  LADO_SVG,
  calhaDe,
  celulaEm,
  celulasNaJanela,
  comFolga,
  passoDaGrade,
  pontoNoSvg,
  projecaoDoPlaneta,
  totalComReguas,
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

type Selecao =
  | { tipo: 'colonia'; c: ColoniaVizinha }
  | { tipo: 'zona'; z: ZonaNeutra }
  | null

export function Mapa({
  aoFechar,
  aoAbrirCapital,
  aoAbrirChatPrivado,
}: {
  aoFechar: () => void
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

  // Nasce nula: o enquadramento inicial depende de onde é a sua colônia, e isso só se sabe quando
  // o diretório chega.
  const [vista, setVista] = useState<Vista | null>(null)
  const [pegando, setPegando] = useState(false)
  // A célula sob o cursor. Só realce e leitura: célula vazia não é alvo de clique (D-64).
  const [cursor, setCursor] = useState<{ x: number; y: number } | null>(null)
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

  // Zoom pela roda do mouse, ancorado no cursor. Nativo e não-passivo para segurar o scroll do
  // modal enquanto se aproxima o mapa; o React registra onWheel como passivo e não deixaria.
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
        if (!v) return v
        const escala = limitarEscala(v.scale * fator)
        if (escala === v.scale) return v

        // O ponto sob o cursor tem de ficar parado: resolvo por ele o novo canto, e daí o centro.
        // A conta inclui a calha — o viewBox é maior que o mapa, e ignorá-la desviaria o zoom.
        const lado = LADO_SVG / escala
        const g = calhaDe(lado)
        const total = totalComReguas(lado)
        const x0 = p.x - fx * total + g
        const y0 = p.y - fy * total + g

        return { cx: limitarCentro(x0 + lado / 2), cy: limitarCentro(y0 + lado / 2), scale: escala }
      })
    }

    svg.addEventListener('wheel', aoRolar, { passive: false })

    return () => svg.removeEventListener('wheel', aoRolar)
  }, [dir])

  const zoom = (fator: number) =>
    setVista((v) => (v ? { ...v, scale: limitarEscala(v.scale * fator) } : v))

  // Arrastar para mover o mapa. Sem setPointerCapture (ele desviaria o `click` da zona para o SVG):
  // ouço o move/up na janela e desligo no fim. O limiar de 4 px separa clique de arraste.
  const iniciarArrasto = (e: React.PointerEvent<SVGSVGElement>) => {
    if (e.button !== 0 || !vista) return
    const r = e.currentTarget.getBoundingClientRect()
    const lado = LADO_SVG / vista.scale
    // O elemento cobre o mapa MAIS a calha: é essa a largura que o arraste percorre.
    const largura = totalComReguas(lado)
    const inicio = { cx: vista.cx, cy: vista.cy, clientX: e.clientX, clientY: e.clientY }
    arrastou.current = false
    setPegando(true)

    const mover = (ev: PointerEvent) => {
      const dx = ((ev.clientX - inicio.clientX) / r.width) * largura
      const dy = ((ev.clientY - inicio.clientY) / r.height) * largura
      if (!arrastou.current && Math.hypot(ev.clientX - inicio.clientX, ev.clientY - inicio.clientY) > 4) {
        arrastou.current = true
      }
      setVista((v) =>
        v ? { ...v, cx: limitarCentro(inicio.cx - dx), cy: limitarCentro(inicio.cy - dy) } : v,
      )
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
      <div className="bg-sand fixed inset-0 z-20 overflow-y-auto">
      <div className="bg-sand-light mx-auto min-h-screen w-full max-w-5xl p-6">
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

        {dir && vista && (
          <div className="mt-5 grid gap-6 md:grid-cols-[1fr_18rem]">
            <div className="border-rust/20 bg-sand relative border">
              {/* Ferramentas de zoom e foco. Ficam sobre o mapa, ABAIXO da régua de X — no topo,
                  elas tapavam o número da última coluna. */}
              <div className="absolute right-1 top-12 z-10 flex flex-col gap-1">
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

              <Desenho
                svgRef={svgRef}
                dir={dir}
                proj={proj}
                vista={vista}
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

            <div>
              <Legenda />

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

              {!selecao && (
                <p className="text-ink-soft mt-4 text-sm">
                  O mapa abre em 15×15, centrado em você. Clique numa colônia ou numa zona neutra.
                  Arraste para mover, role o mouse ou use + / − para o zoom; o botão da mira devolve
                  o enquadramento. As zonas ficam nos cantos: para chegar a elas, afaste.
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
        )}
      </div>
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
  svgRef: React.RefObject<SVGSVGElement | null>
  dir: Diretorio
  proj: Projecao
  vista: Vista
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
  const lado = LADO_SVG / vista.scale
  const caixa: Caixa = { x0: vista.cx - lado / 2, y0: vista.cy - lado / 2, lado }

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
      className={`block h-auto w-full touch-none select-none ${
        pegando ? 'cursor-grabbing' : 'cursor-grab'
      }`}
    >
      <defs>
        {/* Recorta o desenho na janela: sem isto, o planeta invadiria a calha das réguas. */}
        <clipPath id={recorte}>
          <rect x={caixa.x0} y={caixa.y0} width={lado} height={lado} />
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

      {/* De outro colono: o interior é névoa (D-74) — o Drone é o único olho que a atravessa. */}
      {z.owner && !z.mine && (
        <div className="border-rust/20 mt-3 border-t pt-2" data-intel={z.intel}>
          <dl className="text-ink-soft space-y-1 text-sm">
            <Linha termo="Guarnição" valor={z.garrison === null ? '?' : `${z.garrison} robôs`} />
            <Linha termo="Depósito" valor={z.deposit_amount === null ? '?' : `${z.deposit_amount}`} />
          </dl>
          <p className="text-ink-soft/70 mt-1 text-xs">
            {z.intel === 'ao_vivo' && 'Um Drone seu sobrevoa a região: transmissão ao vivo.'}
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
 *    cuidada não tem butim nenhum. Atacá-la é gastar exército de graça.
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
        <option value="invasao">Invasão — toma a zona e saqueia 50% do exposto</option>
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
