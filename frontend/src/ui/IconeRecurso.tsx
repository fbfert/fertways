/**
 * Ícones de recurso e de lado de oferta (COMPRA/VENDE) — pedido do usuário para o Mercado e os
 * dois depósitos (central e da colônia).
 *
 * Não existe nenhum sistema de ícone no jogo para isto: a arte de construção (D-68) é ilustração
 * grande, carregada em runtime para dentro do canvas do Phaser — o formato errado para 26 selos
 * pequenos de recurso, dentro de listas de DOM comuns. O molde que já existe e serve — SVG inline,
 * `currentColor`, dimensionado por Tailwind — é o de `MobileNav.tsx` (`IconeMapa`, `IconeCapital`
 * etc.); os selos de recurso seguem o mesmo espírito, mas como selo colorido com uma sigla, não
 * como desenho — desenhar 26 ícones à mão, um por recurso, não caberia nesta entrega, e uma sigla
 * (boa parte delas o próprio símbolo químico do elemento — Al, Cu, Sn, Li, Au, Si, Ta, W) já
 * distingue os 26 de relance, sem inventar 26 desenhos.
 *
 * A cor do selo é a CLASSE (primário/industrial/raro, §8.3) — a mesma classe que já governa o
 * tributo e o teto do depósito — não o recurso individual: import { PRIMARIOS, INDUSTRIAIS, RAROS }
 * decide a cor; a sigla decide a leitura.
 */
import { INDUSTRIAIS, PRIMARIOS } from './recursos'

const SIGLA: Record<string, string> = {
  // Primários (§8.3).
  oxigenio: 'OX',
  agua: 'AG',
  biomassa: 'BI',
  energia: 'EN',
  metal_bruto: 'MB',

  // Industriais — os 8 minerais usam o símbolo químico real do elemento: é a leitura mais rápida
  // que existe para quem já viu uma tabela periódica, e não inventa nada.
  ligas_metalicas: 'LM',
  compostos_quimicos: 'CQ',
  biocombustivel: 'BC',
  componentes_eletronicos: 'CE',
  aluminio: 'Al',
  cobre: 'Cu',
  estanho: 'Sn',
  litio: 'Li',
  ouro: 'Au',
  silicio: 'Si',
  tantalo: 'Ta',
  tungstenio: 'W',

  // Raros.
  bioenergia_curativa: 'BE',
  cristal_de_helio_3: 'He3',
  ferro_vermelho: 'FV',
  fungo_bioluminescente: 'FB',
  gelo_de_metano: 'GM',
  niobio_alienigena: 'Nb',
  plasma_fossilizado: 'PF',
  quartzo_piezoeletrico: 'QP',
  resina_organica: 'RO',
}

const CORES_POR_CLASSE = {
  primario: 'bg-rust text-sand-light',
  industrial: 'bg-ember text-ink',
  raro: 'bg-ink text-sand-light',
} as const

function classeDe(codigo: string): keyof typeof CORES_POR_CLASSE {
  if (PRIMARIOS.includes(codigo)) return 'primario'
  if (INDUSTRIAIS.includes(codigo)) return 'industrial'
  return 'raro'
}

/** Um selo pequeno, colorido pela classe do recurso (§8.3), com a sigla dele dentro. */
export function IconeRecurso({ codigo, className = '' }: { codigo: string; className?: string }) {
  const sigla = SIGLA[codigo] ?? codigo.slice(0, 2).toUpperCase()

  return (
    <span
      aria-hidden="true"
      className={`inline-flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full px-1 text-micro leading-none font-bold ${CORES_POR_CLASSE[classeDe(codigo)]} ${className}`}
    >
      {sigla}
    </span>
  )
}

/**
 * COMPRA/VENDE (Ofertas Globais): a mesma dupla de setas do resto do jogo (molde de
 * `MobileNav.tsx` — traço fino, `currentColor`, `viewBox 24×24`), só a direção muda. Sem cor
 * própria de propósito: o rótulo ao lado já é neutro (nem toda "VENDE" é um convite a vender, é
 * o anunciante que vende) — colorir o ícone de verde/vermelho sugeriria uma ação de quem lê, que
 * nem sempre é o caso.
 */
export function IconeCompra({ className = 'h-4 w-4' }: { className?: string }) {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      className={className}
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <path d="M12 4v12" />
      <path d="M6 12l6 6 6-6" />
      <path d="M4 21h16" />
    </svg>
  )
}

export function IconeVende({ className = 'h-4 w-4' }: { className?: string }) {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      className={className}
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      <path d="M12 20V8" />
      <path d="M6 12l6-6 6 6" />
      <path d="M4 21h16" />
    </svg>
  )
}
