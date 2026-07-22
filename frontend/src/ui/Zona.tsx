import { useCallback, useEffect, useMemo, useState } from 'react'
import { useParams } from 'react-router-dom'
import { api, ApiError } from '../api/client'
import type { EstadoDaGuerra, EstruturaDaZona, EventoDaZona, Veiculo, ZonaDetalhe } from '../api/client'
import { dataHumana, nomeRecurso, nomeVeiculo } from './recursos'

/**
 * A zona neutra como LUGAR (GDD §17.4; docs/decisoes.md D-67, D-84, D-86, D-144).
 *
 * **Colmeia de slots, e não mais planta por áreas fixas (D-144).** Até aqui cada estrutura morava
 * numa área com posição fixa e sentido físico (a Muralha "tinha" que estar no perímetro). O usuário
 * pediu mecanismos de crescimento e visual como os da colônia; a decisão foi trazer a MESMA
 * geometria da colmeia da colônia (`Domain\Colony\Slots`: linhas 4/4/5/4/4/1, 22 slots, o Posto de
 * Comando no centro — como o Depósito Local da colônia, D-142) e dois mecanismos novos que a
 * colônia não tem: os slots se DESBLOQUEIAM com o nível da zona (`Domain\Zona\ZonaSlots`), e três
 * estruturas passam a ser REPETÍVEIS (`Estruturas::REPETIVEIS` — Refinaria, Indústria Siderúrgica,
 * Estrutura de Extração), cada cópia num slot com nível próprio.
 *
 * **Desenhada em SVG, e não em Phaser — isso NÃO mudou.** A decisão de manter SVG é anterior e
 * continua valendo pela mesma razão: as cenas de Phaser da colônia e da Capital não são testáveis
 * pelo e2e (é um canvas: não tem DOM, não responde a `click` por seletor), e isso já custou
 * cobertura real (D-54, D-63). A matemática da colmeia é a mesma da colônia — `centrosDaColmeia()`,
 * abaixo, é um port da mesma proporção de `game/ColonyScene.ts:colmeia()`, sem zoom/pan (não
 * precisamos: a planta da zona não se move) — só a TECNOLOGIA de desenho continua sendo DOM.
 *
 * **Cinco abas (D-86)** seguem exatamente como eram: Zona Neutra (identidade, colmeia, upgrade),
 * Depósito, Canteiro de obras, Guarnição e Histórico.
 */

/** Hexágono "pontudo em cima" — mesma orientação de `ColonyScene.hexPontos()`. */
function pontosDoHexagono(cx: number, cy: number, r: number): string {
  return Array.from({ length: 6 }, (_, i) => {
    const a = ((60 * i - 90) * Math.PI) / 180
    return `${cx + r * Math.cos(a)},${cy + r * Math.sin(a)}`
  }).join(' ')
}

/**
 * O centro de cada slot da colmeia, em pixels do viewBox — port de `game/ColonyScene.ts:colmeia()`,
 * sem `vista`/zoom/pan (a planta da zona é estática; não há câmera para mover).
 */
function centrosDaColmeia(linhas: number[], largura: number, altura: number) {
  const folga = 1.12
  const base = Math.min(largura / 12, altura / 10)
  const passoX = Math.sqrt(3) * base * folga
  const passoY = 1.5 * base * folga
  const meioY = ((linhas.length - 1) * passoY) / 2

  const centros: { x: number; y: number }[] = []

  linhas.forEach((quantas, linha) => {
    const inicio = -((quantas - 1) * passoX) / 2

    for (let i = 0; i < quantas; i++) {
      centros.push({
        x: largura / 2 + inicio + i * passoX,
        y: altura / 2 + linha * passoY - meioY,
      })
    }
  })

  return { r: base, centros }
}

type Aba = 'zona' | 'deposito' | 'canteiro' | 'guarnicao' | 'historico'

const ABAS: { id: Aba; rotulo: string }[] = [
  { id: 'zona', rotulo: 'Zona Neutra' },
  { id: 'deposito', rotulo: 'Depósito' },
  { id: 'canteiro', rotulo: 'Canteiro de obras' },
  { id: 'guarnicao', rotulo: 'Guarnição' },
  { id: 'historico', rotulo: 'Histórico' },
]

export function Zona() {
  const { id } = useParams()
  const zonaId = Number(id)

  const [aba, setAba] = useState<Aba>('zona')
  const [z, setZ] = useState<ZonaDetalhe | null>(null)
  const [frota, setFrota] = useState<Veiculo[]>([])
  // O slot escolhido na colmeia — ocupado, vazio-desbloqueado ou trancado (D-144).
  const [sel, setSel] = useState<number | null>(null)
  // Quando o slot escolhido está vazio: qual tipo do catálogo o colono quer erguer ali.
  const [tipoEscolhido, setTipoEscolhido] = useState<string | null>(null)
  const [erro, setErro] = useState<string | null>(null)
  const [recibo, setRecibo] = useState<string | null>(null)
  const [ocupado, setOcupado] = useState(false)
  const [envio, setEnvio] = useState<Record<string, number>>({})
  const [envioVeiculoId, setEnvioVeiculoId] = useState<number | null>(null)
  const [retirar, setRetirar] = useState<Record<string, number>>({})
  const [nome, setNome] = useState('')

  // Demolir (D-138): a mesma confirmação por escrito que a colônia já exige (D-61).
  const [confirmandoDemolicao, setConfirmandoDemolicao] = useState(false)
  const [palavraDemolicao, setPalavraDemolicao] = useState('')

  useEffect(() => {
    setConfirmandoDemolicao(false)
    setPalavraDemolicao('')
    setTipoEscolhido(null)
  }, [sel])

  // Guarnição: os defensores em casa, para o reforço (D-70, trazido para dentro da zona no D-86).
  const [guerra, setGuerra] = useState<EstadoDaGuerra | null>(null)
  const [reforcoQtd, setReforcoQtd] = useState(1)

  // Histórico (D-86): carregado só quando a aba abre — é a única que pede um segundo request.
  const [eventos, setEventos] = useState<EventoDaZona[] | null>(null)
  const [carregandoHistorico, setCarregandoHistorico] = useState(false)

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

  // Só sincroniza ao TROCAR de zona — carregar() roda de novo a cada ação (construir, despachar),
  // e ressincronizar sempre apagaria o que o colono estivesse digitando no campo de nome.
  useEffect(() => {
    setNome(z?.name ?? '')
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [z?.id])

  useEffect(() => {
    if (aba === 'guarnicao' && !guerra) {
      void api.guerra().then(setGuerra).catch(() => {})
    }
  }, [aba, guerra])

  useEffect(() => {
    if (aba === 'historico' && eventos === null && !carregandoHistorico) {
      setCarregandoHistorico(true)
      api
        .historicoDaZona(zonaId)
        .then((r) => setEventos(r.eventos))
        .catch((e) => setErro(e instanceof ApiError ? e.message : 'Falha ao carregar o histórico.'))
        .finally(() => setCarregandoHistorico(false))
    }
  }, [aba, eventos, carregandoHistorico, zonaId])

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
      <div className="bg-sand-light mx-auto min-h-screen w-full max-w-5xl px-6 pt-20 pb-24 md:pt-28 md:pb-6">
        <div className="mb-4">
          <div className="text-rust eyebrow">Zona Neutra</div>
          {z ? (
            <>
              <div className="flex flex-wrap items-center gap-2">
                <input
                  type="text"
                  value={nome}
                  onChange={(e) => setNome(e.target.value)}
                  placeholder={`(${z.x}, ${z.y})`}
                  maxLength={120}
                  data-nome-zona
                  className="text-ink border-rust/25 focus:border-rust bg-transparent border-b text-2xl font-black outline-none"
                />
                {nome.trim() !== (z.name ?? '') && (
                  <button
                    className="botao text-xs"
                    data-salvar-nome-zona
                    disabled={ocupado}
                    onClick={() =>
                      void agir(async () => {
                        const r = await api.renomearZona(z.id, nome.trim())
                        setNome(r.name ?? '')

                        return r.name
                          ? `Zona renomeada para "${r.name}".`
                          : 'Nome removido — a zona volta a mostrar as coordenadas.'
                      })
                    }
                  >
                    Salvar nome
                  </button>
                )}
              </div>
              <div className="text-ink-soft text-sm">
                ({z.x}, {z.y}) — {nomeRecurso(z.mineral)} · nível {z.level}
              </div>
            </>
          ) : (
            <h2 className="text-2xl font-black">Carregando…</h2>
          )}
        </div>

        {z && (
          <div className="border-rust/20 mb-4 flex flex-wrap gap-1 border-b text-sm">
            {ABAS.map((a) => (
              <button
                key={a.id}
                onClick={() => setAba(a.id)}
                data-aba-zona={a.id}
                className={`px-3 py-2 font-bold ${
                  aba === a.id ? 'border-rust text-rust border-b-2' : 'text-ink-soft hover:text-rust'
                }`}
              >
                {a.rotulo}
              </button>
            ))}
          </div>
        )}

        {dentro}
      </div>
    </div>
  )

  if (erro && !z) return moldura(<p className="text-rust text-sm font-bold">{erro}</p>)
  if (!z) return moldura(<p className="text-ink-soft text-sm">Carregando…</p>)

  const estr = z.estruturas
  const porSlot = new Map(estr.erguidas.map((e) => [e.slot, e]))
  const erguidaEscolhida = sel !== null ? (porSlot.get(sel) ?? null) : null
  const slotTrancado = sel !== null && !erguidaEscolhida && !estr.colmeia.desbloqueados.includes(sel)
  const emObraNesteSlot = sel !== null ? z.obras.some((o) => o.slot === sel) : false
  const catalogoDisponivel = estr.catalogo.filter((c) => c.disponivel)
  const tipoDoCatalogo = tipoEscolhido ? estr.catalogo.find((c) => c.type === tipoEscolhido) : null
  const ociosos = frota.filter((v) => v.status === 'ocioso')

  /** O canteiro tem com que pagar este custo? A mesma conta que o servidor fará. */
  const canteiroPaga = (custo: Record<string, number>) =>
    Object.entries(custo).every(([r, q]) => (z.canteiro.find((c) => c.resource_type === r)?.amount ?? 0) >= q)

  // A fila de obras: pode ter mais de uma ao mesmo tempo, conforme o teto do operador
  // (`obras_vagas`, D-111) — travar o botão assim que UMA existisse já foi bug (achado #10).
  const filaCheia = z.obras.length >= z.obras_vagas

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

      {z.manutencao.inadimplente_desde ? (
        <div className="border-rust bg-rust/10 border-l-4 p-3" data-manutencao-atrasada>
          <strong className="text-rust">Manutenção territorial em atraso</strong>
          <p className="text-sm">
            Desde {dataHumana(z.manutencao.inadimplente_desde)}
            {z.manutencao.penalidade_bps > 0 &&
              ` — defesa reduzida em ${z.manutencao.penalidade_bps / 100}%`}
            . Sem pagar por 72 h a zona é abandonada automaticamente.
          </p>
        </div>
      ) : (
        // Antes disto, o colono só descobria o custo/prazo depois de já estar atrasado —
        // a cobrança em si é automática (D-84), mas o valor e a data nunca eram ditos antes.
        <div className="border-ink/10 border-l-4 p-3 text-sm" data-manutencao-info>
          <strong className="text-ink">Manutenção territorial</strong>
          <p className="text-ink-soft mt-1">
            {Object.entries(z.manutencao.custo_diario)
              .map(([r, q]) => `${q.toLocaleString('pt-BR')} ${nomeRecurso(r)}`)
              .join(' + ')}{' '}
            por dia, cobrados sozinhos.
            {z.manutencao.proximo_vencimento && (
              <> Próximo vencimento: {dataHumana(z.manutencao.proximo_vencimento)}.</>
            )}
          </p>
        </div>
      )}

      {/* ══════════════════════════════════════════════════════ Zona Neutra ═══ */}
      {aba === 'zona' && (
        <div className="space-y-6" data-aba="zona">
          {/*
            A fila de obras — pedido do usuário (2026-07-19): antes a tela só sabia mostrar UMA
            obra (o `.first()` da API), mesmo quando o operador libera mais de uma ao mesmo tempo
            (`obras_vagas`, D-111) — e o colono não tinha como saber que a fila tinha vaga, ou
            quantas obras já estavam em andamento.
          */}
          {z.obras.length > 0 && (
            <div className="border-ink/10 border-l-4 p-3 text-sm" data-fila-de-obras>
              <strong className="text-ink">
                Fila de obras ({z.obras.length}/{z.obras_vagas})
              </strong>
              <ul className="mt-1 space-y-0.5">
                {z.obras.map((o, i) => (
                  <li key={i} className="text-ink-soft" data-obra-em-curso={o.structure}>
                    {o.nome} (slot {o.slot}) nível {o.target_level} — pronta {dataHumana(o.finishes_at)}.
                  </li>
                ))}
              </ul>
            </div>
          )}

          <div className="grid gap-6 lg:grid-cols-[400px_1fr]">
            <ColmeiaDaZona estr={estr} sel={sel} onEscolher={setSel} />

            {/* ── o painel do slot escolhido ─────────────────────────────────────────────────── */}
            <div>
              {sel === null ? (
                <p className="text-ink-soft text-sm">
                  Clique num hexágono da colmeia. Os apagados ainda estão trancados — suba o nível da
                  zona para abri-los.
                </p>
              ) : slotTrancado ? (
                <div className="painel bg-sand p-4" data-painel-slot-trancado={sel}>
                  <h3 className="text-lg font-black">Slot trancado</h3>
                  <p className="text-ink-soft mt-2 text-sm">
                    Este slot ainda não está desbloqueado. A cada nível da zona (acima de 1) mais um
                    slot abre — suba o nível na seção abaixo.
                  </p>
                </div>
              ) : erguidaEscolhida ? (
                <PainelEstruturaErguida
                  z={z}
                  e={erguidaEscolhida}
                  emObra={emObraNesteSlot}
                  filaCheia={filaCheia}
                  canteiroPaga={canteiroPaga}
                  ocupado={ocupado}
                  agir={agir}
                  confirmandoDemolicao={confirmandoDemolicao}
                  setConfirmandoDemolicao={setConfirmandoDemolicao}
                  palavraDemolicao={palavraDemolicao}
                  setPalavraDemolicao={setPalavraDemolicao}
                />
              ) : (
                <PainelSlotVazio
                  slot={sel}
                  catalogo={catalogoDisponivel}
                  tipoEscolhido={tipoEscolhido}
                  setTipoEscolhido={setTipoEscolhido}
                  tipoDoCatalogo={tipoDoCatalogo}
                  z={z}
                  emObra={emObraNesteSlot}
                  filaCheia={filaCheia}
                  canteiroPaga={canteiroPaga}
                  ocupado={ocupado}
                  agir={agir}
                />
              )}
            </div>
          </div>

          {/* ── upgrade de nível da zona (D-84, teto 5→10 no D-144) ──────────────────────────── */}
          <section className="painel bg-sand p-4" data-secao="upgrade">
            <h3 className="font-bold">Nível da zona</h3>
            <p className="text-ink-soft mt-1 text-sm">
              O nível decide quanto a zona extrai por hora e quantos slots da colmeia estão
              desbloqueados — sobe de 1 a 10, e cada nível custa mais que o anterior. Diferente das
              construções: o custo sai direto do estoque de casa, como a ocupação (não do canteiro).
            </p>

            {z.upgrade.target ? (
              <p className="mt-2 text-sm" data-upgrade-em-curso>
                Upgrade para o nível {z.upgrade.target} em curso
                {z.upgrade.finishes_at && ` — pronto ${dataHumana(z.upgrade.finishes_at)}`}.
              </p>
            ) : z.upgrade.proximo_custo ? (
              <div className="mt-2">
                <p className="text-ink-soft text-sm">
                  Subir ao nível {z.level + 1}: {z.upgrade.proximo_custo.metal_bruto} Metal Bruto +{' '}
                  {z.upgrade.proximo_custo.fert} Fert$, guarnição até {z.upgrade.proxima_guarnicao} robôs.
                </p>
                <button
                  className="botao mt-2"
                  disabled={ocupado || z.cercada}
                  data-upar-zona
                  onClick={() =>
                    void agir(async () => {
                      await api.upgradeZona(z.id)

                      return `Upgrade para o nível ${z.level + 1} iniciado.`
                    })
                  }
                >
                  Upgrade para o nível {z.level + 1}
                </button>
              </div>
            ) : (
              <p className="text-ink-soft mt-2 text-sm">Nível máximo (10).</p>
            )}
          </section>

          {/* ── o que o GDD promete e o jogo não tem ─────────────────────────────────────────── */}
          {Object.keys(z.ausentes).length > 0 && (
            <section data-secao="ausentes">
              <h3 className="font-bold">O que ainda não existe</h3>
              <p className="text-ink-soft mt-1 text-xs">
                O §17.4 lista estas estruturas. O jogo não as tem — e dizer isso é melhor do que
                fingir que elas não foram prometidas.
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
        </div>
      )}

      {/* ══════════════════════════════════════════════════════ Depósito ══════ */}
      {aba === 'deposito' && (
        <section className="painel bg-sand p-4" data-aba="deposito" data-secao="deposito">
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
            {/* Os minerais da Indústria Siderúrgica (D-82) — mesmo Depósito, mesma capacidade. Esta
                lista é genérica de propósito (D-86): quando uma zona passar a produzir mais do que
                bruto + refinado, ela aparece aqui sozinha, sem mexer em código nenhum. */}
            {z.deposito.minerais.map((m) => (
              <div key={m.resource_type}>
                {nomeRecurso(m.resource_type)}:{' '}
                <strong data-mineral={m.resource_type}>{m.amount}</strong>
              </div>
            ))}
          </div>

          <div className="mt-3 text-sm">
            <div>
              Protegido do saque: <strong>{z.deposito.protegido}</strong> de {z.deposito.capacidade}{' '}
              de capacidade
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

          {/* Retirar — qualquer coisa que esteja no Depósito, genericamente (D-86: bruto, refinado,
              ou qualquer mineral futuro). Sem isto, o que a Refinaria e a Siderúrgica produzem
              ficaria preso na zona para sempre — a mesma armadilha que o D-67 já tinha evitado. */}
          {(() => {
            const disponiveis = [
              { recurso: z.mineral, tem: z.deposito.bruto },
              ...(z.deposito.refinado_recurso
                ? [{ recurso: z.deposito.refinado_recurso, tem: z.deposito.refinado }]
                : []),
              ...z.deposito.minerais.map((m) => ({ recurso: m.resource_type, tem: m.amount })),
            ].filter((r) => r.tem > 0)

            if (disponiveis.length === 0) return null

            return (
              <div className="border-rust/20 mt-3 border-t pt-3">
                {ociosos.length === 0 ? (
                  <p className="text-ink-soft text-xs">Nenhum veículo ocioso para retirar carga.</p>
                ) : (
                  <>
                    <div className="text-ink eyebrow">Retirar (com {nomeVeiculo(ociosos[0].type)})</div>
                    <div className="mt-1 grid gap-2 sm:grid-cols-3">
                      {disponiveis.map(({ recurso, tem }) => (
                        <label key={recurso} className="text-sm">
                          {nomeRecurso(recurso)}
                          <input
                            type="number"
                            min={0}
                            max={Math.min(tem, ociosos[0].capacity_efetiva)}
                            value={retirar[recurso] ?? 0}
                            onChange={(e) =>
                              setRetirar((v) => ({
                                ...v,
                                [recurso]: Math.max(0, Math.min(tem, Number(e.target.value))),
                              }))
                            }
                            data-retirar={recurso}
                            className="border-rust/25 bg-sand-light focus:border-rust mt-1 w-full border px-2 py-1 outline-none"
                          />
                        </label>
                      ))}
                    </div>

                    <button
                      className="botao mt-2 w-full"
                      disabled={ocupado || z.cercada || Object.values(retirar).every((q) => !q)}
                      data-retirar-deposito
                      onClick={() =>
                        void agir(async () => {
                          const carga = Object.fromEntries(
                            Object.entries(retirar).filter(([, q]) => q > 0),
                          )
                          const v = await api.retirarDeZona(z.id, ociosos[0].id, carga)
                          setRetirar({})

                          const cargaTexto = Object.entries(carga)
                            .map(([r, q]) => `${q} ${nomeRecurso(r)}`)
                            .join(', ')
                          const chegada = v.arrives_at ? ` — chega ${dataHumana(v.arrives_at)}` : ''

                          return `${nomeVeiculo(v.type)} ${v.plate} a caminho de casa com ${cargaTexto}${chegada}.`
                        })
                      }
                    >
                      {z.cercada ? 'Não se retira sob sítio' : 'Retirar'}
                    </button>
                  </>
                )}
              </div>
            )
          })()}
        </section>
      )}

      {/* ══════════════════════════════════════════════════════ Canteiro ══════ */}
      {aba === 'canteiro' && (
        <section className="painel bg-sand p-4" data-aba="canteiro" data-secao="canteiro">
          <h3 className="font-bold">Canteiro de obras</h3>
          <p className="text-ink-soft mt-1 text-sm">
            <strong>O material das obras chega de veículo.</strong> Não sai do estoque de casa por
            mágica — a zona fica a <em>slots</em> de distância, e tudo o que é físico viaja. A sobra
            fica no canteiro para a próxima obra.
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

          {z.obras.length > 0 && (
            <div className="mt-2 text-sm">
              Em obra ({z.obras.length}/{z.obras_vagas}):
              <ul className="mt-1">
                {z.obras.map((o, i) => (
                  <li key={i}>
                    <strong>{o.nome}</strong> (slot {o.slot}) nível {o.target_level} — pronta{' '}
                    {dataHumana(o.finishes_at)}.
                  </li>
                ))}
              </ul>
            </div>
          )}

          {/*
            ── Enviar material ─────────────────────────────────────────────────────────────────
            Redesenhado no D-86, e adaptado ao slot no D-144: a obra-alvo agora é identificada por
            SLOT, não por tipo (um tipo repetível pode ter mais de uma obra possível ao mesmo
            tempo, em slots diferentes). Escolher o slot na colmeia (aba Zona Neutra) é o que decide
            qual obra este formulário paga.
          */}
          {ociosos.length === 0 ? (
            <p className="text-ink-soft mt-3 text-xs">Nenhum veículo ocioso para levar material.</p>
          ) : (
            <EnviarMaterial
              zona={z}
              sel={sel}
              erguidaEscolhida={erguidaEscolhida}
              tipoDoCatalogo={tipoDoCatalogo}
              ociosos={ociosos}
              envio={envio}
              setEnvio={setEnvio}
              envioVeiculoId={envioVeiculoId}
              setEnvioVeiculoId={setEnvioVeiculoId}
              ocupado={ocupado}
              onIrParaColmeia={() => setAba('zona')}
              onDespachar={(veiculoId, carga) =>
                void agir(async () => {
                  const v = await api.entregarMaterial(z.id, veiculoId, carga)
                  setEnvio({})

                  // Sem tipo, placa, carga e chegada, a mensagem só dizia "um veículo" — e numa
                  // colônia com dois ou três Furgões, o colono não tinha como saber QUAL partiu.
                  const cargaTexto = Object.entries(carga)
                    .map(([r, q]) => `${q} ${nomeRecurso(r)}`)
                    .join(', ')
                  const chegada = v.arrives_at ? ` — chega ${dataHumana(v.arrives_at)}` : ''

                  return `${nomeVeiculo(v.type)} ${v.plate} a caminho da zona com ${cargaTexto}${chegada}.`
                })
              }
            />
          )}
        </section>
      )}

      {/* ══════════════════════════════════════════════════════ Guarnição ═════ */}
      {aba === 'guarnicao' && (
        <section className="painel bg-sand p-4" data-aba="guarnicao" data-secao="guarnicao">
          <h3 className="font-bold">Guarnição</h3>
          <p className="mt-1 text-sm">
            {z.guarnicao.robos} Robôs Mineradores · {z.guarnicao.sentinelas} Sentinelas ·{' '}
            <strong>{z.guarnicao.defesa}</strong> pontos de defesa
          </p>
          <p className="text-ink-soft mt-1 text-xs">
            O bônus da Muralha, da Torre e do Bastião multiplica isto. Sem eles, a zona defende com o
            que tem, e nada mais.
          </p>

          {/* Reforço (§27.5, D-70) — trazido para dentro da zona no D-86. Não exige combate em
              curso: guarnecer em paz é a mesma coisa que socorrer sob ataque. */}
          <div className="border-rust/20 mt-3 border-t pt-3">
            <div className="text-ink eyebrow">Reforçar com Sentinelas</div>
            {!guerra ? (
              <p className="text-ink-soft mt-1 text-xs">Carregando…</p>
            ) : (
              <ReforcarZona
                zona={z}
                sentinelasEmCasa={guerra.unidades.filter((u) => u.type === 'sentinela')}
                quantas={reforcoQtd}
                setQuantas={setReforcoQtd}
                ocupado={ocupado}
                onReforcar={(ids) =>
                  void agir(async () => {
                    const r = await api.reforcar(z.id, ids)
                    setGuerra(null)

                    return `${r.marcharam} Sentinela(s) a caminho da zona.`
                  })
                }
              />
            )}
          </div>
        </section>
      )}

      {/* ══════════════════════════════════════════════════════ Histórico ═════ */}
      {aba === 'historico' && (
        <section className="painel bg-sand p-4" data-aba="historico" data-secao="historico">
          <h3 className="font-bold">Histórico</h3>
          <p className="text-ink-soft mt-1 text-xs">
            Posse (ocupação, abandono, conquista), o que a zona custou e o que a guerra fez com ela.
            Só você vê isto.
          </p>

          {carregandoHistorico && <p className="text-ink-soft mt-2 text-sm">Carregando…</p>}

          {eventos && eventos.length === 0 && (
            <p className="text-ink-soft mt-2 text-sm">Nada ainda.</p>
          )}

          {eventos && eventos.length > 0 && (
            <ul className="mt-2 space-y-2 text-sm">
              {eventos.map((ev, i) => (
                <li key={i} className="border-rust/15 border-b pb-2" data-evento={ev.categoria}>
                  <div className="text-ink-soft text-xs">{dataHumana(ev.em)}</div>
                  <div>
                    {ev.categoria === 'posse' && <LinhaDePosse ev={ev} />}
                    {ev.categoria === 'financeiro' && <LinhaFinanceira ev={ev} />}
                    {ev.categoria === 'guerra' && <LinhaDeGuerra ev={ev} />}
                  </div>
                </li>
              ))}
            </ul>
          )}
        </section>
      )}

      {erro && <p className="text-rust text-sm font-bold">{erro}</p>}
      {recibo && <p className="text-sm font-bold">{recibo}</p>}
    </div>,
  )
}

/**
 * A colmeia da zona, em SVG (D-144) — mesma geometria da colônia, tecnologia diferente (ver o
 * comentário do topo do arquivo). Cada hexágono é: o Posto (fixo, indemolível), uma estrutura
 * erguida, um slot vazio já desbloqueado, ou um slot ainda trancado (apagado, sem clique útil além
 * de dizer "trancado").
 */
function ColmeiaDaZona({
  estr,
  sel,
  onEscolher,
}: {
  estr: ZonaDetalhe['estruturas']
  sel: number | null
  onEscolher: (slot: number) => void
}) {
  const largura = 340
  const altura = 300
  const { r, centros } = useMemo(
    () => centrosDaColmeia(estr.colmeia.linhas, largura, altura),
    [estr.colmeia.linhas],
  )
  const porSlot = new Map(estr.erguidas.map((e) => [e.slot, e]))

  return (
    <svg viewBox={`0 0 ${largura} ${altura}`} className="w-full" role="group" aria-label="Colmeia da zona">
      <rect x={0} y={0} width={largura} height={altura} fill="var(--color-sand)" />

      {centros.map((c, slot) => {
        const ehPosto = slot === estr.colmeia.slot_do_posto
        const erguida = porSlot.get(slot)
        const desbloqueado = estr.colmeia.desbloqueados.includes(slot)
        const trancado = !ehPosto && !erguida && !desbloqueado

        const preenchido = ehPosto || erguida
        const rotulo = ehPosto ? 'Comando' : (erguida?.nome ?? '')

        return (
          <g key={slot} data-slot={slot}>
            <polygon
              points={pontosDoHexagono(c.x, c.y, r * 0.92)}
              // `transparent`, não `none`: um `fill="none"` não recebe clique no interior.
              fill={preenchido ? 'var(--color-ember)' : trancado ? 'var(--color-ink-soft)' : 'transparent'}
              stroke={preenchido ? 'var(--color-rust)' : 'var(--color-ink-soft)'}
              strokeWidth={sel === slot ? 3 : 1.5}
              strokeDasharray={!preenchido && !trancado ? '4 3' : undefined}
              opacity={trancado ? 0.18 : erguida ? Math.max(0.35, erguida.fracao_efetiva / 10_000) : 1}
              className={trancado ? 'cursor-not-allowed' : 'cursor-pointer'}
              onClick={() => onEscolher(slot)}
              data-hex={slot}
              data-hex-tipo={ehPosto ? 'posto_de_comando' : (erguida?.type ?? '')}
              data-hex-estado={ehPosto ? 'posto' : erguida ? 'erguida' : trancado ? 'trancado' : 'vazio'}
            />
            <text
              x={c.x}
              y={c.y + 4}
              textAnchor="middle"
              className="pointer-events-none select-none text-[9px] font-bold"
              fill={preenchido ? 'var(--color-ink)' : 'var(--color-ink-soft)'}
            >
              {trancado ? '🔒' : rotulo}
              {erguida ? ` ${erguida.level}` : ''}
            </text>
          </g>
        )
      })}
    </svg>
  )
}

/** O painel de uma estrutura JÁ ERGUIDA — o que ela faz, upgrade, reparo, demolição. */
function PainelEstruturaErguida({
  z,
  e,
  emObra,
  filaCheia,
  canteiroPaga,
  ocupado,
  agir,
  confirmandoDemolicao,
  setConfirmandoDemolicao,
  palavraDemolicao,
  setPalavraDemolicao,
}: {
  z: ZonaDetalhe
  e: EstruturaDaZona
  emObra: boolean
  filaCheia: boolean
  canteiroPaga: (custo: Record<string, number>) => boolean
  ocupado: boolean
  agir: (acao: () => Promise<string>) => Promise<void>
  confirmandoDemolicao: boolean
  setConfirmandoDemolicao: (v: boolean) => void
  palavraDemolicao: string
  setPalavraDemolicao: (v: string) => void
}) {
  return (
    <div className="painel bg-sand p-4" data-painel-estrutura={e.type} data-painel-slot={e.slot}>
      <h3 className="text-lg font-black">
        {e.nome} <span className="text-ink-soft text-sm font-normal">nível {e.level}</span>
      </h3>

      {e.apreendida && (
        <div className="border-rust/40 bg-sand-light mt-1 border p-2 text-sm">
          <p className="text-rust font-bold">⚠ Apreendida pelo Predador — 0% de efeito.</p>
          <p className="text-ink-soft mt-1 text-xs">
            {e.apreendida.expira_em
              ? `Volta sozinha ${dataHumana(e.apreendida.expira_em)}, ou pague o resgate agora.`
              : 'Volta sozinha, ou pague o resgate agora.'}
          </p>
          <BotaoDeReparo z={z} escolhida={e} agir={agir} rotulo="Pagar resgate" />
        </div>
      )}

      {e.sabotada && (
        <div className="border-rust/40 bg-sand-light mt-1 border p-2 text-sm">
          <p className="text-rust font-bold">
            ⚠ Sabotada pelo Infiltrador (nível {e.sabotada.nivel_do_infiltrador}) — opera a{' '}
            {Math.round(e.fracao_efetiva / 100)}%.
          </p>
          <p className="text-ink-soft mt-1 text-xs">Sem prazo automático — só volta ao normal com reparo.</p>
          <BotaoDeReparo z={z} escolhida={e} agir={agir} rotulo="Reparar" />
        </div>
      )}

      {/* As duas camadas, e elas não se confundem: o que o GDD promete e o que o jogo faz. */}
      <p className="text-ink-soft mt-2 text-xs italic">GDD: {e.gdd}</p>
      <p className="mt-2 text-sm">{e.hoje}</p>

      {e.inerte && (
        <p className="text-ink-soft mt-2 text-xs">
          Esta construção <strong>não faz nada</strong>, e é o próprio GDD que o diz. Erga-a se
          quiser — é a única do jogo que se ergue só por gosto.
        </p>
      )}

      {e.indemolivel ? (
        <p className="text-ink-soft mt-3 text-xs">Nasce com a ocupação e não se ergue nem se demole.</p>
      ) : e.proximo ? (
        <div className="mt-3">
          <div className="text-ink eyebrow">Erguer ao nível {e.proximo.level} — do canteiro</div>
          <ul className="text-ink-soft mt-1 text-sm">
            {Object.entries(e.proximo.custo).map(([r, q]) => {
              const tem = z.canteiro.find((c) => c.resource_type === r)?.amount ?? 0

              return (
                <li key={r} className={tem < q ? 'text-rust' : ''}>
                  {nomeRecurso(r)}: {q} <span className="text-xs">(no canteiro: {tem})</span>
                </li>
              )
            })}
          </ul>
          <p className="text-ink-soft mt-1 text-xs">Leva {Math.round(e.proximo.segundos / 3600)} h.</p>

          {!canteiroPaga(e.proximo.custo) && (
            <p className="text-rust mt-2 text-xs">
              Falta material no canteiro — envie pela aba Canteiro de obras.
            </p>
          )}

          <button
            className="botao mt-2 w-full"
            disabled={ocupado || filaCheia || z.cercada || !canteiroPaga(e.proximo.custo)}
            data-construir={e.type}
            data-construir-slot={e.slot}
            onClick={() =>
              void agir(async () => {
                await api.construirNaZona(z.id, e.type, e.slot)

                return `${e.nome} em obra.`
              })
            }
          >
            {filaCheia
              ? z.obras_vagas === 1
                ? 'Já há uma obra em curso'
                : `Fila cheia (${z.obras.length}/${z.obras_vagas})`
              : z.cercada
                ? 'Não se constrói sob sítio'
                : !canteiroPaga(e.proximo.custo)
                  ? 'Falta material no canteiro'
                  : 'Evoluir'}
          </button>
        </div>
      ) : (
        <p className="text-ink-soft mt-3 text-xs">No nível máximo.</p>
      )}

      {/*
        Demolir (D-138): o espelho do que a colônia já tem (D-59/D-61). O investido não volta, e a
        manutenção NÃO cai (ela nunca dependeu do nível desta estrutura, só do nível da zona).
      */}
      {!e.indemolivel && (
        <div className="border-rust/30 mt-3 border-t pt-3">
          {!confirmandoDemolicao ? (
            <button
              onClick={() => setConfirmandoDemolicao(true)}
              className="text-ink-soft hover:text-rust w-full py-1 text-xs"
              data-demolir-zona={e.type}
              data-demolir-zona-slot={e.slot}
            >
              Demolir
            </button>
          ) : (
            <>
              <p className="text-ink-soft text-xs leading-snug">
                Demolir libera o slot de volta a vazio.{' '}
                <span className="text-rust font-bold">Nada é devolvido</span> — o material investido
                nos {e.level} níveis se perde, e a manutenção da zona não muda (ela nunca dependeu
                desta estrutura).
              </p>

              {emObra && (
                <p className="text-rust mt-2 text-xs font-bold">
                  Há uma obra em curso neste slot — espere-a terminar.
                </p>
              )}
              {z.cercada && <p className="text-rust mt-2 text-xs font-bold">Não se demole sob sítio.</p>}

              <label className="text-ink-soft mt-2 block text-xs">
                Escreva <span className="text-rust font-bold">DEMOLIR</span> para confirmar:
                <input
                  value={palavraDemolicao}
                  onChange={(e2) => setPalavraDemolicao(e2.target.value)}
                  autoFocus
                  data-palavra-demolir-zona
                  className="border-rust/40 bg-sand text-ink mt-1 w-full border px-2 py-1 font-mono text-sm"
                />
              </label>

              <button
                onClick={() =>
                  void agir(async () => {
                    await api.demolirEstruturaDaZona(z.id, e.slot)
                    setConfirmandoDemolicao(false)
                    setPalavraDemolicao('')

                    return `${e.nome} demolida.`
                  })
                }
                disabled={ocupado || z.cercada || emObra || palavraDemolicao !== 'DEMOLIR'}
                data-demolir-zona-confirmar={e.type}
                className="border-rust text-rust hover:bg-rust hover:text-sand-light disabled:border-ink-soft/25 disabled:text-ink-soft/40 mt-2 w-full border py-2 text-sm font-bold disabled:cursor-not-allowed disabled:hover:bg-transparent"
              >
                Demolir mesmo assim
              </button>
              <button
                onClick={() => {
                  setConfirmandoDemolicao(false)
                  setPalavraDemolicao('')
                }}
                className="text-ink-soft hover:text-rust mt-1 w-full py-1 text-xs"
              >
                cancelar
              </button>
            </>
          )}
        </div>
      )}
    </div>
  )
}

/** O painel de um slot VAZIO e desbloqueado (D-144): escolher o que erguer ali. */
function PainelSlotVazio({
  slot,
  catalogo,
  tipoEscolhido,
  setTipoEscolhido,
  tipoDoCatalogo,
  z,
  emObra,
  filaCheia,
  canteiroPaga,
  ocupado,
  agir,
}: {
  slot: number
  catalogo: ZonaDetalhe['estruturas']['catalogo']
  tipoEscolhido: string | null
  setTipoEscolhido: (t: string | null) => void
  tipoDoCatalogo: ZonaDetalhe['estruturas']['catalogo'][number] | null | undefined
  z: ZonaDetalhe
  emObra: boolean
  filaCheia: boolean
  canteiroPaga: (custo: Record<string, number>) => boolean
  ocupado: boolean
  agir: (acao: () => Promise<string>) => Promise<void>
}) {
  const custo = tipoDoCatalogo?.custo_nivel_1 ?? null

  return (
    <div className="painel bg-sand p-4" data-painel-slot-vazio={slot}>
      <h3 className="text-lg font-black">Slot vazio</h3>
      <p className="text-ink-soft mt-1 text-sm">Escolha o que erguer aqui:</p>

      <select
        value={tipoEscolhido ?? ''}
        onChange={(e) => setTipoEscolhido(e.target.value || null)}
        data-escolher-tipo-slot
        className="border-rust/25 bg-sand-light focus:border-rust mt-2 w-full border px-2 py-1 text-sm outline-none"
      >
        <option value="" disabled>
          Escolha uma construção…
        </option>
        {catalogo.map((c) => (
          <option key={c.type} value={c.type}>
            {c.nome}
            {c.repetivel && c.quantas > 0 ? ` (mais uma cópia — já tem ${c.quantas})` : ''}
          </option>
        ))}
      </select>

      {tipoDoCatalogo && (
        <div className="mt-3">
          <p className="text-ink-soft mt-2 text-xs italic">GDD: {tipoDoCatalogo.gdd}</p>
          <p className="mt-2 text-sm">{tipoDoCatalogo.hoje}</p>

          {custo && (
            <ul className="text-ink-soft mt-2 text-sm">
              {Object.entries(custo).map(([r, q]) => {
                const tem = z.canteiro.find((c) => c.resource_type === r)?.amount ?? 0

                return (
                  <li key={r} className={tem < q ? 'text-rust' : ''}>
                    {nomeRecurso(r)}: {q} <span className="text-xs">(no canteiro: {tem})</span>
                  </li>
                )
              })}
            </ul>
          )}

          {custo && !canteiroPaga(custo) && (
            <p className="text-rust mt-2 text-xs">
              Falta material no canteiro — envie pela aba Canteiro de obras.
            </p>
          )}

          <button
            className="botao mt-2 w-full"
            disabled={ocupado || filaCheia || z.cercada || emObra || !custo || !canteiroPaga(custo)}
            data-construir={tipoDoCatalogo.type}
            data-construir-slot={slot}
            onClick={() =>
              void agir(async () => {
                await api.construirNaZona(z.id, tipoDoCatalogo.type, slot)

                return `${tipoDoCatalogo.nome} em obra.`
              })
            }
          >
            {filaCheia
              ? z.obras_vagas === 1
                ? 'Já há uma obra em curso'
                : `Fila cheia (${z.obras.length}/${z.obras_vagas})`
              : z.cercada
                ? 'Não se constrói sob sítio'
                : custo && !canteiroPaga(custo)
                  ? 'Falta material no canteiro'
                  : 'Construir'}
          </button>
        </div>
      )}
    </div>
  )
}

function LinhaDePosse({ ev }: { ev: EventoDaZona }) {
  const ROTULO: Record<string, string> = {
    ocupada: 'Ocupada',
    abandonada: 'Abandonada por manutenção não paga',
    conquistada: 'Conquistada na guerra',
  }

  return (
    <span>
      <strong>{ROTULO[ev.tipo] ?? ev.tipo}</strong>
      {ev.colonia && ` — ${ev.colonia}`}
    </span>
  )
}

function LinhaFinanceira({ ev }: { ev: EventoDaZona }) {
  const ROTULO: Record<string, string> = {
    custo_ocupacao: 'Custo de ocupação',
    custo_upgrade_zona: 'Custo de upgrade de nível',
    manutencao_territorial: 'Manutenção territorial',
    saque_de_guerra: 'Saque de guerra',
    reparo_de_modulo: 'Reparo de módulo',
  }
  const qtd = ev.quantidade ?? 0

  return (
    <span>
      <strong>{ROTULO[ev.tipo] ?? ev.tipo}</strong>
      {ev.recurso ? ` — ${nomeRecurso(ev.recurso)}` : ' — Fert$'}:{' '}
      <span className={qtd < 0 ? 'text-rust' : ''}>{qtd}</span>
    </span>
  )
}

function LinhaDeGuerra({ ev }: { ev: EventoDaZona }) {
  return (
    <span>
      <strong>{ev.tipo}</strong> — {ev.atacante} atacou, {ev.defensor} defendeu ({ev.status})
    </span>
  )
}

/**
 * O formulário de envio de material (D-86, adaptado ao slot no D-144). A obra-alvo é o slot
 * escolhido na colmeia (aba Zona Neutra) — uma estrutura já erguida com upgrade disponível, ou um
 * slot vazio com um tipo já escolhido no painel.
 */
function EnviarMaterial({
  zona,
  sel,
  erguidaEscolhida,
  tipoDoCatalogo,
  ociosos,
  envio,
  setEnvio,
  envioVeiculoId,
  setEnvioVeiculoId,
  ocupado,
  onIrParaColmeia,
  onDespachar,
}: {
  zona: ZonaDetalhe
  sel: number | null
  erguidaEscolhida: EstruturaDaZona | null
  tipoDoCatalogo: ZonaDetalhe['estruturas']['catalogo'][number] | null | undefined
  ociosos: Veiculo[]
  envio: Record<string, number>
  setEnvio: (fn: (v: Record<string, number>) => Record<string, number>) => void
  envioVeiculoId: number | null
  setEnvioVeiculoId: (id: number | null) => void
  ocupado: boolean
  onIrParaColmeia: () => void
  onDespachar: (veiculoId: number, carga: Record<string, number>) => void
}) {
  const alvo = erguidaEscolhida?.proximo
    ? { nome: erguidaEscolhida.nome, custo: erguidaEscolhida.proximo.custo }
    : tipoDoCatalogo?.custo_nivel_1
      ? { nome: tipoDoCatalogo.nome, custo: tipoDoCatalogo.custo_nivel_1 }
      : null

  const veiculo = ociosos.find((v) => v.id === envioVeiculoId) ?? ociosos[0]

  const noCanteiro = (r: string) => zona.canteiro.find((c) => c.resource_type === r)?.amount ?? 0

  /**
   * O valor de VERDADE de cada recurso — o mesmo que o campo MOSTRA (`envio[r]`, e na falta
   * dele, o que falta pra obra). Antes, `total` somava só `envio` cru: os campos já apareciam
   * preenchidos com o padrão (o que falta), mas ninguém tinha DIGITADO nada ainda, então `envio`
   * continuava `{}` e `total` dava 0 — o botão ficava travado mostrando números na tela inteiros.
   */
  const efetivo = (r: string) => {
    const falta = Math.max(0, (alvo?.custo[r] ?? 0) - noCanteiro(r))

    return envio[r] ?? Math.min(falta, veiculo?.capacity_efetiva ?? 0)
  }

  const total = alvo ? Object.keys(alvo.custo).reduce((s, r) => s + efetivo(r), 0) : 0

  return (
    <div className="mt-3">
      {sel === null ? (
        <p className="text-ink-soft text-xs">
          Nenhum slot escolhido.{' '}
          <button className="text-rust underline" onClick={onIrParaColmeia} data-ir-a-colmeia>
            Escolha um na colmeia
          </button>{' '}
          — uma estrutura para evoluir, ou um slot vazio com um tipo já selecionado.
        </p>
      ) : !alvo ? (
        <p className="text-ink-soft text-xs">
          O slot {sel} não tem uma obra possível ainda.{' '}
          <button className="text-rust underline" onClick={onIrParaColmeia} data-ir-a-colmeia>
            Volte à colmeia
          </button>{' '}
          para escolher o que erguer ali.
        </p>
      ) : (
        <>
          <label className="text-ink eyebrow block">
            Obra: {alvo.nome} (slot {sel})
          </label>

          {ociosos.length > 1 && (
            <div className="mt-2">
              <label className="text-ink eyebrow block">Com qual veículo?</label>
              <select
                value={veiculo?.id ?? ''}
                onChange={(e) => setEnvioVeiculoId(Number(e.target.value))}
                data-veiculo-do-canteiro
                className="border-rust/25 bg-sand-light focus:border-rust mt-1 w-full border px-2 py-1 text-sm outline-none"
              >
                {ociosos.map((v) => (
                  <option key={v.id} value={v.id}>
                    {nomeVeiculo(v.type)} #{v.id} — {v.capacity_efetiva} un
                  </option>
                ))}
              </select>
            </div>
          )}

          <div className="mt-2 grid gap-2 sm:grid-cols-3">
            {Object.entries(alvo.custo).map(([r, q]) => {
              const tem = noCanteiro(r)
              const falta = Math.max(0, q - tem)

              return (
                <label key={r} className="text-sm">
                  {nomeRecurso(r)} <span className="text-ink-soft text-xs">(falta {falta} de {q})</span>
                  <input
                    type="number"
                    min={0}
                    max={veiculo?.capacity_efetiva ?? 0}
                    value={efetivo(r)}
                    onChange={(e) =>
                      setEnvio((v) => ({
                        ...v,
                        [r]: Math.max(0, Math.min(veiculo?.capacity_efetiva ?? 0, Number(e.target.value))),
                      }))
                    }
                    data-enviar={r}
                    className="border-rust/25 bg-sand-light focus:border-rust mt-1 w-full border px-2 py-1 outline-none"
                  />
                </label>
              )
            })}
          </div>

          <p className="text-ink-soft mt-1 text-xs">
            {total} de {veiculo?.capacity_efetiva ?? 0} de capacidade do veículo.
          </p>

          <button
            className="botao mt-2 w-full"
            disabled={ocupado || zona.cercada || !veiculo || total === 0}
            data-despachar-material
            onClick={() => {
              if (!veiculo) return
              const carga = Object.fromEntries(
                Object.keys(alvo.custo)
                  .map((r) => [r, efetivo(r)])
                  .filter(([, q]) => (q as number) > 0),
              )
              onDespachar(veiculo.id, carga)
            }}
          >
            {zona.cercada ? 'Não se entrega sob sítio' : 'Despachar material'}
          </button>
        </>
      )}
    </div>
  )
}

/** O formulário de reforço (§27.5, D-70/D-86): quantas Sentinelas mandar para guarnecer a zona. */
function ReforcarZona({
  zona,
  sentinelasEmCasa,
  quantas,
  setQuantas,
  ocupado,
  onReforcar,
}: {
  zona: ZonaDetalhe
  sentinelasEmCasa: { id: number; ataque: number }[]
  quantas: number
  setQuantas: (n: number) => void
  ocupado: boolean
  onReforcar: (ids: number[]) => void
}) {
  const max = sentinelasEmCasa.length
  const n = Math.min(Math.max(1, quantas), Math.max(1, max))
  const escolhidas = sentinelasEmCasa.slice(0, n)

  if (max === 0) {
    return (
      <p className="text-ink-soft mt-1 text-xs">
        Nenhuma Sentinela em casa. Fabrique-as no Quartel para poder reforçar esta zona.
      </p>
    )
  }

  return (
    <div className="mt-2 flex flex-wrap items-center gap-2">
      <input
        type="number"
        min={1}
        max={max}
        value={quantas}
        onChange={(e) => setQuantas(Math.max(1, Number(e.target.value)))}
        data-quantas-reforco
        className="border-rust/25 bg-sand-light focus:border-rust w-20 border px-2 py-1 text-sm outline-none"
      />
      <span className="text-ink-soft text-xs">de {max} em casa</span>
      <button
        className="botao"
        disabled={ocupado || zona.cercada}
        data-reforcar-zona
        onClick={() => onReforcar(escolhidas.map((u) => u.id))}
      >
        {zona.cercada ? 'Cercada: reforço não passa' : 'Despachar reforço'}
      </button>
    </div>
  )
}

/**
 * O reparo/resgate de um módulo (D-118) — mesmo botão para as duas portas do "Módulo Operacional"
 * (D-66): a Sabotagem só sai daqui, a Apreensão também sai sozinha em 24h, mas paga aqui é na hora.
 *
 * Continua identificado por TIPO, não por slot: só as seis estruturas únicas
 * (`Domain\Guerra\Atacar::ALVOS_ATACAVEIS`) podem ser sabotadas/apreendidas — nenhuma repetível
 * (D-144) é alvo de combate, então o tipo não é ambíguo aqui.
 */
function BotaoDeReparo({
  z,
  escolhida,
  agir,
  rotulo,
}: {
  z: ZonaDetalhe
  escolhida: EstruturaDaZona
  agir: (acao: () => Promise<string>) => Promise<void>
  rotulo: string
}) {
  const custo = escolhida.custo_reparo ?? {}

  return (
    <div className="mt-2">
      <ul className="text-ink-soft text-xs">
        {Object.entries(custo).map(([r, q]) => (
          <li key={r}>
            {nomeRecurso(r)}: {q}
          </li>
        ))}
      </ul>
      <button
        className="border-rust/40 text-rust hover:border-rust mt-1 w-full border py-1.5 text-xs font-bold"
        data-reparar={escolhida.type}
        onClick={() =>
          void agir(async () => {
            await api.repararModulo(z.id, escolhida.type)

            return `${escolhida.nome} reparada.`
          })
        }
      >
        {rotulo}
      </button>
    </div>
  )
}
