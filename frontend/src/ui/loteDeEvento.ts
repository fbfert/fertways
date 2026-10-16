import type { LoteDeEvento } from '../api/client'
import { prazoHumano, segundosRestantes } from './recursos'

/**
 * O lote de evento da Endurance dito por extenso (D-274). Sem isto a peça entrava e saía da loja
 * calada, e quem deixasse para comprar amanhã a acharia sumida. O evento secreto abre a porta sem
 * dizer o nome. Usado pela loja e pelo mapa dos destroços.
 */
export function rotuloDoLote(lote: LoteDeEvento): string {
  const quem = lote.nome ? `Lote do evento ${lote.nome}` : 'Lote por tempo limitado'

  return `${quem} · some em ${prazoHumano(segundosRestantes(lote.ate))}`
}
