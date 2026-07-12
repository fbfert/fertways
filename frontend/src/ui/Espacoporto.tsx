/**
 * O Espaçoporto — a área Sul da Capital (D-63).
 *
 * **Conta a verdade**, como a Endurance e como o Gagarin (D-55): mostra o que o GDD publica sobre os
 * cinco planetas NPC — distância, risco, o que exportam e o que importam — e **diz que as rotas não
 * abriram**.
 *
 * Os cinco planetas e os números são **do GDD (§03)**, transcritos. As rotas dependem do Cargueiro
 * Interplanetário, que é frota do governo alugada por contrato (§16.2) e que o D-60 deixou
 * explicitamente **fora de escopo**: ele depende deste Espaçoporto, que depende dele. Alguém tem de
 * quebrar o círculo primeiro, e não foi agora.
 */
const PLANETAS = [
  {
    nome: 'Kalidor',
    distancia: '~4 h',
    risco: 'Nenhum',
    exporta: 'Metais pesados, compostos químicos',
    importa: 'Biomassa, água',
  },
  {
    nome: 'Veyra',
    distancia: '~6,5 h',
    risco: 'Nenhum',
    exporta: 'Sementes alienígenas, compostos orgânicos',
    importa: 'Energia, componentes',
  },
  {
    nome: 'Auryn',
    distancia: '~12 h',
    risco: 'Baixo',
    exporta: 'Variedade enorme',
    importa: 'Qualquer recurso',
  },
  {
    nome: 'Solène',
    distancia: '~18 h',
    risco: 'Nenhum',
    exporta: 'Tecnologia, dados de pesquisa',
    importa: 'Recursos raros',
  },
  {
    nome: 'Drakmoor',
    distancia: '~31 h',
    risco: 'Alto — escolta opcional',
    exporta: 'Minerais raros exclusivos',
    importa: 'Biomassa, água, componentes',
  },
]

export function Espacoporto() {
  return (
    <div className="mt-5 space-y-5" data-tela="espacoporto">
      <section className="border-rust/40 bg-sand-light border border-dashed p-4">
        <div className="text-rust eyebrow">As rotas não abriram</div>
        <p className="text-ink-soft mt-1 text-sm leading-relaxed">
          <b>Ninguém viaja daqui ainda.</b> Viajar exige o <b>Cargueiro Interplanetário</b> — frota do
          governo, alugada por contrato, que o GDD é explícito em não deixar ninguém fabricar. Ele
          depende deste Espaçoporto, e este Espaçoporto depende dele: alguém tem de quebrar o círculo
          primeiro, e não foi agora.
        </p>
        <p className="text-ink-soft/80 mt-2 text-xs">
          O que está abaixo é <b>o que o GDD publica</b> (§03), e é verdade sobre o mundo — só não é
          jogável.
        </p>
      </section>

      <section>
        <h3 className="text-ink font-black">Os cinco planetas NPC</h3>

        <ul className="mt-3 space-y-2">
          {PLANETAS.map((p) => (
            <li key={p.nome} className="border-rust/20 bg-sand border p-3" data-planeta={p.nome}>
              <div className="flex items-baseline justify-between gap-3">
                <div className="text-ink font-black">{p.nome}</div>
                <div className="text-ink-soft shrink-0 text-sm">
                  {p.distancia} ·{' '}
                  <span className={p.risco.startsWith('Alto') ? 'text-rust font-bold' : ''}>
                    risco {p.risco.toLowerCase()}
                  </span>
                </div>
              </div>
              <div className="text-ink-soft/80 mt-1 text-xs">
                <b>Exporta:</b> {p.exporta} · <b>Importa:</b> {p.importa}
              </div>
            </li>
          ))}
        </ul>
      </section>

      <p className="text-ink-soft/60 text-xs">
        Novos planetas são descobertos pela administração, em missões de reconhecimento — e a notícia
        aparece na Central de Pesquisas e Notícias. Também não construído.
      </p>
    </div>
  )
}
