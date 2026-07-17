import { LandingPageChrome } from './LandingPageChrome'

/**
 * Os cinco planetas NPC do GDD (§03) — os mesmos dados que `Espacoporto.tsx` já mostra ao colono
 * logado, "contando a verdade": as rotas não abriram, porque dependem do Cargueiro
 * Interplanetário, frota do Governo que o D-60 deixou explicitamente fora de escopo.
 */
const PLANETAS = [
  { nome: 'Kalidor', distancia: '~4 h', risco: 'Nenhum', exporta: 'Metais pesados, compostos químicos', importa: 'Biomassa, água' },
  { nome: 'Veyra', distancia: '~6,5 h', risco: 'Nenhum', exporta: 'Sementes alienígenas, compostos orgânicos', importa: 'Energia, componentes' },
  { nome: 'Auryn', distancia: '~12 h', risco: 'Baixo', exporta: 'Variedade enorme', importa: 'Qualquer recurso' },
  { nome: 'Solène', distancia: '~18 h', risco: 'Nenhum', exporta: 'Tecnologia, dados de pesquisa', importa: 'Recursos raros' },
  { nome: 'Drakmoor', distancia: '~31 h', risco: 'Alto — escolta opcional', exporta: 'Minerais raros exclusivos', importa: 'Biomassa, água, componentes' },
]

export function PaginaNpcs() {
  return (
    <LandingPageChrome
      eyebrow="Além do céu de Fertways"
      titulo="Cinco vizinhos, e o cargueiro que ainda não zarpou."
      intro={
        <p>
          Fertways não é o único mundo do sistema. O GDD já nomeia cinco planetas NPC — com
          distância, risco e o que cada um comercia — e o transporte até eles é privilégio do{' '}
          <b>Cargueiro Interplanetário</b>, uma frota alugada por contrato do próprio Governo.
        </p>
      }
    >
      <section className="mx-auto max-w-6xl px-6 pb-10">
        <div className="painel border-rust/30 border border-dashed bg-white/60 p-5">
          <div className="text-rust eyebrow">As rotas ainda não abriram</div>
          <p className="text-ink-soft mt-2 text-sm leading-relaxed">
            O Cargueiro Interplanetário depende do Espaçoporto — e o Espaçoporto depende do
            Cargueiro. É uma dependência circular que o próprio design deixou registrada: alguém
            precisa quebrar o círculo primeiro, e isso ainda não aconteceu. Os números abaixo são{' '}
            <b>o que o GDD publica</b> — verdade sobre o universo, ainda não jogável.
          </p>
        </div>
      </section>

      <section className="mx-auto max-w-6xl px-6 pb-14">
        <h2 className="text-ink text-2xl font-black">Os cinco planetas</h2>
        <div className="mt-6 space-y-3">
          {PLANETAS.map((p) => (
            <div key={p.nome} className="painel border-rust/15 bg-sand-light border p-5">
              <div className="flex flex-wrap items-baseline justify-between gap-3">
                <h3 className="text-ink text-lg font-black">{p.nome}</h3>
                <div className="text-ink-soft text-sm">
                  {p.distancia} ·{' '}
                  <span className={p.risco.startsWith('Alto') ? 'text-rust font-bold' : ''}>
                    risco {p.risco.toLowerCase()}
                  </span>
                </div>
              </div>
              <div className="text-ink-soft mt-2 text-sm">
                <b>Exporta:</b> {p.exporta} · <b>Importa:</b> {p.importa}
              </div>
            </div>
          ))}
        </div>
        <p className="text-ink-soft/70 mt-6 text-xs">
          Novos planetas são descobertos pela administração, em missões de reconhecimento — e a
          notícia chegaria pela Central de Pesquisas e Notícias, quando esse sistema existir.
        </p>
      </section>
    </LandingPageChrome>
  )
}
