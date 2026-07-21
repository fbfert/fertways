import { useCallback, useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { api, ApiError } from '../api/client'
import type { EstadoDaGuerra, EstruturaDaZona, EventoDaZona, Veiculo, ZonaDetalhe } from '../api/client'
import { dataHumana, nomeRecurso, nomeVeiculo } from './recursos'

/**
 * A zona neutra como LUGAR (GDD §17.4; docs/decisoes.md D-67, D-84, D-86).
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
 * **Cinco abas (D-86, reorganização pedida pelo usuário)** — antes era uma coluna só, longa demais
 * para achar qualquer coisa: **Zona Neutra** (identidade, planta, upgrade de nível), **Depósito**,
 * **Canteiro de obras**, **Guarnição** e **Histórico**. Três coisas que o colono não tem como
 * adivinhar, e que a tela existe para dizer:
 *
 *  1. **Só o que EXCEDE o Depósito é saqueável.** O que cabe nele está a salvo (D-66). Uma zona bem
 *     cuidada não tem butim nenhum — e é subindo o Depósito que se protege mais.
 *  2. **O material da obra tem de CHEGAR DE VEÍCULO.** O canteiro não se enche do estoque de casa:
 *     ele se enche de caminhão. A aba Canteiro agora pergunta PARA QUAL obra, e já mostra o que
 *     falta — antes o colono tinha de adivinhar entre três recursos fixos, quisesse a obra outra
 *     coisa ou não.
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
  // Construção nova, não está no GDD (D-82). Vaga entre o Comando e o Cemitério.
  industria_siderurgica: { x: 245, y: 115, w: 60, h: 50, rotulo: 'Siderurgia' },
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
  const [sel, setSel] = useState<string | null>(null)
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

  const porTipo = new Map(z.estruturas.map((e) => [e.type, e]))
  const escolhida = sel ? porTipo.get(sel) : null
  const emObraNestaEstrutura = escolhida ? z.obras.some((o) => o.structure === escolhida.type) : false
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
                    {o.nome} nível {o.target_level} — pronta {dataHumana(o.finishes_at)}.
                  </li>
                ))}
              </ul>
            </div>
          )}

          <div className="grid gap-6 lg:grid-cols-[400px_1fr]">
            <svg viewBox="0 0 400 280" className="w-full" role="group" aria-label="Planta da zona">
              <rect x="0" y="0" width="400" height="280" fill="var(--color-sand)" />

              {/*
                A parede. **Desenho puro, sem eventos**: ela engrossa conforme a Muralha sobe, e é o
                que se vê de longe — mas quem recebe o clique é o portão, lá embaixo. Um `<rect>`
                sem preenchimento só é clicável na borda, e um transparente engoliria tudo dentro.
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
                      // Esmaece na proporção do que sobrou de efeito (D-118) — 0,35 é o piso, para
                      // uma estrutura totalmente apreendida continuar visível, só visivelmente fraca.
                      opacity={e ? Math.max(0.35, e.fracao_efetiva / 10_000) : 1}
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

            {/* ── o painel da estrutura escolhida ───────────────────────────────────────────── */}
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

                  {escolhida.apreendida && (
                    <div className="border-rust/40 bg-sand-light mt-1 border p-2 text-sm">
                      <p className="text-rust font-bold">
                        ⚠ Apreendida pelo Predador — 0% de efeito.
                      </p>
                      <p className="text-ink-soft mt-1 text-xs">
                        {escolhida.apreendida.expira_em
                          ? `Volta sozinha ${dataHumana(escolhida.apreendida.expira_em)}, ou pague o resgate agora.`
                          : 'Volta sozinha, ou pague o resgate agora.'}
                      </p>
                      <BotaoDeReparo z={z} escolhida={escolhida} agir={agir} rotulo="Pagar resgate" />
                    </div>
                  )}

                  {escolhida.sabotada && (
                    <div className="border-rust/40 bg-sand-light mt-1 border p-2 text-sm">
                      <p className="text-rust font-bold">
                        ⚠ Sabotada pelo Infiltrador (nível {escolhida.sabotada.nivel_do_infiltrador}) —
                        opera a {Math.round(escolhida.fracao_efetiva / 100)}%.
                      </p>
                      <p className="text-ink-soft mt-1 text-xs">
                        Sem prazo automático — só volta ao normal com reparo.
                      </p>
                      <BotaoDeReparo z={z} escolhida={escolhida} agir={agir} rotulo="Reparar" />
                    </div>
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

                      {!canteiroPaga(escolhida) && (
                        <button
                          className="border-rust/40 text-rust hover:border-rust mt-2 w-full border py-1.5 text-xs font-bold"
                          onClick={() => setAba('canteiro')}
                          data-ir-ao-canteiro
                        >
                          Ir ao Canteiro para enviar o material
                        </button>
                      )}

                      {/*
                        O botão **não se oferece quando o canteiro não paga** — e isso não é enfeite.
                        Antes, clicar sem material mandava a requisição, o servidor devolvia 422, e o
                        colono levava um erro que a tela já sabia de antemão. A guarda do domínio
                        continua lá (é ela que vale contra requisição forjada); esta só evita prometer
                        o que não se pode cumprir.

                        Até aqui, "há uma obra" travava o botão sozinho — mesmo com `obras_vagas` (o
                        teto do operador, D-111) liberando mais de uma ao mesmo tempo. Agora compara
                        a FILA com a VAGA de verdade.
                      */}
                      <button
                        className="botao mt-2 w-full"
                        disabled={ocupado || filaCheia || z.cercada || !canteiroPaga(escolhida)}
                        data-construir={escolhida.type}
                        onClick={() =>
                          void agir(async () => {
                            await api.construirNaZona(z.id, escolhida.type)

                            return `${escolhida.nome} em obra.`
                          })
                        }
                      >
                        {filaCheia
                          ? z.obras_vagas === 1
                            ? 'Já há uma obra em curso'
                            : `Fila cheia (${z.obras.length}/${z.obras_vagas})`
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

                  {/*
                    Demolir (D-138): o espelho do que a colônia já tem (D-59/D-61) — nunca existia
                    do lado da zona. O investido não volta, e a manutenção NÃO cai (ela nunca
                    dependeu do nível desta estrutura, só do nível da zona).
                  */}
                  {escolhida.construivel && escolhida.level > 0 && (
                    <div className="border-rust/30 mt-3 border-t pt-3">
                      {!confirmandoDemolicao ? (
                        <button
                          onClick={() => setConfirmandoDemolicao(true)}
                          className="text-ink-soft hover:text-rust w-full py-1 text-xs"
                          data-demolir-zona={escolhida.type}
                        >
                          Demolir
                        </button>
                      ) : (
                        <>
                          <p className="text-ink-soft text-xs leading-snug">
                            Demolir libera esta estrutura de volta ao nível 0.{' '}
                            <span className="text-rust font-bold">Nada é devolvido</span> — o
                            material investido nos {escolhida.level} níveis se perde, e a
                            manutenção da zona não muda (ela nunca dependeu desta estrutura).
                          </p>

                          {emObraNestaEstrutura && (
                            <p className="text-rust mt-2 text-xs font-bold">
                              Há uma obra em curso nesta estrutura — espere-a terminar.
                            </p>
                          )}
                          {z.cercada && (
                            <p className="text-rust mt-2 text-xs font-bold">
                              Não se demole sob sítio.
                            </p>
                          )}

                          <label className="text-ink-soft mt-2 block text-xs">
                            Escreva <span className="text-rust font-bold">DEMOLIR</span> para
                            confirmar:
                            <input
                              value={palavraDemolicao}
                              onChange={(e) => setPalavraDemolicao(e.target.value)}
                              autoFocus
                              data-palavra-demolir-zona
                              className="border-rust/40 bg-sand text-ink mt-1 w-full border px-2 py-1 font-mono text-sm"
                            />
                          </label>

                          <button
                            onClick={() =>
                              void agir(async () => {
                                await api.demolirEstruturaDaZona(z.id, escolhida.type)
                                setConfirmandoDemolicao(false)
                                setPalavraDemolicao('')

                                return `${escolhida.nome} demolida.`
                              })
                            }
                            disabled={
                              ocupado ||
                              z.cercada ||
                              emObraNestaEstrutura ||
                              palavraDemolicao !== 'DEMOLIR'
                            }
                            data-demolir-zona-confirmar={escolhida.type}
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
              )}
            </div>
          </div>

          {/* ── upgrade de nível da zona (D-84) ──────────────────────────────────────────────── */}
          <section className="painel bg-sand p-4" data-secao="upgrade">
            <h3 className="font-bold">Nível da zona</h3>
            <p className="text-ink-soft mt-1 text-sm">
              O nível decide quanto a zona extrai por hora — sobe de 1 a 5, e cada nível custa mais
              que o anterior. Diferente das construções: o custo sai direto do estoque de casa, como
              a ocupação (não do canteiro).
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
              <p className="text-ink-soft mt-2 text-sm">Nível máximo (5).</p>
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
                    <strong>{o.nome}</strong> nível {o.target_level} — pronta {dataHumana(o.finishes_at)}.
                  </li>
                ))}
              </ul>
            </div>
          )}

          {/*
            ── Enviar material ─────────────────────────────────────────────────────────────────
            Redesenhado no D-86: antes o formulário sempre usava o primeiro veículo ocioso sem
            dizer qual, e oferecia sempre os mesmos três recursos (Metal Bruto, Ligas,
            Componentes) mesmo quando a obra pedia outra coisa — o colono não tinha como saber O
            QUE enviar nem QUANTO. Agora se escolhe a obra primeiro, e o formulário só pergunta o
            que ELA precisa, já com o que falta pré-preenchido.
          */}
          {ociosos.length === 0 ? (
            <p className="text-ink-soft mt-3 text-xs">Nenhum veículo ocioso para levar material.</p>
          ) : (
            <EnviarMaterial
              zona={z}
              porTipo={porTipo}
              sel={sel}
              aoEscolherObra={setSel}
              ociosos={ociosos}
              envio={envio}
              setEnvio={setEnvio}
              envioVeiculoId={envioVeiculoId}
              setEnvioVeiculoId={setEnvioVeiculoId}
              ocupado={ocupado}
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
 * O formulário de envio de material (D-86). Pergunta a obra ANTES do recurso — é o que faz a
 * mecânica ficar legível: o colono escolhe "o que estou construindo", e a tela responde "isto é o
 * que falta".
 */
function EnviarMaterial({
  zona,
  porTipo,
  sel,
  aoEscolherObra,
  ociosos,
  envio,
  setEnvio,
  envioVeiculoId,
  setEnvioVeiculoId,
  ocupado,
  onDespachar,
}: {
  zona: ZonaDetalhe
  porTipo: Map<string, ZonaDetalhe['estruturas'][number]>
  sel: string | null
  aoEscolherObra: (tipo: string) => void
  ociosos: Veiculo[]
  envio: Record<string, number>
  setEnvio: (fn: (v: Record<string, number>) => Record<string, number>) => void
  envioVeiculoId: number | null
  setEnvioVeiculoId: (id: number | null) => void
  ocupado: boolean
  onDespachar: (veiculoId: number, carga: Record<string, number>) => void
}) {
  const obras = zona.estruturas.filter((e) => e.construivel && e.proximo)
  const escolhida = sel ? porTipo.get(sel) : null
  const alvo = escolhida?.proximo ? escolhida : null

  const veiculo = ociosos.find((v) => v.id === envioVeiculoId) ?? ociosos[0]

  const noCanteiro = (r: string) => zona.canteiro.find((c) => c.resource_type === r)?.amount ?? 0

  /**
   * O valor de VERDADE de cada recurso — o mesmo que o campo MOSTRA (`envio[r]`, e na falta
   * dele, o que falta pra obra). Antes, `total` somava só `envio` cru: os campos já apareciam
   * preenchidos com o padrão (o que falta), mas ninguém tinha DIGITADO nada ainda, então `envio`
   * continuava `{}` e `total` dava 0 — o botão ficava travado mostrando números na tela inteiros.
   */
  const efetivo = (r: string) => {
    const falta = Math.max(0, (alvo?.proximo?.custo[r] ?? 0) - noCanteiro(r))

    return envio[r] ?? Math.min(falta, veiculo?.capacity_efetiva ?? 0)
  }

  const total = alvo ? Object.keys(alvo.proximo!.custo).reduce((s, r) => s + efetivo(r), 0) : 0

  return (
    <div className="mt-3">
      <label className="text-ink eyebrow block">Para qual obra?</label>
      <select
        value={sel ?? ''}
        onChange={(e) => aoEscolherObra(e.target.value)}
        data-obra-do-canteiro
        className="border-rust/25 bg-sand-light focus:border-rust mt-1 w-full border px-2 py-1 text-sm outline-none"
      >
        <option value="" disabled>
          Escolha uma obra…
        </option>
        {obras.map((o) => (
          <option key={o.type} value={o.type}>
            {o.nome} → nível {o.proximo!.level}
          </option>
        ))}
      </select>

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

      {!alvo ? (
        <p className="text-ink-soft mt-2 text-xs">
          {obras.length === 0
            ? 'Nenhuma obra disponível para erguer agora.'
            : 'Escolha uma obra para ver o que falta enviar.'}
        </p>
      ) : (
        <>
          <div className="mt-2 grid gap-2 sm:grid-cols-3">
            {Object.entries(alvo.proximo!.custo).map(([r, q]) => {
              const tem = noCanteiro(r)
              const falta = Math.max(0, q - tem)

              return (
                <label key={r} className="text-sm">
                  {nomeRecurso(r)}{' '}
                  <span className="text-ink-soft text-xs">(falta {falta} de {q})</span>
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
                Object.keys(alvo.proximo!.custo)
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
