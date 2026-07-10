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
  x: number
  y: number
  fert: number
  last_tick_at?: string
  buildings: { type: string; level: number }[]
  resources: Record<string, number>
}

/**
 * Uma colônia alheia, como o diretório a publica.
 *
 * `building_levels_sum` é a soma dos níveis das construções — um sinal de porte arbitrado
 * (D-38). **Não** é o "Marco" do GDD, que ainda não tem fórmula. Recursos e saldo do vizinho
 * não vêm: escolher destino não é espionar.
 */
export type ColoniaVizinha = {
  id: number
  name: string
  nickname: string
  x: number
  y: number
  distance: number
  building_levels_sum: number
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
  /** Só a Oficina tem receita (§24.5). Nas demais vem `null`. */
  recipe?: string | null
}

/** Uma das três receitas de Componentes Eletrônicos do §24.5. */
export type Receita = {
  code: string
  nome: string
  contexto: string
  insumos_por_unidade: Record<string, number>
  padrao: boolean
}

/**
 * O diretório, mais a geometria do mapa.
 *
 * `side` e `capital` vêm do servidor de propósito: a grade vai mudar (D-51 — lado 101, Capital em
 * (0,0), coordenadas com sinal), e um número copiado para cá sobreviveria à mudança mentindo.
 */
export type Diretorio = {
  side: number
  capital: { x: number; y: number }
  me: { id: number; name: string; x: number; y: number }
  colonies: ColoniaVizinha[]
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

/**
 * Um Acordo de Troca (§26.5), pelos olhos de quem pediu — o backend já resolve "eu" e "ele".
 *
 * Não há escrow (D-40): nada aqui reserva recurso. `i_still_owe` é o que falta chegar do meu lado,
 * **líquido**; `gross_needed` é o que preciso embarcar para que aquilo chegue, já somado o tributo
 * da entrega (D-41). Prometer 100 e despachar 100 é caloteirar por alguns pontos de tributo.
 */
export type Acordo = {
  id: number
  status: 'proposto' | 'aceito' | 'executado' | 'quebrado' | 'cancelado'
  proposed_by_me: boolean
  counterparty_id: number
  deadline_at: string
  accepted_at: string | null
  executed_at: string | null
  i_promise: Record<string, number>
  they_promise: Record<string, number>
  i_delivered: Record<string, number>
  they_delivered: Record<string, number>
  i_still_owe: Record<string, number>
  gross_needed: Record<string, number>
  value_micro: number
  /** Abaixo do piso anti-farming do §26.3 o acordo registra histórico, mas não move o índice (D-43). */
  moves_reputation: boolean
}

export type Acordos = {
  /** Confiança Comercial do colono, de 0 a 1000 (§26.2). Abaixo do limiar, o Mercado fecha. */
  confianca_comercial: number
  limiar_mercado: number
  agreements: Acordo[]
}

/** O prazo mínimo que o backend aceita para um acordo com esta colônia (D-42): viagem + 12 h. */
export type PrazoMinimo = {
  distance_slots: number
  minimum_seconds: number
  minimum_deadline_at: string
}

/** Os quatro índices do §26.2. Isolados: o §26.9 proíbe compensar um com outro. */
export type Reputacao = {
  confianca_comercial: number
  conduta_social: number
  status_civico: number
  honra_militar_diplomatica: number
}

/** Uma linha da tabela fixa do §26.8. A pena não é segredo, e o conciliador não a escolhe (D-49). */
export type Violacao = {
  violation: string
  indice: keyof Reputacao
  pontos: number
  punicoes: string[]
  /** §9.2: caso grave não passa por conciliador jogador. */
  grave: boolean
  /** Depende de chat, leilões ou tratados, que não existem. Grava e não morde (D-44). */
  inerte: boolean
  fonte: string
}

export type PunicaoVigente = {
  kind: string
  index_name: string | null
  points: number
  expires_at: string | null
}

export type Ministerio = {
  reputacao: Reputacao
  limiar_mercado: number
  persona_non_grata: boolean
  conciliador: {
    nomeado: boolean
    suspenso: boolean
    reversoes: number
    limite_reversoes: number
    salario_diario_micro: number
    bonus_micro: number
  }
  punicoes: PunicaoVigente[]
  catalogo: Violacao[]
}

export type Denuncia = {
  id: number
  violation: string
  fonte: string
  texto: string
  evidence_type: string
  trade_agreement_id: number | null
  status: 'triagem' | 'rejeitado' | 'atribuido' | 'na_equipe' | 'decidido' | 'apelado' | 'revertido' | 'encerrado'
  decision: 'procedente' | 'improcedente' | null
  grave: boolean
  eu_denunciei: boolean
  reporter_colony_id: number
  accused_colony_id: number
  /** As 48 h do §26.8, quando o caso está com um conciliador. */
  deadline_at: string | null
  decided_at: string | null
  /** As 48 h para apelar (D-50). */
  appeal_until: string | null
  punicao_tabelada: { indice: keyof Reputacao; pontos: number; punicoes: string[] }
}

export const api = {
  register: (b: { name: string; nickname: string; email: string; password: string }) =>
    req<Sessao>('/register', { method: 'POST', body: JSON.stringify(b) }),

  login: (b: { email: string; password: string }) =>
    req<Sessao>('/login', { method: 'POST', body: JSON.stringify(b) }),

  /**
   * Revoga o token no servidor. Token do Sanctum não expira: sem esta chamada, apagar o
   * `localStorage` deixaria uma credencial válida em circulação para sempre.
   */
  logout: () => req<{ message: string }>('/logout', { method: 'POST' }),

  colonia: () => req<Colonia>('/colony'),

  /**
   * Diretório de colônias, do vizinho mais próximo ao mais distante.
   *
   * É o que torna o despacho entre colônias alcançável: `dispatch` pede a PK do destino, e sem
   * isto o jogador não teria como descobrir o `id` de ninguém.
   */
  colonias: () => req<Diretorio>('/colonies'),

  fundarColonia: (name: string) =>
    req<Colonia>('/colony', { method: 'POST', body: JSON.stringify({ name }) }),

  construcoes: () => req<Spec[]>('/buildings'),

  enfileirar: (id: number) => req<ItemDaFila>(`/buildings/${id}/upgrade`, { method: 'POST' }),

  fila: () => req<Fila>('/queue'),

  /** As três receitas do §24.5. Sem esta lista, escolher receita seria digitar códigos à mão. */
  receitas: () => req<Receita[]>('/recipes'),

  escolherReceita: (building: number, recipe: string) =>
    req<Receita>(`/buildings/${building}/recipe`, {
      method: 'PATCH',
      body: JSON.stringify({ recipe }),
    }),

  frota: () => req<Frota>('/vehicles'),

  /** Leva carga do estoque até a doca do Mercado. O tributo incide na chegada (D-32). */
  depositar: (veiculo: number, cargo: Record<string, number>) =>
    req<Veiculo>(`/vehicles/${veiculo}/dispatch`, {
      method: 'POST',
      body: JSON.stringify({ destination_type: 'mercado_central', cargo }),
    }),

  /**
   * Leva carga do estoque ao slot de outro colono — o comércio informal do §25.7, em que os dois
   * combinam a troca por fora e o veículo faz a parte física. O tributo incide na entrega (D-32).
   *
   * Com `acordo`, a carga **aponta** um Acordo de Troca e abate a promessa ao chegar. Sem ele, o
   * mesmo envio entre os mesmos colonos não abate nada: um presente casual não é pagamento, e dois
   * acordos abertos do mesmo par se canibalizariam (D-41).
   */
  enviarAColonia: (
    veiculo: number,
    destino: number,
    cargo: Record<string, number>,
    acordo?: number,
  ) =>
    req<Veiculo>(`/vehicles/${veiculo}/dispatch`, {
      method: 'POST',
      body: JSON.stringify({
        destination_type: 'colonia',
        destination_id: destino,
        cargo,
        ...(acordo === undefined ? {} : { trade_agreement_id: acordo }),
      }),
    }),

  acordos: () => req<Acordos>('/trade/agreements'),

  prazoMinimoDoAcordo: (contraparte: number) =>
    req<PrazoMinimo>(`/trade/deadline?counterparty_id=${contraparte}`),

  proporAcordo: (b: {
    counterparty_id: number
    deadline_at: string
    i_promise: Record<string, number>
    they_promise: Record<string, number>
  }) => req<Acordo>('/trade/agreements', { method: 'POST', body: JSON.stringify(b) }),

  /** Só a contraparte fecha o aperto de mão: quem propôs já aderiu ao propor (§26.5). */
  aceitarAcordo: (id: number) => req<Acordo>(`/trade/agreements/${id}/confirm`, { method: 'POST' }),

  /** Recusa ou desistência, enquanto o acordo não foi aceito. Depois de aceito, não há saída. */
  cancelarAcordo: (id: number) => req<Acordo>(`/trade/agreements/${id}`, { method: 'DELETE' }),

  /** Reputação, punições vigentes, cargo, e o catálogo de violações com a pena de cada uma. */
  ministerio: () => req<Ministerio>('/ministry/me'),

  /** As que fiz, as que sofri, e — se eu for conciliador — as que devo julgar. */
  denuncias: () => req<{ minhas: Denuncia[]; a_julgar: Denuncia[] }>('/ministry/reports'),

  denunciar: (b: {
    accused_colony_id: number
    violation: string
    texto: string
    evidence_type: string
    trade_agreement_id?: number
  }) => req<Denuncia>('/ministry/reports', { method: 'POST', body: JSON.stringify(b) }),

  /**
   * O conciliador julga o **fato**, não a pena: a punição sai da tabela fixa do §26.8. Por isso o
   * corpo só carrega `procedente`.
   */
  decidirDenuncia: (id: number, procedente: boolean) =>
    req<Denuncia>(`/ministry/reports/${id}/decide`, {
      method: 'POST',
      body: JSON.stringify({ procedente }),
    }),

  /** §9.3: as partes contestam a decisão, e a equipe do jogo julga a apelação por fora. */
  apelar: (id: number) => req<Denuncia>(`/ministry/reports/${id}/appeal`, { method: 'POST' }),

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
