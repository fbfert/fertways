import { LandingPageChrome } from './LandingPageChrome'

const FROTA_REAL = [
  {
    img: '/media/logistica-e-frota/furgao-orion.png',
    nome: 'Furgão de Comércio',
    texto: '6.000 unidades de capacidade. Vem no kit inicial — o primeiro veículo de toda colônia.',
  },
  {
    img: '/media/logistica-e-frota/caminhao-colosso.png',
    nome: 'Caminhão de Carga',
    texto: '30.000 unidades. Fabricado só pelo Ministério dos Transportes — fila real, prateleira real.',
  },
  {
    img: '/media/logistica-e-frota/drone-horizonte.png',
    nome: 'Drone de Exploração',
    texto: 'Não carrega nada — voa para revelar o que existe além da névoa de uma zona vizinha.',
  },
  {
    img: '/media/logistica-e-frota/minerador-boreal.png',
    nome: 'Robô Minerador',
    texto: 'Extrai o mineral de uma zona neutra ocupada. Sem ele, a ocupação não rende recurso.',
  },
  {
    img: '/media/logistica-e-frota/sentinela-cygnus.png',
    nome: 'Sentinela',
    texto: 'A unidade de combate territorial — força ofensiva e defensiva em zonas neutras.',
  },
]

const FROTA_FUTURA = [
  {
    img: '/media/logistica-e-frota/nave-peregrina.png',
    nome: 'Nave de Transporte Planetária',
    texto: 'Transporte rápido entre pontos estratégicos do planeta. Ainda fora do MVP.',
  },
  {
    img: '/media/logistica-e-frota/cargueiro-zenith.png',
    nome: 'Cargueiro Interplanetário',
    texto: 'Logística de longo alcance entre mundos — depende do Espaçoporto, que ainda não abriu.',
  },
]

export function PaginaVeiculos() {
  return (
    <LandingPageChrome
      eyebrow="A frota"
      titulo="Nada se move sozinho. Tudo viaja de verdade."
      intro={
        <p>
          Não existe teleporte em Fertways. Toda carga sai fisicamente de um lugar, gasta tempo e
          energia na estrada, e chega — ou volta, se o destino não coube. A frota é a extensão da
          sua colônia.
        </p>
      }
    >
      <section className="mx-auto max-w-6xl px-6 pb-10">
        <h2 className="text-ink text-2xl font-black">A frota de hoje</h2>
        <p className="text-ink-soft mt-1 mb-6 max-w-xl text-sm">
          Cinco veículos jogáveis agora — cada um com uma função que nenhum outro cobre.
        </p>
        <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
          {FROTA_REAL.map((v) => (
            <div key={v.nome} className="painel border-rust/15 bg-sand-light border p-6 text-center">
              <img src={v.img} alt={v.nome} className="mx-auto h-40 w-40 object-contain" loading="lazy" />
              <h3 className="text-ink mt-3 text-lg font-black">{v.nome}</h3>
              <p className="text-ink-soft mt-1 text-sm">{v.texto}</p>
            </div>
          ))}
        </div>
      </section>

      <section className="bg-sand-light py-10">
        <div className="mx-auto max-w-6xl px-6">
          <h2 className="text-ink text-2xl font-black">No horizonte</h2>
          <p className="text-ink-soft mt-1 mb-6 max-w-xl text-sm">
            Dois veículos que o universo do jogo já nomeia, mas que dependem de sistemas que ainda
            não abriram — sem promessa de data, sem número inventado.
          </p>
          <div className="grid gap-6 sm:grid-cols-2">
            {FROTA_FUTURA.map((v) => (
              <div key={v.nome} className="painel border-rust/15 border bg-white/40 p-6 text-center opacity-80">
                <img src={v.img} alt={v.nome} className="mx-auto h-40 w-40 object-contain grayscale" loading="lazy" />
                <h3 className="text-ink mt-3 text-lg font-black">{v.nome}</h3>
                <p className="text-ink-soft mt-1 text-sm">{v.texto}</p>
                <span className="text-rust eyebrow mt-3 inline-block">Em breve</span>
              </div>
            ))}
          </div>
        </div>
      </section>
    </LandingPageChrome>
  )
}
