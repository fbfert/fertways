/**
 * O selo de estado — a etiqueta pequena que diz "em rota", "concluído", "sem
 * energia", "em cerco" (A2.V1).
 *
 * Vem em duas formas, e a escolha entre elas não é estética:
 *
 * - **`tom="claro"`** (padrão) põe a cor no TEXTO, sobre a superfície de areia.
 *   É o que se usa dentro de listas e tabelas, onde um retângulo colorido a cada
 *   linha vira ruído.
 * - **`tom="forte"`** põe a cor no FUNDO. É para o estado que precisa
 *   interromper a leitura — um cerco, uma falta de insumo.
 *
 * As duas formas usam pares já medidos por `tools/valida_contraste.py`. As cores
 * de estado são escuras (7,1 a 9,6:1 sobre areia) justamente para servirem como
 * texto; no tom forte, elas viram fundo e o texto claro passa por cima.
 */

type Estado = 'sucesso' | 'perigo' | 'aviso' | 'info' | 'neutro'

/*
 * `aviso` é o caso torto da paleta, e vale explicar para ninguém "consertar".
 *
 * O âmbar do deck (`ember`) dá **1,62:1** sobre areia — não serve como texto, de
 * jeito nenhum. Mas dá 8,71:1 como FUNDO com texto `ink`. Então aviso é o único
 * estado que não tem forma clara: ele sempre pinta o fundo, e sempre com letra
 * escura. É o inverso de todos os outros, e é assim porque foi medido.
 */
const CLARO: Record<Estado, string> = {
  sucesso: 'text-sucesso',
  perigo: 'text-perigo',
  aviso: 'bg-ember text-ink',
  info: 'text-info',
  neutro: 'text-ink-soft',
}

const FORTE: Record<Estado, string> = {
  sucesso: 'bg-sucesso text-sand-light',
  perigo: 'bg-perigo text-sand-light',
  aviso: 'bg-ember text-ink',
  info: 'bg-info text-sand-light',
  neutro: 'bg-ink-soft text-sand-light',
}

export function Selo({
  estado = 'neutro',
  tom = 'claro',
  children,
  className = '',
}: {
  estado?: Estado
  tom?: 'claro' | 'forte'
  children: React.ReactNode
  className?: string
}) {
  const cores = tom === 'forte' ? FORTE[estado] : CLARO[estado]
  const fundo = tom === 'forte' || estado === 'aviso' ? 'px-1.5 py-0.5 rounded' : ''

  return (
    <span
      className={`${cores} ${fundo} eyebrow inline-flex items-center gap-1 ${className}`}
    >
      {children}
    </span>
  )
}
