import { useState } from 'react'
import { Financas } from './Financas'
import { Noticias } from './Noticias'
import { Tesouro } from './Tesouro'
import { Transportes } from './Transportes'

/**
 * A Capital — o hub das instituições do governo (§02, §2.1). Não substitui os botões do HUD:
 * é o diretório dos slots. Os slots ativos abrem sua tela; o Mercado (6) e o Ministério (7) reusam
 * as telas de topo do HUD (via callbacks), e as instituições novas (Tesouro 2, Notícias 3,
 * Finanças 4, Transportes 8) abrem como sub-telas aqui dentro.
 *
 * O Ministério da Segurança e Guerra (5) é a Fatia 2 do D-52 — aparece marcado "em breve".
 *
 * **O slot 8 é uma arbitragem contra o GDD.** O §2.1 o reserva para o Quartel de Alianças, fora do
 * MVP; o usuário pôs ali o Ministério dos Transportes (D-60). São oito slots agora, não sete.
 */
type Sub = 'tesouro' | 'financas' | 'noticias' | 'transportes'

type Acao =
  | { tipo: 'sub'; sub: Sub }
  | { tipo: 'externa'; chave: string; abrir: () => void }
  | { tipo: 'equipe' }
  | { tipo: 'embreve' }
  | { tipo: 'travado' }

type Slot = { n: string; nome: string; funcao: string; acao: Acao }

export function Capital({
  aoFechar,
  aoAbrirMercado,
  aoAbrirMinisterio,
}: {
  aoFechar: () => void
  aoAbrirMercado: () => void
  aoAbrirMinisterio: () => void
}) {
  const [sub, setSub] = useState<Sub | null>(null)

  const slots: Slot[] = [
    { n: '1', nome: 'Administração Pública', funcao: 'Regras, comunicados e aplicação de sanções.', acao: { tipo: 'equipe' } },
    { n: '2', nome: 'Central de Tributos', funcao: 'Painel de taxas e Tesouro Público.', acao: { tipo: 'sub', sub: 'tesouro' } },
    { n: '3', nome: 'Central de Pesquisas e Notícias', funcao: 'Descobertas, Gagarin e boletins oficiais.', acao: { tipo: 'sub', sub: 'noticias' } },
    { n: '4', nome: 'Secretaria de Finanças e Tesouro', funcao: 'Indicadores, preços de referência e intervenções.', acao: { tipo: 'sub', sub: 'financas' } },
    { n: '5', nome: 'Ministério da Segurança e Guerra', funcao: 'Conflitos, tratados e janelas de vulnerabilidade.', acao: { tipo: 'embreve' } },
    { n: '6', nome: 'Pátio Logístico Público', funcao: 'Docas públicas e Mercado Central.', acao: { tipo: 'externa', chave: 'mercado', abrir: aoAbrirMercado } },
    { n: '7', nome: 'Ministério das Reputações', funcao: 'Denúncias, conciliação, recursos e histórico.', acao: { tipo: 'externa', chave: 'ministerio', abrir: aoAbrirMinisterio } },
    { n: '8', nome: 'Ministério dos Transportes', funcao: 'Fábrica de Caminhões, registro de placas e frota.', acao: { tipo: 'sub', sub: 'transportes' } },
  ]

  const TITULO: Record<Sub, string> = {
    tesouro: 'Central de Tributos',
    financas: 'Secretaria de Finanças',
    noticias: 'Central de Pesquisas e Notícias',
    transportes: 'Ministério dos Transportes',
  }

  return (
    <div className="fixed inset-0 z-20 flex items-center justify-center bg-ink/70 p-4">
      <div className="painel bg-sand-light max-h-[92vh] w-full max-w-3xl overflow-y-auto p-6">
        <header className="flex items-start justify-between">
          <div>
            <div className="text-rust eyebrow">Capital</div>
            <h2 className="text-ink text-2xl font-black">{sub ? TITULO[sub] : 'Governo de Fertways'}</h2>
            <p className="text-ink-soft mt-1 text-sm">
              {sub ? 'Instituição do governo, operada pela equipe.' : 'As instituições da Capital, em (0, 0).'}
            </p>
          </div>
          <button onClick={aoFechar} className="text-ink-soft hover:text-rust text-2xl leading-none">
            ×
          </button>
        </header>

        {sub ? (
          <>
            <button
              onClick={() => setSub(null)}
              className="text-rust hover:text-rust-bright mt-4 text-sm"
              data-voltar-capital
            >
              ‹ Voltar às instituições
            </button>
            {sub === 'tesouro' && <Tesouro />}
            {sub === 'financas' && <Financas />}
            {sub === 'noticias' && <Noticias />}
            {sub === 'transportes' && <Transportes />}
          </>
        ) : (
          <>
            <ul className="mt-5 space-y-2">
              {slots.map((s) => (
                <SlotCard key={s.n} slot={s} aoAbrirSub={setSub} aoFecharHub={aoFechar} />
              ))}
            </ul>
            <p className="text-ink-soft/60 mt-4 text-xs">
              O slot 9 (Embaixada) e os 10–20 são reservados. O 8 era o Quartel de Alianças no §2.1;
              o Ministério dos Transportes o ocupa por decisão do usuário (D-60).
            </p>
          </>
        )}
      </div>
    </div>
  )
}

function SlotCard({
  slot,
  aoAbrirSub,
  aoFecharHub,
}: {
  slot: Slot
  aoAbrirSub: (s: Sub) => void
  aoFecharHub: () => void
}) {
  const { acao } = slot

  return (
    <li className="border-rust/20 bg-sand flex items-center gap-3 border p-3" data-slot={slot.n}>
      <span className="hex bg-rust text-sand-light flex h-8 w-8 shrink-0 items-center justify-center text-sm font-black">
        {slot.n}
      </span>
      <div className="min-w-0 flex-1">
        <div className="text-ink font-bold">{slot.nome}</div>
        <div className="text-ink-soft/80 truncate text-xs">{slot.funcao}</div>
      </div>
      {acao.tipo === 'sub' && (
        <button
          onClick={() => aoAbrirSub(acao.sub)}
          data-abrir={acao.sub}
          className="bg-rust text-sand-light hover:bg-rust-bright shrink-0 px-4 py-2 text-sm font-bold"
        >
          Abrir
        </button>
      )}
      {acao.tipo === 'externa' && (
        <button
          onClick={() => {
            aoFecharHub()
            acao.abrir()
          }}
          // Os sete botões da Capital dizem todos "Abrir": sem uma chave, nem o e2e nem um leitor
          // de tela distinguem o Mercado Central do Ministério.
          data-abrir={acao.chave}
          aria-label={`Abrir ${slot.nome}`}
          className="border-rust/30 text-ink-soft hover:text-rust shrink-0 border px-4 py-2 text-sm font-bold"
        >
          Abrir
        </button>
      )}
      {acao.tipo === 'equipe' && <span className="text-ink-soft/60 shrink-0 text-xs">operada pela equipe</span>}
      {acao.tipo === 'embreve' && <span className="text-rust/70 shrink-0 text-xs">em breve</span>}
      {acao.tipo === 'travado' && <span className="text-ink-soft/40 shrink-0 text-xs">reservado</span>}
    </li>
  )
}
