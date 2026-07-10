import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from '../api/client'
import type { Acordo, ColoniaVizinha, Denuncia, Ministerio as Pasta, Reputacao, Violacao } from '../api/client'
import { dataHumana, fert, prazoHumano, segundosRestantes } from './recursos'

const INTERVALO_MS = 3000

const NOME_INDICE: Record<keyof Reputacao, string> = {
  confianca_comercial: 'Confiança Comercial',
  conduta_social: 'Conduta Social',
  status_civico: 'Status Cívico',
  honra_militar_diplomatica: 'Honra Militar/Diplomática',
}

/** O que cada índice mede, na coluna "o que mede" do §26.2. */
const MEDE: Record<keyof Reputacao, string> = {
  confianca_comercial: 'Acordos de Troca e avaliações de comércio',
  conduta_social: 'chat e denúncias de chat confirmadas',
  status_civico: 'tributos, missões da administração, terraformação',
  honra_militar_diplomatica: 'guerras, ataques a aliados, tratados',
}

const NOME_PUNICAO: Record<string, string> = {
  advertencia: 'Advertência',
  reducao: 'Redução de reputação',
  silencio: 'Silêncio temporário',
  restricao_comercial: 'Restrição comercial',
}

const NOME_VIOLACAO: Record<string, string> = {
  avaliacao_injusta: 'Avaliação de 1 estrela injusta',
  fraude_de_avaliacao: 'Fraude de avaliação ou conta vinculada',
  sonegacao: 'Sonegar tributo, burlar o sistema',
  abuso_em_chat: 'Abuso em chat',
  reincidencia_em_chat: 'Reincidência em chat',
  ataque_a_aliado: 'Atacar aliado registrado',
  quebra_de_tratado: 'Quebra de tratado',
  calote_reincidente: 'Calote deliberado e reincidente',
}

const ROTULO_STATUS: Record<Denuncia['status'], string> = {
  triagem: 'Em triagem',
  rejeitado: 'Rejeitada',
  atribuido: 'Com um conciliador',
  na_equipe: 'Com a equipe',
  decidido: 'Decidida',
  apelado: 'Apelada',
  revertido: 'Revertida',
  encerrado: 'Encerrada',
}

/**
 * Ministério das Reputações — GDD §9.1–9.4 e §26.6–26.8. Ver D-44 e D-47 a D-50.
 *
 * A tela existe para tornar visível o que o §26.8 chama de "regras formais de processo": a evidência
 * mínima, o prazo, o impedimento e a **tabela fixa de punições**. É por isso que ela mostra a pena
 * antes de o conciliador julgar. Ele decide se a violação ocorreu; a pena está escrita, e esconder
 * isso dele o convidaria a pensar que a escolhe.
 *
 * A "equipe do jogo" (§9.2) não aparece aqui: é o operador, fora do jogo, e julga por artisan.
 */
export function Ministerio({ aoFechar }: { aoFechar: () => void }) {
  const [aba, setAba] = useState<'reputacao' | 'denunciar' | 'casos'>('reputacao')
  const [pasta, setPasta] = useState<Pasta | null>(null)
  const [minhas, setMinhas] = useState<Denuncia[]>([])
  const [aJulgar, setAJulgar] = useState<Denuncia[]>([])
  const [vizinhas, setVizinhas] = useState<ColoniaVizinha[]>([])
  const [acordos, setAcordos] = useState<Acordo[]>([])
  const [erro, setErro] = useState<string | null>(null)

  const carregar = useCallback(async () => {
    try {
      const [m, d] = await Promise.all([api.ministerio(), api.denuncias()])
      setPasta(m)
      setMinhas(d.minhas)
      setAJulgar(d.a_julgar)
    } catch (e) {
      if (e instanceof ApiError) setErro(e.message)
    }
  }, [])

  useEffect(() => {
    void carregar()
    const t = setInterval(() => void carregar(), INTERVALO_MS)
    return () => clearInterval(t)
  }, [carregar])

  /*
   * O diretório e os acordos só mudam devagar, e a aba de denunciar precisa dos dois: de quem se
   * denuncia, e qual acordo quebrado serve de evidência (§26.8).
   */
  useEffect(() => {
    api.colonias().then((r) => setVizinhas(r.colonies)).catch(() => {})
    api.acordos().then((r) => setAcordos(r.agreements)).catch(() => {})
  }, [])

  // Faz os relógios das 48 h andarem sem bater na API.
  const [, tique] = useState(0)
  useEffect(() => {
    const t = setInterval(() => tique((n) => n + 1), 1000)
    return () => clearInterval(t)
  }, [])

  async function agir(acao: () => Promise<unknown>) {
    setErro(null)
    try {
      await acao()
      await carregar()
    } catch (e) {
      setErro(e instanceof ApiError ? e.message : 'Falha na operação.')
    }
  }

  const nomeDe = (id: number) => vizinhas.find((c) => c.id === id)?.nickname ?? `colônia #${id}`
  const pendentes = aJulgar.filter((d) => d.status === 'atribuido').length

  return (
    <div className="fixed inset-0 z-20 flex items-center justify-center bg-ink/70 p-4">
      <div className="painel bg-sand-light max-h-[90vh] w-full max-w-3xl overflow-y-auto p-6">
        <header className="flex items-start justify-between">
          <div>
            <div className="text-rust eyebrow">Ministério das Reputações</div>
            <h2 className="text-ink text-2xl font-black">Slot 7 da Capital</h2>
            <p className="text-ink-soft mt-1 text-sm">
              Recebe denúncias, investiga e pune. A pena de cada violação está escrita: o conciliador
              julga o fato, não a punição.
            </p>
          </div>
          <button onClick={aoFechar} className="text-ink-soft hover:text-rust text-2xl leading-none">
            ×
          </button>
        </header>

        <nav className="border-rust/20 mt-5 flex gap-1 border-b">
          {(['reputacao', 'denunciar', 'casos'] as const).map((a) => (
            <button
              key={a}
              onClick={() => setAba(a)}
              className={`eyebrow px-4 py-2 ${
                aba === a ? 'bg-rust text-sand-light' : 'text-ink-soft hover:text-rust'
              }`}
            >
              {a === 'reputacao' ? 'Minha reputação' : a === 'denunciar' ? 'Denunciar' : 'Casos'}
              {/* Só o que espera decisão dele. Um caso já julgado não é uma pendência. */}
              {a === 'casos' && pendentes > 0 && (
                <span className="bg-rust text-sand-light ml-2 px-1.5 py-0.5 text-xs">{pendentes}</span>
              )}
            </button>
          ))}
        </nav>

        {erro && <p className="text-rust mt-4 text-sm font-bold">{erro}</p>}

        {aba === 'reputacao' && pasta && <MinhaReputacao pasta={pasta} />}

        {aba === 'denunciar' && pasta && (
          <Denunciar
            catalogo={pasta.catalogo}
            vizinhas={vizinhas}
            acordos={acordos}
            agir={agir}
            aoDenunciar={() => setAba('casos')}
          />
        )}

        {aba === 'casos' && (
          <Casos minhas={minhas} aJulgar={aJulgar} nomeDe={nomeDe} agir={agir} />
        )}
      </div>
    </div>
  )
}

function MinhaReputacao({ pasta }: { pasta: Pasta }) {
  const { reputacao, conciliador } = pasta
  const indices = Object.keys(reputacao) as (keyof Reputacao)[]

  return (
    <div className="mt-5 space-y-6">
      <section>
        <div className="text-rust eyebrow">Os quatro índices</div>
        <p className="text-ink-soft mt-1 text-xs">
          Cada um é isolado: cumprir missões não recupera confiança perdida num calote (§26.9).
        </p>

        <div className="mt-3 space-y-3">
          {indices.map((i) => (
            <Barra
              key={i}
              titulo={NOME_INDICE[i]}
              mede={MEDE[i]}
              valor={reputacao[i]}
              limiar={i === 'confianca_comercial' ? pasta.limiar_mercado : null}
            />
          ))}
        </div>
      </section>

      {pasta.persona_non_grata && (
        <p className="border-rust text-rust border p-3 text-sm font-bold">
          Persona Non Grata: sua Confiança Comercial está abaixo de {pasta.limiar_mercado}. O Mercado
          Central e os leilões estão fechados para você. Só cumprir Acordos de Troca reabre —
          Status Cívico alto não compensa (§26.9).
        </p>
      )}

      <section>
        <div className="text-rust eyebrow">Punições vigentes</div>
        {pasta.punicoes.length === 0 ? (
          <p className="text-ink-soft mt-2 text-sm">Nenhuma. Você está limpo.</p>
        ) : (
          <div className="mt-2 space-y-2">
            {pasta.punicoes.map((p, i) => (
              <div key={i} className="border-rust/20 flex items-center justify-between border p-2">
                <div className="text-sm">
                  <span className="text-ink font-bold">{NOME_PUNICAO[p.kind] ?? p.kind}</span>
                  {p.points !== 0 && p.index_name && (
                    <span className="text-ink-soft">
                      {' '}
                      · {p.points} em {NOME_INDICE[p.index_name as keyof Reputacao]}
                    </span>
                  )}
                </div>
                {p.expires_at && (
                  <span className="text-rust text-xs font-bold">
                    termina em {prazoHumano(segundosRestantes(p.expires_at))}
                  </span>
                )}
              </div>
            ))}
          </div>
        )}
      </section>

      <section>
        <div className="text-rust eyebrow">O cargo de Conciliador</div>
        {conciliador.nomeado ? (
          <div className="border-rust/20 mt-2 border p-3 text-sm">
            <p className="text-ink font-bold">
              Você é Conciliador{conciliador.suspenso && ' — e está suspenso do cargo.'}
            </p>
            <p className="text-ink-soft mt-1 text-xs">
              {fert(conciliador.salario_diario_micro, 0)} Fert$ por dia, independente do volume de
              casos, e {fert(conciliador.bonus_micro, 0)} Fert$ por decisão que sobrevive à apelação
              (§26.7).
            </p>
            <p className="text-ink-soft mt-1 text-xs">
              Reversões: <span className="text-ink font-bold">{conciliador.reversoes}</span> de{' '}
              {conciliador.limite_reversoes}. Ao atingir o limite, o cargo é suspenso.
            </p>
          </div>
        ) : (
          <p className="text-ink-soft mt-2 text-sm">
            Você não é Conciliador. O cargo é nomeado pela administração de Fertways, e só ele
            confere o status de Neutro Registrado.
          </p>
        )}
      </section>
    </div>
  )
}

function Barra({
  titulo,
  mede,
  valor,
  limiar,
}: {
  titulo: string
  mede: string
  valor: number
  limiar: number | null
}) {
  const baixo = limiar !== null && valor < limiar

  return (
    <div>
      <div className="flex items-baseline justify-between">
        <span className="text-ink text-sm font-bold">{titulo}</span>
        <span className="text-ink font-black tabular-nums">
          {valor}
          <span className="text-ink-soft text-xs font-bold"> / 1000</span>
        </span>
      </div>

      <div className="bg-sand relative mt-1 h-2 w-full">
        <div className={baixo ? 'bg-rust h-2' : 'bg-rust-bright h-2'} style={{ width: `${valor / 10}%` }} />
        {limiar !== null && (
          <div className="bg-ink absolute top-0 h-2 w-px" style={{ left: `${limiar / 10}%` }} />
        )}
      </div>

      <p className="text-ink-soft mt-0.5 text-xs">move-se por {mede}</p>
    </div>
  )
}

/** A pena tabelada de uma violação, dita antes de qualquer julgamento (§26.8). */
function Pena({ v }: { v: Violacao }) {
  return (
    <p className="text-ink-soft text-xs">
      Se procedente:{' '}
      <span className="text-ink font-bold">
        {v.punicoes.map((p) => NOME_PUNICAO[p] ?? p).join(' + ')}
      </span>
      {v.pontos !== 0 && (
        <>
          {' '}
          · <span className="text-rust font-bold">{v.pontos}</span> em {NOME_INDICE[v.indice]}
        </>
      )}
      {' · '}
      {v.fonte}
      {v.grave && <span className="text-rust font-bold"> · caso grave: julgado pela equipe</span>}
    </p>
  )
}

function Denunciar({
  catalogo,
  vizinhas,
  acordos,
  agir,
  aoDenunciar,
}: {
  catalogo: Violacao[]
  vizinhas: ColoniaVizinha[]
  acordos: Acordo[]
  agir: (a: () => Promise<unknown>) => Promise<void>
  aoDenunciar: () => void
}) {
  const [denunciado, setDenunciado] = useState<number | null>(null)
  const [violacao, setViolacao] = useState('')
  const [texto, setTexto] = useState('')
  const [acordoId, setAcordoId] = useState<number | null>(null)

  const alvo = vizinhas.find((c) => c.id === denunciado) ?? vizinhas[0]
  const escolhida = catalogo.find((v) => v.violation === violacao) ?? catalogo[0]

  /*
   * §26.8, evidência mínima: só um Acordo de Troca **quebrado, entre você e o denunciado** serve. É
   * o único registro par-a-par que o servidor guarda — um despacho avulso lança no ledger da
   * origem, sem o destino. Não há print de chat porque não há chat.
   *
   * Filtrar aqui, e não deixar o backend recusar depois, é a diferença entre uma tela que ensina a
   * regra e uma que a esconde até o colono errar.
   */
  const evidencias = acordos.filter((a) => a.status === 'quebrado' && a.counterparty_id === alvo?.id)
  const evidencia = evidencias.find((a) => a.id === acordoId) ?? evidencias[0]

  const valido = !!alvo && !!escolhida && !!evidencia && texto.trim().length >= 10

  if (vizinhas.length === 0) {
    return <p className="text-ink-soft mt-5 text-sm">Nenhuma outra colônia no servidor.</p>
  }

  return (
    <div className="mt-5 space-y-4">
      <div>
        <label className="text-rust eyebrow" htmlFor="denunciado">
          Quem você denuncia
        </label>
        <select
          id="denunciado"
          className="border-rust/25 bg-sand focus:border-rust mt-1 w-full border px-2 py-1.5 text-sm outline-none"
          value={alvo?.id ?? ''}
          onChange={(e) => {
            setDenunciado(Number(e.target.value))
            setAcordoId(null)
          }}
        >
          {vizinhas.map((c) => (
            <option key={c.id} value={c.id}>
              {c.nickname}
            </option>
          ))}
        </select>
      </div>

      <div>
        <label className="text-rust eyebrow" htmlFor="violacao">
          Violação
        </label>
        <select
          id="violacao"
          className="border-rust/25 bg-sand focus:border-rust mt-1 w-full border px-2 py-1.5 text-sm outline-none"
          value={escolhida?.violation ?? ''}
          onChange={(e) => setViolacao(e.target.value)}
        >
          {catalogo.map((v) => (
            <option key={v.violation} value={v.violation}>
              {NOME_VIOLACAO[v.violation] ?? v.violation}
            </option>
          ))}
        </select>
        {escolhida && (
          <div className="mt-1 space-y-1">
            <Pena v={escolhida} />
            {escolhida.inerte && (
              <p className="text-ink-soft text-xs">
                Esta violação depende de um sistema que Fertways ainda não tem. A denúncia fica
                registrada, e a punição só passa a morder quando ele existir.
              </p>
            )}
          </div>
        )}
      </div>

      <div>
        <label className="text-rust eyebrow" htmlFor="evidencia">
          Evidência
        </label>
        {evidencias.length === 0 ? (
          <p className="text-ink-soft mt-1 text-sm">
            Nenhum Acordo de Troca quebrado entre você e {alvo?.nickname}. Sem evidência, o
            Ministério rejeita a denúncia na triagem — é o que o §26.8 manda.
          </p>
        ) : (
          <>
            <select
              id="evidencia"
              className="border-rust/25 bg-sand focus:border-rust mt-1 w-full border px-2 py-1.5 text-sm outline-none"
              value={evidencia?.id ?? ''}
              onChange={(e) => setAcordoId(Number(e.target.value))}
            >
              {evidencias.map((a) => (
                <option key={a.id} value={a.id}>
                  Acordo #{a.id}, vencido em {dataHumana(a.deadline_at)}
                </option>
              ))}
            </select>
            <p className="text-ink-soft mt-1 text-xs">
              Só um Acordo de Troca quebrado entre vocês dois. É o único log de transação par-a-par
              que o servidor guarda.
            </p>
          </>
        )}
      </div>

      <div>
        <label className="text-rust eyebrow" htmlFor="texto">
          O que aconteceu
        </label>
        <textarea
          id="texto"
          rows={4}
          className="border-rust/25 bg-sand focus:border-rust mt-1 w-full border px-2 py-1.5 text-sm outline-none"
          placeholder="Descreva o caso. O conciliador lê isto, com o log do acordo e o histórico de reputação de vocês dois."
          value={texto}
          onChange={(e) => setTexto(e.target.value)}
        />
      </div>

      <button
        disabled={!valido}
        onClick={() => {
          if (!alvo || !escolhida || !evidencia) return
          void agir(() =>
            api.denunciar({
              accused_colony_id: alvo.id,
              violation: escolhida.violation,
              texto,
              evidence_type: 'acordo_expirado',
              trade_agreement_id: evidencia.id,
            }),
          ).then(() => {
            setTexto('')
            aoDenunciar()
          })
        }}
        className="bg-rust text-sand-light hover:bg-rust-bright w-full py-3 font-bold disabled:cursor-not-allowed disabled:opacity-40"
      >
        Abrir denúncia
      </button>

      <p className="text-ink-soft text-xs">
        A triagem é imediata. Caso grave sobe à equipe do jogo; caso simples cai com um conciliador
        que não tenha negociado com nenhum de vocês nos últimos 30 dias (§26.8).
      </p>
    </div>
  )
}

function Casos({
  minhas,
  aJulgar,
  nomeDe,
  agir,
}: {
  minhas: Denuncia[]
  aJulgar: Denuncia[]
  nomeDe: (id: number) => string
  agir: (a: () => Promise<unknown>) => Promise<void>
}) {
  const pendentes = aJulgar.filter((d) => d.status === 'atribuido')

  /*
   * O conciliador não é parte nos casos que julga, então eles nunca aparecem em "minhas denúncias".
   * Sem esta seção, julgar faria o caso sumir da tela sem confirmação nenhuma.
   */
  const decididos = aJulgar.filter((d) => d.status !== 'atribuido')

  return (
    <div className="mt-5 space-y-6">
      {pendentes.length > 0 && (
        <section>
          <div className="text-rust eyebrow">A julgar</div>
          <p className="text-ink-soft mt-1 text-xs">
            Você tem 48 horas por caso. Passado o prazo, ele vai a outro conciliador — e o seu tempo
            foi perdido, não a reputação de ninguém.
          </p>
          <div className="mt-2 space-y-3">
            {pendentes.map((d) => (
              <Cartao key={d.id} d={d} nomeDe={nomeDe} agir={agir} julgando />
            ))}
          </div>
        </section>
      )}

      {decididos.length > 0 && (
        <section>
          <div className="text-rust eyebrow">Decididos por você</div>
          <p className="text-ink-soft mt-1 text-xs">
            O bônus de cada decisão cai quando a janela de apelação fecha sem reversão (§26.7).
          </p>
          <div className="mt-2 space-y-3">
            {decididos.map((d) => (
              <Cartao key={d.id} d={d} nomeDe={nomeDe} agir={agir} julgando />
            ))}
          </div>
        </section>
      )}

      <section>
        <div className="text-rust eyebrow">Minhas denúncias</div>
        {minhas.length === 0 ? (
          <p className="text-ink-soft mt-2 text-sm">Nenhuma denúncia sua, nem contra você.</p>
        ) : (
          <div className="mt-2 space-y-3">
            {minhas.map((d) => (
              <Cartao key={d.id} d={d} nomeDe={nomeDe} agir={agir} />
            ))}
          </div>
        )}
      </section>
    </div>
  )
}

function Cartao({
  d,
  nomeDe,
  agir,
  julgando = false,
}: {
  d: Denuncia
  nomeDe: (id: number) => string
  agir: (a: () => Promise<unknown>) => Promise<void>
  julgando?: boolean
}) {
  const restamParaDecidir = segundosRestantes(d.deadline_at)
  const restamParaApelar = segundosRestantes(d.appeal_until)
  const podeApelar = !julgando && d.status === 'decidido' && restamParaApelar > 0

  return (
    <div className="border-rust/20 border p-3">
      <div className="flex items-start justify-between gap-3">
        <div>
          <span className="text-ink font-bold">{NOME_VIOLACAO[d.violation] ?? d.violation}</span>
          <div className="text-ink-soft mt-0.5 text-xs">
            {julgando
              ? `${nomeDe(d.reporter_colony_id)} contra ${nomeDe(d.accused_colony_id)}`
              : d.eu_denunciei
                ? `Você denunciou ${nomeDe(d.accused_colony_id)}`
                : `${nomeDe(d.reporter_colony_id)} denunciou você`}
            {' · '}
            {d.fonte}
          </div>
        </div>
        <span className="border-rust/25 text-ink-soft eyebrow shrink-0 border px-2 py-1 text-xs">
          {ROTULO_STATUS[d.status]}
          {d.decision && ` · ${d.decision}`}
        </span>
      </div>

      <p className="text-ink-soft mt-2 text-sm">{d.texto}</p>

      <div className="mt-2">
        <Pena
          v={{
            violation: d.violation,
            indice: d.punicao_tabelada.indice,
            pontos: d.punicao_tabelada.pontos,
            punicoes: d.punicao_tabelada.punicoes,
            grave: d.grave,
            inerte: false,
            fonte: d.fonte,
          }}
        />
      </div>

      {julgando && d.status === 'atribuido' && (
        <>
          <p className="text-rust mt-2 text-xs font-bold">
            Restam {prazoHumano(restamParaDecidir)} para decidir.
          </p>
          <div className="mt-2 flex gap-2">
            <button
              onClick={() => void agir(() => api.decidirDenuncia(d.id, true))}
              className="bg-rust text-sand-light hover:bg-rust-bright flex-1 py-2 text-sm font-bold"
            >
              Procedente
            </button>
            <button
              onClick={() => void agir(() => api.decidirDenuncia(d.id, false))}
              className="border-rust/25 text-ink-soft hover:text-rust flex-1 border py-2 text-sm font-bold"
            >
              Improcedente
            </button>
          </div>
          <p className="text-ink-soft mt-1 text-xs">
            Você decide se a violação ocorreu. A punição está na tabela acima, e não é sua escolha.
          </p>
        </>
      )}

      {podeApelar && (
        <div className="mt-3 flex items-center gap-3">
          <p className="text-ink-soft text-xs">
            Restam {prazoHumano(restamParaApelar)} para apelar. A equipe do jogo revê o caso.
          </p>
          <button
            onClick={() => void agir(() => api.apelar(d.id))}
            className="border-rust/25 text-ink-soft hover:text-rust ml-auto border px-3 py-1.5 text-xs font-bold"
          >
            Apelar
          </button>
        </div>
      )}

      {d.status === 'decidido' && !podeApelar && d.decided_at && (
        <p className="text-ink-soft mt-2 text-xs">
          Decidida em {dataHumana(d.decided_at)}. A janela de apelação fechou.
        </p>
      )}
    </div>
  )
}
