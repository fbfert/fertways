import { LandingPageChrome } from './LandingPageChrome'

/**
 * As construções com arte confirmada (o mesmo vínculo "evidente" que o
 * `ImportarImagens::EVIDENTES` do backend usa — a imagem tem de provar a coisa, não o nome
 * chutar). Cada uma aqui é uma construção real do jogo, jogável hoje.
 */
const COLONIA = [
  { img: '/media/colonia-base/reator-helios.png', nome: 'Reator de Energia', texto: 'Energia é a base de tudo — sem ela, nada mais produz.' },
  { img: '/media/colonia-base/estufa-aurora.png', nome: 'Fazenda', texto: 'Biomassa e alimento para sustentar a colônia.' },
  { img: '/media/colonia-base/estacao-nereida.png', nome: 'Captação de Água', texto: 'Água é essencial em toda cadeia de produção.' },
  { img: '/media/colonia-base/habitat-pioneiro.png', nome: 'Estrutura de Sobrevivência', texto: 'O abrigo que faz da colônia um lugar habitável.' },
  { img: '/media/colonia-base/nucleo-ares.png', nome: 'Gerador de Atmosfera', texto: 'Atmosfera respirável, gerada — não encontrada.' },
]

const ESPECIALIZADAS = [
  { img: '/media/especializacoes-da-colonia/forja-titan.png', nome: 'Oficina', texto: 'Onde nascem as Ligas Metálicas — a base de toda fabricação avançada.' },
  { img: '/media/especializacoes-da-colonia/observatorio-kepler.png', nome: 'Antena de Comunicação', texto: 'A colônia fala com o resto do planeta.' },
  { img: '/media/especializacoes-da-colonia/salao-aurum.png', nome: 'Mercado Local', texto: 'Onde o Acordo de Troca entre colonos acontece.' },
  { img: '/media/especializacoes-da-colonia/terminal-mercurio.png', nome: 'Plataforma de Pouso', texto: 'A porta de saída da colônia — hoje, o slot reservado.' },
  { img: '/media/especializacoes-da-colonia/torre-vulcan.png', nome: 'Tanque de Combustível', texto: 'Reserva de Biocombustível para a frota.' },
  { img: '/media/especializacoes-da-colonia/extratora-rubicon.png', nome: 'Mina Local', texto: 'Extração de Metal Bruto direto do subsolo da colônia.' },
]

const CAPITAL = [
  { img: '/media/capital/tesouro-solaris.png', nome: 'Central de Tributos', texto: 'O caixa do Governo — todo tributo do comércio chega até aqui.' },
  { img: '/media/capital/instituto-gagarin.png', nome: 'Central de Pesquisas e Notícias', texto: 'O mural público do planeta.' },
  { img: '/media/capital/cofre-meridian.png', nome: 'Secretaria de Finanças e Tesouro', texto: 'De onde o Governo distribui e o Mercado Central se abastece.' },
  { img: '/media/capital/bastiao-aegis.png', nome: 'Ministério da Segurança e Guerra', texto: 'A força armada do planeta.' },
  { img: '/media/capital/forum-concordia.png', nome: 'Ministério das Reputações', texto: 'Quem julga as disputas comerciais entre colonos.' },
  { img: '/media/capital/terminal-atlas.png', nome: 'Ministério dos Transportes', texto: 'A fábrica única de Caminhões de Carga do planeta.' },
]

function Grade({ itens }: { itens: { img: string; nome: string; texto: string }[] }) {
  return (
    <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
      {itens.map((c) => (
        <div key={c.nome} className="painel border-rust/15 bg-sand-light border p-6 text-center">
          <img src={c.img} alt={c.nome} className="mx-auto h-40 w-40 object-contain" loading="lazy" />
          <h3 className="text-ink mt-3 text-lg font-black">{c.nome}</h3>
          <p className="text-ink-soft mt-1 text-sm">{c.texto}</p>
        </div>
      ))}
    </div>
  )
}

export function PaginaConstrucoes() {
  return (
    <LandingPageChrome
      eyebrow="O que você constrói"
      titulo="Cada construção tem uma função — nenhuma é decoração."
      intro={
        <p>
          O jogo tem construções essenciais, especializadas e institucionais. As essenciais vêm no
          kit inicial; as demais, você ergue conforme sua colônia cresce.
        </p>
      }
    >
      <section className="mx-auto max-w-6xl px-6 pb-10">
        <h2 className="text-ink text-2xl font-black">As cinco essenciais</h2>
        <p className="text-ink-soft mt-1 mb-6 max-w-xl text-sm">
          O kit inicial já entrega as cinco de pé — energia, água, comida, atmosfera e abrigo.
        </p>
        <Grade itens={COLONIA} />
      </section>

      <section className="bg-sand-light py-10">
        <div className="mx-auto max-w-6xl px-6">
          <h2 className="text-ink text-2xl font-black">Especializações da colônia</h2>
          <p className="text-ink-soft mt-1 mb-6 max-w-xl text-sm">
            Construções de progressão — cada uma abre uma cadeia de produção ou um canal de
            comércio novo.
          </p>
          <Grade itens={ESPECIALIZADAS} />
        </div>
      </section>

      <section className="mx-auto max-w-6xl px-6 py-10">
        <h2 className="text-ink text-2xl font-black">As instituições da Capital</h2>
        <p className="text-ink-soft mt-1 mb-6 max-w-xl text-sm">
          A Capital é do Governo, não de um colono — e é de lá que o planeta é administrado.
        </p>
        <Grade itens={CAPITAL} />
      </section>
    </LandingPageChrome>
  )
}
