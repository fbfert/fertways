import { useEffect, useState } from 'react'
import { api, ApiError } from '../api/client'
import type { Colonia, Fila, Receita, Spec } from '../api/client'
import { rotulo } from '../game/ColonyScene'
import { nomeRecurso } from './recursos'

/** Ordem do slide 07: primários, depois industriais. Só o que a colônia realmente move. */
const PRIMARIOS = ['oxigenio', 'agua', 'biomassa', 'energia']
const INDUSTRIAIS = ['metal_bruto', 'ligas_metalicas', 'compostos_quimicos', 'biocombustivel', 'componentes_eletronicos']

function Linha({ codigo, valor }: { codigo: string; valor: number }) {
  return (
    <div className="border-rust/10 flex items-center justify-between border-b py-1.5 last:border-0">
      <span className="text-ink-soft text-sm">{nomeRecurso(codigo)}</span>
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

/**
 * As três receitas de Componentes Eletrônicos (§24.5). Só a Oficina as tem.
 *
 * O `PATCH /buildings/{id}/recipe` existia desde a fatia de fabricação e **nenhuma tela o
 * chamava**: o jogador ficava preso na Básica, que é só o padrão do D-23, sem saber que havia
 * escolha. A lista vem da API, não daqui — os insumos são do GDD e moram no banco.
 */
function ReceitaDaOficina({ spec, aoAtualizar }: { spec: Spec; aoAtualizar: () => void }) {
  const [receitas, setReceitas] = useState<Receita[]>([])
  const [erro, setErro] = useState<string | null>(null)
  const [salvando, setSalvando] = useState(false)

  useEffect(() => {
    api
      .receitas()
      .then(setReceitas)
      .catch((e: unknown) => setErro(e instanceof ApiError ? e.message : 'Falha ao ler as receitas.'))
  }, [])

  async function escolher(code: string) {
    if (code === spec.recipe || salvando) return
    setErro(null)
    setSalvando(true)
    try {
      await api.escolherReceita(spec.id, code)
      aoAtualizar()
    } catch (e) {
      setErro(e instanceof ApiError ? e.message : 'Falha ao trocar a receita.')
    } finally {
      setSalvando(false)
    }
  }

  const ativa = receitas.find((r) => r.code === spec.recipe)

  return (
    <>
      <div className="border-rust/30 my-3 border-t" />
      <div className="text-ink-soft eyebrow">Receita de Componentes</div>

      <div className="mt-2 space-y-1">
        {receitas.map((r) => (
          <button
            key={r.code}
            onClick={() => void escolher(r.code)}
            disabled={salvando}
            className={`block w-full px-2 py-1.5 text-left text-sm ${
              r.code === spec.recipe
                ? 'bg-rust text-sand-light'
                : 'text-ink-soft hover:bg-sand disabled:opacity-50'
            }`}
          >
            {r.nome}
            {r.padrao && r.code !== spec.recipe && <span className="text-xs"> · padrão</span>}
          </button>
        ))}
      </div>

      {ativa && (
        <>
          <p className="text-ink-soft/70 mt-2 text-xs">{ativa.contexto}</p>
          <div className="mt-2">
            {Object.entries(ativa.insumos_por_unidade).map(([c, v]) => (
              <Linha key={c} codigo={c} valor={v} />
            ))}
          </div>
          <p className="text-ink-soft/70 mt-1 text-xs">Insumos por unidade produzida.</p>
        </>
      )}

      {erro && <p className="text-rust mt-2 text-sm">{erro}</p>}
    </>
  )
}

export function Detalhe({
  spec,
  aoConstruir,
  aoAtualizar,
  erro,
}: {
  spec: Spec | null
  aoConstruir: (s: Spec) => void
  aoAtualizar: () => void
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

      {/* Oficina no nível 0 não fabrica nada; oferecer receita ali seria oferecer o vazio. */}
      {spec.type === 'oficina' && spec.level > 0 && (
        <ReceitaDaOficina spec={spec} aoAtualizar={aoAtualizar} />
      )}
    </div>
  )
}
