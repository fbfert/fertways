import type { Colonia, Fila, Spec } from '../api/client'
import { rotulo } from '../game/ColonyScene'

/** Ordem do slide 07: primários, depois industriais. Só o que a colônia realmente move. */
const PRIMARIOS = ['oxigenio', 'agua', 'biomassa', 'energia']
const INDUSTRIAIS = ['metal_bruto', 'ligas_metalicas', 'compostos_quimicos', 'biocombustivel', 'componentes_eletronicos']

const NOME_RECURSO: Record<string, string> = {
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

function Linha({ codigo, valor }: { codigo: string; valor: number }) {
  return (
    <div className="border-rust/10 flex items-center justify-between border-b py-1.5 last:border-0">
      <span className="text-ink-soft text-sm">{NOME_RECURSO[codigo] ?? codigo}</span>
      <span className="text-ink font-bold tabular-nums">{valor.toLocaleString('pt-BR')}</span>
    </div>
  )
}

export function Recursos({ colonia }: { colonia: Colonia }) {
  return (
    <div className="painel bg-sand-light w-64 p-4">
      <div className="text-rust eyebrow">Recursos primários</div>
      <div className="mt-2">
        {PRIMARIOS.map((c) => (
          <Linha key={c} codigo={c} valor={colonia.resources[c] ?? 0} />
        ))}
      </div>

      <div className="text-rust eyebrow mt-5">Recursos industriais</div>
      <div className="mt-2">
        {INDUSTRIAIS.map((c) => (
          <Linha key={c} codigo={c} valor={colonia.resources[c] ?? 0} />
        ))}
      </div>
    </div>
  )
}

function Contagem({ ate }: { ate: string | null }) {
  if (!ate) return <span className="text-ink-soft text-xs">na fila</span>
  const restam = Math.max(0, Math.round((new Date(ate).getTime() - Date.now()) / 1000))
  const m = Math.floor(restam / 60)
  const s = restam % 60
  return (
    <span className="text-rust font-bold tabular-nums">
      {m}:{String(s).padStart(2, '0')}
    </span>
  )
}

export function FilaDeObras({ fila }: { fila: Fila }) {
  return (
    <div className="painel bg-sand-light w-72 p-4">
      <div className="flex items-baseline justify-between">
        <span className="text-rust eyebrow">Fila de construção</span>
        <span className="text-ink-soft text-xs tabular-nums">
          {fila.used}/{fila.slots}
        </span>
      </div>

      {fila.items.length === 0 && (
        <p className="text-ink-soft mt-3 text-sm">Nada em obra.</p>
      )}

      <div className="mt-3 space-y-2">
        {fila.items.map((i) => (
          <div key={i.position} className="border-rust/15 border p-2">
            <div className="flex items-center justify-between">
              <span className="text-ink text-sm font-bold">{rotulo(i.building)}</span>
              <Contagem ate={i.finishes_at} />
            </div>
            <div className="text-ink-soft mt-0.5 text-xs">
              nível {i.target_level}
              {i.subsidized && <span className="text-rust"> · custeado pelo Governo</span>}
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}

export function Detalhe({
  spec,
  aoConstruir,
  erro,
}: {
  spec: Spec | null
  aoConstruir: (s: Spec) => void
  erro: string | null
}) {
  if (!spec) {
    return (
      <div className="painel bg-sand-light w-72 p-4">
        <div className="text-rust eyebrow">Construção</div>
        <p className="text-ink-soft mt-3 text-sm">Clique num hexágono da colônia.</p>
      </div>
    )
  }

  const noMaximo = spec.next_level === undefined
  const min = spec.build_time_seconds ? Math.round(spec.build_time_seconds / 60) : null

  return (
    <div className="painel bg-sand-light w-72 p-4">
      <div className="text-rust eyebrow">Construção</div>
      <h2 className="text-ink mt-1 text-lg leading-tight font-black">{rotulo(spec.type)}</h2>
      <div className="text-ink-soft text-xs">
        nível {spec.level} de {spec.max_level}
      </div>

      {spec.blocked === 'tempo_indefinido' && (
        <p className="text-rust mt-3 text-sm">
          O GDD não define tempo de construção para esta estrutura.
        </p>
      )}

      {noMaximo && !spec.blocked && (
        <p className="text-ink-soft mt-3 text-sm">Nível máximo atingido.</p>
      )}

      {spec.cost && (
        <>
          <div className="border-rust/30 my-3 border-t" />
          <div className="text-ink-soft eyebrow">Custo do nível {spec.next_level}</div>
          <div className="mt-1">
            {Object.entries(spec.cost).map(([c, v]) => (
              <Linha key={c} codigo={c} valor={v} />
            ))}
          </div>
          {min !== null && <div className="text-ink-soft mt-2 text-xs">Tempo: {min} min</div>}

          {/* §24.7, verbatim: a mensagem acompanha o custo, que continua exibido. */}
          {spec.subsidized && (
            <p className="text-rust mt-3 text-sm font-bold">
              Esta construção será custeada pelo Governo Central até o nível 3
            </p>
          )}

          {erro && <p className="text-rust mt-3 text-sm">{erro}</p>}

          <button
            onClick={() => aoConstruir(spec)}
            className="bg-rust text-sand-light hover:bg-rust-bright mt-4 w-full py-2.5 font-bold"
          >
            {spec.level === 0 ? 'Construir' : 'Evoluir'}
          </button>
        </>
      )}
    </div>
  )
}
