import { LandingPageChrome } from './LandingPageChrome'

/**
 * As oito luas — homenagens e o recurso raro que cada uma nomeia (GDD §12, "Gagarin, luas e
 * Temporada 2"). Os oito recursos já existem no catálogo real do jogo hoje; as luas em si —
 * lugares para minerar — são Temporada 2, sem GDD complementar aprovado ainda.
 */
const LUAS = [
  { nome: 'Armstrong', homenagem: 'Neil Armstrong', recurso: 'niobio_alienigena', recursoNome: 'Nióbio Alienígena', leitura: 'Colonização e estruturas avançadas.' },
  { nome: 'Tereshkova', homenagem: 'Valentina Tereshkova', recurso: 'cristal_de_helio_3', recursoNome: 'Cristal de Hélio-3', leitura: 'Energia e mineração hermética.' },
  { nome: 'Sagan', homenagem: 'Carl Sagan', recurso: 'quartzo_piezoeletrico', recursoNome: 'Quartzo Piezoelétrico', leitura: 'Anomalias de superfície e pesquisa.' },
  { nome: 'Aldrin', homenagem: 'Buzz Aldrin', recurso: 'ferro_vermelho', recursoNome: 'Ferro Vermelho', leitura: 'Construção pesada.' },
  { nome: 'Ride', homenagem: 'Sally Ride', recurso: 'resina_organica', recursoNome: 'Resina Orgânica', leitura: 'Biosfera e habitação.' },
  { nome: 'Leonov', homenagem: 'Alexei Leonov', recurso: 'gelo_de_metano', recursoNome: 'Gelo de Metano', leitura: 'Propulsão e logística.' },
  { nome: 'Hawking', homenagem: 'Stephen Hawking', recurso: 'plasma_fossilizado', recursoNome: 'Plasma Fossilizado', leitura: 'Pesquisa de alto risco.' },
  { nome: 'Laika', homenagem: 'Laika', recurso: 'fungo_bioluminescente', recursoNome: 'Fungo Bioluminescente', leitura: 'Anomalia de origem desconhecida — mistério narrativo.' },
]

export function PaginaLuas() {
  return (
    <LandingPageChrome
      eyebrow="O que ainda vem por aí"
      titulo="Oito luas, oito nomes que abriram caminho para as estrelas."
      intro={
        <p>
          Cada lua do sistema de Fertways homenageia alguém que fez história na exploração
          espacial de verdade — e cada uma empresta o nome a um recurso raro que já existe na
          economia do jogo hoje, mesmo que a lua em si ainda não seja um lugar visitável.
        </p>
      }
    >
      <section className="mx-auto max-w-6xl px-6 pb-10">
        <div className="painel border-rust/30 border border-dashed bg-white/60 p-5">
          <div className="text-rust eyebrow">Temporada 2 — ainda não construída</div>
          <p className="text-ink-soft mt-2 text-sm leading-relaxed">
            O telescópio orbital <b>Gagarin</b> observa as oito luas e publica boletins — mas eles{' '}
            <b>não liberam mineração lunar</b>. A campanha "Janela de Órbita Lunar" só começa
            quando três indicadores de terraformação do planeta chegarem a 75%, e bases lunares só
            entram em jogo com um documento de design complementar aprovado, com regras próprias
            de custo, propriedade, defesa e combate. Até lá, as luas são fundamento narrativo —
            verdade sobre o universo, ainda não jogáveis.
          </p>
        </div>
      </section>

      <section className="mx-auto max-w-6xl px-6 pb-14">
        <h2 className="text-ink text-2xl font-black">As oito</h2>
        <p className="text-ink-soft mt-1 mb-6 max-w-xl text-sm">
          O recurso de cada lua já circula na economia real do jogo — só o lugar de origem que
          ainda não abriu.
        </p>
        <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
          {LUAS.map((l) => (
            <div key={l.nome} className="painel border-rust/15 bg-sand-light border p-5">
              <div className="hex bg-rust text-sand-light flex h-10 w-10 items-center justify-center text-xs font-black">
                {l.nome.slice(0, 2).toUpperCase()}
              </div>
              <h3 className="text-ink mt-3 font-black">Lua {l.nome}</h3>
              <p className="text-ink-soft/70 text-xs">em homenagem a {l.homenagem}</p>
              <p className="text-rust mt-2 text-sm font-bold">{l.recursoNome}</p>
              <p className="text-ink-soft mt-1 text-xs">{l.leitura}</p>
            </div>
          ))}
        </div>
      </section>
    </LandingPageChrome>
  )
}
