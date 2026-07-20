import { useCallback, useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api, ApiError } from '../api/client'
import { EnduranceMapa } from './EnduranceMapa'
import { LojaDaEndurance } from './LojaDaEndurance'

/**
 * Os destroços da Endurance of Mankind — agora uma tela própria (`/capital/endurance`, D-132), não
 * mais um painel de texto dentro do modal da Capital.
 *
 * **Por que rota própria, e não mais um `sub` do `Capital.tsx`.** O padrão dominante do app já é
 * rota de verdade (`/mapa`, `/zona/:id`, `/mercado/:contexto`) — só a Capital ainda trocava painel
 * por `useState` local, sem URL, sem sobreviver a um recarregamento. Como esta tela ganhou um mapa
 * zoomável de verdade (pedido do usuário), ela se junta ao padrão dominante em vez de esticar o
 * antigo.
 *
 * O GDD chama a Endurance de "fonte de peças históricas e missões narrativas" (§02) e liga peças ao
 * Marco (§05) sem publicar o que uma peça É. A Loja de Peças (`LojaDaEndurance`) é o que preenche
 * essa lacuna — ver `docs/decisoes.md` D-132 para a arbitragem completa (preço, bônus, teto). As
 * missões narrativas continuam sem existir; esta tela não finge o contrário.
 */
export function Endurance() {
  const navegar = useNavigate()
  const [lojaAberta, setLojaAberta] = useState(false)
  const [dados, setDados] = useState<Awaited<ReturnType<typeof api.endurance>> | null>(null)
  const [erro, setErro] = useState<string | null>(null)

  const carregar = useCallback(async () => {
    try {
      setDados(await api.endurance())
    } catch (e) {
      setErro(e instanceof ApiError ? e.message : 'Falha ao carregar a Loja de Peças.')
    }
  }, [])

  useEffect(() => {
    void carregar()
  }, [carregar])

  async function comprar(chave: string) {
    await api.comprarPecaDaEndurance(chave)
    await carregar()
  }

  return (
    <div className="bg-sand fixed inset-0 z-20 overflow-y-auto" data-tela="endurance">
      <div className="bg-sand-light mx-auto flex min-h-screen w-full max-w-6xl flex-col px-6 pt-20 pb-24 md:pt-28 md:pb-6">
        <header className="shrink-0">
          <div className="text-rust eyebrow">Capital — Oeste</div>
          <h2 className="text-ink text-2xl font-black">Destroços da Endurance of Mankind</h2>
          <p className="text-ink-soft mt-1 text-sm">
            Ela nunca voltará a voar. {lojaAberta ? 'A Loja de Peças, seção por seção.' : 'Clique num destroço para abrir a Loja de Peças.'}
          </p>
        </header>

        <div className="mt-4 flex flex-1 gap-4">
          {/* Os atalhos: dentro da Capital, não há hoje como pular de uma área para outra sem
              voltar à praça — esta barra existe só aqui, enquanto isso não for um padrão do app
              inteiro. */}
          <nav className="flex shrink-0 flex-col gap-2" aria-label="Atalhos da Capital" data-atalhos-capital>
            <button
              onClick={() => navegar('/capital')}
              className="border-rust/25 bg-sand hover:bg-rust hover:text-sand-light text-ink-soft w-32 border px-2 py-2 text-left text-xs font-bold"
              data-atalho="governo-central"
            >
              ‹ Governo Central
            </button>
            <button
              onClick={() => navegar('/mercado/central')}
              className="border-rust/25 bg-sand hover:bg-rust hover:text-sand-light text-ink-soft w-32 border px-2 py-2 text-left text-xs font-bold"
              data-atalho="mercado-central"
            >
              Mercado Central
            </button>
            <button
              onClick={() => navegar('/capital', { state: { abrirSub: 'espacoporto' } })}
              className="border-rust/25 bg-sand hover:bg-rust hover:text-sand-light text-ink-soft w-32 border px-2 py-2 text-left text-xs font-bold"
              data-atalho="espacoporto"
            >
              Espaçoporto
            </button>
          </nav>

          <div className="min-w-0 flex-1">
            {erro && <p className="text-rust mb-3 text-sm font-bold">{erro}</p>}

            {!lojaAberta && (
              <div className="h-[60vh] min-h-[360px]">
                <EnduranceMapa aoAbrirLoja={() => setLojaAberta(true)} />
              </div>
            )}

            {lojaAberta && dados && (
              <LojaDaEndurance dados={dados} aoComprar={comprar} aoFechar={() => setLojaAberta(false)} />
            )}
            {lojaAberta && !dados && !erro && <p className="text-ink-soft text-sm">Carregando…</p>}
          </div>
        </div>
      </div>
    </div>
  )
}
