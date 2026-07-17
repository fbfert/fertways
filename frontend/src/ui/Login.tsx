import { useLocation } from 'react-router-dom'
import { PaginaConstrucoes } from './landing/PaginaConstrucoes'
import { PaginaEstatisticas } from './landing/PaginaEstatisticas'
import { PaginaGuerra } from './landing/PaginaGuerra'
import { PaginaInicial } from './landing/PaginaInicial'
import { PaginaLuas } from './landing/PaginaLuas'
import { PaginaNpcs } from './landing/PaginaNpcs'
import { PaginaVeiculos } from './landing/PaginaVeiculos'

/**
 * A porta de entrada de quem não está logado (pedido do usuário, 2026-07-17): a landing page e as
 * páginas satélite do cabeçalho (Construções, Veículos, Guerra, 8 Luas, NPCs, Estatísticas).
 *
 * Um despachante por `pathname`, não `<Routes>` do react-router: `App.tsx` já devolve este
 * componente para QUALQUER caminho enquanto `!autenticado`, sem olhar a URL — então despachar aqui
 * dentro é o jeito de dar a cada link do cabeçalho uma URL de verdade (compartilhável, com
 * "voltar" funcionando) sem tocar em nada do roteamento do jogo.
 */
export function Login({ aoEntrar }: { aoEntrar: () => void }) {
  const { pathname } = useLocation()

  switch (pathname) {
    case '/construcoes':
      return <PaginaConstrucoes />
    case '/veiculos':
      return <PaginaVeiculos />
    case '/guerra':
      return <PaginaGuerra />
    case '/luas':
      return <PaginaLuas />
    case '/npcs':
      return <PaginaNpcs />
    case '/estatisticas':
      return <PaginaEstatisticas />
    default:
      return <PaginaInicial aoEntrar={aoEntrar} />
  }
}
