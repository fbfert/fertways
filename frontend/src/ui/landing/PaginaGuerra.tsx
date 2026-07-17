import { LandingPageChrome } from './LandingPageChrome'

const ATOS = [
  {
    titulo: 'Ocupação',
    texto:
      'Toda conquista começa aqui. Uma zona neutra livre é reivindicada com um Posto de Comando e Robôs Mineradores — e só então começa a render o mineral do distrito.',
  },
  {
    titulo: 'Sabotagem',
    texto: 'Interrompe a produção do ocupante, sem tomar o território — enfraquece antes de um cerco de verdade.',
  },
  {
    titulo: 'Invasão',
    texto: 'O ataque direto. Quem vence leva 50% do estoque que a zona não tinha protegido — nunca mais que isso.',
  },
  {
    titulo: 'Cerco',
    texto: 'Isola a zona e pressiona. Um cerco vencido entrega 30% do estoque em 48 horas — mais lento que a invasão, mais difícil de resistir.',
  },
]

const REGRAS = [
  {
    titulo: 'A colônia principal é inviolável',
    texto:
      'Sempre. O slot onde você fundou nunca é alvo — nem no primeiro dia, nem no centésimo. O conflito de Fertways é sobre território disputável, não sobre destruir quem já está em pé.',
  },
  {
    titulo: 'Oito dias de trégua, sempre',
    texto:
      'Toda colônia nova nasce protegida. Ninguém aprende as regras do jogo sob ataque.',
  },
  {
    titulo: 'A janela de vulnerabilidade é sua escolha',
    texto:
      'Cada zona ocupada tem 4 horas por dia em que pode ser atacada — e mudar esse horário só vale 48 horas depois de decidido. Defender é antecipar, não reagir.',
  },
  {
    titulo: 'O mesmo inimigo não insiste duas vezes seguidas',
    texto: 'Cooldown de 48 horas para o mesmo atacante voltar à mesma zona. Cerco não é assédio.',
  },
]

const UNIDADES = [
  {
    img: '/media/logistica-e-frota/sentinela-cygnus.png',
    nome: 'Sentinela',
    texto: 'A linha de frente. Força ofensiva e defensiva, fabricada a partir de Nióbio Alienígena — comprado do próprio Governo.',
  },
  {
    img: '/media/logistica-e-frota/minerador-boreal.png',
    nome: 'Robô Minerador',
    texto: 'Sem ele, uma zona ocupada é só uma bandeira. É quem de fato extrai o mineral que a conquista promete.',
  },
  {
    img: '/media/logistica-e-frota/drone-horizonte.png',
    nome: 'Drone de Exploração',
    texto: 'Antes de atacar, é preciso ver. O Drone atravessa a névoa de uma zona vizinha e revela o que está lá.',
  },
]

export function PaginaGuerra() {
  return (
    <LandingPageChrome
      eyebrow="Território e conflito"
      titulo="O território é conquistado. A colônia é protegida."
      intro={
        <p>
          Fertways não é um planeta pacífico — mas o conflito tem endereço certo. As zonas neutras
          fora de qualquer colônia é onde a força decide quem controla o quê. Dentro do seu slot
          principal, ninguém decide nada por você.
        </p>
      }
    >
      <section className="mx-auto max-w-6xl px-6 pb-10">
        <div className="painel border-rust/15 bg-sand-light flex justify-center border py-8">
          <img
            src="/media/zonas-neutras-e-conflito/centro-cerco-kraken.png"
            alt="Um posto de comando sob cerco, numa zona neutra"
            className="h-64 w-64 object-contain"
            loading="lazy"
          />
        </div>
      </section>

      <section className="mx-auto max-w-6xl px-6 pb-10">
        <h2 className="text-ink text-2xl font-black">Os quatro atos da guerra</h2>
        <p className="text-ink-soft mt-1 mb-6 max-w-xl text-sm">
          Cada um serve a um propósito diferente — nenhum é uma versão mais forte do outro.
        </p>
        <div className="grid gap-6 sm:grid-cols-2">
          {ATOS.map((a) => (
            <div key={a.titulo} className="painel border-rust/15 bg-sand-light border p-6">
              <h3 className="text-ink text-lg font-black">{a.titulo}</h3>
              <p className="text-ink-soft mt-2 text-sm">{a.texto}</p>
            </div>
          ))}
        </div>
      </section>

      <section className="bg-sand-light py-10">
        <div className="mx-auto max-w-6xl px-6">
          <h2 className="text-ink text-2xl font-black">Quem luta</h2>
          <p className="text-ink-soft mt-1 mb-6 max-w-xl text-sm">
            A guerra não recruta no Quartel — as unidades nascem do comércio com o próprio Governo.
          </p>
          <div className="grid gap-6 sm:grid-cols-3">
            {UNIDADES.map((u) => (
              <div key={u.nome} className="painel border-rust/15 border bg-white p-6 text-center">
                <img src={u.img} alt={u.nome} className="mx-auto h-32 w-32 object-contain" loading="lazy" />
                <h3 className="text-ink mt-3 text-lg font-black">{u.nome}</h3>
                <p className="text-ink-soft mt-1 text-sm">{u.texto}</p>
              </div>
            ))}
          </div>
        </div>
      </section>

      <section className="mx-auto max-w-6xl px-6 py-10">
        <h2 className="text-ink text-2xl font-black">As regras que protegem o jogo</h2>
        <p className="text-ink-soft mt-1 mb-6 max-w-xl text-sm">
          Conflito real, sem virar um jogo só para quem tem mais tempo livre.
        </p>
        <div className="grid gap-6 sm:grid-cols-2">
          {REGRAS.map((r) => (
            <div key={r.titulo} className="border-rust/15 border-l-4 pl-4">
              <h3 className="text-ink font-black">{r.titulo}</h3>
              <p className="text-ink-soft mt-1 text-sm">{r.texto}</p>
            </div>
          ))}
        </div>
      </section>
    </LandingPageChrome>
  )
}
