import { useCallback, useEffect, useState } from 'react'
import { api, ApiError, token } from './api/client'
import type { Catalogo, Colonia, Fila, Spec } from './api/client'
import { ColonyCanvas } from './game/ColonyCanvas'
import { Capital } from './ui/Capital'
import { Endurance } from './ui/Endurance'
import { Extrato } from './ui/Extrato'
import { Frota } from './ui/Frota'
import { Fundacao } from './ui/Fundacao'
import { Login } from './ui/Login'
import { BugsMelhorias } from './ui/BugsMelhorias'
import { Chat } from './ui/Chat'
import type { AvisosDoChat } from './ui/Chat'
import { Missoes } from './ui/Missoes'
import { Mapa } from './ui/Mapa'
import { Header } from './ui/Header'
import { MobileNav } from './ui/MobileNav'
import { Route, Routes, useNavigate, useParams } from 'react-router-dom'
import { Mercado } from './ui/Mercado'
import { Quartel } from './ui/Quartel'
import { Zona } from './ui/Zona'
import { MinhasZonas } from './ui/MinhasZonas'
import { Ministerio } from './ui/Ministerio'
import { Perfil } from './ui/Perfil'
import { Popup } from './ui/Popup'
import { Detalhe, FilaDeObras, SlotVazio, TaxasDeRecursos } from './ui/Hud'

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
  // O erro de construir/evoluir/demolir agora é um popup à parte (pedido do usuário) — não mais
  // um texto inline dentro do card que já fechou.
  const [erroConstrucao, setErroConstrucao] = useState<string | null>(null)
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

  // Fecha o popup NA HORA do clique (pedido do usuário) — sucesso ou falha, o card não fica
  // esperando resposta. Se falhar, o aviso chega pelo popup de erroConstrucao, por cima.
  async function evoluir(spec: Spec) {
    setSelecionada(null)
    setErroConstrucao(null)
    try {
      await api.enfileirar(spec.id)
      await carregar()
    } catch (e) {
      setErroConstrucao(e instanceof ApiError ? e.message : 'Falha ao enfileirar.')
    }
  }

  async function erguer(tipo: string, slot: number) {
    setSlotVazio(null)
    setErroConstrucao(null)
    try {
      await api.construir(tipo, slot)
      await carregar()
    } catch (e) {
      setErroConstrucao(e instanceof ApiError ? e.message : 'Falha ao construir.')
    }
  }

  // Demolir continua com a confirmação digitada aberta (fluxo deliberadamente diferente) — só o
  // erro passa a ficar visível, o que hoje não acontecia (Detalhe nunca mostrava `erro` no ramo de
  // demolição).
  async function demolir(spec: Spec) {
    setErroConstrucao(null)
    try {
      await api.demolir(spec.id)
      setSelecionada(null)
      await carregar()
    } catch (e) {
      setErroConstrucao(e instanceof ApiError ? e.message : 'Falha ao demolir.')
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
  // A mesma resposta do poll, guardada inteira — o selo do botão só soma; o Chat usa o detalhe por
  // canal para acender a aba certa (pedido do usuário).
  const [avisosChat, setAvisosChat] = useState<AvisosDoChat | null>(null)
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
      void api
        .chatPendencias()
        .then((p) => {
          setChatPendente(p.privadas_nao_lidas + p.mencoes)
          setAvisosChat(p)
        })
        .catch(() => {})
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
   * (D-67). O header/barra de navegação não vivem mais aqui dentro — são globais agora
   * (reforma de navegação, pedido do usuário) e envolvem `<Routes>` lá embaixo.
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

      {/*
        A DIREITA deixou de ser o lugar do detalhe (D-69). O card da construção virou POPUP — por
        cima da colônia, que é o que lhe dá contexto —, e a barra lateral ficou para o que o colono
        precisa ver SEM clicar: a fila de obras e as zonas dele.
        A fila vem primeiro (D-88): é o que o colono acabou de mexer, e as zonas — que só aparecem
        quando ele tem alguma — empurravam a fila pra baixo da dobra em quem tinha várias.
      */}
      {/* Só desktop — no mobile, a mesma dupla vive dentro de "Mais" (`MobileNav.tsx`). */}
      <div className="absolute top-24 right-5 hidden w-64 space-y-4 md:block">
        {fila && <FilaDeObras fila={fila} />}
        {colonia && <TaxasDeRecursos colonia={colonia} />}
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
        />
      )}

      {slotVazio === null && selecionada && colonia && (
        <Detalhe
          spec={selecionada}
          colonia={colonia}
          aoConstruir={(s) => void evoluir(s)}
          aoAtualizar={() => void carregar()}
          aoDemolir={(s) => void demolir(s)}
          aoAbrirPorta={abrirPorta}
          aoFechar={() => setSelecionada(null)}
        />
      )}
    </div>
  )

  /**
   * O botão Colônia (novo, reforma de navegação): o único caminho de volta agora — cada tela
   * roteada perdeu o próprio `×`. `carregar()` é o que o antigo `aoFechar` de Ministério/Mercado já
   * fazia: atualiza o HUD na hora (uma condenação ou um depósito pode ter mexido em recurso), em
   * vez de esperar o poll de 5s.
   */
  const irParaColonia = () => {
    void carregar()
    navegar('/')
  }

  return (
    <>
      {/* Navegação global (pedido do usuário): antes só existia na rota `/`, agora envolve
          `<Routes>` inteiro e fica visível em qualquer tela — inclusive os popups que ela abre
          (Chat/Missões/Bugs-Melhorias/Extrato/erro de construção), que também deixam de existir só
          dentro da colônia. */}
      <Header
        colonia={colonia}
        chatPendente={chatPendente}
        aoAbrirChat={() => setChatAberto((v) => !v)}
        aoAbrirMissoes={() => setMissoesAbertas((v) => !v)}
        aoAbrirBugs={() => setBugsAbertos((v) => !v)}
        aoAbrirExtrato={() => setExtratoAberto(true)}
        aoIrColonia={irParaColonia}
        aoIrMapa={() => navegar('/mapa')}
        aoIrCapital={() => navegar('/capital')}
        aoIrPerfil={() => navegar('/perfil')}
        confirmandoSaida={confirmandoSaida}
        setConfirmandoSaida={setConfirmandoSaida}
        aoSair={() => void sair()}
      />

      {colonia && (
        <MobileNav
          colonia={colonia}
          fila={fila}
          chatPendente={chatPendente}
          aoAbrirChat={() => setChatAberto((v) => !v)}
          aoAbrirMissoes={() => setMissoesAbertas((v) => !v)}
          aoAbrirBugs={() => setBugsAbertos((v) => !v)}
          aoAbrirExtrato={() => setExtratoAberto(true)}
          aoIrColonia={irParaColonia}
          aoIrMapa={() => navegar('/mapa')}
          aoIrCapital={() => navegar('/capital')}
          aoIrPerfil={() => navegar('/perfil')}
          aoSair={() => void sair()}
        />
      )}

      <Routes>
        <Route path="/" element={jogo} />

        <Route
          path="/mapa"
          element={
            <Mapa
              aoAbrirCapital={() => navegar('/capital')}
              aoAbrirChatPrivado={(id, nickname) => {
                setConversaAlvo({ id, nickname })
                setChatAberto(true)
                navegar('/')
              }}
            />
          }
        />

        <Route path="/frota" element={<Frota />} />

        {/* O perfil do colono (D-69). `aoSalvar` recarrega o HUD: o nome da colônia aparece no topo. */}
        <Route path="/perfil" element={<Perfil aoSalvar={() => void carregar()} />} />
        <Route path="/quartel" element={<Quartel />} />

        {/* A zona neutra ocupada é um LUGAR, como a colônia e a Capital (D-67). */}
        <Route path="/zona/:id" element={<Zona />} />

        <Route
          path="/capital"
          element={
            <Capital
              aoAbrirMercado={() => navegar('/mercado/central')}
              aoAbrirMinisterio={() => navegar('/ministerio')}
            />
          }
        />

        <Route path="/ministerio" element={<Ministerio />} />

        {/* Os destroços da Endurance (D-132): rota própria, com mapa e Loja de Peças — não mais
            um `sub` de dentro do modal da Capital. */}
        <Route path="/capital/endurance" element={<Endurance />} />

        {/* O contexto do Mercado está na URL desde o D-67: o Local é a construção do colono, o Central
            é a instituição do governo. São duas telas, e agora são dois endereços. */}
        <Route path="/mercado/:contexto" element={<MercadoRota colonia={colonia} />} />

        {/* Endereço que não existe: volta à colônia, em vez de deixar a tela branca. */}
        <Route path="*" element={jogo} />
      </Routes>

      {chatAberto && colonia && (
        <Chat
          aoFechar={() => setChatAberto(false)}
          conversaInicial={conversaAlvo}
          aoConsumirConversaInicial={() => setConversaAlvo(null)}
          avisos={avisosChat}
        />
      )}
      {missoesAbertas && !chatAberto && colonia && <Missoes aoFechar={() => setMissoesAbertas(false)} />}
      {extratoAberto && colonia && <Extrato aoFechar={() => setExtratoAberto(false)} />}
      {bugsAbertos && !chatAberto && !missoesAbertas && colonia && (
        <BugsMelhorias aoFechar={() => setBugsAbertos(false)} />
      )}
      {erroConstrucao && (
        <Popup titulo="Não foi possível" aoFechar={() => setErroConstrucao(null)}>
          <p className="text-ink text-sm">{erroConstrucao}</p>
        </Popup>
      )}
    </>
  )
}

/**
 * O Mercado, pela URL. O `contexto` vem do endereço (`/mercado/local` ou `/mercado/central`) e não
 * de um estado que se perde ao recarregar.
 */
function MercadoRota({ colonia }: { colonia: Colonia | null }) {
  const { contexto } = useParams()

  if (!colonia) return null

  return <Mercado colonia={colonia} contexto={contexto === 'central' ? 'central' : 'local'} />
}