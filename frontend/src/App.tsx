import { useCallback, useEffect, useState } from 'react'
import { api, ApiError, token } from './api/client'
import type { Catalogo, Colonia, Fila, Spec } from './api/client'
import { ColonyCanvas } from './game/ColonyCanvas'
import { Capital } from './ui/Capital'
import { Frota } from './ui/Frota'
import { Fundacao } from './ui/Fundacao'
import { Login } from './ui/Login'
import { Mapa } from './ui/Mapa'
import { Marca } from './ui/Marca'
import { Mercado } from './ui/Mercado'
import { Ministerio } from './ui/Ministerio'
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
  const [autenticado, setAutenticado] = useState(!!token.get())
  const [colonia, setColonia] = useState<Colonia | null>(null)
  const [specs, setSpecs] = useState<Spec[]>([])
  const [catalogo, setCatalogo] = useState<Catalogo | null>(null)
  const [fila, setFila] = useState<Fila | null>(null)
  const [selecionada, setSelecionada] = useState<Spec | null>(null)
  const [slotVazio, setSlotVazio] = useState<number | null>(null)
  const [erro, setErro] = useState<string | null>(null)
  const [semColonia, setSemColonia] = useState(false)
  const [mercadoAberto, setMercadoAberto] = useState(false)
  const [abaDoMercado, setAbaDoMercado] = useState<'doca' | 'ofertar_colono'>('doca')
  const [ministerioAberto, setMinisterioAberto] = useState(false)
  const [mapaAberto, setMapaAberto] = useState(false)
  const [frotaAberta, setFrotaAberta] = useState(false)
  const [capitalAberta, setCapitalAberta] = useState(false)

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
    setMercadoAberto(false)
    setMinisterioAberto(false)
    setMapaAberto(false)
    setFrotaAberta(false)
    setCapitalAberta(false)
    setColonia(null)

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

  /** A porta de uma construção: a Central de Transportes leva à Frota, o Mercado Local aos Acordos. */
  function abrirPorta(tipo: string) {
    if (tipo === 'central_de_transportes') {
      setFrotaAberta(true)
      return
    }

    if (tipo === 'mercado_local') {
      setAbaDoMercado('ofertar_colono')
      setMercadoAberto(true)
    }
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

  return (
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

      <header className="pointer-events-none absolute inset-x-0 top-0 flex items-start justify-between p-5">
        {/* A marca e o Mapa, juntos: sair da colônia é ir ao mapa, e é o único caminho para fora. */}
        <div className="pointer-events-auto flex items-stretch gap-3">
          <div className="painel bg-sand-light flex items-center px-4 py-3">
            <Marca compacto />
          </div>

          {colonia && (
            <button
              onClick={() => setMapaAberto(true)}
              className="painel bg-rust text-sand-light hover:bg-rust-bright eyebrow px-5"
            >
              Mapa
            </button>
          )}
        </div>

        {colonia && (
          <div className="painel bg-sand-light pointer-events-auto px-5 py-3 text-right">
            <div className="text-rust eyebrow">{colonia.name}</div>
            <div className="text-ink text-xl font-black tabular-nums">
              {colonia.fert.toLocaleString('pt-BR', { minimumFractionDigits: 2 })}{' '}
              <span className="text-rust text-sm">Fert$</span>
            </div>
          </div>
        )}
      </header>

      {/* O mapa e a frota só leem: fechar não muda estoque nem saldo, e não precisa recarregar. */}
      {colonia && mapaAberto && (
        <Mapa
          aoFechar={() => setMapaAberto(false)}
          aoAbrirCapital={() => {
            setMapaAberto(false)
            setCapitalAberta(true)
          }}
        />
      )}

      {colonia && frotaAberta && <Frota aoFechar={() => setFrotaAberta(false)} />}

      {/* A Capital só lê: abrir/fechar não muda estoque nem saldo. Mercado Central e Ministério
          (slots 6 e 7) são instituições do governo e se alcançam por aqui — não por construção. */}
      {colonia && capitalAberta && (
        <Capital
          aoFechar={() => setCapitalAberta(false)}
          aoAbrirMercado={() => {
            setAbaDoMercado('doca')
            setMercadoAberto(true)
          }}
          aoAbrirMinisterio={() => setMinisterioAberto(true)}
        />
      )}

      {colonia && ministerioAberto && (
        <Ministerio
          aoFechar={() => {
            setMinisterioAberto(false)
            // Uma condenação pode ter tirado recurso de circulação; o HUD reflete o estado novo.
            void carregar()
          }}
        />
      )}

      {colonia && mercadoAberto && (
        <Mercado
          colonia={colonia}
          abaInicial={abaDoMercado}
          aoFechar={() => {
            setMercadoAberto(false)
            // O depósito tira recurso do estoque na hora: o HUD tem de refletir isso já.
            void carregar()
          }}
        />
      )}

      {colonia && (
        <div className="absolute top-24 left-5">
          <Recursos colonia={colonia} />
        </div>
      )}

      <div className="absolute top-24 right-5 space-y-4">
        {slotVazio !== null ? (
          <SlotVazio
            slot={slotVazio}
            catalogo={catalogo}
            aoErguer={(tipo, slot) => void erguer(tipo, slot)}
            aoFechar={() => setSlotVazio(null)}
            erro={erro}
          />
        ) : (
          <Detalhe
            spec={selecionada}
            aoConstruir={(s) => void evoluir(s)}
            aoAtualizar={() => void carregar()}
            aoDemolir={(s) => void demolir(s)}
            aoAbrirPorta={abrirPorta}
            erro={erro}
          />
        )}
        {fila && <FilaDeObras fila={fila} />}
      </div>

      <button
        onClick={() => void sair()}
        className="painel bg-sand-light text-ink-soft hover:text-rust hover:bg-sand eyebrow absolute bottom-4 left-5 px-4 py-2"
      >
        Sair
      </button>
    </div>
  )
}
