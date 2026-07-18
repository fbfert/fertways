import { useEffect, useState } from 'react'
import { api, ApiError } from '../api/client'
import type { Catalogo, Colonia, Efeito, Erguivel, Fila, Funcao, Receita, Spec } from '../api/client'
import { rotulo } from '../game/ColonyScene'
import { carregarArte } from '../game/arte'
import type { Arte } from '../game/arte'
import { Popup } from './Popup'
import { INDUSTRIAIS, nomeRecurso, PRIMARIOS, RAROS } from './recursos'

function Linha({ codigo, valor }: { codigo: string; valor: number }) {
  return (
    <div className="border-rust/10 flex items-center justify-between border-b py-1.5 last:border-0">
      <span className="text-ink-soft text-sm">{nomeRecurso(codigo)}</span>
      <span className="text-ink font-bold tabular-nums">{valor.toLocaleString('pt-BR')}</span>
    </div>
  )
}

/**
 * Um bloco do painel. Os raros nascem recolhidos: são nove, o colono quase nunca os move, e
 * abertos empurrariam os industriais para fora da tela.
 */
function Bloco({
  titulo,
  codigos,
  colonia,
  recolhivel = false,
}: {
  titulo: string
  codigos: string[]
  colonia: Colonia
  recolhivel?: boolean
}) {
  const [aberto, setAberto] = useState(!recolhivel)
  const total = codigos.reduce((s, c) => s + (colonia.resources[c] ?? 0), 0)

  return (
    <>
      <button
        onClick={() => recolhivel && setAberto((a) => !a)}
        disabled={!recolhivel}
        className="text-rust eyebrow mt-5 flex w-full items-center justify-between first:mt-0"
      >
        <span>{titulo}</span>
        {recolhivel && (
          <span className="text-ink-soft text-xs tabular-nums">
            {total.toLocaleString('pt-BR')} {aberto ? '▾' : '▸'}
          </span>
        )}
      </button>

      {aberto && (
        <div className="mt-2">
          {codigos.map((c) => (
            <Linha key={c} codigo={c} valor={colonia.resources[c] ?? 0} />
          ))}
        </div>
      )}
    </>
  )
}

/**
 * Os 26 recursos do GDD, nas três classes do §8.3 (D-59, item 4).
 *
 * A tela antiga mostrava 9 e chamava Metal Bruto de industrial. Ele é **primário** no GDD, e é daí
 * que sai a alíquota do tributo e o teto do depósito — a classe não é rótulo, é regra.
 */
export function Recursos({ colonia }: { colonia: Colonia }) {
  return (
    <div className="painel bg-sand-light max-h-[calc(100vh-13rem)] w-64 overflow-y-auto p-4">
      <Bloco titulo="Recursos primários" codigos={PRIMARIOS} colonia={colonia} />
      <Bloco titulo="Recursos industriais" codigos={INDUSTRIAIS} colonia={colonia} />
      <Bloco titulo="Recursos raros" codigos={RAROS} colonia={colonia} recolhivel />
    </div>
  )
}

function Contagem({ ate }: { ate: string | null }) {
  if (!ate) return <span className="text-ink-soft text-xs">na fila</span>
  const restam = Math.max(0, Math.round((new Date(ate).getTime() - Date.now()) / 1000))
  const m = Math.floor(restam / 60)
  const s = restam % 60
  return (
    <span className="text-rust font-bold tabular-nums">
      {m}:{String(s).padStart(2, '0')}
    </span>
  )
}

export function FilaDeObras({ fila }: { fila: Fila }) {
  return (
    <div className="painel bg-sand-light w-72 p-4">
      <div className="flex items-baseline justify-between">
        <span className="text-rust eyebrow">Fila de construção</span>
        <span className="text-ink-soft text-xs tabular-nums">
          {fila.used}/{fila.slots}
        </span>
      </div>

      {fila.items.length === 0 && (
        <p className="text-ink-soft mt-3 text-sm">Nada em obra.</p>
      )}

      <div className="mt-3 space-y-2">
        {fila.items.map((i) => (
          <div key={i.position} className="border-rust/15 border p-2">
            <div className="flex items-center justify-between">
              <span className="text-ink text-sm font-bold">{rotulo(i.building)}</span>
              <Contagem ate={i.finishes_at} />
            </div>
            <div className="text-ink-soft mt-0.5 text-xs">
              nível {i.target_level}
              {i.subsidized && <span className="text-rust"> · custeado pelo Governo</span>}
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}

/**
 * As três receitas de Componentes Eletrônicos (§24.5). Só a Oficina as tem.
 *
 * O `PATCH /buildings/{id}/recipe` existia desde a fatia de fabricação e **nenhuma tela o
 * chamava**: o jogador ficava preso na Básica, que é só o padrão do D-23, sem saber que havia
 * escolha. A lista vem da API, não daqui — os insumos são do GDD e moram no banco.
 */
function ReceitaDaOficina({ spec, aoAtualizar }: { spec: Spec; aoAtualizar: () => void }) {
  const [receitas, setReceitas] = useState<Receita[]>([])
  const [erro, setErro] = useState<string | null>(null)
  const [salvando, setSalvando] = useState(false)

  useEffect(() => {
    api
      .receitas()
      .then(setReceitas)
      .catch((e: unknown) => setErro(e instanceof ApiError ? e.message : 'Falha ao ler as receitas.'))
  }, [])

  async function escolher(code: string) {
    if (code === spec.recipe || salvando) return
    setErro(null)
    setSalvando(true)
    try {
      await api.escolherReceita(spec.id, code)
      aoAtualizar()
    } catch (e) {
      setErro(e instanceof ApiError ? e.message : 'Falha ao trocar a receita.')
    } finally {
      setSalvando(false)
    }
  }

  const ativa = receitas.find((r) => r.code === spec.recipe)

  return (
    <>
      <div className="border-rust/30 my-3 border-t" />
      <div className="text-ink-soft eyebrow">Receita de Componentes</div>

      <div className="mt-2 space-y-1">
        {receitas.map((r) => (
          <button
            key={r.code}
            onClick={() => void escolher(r.code)}
            disabled={salvando}
            className={`block w-full px-2 py-1.5 text-left text-sm ${
              r.code === spec.recipe
                ? 'bg-rust text-sand-light'
                : 'text-ink-soft hover:bg-sand disabled:opacity-50'
            }`}
          >
            {r.nome}
            {r.padrao && r.code !== spec.recipe && <span className="text-xs"> · padrão</span>}
          </button>
        ))}
      </div>

      {ativa && (
        <>
          <p className="text-ink-soft/70 mt-2 text-xs">{ativa.contexto}</p>
          <div className="mt-2">
            {Object.entries(ativa.insumos_por_unidade).map(([c, v]) => (
              <Linha key={c} codigo={c} valor={v} />
            ))}
          </div>
          <p className="text-ink-soft/70 mt-1 text-xs">Insumos por unidade produzida.</p>
        </>
      )}

      {erro && <p className="text-rust mt-2 text-sm">{erro}</p>}
    </>
  )
}

/**
 * O que a construção FAZ — a primeira coisa que o painel diz (D-59, item 5).
 *
 * Duas camadas, e a distinção entre elas é o ponto: a **frase** é o que o GDD promete, com o § de
 * onde saiu; a **nota** é o que o jogo entrega, quando entrega menos. Sete construções ainda não
 * fazem nada, e a tela diz isso em vez de deixar o colono gastar 90 Ligas para descobrir sozinho.
 */
function OQueFaz({ funcao, atual, proximo }: { funcao: Funcao; atual: Efeito | null; proximo: Efeito | null }) {
  const producao = atual?.producao_hora ?? null
  const proxima = proximo?.producao_hora ?? null
  const insumo = atual?.insumo_hora ?? null
  const proximoInsumo = proximo?.insumo_hora ?? null

  return (
    <>
      <p className="text-ink mt-3 text-sm leading-snug">{funcao.frase}</p>
      <p className="text-ink-soft/60 mt-0.5 text-xs">GDD {funcao.fonte}</p>

      {producao && (
        <div className="mt-3">
          <div className="text-ink-soft eyebrow">Produz por hora</div>
          <div className="mt-1">
            {Object.entries(producao).map(([c, v]) => (
              <div
                key={c}
                className="border-rust/10 flex items-center justify-between border-b py-1.5 last:border-0"
              >
                <span className="text-ink-soft text-sm">{nomeRecurso(c)}</span>
                <span className="text-ink font-bold tabular-nums">
                  {v.toLocaleString('pt-BR')}
                  {proxima?.[c] !== undefined && proxima[c] !== v && (
                    <span className="text-rust text-xs"> → {proxima[c].toLocaleString('pt-BR')}</span>
                  )}
                </span>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* O que ela PROCESSA (consome como insumo), não produz — a Siderúrgica (D-82). */}
      {insumo && (
        <div className="mt-3">
          <div className="text-ink-soft eyebrow">Processa por hora</div>
          <div className="mt-1">
            {Object.entries(insumo).map(([c, v]) => (
              <div
                key={c}
                className="border-rust/10 flex items-center justify-between border-b py-1.5 last:border-0"
              >
                <span className="text-ink-soft text-sm">{nomeRecurso(c)}</span>
                <span className="text-ink font-bold tabular-nums">
                  {v.toLocaleString('pt-BR')}
                  {proximoInsumo?.[c] !== undefined && proximoInsumo[c] !== v && (
                    <span className="text-rust text-xs"> → {proximoInsumo[c].toLocaleString('pt-BR')}</span>
                  )}
                </span>
              </div>
            ))}
          </div>
        </div>
      )}

      {atual && atual.energia_hora > 0 && (
        <p className="text-ink-soft mt-2 text-xs">
          Consome {atual.energia_hora} kW/h de energia
          {proximo && proximo.energia_hora !== atual.energia_hora && (
            <span className="text-rust"> → {proximo.energia_hora}</span>
          )}
        </p>
      )}

      {/* A honestidade do D-59: a promessa do GDD e a entrega do jogo não são a mesma coisa. */}
      {funcao.nota && (
        <p className="border-rust/40 text-ink-soft mt-3 border-l-2 pl-2 text-xs leading-snug">
          {funcao.nota}
        </p>
      )}
    </>
  )
}

export function Detalhe({
  spec,
  colonia,
  aoConstruir,
  aoAtualizar,
  aoDemolir,
  aoAbrirPorta,
  aoFechar,
}: {
  spec: Spec | null
  // Só pro Depósito Local (D-105) mostrar os recursos — o resto do popup nem olha pra isto.
  colonia: Colonia
  aoConstruir: (s: Spec) => void
  aoAtualizar: () => void
  aoDemolir: (s: Spec) => void
  aoAbrirPorta: (tipo: string) => void
  // Desde o D-69 o detalhe é um POPUP, e um popup fecha. Antes era um card fixo na direita.
  aoFechar: () => void
}) {
  // O custo só aparece depois de o colono pedir para evoluir (D-59, item 5): a tela abre no que a
  // construção faz, não no que ela cobra.
  const [evoluindo, setEvoluindo] = useState(false)
  const [confirmandoDemolicao, setConfirmandoDemolicao] = useState(false)
  // D-61: a palavra que o colono tem de escrever. A API exige a mesma.
  const [palavra, setPalavra] = useState('')

  // Trocar de construção fecha os dois painéis: o custo da Oficina não pode ficar aberto quando o
  // colono clica na Mina.
  useEffect(() => {
    setEvoluindo(false)
    setConfirmandoDemolicao(false)
  }, [spec?.id])

  if (!spec) {
    return (
      <div className="painel bg-sand-light w-72 p-4">
        <div className="text-rust eyebrow">Construção</div>
        <p className="text-ink-soft mt-3 text-sm">
          Clique numa construção para ver o que ela faz, ou num slot vazio para erguer algo.
        </p>
      </div>
    )
  }

  const noMaximo = spec.next_level === undefined
  const min = spec.build_time_seconds ? Math.round(spec.build_time_seconds / 60) : null
  const emObra = spec.level === 0

  return (
    <Popup eyebrow="Construção" titulo={rotulo(spec.type)} aoFechar={aoFechar}>
      {/*
        A arte grande (1024×1024), quando existe (D-68). Sem imagem, nada aparece — e o cartão fica
        como sempre foi. É o mesmo fallback do hexágono: nada quebra por falta de arte.
      */}
      <ArteDaConstrucao tipo={spec.type} />
      <div className="text-ink-soft text-xs">
        {emObra ? 'em obra' : `nível ${spec.level} de ${spec.max_level}`}
        {spec.essencial && <span className="text-rust"> · essencial</span>}
      </div>

      <OQueFaz funcao={spec.funcao} atual={spec.efeito_atual} proximo={spec.efeito_proximo} />

      {/* O Depósito Local (D-105): os recursos deixaram de ficar sempre visíveis — abrir esta
          construção é agora o único jeito de vê-los, no desktop e no mobile. Mesmo componente da
          antiga barra lateral, só que morando aqui dentro. */}
      {spec.type === 'deposito_local' && (
        <div className="border-rust/30 my-3 border-t pt-3">
          <Recursos colonia={colonia} />
        </div>
      )}

      {/* A porta: a Central de Transportes abre a Frota, o Mercado Local abre os Acordos (item 6). */}
      {spec.funcao.efeito === 'porta' && !emObra && (
        <button
          onClick={() => aoAbrirPorta(spec.type)}
          className="bg-rust text-sand-light hover:bg-rust-bright mt-4 w-full py-2.5 font-bold"
        >
          {spec.type === 'central_de_transportes' ? 'Ver a Frota' : 'Abrir o Mercado'}
        </button>
      )}

      {spec.blocked === 'tempo_indefinido' && (
        <p className="text-rust mt-3 text-sm">
          O GDD não define tempo de construção para esta estrutura.
        </p>
      )}

      {noMaximo && !spec.blocked && (
        <p className="text-ink-soft mt-3 text-sm">Nível máximo atingido.</p>
      )}

      {spec.cost && !emObra && (
        <>
          <div className="border-rust/30 my-3 border-t" />

          {!evoluindo ? (
            <button
              onClick={() => setEvoluindo(true)}
              className="border-rust text-rust hover:bg-rust hover:text-sand-light w-full border py-2 font-bold"
            >
              Evoluir para o nível {spec.next_level}
            </button>
          ) : (
            <>
              <div className="text-ink-soft eyebrow">Custo do nível {spec.next_level}</div>
              <div className="mt-1">
                {Object.entries(spec.cost).map(([c, v]) => (
                  <Linha key={c} codigo={c} valor={v} />
                ))}
              </div>
              {min !== null && <div className="text-ink-soft mt-2 text-xs">Tempo: {min} min</div>}

              {/* §24.7, verbatim: a mensagem acompanha o custo, que continua exibido. */}
              {spec.subsidized && (
                <p className="text-rust mt-3 text-sm font-bold">
                  Esta construção será custeada pelo Governo Central até o nível 3
                </p>
              )}

              <button
                onClick={() => aoConstruir(spec)}
                className="bg-rust text-sand-light hover:bg-rust-bright mt-3 w-full py-2.5 font-bold"
              >
                Confirmar
              </button>
              <button
                onClick={() => setEvoluindo(false)}
                className="text-ink-soft hover:text-rust mt-1 w-full py-1 text-xs"
              >
                cancelar
              </button>
            </>
          )}
        </>
      )}

      {/* Oficina no nível 0 não fabrica nada; oferecer receita ali seria oferecer o vazio. */}
      {spec.type === 'oficina' && spec.level > 0 && (
        <ReceitaDaOficina spec={spec} aoAtualizar={aoAtualizar} />
      )}

      {/* Demolir: nunca uma essencial, nunca em obra. O investido não volta, e a tela avisa. */}
      {spec.demolivel && !emObra && (
        <>
          <div className="border-rust/30 my-3 border-t" />
          {!confirmandoDemolicao ? (
            <button
              onClick={() => setConfirmandoDemolicao(true)}
              className="text-ink-soft hover:text-rust w-full py-1 text-xs"
            >
              Demolir
            </button>
          ) : (
            <>
              <p className="text-ink-soft text-xs leading-snug">
                Demolir libera o slot. <span className="text-rust font-bold">Nada é devolvido</span> —
                os recursos investidos nos {spec.level} níveis se perdem.
              </p>

              {/*
                D-61: além de confirmar, o colono tem de ESCREVER a palavra.

                O botão de confirmação sozinho já foi clicado por engano. Escrever exige ler — e é a
                única defesa contra o dedo rápido numa ação que não tem volta e não devolve nada.

                A API exige a mesma palavra (`Demolir::PALAVRA`), e essa é a guarda de verdade: esta
                aqui protege o descuido; a de lá protege contra tudo o mais.
              */}
              <label className="text-ink-soft mt-2 block text-xs">
                Escreva <span className="text-rust font-bold">DEMOLIR</span> para confirmar:
                <input
                  value={palavra}
                  onChange={(e) => setPalavra(e.target.value)}
                  autoFocus
                  data-palavra-demolir
                  className="border-rust/40 bg-sand text-ink mt-1 w-full border px-2 py-1 font-mono text-sm"
                />
              </label>

              <button
                onClick={() => aoDemolir(spec)}
                disabled={palavra !== 'DEMOLIR'}
                data-demolir
                className="border-rust text-rust hover:bg-rust hover:text-sand-light disabled:border-ink-soft/25 disabled:text-ink-soft/40 mt-2 w-full border py-2 text-sm font-bold disabled:cursor-not-allowed disabled:hover:bg-transparent"
              >
                Demolir mesmo assim
              </button>
              <button
                onClick={() => {
                  setConfirmandoDemolicao(false)
                  setPalavra('')
                }}
                className="text-ink-soft hover:text-rust mt-1 w-full py-1 text-xs"
              >
                cancelar
              </button>
            </>
          )}
        </>
      )}
    </Popup>
  )
}

/**
 * O painel do slot vazio (D-59, item 2): o colono escolhe **o que** vai ali.
 *
 * A lista abre no que cada construção faz, não no preço — a mesma ordem do painel de detalhe. O
 * custo aparece quando ele escolhe uma. As já erguidas somem da lista, exceto as repetíveis (Mina,
 * Oficina, Refinaria, Destilaria), que podem ocupar quantos slots couberem.
 */
export function SlotVazio({
  slot,
  catalogo,
  aoErguer,
  aoFechar,
}: {
  slot: number
  catalogo: Catalogo | null
  aoErguer: (tipo: string, slot: number) => void
  aoFechar: () => void
}) {
  const [escolhida, setEscolhida] = useState<Erguivel | null>(null)

  // Trocar de slot desfaz a escolha: o custo da Mina não pode ficar aberto num slot que não é o dela.
  useEffect(() => setEscolhida(null), [slot])

  if (!catalogo) {
    return (
      <div className="painel bg-sand-light w-72 p-4">
        <div className="text-rust eyebrow">Slot vazio</div>
        <p className="text-ink-soft mt-3 text-sm">Carregando o catálogo…</p>
      </div>
    )
  }

  const disponiveis = catalogo.buildings.filter((b) => b.disponivel)

  return (
    <Popup eyebrow="Slot vazio" titulo={`Slot ${slot}`} aoFechar={aoFechar}>

      {!escolhida ? (
        <>
          <p className="text-ink-soft mt-2 text-sm">O que erguer aqui?</p>

          <div className="mt-3 space-y-1">
            {disponiveis.map((b) => (
              <button
                key={b.type}
                onClick={() => setEscolhida(b)}
                className="hover:bg-sand block w-full border border-transparent px-2 py-1.5 text-left"
              >
                <div className="text-ink text-sm font-bold">
                  {rotulo(b.type)}
                  {b.quantas > 0 && (
                    <span className="text-ink-soft text-xs font-normal"> · você tem {b.quantas}</span>
                  )}
                </div>
                <div className="text-ink-soft/80 text-xs leading-snug">{b.funcao.frase}</div>
                {/* A construção inerte é anunciada como inerte, aqui também — e não só depois de paga. */}
                {b.funcao.efeito === 'nenhum' && (
                  <div className="text-rust/70 text-xs">efeito ainda não implementado</div>
                )}
              </button>
            ))}
          </div>

          {disponiveis.length === 0 && (
            <p className="text-ink-soft mt-3 text-sm">
              Você já ergueu tudo o que existe. Os slots restantes esperam as construções das
              próximas fatias.
            </p>
          )}
        </>
      ) : (
        <>
          <h2 className="text-ink mt-1 text-lg leading-tight font-black">{rotulo(escolhida.type)}</h2>

          <OQueFaz funcao={escolhida.funcao} atual={null} proximo={null} />

          <div className="border-rust/30 my-3 border-t" />
          <div className="text-ink-soft eyebrow">Custo do nível 1</div>
          <div className="mt-1">
            {Object.entries(escolhida.cost).map(([c, v]) => (
              <Linha key={c} codigo={c} valor={v} />
            ))}
          </div>
          <div className="text-ink-soft mt-2 text-xs">
            Tempo: {Math.round(escolhida.build_time_seconds / 60)} min
          </div>

          <button
            onClick={() => aoErguer(escolhida.type, slot)}
            className="bg-rust text-sand-light hover:bg-rust-bright mt-4 w-full py-2.5 font-bold"
          >
            Construir aqui
          </button>
          <button
            onClick={() => setEscolhida(null)}
            className="text-ink-soft hover:text-rust mt-1 w-full py-1 text-xs"
          >
            escolher outra
          </button>
        </>
      )}
    </Popup>
  )
}

/**
 * A arte de uma construção, no cartão de detalhe (docs/decisoes.md D-68).
 *
 * ⚠️ **Usa a MESMA cache que a cena** (`game/arte.ts`), e não uma sua. A primeira versão tinha uma
 * cache própria aqui, e duas caches para a mesma tabela é o começo de uma divergência: a cena
 * mostrava o prédio no hexágono e o cartão não mostrava nada, e não havia como saber por quê sem ler
 * os dois arquivos. Uma fonte só.
 *
 * **Sem imagem, não renderiza nada.** O cartão fica exatamente como sempre foi. É o mesmo princípio
 * do hexágono: a falta de arte nunca é um buraco na tela, é só a ausência de um enfeite.
 */
function ArteDaConstrucao({ tipo }: { tipo: string }) {
  const [arte, setArte] = useState<Arte | null>(null)

  useEffect(() => {
    let vivo = true

    void carregarArte().then((a) => vivo && setArte(a))

    return () => {
      vivo = false
    }
  }, [])

  const img = arte?.[tipo]

  if (!img) return null

  return (
    <img
      src={img.grande}
      alt=""
      data-arte={tipo}
      className="border-rust/15 mt-3 w-full rounded border bg-white/30"
    />
  )
}