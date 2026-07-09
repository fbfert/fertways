/** Nomes e formatação de recursos e de Fert$. Compartilhado pelo HUD e pela tela do Mercado. */

export const NOME_RECURSO: Record<string, string> = {
  oxigenio: 'Oxigênio',
  agua: 'Água',
  biomassa: 'Biomassa',
  energia: 'Energia',
  metal_bruto: 'Metal Bruto',
  ligas_metalicas: 'Ligas Metálicas',
  compostos_quimicos: 'Compostos Químicos',
  biocombustivel: 'Biocombustível',
  componentes_eletronicos: 'Componentes',
}

export const nomeRecurso = (codigo: string) => NOME_RECURSO[codigo] ?? codigo

/** Os dois veículos do MVP (§25.4). `rotulo()` da cena só conhece construções. */
const NOME_VEICULO: Record<string, string> = {
  furgao_de_comercio: 'Furgão de Comércio',
  caminhao_de_carga: 'Caminhão de Carga',
}

export const nomeVeiculo = (tipo: string) => NOME_VEICULO[tipo] ?? tipo

/** Recursos que o colono pode negociar no Mercado. Energia não viaja como carga: é combustível. */
export const NEGOCIAVEIS = [
  'metal_bruto',
  'ligas_metalicas',
  'compostos_quimicos',
  'biocombustivel',
  'componentes_eletronicos',
  'oxigenio',
  'agua',
  'biomassa',
]

/** Fert$ vive em micro-unidades no backend (D-07): 1 Fert$ = 1.000.000 µF$. */
export const MICRO = 1_000_000

export function fert(micro: number, casas = 4): string {
  return (micro / MICRO).toLocaleString('pt-BR', {
    minimumFractionDigits: casas,
    maximumFractionDigits: casas,
  })
}

/** Converte o que o colono digitou em Fert$ para micro, sem passar por float impreciso. */
export function paraMicro(texto: string): number {
  const limpo = texto.trim().replace(',', '.')
  if (!/^\d+(\.\d{1,6})?$/.test(limpo)) return NaN
  const [inteiro, decimal = ''] = limpo.split('.')
  return Number(inteiro) * MICRO + Number(decimal.padEnd(6, '0'))
}

export function segundosRestantes(iso: string | null): number {
  if (!iso) return 0
  return Math.max(0, Math.round((new Date(iso).getTime() - Date.now()) / 1000))
}

export function relogio(segundos: number): string {
  const m = Math.floor(segundos / 60)
  const s = segundos % 60
  return `${m}:${String(s).padStart(2, '0')}`
}
