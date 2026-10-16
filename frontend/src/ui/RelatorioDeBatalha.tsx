import type { RelatorioDeBatalha as Relatorio } from '../api/client'
import { Selo } from './sistema'
import { dataHumana } from './recursos'

const NOME_DO_TIPO: Record<string, string> = {
  invasao: 'Invasão',
  cerco: 'Cerco',
  ruptura: 'Ruptura de cerco',
  sabotagem: 'Sabotagem',
  apreensao: 'Apreensão',
}

/**
 * A curva da batalha (A2.V6, D-275): a força de cada lado no começo de cada rodada.
 *
 * É a "timeline" e o "impacto" do roadmap numa figura só. Um placar final diz quem ganhou; a curva
 * diz COMO — a defesa que caiu reta, o reforço que chegou na rodada 6 e entortou a linha, a batalha
 * que se decidiu na última rodada. As duas linhas diferem pela cor E pelo traço (a defesa é
 * tracejada): a cor nunca é o único sinal (A2.V1).
 */
export function CurvaDaBatalha({ linha }: { linha: Relatorio['linha'] }) {
  if (linha.length < 2) return null

  const L = 300
  const A = 80
  const topo = Math.max(1, ...linha.map((p) => Math.max(p.fo, p.fd)))
  const x = (i: number) => (i / (linha.length - 1)) * L
  const y = (v: number) => A - (v / topo) * (A - 4)
  const caminho = (lado: 'fo' | 'fd') => linha.map((p, i) => `${x(i).toFixed(1)},${y(p[lado]).toFixed(1)}`).join(' ')

  return (
    <figure className="mt-2" data-curva-da-batalha>
      <svg viewBox={`0 0 ${L} ${A}`} className="h-20 w-full" preserveAspectRatio="none" role="img"
        aria-label={`Força de ataque de ${linha[0].fo} para ${linha[linha.length - 1].fo}, de defesa de ${linha[0].fd} para ${linha[linha.length - 1].fd}, em ${linha.length} rodadas`}
      >
        <line x1="0" y1={A} x2={L} y2={A} stroke="var(--color-hairline-forte)" />
        <polyline points={caminho('fo')} fill="none" stroke="var(--color-rust)" strokeWidth="2" vectorEffect="non-scaling-stroke" />
        <polyline points={caminho('fd')} fill="none" stroke="var(--color-info)" strokeWidth="2" strokeDasharray="5 3" vectorEffect="non-scaling-stroke" />
      </svg>
      <figcaption className="text-ink-soft flex flex-wrap justify-between gap-2 text-micro">
        <span>
          <span className="text-rust font-bold">— ataque</span> {linha[0].fo} → {linha[linha.length - 1].fo}
          {'  '}
          <span className="text-info font-bold">- - defesa</span> {linha[0].fd} → {linha[linha.length - 1].fd}
        </span>
        <span>rodada 1 a {linha[linha.length - 1].r}</span>
      </figcaption>
    </figure>
  )
}

/** "12 entraram, 9 voltaram (3 perdidas)" — ou o que se souber, nas batalhas de antes do D-275. */
function baixas(lado: { inicio: number | null; de_pe: number | null }, voltar: boolean): string | null {
  if (lado.inicio === null) return null
  if (lado.de_pe === null) return `${lado.inicio} em campo`

  const perdidas = Math.max(0, lado.inicio - lado.de_pe)

  return `${lado.inicio} em campo, ${lado.de_pe} ${voltar ? 'voltaram' : 'de pé'}${perdidas > 0 ? ` (${perdidas} perdida${perdidas > 1 ? 's' : ''})` : ''}`
}

/**
 * O relatório de UMA batalha encerrada (A2.V6, D-275). Até aqui a batalha terminava e sumia do
 * Quartel; quem perdesse uma zona à noite voltaria a um mapa diferente sem uma linha dizendo o quê.
 */
export function RelatorioDeBatalha({ r }: { r: Relatorio }) {
  const meu = r.sou_o_atacante ? r.atacantes : r.defensores
  const deles = r.sou_o_atacante ? r.defensores : r.atacantes
  const onde = r.zona ? `a zona ${r.zona.nome ?? ''} (${r.zona.x}, ${r.zona.y})`.replace('  ', ' ') : 'sua colônia'
  const quem = r.adversario ?? 'uma colônia que não existe mais'

  return (
    <li className="painel bg-sand p-3 text-sm" data-relatorio={r.id} data-venci={String(r.venci)}>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          {r.venci === true ? (
            <Selo estado="sucesso" tom="forte">Vitória</Selo>
          ) : r.venci === false ? (
            <Selo estado="perigo" tom="forte">Derrota</Selo>
          ) : (
            <Selo estado="neutro" tom="forte">Sem combate</Selo>
          )}
          <strong>{NOME_DO_TIPO[r.tipo] ?? r.tipo}</strong>
        </div>
        {r.terminou_em && <span className="text-ink-soft text-xs">terminou {dataHumana(r.terminou_em)}</span>}
      </div>

      <p className="text-ink mt-1">
        {r.sou_o_atacante ? `Você atacou ${onde}, de ${quem}.` : `${quem} atacou ${onde}.`}
        {r.rodadas > 0 && ` ${r.rodadas} rodada${r.rodadas > 1 ? 's' : ''} (${r.rodadas * 10} min).`}
      </p>

      <ul className="text-ink-soft mt-1 space-y-0.5 text-xs">
        {baixas(meu, r.sou_o_atacante) && <li data-baixas="minhas">Seu lado: {baixas(meu, r.sou_o_atacante)}</li>}
        {baixas(deles, !r.sou_o_atacante) && <li data-baixas="deles">O outro lado: {baixas(deles, !r.sou_o_atacante)}</li>}
        {r.saque !== null && r.saque > 0 && (
          <li className="text-ink font-bold" data-saque>
            {r.sou_o_atacante ? `Saque trazido: ${r.saque.toLocaleString('pt-BR')}` : `Levado no saque: ${r.saque.toLocaleString('pt-BR')}`}
          </li>
        )}
      </ul>

      <CurvaDaBatalha linha={r.linha} />

      {r.chegou_em && (
        <p className="text-ink-soft mt-1 text-micro">
          {r.despachado_em && `partiu ${dataHumana(r.despachado_em)} · `}chegou {dataHumana(r.chegou_em)}
        </p>
      )}
    </li>
  )
}
