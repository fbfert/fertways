import { useCallback, useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { api, ApiError } from '../api/client'
import type { Veiculo, ZonaDetalhe } from '../api/client'
import { dataHumana, nomeRecurso } from './recursos'

/**
 * A zona neutra como LUGAR (GDD §17.4; docs/decisoes.md D-67).
 *
 * **Planta com áreas, e não colmeia de slots** — a decisão é do usuário, e tem razão de ser: uma
 * muralha *deve* estar no perímetro, e uma grade de hexágonos não sabe disso. É o idioma da Capital
 * (D-63), não o da colônia (D-59). ⚠️ A planta **não está no GDD**; é arbitragem.
 *
 * **Desenhada em SVG, e não em Phaser** — de propósito. As cenas de Phaser da colônia e da Capital
 * não são testáveis pelo e2e (é um canvas: não tem DOM, não responde a `click` por seletor), e por
 * isso a receita da Oficina e a demolição ficaram sem cobertura (D-54, D-59). E o D-63 mostrou o
 * preço: os sete ministérios saíram **pálidos** na tela e os sete e2e passaram, porque os cliques
 * funcionavam e só o desenho mentia. Um SVG é DOM: o e2e o vê, o clica, e lê o que está escrito nele.
 *
 * Três coisas que o colono não tem como adivinhar, e que a tela existe para dizer:
 *
 *  1. **Só o que EXCEDE o Depósito é saqueável.** O que cabe nele está a salvo (D-66). Uma zona bem
 *     cuidada não tem butim nenhum — e é subindo o Depósito que se protege mais.
 *  2. **O material da obra tem de CHEGAR DE VEÍCULO.** O canteiro não se enche do estoque de casa:
 *     ele se enche de caminhão. Quem clica em "Construir" sem ter despachado nada leva um erro.
 *  3. **O Cemitério de Robôs não faz nada** — e é o próprio GDD que o declara "apenas visual".
 */

/**
 * A planta. Cada estrutura mora numa área da zona, e o lugar dela diz o que ela é.
 *
 * ⚠️ **A Muralha tem um CORPO, e não é a moldura.** A primeira versão a desenhou como o retângulo
 * do perímetro inteiro, com `fill="none"` — e um `<rect>` sem preenchimento **não recebe clique no
 * interior, só na borda**. O e2e clica no centro do elemento, o clique atravessava, e o painel da
 * Muralha nunca abria. (Uma moldura de `fill="transparent"` teria o problema oposto: engoliria os
 * cliques de tudo o que está dentro dela.)
 *
 * A moldura continua existindo — ela **engrossa** conforme a Muralha sobe de nível —, mas é
 * **desenho puro**, sem eventos. Quem se clica é o portão.
 */
const AREAS: Record<string, { x: number; y: number; w: number; h: number; rotulo: string }> = {
  // O portão, encravado na parede de baixo: é o corpo clicável da Muralha.
  muralha_de_perimetro: { x: 155, y: 258, w: 90, h: 22, rotulo: 'Muralha' },
  // O miolo: o Posto de Comando, sem o qual não há controle territorial (§17.4).
  posto_de_comando: { x: 160, y: 110, w: 80, h: 60, rotulo: 'Comando' },
  // A guarda, nos cantos altos: quem vê longe fica no alto.
  torre_de_vigia: { x: 30, y: 30, w: 70, h: 60, rotulo: 'Vigia' },
  bastiao: { x: 300, y: 30, w: 70, h: 60, rotulo: 'Bastião' },
  // A produção, embaixo: o que se extrai, o que se guarda, o que se refina.
  deposito_de_zona_neutra: { x: 30, y: 190, w: 90, h: 60, rotulo: 'Depósito' },
  refinaria_de_campo: { x: 145, y: 190, w: 110, h: 60, rotulo: 'Refinaria' },
  abrigo_de_robos: { x: 280, y: 190, w: 90, h: 60, rotulo: 'Abrigo' },
  // A logística e a memória, à margem.
  estacionamento_da_zona: { x: 30, y: 115, w: 60, h: 50, rotulo: 'Pátio' },
  cemiterio_de_robos: { x: 310, y: 115, w: 60, h: 50, rotulo: 'Cemitério' },
  // As três últimas do §17.4 (D-79) — inertes, sem sistema que as acione ainda. Faixa de cima,
  // entre as duas torres, onde havia espaço vazio na planta.
  estrutura_de_extracao: { x: 100, y: 30, w: 58, h: 60, rotulo: 'Extração' },
  central_de_comunicacao: { x: 171, y: 30, w: 58, h: 60, rotulo: 'Antena' },
  plataforma_de_pouso_da_zona: { x: 242, y: 30, w: 58, h: 60, rotulo: 'Pouso' },
}

export function Zona({ aoFechar }: { aoFechar: () => void }) {
  const { id } = useParams()
  const zonaId = Number(id)

  const [z, setZ] = useState<ZonaDetalhe | null>(null)
  const [frota, setFrota] = useState<Veiculo[]>([])
  const [sel, setSel] = useState<string | null>(null)
  const [erro, setErro] = useState<string | null>(null)
  const [recibo, setRecibo] = useState<string | null>(null)
  const [ocupado, setOcupado] = useState(false)
  const [envio, setEnvio] = useState<Record<string, number>>({})

  const carregar = useCallback(async () => {
    try {
      const [d, f] = await Promise.all([api.zona(zonaId), api.frota()])
      setZ(d)
      setFrota(f.vehicles)
      setErro(null)
    } catch (e) {
      setErro(e instanceof ApiError ? e.message : 'Falha ao carregar a zona.')
    }
  }, [zonaId])

  useEffect(() => {
    void carregar()
  }, [carregar])

  async function agir(acao: () => Promise<string>) {
    setOcupado(true)
    setErro(null)
    setRecibo(null)
    try {
      setRecibo(await acao())
      await carregar()
    } catch (e) {
      setErro(e instanceof ApiError ? e.message : 'Falha na operação.')
    } finally {
      setOcupado(false)
    }
  }

  const moldura = (dentro: React.ReactNode) => (
    <div className="bg-sand fixed inset-0 z-20 overflow-y-auto" data-tela="zona">
      <div className="bg-sand-light mx-auto min-h-screen w-full max-w-5xl p-6">
        <div className="mb-4 flex items-start justify-between">
          <div>
            <div className="text-rust eyebrow">Zona Neutra</div>
            <h2 className="text-2xl font-black">
              {z ? `(${z.x}, ${z.y}) — ${nomeRecurso(z.mineral)}` : 'Carregando…'}
            </h2>
          </div>
          <button
            onClick={aoFechar}
            data-fechar-zona
            className="text-ink-soft hover:text-rust text-2xl leading-none"
          >
            ×
          </button>
        </div>
        {dentro}
      </div>
    </div>
  )

  if (erro && !z) return moldura(<p className="text-rust text-sm font-bold">{erro}</p>)
  if (!z) return moldura(<p className="text-ink-soft text-sm">Carregando…</p>)

  const porTipo = new Map(z.estruturas.map((e) => [e.type, e]))
  const escolhida = sel ? porTipo.get(sel) : null
  const ociosos = frota.filter((v) => v.status === 'ocioso')
  // A parede engrossa com o nível da Muralha — é o único sinal que se lê de longe.
  const nivelMuralha = porTipo.get('muralha_de_perimetro')?.level ?? 0

  /** O canteiro tem com que pagar esta obra? A mesma conta que o servidor fará. */
  const canteiroPaga = (e: { proximo: { custo: Record<string, number> } | null }) =>
    e.proximo
      ? Object.entries(e.proximo.custo).every(
          ([r, q]) => (z.canteiro.find((c) => c.resource_type === r)?.amount ?? 0) >= q,
        )
      : false

  return moldura(
    <div className="space-y-6">
      {z.cercada && (
        <div className="border-rust bg-rust/10 border-l-4 p-3" data-cercada>
          <strong className="text-rust">A zona está CERCADA.</strong>
          <p className="text-sm">
            Nada entra nem sai: não se retira carga, não chega material, e{' '}
            <strong>não se constrói sob sítio</strong>. Rompa o cerco ou espere as 48 h.
          </p>
        </div>
      )}

      {/* ── a planta ──────────────────────────────────────────────────────────────────────── */}
      <div className="grid gap-6 lg:grid-cols-[400px_1fr]">
        <svg viewBox="0 0 400 280" className="w-full" role="group" aria-label="Planta da zona">
          <rect x="0" y="0" width="400" height="280" fill="var(--color-sand)" />

          {/*
            A parede. **Desenho puro, sem eventos**: ela engrossa conforme a Muralha sobe, e é o que
            se vê de longe — mas quem recebe o clique é o portão, lá embaixo. Um `<rect>` sem
            preenchimento só é clicável na borda, e um transparente engoliria tudo o que está dentro.
          */}
          <rect
            x={10}
            y={10}
            width={380}
            height={260}
            rx={6}
            fill="none"
            stroke={nivelMuralha > 0 ? 'var(--color-rust)' : 'var(--color-ink-soft)'}
            strokeWidth={nivelMuralha > 0 ? 2 + nivelMuralha * 1.5 : 1}
            strokeDasharray={nivelMuralha > 0 ? undefined : '5 4'}
            className="pointer-events-none"
          />

          {Object.entries(AREAS).map(([tipo, a]) => {
            const e = porTipo.get(tipo)
            const erguida = (e?.level ?? 0) > 0

            return (
              <g key={tipo}>
                <rect
                  x={a.x}
                  y={a.y}
                  width={a.w}
                  height={a.h}
                  rx={4}
                  // `transparent` e não `none`: um `fill="none"` não recebe clique no interior.
                  fill={erguida ? 'var(--color-ember)' : 'transparent'}
                  stroke={erguida ? 'var(--color-rust)' : 'var(--color-ink-soft)'}
                  strokeWidth={1.5}
                  strokeDasharray={erguida ? undefined : '4 3'}
                  opacity={e?.offline ? 0.35 : 1}
                  className="cursor-pointer"
                  onClick={() => setSel(tipo)}
                  data-area={tipo}
                />
                <text
                  x={a.x + a.w / 2}
                  y={a.y + a.h / 2 + 4}
                  textAnchor="middle"
                  className="pointer-events-none select-none text-[10px] font-bold"
                  fill={erguida ? 'var(--color-ink)' : 'var(--color-ink-soft)'}
                >
                  {a.rotulo}
                  {erguida ? ` ${e?.level}` : ''}
                </text>
              </g>
            )
          })}
        </svg>

        {/* ── o painel da estrutura escolhida ─────────────────────────────────────────────── */}
        <div>
          {!escolhida ? (
            <p className="text-ink-soft text-sm">
              Clique numa área da planta. As de traço interrompido ainda não foram erguidas.
            </p>
          ) : (
            <div className="painel bg-sand p-4" data-painel-estrutura={escolhida.type}>
              <h3 className="text-lg font-black">
                {escolhida.nome}{' '}
                <span className="text-ink-soft text-sm font-normal">
                  {escolhida.level > 0 ? `nível ${escolhida.level}` : 'não erguida'}
                </span>
              </h3>

              {escolhida.offline && (
                <p className="text-rust mt-1 text-sm font-bold">
                  ⚠ Fora de operação — sabotada ou apreendida. Precisa de reparo.
                </p>
              )}

              {/* As duas camadas, e elas não se confundem: o que o GDD promete e o que o jogo faz. */}
              <p className="text-ink-soft mt-2 text-xs italic">GDD: {escolhida.gdd}</p>
              <p className="mt-2 text-sm">{escolhida.hoje}</p>

              {escolhida.inerte && (
                <p className="text-ink-soft mt-2 text-xs">
                  Esta construção <strong>não faz nada</strong>, e é o próprio GDD que o diz. Erga-a
                  se quiser — é a única do jogo que se ergue só por gosto.
                </p>
              )}

              {!escolhida.construivel ? (
                <p className="text-ink-soft mt-3 text-xs">
                  Nasce com a ocupação e não se ergue nem se demole.
                </p>
              ) : escolhida.proximo ? (
                <div className="mt-3">
                  <div className="text-ink eyebrow">
                    Erguer ao nível {escolhida.proximo.level} — do canteiro
                  </div>
                  <ul className="text-ink-soft mt-1 text-sm">
                    {Object.entries(escolhida.proximo.custo).map(([r, q]) => {
                      const tem = z.canteiro.find((c) => c.resource_type === r)?.amount ?? 0

                      return (
                        <li key={r} className={tem < q ? 'text-rust' : ''}>
                          {nomeRecurso(r)}: {q} <span className="text-xs">(no canteiro: {tem})</span>
                        </li>
                      )
                    })}
                  </ul>
                  <p className="text-ink-soft mt-1 text-xs">
                    Leva {Math.round(escolhida.proximo.segundos / 3600)} h.
                  </p>

                  {/*
                    O botão **não se oferece quando o canteiro não paga** — e isso não é enfeite.
                    Antes, clicar sem material mandava a requisição, o servidor devolvia 422, e o
                    colono levava um erro que a tela já sabia de antemão. A guarda do domínio continua
                    lá (é ela que vale contra requisição forjada); esta só evita prometer o que não
                    se pode cumprir.
                  */}
                  <button
                    className="botao mt-2 w-full"
                    disabled={ocupado || z.obra !== null || z.cercada || !canteiroPaga(escolhida)}
                    data-construir={escolhida.type}
                    onClick={() =>
                      void agir(async () => {
                        await api.construirNaZona(z.id, escolhida.type)

                        return `${escolhida.nome} em obra.`
                      })
                    }
                  >
                    {z.obra
                      ? 'Já há uma obra em curso'
                      : z.cercada
                        ? 'Não se constrói sob sítio'
                        : !canteiroPaga(escolhida)
                          ? 'Falta material no canteiro'
                          : 'Construir'}
                  </button>
                </div>
              ) : (
                <p className="text-ink-soft mt-3 text-xs">No nível máximo.</p>
              )}
            </div>
          )}
        </div>
      </div>

      {/* ── o depósito, e o que a guerra pode levar ─────────────────────────────────────── */}
      <section className="painel bg-sand p-4" data-secao="deposito">
        <h3 className="font-bold">Depósito</h3>
        <div className="mt-2 grid gap-2 text-sm sm:grid-cols-2">
          <div>
            {nomeRecurso(z.mineral)}: <strong data-bruto>{z.deposito.bruto}</strong>
            <span className="text-ink-soft text-xs"> · extrai {z.extracao_hora}/h</span>
          </div>
          {z.deposito.refinado_recurso && (
            <div>
              {nomeRecurso(z.deposito.refinado_recurso)}:{' '}
              <strong data-refinado>{z.deposito.refinado}</strong>
              <span className="text-ink-soft text-xs">
                {' '}
                · {z.refino_hora > 0 ? `refina ${z.refino_hora}/h` : 'sem Refinaria'}
              </span>
            </div>
          )}
        </div>

        <div className="mt-3 text-sm">
          <div>
            Protegido do saque: <strong>{z.deposito.protegido}</strong> de{' '}
            {z.deposito.capacidade} de capacidade
          </div>
          <div className={z.deposito.exposto > 0 ? 'text-rust font-bold' : 'text-ink-soft'}>
            Exposto ao saque: <span data-exposto>{z.deposito.exposto}</span>
          </div>
        </div>

        <p className="text-ink-soft mt-2 text-xs">
          <strong>Só o que EXCEDE o Depósito pode ser saqueado.</strong> O que cabe nele está a
          salvo. Suba o Depósito para proteger mais — ou retire a carga antes que alguém venha
          buscá-la.
        </p>
      </section>

      {/* ── o canteiro de obras ─────────────────────────────────────────────────────────── */}
      <section className="painel bg-sand p-4" data-secao="canteiro">
        <h3 className="font-bold">Canteiro de obras</h3>
        <p className="text-ink-soft mt-1 text-sm">
          <strong>O material das obras chega de veículo.</strong> Não sai do estoque de casa por
          mágica — a zona fica a {' '}
          <em>slots</em> de distância, e tudo o que é físico viaja. A sobra fica no canteiro para a
          próxima obra.
        </p>

        {z.canteiro.length === 0 ? (
          <p className="text-ink-soft mt-2 text-sm">Vazio. Despache um veículo com material.</p>
        ) : (
          <ul className="mt-2 text-sm">
            {z.canteiro.map((c) => (
              <li key={c.resource_type} data-canteiro={c.resource_type}>
                {nomeRecurso(c.resource_type)}: <strong>{c.amount}</strong>
              </li>
            ))}
          </ul>
        )}

        {z.obra && (
          <p className="mt-2 text-sm">
            Em obra: <strong>{z.obra.nome}</strong> nível {z.obra.target_level} — pronta{' '}
            {dataHumana(z.obra.finishes_at)}.
          </p>
        )}

        {/* Enviar material */}
        {ociosos.length === 0 ? (
          <p className="text-ink-soft mt-3 text-xs">Nenhum veículo ocioso para levar material.</p>
        ) : (
          <div className="mt-3">
            <div className="text-ink eyebrow">Enviar material (de {ociosos[0].type})</div>
            <div className="mt-1 grid gap-2 sm:grid-cols-3">
              {['metal_bruto', 'ligas_metalicas', 'componentes_eletronicos'].map((r) => (
                <label key={r} className="text-sm">
                  {nomeRecurso(r)}
                  <input
                    type="number"
                    min={0}
                    value={envio[r] ?? 0}
                    onChange={(e) =>
                      setEnvio((v) => ({ ...v, [r]: Math.max(0, Number(e.target.value)) }))
                    }
                    data-enviar={r}
                    className="border-rust/25 bg-sand-light focus:border-rust mt-1 w-full border px-2 py-1 outline-none"
                  />
                </label>
              ))}
            </div>

            <button
              className="botao mt-2 w-full"
              disabled={ocupado || z.cercada || Object.values(envio).every((q) => !q)}
              data-despachar-material
              onClick={() =>
                void agir(async () => {
                  const carga = Object.fromEntries(
                    Object.entries(envio).filter(([, q]) => q > 0),
                  )
                  await api.entregarMaterial(z.id, ociosos[0].id, carga)
                  setEnvio({})

                  return 'Veículo a caminho da zona com o material.'
                })
              }
            >
              Despachar material
            </button>
          </div>
        )}
      </section>

      {/* ── a guarnição ─────────────────────────────────────────────────────────────────── */}
      <section className="painel bg-sand p-4" data-secao="guarnicao">
        <h3 className="font-bold">Guarnição</h3>
        <p className="mt-1 text-sm">
          {z.guarnicao.robos} Robôs Mineradores · {z.guarnicao.sentinelas} Sentinelas ·{' '}
          <strong>{z.guarnicao.defesa}</strong> pontos de defesa
        </p>
        <p className="text-ink-soft mt-1 text-xs">
          O bônus da Muralha, da Torre e do Bastião multiplica isto. Sem eles, a zona defende com o
          que tem, e nada mais.
        </p>
      </section>

      {/* ── o que o GDD promete e o jogo não tem ────────────────────────────────────────── */}
      {Object.keys(z.ausentes).length > 0 && (
        <section data-secao="ausentes">
          <h3 className="font-bold">O que ainda não existe</h3>
          <p className="text-ink-soft mt-1 text-xs">
            O §17.4 lista estas estruturas. O jogo não as tem — e dizer isso é melhor do que fingir
            que elas não foram prometidas.
          </p>
          <ul className="mt-2 space-y-2">
            {Object.entries(z.ausentes).map(([k, a]) => (
              <li key={k} className="text-sm" data-ausente={k}>
                <strong>{a.nome}</strong>{' '}
                <span className="text-ink-soft text-xs">— {a.porque}</span>
              </li>
            ))}
          </ul>
        </section>
      )}

      {erro && <p className="text-rust text-sm font-bold">{erro}</p>}
      {recibo && <p className="text-sm font-bold">{recibo}</p>}
    </div>,
  )
}
