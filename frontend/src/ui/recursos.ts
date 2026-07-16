/** Nomes e formatação de recursos e de Fert$. Compartilhado pelo HUD e pela tela do Mercado. */

export const NOME_RECURSO: Record<string, string> = {
  // Primários (§8.3, `tax_class` = primario). Metal Bruto é PRIMÁRIO no GDD — a tela antiga o
  // listava entre os industriais, e estava errada.
  oxigenio: 'Oxigênio',
  agua: 'Água',
  biomassa: 'Biomassa',
  energia: 'Energia',
  metal_bruto: 'Metal Bruto',

  // Industriais: os 4 secundários do §18.2 mais os 8 minerais do §18.3 (D-58).
  ligas_metalicas: 'Ligas Metálicas',
  compostos_quimicos: 'Compostos Químicos',
  biocombustivel: 'Biocombustível',
  componentes_eletronicos: 'Componentes',
  aluminio: 'Alumínio',
  cobre: 'Cobre',
  estanho: 'Estanho',
  litio: 'Lítio',
  ouro: 'Ouro',
  silicio: 'Silício',
  tantalo: 'Tântalo',
  tungstenio: 'Tungstênio',

  // Raros. Não há fonte deles no MVP fora do kit inicial (D-85, era D-17): as fontes da
  // Temporada 1 (eventos, zonas profundas, contratos do governo) ainda não existem.
  bioenergia_curativa: 'Bioenergia Curativa',
  cristal_de_helio_3: 'Cristal de Hélio-3',
  ferro_vermelho: 'Ferro Vermelho',
  fungo_bioluminescente: 'Fungo Bioluminescente',
  gelo_de_metano: 'Gelo de Metano',
  niobio_alienigena: 'Nióbio Alienígena',
  plasma_fossilizado: 'Plasma Fossilizado',
  quartzo_piezoeletrico: 'Quartzo Piezoelétrico',
  resina_organica: 'Resina Orgânica',
}

/**
 * As três classes do GDD (§8.3), na ordem em que o painel as mostra. São os 26 recursos do
 * documento — a tela antiga mostrava 9.
 *
 * A classe não é decoração: é o `tax_class` que já governa o tributo (3/2/1%) e, desde o D-58, o
 * teto do depósito da Capital (10.000 / 2.500 / 100).
 */
export const PRIMARIOS = ['oxigenio', 'agua', 'biomassa', 'energia', 'metal_bruto']

export const INDUSTRIAIS = [
  'ligas_metalicas',
  'compostos_quimicos',
  'biocombustivel',
  'componentes_eletronicos',
  'aluminio',
  'cobre',
  'estanho',
  'litio',
  'ouro',
  'silicio',
  'tantalo',
  'tungstenio',
]

export const RAROS = [
  'bioenergia_curativa',
  'cristal_de_helio_3',
  'ferro_vermelho',
  'fungo_bioluminescente',
  'gelo_de_metano',
  'niobio_alienigena',
  'plasma_fossilizado',
  'quartzo_piezoeletrico',
  'resina_organica',
]

export const nomeRecurso = (codigo: string) => NOME_RECURSO[codigo] ?? codigo

/** Os dois veículos do MVP (§25.4). `rotulo()` da cena só conhece construções. */
const NOME_VEICULO: Record<string, string> = {
  furgao_de_comercio: 'Furgão de Comércio',
  caminhao_de_carga: 'Caminhão de Carga',
  drone_de_exploracao: 'Drone de Exploração',
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

/**
 * Prazo de Acordo de Troca, em linguagem grosseira. O `relogio()` não serve: o prazo mínimo é a
 * viagem mais 12 h (D-42), e "735:12" não diz nada a ninguém.
 */
export function prazoHumano(segundos: number): string {
  if (segundos <= 0) return 'vencido'

  const dias = Math.floor(segundos / 86400)
  const horas = Math.floor((segundos % 86400) / 3600)
  const minutos = Math.floor((segundos % 3600) / 60)

  if (dias > 0) return `${dias} d ${horas} h`
  if (horas > 0) return `${horas} h ${minutos} min`
  return `${minutos} min`
}

/** Um instante ISO como `<input type="datetime-local">` o exige: hora local, sem fuso nem segundos. */
export function paraCampoLocal(iso: string): string {
  const d = new Date(iso)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`
}

export function dataHumana(iso: string): string {
  return new Date(iso).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' })
}
