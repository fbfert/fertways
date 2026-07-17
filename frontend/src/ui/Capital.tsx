import { useState } from 'react'
import { CapitalCanvas } from '../game/CapitalCanvas'
import type { AreaId, SlotDaCapital } from '../game/CapitalScene'
import { Endurance } from './Endurance'
import { Espacoporto } from './Espacoporto'
import { Financas } from './Financas'
import { Noticias } from './Noticias'
import { Tesouro } from './Tesouro'
import { Transportes } from './Transportes'

/**
 * A Capital (§02) — **um lugar, não um menu** (D-63).
 *
 * Era um diretório de sete linhas. Agora é uma cena, como a colônia: quatro áreas em volta de uma
 * praça, e o Governo Central ao norte com os slots institucionais do §2.1.
 *
 * **A planta não está no GDD.** O documento trata a Capital como uma lista plana de 20 slots, sem
 * geografia nenhuma — sem praça, sem bairros, sem norte nem sul. As quatro áreas são arbitragem do
 * usuário, e vale um leitor futuro saber disso antes de procurar no documento uma planta que não
 * existe.
 *
 * **O Leste é o slot 6 inteiro.** No GDD o slot 6 *é* o Estacionamento de Caminhões, que a versão
 * sanitizada rebatiza de Pátio Logístico Público — e é dentro dele que o Mercado Central mora desde
 * o D-55. Mercado e Pátio são a mesma área, e os caminhões desenhados ali são **desenho**, não uma
 * segunda porta.
 */
type Sub = 'tesouro' | 'financas' | 'noticias' | 'transportes' | 'endurance' | 'espacoporto'

/**
 * Os slots do Governo Central. **O 6 não está aqui**: ele é a área do Leste.
 *
 * Os vagos (9–20) aparecem, apagados e travados. É o que faz a Capital parecer um lugar que vai
 * crescer, e não uma lista — e o GDD publica os 20.
 */
const SLOTS: SlotDaCapital[] = [
  { n: 1, nome: 'Administração Pública', abre: null, estado: 'em_breve' },
  { n: 2, nome: 'Central de Tributos', abre: 'tesouro', estado: 'ativo' },
  { n: 3, nome: 'Central de Pesquisas e Notícias', abre: 'noticias', estado: 'ativo' },
  { n: 4, nome: 'Secretaria de Finanças e Tesouro', abre: 'financas', estado: 'ativo' },
  { n: 5, nome: 'Ministério da Segurança e Guerra', abre: null, estado: 'em_breve' },
  { n: 7, nome: 'Ministério das Reputações', abre: 'ministerio', estado: 'ativo' },
  { n: 8, nome: 'Ministério dos Transportes', abre: 'transportes', estado: 'ativo' },
  { n: 9, nome: 'Quartel de Alianças', abre: null, estado: 'reservado' },
  ...Array.from({ length: 11 }, (_, i) => ({
    n: 10 + i,
    nome: 'Vago',
    abre: null,
    estado: 'vago' as const,
  })),
]

const TITULO: Record<Sub, string> = {
  tesouro: 'Central de Tributos',
  financas: 'Secretaria de Finanças',
  noticias: 'Central de Pesquisas e Notícias',
  transportes: 'Ministério dos Transportes',
  endurance: 'Endurance of Mankind',
  espacoporto: 'Espaçoporto',
}

export function Capital({
  aoAbrirMercado,
  aoAbrirMinisterio,
}: {
  aoAbrirMercado: () => void
  aoAbrirMinisterio: () => void
}) {
  const [sub, setSub] = useState<Sub | null>(null)

  function clicarSlot(slot: SlotDaCapital) {
    // O Ministério das Reputações reusa a tela de topo do HUD, como antes do D-63.
    if (slot.abre === 'ministerio') {
      aoAbrirMinisterio()

      return
    }

    if (slot.abre) setSub(slot.abre as Sub)
  }

  function clicarArea(area: AreaId) {
    // O Leste é o slot 6: Mercado Central + Pátio Logístico. Clicar nele abre o Mercado.
    if (area === 'leste') {
      aoAbrirMercado()

      return
    }

    if (area === 'oeste') setSub('endurance')
    if (area === 'sul') setSub('espacoporto')
  }

  return (
    <div className="bg-sand fixed inset-0 z-20 overflow-y-auto">
      <div className="bg-sand-light mx-auto flex min-h-screen w-full max-w-4xl flex-col px-6 pt-20 pb-24 md:pt-28 md:pb-6">
        <header className="shrink-0">
          <div className="text-rust eyebrow">Capital</div>
          <h2 className="text-ink text-2xl font-black">
            {sub ? TITULO[sub] : 'Governo de Fertways'}
          </h2>
          <p className="text-ink-soft mt-1 text-sm">
            {sub
              ? 'Instituição do governo.'
              : 'Clique numa área ou num slot. Use a roda do mouse para aproximar.'}
          </p>
        </header>

        {sub ? (
          <div className="mt-2 flex-1 overflow-y-auto">
            <button
              onClick={() => setSub(null)}
              className="text-rust hover:text-rust-bright text-sm"
              data-voltar-capital
            >
              ‹ Voltar à Capital
            </button>
            {sub === 'tesouro' && <Tesouro />}
            {sub === 'financas' && <Financas />}
            {sub === 'noticias' && <Noticias />}
            {sub === 'transportes' && <Transportes />}
            {sub === 'endurance' && <Endurance />}
            {sub === 'espacoporto' && <Espacoporto />}
          </div>
        ) : (
          <>
            {/*
              **Altura definida, e sem `flex-1`.**

              Com `flex-1` o item ganha `flex-basis: 0`, que sobrepõe a altura no eixo principal de
              um flex-col — o `div` colapsava a zero, o Phaser nascia com um canvas de altura nula e
              os hexágonos vinham com raio 0. Os botões existiam, mediam 0 px e não recebiam clique.
              O e2e pegou: "Node is either not clickable".
            */}
            <div
              className="border-rust/20 mt-4 h-[60vh] min-h-[360px] shrink-0 border"
              data-cena-capital
            >
              <CapitalCanvas slots={SLOTS} aoClicarSlot={clicarSlot} aoClicarArea={clicarArea} />
            </div>

            <p className="text-ink-soft/60 mt-3 shrink-0 text-xs">
              O <b>slot 6</b> não aparece no Governo Central porque ele <b>é</b> o Leste: o Mercado
              Central e o Pátio Logístico são a mesma área (§2.1). Os slots 10–20 estão vagos, e o 9
              (Embaixada) é reservado — fora do MVP.
            </p>
          </>
        )}
      </div>
    </div>
  )
}
