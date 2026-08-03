import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from '../api/client'
import type { Combate, ColoniaInimiga, EstadoDaGuerra, LinhaDoRanking, Unidade } from '../api/client'
import { Botao, Selo } from './sistema'
import { dataHumana } from './recursos'

/**
 * O Quartel (§27.1, §28.7; docs/decisoes.md D-66) — a porta da guerra.
 *
 * Duas coisas que o jogador não tem como adivinhar, e que a tela existe para dizer:
 *
 *  1. **Nada no jogo produz Nióbio Alienígena, e a Sentinela custa 3.** Quem ler só a tabela de
 *     custo vai procurar uma mina de Nióbio e não achar nenhuma — porque não existe. O governo
 *     vende, e é a única fonte. Sem isso, a colônia ergue **uma** Sentinela com o Nióbio do kit de
 *     fundação, e mais nenhuma, para sempre.
 *  2. **O nível do Quartel é o teto do nível da unidade.** Não está no GDD, e é o mesmo desenho da
 *     Central de Transportes (D-60): a fábrica limita o que sai dela.
 *
 * E uma que ela diz porque o §27.5 **quer** que ela diga: as batalhas em curso, **inclusive as em
 * que se está defendendo**. O combate dura ~2 h de propósito, "tempo suficiente para o defensor
 * receber notificação, recrutar reforços e despachá-los". Um defensor que não vê o ataque chegando
 * não tem o que o documento lhe promete.
 */

const NOMES: Record<Unidade['type'], string> = {
  sentinela: 'Sentinela',
  robo_minerador: 'Robô Minerador',
  infiltrador: 'Infiltrador',
  predador: 'Predador',
}

/** O que cada uma serve para fazer. O GDD diz; a tela repete, porque a tabela sozinha não conta. */
const PARA_QUE: Record<Unidade['type'], string> = {
  sentinela: 'A única que ataca. Invade e cerca zonas — e é a única que as defende de verdade.',
  robo_minerador: 'Extrai na zona. Em combate, defende com 25% de uma Sentinela e não ataca nada.',
  infiltrador: 'Sabotagem: desliga uma estrutura da zona. A Torre de Vigia pode vê-lo — e aí morre.',
  predador: 'Apreende um módulo da zona. Disputa contra o Abrigo de Robôs. Visto, morre.',
}

const TIPOS: Unidade['type'][] = ['sentinela', 'robo_minerador', 'infiltrador', 'predador']

/** Os três recursos do custo do Drone (§4.3), com nome de gente. */
const NOME_CUSTO: Record<string, string> = {
  componentes_eletronicos: 'Componentes',
  compostos_quimicos: 'Compostos',
  metal_bruto: 'Metal Bruto',
}

/**
 * O botão que faltava (D-70): despachar N Sentinelas — reforçar uma zona ou romper um cerco.
 *
 * O jogador diz **quantas**, e a tela escolhe **quais**: as mais inteiras primeiro. Trinta Sentinelas
 * nível 1 são intercambiáveis, e obrigar a marcar caixinha por caixinha seria trabalho sem escolha.
 */
function Despacho({
  rotulo,
  dica,
  disponiveis,
  ocupado,
  teste,
  aoDespachar,
}: {
  rotulo: string
  dica: string
  disponiveis: Unidade[]
  ocupado: boolean
  teste: string
  aoDespachar: (ids: number[]) => Promise<void>
}) {
  const [quantas, setQuantas] = useState(1)

  const max = disponiveis.length
  const n = Math.min(Math.max(1, quantas), Math.max(1, max))
  const escolhidas = disponiveis.slice(0, n)
  const forca = escolhidas.reduce((s, u) => s + u.ataque, 0)

  return (
    <div className="border-ink-soft/20 mt-2 border-t pt-2" data-despacho={teste}>
      <p className="text-ink-soft text-xs">{dica}</p>

      {max === 0 ? (
        <p className="text-rust mt-1 text-xs font-bold">
          Nenhuma Sentinela no pátio. Não há o que despachar — fabrique-as acima, se ainda houver
          tempo.
        </p>
      ) : (
        <div className="mt-2 flex flex-wrap items-center gap-2">
          <input
            type="number"
            min={1}
            max={max}
            value={quantas}
            onChange={(e) => setQuantas(Math.max(1, Number(e.target.value)))}
            className="w-20 rounded border px-2 py-1 text-sm"
            data-quantas={teste}
          />
          <span className="text-ink-soft text-xs">
            de {max} · {forca} de ataque
          </span>
          <button
            className="botao"
            disabled={ocupado}
            data-despachar={teste}
            onClick={() => void aoDespachar(escolhidas.map((u) => u.id))}
          >
            {rotulo}
          </button>
        </div>
      )}
    </div>
  )
}

export function Quartel() {
  const [dados, setDados] = useState<EstadoDaGuerra | null>(null)
  /*
   * A2.10: as colônias inimigas. Carregadas à parte da guerra de zona porque só existem dentro de
   * guerra federativa declarada — fora dela a lista é vazia e a seção nem aparece.
   */
  const [inimigos, setInimigos] = useState<{ tem_quartel: boolean; inimigos: ColoniaInimiga[] } | null>(null)
  const [combates, setCombates] = useState<Combate[]>([])
  const [ranking, setRanking] = useState<LinhaDoRanking[]>([])
  const [erro, setErro] = useState<string | null>(null)
  const [recibo, setRecibo] = useState<string | null>(null)
  const [ocupado, setOcupado] = useState(false)

  const [tipo, setTipo] = useState<Unidade['type']>('sentinela')
  const [nivel, setNivel] = useState(1)
  const [quantas, setQuantas] = useState(1)
  const [niobio, setNiobio] = useState(3)
  const [nivelDrone, setNivelDrone] = useState(1)

  const carregar = useCallback(async () => {
    try {
      const [g, c, r, i] = await Promise.all([
        api.guerra(),
        api.combates(),
        api.rankingDeGuerras(),
        // A2.10. `catch` próprio: uma falha na lista de inimigos não pode derrubar o Quartel inteiro,
        // que é a tela onde o jogador produz unidades e vê os combates dele.
        api.inimigos().catch(() => ({ tem_quartel: false, inimigos: [] })),
      ])
      setDados(g)
      setCombates(c.combats)
      setRanking(r.ranking)
      setInimigos(i)
    } catch (e) {
      setErro(e instanceof ApiError ? e.message : 'Falha ao carregar o Quartel.')
    }
  }, [])

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
    <div className="bg-sand fixed inset-0 z-20 overflow-y-auto">
      <div className="bg-sand-light mx-auto min-h-screen w-full max-w-3xl px-6 pt-20 pb-24 md:pt-28 md:pb-6">
        <div className="mb-4">
          <h2 className="text-xl font-bold">Quartel</h2>
        </div>
        {dentro}
      </div>
    </div>
  )

  if (erro && !dados) return moldura(<p className="text-rust text-sm font-bold">{erro}</p>)
  if (!dados) return moldura(<p className="text-ink-soft text-sm">Carregando…</p>)

  const semQuartel = dados.quartel_nivel < 1

  /*
   * As Sentinelas no pátio, **as mais inteiras primeiro** (D-70).
   *
   * Só elas socorrem: o Robô defende a zona onde já está, e o Infiltrador e o Predador não têm
   * ataque nenhum. Quem escolhe é a tela — o jogador diz *quantas*, não *quais*, porque escolher
   * unidade a unidade entre trinta Sentinelas idênticas não é decisão, é digitação. Manda as
   * saudáveis primeiro pelo motivo óbvio: ferida, ela vale menos (a força conta o HP).
   */
  const emCasa = dados.unidades
    .filter((u) => u.type === 'sentinela' && u.hp_pct > 0)
    .sort((a, b) => b.hp_pct - a.hp_pct)

  // Agrupa o exército: vinte Sentinelas nível 1 são uma linha, não vinte.
  const porGrupo = new Map<string, { u: Unidade; n: number; feridas: number }>()
  for (const u of dados.unidades) {
    const chave = `${u.type}:${u.level}`
    const g = porGrupo.get(chave) ?? { u, n: 0, feridas: 0 }
    g.n++
    if (u.hp_pct < 100) g.feridas++
    porGrupo.set(chave, g)
  }

  const sentinelasSaudaveis = emCasa.slice(0, 10).map((u) => u.id)

  return moldura(
    <div className="space-y-6" data-tela="quartel">
      {/*
        A2.10: marchar sobre colônia inimiga.

        ⚠️ Só aparece com guerra federativa declarada — e a lista vem do servidor já filtrada, para
        a tela não oferecer o que a regra recusaria. O `exposto` está aqui porque marchar sem saber
        o que se ganha não é decisão, é aposta.
      */}
      {(inimigos?.inimigos.length ?? 0) > 0 && (
        <section className="painel bg-sand p-4" data-secao="inimigos">
          <h3 className="eyebrow text-perigo mb-2">Colônias inimigas</h3>

          <p className="text-ink-soft mb-3 text-xs">
            Fora de guerra a colônia é <strong>inviolável</strong> (§01). Dentro dela, o saque leva
            só o <strong>excedente do Depósito</strong> — o protegido nunca é tocado.
          </p>

          <ul className="flex flex-col gap-2">
            {inimigos!.inimigos.map((c) => (
              <li key={c.id} className="border-ink/10 flex flex-wrap items-center gap-2 border-t pt-2">
                <strong className="text-ink text-sm">{c.nome}</strong>
                <span className="text-ink-soft/70 text-xs">
                  exposto: {c.exposto.toLocaleString('pt-BR')}
                  {c.torre > 0 && ` · Torre de Defesa n${c.torre} corta o espólio`}
                </span>

                {c.sob_cerco ? (
                  <Selo estado="aviso">já sob cerco</Selo>
                ) : (
                  <Botao
                    variante="perigo"
                    tamanho="pequeno"
                    onClick={() =>
                      agir(async () => {
                        await api.atacarColonia(c.id, sentinelasSaudaveis)

                        return `Tropa a caminho de ${c.nome}.`
                      })
                    }
                    disabled={!inimigos!.tem_quartel || sentinelasSaudaveis.length === 0}
                    data-atacar-colonia={c.id}
                    title={
                      !inimigos!.tem_quartel
                        ? 'Marchar sobre colônia exige Quartel erguido.'
                        : 'A marcha leva tempo: distância é pilar do jogo.'
                    }
                  >
                    Marchar
                  </Botao>
                )}
              </li>
            ))}
          </ul>
        </section>
      )}
      <header>
        <p className="text-ink-soft text-sm">
          {semQuartel
            ? 'Você não tem Quartel. Erga um na colônia: é lá que as unidades são produzidas (§27.1).'
            : `Nível ${dados.quartel_nivel} — produz unidades até o nível ${dados.quartel_nivel}.`}
        </p>
      </header>

      {/* ── o Nióbio, que é o freio de tudo ───────────────────────────────────────────────── */}
      <section className="painel bg-sand p-4" data-secao="niobio">
        <h3 className="font-bold">Nióbio Alienígena</h3>
        <p className="text-ink-soft mt-1 text-sm">
          <strong>Nada em Fertways produz Nióbio.</strong> A Sentinela custa 3 por unidade, e o
          Quartel custou outros 3 para erguer. O governo o vende do caixa do Tesouro — é a única
          fonte, e o caixa pode secar.
        </p>

        <div className="mt-3 flex flex-wrap items-center gap-3">
          <span className="text-sm">
            Em estoque: <strong data-niobio-estoque>{dados.niobio.em_estoque}</strong>
          </span>
          <span className="text-ink-soft text-sm">
            {dados.niobio.preco_fert.toFixed(3).replace('.', ',')} Fert$ a unidade
          </span>

          <input
            type="number"
            min={1}
            max={1000}
            value={niobio}
            onChange={(e) => setNiobio(Math.max(1, Number(e.target.value)))}
            className="w-24 rounded border px-2 py-1 text-sm"
            data-niobio-qtd
          />
          <button
            className="botao"
            disabled={ocupado}
            data-comprar-niobio
            onClick={() =>
              void agir(async () => {
                const r = await api.comprarNiobio(niobio)
                return `Comprados ${r.comprado} de Nióbio ao governo.`
              })
            }
          >
            Comprar do governo
          </button>
          <span className="text-ink-soft text-sm">
            = {(niobio * dados.niobio.preco_fert).toFixed(2).replace('.', ',')} Fert$
          </span>
        </div>
      </section>

      {/* ── a fábrica ─────────────────────────────────────────────────────────────────────── */}
      <section className="painel bg-sand p-4" data-secao="fabricar">
        <h3 className="font-bold">Fabricar</h3>

        <div className="mt-3 grid gap-2 sm:grid-cols-2">
          {TIPOS.map((t) => (
            <button
              key={t}
              onClick={() => setTipo(t)}
              data-unidade={t}
              className={`rounded border p-2 text-left text-sm ${
                tipo === t ? 'border-rust bg-sand-light font-bold' : 'border-ink-soft/30'
              }`}
            >
              <span className="block">{NOMES[t]}</span>
              <span className="text-ink-soft block text-xs">{PARA_QUE[t]}</span>
            </button>
          ))}
        </div>

        <div className="mt-3 flex flex-wrap items-center gap-3">
          <label className="text-sm">
            Nível{' '}
            <select
              value={nivel}
              onChange={(e) => setNivel(Number(e.target.value))}
              className="rounded border px-2 py-1"
              data-nivel
            >
              {[1, 2, 3, 4, 5].map((n) => (
                <option key={n} value={n} disabled={n > dados.quartel_nivel}>
                  {n}
                  {n > dados.quartel_nivel ? ' (acima do Quartel)' : ''}
                </option>
              ))}
            </select>
          </label>

          <input
            type="number"
            min={1}
            max={50}
            value={quantas}
            onChange={(e) => setQuantas(Math.max(1, Number(e.target.value)))}
            className="w-24 rounded border px-2 py-1 text-sm"
            data-quantidade
          />

          <button
            className="botao"
            disabled={ocupado || semQuartel}
            data-fabricar
            onClick={() =>
              void agir(async () => {
                const r = await api.fabricarUnidade(tipo, nivel, quantas)
                return `${r.fabricadas} × ${NOMES[tipo]} nível ${nivel}.`
              })
            }
          >
            Fabricar
          </button>
        </div>
      </section>

      {/* ── o hangar dos Drones (§21.4: o Quartel armazena e recarrega; a fábrica é a Oficina, D-74) */}
      <section className="painel bg-sand p-4" data-secao="drones">
        <h3 className="font-bold">Drones de Exploração</h3>
        <p className="text-ink-soft mt-1 text-xs">
          O único olho que atravessa a névoa: guarnição e depósito de zona alheia só se veem por
          Drone (D-74). <strong>Fabrica-se na Oficina</strong> — o Quartel só o guarda e recarrega
          (§21.4) — e a missão parte do <strong>mapa</strong>: clique numa zona de outro colono.
        </p>

        {dados.drones.length > 0 && (
          <ul className="mt-3 space-y-1 text-sm" data-hangar>
            {dados.drones.map((d) => (
              <li key={d.id} className="flex flex-wrap items-baseline gap-2" data-drone={d.id}>
                <span className="font-bold">{d.placa}</span>
                <span className="text-ink-soft text-xs">
                  nível {d.level} · raio {d.raio} slots · bateria {d.bateria_horas} h
                </span>
                <span className="text-rust text-xs">
                  {d.fase === null && 'no hangar'}
                  {d.fase === 'ida' && 'voando ao alvo'}
                  {d.fase === 'vigia' && `sobrevoando (volta ${d.chega_at ? dataHumana(d.chega_at) : '…'})`}
                  {d.fase === 'volta' && 'voltando'}
                </span>
              </li>
            ))}
          </ul>
        )}

        <div className="mt-3 flex flex-wrap items-center gap-3">
          <label className="text-sm">
            Nível{' '}
            <select
              value={nivelDrone}
              onChange={(e) => setNivelDrone(Number(e.target.value))}
              className="rounded border px-2 py-1"
              data-drone-nivel
            >
              {[1, 2, 3, 4, 5].map((n) => (
                <option key={n} value={n} disabled={n > dados.oficina_nivel}>
                  {n}
                  {n > dados.oficina_nivel ? ' (acima da Oficina)' : ''}
                </option>
              ))}
            </select>
          </label>

          <button
            className="botao"
            disabled={ocupado || dados.oficina_nivel < 1}
            data-fabricar-drone
            onClick={() =>
              void agir(async () => {
                const r = await api.fabricarDrone(nivelDrone)
                return `Drone ${r.placa} fabricado na Oficina.`
              })
            }
          >
            Fabricar Drone
          </button>

          <span className="text-ink-soft text-xs">
            custa{' '}
            {Object.entries(dados.drone_custos[nivelDrone] ?? {})
              .map(([r, q]) => `${q} ${NOME_CUSTO[r] ?? r}`)
              .join(' + ')}
          </span>
        </div>

        {dados.oficina_nivel < 1 && (
          <p className="text-rust mt-2 text-xs">Sem Oficina não há Drone: é ela que o fabrica (D-74).</p>
        )}
      </section>

      {/* ── o exército ────────────────────────────────────────────────────────────────────── */}
      <section data-secao="exercito">
        <h3 className="font-bold">O seu exército, em casa</h3>

        {porGrupo.size === 0 ? (
          <p className="text-ink-soft mt-2 text-sm">
            Nenhuma unidade no pátio. Sem Sentinela não se ataca nem se defende zona nenhuma.
          </p>
        ) : (
          <table className="mt-2 w-full text-sm">
            <thead className="text-ink-soft text-left text-xs">
              <tr>
                <th className="py-1">Unidade</th>
                <th>Nível</th>
                <th>Quantas</th>
                <th>Ataque</th>
                <th>Defesa</th>
                <th>Feridas</th>
              </tr>
            </thead>
            <tbody>
              {[...porGrupo.values()].map(({ u, n, feridas }) => (
                <tr key={`${u.type}:${u.level}`} className="border-ink-soft/20 border-t">
                  <td className="py-1">{NOMES[u.type]}</td>
                  <td>{u.level}</td>
                  <td data-grupo={`${u.type}-${u.level}`}>{n}</td>
                  <td>{u.ataque}</td>
                  <td>{u.defesa}</td>
                  <td className={feridas > 0 ? 'text-rust' : 'text-ink-soft'}>
                    {feridas > 0 ? feridas : '—'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}

        <p className="text-ink-soft mt-2 text-xs">
          Ferida, a unidade vale menos: ataque e defesa caem com o HP. Quem sai de uma batalha sai
          machucado, e não há enfermaria — o exército gasto ataca fraco.
        </p>
      </section>

      {/* ── as batalhas ───────────────────────────────────────────────────────────────────── */}
      <section data-secao="combates">
        <h3 className="font-bold">Batalhas em curso</h3>

        {combates.length === 0 ? (
          <p className="text-ink-soft mt-2 text-sm">Nenhuma. Nem atacando, nem sendo atacado.</p>
        ) : (
          <ul className="mt-2 space-y-2">
            {combates.map((c) => {
              /*
               * O que o defensor pode FAZER, que até o D-70 era nada.
               *
               *  - **Cercado**: nada entra nem sai (§28.10). Reforçar é impossível por desenho, e a
               *    única saída é romper — por isso os dois nunca aparecem juntos.
               *  - **Não cercado**: dá para socorrer, e o §27.5 desenhou o combate longo justamente
               *    para isso ("reforços tardios podem ainda mudar o resultado").
               */
              const meDefendo = !c.sou_o_atacante && c.tipo !== 'ruptura'
              const podeRomper = meDefendo && c.tipo === 'cerco' && c.status === 'em_curso'
              const podeReforcar = meDefendo && !c.cercada && !podeRomper

              return (
                <li
                  key={c.id}
                  className="painel bg-sand p-3 text-sm"
                  data-combate={c.id}
                  data-lado={c.sou_o_atacante ? 'atacante' : 'defensor'}
                >
                  <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <strong className={c.sou_o_atacante || c.tipo === 'ruptura' ? '' : 'text-rust'}>
                      {c.tipo === 'ruptura'
                        ? 'Sua força de socorro'
                        : c.sou_o_atacante
                          ? 'Você ataca'
                          : '⚠ Estão a atacar você'}{' '}
                      — {c.tipo} na zona ({c.zona.x}, {c.zona.y})
                    </strong>
                    <span className="text-ink-soft text-xs">
                      {c.status === 'marchando'
                        ? `marcha chega ${dataHumana(c.chega_at)}`
                        : `rodada ${c.rodada}`}
                    </span>
                  </div>

                  {c.status === 'em_curso' && (
                    <p className="text-ink-soft mt-1 text-xs">
                      Ataque {c.forca_ofensiva} × Defesa {c.forca_defensiva}
                      {c.exposto > 0 && ` · ${c.exposto} exposto ao saque`}
                      {c.prazo_at && ` · prazo ${dataHumana(c.prazo_at)}`}
                    </p>
                  )}

                  {podeReforcar && (
                    <Despacho
                      rotulo="Reforçar a zona"
                      dica={
                        c.status === 'marchando'
                          ? 'Ainda dá tempo: despache Sentinelas antes de a marcha chegar. Elas só contam quando chegam — e a marcha militar é 1,3× mais lenta.'
                          : 'A batalha já corre. Um reforço que chegue a tempo ainda muda o resultado (§27.5) — mas ele marcha, e a rodada é de 10 minutos.'
                      }
                      disponiveis={emCasa}
                      ocupado={ocupado}
                      teste={`reforcar-${c.id}`}
                      aoDespachar={(ids) =>
                        agir(async () => {
                          const r = await api.reforcar(c.zona.id, ids)
                          return `${r.marcharam} Sentinela(s) a caminho da zona (${c.zona.x}, ${c.zona.y}).`
                        })
                      }
                    />
                  )}

                  {podeRomper && (
                    <Despacho
                      rotulo="Romper o cerco"
                      dica="A zona está fechada: nada entra nem sai, nem tropa. Suas Sentinelas lutam FORA — sem Muralha, sem Torre, sem Bastião e sem a guarnição, que está presa lá dentro. Vencendo, o cerco cai. Perdendo, o socorro morre e as 48 h continuam a correr."
                      disponiveis={emCasa}
                      ocupado={ocupado}
                      teste={`romper-${c.id}`}
                      aoDespachar={(ids) =>
                        agir(async () => {
                          await api.romperCerco(c.id, ids)
                          return `${ids.length} Sentinela(s) marcham para romper o cerco.`
                        })
                      }
                    />
                  )}

                  {meDefendo && c.cercada && !podeRomper && (
                    <p className="text-rust mt-1 text-xs">
                      Zona cercada: reforço nenhum entra. Só rompendo o cerco.
                    </p>
                  )}
                </li>
              )
            })}
          </ul>
        )}
      </section>

      {/* ── o ranking de guerras (§27.13, D-128) ──────────────────────────────────────────── */}
      <section className="mt-6" data-secao="ranking-de-guerras">
        <h3 className="font-bold">Ranking de Guerras</h3>
        <p className="text-ink-soft mt-1 text-xs">
          Cinco frentes, cada uma normalizada pelo máximo do servidor, somadas com o peso do GDD:
          Zonas Conquistadas (25%), Vitórias (20%), Tempo de Controle (20%), Saque em Fert$ (15%) e
          Maior Sequência (10%).
        </p>

        {ranking.length === 0 ? (
          <p className="text-ink-soft mt-2 text-sm">Ninguém pontuou ainda.</p>
        ) : (
          <div className="mt-2 overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead>
                <tr className="text-ink-soft border-ink-soft/20 border-b">
                  <th className="py-1 pr-2">#</th>
                  <th className="py-1 pr-2">Colônia</th>
                  <th className="py-1 pr-2 text-right">Geral</th>
                  <th className="py-1 pr-2 text-right">Zonas</th>
                  <th className="py-1 pr-2 text-right">Vitórias</th>
                  <th className="py-1 pr-2 text-right">Controle (h)</th>
                  <th className="py-1 pr-2 text-right">Saque (F$)</th>
                  <th className="py-1 pr-2 text-right">Sequência</th>
                </tr>
              </thead>
              <tbody>
                {ranking.map((l, i) => (
                  <tr
                    key={l.colony_id}
                    className={`border-ink-soft/10 border-b ${l.mine ? 'text-rust font-bold' : ''}`}
                    data-linha-do-ranking={l.colony_id}
                  >
                    <td className="py-1 pr-2 tabular-nums">{i + 1}</td>
                    <td className="py-1 pr-2">{l.colony_name ?? `Colônia ${l.colony_id}`}</td>
                    <td className="py-1 pr-2 text-right tabular-nums">{l.geral}</td>
                    <td className="py-1 pr-2 text-right tabular-nums">{l.zonas_conquistadas}</td>
                    <td className="py-1 pr-2 text-right tabular-nums">{l.vitorias}</td>
                    <td className="py-1 pr-2 text-right tabular-nums">{l.tempo_de_controle_horas}</td>
                    <td className="py-1 pr-2 text-right tabular-nums">{l.saque_fert}</td>
                    <td className="py-1 pr-2 text-right tabular-nums">{l.sequencia}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {erro && <p className="text-rust text-sm font-bold">{erro}</p>}
      {recibo && <p className="text-sm font-bold">{recibo}</p>}
    </div>,
  )
}
