import { useCallback, useEffect, useState } from 'react'
import { api, ApiError, token } from './api/client'
import type { Catalogo, Colonia, Fila, Spec } from './api/client'
import { ColonyCanvas } from './game/ColonyCanvas'
import { Capital } from './ui/Capital'
import { Extrato } from './ui/Extrato'
import { Frota } from './ui/Frota'
import { Fundacao } from './ui/Fundacao'
import { Login } from './ui/Login'
import { BugsMelhorias } from './ui/BugsMelhorias'
import { Chat } from './ui/Chat'
import { Missoes } from './ui/Missoes'
import { Mapa } from './ui/Mapa'
import { Marca } from './ui/Marca'
import { MobileNav } from './ui/MobileNav'
import { Route, Routes, useNavigate, useParams } from 'react-router-dom'
import { Mercado } from './ui/Mercado'
import { Quartel } from './ui/Quartel'
import { Zona } from './ui/Zona'
import { MinhasZonas } from './ui/MinhasZonas'
import { Ministerio } from './ui/Ministerio'
import { Perfil } from './ui/Perfil'
import { Detalhe, FilaDeObras, Recursos, SlotVazio } from './ui/Hud'

/** Sem websocket nesta fase: polling simples, como o plano define. */
const INTERVALO_MS = 5000

/**
 * A colônia, e a navegação que sai dela (D-59, item 6).
 *
 * Os cinco botões do topo morreram. O que os substituiu:
 *
 *  - **Mapa** — único botão do topo, ao lado da marca. É de onde se sai da colônia.
 *  - **Capital** — clicando no losango dela no mapa. E é lá dentro que vivem o **Ministério** e o
 *    **Mercado Central**, que são instituições do governo (§2.1), não construções do colono.
 *  - **Frota** — dentro da Central de Transportes, a construção que o GDD diz gerir os veículos.
 *  - **Acordos** — dentro do Mercado Local, que o GDD define como "comércio direto com vizinhos".
 *
 * O efeito colateral é o ponto: para chegar a qualquer lugar, o colono passa por uma construção —
 * e é isso que dá peso à pergunta "o que esta construção faz?", que o item 5 finalmente responde.
 */
export default function App() {
  /**
   * As telas têm URL própria (D-67). Antes eram booleanos de estado — `mapaAberto`, `capitalAberta`
   * —, e por isso eram *popups*: sem endereço, sem histórico, e recarregar a página largava o colono
   * na colônia. Agora quem decide o que se vê é a URL.
   */
  const navegar = useNavigate()

  const [autenticado, setAutenticado] = useState(!!token.get())
  const [colonia, setColonia] = useState<Colonia | null>(null)
  const [specs, setSpecs] = useState<Spec[]>([])
  const [catalogo, setCatalogo] = useState<Catalogo | null>(null)
  const [fila, setFila] = useState<Fila | null>(null)
  const [selecionada, setSelecionada] = useState<Spec | null>(null)
  const [slotVazio, setSlotVazio] = useState<number | null>(null)
  const [erro, setErro] = useState<string | null>(null)
  const [semColonia, setSemColonia] = useState(false)

  const carregar = useCallback(async () => {
    try {
      const [c, s, f, cat] = await Promise.all([
        api.colonia(),
        api.construcoes(),
        api.fila(),
        api.catalogo(),
      ])
      setColonia(c)
      setSpecs(s)
      setFila(f)
      setCatalogo(cat)
      setSemColonia(false)
      // Mantém o painel de detalhe em sincronia com o nível recém-concluído. Se a construção foi
      // demolida, o painel se fecha sozinho em vez de mostrar um prédio que não existe mais.
      setSelecionada((atual) => (atual ? (s.find((x) => x.id === atual.id) ?? null) : null))
    } catch (e) {
      if (e instanceof ApiError && (e.status === 404 || e.code === 'sem_colonia')) {
        setSemColonia(true)
      } else if (e instanceof ApiError && e.status === 401) {
        token.clear()
        setAutenticado(false)
      }
    }
  }, [])

  /**
   * Sair de verdade: revoga o token no servidor antes de apagá-lo daqui.
   *
   * Se a chamada falhar — rede caída, ou token já inválido porque o servidor o revogou antes —
   * ainda assim saímos localmente. O pior desfecho de insistir seria prender o colono numa
   * sessão que ele pediu para encerrar.
   */
  const sair = useCallback(async () => {
    // Derruba o polling **antes** de revogar. Na ordem inversa, o `setInterval` de 5 s dispara
    // `/colony`, `/buildings` e `/queue` com um token que o servidor acabou de invalidar, e o
    // colono vê três 401 no console ao sair.
    setAutenticado(false)
    setColonia(null)
    // A URL volta à raiz: sair de dentro do Mercado não pode deixar `/mercado/local` no endereço.
    navegar('/')

    try {
      await api.logout()
    } catch {
      // Rede caída, ou token já revogado pelo servidor. Sair localmente mesmo assim: o pior
      // desfecho de insistir seria prender o colono numa sessão que ele pediu para encerrar.
    }

    token.clear()
  }, [])

  useEffect(() => {
    if (!autenticado) return
    void carregar()
    const t = setInterval(() => void carregar(), INTERVALO_MS)
    return () => clearInterval(t)
  }, [autenticado, carregar])

  // Faz o contador da fila andar de segundo em segundo, sem bater na API.
  const [, tique] = useState(0)
  useEffect(() => {
    const t = setInterval(() => tique((n) => n + 1), 1000)
    return () => clearInterval(t)
  }, [])

  async function evoluir(spec: Spec) {
    setErro(null)
    try {
      await api.enfileirar(spec.id)
      await carregar()
    } catch (e) {
      setErro(e instanceof ApiError ? e.message : 'Falha ao enfileirar.')
    }
  }

  async function erguer(tipo: string, slot: number) {
    setErro(null)
    try {
      await api.construir(tipo, slot)
      setSlotVazio(null)
      await carregar()
    } catch (e) {
      setErro(e instanceof ApiError ? e.message : 'Falha ao construir.')
    }
  }

  async function demolir(spec: Spec) {
    setErro(null)
    try {
      await api.demolir(spec.id)
      setSelecionada(null)
      await carregar()
    } catch (e) {
      setErro(e instanceof ApiError ? e.message : 'Falha ao demolir.')
    }
  }

  /**
   * A porta de uma construção: a Central de Transportes leva à Frota, o Mercado Local ao Mercado,
   * o Quartel à guerra.
   *
   * ⚠️ **Atravessar a porta FECHA o popup — e sem isto o jogo trava.** Desde o D-69 o detalhe da
   * construção é um popup com escurecimento por cima da colônia. Sem esta linha, o colono clicava no
   * Mercado Local, entrava no Mercado, voltava — e encontrava o popup ainda aberto, cobrindo tudo:
   * o HUD, os recursos e o botão de sair ficavam **inalcançáveis atrás dele**.
   *
   * O e2e pegou: o teste de logout falhou porque o clique em "Sair" acertava o escurecimento. E o
   * card já cumpriu o papel dele — quem atravessou a porta não precisa mais dela aberta.
   */
  const [chatAberto, setChatAberto] = useState(false)
  const [missoesAbertas, setMissoesAbertas] = useState(false)
  const [bugsAbertos, setBugsAbertos] = useState(false)
  const [chatPendente, setChatPendente] = useState(0)
  // O "Sim/Não" do ícone de Sair (D-88) — fecha sozinho depois de confirmar, porque `sair()`
  // já tira `colonia` da tela e o dropdown não teria mais onde se ancorar.
  const [confirmandoSaida, setConfirmandoSaida] = useState(false)
  // O extrato bancário (D-93), aberto clicando o valor/palavra "Fert$" do card do HUD.
  const [extratoAberto, setExtratoAberto] = useState(false)
  // Uma privada pedida de FORA da colônia (D-86): o Mapa está numa rota própria, e o Chat só
  // existe dentro da rota "/" — este é o que atravessa a troca de rota entre os dois.
  const [conversaAlvo, setConversaAlvo] = useState<{ id: number; nickname: string } | null>(null)

  /*
   * O selo do rádio (D-77, aditivo): sem isto, uma privada só era vista se o colono abrisse a aba
   * por vontade própria. Poll de 30 s, duas contagens indexadas — barato o bastante para rodar
   * sempre; o painel aberto zera ao ler.
   */
  useEffect(() => {
    if (!colonia) return
    const puxar = () =>
      void api.chatPendencias().then((p) => setChatPendente(p.privadas_nao_lidas + p.mencoes)).catch(() => {})
    puxar()
    const tique = setInterval(puxar, 30_000)
    return () => clearInterval(tique)
  }, [colonia, chatAberto])


  function abrirPorta(tipo: string) {
    setSelecionada(null)

    if (tipo === 'central_de_transportes') return navegar('/frota')
    if (tipo === 'quartel') return navegar('/quartel')
    if (tipo === 'mercado_local') return navegar('/mercado/local')
  }

  if (!autenticado) return <Login aoEntrar={() => setAutenticado(true)} />

  if (semColonia) {
    // O colono escolhe a célula (D-51): o seletor visual substitui o antigo formulário só-de-nome.
    return (
      <div className="relative min-h-screen">
        <div className="absolute right-4 top-4 z-10">
          {/* Sem isto, quem entra e ainda não fundou colônia não tem como sair da conta. */}
          <button onClick={() => void sair()} className="text-ink-soft hover:text-rust text-xs">
            Sair
          </button>
        </div>
        <Fundacao aoFundar={carregar} />
      </div>
    )
  }

  /**
   * A colônia — a rota `/`. Deixou de ser "o app" e passou a ser **uma tela entre outras**
   * (D-67). Antes, tudo o mais era popup por cima dela.
   */
  const jogo = (
    <div className="relative h-screen w-screen overflow-hidden">
      <div className="absolute inset-0">
        <ColonyCanvas
          specs={specs}
          linhas={catalogo?.slots.linhas ?? []}
          onSelecionar={(s) => {
            setSlotVazio(null)
            setSelecionada(s)
          }}
          onSlotVazio={(slot) => {
            setSelecionada(null)
            setSlotVazio(slot)
          }}
        />
      </div>

      {/* Reforma mobile-first do HUD (pedido do usuário): este header é só desktop — os seis
          botões e os dois cartões não cabem numa tela de 360-430px. O `<MobileNav>` logo abaixo é
          o equivalente para `md:hidden`. */}
      <header className="pointer-events-none absolute inset-x-0 top-0 hidden items-start justify-between p-5 md:flex">
        {/* A marca e o Mapa, juntos: sair da colônia é ir ao mapa, e é o único caminho para fora. */}
        <div className="pointer-events-auto flex items-stretch gap-3">
          <div className="painel bg-sand-light flex items-center px-4 py-3">
            <Marca compacto />
          </div>

          {colonia && (
            <button
              onClick={() => navegar('/mapa')}
              className="painel bg-rust text-sand-light hover:bg-rust-bright eyebrow px-5"
            >
              Mapa
            </button>
          )}

          {/* Atalho para a Capital — antes só se chegava lá abrindo o Mapa e clicando no
              losango do Governo Central. */}
          {colonia && (
            <button
              onClick={() => navegar('/capital')}
              className="painel bg-sand-light text-rust hover:text-rust-bright eyebrow px-5"
            >
              Capital
            </button>
          )}

          {/* As Missões do §06 (D-78): a mão do dia, a semanal e a tutoria. */}
          {colonia && (
            <button
              onClick={() => setMissoesAbertas((v) => !v)}
              data-abrir-missoes
              className="painel bg-sand-light text-rust hover:text-rust-bright eyebrow px-5"
            >
              Missões
            </button>
          )}

          {/* O rádio do planeta (§10, D-77). Fechado, não custa um request. */}
          {colonia && (
            <button
              onClick={() => setChatAberto((v) => !v)}
              data-abrir-chat
              data-chat-pendente={chatPendente}
              className="painel bg-sand-light text-rust hover:text-rust-bright eyebrow relative px-5"
            >
              Chat
              {chatPendente > 0 && (
                <span className="bg-rust text-sand-light absolute -top-1.5 -right-1.5 flex h-5 min-w-5 items-center justify-center rounded-full px-1 text-[10px] font-black">
                  {chatPendente > 9 ? '9+' : chatPendente}
                </span>
              )}
            </button>
          )}

          {/* Bugs/Melhorias (D-95): ao lado do Chat — o mesmo lugar de onde se pede ajuda. */}
          {colonia && (
            <button
              onClick={() => setBugsAbertos((v) => !v)}
              data-abrir-bugs-melhorias
              className="painel bg-sand-light text-rust hover:text-rust-bright eyebrow px-5"
            >
              Bugs/Melhorias
            </button>
          )}
        </div>

        {colonia && (
          <div className="pointer-events-auto flex items-stretch gap-3">
            {/* O Marco (§03/§05, D-75), ao lado do saldo — o colono já tinha esse número no
                Perfil, mas o progresso é algo que se olha de relance, não algo que se busca. */}
            <div className="painel bg-sand-light px-5 py-3 text-right" data-marco={colonia.marco.numero}>
              <div className="text-rust eyebrow">Marco {colonia.marco.numero}</div>
              <div className="text-ink text-sm font-bold">{colonia.marco.titulo}</div>
              <div className="text-ink-soft text-xs tabular-nums">
                {colonia.marco.xp_do_proximo !== null
                  ? `${colonia.marco.xp.toLocaleString('pt-BR')} / ${colonia.marco.xp_do_proximo.toLocaleString('pt-BR')} XP`
                  : `${colonia.marco.xp.toLocaleString('pt-BR')} XP · máximo`}
              </div>
            </div>

            <div className="painel bg-sand-light px-5 py-3 text-right">
              <div className="text-rust eyebrow">{colonia.name}</div>
              {/* O valor e a palavra "Fert$" abrem o extrato bancário — o resto do card (o nome
                  da colônia) não é clicável, de propósito: só o saldo tem extrato para ver. */}
              <button
                onClick={() => setExtratoAberto(true)}
                data-abrir-extrato
                title="Ver extrato"
                className="text-ink hover:text-rust text-xl font-black tabular-nums"
              >
                {colonia.fert.toLocaleString('pt-BR', { minimumFractionDigits: 2 })}{' '}
                <span className="text-rust text-sm">Fert$</span>
              </button>
            </div>

            {/*
              O perfil (D-69). Ao lado do saldo e do nome da colônia, que é onde o colono já olha
              para se ver. Antes ele não podia sequer trocar a própria senha — tinha de pedir a um
              operador.
            */}
            <button
              onClick={() => navegar('/perfil')}
              aria-label="O seu perfil"
              title="O seu perfil"
              data-abrir-perfil
              className="painel bg-sand-light text-rust hover:bg-sand flex w-14 items-center justify-center"
            >
              <svg viewBox="0 0 24 24" className="h-6 w-6" fill="none" stroke="currentColor" strokeWidth="2">
                <circle cx="12" cy="8" r="4" />
                <path d="M4 21c0-4 3.6-6 8-6s8 2 8 6" strokeLinecap="round" />
              </svg>
            </button>

            {/*
              Sair virou ícone ao lado do perfil (D-88) — morava sozinho no canto inferior
              esquerdo, longe de onde o colono já olha para se ver. Ícone é mais fácil de clicar
              sem querer do que o botão de texto que havia antes, então pede confirmação: mesmo
              toggle "Sim/Não" que o resto do jogo já usa para ações que não voltam sozinhas
              (`Transportes.tsx`, sucatear veículo) — sair é reversível (basta entrar de novo),
              mas o clique tem de ser de propósito.
            */}
            <div className="relative flex">
              <button
                onClick={() => setConfirmandoSaida((v) => !v)}
                aria-label="Sair"
                title="Sair"
                data-sair
                className="painel bg-sand-light text-rust hover:bg-sand flex w-14 items-center justify-center"
              >
                <svg viewBox="0 0 24 24" className="h-6 w-6" fill="none" stroke="currentColor" strokeWidth="2">
                  <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" strokeLinecap="round" strokeLinejoin="round" />
                  <polyline points="16 17 21 12 16 7" strokeLinecap="round" strokeLinejoin="round" />
                  <line x1="21" y1="12" x2="9" y2="12" strokeLinecap="round" />
                </svg>
              </button>

              {confirmandoSaida && (
                <div className="painel bg-sand-light border-rust/30 absolute right-0 top-full z-10 mt-2 w-48 border p-3 text-right">
                  <p className="text-ink text-xs font-bold">Sair da conta?</p>
                  <div className="mt-2 flex justify-end gap-2">
                    <button
                      onClick={() => setConfirmandoSaida(false)}
                      className="text-ink-soft hover:text-ink px-2 py-1 text-xs"
                    >
                      Não
                    </button>
                    <button
                      onClick={() => void sair()}
                      data-confirmar-sair
                      className="bg-rust text-sand-light px-3 py-1 text-xs font-bold"
                    >
                      Sim
                    </button>
                  </div>
                </div>
              )}
            </div>
          </div>
        )}
      </header>

      {colonia && (
        <MobileNav
          colonia={colonia}
          chatPendente={chatPendente}
          aoAbrirChat={() => setChatAberto((v) => !v)}
          aoAbrirMissoes={() => setMissoesAbertas((v) => !v)}
          aoAbrirBugs={() => setBugsAbertos((v) => !v)}
          aoAbrirExtrato={() => setExtratoAberto(true)}
          aoIrMapa={() => navegar('/mapa')}
          aoIrCapital={() => navegar('/capital')}
          aoIrPerfil={() => navegar('/perfil')}
          aoSair={() => void sair()}
        />
      )}

      {chatAberto && colonia && (
        <Chat
          aoFechar={() => setChatAberto(false)}
          conversaInicial={conversaAlvo}
          aoConsumirConversaInicial={() => setConversaAlvo(null)}
        />
      )}
      {missoesAbertas && !chatAberto && colonia && <Missoes aoFechar={() => setMissoesAbertas(false)} />}
      {extratoAberto && colonia && <Extrato aoFechar={() => setExtratoAberto(false)} />}
      {bugsAbertos && !chatAberto && !missoesAbertas && colonia && (
        <BugsMelhorias aoFechar={() => setBugsAbertos(false)} />
      )}


      {/* Reforma mobile-first: escondida no mobile por ora — ainda sem lugar para abrir (o PR
          seguinte lhe dá um ícone próprio na barra inferior, como um sheet). */}
      {colonia && (
        <div className="absolute top-24 left-5 hidden md:block">
          <Recursos colonia={colonia} />
        </div>
      )}

      {/*
        A DIREITA deixou de ser o lugar do detalhe (D-69). O card da construção virou POPUP — por
        cima da colônia, que é o que lhe dá contexto —, e a barra lateral ficou para o que o colono
        precisa ver SEM clicar: a fila de obras e as zonas dele.
        A fila vem primeiro (D-88): é o que o colono acabou de mexer, e as zonas — que só aparecem
        quando ele tem alguma — empurravam a fila pra baixo da dobra em quem tinha várias.
      */}
      {/* Mesma reforma: escondida no mobile por ora, ganha um ícone próprio no PR seguinte. */}
      <div className="absolute top-24 right-5 hidden w-64 space-y-4 md:block">
        {fila && <FilaDeObras fila={fila} />}
        <MinhasZonas />
      </div>

      {/*
        O popup. **Não é uma tela com URL**, ao contrário de tudo o mais desde o D-67 — e é decisão
        do usuário: o detalhe de uma construção só faz sentido COM A COLÔNIA ATRÁS DELE. Uma tela
        cheia esconderia justamente o que dá contexto ao card.
      */}
      {slotVazio !== null && (
        <SlotVazio
          slot={slotVazio}
          catalogo={catalogo}
          aoErguer={(tipo, slot) => void erguer(tipo, slot)}
          aoFechar={() => setSlotVazio(null)}
          erro={erro}
        />
      )}

      {slotVazio === null && selecionada && (
        <Detalhe
          spec={selecionada}
          aoConstruir={(s) => void evoluir(s)}
          aoAtualizar={() => void carregar()}
          aoDemolir={(s) => void demolir(s)}
          aoAbrirPorta={abrirPorta}
          aoFechar={() => setSelecionada(null)}
          erro={erro}
        />
      )}

    </div>
  )

  /*
   * As telas têm URL própria (D-67). O botão Voltar do navegador funciona, recarregar a página não
   * larga o colono na colônia, e um link pode ser mandado a alguém.
   *
   * `voltar()` usa o histórico quando há para onde voltar, e cai na colônia quando não há — quem
   * abre `/zona/12` direto pelo endereço não tem histórico nenhum, e um Voltar que saísse do site
   * seria pior que nenhum.
   */
  const voltar = () => (window.history.length > 1 ? navegar(-1) : navegar('/'))

  return (
    <Routes>
      <Route path="/" element={jogo} />

      <Route
        path="/mapa"
        element={
          <Mapa
            aoFechar={voltar}
            aoAbrirCapital={() => navegar('/capital')}
            aoAbrirChatPrivado={(id, nickname) => {
              setConversaAlvo({ id, nickname })
              setChatAberto(true)
              navegar('/')
            }}
          />
        }
      />

      <Route path="/frota" element={<Frota aoFechar={voltar} />} />

      {/* O perfil do colono (D-69). `aoSalvar` recarrega o HUD: o nome da colônia aparece no topo. */}
      <Route
        path="/perfil"
        element={<Perfil aoFechar={voltar} aoSalvar={() => void carregar()} />}
      />
      <Route path="/quartel" element={<Quartel aoFechar={voltar} />} />

      {/* A zona neutra ocupada é um LUGAR, como a colônia e a Capital (D-67). */}
      <Route path="/zona/:id" element={<Zona aoFechar={voltar} />} />

      <Route
        path="/capital"
        element={
          <Capital
            aoFechar={voltar}
            aoAbrirMercado={() => navegar('/mercado/central')}
            aoAbrirMinisterio={() => navegar('/ministerio')}
          />
        }
      />

      <Route
        path="/ministerio"
        element={
          <Ministerio
            aoFechar={() => {
              // Uma condenação pode ter tirado recurso de circulação; o HUD reflete o estado novo.
              void carregar()
              voltar()
            }}
          />
        }
      />

      {/* O contexto do Mercado está na URL desde o D-67: o Local é a construção do colono, o Central
          é a instituição do governo. São duas telas, e agora são dois endereços. */}
      <Route
        path="/mercado/:contexto"
        element={
          <MercadoRota
            colonia={colonia}
            aoFechar={() => {
              // O depósito tira recurso do estoque na hora: o HUD tem de refletir isso já.
              void carregar()
              voltar()
            }}
          />
        }
      />

      {/* Endereço que não existe: volta à colônia, em vez de deixar a tela branca. */}
      <Route path="*" element={jogo} />
    </Routes>
  )
}

/**
 * O Mercado, pela URL. O `contexto` vem do endereço (`/mercado/local` ou `/mercado/central`) e não
 * de um estado que se perde ao recarregar.
 */
function MercadoRota({
  colonia,
  aoFechar,
}: {
  colonia: Colonia | null
  aoFechar: () => void
}) {
  const { contexto } = useParams()

  if (!colonia) return null

  return (
    <Mercado
      colonia={colonia}
      contexto={contexto === 'central' ? 'central' : 'local'}
      aoFechar={aoFechar}
    />
  )
}