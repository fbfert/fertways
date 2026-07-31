import { useCallback, useEffect, useRef, useState } from 'react'
import { api, ApiError } from '../api/client'
import type { ColoniaVizinha, MensagemDeChat } from '../api/client'
import { InfoJogador } from './InfoJogador'
import { painelFlutuante } from './painelFlutuante'

/**
 * O rádio do planeta (§10; docs/decisoes.md D-77) — um painel flutuante com os canais vivos:
 * Global, Vizinhança (um RAIO, não uma sala), Federação (D-115 — só aparece pra quem tem uma) e as
 * Privadas. (O canal de Região existiu e foi removido por pedido do usuário.)
 *
 * **Polling, não websocket** — arbitragem do usuário: o servidor tem 4 GB divididos com o banco de
 * produção, e um daemon Reverb é memória que o jogo não tem. Enquanto o painel está aberto, a aba
 * ativa consulta o servidor a cada 5 s pedindo só o que chegou depois da última mensagem (`after`).
 * Fechado, o chat não custa NADA — nem um request.
 */

type Aba = 'global' | 'vizinhanca' | 'federacao' | 'privadas'

const RITMO_MS = 5_000

export type AvisosDoChat = {
  privadas_nao_lidas: number
  mencoes_por_canal: { global: number; vizinhanca: number; federacao: number }
}

export function Chat({
  aoFechar,
  conversaInicial,
  aoConsumirConversaInicial,
  avisos,
}: {
  aoFechar: () => void
  /** Uma privada pedida de FORA do Chat (o "Conversar" da ficha do jogador, aberta do Mapa). */
  conversaInicial?: { id: number; nickname: string } | null
  aoConsumirConversaInicial?: () => void
  /**
   * O mesmo poll de 30 s que já acende o selo do botão Chat (D-77) — aqui só para dizer EM QUAL
   * aba está a novidade (pedido do usuário). `null` enquanto o primeiro poll não chegou.
   */
  avisos?: AvisosDoChat | null
}) {
  const [aba, setAba] = useState<Aba>('global')
  const [silenciadoAte, setSilenciadoAte] = useState<string | null>(null)
  const [meuNick, setMeuNick] = useState('')
  // A aba Federação só existe pra quem tem uma (D-115) — uma busca leve, uma vez, ao abrir o Chat.
  const [temFederacao, setTemFederacao] = useState(false)
  // A conversa privada aberta mora AQUI, e não dentro de `Privadas` — um clique no nick de uma
  // mensagem PÚBLICA precisa abri-la também, e `Canal` é irmão de `Privadas`, não filho.
  const [privadaAberta, setPrivadaAberta] = useState<{ id: number; nickname: string } | null>(null)
  const [infoAberta, setInfoAberta] = useState<number | null>(null)
  // A lupa mora na barra de abas, fora de `Privadas` — por isso o estado sobe até aqui, como o
  // da conversa aberta.
  const [buscaAberta, setBuscaAberta] = useState(false)

  useEffect(() => {
    void api.chatCanais().then((c) => {
      setSilenciadoAte(c.silenciado_ate)
      setMeuNick(c.nickname)
    })
    void api.minhaFederacao().then((f) => setTemFederacao(f.federation !== null))
  }, [])

  /**
   * Só diz QUAL aba, não QUANTO — o pontinho é mais discreto que o número do selo do botão, que já
   * fez esse trabalho. Latência aceita de até 30 s (o ritmo do poll do HUD, D-77): ler a aba limpa
   * a menção no servidor na hora, mas o pontinho só acompanha no próximo poll.
   */
  function temAviso(id: Aba): boolean {
    if (!avisos) return false
    if (id === 'privadas') return avisos.privadas_nao_lidas > 0
    return avisos.mencoes_por_canal[id] > 0
  }

  function abrirPrivada(id: number, nickname: string) {
    setPrivadaAberta({ id, nickname })
    setAba('privadas')
  }

  function abrirBusca() {
    setAba('privadas')
    setBuscaAberta(true)
  }

  // Chegou pedida de fora (Mapa → InfoJogador → "Conversar"): abre e avisa o pai que já consumiu,
  // para um `conversaInicial` velho não reabrir a mesma privada se o Chat fechar e reabrir.
  useEffect(() => {
    if (!conversaInicial) return
    abrirPrivada(conversaInicial.id, conversaInicial.nickname)
    aoConsumirConversaInicial?.()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [conversaInicial])

  return (
    <>
      <div className={painelFlutuante.chat} data-tela="chat">
        <div className="border-rust/20 flex items-center justify-between border-b px-3 py-2">
          <span className="text-rust eyebrow">Rádio do planeta</span>
          <button onClick={aoFechar} data-fechar-chat className="text-ink-soft hover:text-rust text-xl leading-none">
            ×
          </button>
        </div>

        <div className="border-rust/20 flex border-b text-xs">
          {(
            [
              ['global', 'Global'],
              ['vizinhanca', 'Vizinhança'],
              ...(temFederacao ? [['federacao', 'Federação']] : []),
              ['privadas', 'Privadas'],
            ] as [Aba, string][]
          ).map(([id, rotulo]) => (
            <button
              key={id}
              onClick={() => setAba(id)}
              data-aba-chat={id}
              className={`relative flex-1 py-2 font-bold ${aba === id ? 'bg-rust text-sand-light' : 'text-ink-soft hover:text-rust'}`}
            >
              {rotulo}
              {temAviso(id) && (
                <span
                  data-aviso-aba={id}
                  className={`absolute top-1.5 right-2 h-1.5 w-1.5 rounded-full ${aba === id ? 'bg-sand-light' : 'bg-rust'}`}
                />
              )}
            </button>
          ))}
          {/* Ao lado de Privadas, de propósito: buscar é o primeiro passo de uma privada nova. */}
          <button
            onClick={abrirBusca}
            data-buscar-jogador
            aria-label="Buscar jogadores"
            title="Buscar jogadores"
            className="text-ink-soft hover:text-rust px-2 font-bold"
          >
            🔍
          </button>
        </div>

        {silenciadoAte && (
          <p className="text-rust bg-sand px-3 py-1 text-xs">
            Você está em silêncio até {new Date(silenciadoAte).toLocaleString('pt-BR')} — as privadas
            continuam abertas.
          </p>
        )}

        {aba === 'privadas' ? (
          <Privadas
            aberta={privadaAberta}
            setAberta={setPrivadaAberta}
            aoVerInfo={setInfoAberta}
            buscaAberta={buscaAberta}
            setBuscaAberta={setBuscaAberta}
          />
        ) : (
          <Canal canal={aba} meuNick={meuNick} key={aba} aoAbrirPrivada={abrirPrivada} />
        )}
      </div>

      {infoAberta !== null && (
        <InfoJogador
          userId={infoAberta}
          aoFechar={() => setInfoAberta(null)}
          aoConversar={(id, nickname) => {
            setInfoAberta(null)
            abrirPrivada(id, nickname)
          }}
        />
      )}
    </>
  )
}

/** Um canal público (ou a federação, um círculo fechado de aliados): lista com polling + envio. */
function Canal({
  canal,
  meuNick,
  aoAbrirPrivada,
}: {
  canal: 'global' | 'vizinhanca' | 'federacao'
  meuNick: string
  aoAbrirPrivada: (id: number, nickname: string) => void
}) {
  const [mensagens, setMensagens] = useState<MensagemDeChat[]>([])
  const [texto, setTexto] = useState('')
  const [erro, setErro] = useState<string | null>(null)
  const ultimaRef = useRef(0)
  const fimRef = useRef<HTMLDivElement>(null)

  const puxar = useCallback(async () => {
    try {
      const r = await api.chatLer(canal, ultimaRef.current)
      if (r.mensagens.length > 0) {
        ultimaRef.current = r.mensagens[r.mensagens.length - 1].id
        setMensagens((atuais) => [...atuais, ...r.mensagens].slice(-100))
      }
    } catch {
      /* rede piscou; o próximo tique tenta de novo */
    }
  }, [canal])

  useEffect(() => {
    void puxar()
    const tique = setInterval(() => void puxar(), RITMO_MS)
    return () => clearInterval(tique)
  }, [puxar])

  useEffect(() => {
    fimRef.current?.scrollIntoView({ behavior: 'smooth' })
  }, [mensagens.length])

  async function falar() {
    const corpo = texto.trim()
    if (!corpo) return
    setErro(null)
    try {
      await api.chatFalar(canal, corpo)
      setTexto('')
      await puxar()
    } catch (e) {
      setErro(e instanceof ApiError ? e.message : 'A mensagem não saiu. Tente de novo.')
    }
  }

  return (
    <>
      <div className="flex-1 space-y-1 overflow-y-auto px-3 py-2 text-sm" data-mensagens>
        {mensagens.length === 0 && (
          <p className="text-ink-soft/60 text-xs">O canal está em silêncio — diga um oi.</p>
        )}
        {mensagens.map((m) => {
          // A citação: quem chamou o SEU nome sai destacado — é para isso que o selo trouxe você.
          const meCita = meuNick !== '' && m.body.toLowerCase().includes('@' + meuNick.toLowerCase())
          const souEu = meuNick !== '' && m.de.nickname === meuNick

          return (
            <p key={m.id} className={meCita ? 'border-rust bg-sand -mx-1 border-l-2 px-1' : undefined}>
              {/*
                Clicar no nick de OUTRO colono abre uma privada com ele — não faz sentido no seu
                próprio nome, então o seu continua texto puro, sem botão.
              */}
              {souEu ? (
                <strong className="text-rust">{m.de.nickname}</strong>
              ) : (
                <button
                  onClick={() => aoAbrirPrivada(m.de.id, m.de.nickname)}
                  data-abrir-privada={m.de.id}
                  className="text-rust hover:underline font-bold"
                >
                  {m.de.nickname}
                </button>
              )}{' '}
              <span className="text-ink">{m.body}</span>
            </p>
          )
        })}
        <div ref={fimRef} />
      </div>

      {erro && <p className="text-rust px-3 text-xs">{erro}</p>}

      <div className="border-rust/20 flex gap-2 border-t p-2">
        <input
          value={texto}
          onChange={(e) => setTexto(e.target.value)}
          onKeyDown={(e) => e.key === 'Enter' && void falar()}
          maxLength={500}
          placeholder="Fale com o planeta… (@nickname cita alguém)"
          data-chat-texto
          className="border-rust/30 bg-sand-light text-ink min-w-0 flex-1 border px-2 py-1 text-sm"
        />
        <button
          onClick={() => void falar()}
          data-chat-enviar
          className="bg-rust text-sand-light hover:bg-rust-bright px-3 text-sm font-bold"
        >
          →
        </button>
      </div>
    </>
  )
}

/** As conversas privadas: a busca, a lista, e dentro dela cada conversa com polling próprio. */
function Privadas({
  aberta,
  setAberta,
  aoVerInfo,
  buscaAberta,
  setBuscaAberta,
}: {
  aberta: { id: number; nickname: string } | null
  setAberta: (v: { id: number; nickname: string } | null) => void
  aoVerInfo: (userId: number) => void
  buscaAberta: boolean
  setBuscaAberta: (v: boolean) => void
}) {
  const [conversas, setConversas] = useState<
    { user_id: number; nickname: string; ultima: MensagemDeChat; nao_lidas: number }[]
  >([])
  const [busca, setBusca] = useState('')
  // Todo colono com colônia fundada — o mesmo diretório do Mapa (D-37), sem endpoint novo: quem
  // já é público lá não fica mais exposto por aparecer aqui também.
  const [jogadores, setJogadores] = useState<ColoniaVizinha[]>([])

  useEffect(() => {
    if (aberta) return
    const carregar = () => void api.chatConversas().then((r) => setConversas(r.conversas))
    carregar()
    const tique = setInterval(carregar, RITMO_MS)
    return () => clearInterval(tique)
  }, [aberta])

  useEffect(() => {
    if (!buscaAberta) return
    void api.colonias().then((d) => setJogadores(d.colonies))
  }, [buscaAberta])

  if (aberta) {
    return <Conversa com={aberta} aoVoltar={() => setAberta(null)} aoVerInfo={aoVerInfo} />
  }

  const termo = busca.trim().toLowerCase()
  // Nick OU nome da colônia: antes só batia nick, mas a linha do resultado já mostra os dois
  // (`{j.nickname} — {j.name}`), então quem digitasse o nome da colônia via o resultado na
  // tela e mesmo assim levava "ninguém com esse nick".
  const encontrados = termo
    ? jogadores.filter(
        (j) => j.nickname.toLowerCase().includes(termo) || j.name.toLowerCase().includes(termo),
      )
    : []

  return (
    <div className="flex-1 overflow-y-auto px-3 py-2 text-sm" data-conversas>
      {buscaAberta && (
        <div className="border-rust/20 mb-2 border-b pb-2" data-busca-jogador>
          <div className="flex gap-2">
            <input
              value={busca}
              onChange={(e) => setBusca(e.target.value)}
              placeholder="Buscar por nickname ou nome da colônia…"
              autoFocus
              data-buscar-texto
              className="border-rust/30 bg-sand-light text-ink min-w-0 flex-1 border px-2 py-1 text-sm"
            />
            <button
              onClick={() => {
                setBuscaAberta(false)
                setBusca('')
              }}
              data-fechar-busca
              className="text-ink-soft hover:text-rust text-xs"
            >
              fechar
            </button>
          </div>

          {termo && (
            <ul className="mt-2 space-y-1" data-resultados-busca>
              {encontrados.length === 0 ? (
                <li className="text-ink-soft/60 text-xs">Ninguém com esse nick ou colônia.</li>
              ) : (
                encontrados.map((j) => (
                  <li key={j.user_id}>
                    <button
                      onClick={() => aoVerInfo(j.user_id)}
                      data-resultado-busca={j.user_id}
                      className="hover:bg-sand block w-full px-1 py-1 text-left"
                    >
                      <strong className="text-rust">{j.nickname}</strong>{' '}
                      <span className="text-ink-soft text-xs">— {j.name}</span>
                    </button>
                  </li>
                ))
              )}
            </ul>
          )}
        </div>
      )}

      {conversas.length === 0 && (
        <p className="text-ink-soft/60 text-xs">
          Nenhuma conversa ainda. As privadas nascem no perfil dos outros colonos — ou aqui, quando
          alguém falar com você.
        </p>
      )}
      {conversas.map((c) => (
        <button
          key={c.user_id}
          onClick={() => setAberta({ id: c.user_id, nickname: c.nickname })}
          data-conversa={c.user_id}
          className="border-rust/10 hover:bg-sand block w-full border-b py-2 text-left"
        >
          <strong className="text-rust">{c.nickname}</strong>
          {c.nao_lidas > 0 && (
            <span className="bg-rust text-sand-light ml-2 rounded-full px-1.5 text-micro font-black" data-nao-lidas={c.nao_lidas}>
              {c.nao_lidas}
            </span>
          )}
          <span className={`block truncate text-xs ${c.nao_lidas > 0 ? 'text-ink font-bold' : 'text-ink-soft'}`}>
            {c.ultima.body}
          </span>
        </button>
      ))}
    </div>
  )
}

function Conversa({
  com,
  aoVoltar,
  aoVerInfo,
}: {
  com: { id: number; nickname: string }
  aoVoltar: () => void
  aoVerInfo: (userId: number) => void
}) {
  const [mensagens, setMensagens] = useState<MensagemDeChat[]>([])
  const [texto, setTexto] = useState('')
  const [erro, setErro] = useState<string | null>(null)
  const ultimaRef = useRef(0)
  const fimRef = useRef<HTMLDivElement>(null)

  const puxar = useCallback(async () => {
    try {
      const r = await api.chatPrivada(com.id, ultimaRef.current)
      if (r.mensagens.length > 0) {
        ultimaRef.current = r.mensagens[r.mensagens.length - 1].id
        setMensagens((atuais) => [...atuais, ...r.mensagens].slice(-100))
      }
    } catch {
      /* o próximo tique tenta */
    }
  }, [com.id])

  useEffect(() => {
    void puxar()
    const tique = setInterval(() => void puxar(), RITMO_MS)
    return () => clearInterval(tique)
  }, [puxar])

  useEffect(() => {
    fimRef.current?.scrollIntoView({ behavior: 'smooth' })
  }, [mensagens.length])

  async function falar() {
    const corpo = texto.trim()
    if (!corpo) return
    setErro(null)
    try {
      await api.chatFalarPrivado(com.id, corpo)
      setTexto('')
      await puxar()
    } catch (e) {
      setErro(e instanceof ApiError ? e.message : 'A mensagem não saiu.')
    }
  }

  async function bloquear() {
    await api.chatBloquear(com.id)
    aoVoltar()
  }

  return (
    <>
      <div className="border-rust/20 flex items-center justify-between border-b px-3 py-1 text-xs">
        <button onClick={aoVoltar} className="text-rust font-bold">
          ← conversas
        </button>
        {/* Aqui, ao contrário do canal público, o nick abre INFORMAÇÕES — não faz sentido mandar
            privada pra quem você já está mandando privada. */}
        <button
          onClick={() => aoVerInfo(com.id)}
          data-ver-info={com.id}
          className="text-ink hover:text-rust font-black"
        >
          {com.nickname}
        </button>
        {/* Bloquear é não ouvir: some da SUA tela e ele não te alcança mais — o resto do planeta segue ouvindo-o. */}
        <button onClick={() => void bloquear()} data-bloquear className="text-ink-soft hover:text-rust">
          bloquear
        </button>
      </div>

      <div className="flex-1 space-y-1 overflow-y-auto px-3 py-2 text-sm">
        {mensagens.map((m) => (
          <p key={m.id}>
            <strong className="text-rust">{m.de.nickname}</strong>{' '}
            <span className="text-ink">{m.body}</span>
          </p>
        ))}
        <div ref={fimRef} />
      </div>

      {erro && <p className="text-rust px-3 text-xs">{erro}</p>}

      <div className="border-rust/20 flex gap-2 border-t p-2">
        <input
          value={texto}
          onChange={(e) => setTexto(e.target.value)}
          onKeyDown={(e) => e.key === 'Enter' && void falar()}
          maxLength={500}
          placeholder={`Para ${com.nickname}…`}
          className="border-rust/30 bg-sand-light text-ink min-w-0 flex-1 border px-2 py-1 text-sm"
        />
        <button onClick={() => void falar()} className="bg-rust text-sand-light hover:bg-rust-bright px-3 text-sm font-bold">
          →
        </button>
      </div>
    </>
  )
}
