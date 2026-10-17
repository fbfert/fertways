import { useState } from 'react'
import type { ResumoDeAvaliacoes } from '../api/client'

/**
 * As estrelas do comércio entre colonos (GDD §8.4; D-287): a nota de 0 a 5 que cada parte dá à
 * outra depois que o Acordo de Troca fecha, pública no perfil.
 */
export function Estrelas({ nota, tamanho = 'text-base' }: { nota: number; tamanho?: string }) {
  const cheias = Math.round(nota)

  return (
    <span className={`${tamanho} tracking-tight`} aria-label={`${nota.toLocaleString('pt-BR')} de 5 estrelas`}>
      <span className="text-rust">{'★'.repeat(cheias)}</span>
      <span className="text-ink-soft/40">{'★'.repeat(5 - cheias)}</span>
    </span>
  )
}

/** O resumo do perfil: "4,7 estrelas · 23 avaliações recebidas", e as últimas notas. */
export function ResumoDeEstrelas({ resumo }: { resumo: ResumoDeAvaliacoes }) {
  if (resumo.total === 0) {
    return <p className="text-ink-soft mt-1 text-xs">Ainda sem avaliações de comércio.</p>
  }

  return (
    <div className="mt-1" data-avaliacoes>
      <div className="flex items-center gap-2">
        <Estrelas nota={resumo.media ?? 0} />
        <span className="text-ink-soft text-xs">
          {(resumo.media ?? 0).toLocaleString('pt-BR', { minimumFractionDigits: 1 })} estrelas · {resumo.total}{' '}
          {resumo.total === 1 ? 'avaliação recebida' : 'avaliações recebidas'}
        </span>
      </div>
      <ul className="mt-2 space-y-2">
        {resumo.ultimas.map((a, i) => (
          <li key={i} className="text-xs">
            <Estrelas nota={a.estrelas} tamanho="text-xs" />
            {a.comentario && <span className="text-ink"> "{a.comentario}"</span>}
            <span className="text-ink-soft"> — {a.autor ?? 'colono'}</span>
          </li>
        ))}
      </ul>
    </div>
  )
}

/** Escolher a nota e, se quiser, dizer por quê. A nota zero existe: é o calote (§8.2). */
export function Avaliar({
  nome,
  aoAvaliar,
}: {
  nome: string
  aoAvaliar: (estrelas: number, comentario: string) => Promise<void>
}) {
  const [nota, setNota] = useState<number | null>(null)
  const [comentario, setComentario] = useState('')
  const [enviando, setEnviando] = useState(false)

  return (
    <div className="border-rust/20 mt-3 border-t pt-3" data-avaliar>
      <div className="text-ink eyebrow text-xs">Como foi negociar com {nome}?</div>
      <div className="mt-2 flex flex-wrap items-center gap-1">
        {[0, 1, 2, 3, 4, 5].map((n) => (
          <button
            key={n}
            type="button"
            onClick={() => setNota(n)}
            aria-pressed={nota === n}
            className={`border px-2 py-1 text-xs font-bold ${nota === n ? 'bg-rust text-sand-light border-rust' : 'border-rust/25 text-ink-soft hover:text-rust'}`}
          >
            {n} ★
          </button>
        ))}
      </div>
      <textarea
        value={comentario}
        onChange={(e) => setComentario(e.target.value.slice(0, 280))}
        placeholder="Comentário público (opcional)"
        rows={2}
        className="border-rust/25 mt-2 w-full border bg-transparent p-2 text-xs"
      />
      <div className="mt-1 flex items-center gap-2">
        <p className="text-ink-soft text-xs">
          A nota é pública e mexe na Confiança Comercial de {nome}. Avaliação injusta pode ser denunciada.
        </p>
        <button
          type="button"
          disabled={nota === null || enviando}
          onClick={async () => {
            if (nota === null) return
            setEnviando(true)
            try {
              await aoAvaliar(nota, comentario)
            } finally {
              setEnviando(false)
            }
          }}
          className="bg-rust text-sand-light hover:bg-rust-bright ml-auto shrink-0 px-3 py-1.5 text-xs font-bold disabled:opacity-40"
        >
          Avaliar
        </button>
      </div>
    </div>
  )
}
