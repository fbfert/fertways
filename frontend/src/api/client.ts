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

/** O Marco de colonização (§03/§05; D-75): a curva é 50×N², e os títulos são os oito publicados. */
export type Marco = {
  numero: number
  titulo: string
  xp: number
  /** null no 100: a Lenda é o teto, e não se promete um 101. */
  xp_do_proximo: number | null
}

export type Colonia = {
  id: number
  name: string
  x: number
  y: number
  fert: number
  last_tick_at?: string
  buildings: { type: string; level: number }[]
  resources: Record<string, number>
  taxas_hora: Record<string, { produzido: number; consumido: number }>
  marco: Marco
}

/** Um efeito empilhado de um item da Endurance (D-135) — o vocabulário fechado de `EfeitosDaEndurance`. */
export type EfeitoDoItemDaEndurance = {
  tipo_efeito:
    | 'desconto_tributo'
    | 'producao_bonus'
    | 'velocidade_veiculo'
    | 'capacidade_veiculo'
    | 'drone_raio'
    | 'drone_bateria'
  alvo: string | null
  valor_bps: number
}

/** Um item do catálogo dinâmico da Loja de Peças da Endurance (§05, D-135) — uma seção do casco. */
export type ItemDaEndurance = {
  item_key: string
  nome: string
  tipo: 'comum' | 'raro' | 'unico'
  estoque_livre: number
  quantidade_total: number
  preco_fert: number
  marco_minimo: number | null
  vendavel_em_leilao: boolean
  descricao: string | null
  possuo: number
  estado: 'disponivel' | 'bloqueado' | 'esgotado'
  efeitos: EfeitoDoItemDaEndurance[]
  /**
   * A identidade e a biografia do item ÚNICO (A2.9 / §11.1) — nulo em comum e raro, que são
   * fungíveis e não têm história nenhuma.
   *
   * O `descobridor` aparece mesmo quando o item já é de outro: é a origem que ninguém pode
   * reescrever, e é ela que faz o único valer mais que o raro.
   */
  unico: {
    selo: string
    descobridor: string | null
    descoberto_em: string | null
    dono: string | null
    e_meu: boolean
    /** Em escrow de leilão: saiu de uma mão e ainda não chegou na outra. */
    em_leilao: boolean
    /** Quantas vezes trocou de mão desde a descoberta. */
    trocas: number
  } | null
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
  /** Chave do card de informações do colono (D-81) — é por USER, não por colônia. */
  user_id: number
  name: string
  nickname: string
  x: number
  y: number
  distance: number
  building_levels_sum: number
}

/**
 * O card "quem é esse colono" (D-81). Mesma régua de privacidade do diretório (D-37): nada de
 * recursos, saldo, frota ou reputação. As zonas trazem só o que já é público em `GET /zones`.
 */
export type JogadorInfo = {
  id: number
  nickname: string
  colony: {
    id: number
    name: string
    x: number
    y: number
    distance: number | null
    building_levels_sum: number
  } | null
  zones: {
    id: number
    name: string | null
    x: number
    y: number
    district: string
    mineral: string
    level: number
    status: string
  }[]
}

/**
 * O que uma construção FAZ (D-59).
 *
 * `frase` e `fonte` são o que o GDD promete, verbatim. `nota` é o que o jogo entrega quando isso
 * é menos do que a promessa — e sete construções ainda não entregam nada. A tela mostra as duas:
 * anunciar só a promessa faria o colono gastar 90 Ligas num prédio inerte.
 */
export type Funcao = {
  frase: string
  fonte: string
  /** produz | converte | porta (abre uma tela) | nenhum */
  efeito: string
  nota: string | null
}

/** O que a construção produz e consome por hora, num dado nível (§19.2–19.4). */
export type Efeito = {
  producao_hora: Record<string, number> | null
  /** O que ela PROCESSA por hora — só a Indústria Siderúrgica usa isto, hoje (D-82). */
  insumo_hora: Record<string, number> | null
  energia_hora: number
}

export type Spec = {
  id: number
  type: string
  level: number
  /** Onde ela está na colmeia de 21 (D-59). */
  slot: number
  /** Só quando ELA está em obra de verdade (não só na fila, atrás de outra) — D-110. */
  finishes_at?: string | null
  max_level: number
  next_level?: number
  cost?: Record<string, number>
  build_time_seconds?: number
  subsidized?: boolean
  blocked?: string
  essencial: boolean
  demolivel: boolean
  repetivel: boolean
  funcao: Funcao
  efeito_atual: Efeito | null
  efeito_proximo: Efeito | null
  /** Só a Oficina tem receita (§24.5). Nas demais vem `null`. */
  recipe?: string | null
}

/** Uma construção que o colono pode erguer num slot vazio (D-59). */
export type Erguivel = {
  type: string
  funcao: Funcao
  cost: Record<string, number>
  build_time_seconds: number
  max_level: number
  repetivel: boolean
  quantas: number
  disponivel: boolean
}

/**
 * O catálogo do slot vazio, mais a geometria da colmeia.
 *
 * `linhas` vem do servidor pelo mesmo motivo que a grade do mapa vem (D-54): o layout é decisão de
 * domínio (`Domain/Colony/Slots`), e copiá-lo para o React o faria mentir no dia em que mudasse.
 */
export type Catalogo = {
  slots: { linhas: number[]; total: number }
  ocupados: number[]
  buildings: Erguivel[]
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
  /** Os raios das faixas do centro (D-51), para o mapa sombrear as células de cada uma (D-64). */
  raio_founder: number
  raio_anel: number
  me: { id: number; name: string; x: number; y: number }
  colonies: ColoniaVizinha[]
}

/** Um dos 48 slots de founder do disco central (D-51). */
export type SlotFounder = {
  x: number
  y: number
  reservado: boolean
  ocupado: boolean
}

/**
 * O mapa para o seletor de fundação (`GET /map`): geometria, os slots de founder, as células de
 * periferia liberadas pelo admin (D-147 — a periferia deixou de ser "qualquer lugar") e as
 * células já ocupadas. Serve o colono que ainda não fundou — por isso não traz `me`.
 */
export type MapaFundacao = {
  side: number
  raio: number
  capital: { x: number; y: number }
  raio_founder: number
  raio_anel: number
  founder_slots: SlotFounder[]
  periferia_liberada: { x: number; y: number }[]
  colonias: { x: number; y: number }[]
}

/** Uma zona neutra, como `GET /zones` a publica (D-52). */
export type ZonaNeutra = {
  id: number
  /** Como o nome da colônia: público, opcional. Sem nome, a tela mostra as coordenadas. */
  name: string | null
  x: number
  y: number
  district: string
  mineral: string
  level: number
  status: string
  owner: { id: number; name: string; user_id: number } | null
  mine: boolean
  /**
   * Os dois únicos segredos do interior (D-74): null = névoa, não zero. Zero é um fato ("está
   * indefesa"); null é a honestidade de não saber. Mande um Drone.
   */
  deposit_amount: number | null
  deposit_cap: number
  extraction_per_hour: number
  productive_at: string | null
  garrison: number | null
  /** Com que olhos esta colônia vê a zona (D-74; 'federacao' — D-116, Central de Comunicação). */
  intel: 'dona' | 'livre' | 'ao_vivo' | 'foto' | 'nenhuma' | 'federacao'
  /** A data da foto, quando intel === 'foto' — informação que envelhece é informação honesta. */
  intel_em: string | null
  /** Upgrade de nível (D-84). Só o dono vê — nulo para qualquer outra colônia. */
  upgrade: {
    target: number | null
    finishes_at: string | null
    proximo_custo: { metal_bruto: number; fert: number } | null
    proxima_guarnicao: number | null
  } | null
  /**
   * Manutenção territorial (D-84). Só o dono vê o extrato — o EFEITO (Pontos de Defesa perdidos)
   * é real para qualquer atacante, mesmo sem ver o motivo.
   */
  manutencao: {
    custo_diario: Record<string, number>
    proximo_vencimento: string | null
    inadimplente_desde: string | null
    penalidade_bps: number
  } | null
  /** A fila de construção (D-125). Só o dono vê — nulo para qualquer outra colônia. */
  obras: { structure: string; nome: string; target_level: number; finishes_at: string }[] | null
  /** O teto de obras simultâneas na zona (`FilaSetting.zona_vagas`, do operador, D-111). */
  obras_vagas: number | null
}

/** Um Drone de Exploração no hangar do Quartel (§21.4; D-74). */
export type Drone = {
  id: number
  placa: string
  level: number
  status: string
  /** A fase da missão: null (em casa) | 'ida' | 'vigia' | 'volta'. */
  fase: 'ida' | 'vigia' | 'volta' | null
  modo: 'foto' | 'vigilancia' | null
  alvo_zone_id: number | null
  chega_at: string | null
  raio: number
  bateria_horas: number
}

/** Uma unidade de combate (§27.1, §27.2; D-66). O HP decide o que ela vale: ferida, vale menos. */
export type Unidade = {
  id: number
  type: 'sentinela' | 'robo_minerador' | 'infiltrador' | 'predador'
  level: number
  hp_pct: number
  ataque: number
  defesa: number
}

export type EstadoDaGuerra = {
  quartel_nivel: number
  unidades: Unidade[]
  /** O hangar (§21.4: o Quartel armazena e recarrega; a fábrica é a Oficina — D-74). */
  drones: Drone[]
  oficina_nivel: number
  drone_custos: Record<number, Record<string, number>>
  /** Nada no jogo produz Nióbio, e a Sentinela custa 3. O governo vende (D-66). */
  niobio: { em_estoque: number; preco_fert: number }
  bonus_defensivos: {
    muralha_pct_por_nivel: number
    torre_de_vigia_pct_por_nivel: number
    bastiao_pct_por_nivel: number
  }
}

export type TipoDeAtaque = 'invasao' | 'cerco' | 'sabotagem' | 'apreensao'

/** A ruptura (§28.10) não se despacha como ataque — nasce de um cerco. Por isso está fora acima. */
export type TipoDeCombate = TipoDeAtaque | 'ruptura'

export type Combate = {
  id: number
  tipo: TipoDeCombate
  status: string
  sou_o_atacante: boolean
  zona: { id: number; x: number; y: number }
  rodada: number
  chega_at: string
  proxima_rodada_at: string | null
  prazo_at: string | null
  alvo: string | null
  forca_ofensiva: number | null
  forca_defensiva: number | null
  /** Só o exposto é saqueável: o que cabe no Depósito está protegido (D-66). */
  exposto: number
  /** A zona está cercada AGORA (§28.10): nada entra nem sai — nem tropa. Só romper a abre (D-70). */
  cercada: boolean
}

/**
 * Uma linha do Ranking de Guerras (§27.13; D-128). Cinco sub-rankings normalizados por percentil
 * (0-100, o valor do jogador sobre o MÁXIMO do servidor) e o Ranking Geral, soma ponderada deles.
 */
export type LinhaDoRanking = {
  colony_id: number
  colony_name: string | null
  zonas_conquistadas: number
  vitorias: number
  sequencia: number
  tempo_de_controle_horas: number
  saque_fert: number
  percentil: {
    zonas_conquistadas: number
    vitorias: number
    tempo_de_controle: number
    saque: number
    sequencia: number
  }
  geral: number
  mine: boolean
}

/** O perfil do colono (D-69). Os quatro índices são do Ministério e NÃO se editam. */
export type Perfil = {
  name: string
  nickname: string
  email: string
  colony_name: string | null
  desde: string
  reputacao: {
    confianca_comercial: number
    conduta_social: number
    status_civico: number
    honra_militar_diplomatica: number
  }
  /** Abaixo disto, a Confiança Comercial bloqueia o acesso ao Mercado (§26.2, D-43). */
  limiar_bloqueio: number
  conciliador: boolean
  /** Cargos Públicos, §14.2 (D-130) — os 3 que não são o Conciliador. */
  cargos: CargoCivico[]
  /** O Marco (§03/§05; D-75). Null para quem ainda não fundou colônia. */
  marco: Marco | null
}

/** Um Cargo Público ocupado por este colono (§14.2, D-130). */
export type CargoCivico = {
  kind: 'reporter' | 'fiscal_de_mercado' | 'auxiliar_de_tesouro'
  nome: string
  suspenso: boolean
  salario_diario_fert: number
  bonus_fert: number
}

/** Uma linha do extrato bancário — só Fert$, nunca recurso (o card de Fert$ do HUD abre isto). */
export type Lancamento = {
  id: number
  tipo: string
  fert: number
  ref: string
  quando: string
}

export type Extrato = {
  lancamentos: Lancamento[]
  pagina_atual: number
  ultima_pagina: number
  total: number
}

/** Uma missão na sua mão (§06; D-78). Concluir paga na hora — não há botão de resgate. */
export type Missao = {
  id: number
  categoria: 'tutoria' | 'diaria' | 'semanal' | 'federacao' | 'narrativa'
  titulo: string
  descricao: string
  progresso: number
  meta: number
  status: 'ativa' | 'concluida' | 'rejeitada' | 'expirada'
  expira_em: string | null
  recompensa: {
    fert: number
    xp: number
    recursos: Record<string, number> | null
    /**
     * A2.5: o que vai ao FUNDO da federação, e não a quem cumpriu — é o que distingue um objetivo
     * federativo de uma missão pessoal com placar compartilhado. Nulo em quase todas.
     */
    federacao: Record<string, number> | null
  }
}

/**
 * Um evento de mundo visível ao jogador (A2.8).
 *
 * O `parcial` diz que ALGO mexe na produção sem dizer o quê — tensão sem explicação. O secreto nunca
 * chega aqui: o servidor o filtra antes.
 */
export type EventoDoMundo = {
  parcial: boolean
  nome?: string
  mensagem?: string | null
  modificador?: 'producao' | 'consumo'
  /** Em porcentagem, com sinal. */
  efeito?: number
  recurso?: string | null
  termina_em: string
}

/** A árvore de pesquisa (A2.3). */
export type ArvoreDePesquisa = {
  /** A chave-mestra do servidor. Desligada, a tela diz isso em vez de fingir. */
  ativo: boolean
  laboratorio: number
  vagas: { total: number; ocupadas: number; livres: number; fontes: Record<string, number> }
  /** O que JÁ está valendo — progressão que não se vê é indistinguível de progressão que não houve. */
  meus_efeitos: {
    desconto_tributo_pct: number
    desconto_duracao_pct: number
    producao_por_alvo: Record<string, number>
  }
  tecnologias: Array<{
    id: number
    chave: string
    nome: string
    descricao: string | null
    trilha: string
    nivel: number
    nivel_maximo: number
    laboratorio_minimo: number
    custo: Record<string, number>
    duracao_segundos: number
    efeitos: Array<{ tipo: string; alvo: string; valor_bps: number }>
    status: 'nao_iniciada' | 'pesquisando' | 'concluida'
    termina_em: string | null
    /** O porquê de não poder, para a tela não oferecer o que a regra recusaria. */
    bloqueio: 'inativa' | 'laboratorio' | 'no_maximo' | 'em_andamento' | null
  }>
}

/** Uma fala no rádio do planeta (§10; D-77). */
export type MensagemDeChat = {
  id: number
  de: { id: number; nickname: string }
  body: string
  em: string
}

/** Uma zona minha, como a barra lateral da colônia a lista (D-69). */
export type MinhaZona = {
  id: number
  name: string | null
  x: number
  y: number
  mineral: string
  deposito: number
  capacidade: number
  /** O que a guerra pode levar. Só o que EXCEDE o Depósito é saqueável (D-66). */
  exposto: number
  cercada: boolean
  produtiva: boolean
  obra: { nome: string; nivel: number; termina_at: string } | null
  /** Nível da zona e upgrade em curso, se houver (D-84/D-88). */
  level: number
  upgrade: { target: number; finishes_at: string } | null
  guarnicao: { robos: number; sentinelas: number; defesa: number }
  /** Manutenção territorial (D-84/D-88) — `inadimplente_desde` nulo é dia em dia. */
  manutencao: { inadimplente_desde: string | null; penalidade_bps: number }
  /** O que já chegou de veículo, esperando virar a próxima obra (D-88). */
  canteiro: { resource_type: string; amount: number }[]
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
  plate: string | null
  nickname: string | null
  level: number
  status: 'ocioso' | 'carregando' | 'em_rota' | 'descarregando'
  /** A capacidade de fábrica (§25.4). É a nominal: não é ela que o despacho cobra. */
  capacity: number
  /**
   * A capacidade que o veículo **de fato** tem hoje, encolhida pelo desgaste (§16.4, D-60). É
   * contra este número que a carga é montada — a nominal deixaria o colono somar uma carga que o
   * servidor recusa.
   */
  capacity_efetiva: number
  /**
   * Onde ele está parado: em casa, no Pátio Logístico da Capital, ou — desde o D-109 — numa Zona
   * Neutra sua (só chega lá vazio, por reposicionamento explícito).
   */
  local: 'colonia' | 'capital' | 'zona'
  parked_at: string | null
  leg: 'ida' | 'volta' | null
  trip_purpose:
    | 'entrega'
    | 'retirada'
    | 'reboque'
    | 'entrega_de_fabrica'
    | 'venda_usado'
    | 'reposicionamento'
    | null
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
/**
 * Os dois estoques do colono, lado a lado (D-58) — a distinção de que a regra do jogo depende:
 * o que está **na colônia** se negocia entre colonos; o que está **no depósito da Capital** se
 * oferta no Mercado Central.
 *
 * `em_ofertas` é o que já saiu do saldo mas continua ocupando o teto: uma oferta anunciada não
 * libera espaço, senão bastaria anunciar tudo a preço absurdo para o teto virar decoração.
 */
export type SaldoDoRecurso = {
  resource_type: string
  na_colonia: number
  no_deposito: number
  em_ofertas: number
  teto: number
  livre: number
}

export type ContaDoMercado = {
  capital: { x: number; y: number }
  distance_slots: number
  balances: { resource_type: string; amount: number }[]
  deposito: SaldoDoRecurso[]
  /** O frete público do §07 (D-76): o governo leva da doca até a colônia — se houver caminhão livre. */
  frete: { preco_fert: number; capacidade: number; caminhoes_livres: number }
}

export type Ordem = {
  id: number
  side: 'buy' | 'sell'
  price_micro: number
  qty: number
  status: 'aberta' | 'parcial' | 'executada' | 'cancelada'
}

/** Uma oferta parada na vitrine das Ofertas Globais. Ela só sai dali se alguém a executar. */
export type OfertaGlobal = {
  id: number
  resource_type: string
  side: 'buy' | 'sell'
  price_micro: number
  qty: number
  /** Nulo é o Governo (D-87) — mesmo padrão da frota pública. `colonia` já vem "Governo". */
  colony_id: number | null
  colonia: string | null
  /** O Governo vende no Mercado Central (D-87), ao lado das ofertas dos colonos. */
  e_governo: boolean
  /** A própria oferta não se executa (§26.4): a UI troca "Comprar" por "Cancelar". */
  minha: boolean
}

/**
 * Um leilão (D-129) — sem seção no GDD, desenhado sobre o Mercado Central: lote único, tudo ou
 * nada, lance em escrow, fechamento automático no tick quando `deadline_at` passa.
 *
 * **OU-OU (D-135, Fase 2)**: `resource_type` (recurso do catálogo) OU `item_key` (item da Loja de
 * Peças da Endurance), nunca os dois juntos — sempre confira `item_key !== null` para saber qual é
 * qual, nunca confie em `resource_type` sozinho.
 */
export type Leilao = {
  id: number
  resource_type: string | null
  item_key: string | null
  item_nome: string | null
  qty: number
  colony_id: number
  colonia: string | null
  /** A própria colônia não dá lance no próprio leilão — a UI troca "Dar lance" por "Cancelar". */
  minha: boolean
  lance_minimo_fert: number
  lance_atual_fert: number | null
  proximo_lance_minimo_fert: number
  lance_colony_id: number | null
  lance_colonia: string | null
  meu_lance: boolean
  status: 'aberto' | 'arrematado' | 'sem_lance' | 'cancelado'
  deadline_at: string
}

/** Um item da Endurance que esta colônia possui e pode anunciar em Leilão (D-135, Fase 2). */
export type ItemVendavelEmLeilao = {
  item_key: string
  nome: string
  secao: string
  quantidade: number
}

export type RecursoDoCatalogo = {
  code: string
  nome: string
  tax_class: 'primario' | 'secundario' | 'raro'
  taxa_bps: number
  /** Referência exibida, não teto nem piso (§06, D-35). */
  preco_base_micro: number
  teto_deposito: number
}

/**
 * A vitrine. Sem `resource_type`, mostra **todos** os recursos — era a falta disso que fazia o
 * colono não ver oferta nenhuma: a lista pedia um recurso por vez e abria em Metal Bruto.
 */
export type Vitrine = {
  resource_type: string | null
  ofertas: OfertaGlobal[]
  catalogo: RecursoDoCatalogo[]
}

/** Uma oferta aberta no mural entre colonos: sem contraparte, o primeiro que aceitar leva (D-58). */
export type OfertaDeColono = {
  id: number
  colony_id: number
  colonia: string | null
  minha: boolean
  oferece: Record<string, number>
  quer: Record<string, number>
  deadline_at: string
  value_micro: number
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
  /** `null` enquanto a oferta está aberta no mural, sem contraparte (D-58). */
  counterparty_id: number | null
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

// ── Capital: instituições do governo (§02) ──────────────────────────────────

/**
 * Ministério dos Transportes (§16, slot 8 — D-60).
 *
 * `em_estoque` é a prateleira de pronta entrega; `em_fabricacao`, a linha de montagem. O governo
 * repõe sozinho até 5, consumindo o Tesouro — se o caixa secar, os dois zeram e ninguém compra.
 */
/**
 * O Registro de Veículo do §16.3, com os campos que o GDD desenha — placa, tipo, horas de uso ativo
 * e estado de conservação — mais o que o desgaste FAZ (§16.4): velocidade e capacidade encolhem.
 *
 * `conservacao` e `desempenho` divergem abaixo do piso de 25%: uma carcaça a 5% ainda **anda** a
 * 25%, porque o D-60 decidiu que o veículo nunca trava.
 */
export type RegistroVeiculo = {
  id: number
  placa: string | null
  tipo: string
  status: string
  chega_em: string | null
  horas_de_uso: number
  conservacao: number
  teto_conservacao: number
  manutencoes: number
  desempenho: number
  capacidade_efetiva: number
  deprecia: boolean
  custo_manutencao: Record<string, number> | null
  pode_reparar: boolean
  /** Só o Caminhão tem teto — o Furgão não tem preço de fábrica (D-60, aditivo 14). */
  teto_de_revenda_fert: number | null
  anunciado: boolean

  nivel: number
  /**
   * Os DOIS lados do upgrade (A2.7), juntos de propósito.
   *
   * O critério de saída da fase é "escolha econômica mensurável, e não apenas aumento nominal de
   * nível". Mostrar só o ganho de capacidade transformaria o botão em decisão óbvia — a manutenção
   * mais cara é o que devolve a escolha ao jogador.
   */
  upgrade: {
    nivel_maximo: number
    no_maximo: boolean
    pode: boolean
    proximo_nivel: number | null
    custo: Record<string, number> | null
    capacidade_agora: number
    capacidade_depois: number | null
    /** Em porcentagem do custo de manutenção do nível 1: 100 é o normal, 120 é 20% mais caro. */
    manutencao_agora: number
    manutencao_depois: number | null
  }
}

export type AnuncioUsado = {
  id: number
  preco_fert: number
  meu: boolean
  vendedor: string | null
  veiculo: RegistroVeiculo
}

export type ItemDaFabrica = {
  tipo: string
  preco_fert: number
  capacidade: number
  em_estoque: number
  em_fabricacao: number
  minutos_fabricacao: number
}

export type Transportes = {
  /** Desde o D-109: uma entrada por tipo que a fábrica produz — hoje Caminhão e Furgão. */
  fabrica: Record<string, ItemDaFabrica>
  frota: { teto: number; ocupadas: number; livres: number; regra: string }
  veiculos: RegistroVeiculo[]
  /** A 6ª atribuição do painel do §16, na porção que vai ao colono. */
  planeta: { veiculos_registrados: number; vendidos: number; sucateados: number }
}

export type CaminhaoComprado = {
  id: number
  placa: string | null
  tipo: string
  /** A entrega é física: ele vem dirigindo da Capital (D-60). */
  a_caminho: boolean
  chega_em: string | null
}

export type Tesouro = {
  fert_micro: number
  recursos: { code: string; nome: string; tax_class: string; total: number }[]
  aliquotas: { tax_class: string; rotulo: string; bps: number }[]
  recentes: {
    kind: string
    resource_type: string | null
    tax_amount: number
    colonia: string | null
    created_at: string
  }[]
}

export type Financas = {
  precos: {
    code: string
    nome: string
    tax_class: string
    tax_bps: number
    preco_base_micro: number | null
    derivado: boolean
  }[]
  intervencoes: {
    id: number
    resource_type: string
    nome: string
    floor_micro: number | null
    ceil_micro: number | null
    reason: string
    expires_at: string
  }[]
  indicadores: {
    fert_em_circulacao_micro: number
    tesouro_fert_micro: number
    colonias: number
  }
}

export type Noticias = {
  noticias: {
    id: number
    title: string
    body: string
    kind: string
    author: string
    published_at: string
  }[]
  gagarin: { ativo: boolean; jogadores: number; limiar_jogadores: number; regra: string }
  /** Só true para quem ocupa o cargo de Repórter, ativo (§14.2, D-130). */
  posso_publicar: boolean
}

// ── Federação (§04/§07; D-114) — o Quartel de Alianças, Capital slot 9 ─────────────────────────

export type FederationRole = 'lider' | 'diplomata' | 'intendente' | 'membro'

export type FederationMember = { colony_id: number; name: string; role: FederationRole }

export type FederationFundLine = { resource_type: string; amount: number }

/** Um convite (Líder/Diplomata chama de fora) ou um pedido (colônia sem federação pede entrada). */
export type FederationInviteDto = {
  id: number
  kind: 'convite' | 'pedido'
  federation: { id: number; name: string } | null
  colony: { id: number; name: string } | null
  created_by_colony_id: number
  created_at: string
}

export type MinhaFederacao = {
  federation: { id: number; name: string } | null
  my_role: FederationRole | null
  members: FederationMember[]
  fund: FederationFundLine[]
  /** Só vem preenchido pro Líder/Diplomata — quem age sobre convites/pedidos. */
  pending_invites: FederationInviteDto[]
}

export type FederationListItem = { id: number; name: string; membros: number; cheia: boolean }

/** "Desde sua última visita" (A2.0.3; janela do GDD ALPHA 2 §5.1). */
export type ResumoDeRetorno = {
  mostrar: boolean
  /** `primeira_vez` | `piso_de_uma_hora` | `sem_colonia` | `ok` — por que a tela aparece ou não. */
  motivo: string
  desde: string | null
  ate: string
  producao: { recurso: string; quantidade: number }[]
  fert_ganho_micro: number
  fert_gasto_micro: number
  obras_concluidas: { tipo: string; nivel: number; em: string }[]
  /** A janela existiu, mas nada aconteceu nela. É resultado legítimo, não erro. */
  vazio: boolean
}

/** O perfil DERIVADO da colônia (A2.4; GDD ALPHA 2 §8.1) — calculado, nunca declarado. */
export type PerfilDaColonia = {
  tem_colonia: boolean
  producao?: Record<string, number>
  /** O recurso de maior VALOR produzido. Nulo quando a colônia ainda não produz nada. */
  vocacao?: string | null
  /** Quanto a vocação domina o valor total produzido, em pontos percentuais. */
  forca_pct?: number
  /** O que a colônia consome e NÃO produz — a outra metade que o §8.1 manda exibir. */
  depende_de?: string[]
  trilhas?: string[]
  repetidas?: Record<string, number>
}

/** Concentração da federação e o teto antimonopólio (A2.5). */
export type ConcentracaoDaFederacao = {
  tem_federacao: boolean
  zonas_da_federacao?: number
  zonas_do_jogo?: number
  /** Fatia das zonas do jogo, em pontos-base (2000 = 20%). */
  ocupacao_bps?: number
  teto_bps?: number
  no_teto?: boolean
  /** Quantas zonas ainda cabem antes de o teto travar — o denominador cresce junto. */
  zonas_ate_o_teto?: number
  membros?: number
  membros_max?: number
  fert_micro?: number
  /**
   * Quantas federações o bloco reúne — 1 quando não há aliança (A2.5).
   *
   * A tela precisa dizer de quem é o número acima: com aliança, as zonas contadas são as do BLOCO,
   * e "17%" pareceria errado para quem só contou as suas.
   */
  federacoes_no_bloco?: number
}

/**
 * A mesa diplomática (A2.5, item 7).
 *
 * "Diplomata" era um cargo sem sistema — existia desde o D-114 e só sabia convidar colônia.
 */
export type MesaDiplomatica = {
  tem_federacao: boolean
  /** Só Líder e Diplomata tratam de aliança: a mesma permissão do convite. */
  pode_tratar?: boolean
  max_aliadas?: number
  aliadas?: number
  /** Em porcentagem. Os dois lado a lado tornam visível POR QUE filiar-se vale mais que aliar-se. */
  desconto_interno?: number
  desconto_alianca?: number
  relacoes?: Array<{
    id: number
    nome: string
    status: 'proposta' | 'aceita'
    /** Quem propôs não aceita a própria proposta — a tela precisa saber de que lado está. */
    propus: boolean
  }>
  disponiveis?: Array<{ id: number; nome: string }>

  /** O caixa comum (A2.10): é dele que sai o custo de declarar guerra. */
  fundo_fert?: number

  /** A mesa de guerra (A2.10, primeira fatia). */
  guerra?: {
    /** O Governo suspendeu declarações — o portão do Motor de Eventos está fechado. */
    tregua: boolean
    /** A própria federação declarou-se neutra (A2.10, decisão 12). */
    neutra: boolean
    /** Preenchido = carência em curso: ainda protegida, e já com data para deixar de estar. */
    saindo_em: string | null
    carencia_horas: number
    /** O custo AGORA, já com o modificador de mobilização. Não é o custo de tabela. */
    custo_fert: number
    custo_niobio: number
    em_guerra_com: Array<{
      id: number
      nome: string | null
      eu_declarei: boolean
      termina_em: string
    }>
  }
}

export const api = {
  register: (b: { name: string; nickname: string; email: string; password: string }) =>
    req<Sessao>('/register', { method: 'POST', body: JSON.stringify(b) }),

  login: (b: { email: string; password: string }) =>
    req<Sessao>('/login', { method: 'POST', body: JSON.stringify(b) }),

  /** Números reais do planeta, para a landing page — sem exigir conta (pedido do usuário). */
  estatisticas: () =>
    req<{
      colonos: number
      colonias: number
      fert_em_circulacao_micro: number
      construcoes_erguidas: number
      veiculos_registrados: number
      zonas_ocupadas: number
      lancamentos_no_ledger: number
    }>('/estatisticas'),

  /**
   * Revoga o token no servidor. Token do Sanctum não expira: sem esta chamada, apagar o
   * `localStorage` deixaria uma credencial válida em circulação para sempre.
   */
  logout: () => req<{ message: string }>('/logout', { method: 'POST' }),

  colonia: () => req<Colonia>('/colony'),

  /*
   * O GET **não** move o marcador — quem move é o `resumoVisto`, chamado ao FECHAR a tela. Se o GET
   * movesse, abrir e fechar sem ler já teria consumido a janela (§5.1).
   */
  resumo: () => req<ResumoDeRetorno>('/resumo'),
  resumoVisto: () => req<{ message: string }>('/resumo/visto', { method: 'POST' }),

  /*
   * Só leitura, e nunca haverá escrita: o §8.1 proíbe escolha declarada de perfil. O colono se
   * especializa pelo que pesquisou e construiu; o jogo calcula e exibe.
   */
  perfilDaColonia: () => req<PerfilDaColonia>('/perfil-da-colonia'),

  /**
   * Diretório de colônias, do vizinho mais próximo ao mais distante.
   *
   * É o que torna o despacho entre colônias alcançável: `dispatch` pede a PK do destino, e sem
   * isto o jogador não teria como descobrir o `id` de ninguém.
   */
  colonias: () => req<Diretorio>('/colonies'),

  /** O mapa para o seletor de fundação (D-51): slots de founder e células ocupadas. */
  mapaDeFundacao: () => req<MapaFundacao>('/map'),

  /** As 120 zonas neutras (D-52). */
  zonas: () => req<{ zones: ZonaNeutra[] }>('/zones'),

  /** Capital — instituições do governo (§02), só leitura. */
  tesouro: () => req<Tesouro>('/treasury'),
  financas: () => req<Financas>('/finance'),
  noticias: () => req<Noticias>('/news'),

  /** O ato do Repórter (§14.2, D-130): publica no mesmo mural, como boletim. */
  publicarMateria: (titulo: string, corpo: string) =>
    req<{ id: number }>('/cargos/materia', {
      method: 'POST',
      body: JSON.stringify({ titulo, corpo }),
    }),

  /** O ato do Fiscal de Mercado e do Auxiliar de Tesouro (§14.2, D-130): sinaliza para a equipe. */
  sinalizarCargo: (kind: 'fiscal_de_mercado' | 'auxiliar_de_tesouro', motivo: string) =>
    req<{ id: number }>('/cargos/sinalizar', {
      method: 'POST',
      body: JSON.stringify({ kind, motivo }),
    }),

  /**
   * Ministério dos Transportes (§16, slot 8): a vitrine da fábrica, a prateleira do governo e a
   * frota do colono com as placas. Desde o D-60, é o único lugar do planeta que fabrica veículo —
   * e desde o D-109, fabrica os dois tipos (Caminhão de Carga e Furgão de Comércio).
   */
  transportes: () => req<Transportes>('/transport'),
  comprarVeiculo: (tipo: string) =>
    req<{ comprado: CaminhaoComprado }>('/transport/buy', {
      method: 'POST',
      body: JSON.stringify({ tipo }),
    }),

  /** §16.4: restaura o desempenho, mas corrói a vida útil e o teto de revenda. Custa recursos. */
  repararVeiculo: (id: number) =>
    req<{ veiculo: RegistroVeiculo }>(`/transport/vehicles/${id}/maintain`, { method: 'POST' }),

  /** A2.7: sobe o nível. Capacidade↑ e manutenção↑ juntas; velocidade nunca — é traço do tipo. */
  melhorarVeiculo: (id: number) =>
    req<{ veiculo: RegistroVeiculo }>(`/transport/vehicles/${id}/upgrade`, { method: 'POST' }),

  /** Sucatear. Sem devolução (D-60), e o veículo fica arquivado no registro do Ministério. */
  sucatearVeiculo: (id: number) =>
    req<{ sucateado: boolean }>(`/transport/vehicles/${id}`, { method: 'DELETE' }),

  /** Mercado de usados, com escrow: o vendedor só recebe quando o veículo chega ao comprador. */
  usados: () => req<{ anuncios: AnuncioUsado[] }>('/transport/listings'),
  anunciarUsado: (b: { vehicle_id: number; preco_fert: number }) =>
    req<{ anuncio: { id: number } }>('/transport/listings', { method: 'POST', body: JSON.stringify(b) }),
  comprarUsado: (id: number) =>
    req<{ comprado: CaminhaoComprado }>(`/transport/listings/${id}/buy`, { method: 'POST' }),
  cancelarAnuncio: (id: number) =>
    req<{ cancelado: boolean }>(`/transport/listings/${id}`, { method: 'DELETE' }),

  /** Ocupa uma zona livre: Posto de Comando + 20 Robôs Mineradores + tempo de ocupação (§07). */
  ocuparZona: (id: number) =>
    req<ZonaNeutra>(`/zones/${id}/occupy`, { method: 'POST' }),

  /** Sobe o nível da zona (D-84): custo e guarnição cobrados na hora, o nível sobe no tick. */
  upgradeZona: (id: number) =>
    req<{ id: number; level: number; level_target: number; level_upgrade_finishes_at: string }>(
      `/zones/${id}/upgrade`,
      { method: 'POST' },
    ),

  /** Despacha um veículo para retirar o mineral extraído no Depósito da zona. */
  retirarDeZona: (id: number, vehicleId: number, cargo: Record<string, number>) =>
    req<{ id: number; type: string; plate: string; status: string; arrives_at: string | null }>(
      `/zones/${id}/withdraw`,
      { method: 'POST', body: JSON.stringify({ vehicle_id: vehicleId, cargo }) },
    ),

  // ── a guerra (§27, §28.10; D-66) ──────────────────────────────────────────────────────────────

  /** O exército em casa, o Quartel, e a que preço o governo vende o Nióbio que falta. */
  guerra: () => req<EstadoDaGuerra>('/war'),

  /** Fabrica um Drone na Oficina (D-74). Instantâneo: o freio é o custo, não o relógio. */
  fabricarDrone: (nivel: number) =>
    req<{ id: number; placa: string; level: number }>('/drones', {
      method: 'POST',
      body: JSON.stringify({ nivel }),
    }),

  /** Missão de reconhecimento (§21.4): foto = ida e volta; vigilancia = fica até a bateria acabar. */
  enviarDrone: (droneId: number, zoneId: number, modo: 'foto' | 'vigilancia') =>
    req<{ id: number; fase: string; chega_at: string }>(`/drones/${droneId}/mission`, {
      method: 'POST',
      body: JSON.stringify({ zone_id: zoneId, modo }),
    }),

  /** As batalhas em curso — atacando E defendendo: o §27.5 quer que o defensor veja e socorra. */
  combates: () => req<{ combats: Combate[] }>('/war/combats'),

  /** O Ranking de Guerras (§27.13; D-128) — cinco sub-rankings por percentil, e o Geral. */
  rankingDeGuerras: () => req<{ ranking: LinhaDoRanking[] }>('/war/ranking'),

  /** Fabrica no Quartel. Instantâneo: o freio do exército é o Nióbio, não o relógio (D-66). */
  fabricarUnidade: (type: Unidade['type'], level: number, quantidade: number) =>
    req<{ fabricadas: number }>('/war/units', {
      method: 'POST',
      body: JSON.stringify({ type, level, quantidade }),
    }),

  /** Compra Nióbio do caixa do Tesouro. Se o caixa secar, não há (como o caminhão do D-60). */
  comprarNiobio: (quantidade: number) =>
    req<{ comprado: number }>('/war/niobio', {
      method: 'POST',
      body: JSON.stringify({ quantidade }),
    }),

  /** Despacha um dos quatro ataques. A marcha de combate é 1,3× mais lenta que a civil (§27.4). */
  atacar: (
    zoneId: number,
    tipo: TipoDeAtaque,
    unitIds: number[],
    alvo?: string,
  ) =>
    req<{ id: number; tipo: string; status: string; chega_at: string }>('/war/attack', {
      method: 'POST',
      body: JSON.stringify({ zone_id: zoneId, tipo, unit_ids: unitIds, alvo: alvo ?? null }),
    }),

  /**
   * Reforça uma zona sua (§27.5, D-70). Elas marcham 1,3× mais devagar, como todo movimento militar.
   *
   * ⚠️ **Não entra em zona cercada.** "Nada entra nem sai" alcança a tropa: quem está sitiado não
   * recebe socorro por dentro. A única saída é `romperCerco`.
   */
  reforcar: (zoneId: number, unitIds: number[]) =>
    req<{ marcharam: number }>('/war/reinforce', {
      method: 'POST',
      body: JSON.stringify({ zone_id: zoneId, unit_ids: unitIds }),
    }),

  /**
   * Rompe um cerco (§28.10, D-70): o sitiado sai a campo contra o exército que o cerca.
   *
   * A batalha é **fora** da zona — sem Muralha, sem Torre, sem Bastião e sem a guarnição. Vencendo,
   * o cerco se levanta; perdendo, o socorro morre e as 48 h continuam a correr.
   */
  romperCerco: (combatId: number, unitIds: number[]) =>
    req<{ id: number; status: string; chega_at: string }>('/war/break-siege', {
      method: 'POST',
      body: JSON.stringify({ combat_id: combatId, unit_ids: unitIds }),
    }),

  /** A ficha da zona: estruturas, canteiro, depósito, guarnição (D-67). Só o dono a vê. */
  zona: (id: number) => req<ZonaDetalhe>(`/zones/${id}`),

  /**
   * Ergue ou evolui uma estrutura da zona, num slot da colmeia (D-144). O material sai do
   * CANTEIRO, não do estoque da colônia.
   */
  construirNaZona: (id: number, structure: string, slot: number) =>
    req<{ obra: unknown }>(`/zones/${id}/build`, {
      method: 'POST',
      body: JSON.stringify({ structure, slot }),
    }),

  /**
   * Demole a estrutura de um slot da zona (D-138/D-144). A API **exige a palavra**, mesma
   * exigência de `demolir()` da colônia (D-61) — uma confirmação que vivesse só no React não
   * protegeria nada.
   */
  demolirEstruturaDaZona: (id: number, slot: number) =>
    req<{ demolida: boolean }>(`/zones/${id}/build/${slot}`, {
      method: 'DELETE',
      body: JSON.stringify({ confirmacao: 'DEMOLIR' }),
    }),

  /** Repara uma estrutura sabotada, ou resgata antecipadamente uma apreendida (D-118). */
  repararModulo: (id: number, estrutura: string) =>
    req<{ estruturas: ZonaDetalhe['estruturas'] }>(`/zones/${id}/reparar`, {
      method: 'POST',
      body: JSON.stringify({ estrutura }),
    }),

  /** Despacha um veículo com material de obra até o canteiro da zona. A entrega é FÍSICA (D-67). */
  entregarMaterial: (id: number, vehicleId: number, cargo: Record<string, number>) =>
    req<{ id: number; type: string; plate: string; status: string; arrives_at: string | null }>(
      `/zones/${id}/material`,
      { method: 'POST', body: JSON.stringify({ vehicle_id: vehicleId, cargo }) },
    ),

  /** Nomeia a zona, como já se nomeia a colônia. Vazio volta a mostrar as coordenadas. */
  /** A2.6: "transferência colônia → zona". Instantânea, sem colono em trânsito. */
  alocarOperadores: (id: number, quantos: number) =>
    req<{ operadores: ZonaDetalhe['operadores'] }>(`/zones/${id}/operadores`, {
      method: 'POST',
      body: JSON.stringify({ quantos }),
    }),

  /** E o "retorno". */
  devolverOperadores: (id: number, quantos: number) =>
    req<{ operadores: ZonaDetalhe['operadores'] }>(`/zones/${id}/operadores`, {
      method: 'DELETE',
      body: JSON.stringify({ quantos }),
    }),

  renomearZona: (id: number, name: string) =>
    req<{ name: string | null }>(`/zones/${id}/name`, {
      method: 'PATCH',
      body: JSON.stringify({ name }),
    }),

  /** O Histórico da zona (D-86): posse, financeiro e guerra, numa linha do tempo só. Só o dono vê. */
  historicoDaZona: (id: number) => req<{ eventos: EventoDaZona[] }>(`/zones/${id}/historico`),

  /**
   * A arte das construções (D-68). Só o que TEM imagem vem no mapa; o resto cai no hexágono.
   *
   * A chave é a coisa do jogo: um `building_type` (`reator_de_energia`, `sentinela`) ou um lugar da
   * Capital (`capital:slot:2`, `capital:area:oeste`).
   */
  imagens: () =>
    req<{ images: Record<string, { pequena: string; grande: string }> }>('/images'),

  // ── A Loja de Peças da Endurance (§05, D-135) — catálogo dinâmico, uma loja por seção ─────────

  enduranceEfeitos: () =>
    req<{
      desconto_tributo_pct: number
      teto_desconto_tributo_pct: number
      teto_producao_pct: number
      teto_veiculo_pct: number
      teto_drone_pct: number
    }>('/endurance/efeitos'),

  enduranceSecao: (secao: string) =>
    req<{
      secao: string
      meu_marco: number
      itens: ItemDaEndurance[]
    }>(`/endurance/secoes/${secao}`),

  comprarItemDaEndurance: (itemKey: string) =>
    req<{ item_key: string; quantidade: number }>(`/endurance/itens/${itemKey}/comprar`, { method: 'POST' }),

  /** Os itens que esta colônia possui e pode anunciar em Leilão (D-135, Fase 2) — todas as seções. */
  meusItensVendaveisEmLeilao: () =>
    req<{ itens: ItemVendavelEmLeilao[] }>('/endurance/meus-itens-vendaveis'),

  // ── o perfil do colono (D-69) ───────────────────────────────────────────────────────────────

  perfil: () => req<Perfil>('/profile'),

  /** O extrato bancário: só Fert$. Aberto ao clicar no card de saldo do HUD. */
  extrato: (pagina = 1) => req<Extrato>(`/profile/extrato?page=${pagina}`),

  /** Bugs/Melhorias (D-95): dados do jogador/colônia/e-mail são anexados pelo servidor. */
  enviarFeedback: (tipo: string, assunto: string, mensagem: string) =>
    req<{ ok: boolean }>('/feedback', {
      method: 'POST',
      body: JSON.stringify({ tipo, assunto, mensagem }),
    }),

  /** Trocar o e-mail exige a senha atual: é com ele que se entra, e não há recuperação de conta. */
  salvarPerfil: (dados: {
    name: string
    nickname: string
    email: string
    colony_name?: string
    senha_atual?: string
  }) => req<{ ok: boolean }>('/profile', { method: 'PATCH', body: JSON.stringify(dados) }),

  /** Trocar a senha REVOGA as outras sessões — senão quem entrou na conta continua dentro (D-53). */
  trocarSenha: (senha_atual: string, senha: string) =>
    req<{ ok: boolean; sessoes_revogadas: number }>('/profile/password', {
      method: 'POST',
      body: JSON.stringify({ senha_atual, senha, senha_confirmation: senha }),
    }),

  /** As minhas zonas, com o que exige ação: o exposto ao saque, o cerco e a obra. */
  minhasZonas: () => req<{ zones: MinhaZona[] }>('/zones/minhas'),

  fundarColonia: (name: string, x: number, y: number) =>
    req<Colonia>('/colony', { method: 'POST', body: JSON.stringify({ name, x, y }) }),

  construcoes: () => req<Spec[]>('/buildings'),

  enfileirar: (id: number) => req<ItemDaFila>(`/buildings/${id}/upgrade`, { method: 'POST' }),

  /** O que se pode erguer, e a colmeia de 21 slots (D-59). */
  catalogo: () => req<Catalogo>('/buildings/catalogo'),

  /** Ergue uma construção no slot escolhido: cria a linha e enfileira o nível 1 (D-59). */
  construir: (type: string, slot: number) =>
    req<{ building: string; slot: number }>('/buildings', {
      method: 'POST',
      body: JSON.stringify({ type, slot }),
    }),

  /** Demole e libera o slot. O investido não volta (D-59). */
  /**
   * Demolir (D-61). A API **exige a palavra** — não é enfeite de tela.
   *
   * Uma confirmação que vivesse só no React protegeria contra o dedo escorregando e contra mais
   * nada: quem chamasse a API direto demoliria sem digitar coisa alguma. Ela vai no corpo porque é
   * a porta de verdade.
   */
  demolir: (id: number) =>
    req<{ demolida: boolean }>(`/buildings/${id}`, {
      method: 'DELETE',
      body: JSON.stringify({ confirmacao: 'DEMOLIR' }),
    }),

  fila: () => req<Fila>('/queue'),

  /** As três receitas do §24.5. Sem esta lista, escolher receita seria digitar códigos à mão. */
  receitas: () => req<Receita[]>('/recipes'),

  escolherReceita: (building: number, recipe: string) =>
    req<Receita>(`/buildings/${building}/recipe`, {
      method: 'PATCH',
      body: JSON.stringify({ recipe }),
    }),

  frota: () => req<Frota>('/vehicles'),

  /** A2.8: os eventos de mundo que o jogador pode ver. */
  eventosDoMundo: () => req<{ eventos: EventoDoMundo[] }>('/eventos'),

  /** A2.3: a árvore de pesquisa. */
  arvoreDePesquisa: () => req<ArvoreDePesquisa>('/pesquisa'),
  pesquisar: (id: number) => req<{ iniciada: boolean }>(`/pesquisa/${id}`, { method: 'POST' }),

  /** Dá (ou tira) um apelido do veículo. A placa não muda — é do veículo, não do dono (§16.3). */
  renomearVeiculo: (veiculo: number, nickname: string) =>
    req<{ nickname: string | null }>(`/vehicles/${veiculo}/nickname`, {
      method: 'PATCH',
      body: JSON.stringify({ nickname }),
    }),

  /** Leva carga do estoque até a doca do Mercado. O tributo incide na chegada (D-32). */
  depositar: (veiculo: number, cargo: Record<string, number>) =>
    req<Veiculo>(`/vehicles/${veiculo}/dispatch`, {
      method: 'POST',
      body: JSON.stringify({ destination_type: 'mercado_central', cargo }),
    }),

  /**
   * Reposiciona um veículo VAZIO (D-109) — substitui o antigo "Chamar de volta": do Pátio, para
   * casa ou para uma zona neutra sua; de casa, para a Capital ou para uma zona neutra sua; de uma
   * zona neutra sua, só de volta para casa. O servidor decide o que aquele destino aceita a partir
   * de onde o veículo está agora — esta função só varia o destino.
   */
  reposicionarVazio: (
    veiculo: number,
    destinationType: 'colonia' | 'mercado_central' | 'zona_neutra',
    destinationId: number | null,
  ) =>
    req<Veiculo>(`/vehicles/${veiculo}/dispatch`, {
      method: 'POST',
      body: JSON.stringify({ destination_type: destinationType, destination_id: destinationId, cargo: {} }),
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

  /** Sem `counterparty_id`, a oferta vai ao mural, aberta a quem quiser (D-58). */
  proporAcordo: (b: {
    counterparty_id?: number | null
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

  /** O governo leva (§07, D-76): frete pago, com tributo na chegada como toda entrega física. */
  fretePublico: (cargo: Record<string, number>) =>
    req<{ caminhao: string; chega_at: string; preco_fert: number }>('/market/freight', {
      method: 'POST',
      body: JSON.stringify({ cargo }),
    }),

  // ── O Sistema de Mensagens (§10; D-77). Polling: `after` traz só o que chegou depois. ──
  chatCanais: () =>
    req<{
      nickname: string
      silenciado_ate: string | null
      bloqueados: { id: number; nickname: string }[]
    }>('/chat'),
  /** O poll leve do HUD (~30 s, mesmo com o painel fechado): as contagens do selo, e por canal. */
  chatPendencias: () =>
    req<{
      privadas_nao_lidas: number
      mencoes: number
      mencoes_por_canal: { global: number; vizinhanca: number; federacao: number }
    }>('/chat/pendencias'),
  chatLer: (canal: 'global' | 'vizinhanca' | 'federacao', after = 0) =>
    req<{ mensagens: MensagemDeChat[] }>(`/chat/${canal}?after=${after}`),
  chatFalar: (canal: 'global' | 'vizinhanca' | 'federacao', body: string) =>
    req<MensagemDeChat>(`/chat/${canal}`, { method: 'POST', body: JSON.stringify({ body }) }),
  chatConversas: () =>
    req<{ conversas: { user_id: number; nickname: string; ultima: MensagemDeChat; nao_lidas: number }[] }>('/chat/conversas'),
  chatPrivada: (userId: number, after = 0) =>
    req<{ com: { id: number; nickname: string }; mensagens: MensagemDeChat[] }>(`/chat/privada/${userId}?after=${after}`),
  chatFalarPrivado: (userId: number, body: string) =>
    req<MensagemDeChat>(`/chat/privada/${userId}`, { method: 'POST', body: JSON.stringify({ body }) }),
  chatBloquear: (userId: number) =>
    req<{ bloqueado: string }>(`/chat/bloquear/${userId}`, { method: 'POST' }),
  chatDesbloquear: (userId: number) =>
    req<{ desbloqueado: string }>(`/chat/bloquear/${userId}`, { method: 'DELETE' }),

  /** O card "quem é esse colono" (D-81): do Chat privado e do diretório de colônias. */
  jogador: (userId: number) => req<JogadorInfo>(`/players/${userId}/info`),

  // ── As Missões do §06 (D-78): a mão do dia nasce no primeiro pedido; 1 rejeição diária. ──
  missoes: () =>
    req<{
      missoes: Missao[]
      rejeicoes_restantes: number
      dia_vira_em: string
      semana_vira_em: string
    }>('/missions'),
  rejeitarMissao: (id: number) =>
    req<{ rejeitada: number }>(`/missions/${id}/reject`, { method: 'POST' }),

  conta: () => req<ContaDoMercado>('/market/account'),

  /** Sem `recurso`, a vitrine traz todos: é assim que se enxerga o que os outros anunciaram. */
  vitrine: (recurso?: string) =>
    req<Vitrine>(
      recurso ? `/market/orders?resource_type=${encodeURIComponent(recurso)}` : '/market/orders',
    ),

  ordenar: (b: { side: 'buy' | 'sell'; resource_type: string; qty: number; price_micro: number }) =>
    req<Ordem>('/market/orders', { method: 'POST', body: JSON.stringify(b) }),

  /**
   * Fecha uma oferta da vitrine, pelo preço dela (D-58). Não há veículo: as duas pontas já estão na
   * Capital, e o recurso passa de um depósito ao outro. Retirá-lo é outra viagem, e outro tributo.
   */
  executarOferta: (ordem: number, qty: number) =>
    req<{ id: number; qty: number; status: string }>(`/market/orders/${ordem}/execute`, {
      method: 'POST',
      body: JSON.stringify({ qty }),
    }),

  cancelar: (ordem: number) =>
    req<{ id: number; status: string }>(`/market/orders/${ordem}`, { method: 'DELETE' }),

  // ── Leilões (D-129) — sem seção no GDD, desenho nosso sobre o Mercado Central ──────────────

  leiloes: () => req<{ abertos: Leilao[]; minhas: Leilao[] }>('/auctions'),

  anunciarLeilao: (
    b: { qty: number; lance_minimo_fert: number; duracao_horas: number } & (
      | { resource_type: string; item_key?: never }
      | { resource_type?: never; item_key: string }
    ),
  ) =>
    req<{ id: number; status: string; deadline_at: string }>('/auctions', {
      method: 'POST',
      body: JSON.stringify(b),
    }),

  darLance: (leilao: number, lance_fert: number) =>
    req<{ id: number; lance_atual_fert: number }>(`/auctions/${leilao}/bid`, {
      method: 'POST',
      body: JSON.stringify({ lance_fert }),
    }),

  cancelarLeilao: (leilao: number) =>
    req<{ id: number; status: string }>(`/auctions/${leilao}`, { method: 'DELETE' }),

  /** O mural: as ofertas abertas de todos os colonos, sem contraparte definida (D-58). */
  mural: () => req<{ ofertas: OfertaDeColono[] }>('/trade/board'),

  /** Aceita uma oferta do mural e vira a contraparte dela. Quem chega primeiro leva. */
  aceitarOfertaDoMural: (id: number) =>
    req<Acordo>(`/trade/agreements/${id}/accept`, { method: 'POST' }),

  // ── Federação (§04/§07, D-114) — o Quartel de Alianças, Capital slot 9 ──────────────────────

  /** A federação da própria colônia (ou `federation: null`), membros, fundo e pendências. */
  minhaFederacao: () => req<MinhaFederacao>('/federation'),

  /*
   * A2.5: torna o teto antimonopólio VISÍVEL antes de o colono bater nele. Só leitura — quem aplica
   * o limite continua sendo o domínio, em `OcuparZonaNeutra`.
   */
  concentracaoDaFederacao: () => req<ConcentracaoDaFederacao>('/federation/concentracao'),

  mesaDiplomatica: () => req<MesaDiplomatica>('/federation/diplomacia'),
  proporAlianca: (id: number) =>
    req<{ proposta: boolean }>(`/federations/${id}/alianca`, { method: 'POST' }),
  aceitarAlianca: (id: number) =>
    req<{ aliada: boolean }>(`/federations/${id}/alianca/accept`, { method: 'POST' }),
  romperAlianca: (id: number) =>
    req<{ rompida: boolean }>(`/federations/${id}/alianca`, { method: 'DELETE' }),

  /** A2.10: declarar guerra. Não há recusa do outro lado — ver o D-193, decisão 4. */
  declararGuerra: (id: number) =>
    req<{ guerra: { id: number; termina_em: string } }>(`/federations/${id}/guerra`, { method: 'POST' }),

  /** A2.10: declarar-se neutra. Imediato — sair, não. */
  declararNeutralidade: () =>
    req<{ neutra_desde: string | null }>('/federation/neutralidade', { method: 'POST' }),
  encerrarNeutralidade: () =>
    req<{ termina_em: string | null }>('/federation/neutralidade', { method: 'DELETE' }),

  contribuirParaOFundo: (fert: number) =>
    req<{ fundo_fert: number }>('/federation/fundo', {
      method: 'POST',
      body: JSON.stringify({ fert }),
    }),

  /** O diretório público — para escolher a quem pedir entrada. */
  federacoes: () => req<FederationListItem[]>('/federations'),

  fundarFederacao: (name: string) =>
    req<{ id: number; name: string }>('/federations', { method: 'POST', body: JSON.stringify({ name }) }),

  convidarParaFederacao: (federation: number, colonyId: number) =>
    req<{ id: number }>(`/federations/${federation}/invite`, {
      method: 'POST',
      body: JSON.stringify({ colony_id: colonyId }),
    }),

  pedirEntradaNaFederacao: (federation: number) =>
    req<{ id: number }>(`/federations/${federation}/apply`, { method: 'POST' }),

  aceitarConviteDeFederacao: (invite: number) =>
    req<{ ok: true }>(`/federation/invites/${invite}/accept`, { method: 'POST' }),

  recusarConviteDeFederacao: (invite: number) =>
    req<{ ok: true }>(`/federation/invites/${invite}/reject`, { method: 'POST' }),

  cancelarConviteDeFederacao: (invite: number) =>
    req<{ ok: true }>(`/federation/invites/${invite}`, { method: 'DELETE' }),

  sairDaFederacao: (confirmacao: string) =>
    req<{ ok: true }>('/federation/leave', { method: 'POST', body: JSON.stringify({ confirmacao }) }),

  transferirLiderancaDaFederacao: (colonyId: number) =>
    req<{ ok: true }>('/federation/transfer-leadership', {
      method: 'POST',
      body: JSON.stringify({ colony_id: colonyId }),
    }),

  expulsarDaFederacao: (colonyId: number) =>
    req<{ ok: true }>(`/federation/members/${colonyId}/kick`, { method: 'POST' }),

  alterarCargoNaFederacao: (colonyId: number, role: string) =>
    req<{ ok: true }>(`/federation/members/${colonyId}/role`, {
      method: 'PATCH',
      body: JSON.stringify({ role }),
    }),

  sacarDoFundoDaFederacao: (resourceType: string, amount: number) =>
    req<{ ok: true }>('/federation/withdraw', {
      method: 'POST',
      body: JSON.stringify({ resource_type: resourceType, amount }),
    }),

  /** Contribui ao fundo — a MESMA rota de despacho, com `destination_type: 'federacao'` (D-114). */
  contribuirParaFederacao: (veiculo: number, cargo: Record<string, number>) =>
    req<Veiculo>(`/vehicles/${veiculo}/dispatch`, {
      method: 'POST',
      body: JSON.stringify({ destination_type: 'federacao', cargo }),
    }),
}

// ── a zona como LUGAR (§17.4, D-67) ───────────────────────────────────────────────────────────────

/** Uma estrutura ERGUIDA — uma linha por slot (D-144; até o D-144 era uma entrada por TIPO). */
export type EstruturaDaZona = {
  slot: number
  type: string
  nome: string
  level: number
  /** O que o GDD PROMETE. */
  gdd: string
  /** O que o jogo ENTREGA hoje. As duas coisas não se confundem (padrão do D-59). */
  hoje: string
  /** O Cemitério é declarado "apenas visual" pelo próprio GDD. */
  inerte: boolean
  /** Só o Posto de Comando (slot fixo) — nasce com a ocupação, não se demole. */
  indemolivel: boolean
  /** Desligada por uma apreensão (§28.10) — mantido por compatibilidade, é o mesmo que `apreendida`. */
  offline: boolean
  /** Quanto do efeito normal está de pé agora, em bps (10000 = cheio, 0 = totalmente fora, D-118). */
  fracao_efetiva: number
  /** A Apreensão do Predador: desliga por inteiro até `expira_em` (24h) ou um reparo antecipado. */
  apreendida: { expira_em: string | null } | null
  /** A Sabotagem do Infiltrador: reduz proporcionalmente ao nível de quem sabotou. Sem prazo — só reparo. */
  sabotada: { nivel_do_infiltrador: number } | null
  /** Custo do reparo/resgate, só quando `apreendida` ou `sabotada`. */
  custo_reparo: Record<string, number> | null
  proximo: { level: number; custo: Record<string, number>; segundos: number } | null
}

/** Um tipo do catálogo — o que se PODE erguer num slot vazio (D-144, mirror de `Erguivel`). */
export type ErguivelNaZona = {
  type: string
  nome: string
  gdd: string
  hoje: string
  inerte: boolean
  /** As produtoras (Refinaria, Indústria Siderúrgica, Estrutura de Extração) podem repetir. */
  repetivel: boolean
  quantas: number
  disponivel: boolean
  custo_nivel_1: Record<string, number> | null
  segundos_nivel_1: number | null
}

export type ZonaDetalhe = {
  id: number
  /**
   * A equipe da zona (A2.6).
   *
   * Sem isto a degradação do §6.6 seria invisível: o jogador veria a extração cair e não teria como
   * saber por quê — e penalidade que não se consegue ver é indistinguível de defeito.
   */
  operadores: {
    ativo: boolean
    na_zona: number
    exigidos: number
    /** Em porcentagem. 100 = equipe completa; nunca zero, porque o §6.6 degrada e não para. */
    eficiencia: number
    livres_na_colonia: number
  }
  name: string | null
  x: number
  y: number
  district: string
  mineral: string
  status: string
  cercada: boolean
  productive_at: string | null
  protected_until: string | null
  /** Upgrade de nível e manutenção territorial (D-84). */
  level: number
  upgrade: {
    target: number | null
    finishes_at: string | null
    proximo_custo: { metal_bruto: number; fert: number } | null
    proxima_guarnicao: number | null
  }
  manutencao: {
    custo_diario: Record<string, number>
    proximo_vencimento: string | null
    inadimplente_desde: string | null
    penalidade_bps: number
  }
  deposito: {
    bruto: number
    /** O que a Refinaria de Campo já converteu. Ocupa o mesmo Depósito. */
    refinado: number
    refinado_recurso: string | null
    /** Os minerais da Indústria Siderúrgica (D-82) — mesmo Depósito. Só os com saldo aparecem. */
    minerais: { resource_type: string; amount: number }[]
    capacidade: number
    /** O que cabe no Depósito está a salvo do saque; o que transborda é butim (D-66). */
    protegido: number
    exposto: number
  }
  extracao_hora: number
  refino_hora: number
  guarnicao: { robos: number; sentinelas: number; defesa: number }
  /**
   * A colmeia de slots (D-144) — mesma geometria da colônia (`Domain\Colony\Slots`), 22 slots,
   * com o Posto de Comando fixo no centro. `desbloqueados` cresce com o nível da zona: o que não
   * está nela é um slot ainda TRANCADO, não um slot vazio comum.
   */
  estruturas: {
    colmeia: { linhas: number[]; total: number; slot_do_posto: number; desbloqueados: number[] }
    /** Uma entrada por slot OCUPADO — repetíveis podem aparecer mais de uma vez, em slots diferentes. */
    erguidas: EstruturaDaZona[]
    /** O que se pode erguer num slot vazio e desbloqueado. */
    catalogo: ErguivelNaZona[]
  }
  /** O canteiro de obras: material entregue de veículo, à espera de virar construção. */
  canteiro: { resource_type: string; amount: number }[]
  /** A fila de obras inteira — pode ter mais de uma ao mesmo tempo, conforme `obras_vagas`. */
  obras: { structure: string; slot: number; nome: string; target_level: number; finishes_at: string }[]
  /** O teto de obras simultâneas na zona (`FilaSetting.zona_vagas`, do operador, D-111). */
  obras_vagas: number
  /** O que o §17.4 lista e o jogo NÃO tem, com o porquê. */
  ausentes: Record<string, { nome: string; porque: string }>
  modules_offline: string[]
}

/** Uma linha do Histórico da zona (D-86) — posse, financeiro ou guerra, já numa forma comum. */
export type EventoDaZona = {
  categoria: 'financeiro' | 'guerra' | 'posse'
  em: string
  tipo: string
  // financeiro
  recurso?: string | null
  quantidade?: number
  ref?: string
  // guerra
  status?: string
  atacante?: string | null
  defensor?: string | null
  resultado?: Record<string, unknown> | null
  // posse
  colonia?: string | null
  meta?: Record<string, unknown> | null
}
