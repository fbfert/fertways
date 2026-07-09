/** Cliente tipado da API. Os tipos espelham as respostas dos controllers do backend. */

/** O backend é montado sob este caminho, tanto em produção (Apache) quanto no proxy do Vite. */
const BASE = '/central'

const TOKEN_KEY = 'fertways.token'

export const token = {
  get: () => localStorage.getItem(TOKEN_KEY),
  set: (t: string) => localStorage.setItem(TOKEN_KEY, t),
  clear: () => localStorage.removeItem(TOKEN_KEY),
}

/** Erro de regra de jogo: o backend devolve 422 com um `code` estável. */
export class ApiError extends Error {
  status: number
  code: string | null

  constructor(status: number, code: string | null, message: string) {
    super(message)
    this.status = status
    this.code = code
  }
}

async function req<T>(path: string, init: RequestInit = {}): Promise<T> {
  const t = token.get()
  const r = await fetch(`${BASE}${path}`, {
    ...init,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(t ? { Authorization: `Bearer ${t}` } : {}),
      ...init.headers,
    },
  })

  if (r.status === 204) return undefined as T

  const corpo = await r.json().catch(() => ({}))

  if (!r.ok) {
    throw new ApiError(r.status, corpo.code ?? null, corpo.message ?? `HTTP ${r.status}`)
  }

  return corpo as T
}

export type Sessao = { token: string; user: { id: number; nickname: string } }

export type Colonia = {
  id: number
  name: string
  fert: number
  last_tick_at?: string
  buildings: { type: string; level: number }[]
  resources: Record<string, number>
}

export type Spec = {
  id: number
  type: string
  level: number
  max_level: number
  next_level?: number
  cost?: Record<string, number>
  build_time_seconds?: number
  subsidized?: boolean
  blocked?: string
}

export type ItemDaFila = {
  building: string
  target_level: number
  position: number
  status: 'queued' | 'building'
  subsidized: boolean
  cost: Record<string, number>
  finishes_at: string | null
}

export type Fila = { slots: number; used: number; items: ItemDaFila[] }

export type Veiculo = {
  id: number
  type: string
  level: number
  status: 'ocioso' | 'carregando' | 'em_rota' | 'descarregando'
  capacity: number
  leg: 'ida' | 'volta' | null
  trip_purpose: 'entrega' | 'retirada' | null
  distance_slots: number | null
  destination_type: string | null
  destination_id: number | null
  arrives_at: string | null
  cargo: Record<string, number> | null
}

export type Frota = {
  colony: { x: number; y: number }
  capital: { x: number; y: number }
  vehicles: Veiculo[]
}

/** O saldo do colono na doca do Mercado (§25.8). Não é estoque da colônia. */
export type ContaDoMercado = {
  capital: { x: number; y: number }
  distance_slots: number
  balances: { resource_type: string; amount: number }[]
}

export type Ordem = {
  id: number
  side: 'buy' | 'sell'
  price_micro: number
  qty: number
  status: 'aberta' | 'parcial' | 'executada' | 'cancelada'
}

export type Livro = {
  resource_type: string
  /** Referência exibida, não teto nem piso (§06, D-35). */
  preco_base_micro: number
  taxa_bps: number
  bids: { price_micro: number; qty: number }[]
  asks: { price_micro: number; qty: number }[]
  minhas_ordens: Ordem[]
}

export const api = {
  register: (b: { name: string; nickname: string; email: string; password: string }) =>
    req<Sessao>('/register', { method: 'POST', body: JSON.stringify(b) }),

  login: (b: { email: string; password: string }) =>
    req<Sessao>('/login', { method: 'POST', body: JSON.stringify(b) }),

  colonia: () => req<Colonia>('/colony'),

  fundarColonia: (name: string) =>
    req<Colonia>('/colony', { method: 'POST', body: JSON.stringify({ name }) }),

  construcoes: () => req<Spec[]>('/buildings'),

  enfileirar: (id: number) => req<ItemDaFila>(`/buildings/${id}/upgrade`, { method: 'POST' }),

  fila: () => req<Fila>('/queue'),

  frota: () => req<Frota>('/vehicles'),

  /** Leva carga do estoque até a doca do Mercado. O tributo incide na chegada (D-32). */
  depositar: (veiculo: number, cargo: Record<string, number>) =>
    req<Veiculo>(`/vehicles/${veiculo}/dispatch`, {
      method: 'POST',
      body: JSON.stringify({ destination_type: 'mercado_central', cargo }),
    }),

  /** Manda um veículo buscar carga da doca. O saldo é reservado já no despacho (D-32). */
  retirar: (veiculo: number, cargo: Record<string, number>) =>
    req<Veiculo>(`/vehicles/${veiculo}/withdraw`, {
      method: 'POST',
      body: JSON.stringify({ cargo }),
    }),

  conta: () => req<ContaDoMercado>('/market/account'),

  livro: (recurso: string) =>
    req<Livro>(`/market/orders?resource_type=${encodeURIComponent(recurso)}`),

  ordenar: (b: { side: 'buy' | 'sell'; resource_type: string; qty: number; price_micro: number }) =>
    req<Ordem>('/market/orders', { method: 'POST', body: JSON.stringify(b) }),

  cancelar: (ordem: number) =>
    req<{ id: number; status: string }>(`/market/orders/${ordem}`, { method: 'DELETE' }),
}
