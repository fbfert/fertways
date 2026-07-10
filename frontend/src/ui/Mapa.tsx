import { useEffect, useState } from 'react'
import { api, ApiError } from '../api/client'
import type { ColoniaVizinha, Diretorio } from '../api/client'

/**
 * O mapa de Fertways: a Capital, a sua colônia e as vizinhas.
 *
 * Toda a geometria — lado da grade e posição da Capital — vem da API (`GET /colonies`), nunca de
 * constante daqui. A grade vai mudar (D-51: lado 101, Capital em (0,0), coordenadas com sinal), e
 * um 100 copiado para dentro deste arquivo sobreviveria à mudança desenhando um mapa errado sem
 * reclamar de nada.
 *
 * Não há névoa de guerra: o diretório lista todas as colônias, por decisão do usuário (D-37). Este
 * mapa é o desenho daquela lista, e a lista já era ordenada da mais próxima à mais distante — a
 * distância é o que decide o custo do frete e do tributo (§25.6).
 */

/** Lado do desenho, em px do sistema de coordenadas do SVG. */
const LADO_SVG = 1000

export function Mapa({ aoFechar }: { aoFechar: () => void }) {
  const [dir, setDir] = useState<Diretorio | null>(null)
  const [erro, setErro] = useState<string | null>(null)
  const [alvo, setAlvo] = useState<ColoniaVizinha | null>(null)

  useEffect(() => {
    api
      .colonias()
      .then(setDir)
      .catch((e: unknown) => setErro(e instanceof ApiError ? e.message : 'Falha ao carregar o mapa.'))
  }, [])

  /*
   * Da coordenada de jogo para a do SVG. `side` é o número de células; a célula `i` ocupa o
   * intervalo [i, i+1), então o centro dela é `i + 0,5`. Sem o meio, os marcadores encostam no
   * canto superior esquerdo da própria célula, e a Capital não fica no centro do desenho.
   *
   * O eixo Y é invertido: no SVG ele cresce para baixo, e num mapa cresce para cima.
   */
  const px = (v: number, side: number) => ((v + 0.5) / side) * LADO_SVG
  const py = (v: number, side: number) => LADO_SVG - ((v + 0.5) / side) * LADO_SVG

  return (
    <div className="fixed inset-0 z-20 flex items-center justify-center bg-ink/70 p-4">
      <div className="painel bg-sand-light max-h-[90vh] w-full max-w-5xl overflow-y-auto p-6">
        <header className="flex items-start justify-between">
          <div>
            <div className="text-rust eyebrow">Mapa</div>
            <h2 className="text-ink text-2xl font-black">Fertways</h2>
            {dir && (
              <p className="text-ink-soft mt-1 text-sm">
                Grade {dir.side}×{dir.side}. Capital em ({dir.capital.x}, {dir.capital.y}). Você em (
                {dir.me.x}, {dir.me.y}), a{' '}
                {distancia(dir.me, dir.capital)} slots dela.
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
            <div className="border-rust/20 bg-sand border">
              {/* `data-mapa` é a âncora do e2e: `svg circle` casaria com outros SVGs do HUD. */}
              <svg
                data-mapa
                viewBox={`0 0 ${LADO_SVG} ${LADO_SVG}`}
                className="block h-auto w-full"
              >
                <GradeDeFundo lado={LADO_SVG} />

                {/* A Capital. Losango, para não se confundir com colônia nenhuma. */}
                <g>
                  <rect
                    x={px(dir.capital.x, dir.side) - 9}
                    y={py(dir.capital.y, dir.side) - 9}
                    width={18}
                    height={18}
                    transform={`rotate(45 ${px(dir.capital.x, dir.side)} ${py(dir.capital.y, dir.side)})`}
                    fill="var(--color-rust)"
                  />
                  <title>Capital — Mercado Central</title>
                </g>

                {dir.colonies.map((c) => (
                  <circle
                    key={c.id}
                    cx={px(c.x, dir.side)}
                    cy={py(c.y, dir.side)}
                    r={alvo?.id === c.id ? 11 : 7}
                    fill={alvo?.id === c.id ? 'var(--color-rust-bright)' : 'var(--color-ink-soft)'}
                    className="cursor-pointer"
                    onClick={() => setAlvo(alvo?.id === c.id ? null : c)}
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
                  r={9}
                  fill="var(--color-ember)"
                  stroke="var(--color-ink)"
                  strokeWidth={2}
                >
                  <title>{dir.me.name} — você</title>
                </circle>

                {/* A reta até a colônia escolhida: o frete cobra por esta distância. */}
                {alvo && (
                  <line
                    x1={px(dir.me.x, dir.side)}
                    y1={py(dir.me.y, dir.side)}
                    x2={px(alvo.x, dir.side)}
                    y2={py(alvo.y, dir.side)}
                    stroke="var(--color-rust)"
                    strokeWidth={2}
                    strokeDasharray="8 6"
                  />
                )}
              </svg>
            </div>

            <div>
              <Legenda />

              {alvo ? (
                <div className="border-rust/25 bg-sand mt-4 border p-3">
                  <div className="text-rust eyebrow">{alvo.nickname}</div>
                  <div className="text-ink font-black">{alvo.name}</div>
                  <dl className="text-ink-soft mt-2 space-y-1 text-sm">
                    <Linha termo="Posição" valor={`(${alvo.x}, ${alvo.y})`} />
                    <Linha termo="Distância" valor={`${alvo.distance} slots`} />
                    <Linha termo="Porte" valor={`${alvo.building_levels_sum} níveis`} />
                  </dl>
                  {/*
                    "Porte" e não "Marco": `building_levels_sum` é a soma dos níveis das
                    construções, um sinal arbitrado (D-38). O Marco do GDD não existe ainda.
                  */}
                  <p className="text-ink-soft/70 mt-2 text-xs">
                    Porte é a soma dos níveis das construções — não é o Marco do GDD.
                  </p>
                </div>
              ) : (
                <p className="text-ink-soft mt-4 text-sm">
                  Clique numa colônia para ver a distância até ela.
                </p>
              )}

              <h3 className="text-ink eyebrow mt-5">
                {dir.colonies.length === 0
                  ? 'Nenhuma vizinha'
                  : `${dir.colonies.length} ${dir.colonies.length === 1 ? 'vizinha' : 'vizinhas'}`}
              </h3>

              <ul className="mt-2 space-y-1">
                {dir.colonies.map((c) => (
                  <li key={c.id}>
                    <button
                      onClick={() => setAlvo(alvo?.id === c.id ? null : c)}
                      className={`flex w-full items-baseline justify-between px-2 py-1 text-left text-sm ${
                        alvo?.id === c.id ? 'bg-rust text-sand-light' : 'text-ink-soft hover:bg-sand'
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
        Sua colônia
      </li>
      <li>
        <span className="bg-ink-soft mr-2 inline-block h-3 w-3 rounded-full align-middle" /> Vizinhas
      </li>
    </ul>
  )
}

/** Dez linhas por eixo, só para dar noção de escala. Não são as células: são 100 delas por linha. */
function GradeDeFundo({ lado }: { lado: number }) {
  const passos = Array.from({ length: 9 }, (_, i) => ((i + 1) * lado) / 10)

  return (
    <g stroke="var(--color-rust)" strokeOpacity={0.12} strokeWidth={1}>
      {passos.map((p) => (
        <line key={`v${p}`} x1={p} y1={0} x2={p} y2={lado} />
      ))}
      {passos.map((p) => (
        <line key={`h${p}`} x1={0} y1={p} x2={lado} y2={p} />
      ))}
    </g>
  )
}

/**
 * A mesma métrica do servidor: euclidiana, arredondada meio-para-cima (`MapaFertways::distancia`).
 * Duplicada aqui só para o cabeçalho, porque o diretório mede a distância de você até as vizinhas,
 * e não de você até a Capital.
 */
function distancia(a: { x: number; y: number }, b: { x: number; y: number }): number {
  return Math.floor(Math.hypot(a.x - b.x, a.y - b.y) + 0.5)
}
