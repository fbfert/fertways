import { useEffect, useState } from 'react'

/** As 19 lâminas do pitch (`/home/fertways/pitch/LEIA-ME.txt`), em ordem de apresentação. */
const SLIDES = [
  { arquivo: '01_FERTWAYS_Capa.png', legenda: 'FERTWAYS: The Next Colony' },
  { arquivo: '02_2387_A_Terra_ficou_para_tras.png', legenda: '2387 — A Terra ficou para trás' },
  { arquivo: '03_Nao_e_sobrevivencia_e_civilizacao.png', legenda: 'Não é sobrevivência. É civilização.' },
  { arquivo: '04_Cada_setor_e_uma_decisao.png', legenda: 'Cada setor é uma decisão' },
  { arquivo: '05_Da_sobrevivencia_a_especializacao.png', legenda: 'Da sobrevivência à especialização' },
  { arquivo: '06_Especializacoes_que_tornam_cada_colonia_unica.png', legenda: 'Especializações que tornam cada colônia única' },
  { arquivo: '07_Nada_nasce_pronto.png', legenda: 'Nada nasce pronto — economia baseada em cadeias reais' },
  { arquivo: '08_A_frota_e_a_extensao_da_colonia.png', legenda: 'A frota é a extensão da colônia' },
  { arquivo: '09_Logistica_sem_teleporte.png', legenda: 'Quem controla as rotas controla o futuro' },
  { arquivo: '10_Mercado_Fert_e_integridade_economica.png', legenda: 'Uma economia que deixa rastros' },
  { arquivo: '11_Comercio_confianca_e_federacoes.png', legenda: 'Comércio, confiança e federações' },
  { arquivo: '12_Reputacao_e_vida_social.png', legenda: 'Toda decisão deixa uma marca' },
  { arquivo: '13_Capital_e_governanca.png', legenda: 'Uma civilização precisa de instituições' },
  { arquivo: '14_Zonas_neutras_e_conflito_territorial.png', legenda: 'O território é conquistado. A colônia principal é protegida.' },
  { arquivo: '15_Missoes_eventos_e_terraformacao.png', legenda: 'O planeta muda com os jogadores' },
  { arquivo: '16_Espacoporto_e_mundos_vizinhos.png', legenda: 'Fertways não está sozinho' },
  { arquivo: '17_Competicao_justa_por_design.png', legenda: 'Competição justa por design' },
  { arquivo: '18_Roadmap_de_expansao.png', legenda: 'A próxima colônia começa agora' },
  { arquivo: '19_Encerramento_FERTWAYS.png', legenda: 'Construir, conectar, conquistar e transformar' },
] as const

const INTERVALO_MS = 5000

/** O pôster do pitch virou slideshow (pedido do usuário): todas as 19 lâminas, em ordem, rodando sozinhas. */
export function PitchSlideshow() {
  const [indice, setIndice] = useState(0)
  const [pausado, setPausado] = useState(false)

  useEffect(() => {
    if (pausado) return
    const t = setInterval(() => setIndice((i) => (i + 1) % SLIDES.length), INTERVALO_MS)
    return () => clearInterval(t)
  }, [pausado])

  const atual = SLIDES[indice]

  return (
    <section className="mx-auto max-w-6xl px-6 py-4">
      <div
        className="painel border-rust/15 relative overflow-hidden border"
        onMouseEnter={() => setPausado(true)}
        onMouseLeave={() => setPausado(false)}
        data-slideshow-pitch
      >
        <img
          key={atual.arquivo}
          src={`/pitch/${atual.arquivo}`}
          alt={atual.legenda}
          className="w-full"
          loading={indice === 0 ? 'eager' : 'lazy'}
        />

        <div className="absolute right-3 bottom-3 left-3 flex items-center justify-between gap-3">
          <span className="bg-ink/70 text-sand-light px-3 py-1 text-xs font-bold backdrop-blur">
            {atual.legenda}
          </span>
          <div className="flex gap-1">
            {SLIDES.map((s, i) => (
              <button
                key={s.arquivo}
                type="button"
                aria-label={`Lâmina ${i + 1}: ${s.legenda}`}
                onClick={() => setIndice(i)}
                className={`h-1.5 w-4 ${i === indice ? 'bg-rust' : 'bg-sand-light/50'}`}
              />
            ))}
          </div>
        </div>

        <button
          type="button"
          aria-label="Lâmina anterior"
          onClick={() => setIndice((i) => (i - 1 + SLIDES.length) % SLIDES.length)}
          className="bg-ink/40 text-sand-light hover:bg-ink/70 absolute top-1/2 left-2 -translate-y-1/2 px-2 py-3 text-xl"
        >
          ‹
        </button>
        <button
          type="button"
          aria-label="Próxima lâmina"
          onClick={() => setIndice((i) => (i + 1) % SLIDES.length)}
          className="bg-ink/40 text-sand-light hover:bg-ink/70 absolute top-1/2 right-2 -translate-y-1/2 px-2 py-3 text-xl"
        >
          ›
        </button>
      </div>
    </section>
  )
}
