import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import { api } from '../api/client'
import type { DestrocoNoMapa } from '../api/client'
import { carregarArte } from '../game/arte'
import type { Arte } from '../game/arte'
import { ControlesDeZoom } from '../game/ControlesDeZoom'
import { transformar, useVista } from '../game/vista'
import { rotuloDoLote } from './loteDeEvento'
import { prazoHumano, segundosRestantes } from './recursos'

/**
 * O campo de destroços da Endurance (D-132) — as 8 seções do casco, espalhadas e clicáveis.
 *
 * **DOM puro, não Phaser.** As oito imagens já existem como arte de verdade (D-132, importadas do
 * lote `structures.zip`); não há vetor para desenhar nem textura para carregar. A câmera é a MESMA
 * das outras cenas (`useVista()` — roda do mouse, arrastar, botões −/+/centralizar), só que aplicada
 * a `<img>` posicionadas por CSS em vez de a um canvas: um botão real, testável, com foco e
 * `aria-label` — o problema que fez os sete ministérios da Capital "saírem pálidos" no e2e (D-63)
 * não existe aqui porque não há canvas nenhum no meio.
 *
 * Posições normalizadas (0..1), arbitradas — o GDD não desenha uma planta dos destroços.
 */
const SECOES: { chave: string; nome: string; cx: number; cy: number }[] = [
  { chave: 'comando', nome: 'Comando', cx: 0.5, cy: 0.16 },
  { chave: 'anel_habitacional', nome: 'Anel Habitacional', cx: 0.18, cy: 0.32 },
  { chave: 'matriz_comunicacao', nome: 'Matriz de Comunicação', cx: 0.8, cy: 0.3 },
  { chave: 'baia_criogenica', nome: 'Baía Criogênica', cx: 0.14, cy: 0.62 },
  { chave: 'modulo_medico', nome: 'Módulo Médico', cx: 0.5, cy: 0.52 },
  { chave: 'secao_acoplagem', nome: 'Seção de Acoplagem', cx: 0.86, cy: 0.62 },
  { chave: 'silo_suprimentos', nome: 'Silo de Suprimentos', cx: 0.3, cy: 0.86 },
  { chave: 'nucleo_propulsao', nome: 'Núcleo de Propulsão', cx: 0.7, cy: 0.86 },
]

/**
 * O esqueleto do casco (A2.V5, D-274): o anel externo e a espinha até o Comando. As oito seções
 * eram oito ilhas soltas na areia; ligadas, leem como UMA nave partida — e "exploração por seções"
 * pede justamente saber que elas são partes da mesma coisa. Arbitrado, como as posições.
 */
const CASCO: [string, string][] = [
  ['comando', 'matriz_comunicacao'],
  ['matriz_comunicacao', 'secao_acoplagem'],
  ['secao_acoplagem', 'nucleo_propulsao'],
  ['nucleo_propulsao', 'silo_suprimentos'],
  ['silo_suprimentos', 'baia_criogenica'],
  ['baia_criogenica', 'anel_habitacional'],
  ['anel_habitacional', 'comando'],
  ['comando', 'modulo_medico'],
  ['modulo_medico', 'nucleo_propulsao'],
  ['modulo_medico', 'silo_suprimentos'],
]

export function EnduranceMapa({
  aoAbrirLoja,
}: {
  aoAbrirLoja: (secao: string, nome: string) => void
}) {
  const [arte, setArte] = useState<Arte>({})
  /*
   * O que cada destroço guarda (D-246). Falha em silêncio de propósito: o mapa é navegação, e um
   * erro de carga não pode impedir o jogador de entrar numa loja — ele só volta a ser oito portas.
   */
  const [conteudo, setConteudo] = useState<Record<string, DestrocoNoMapa>>({})
  const [tamanho, setTamanho] = useState({ largura: 0, altura: 0 })

  useEffect(() => {
    let vivo = true
    api
      .enduranceMapa()
      .then((r) => {
        if (!vivo) return
        setConteudo(Object.fromEntries(r.secoes.map((s) => [s.chave, s])))
      })
      .catch(() => {})

    return () => {
      vivo = false
    }
  }, [])
  const container = useRef<HTMLDivElement>(null)
  const { vista, alvo, ampliar, centralizar, arrastando, gestos } = useVista()

  useEffect(() => {
    void carregarArte().then(setArte)
  }, [])

  // Mesmo padrão do `CapitalCanvas`: mede o contêiner e reage ao redimensionar, para a câmera e os
  // botões partilharem exatamente a mesma caixa.
  useLayoutEffect(() => {
    const el = container.current
    if (!el) return

    const medir = () => setTamanho({ largura: el.clientWidth, altura: el.clientHeight })
    medir()

    const observador = new ResizeObserver(medir)
    observador.observe(el)

    return () => observador.disconnect()
  }, [])

  // Numa caixa estreita (celular), as seções das pontas chegam para o meio e encolhem: com 110 px
  // fixos e a posição das pontas, "Seção de Acoplagem" saía cortada pela borda (D-268).
  const estreita = tamanho.largura < 480
  const ponto = (chave: string): [number, number] => {
    const s = SECOES.find((x) => x.chave === chave)!
    const cx = estreita ? 0.5 + (s.cx - 0.5) * 0.8 : s.cx

    return transformar(cx * tamanho.largura, s.cy * tamanho.altura, tamanho.largura, tamanho.altura, vista)
  }

  return (
    <div
      ref={(el) => {
        container.current = el
        alvo.current = el
      }}
      {...gestos}
      className="border-rust/20 relative h-full w-full overflow-hidden border"
      style={{ cursor: arrastando ? 'grabbing' : 'grab', touchAction: 'none' }}
      data-mapa-endurance
      data-tema="casco"
    >
      {/* No canto de cima à esquerda: à direita, cobriam o Comando e a Matriz no celular (D-268). */}
      <ControlesDeZoom vista={vista} ampliar={ampliar} centralizar={centralizar} canto="top-3 left-3" />

      {tamanho.largura > 0 && (
        <svg
          className="pointer-events-none absolute inset-0"
          width={tamanho.largura}
          height={tamanho.altura}
          aria-hidden="true"
          data-casco
        >
          {CASCO.map(([a, b]) => {
            const [x1, y1] = ponto(a)
            const [x2, y2] = ponto(b)

            return (
              <line
                key={`${a}-${b}`}
                x1={x1}
                y1={y1}
                x2={x2}
                y2={y2}
                stroke="var(--color-hairline-forte)"
                strokeWidth={2 * vista.escala}
                strokeDasharray={`${6 * vista.escala} ${4 * vista.escala}`}
              />
            )
          })}
        </svg>
      )}

      {tamanho.largura > 0 &&
        SECOES.map((s) => {
          const [x, y] = ponto(s.chave)
          const lado = (estreita ? Math.min(110, tamanho.largura * 0.17) : 110) * vista.escala
          const urls = arte[`endurance:secao:${s.chave}`]

          return (
            <button
              key={s.chave}
              onClick={() => aoAbrirLoja(s.chave, s.nome)}
              aria-label={`${s.nome} — abrir a Loja de Peças`}
              title={s.nome}
              data-destroco={s.chave}
              style={{
                position: 'absolute',
                left: x - lado / 2,
                top: y - lado / 2,
                width: lado,
                height: lado,
                background: 'transparent',
                border: 'none',
                padding: 0,
                cursor: 'pointer',
              }}
              className="focus-visible:ring-rust group flex flex-col items-center justify-center gap-1 focus-visible:ring-2 focus-visible:outline-none"
            >
              {urls ? (
                <img
                  src={urls.pequena}
                  alt=""
                  className="pointer-events-none shrink-0 drop-shadow-md transition-transform group-hover:scale-110"
                  style={{ width: lado, height: lado, objectFit: 'contain' }}
                />
              ) : (
                <div
                  className="border-rust/40 bg-sand-light/70 pointer-events-none shrink-0 rounded-full border-2 border-dashed transition-transform group-hover:scale-110"
                  style={{ width: lado * 0.7, height: lado * 0.7 }}
                />
              )}
              {/*
                No celular o nome quebra em duas linhas: com o progresso da seção embaixo (D-274),
                "Silo de Suprimentos" e "Núcleo de Propulsão" se encostavam na linha de baixo.
              */}
              <span
                className={`bg-sand-light/90 text-ink pointer-events-none px-1 text-[0.65rem] leading-tight font-bold ${
                  estreita ? 'text-center whitespace-normal' : 'whitespace-nowrap'
                }`}
                style={estreita ? { maxWidth: Math.max(lado, 84) } : undefined}
              >
                {s.nome}
              </span>

              {/*
               * ⚠️ **O que o destroço guarda** (D-246).
               *
               * O mapa desenhava oito portas iguais. Enquanto o catálogo tinha um item isso não
               * custava nada; cheio (D-244/D-245), achar a peça única exigia **abrir as oito** e
               * voltar — e o §11.2 quer que a Endurance ganhe importância, o que um mapa que não
               * distingue os próprios destroços não deixa acontecer.
               *
               * O `◈` da peça única vem acompanhado da palavra no `title`: cor e símbolo nunca são
               * o único sinal (regra da A2.V1). Seção sem nada à venda diz **esgotado**, e não "0
               * peças" — é a diferença entre uma porta vazia e uma porta que já foi esvaziada.
               */}
              {conteudo[s.chave] ? (
                <span
                  className="bg-sand-light/90 text-ink-soft pointer-events-none px-1 text-[0.6rem] whitespace-nowrap"
                  data-destroco-conteudo={s.chave}
                  title={
                    (conteudo[s.chave].tem_unico
                      ? `${conteudo[s.chave].pecas} peças à venda, uma delas única`
                      : `${conteudo[s.chave].pecas} peças à venda`) +
                    (conteudo[s.chave].a_escavar > 0
                      ? `; ${conteudo[s.chave].a_escavar} tipo(s) de peça para achar escavando`
                      : '')
                  }
                >
                  {conteudo[s.chave].pecas === 0 && !conteudo[s.chave].a_escavar ? (
                    'esgotado'
                  ) : (
                    <>
                      {conteudo[s.chave].pecas > 0 &&
                        `${conteudo[s.chave].pecas} peça${conteudo[s.chave].pecas > 1 ? 's' : ''}`}
                      {conteudo[s.chave].tem_unico ? (
                        <span className="text-ember font-black"> ◈</span>
                      ) : null}
                      {/*
                       * D-249: o que ainda se acha escavando. Em PALAVRA, e não com "⛏": a foto
                       * mostrou o símbolo virando um quadrado vazio — a fonte do jogo não o tem.
                       */}
                      {conteudo[s.chave].a_escavar > 0 && (
                        <span data-destroco-escavar={s.chave}>
                          {conteudo[s.chave].pecas > 0 ? ' · ' : ''}
                          {conteudo[s.chave].a_escavar} a escavar
                        </span>
                      )}
                    </>
                  )}
                </span>
              ) : null}

              {/*
               * D-274: a exploração da seção — quantas peças daqui a colônia já tirou. Some quando a
               * seção não tem catálogo nenhum: "você tem 0 de 0" é ruído.
               */}
              {conteudo[s.chave] && conteudo[s.chave].catalogo > 0 && (
                <span
                  className={`pointer-events-none px-1 text-micro whitespace-nowrap ${
                    conteudo[s.chave].minhas === conteudo[s.chave].catalogo
                      ? 'bg-ember text-sand-light font-bold'
                      : 'bg-sand-light/90 text-ink-soft'
                  }`}
                  data-destroco-minhas={s.chave}
                  title={`Você tem ${conteudo[s.chave].minhas} dos ${conteudo[s.chave].catalogo} tipos de peça desta seção`}
                >
                  {conteudo[s.chave].minhas === conteudo[s.chave].catalogo
                    ? 'seção completa'
                    : `você tem ${conteudo[s.chave].minhas} de ${conteudo[s.chave].catalogo}`}
                </span>
              )}

              {conteudo[s.chave]?.lote_de_evento && (
                <span
                  className="bg-ember text-sand-light pointer-events-none px-1 text-micro font-bold whitespace-nowrap"
                  data-destroco-evento={s.chave}
                  title={rotuloDoLote(conteudo[s.chave].lote_de_evento!)}
                >
                  evento · {prazoHumano(segundosRestantes(conteudo[s.chave].lote_de_evento!.ate))}
                </span>
              )}
            </button>
          )
        })}
    </div>
  )
}
