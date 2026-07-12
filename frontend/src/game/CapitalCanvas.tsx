import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import Phaser from 'phaser'
import { CapitalScene, plantaDaCapital, SLOTS_DO_NORTE, type AreaId, type SlotDaCapital } from './CapitalScene'
import { ControlesDeZoom } from './ControlesDeZoom'
import { useVista } from './vista'

type Props = {
  slots: SlotDaCapital[]
  aoClicarSlot: (slot: SlotDaCapital) => void
  aoClicarArea: (area: AreaId) => void
}

/**
 * Ponte React → Phaser da Capital (D-63), e a camada de alvos de clique.
 *
 * Mesma arquitetura da colônia, e pela mesma razão: **a cena pinta, o DOM recebe o clique**. Um
 * canvas não tem foco, não responde a Tab, não tem `aria-label`, e o Chromium do e2e não consegue
 * clicar num alvo que só existe em pixels. Como a Capital é a porta do Mercado, do Tesouro, do
 * Ministério e de tudo o mais, um canvas mudo tiraria metade do jogo do alcance de quem usa teclado
 * — e do alcance do e2e.
 *
 * A geometria dos botões e a do desenho saem da **mesma** função (`plantaDaCapital`), com a **mesma**
 * vista. Não há duas contas, então não há como divergirem quando o jogador aproxima.
 *
 * **A praça não tem botão.** Ela é decorativa por decisão do usuário, e um alvo de clique que não
 * faz nada é pior do que nenhum.
 */
export function CapitalCanvas({ slots, aoClicarSlot, aoClicarArea }: Props) {
  const container = useRef<HTMLDivElement>(null)
  const jogo = useRef<Phaser.Game | null>(null)
  const cena = useRef<CapitalScene | null>(null)
  const [tamanho, setTamanho] = useState({ largura: 0, altura: 0 })

  const { vista, alvo, ampliar, centralizar, arrastando, gestos } = useVista()

  useLayoutEffect(() => {
    const el = container.current
    if (!el) return

    const medir = () => setTamanho({ largura: el.clientWidth, altura: el.clientHeight })

    medir()
    const observador = new ResizeObserver(medir)
    observador.observe(el)

    return () => observador.disconnect()
  }, [])

  /*
   * **O Phaser só nasce depois de o contêiner ter tamanho**, e é redimensionado junto com ele.
   *
   * A cena da colônia não precisa disso porque o `div` dela já tem altura quando o jogo é criado. O
   * da Capital vive **dentro de um modal**, e no primeiro render ele mede zero: o Phaser nascia com
   * um canvas do tamanho da janela, desenhava as áreas fora do enquadramento, e os hexágonos
   * apareciam a centenas de pixels dos botões que deviam cobri-los. O e2e pegou.
   *
   * A invariante que isto garante é a mesma que a função de geometria garante: **a cena e os botões
   * medem a mesma caixa.** Sem ela, o zoom estaria certo e o alinhamento errado.
   */
  // A dependência é só "já tem tamanho", e não o tamanho: dependendo dele, cada redimensionamento
  // destruiria e recriaria o jogo inteiro — a tela piscaria a cada arraste de janela.
  const medido = tamanho.largura > 0

  useEffect(() => {
    if (!container.current || jogo.current || !medido) return

    const c = new CapitalScene()
    jogo.current = new Phaser.Game({
      type: Phaser.AUTO,
      parent: container.current,
      backgroundColor: '#f8e7d6',
      width: container.current.clientWidth,
      height: container.current.clientHeight,
      scale: { mode: Phaser.Scale.RESIZE },
      scene: c,
    })
    cena.current = c

    return () => {
      jogo.current?.destroy(true)
      jogo.current = null
      cena.current = null
    }
  }, [medido])

  useEffect(() => {
    if (jogo.current && tamanho.largura) {
      jogo.current.scale.resize(tamanho.largura, tamanho.altura)
    }
  }, [tamanho])

  /*
   * `medido` está nas dependências, e não é enfeite.
   *
   * No primeiro render o contêiner mede zero, então o jogo **ainda não existe** e este efeito roda
   * com `cena.current` nulo — não faz nada. Quando a medida chega, o jogo é criado; mas `slots` e
   * `vista` não mudaram, então sem `medido` aqui **este efeito nunca mais rodaria**, e a cena ficaria
   * para sempre com a lista vazia que recebeu no `create()`.
   *
   * O sintoma era mudo e feio: **os ministérios apareciam pálidos, iguais aos slots vagos** — todos
   * desenhados como "vago", porque a cena nunca soube que existiam. Nenhum teste pegava: os cliques
   * funcionavam (os botões são de DOM e leem a lista certa), só o desenho mentia. Foi preciso olhar.
   */
  useEffect(() => {
    cena.current?.atualizar(slots, vista)
  }, [slots, vista, medido])

  const planta = tamanho.largura
    ? plantaDaCapital(tamanho.largura, tamanho.altura, vista)
    : null

  const porSlot = new Map(slots.map((s) => [s.n, s]))

  return (
    <div
      ref={(el) => {
        container.current = el
        alvo.current = el
      }}
      {...gestos}
      className="relative h-full w-full"
      style={{ cursor: arrastando ? 'grabbing' : 'grab', touchAction: 'none' }}
    >
      <ControlesDeZoom vista={vista} ampliar={ampliar} centralizar={centralizar} />

      {planta && (
        <>
          {/* As três áreas clicáveis. O Norte não clica (os slots dele clicam); a praça, também não. */}
          {planta.areas
            .filter((a) => a.id === 'oeste' || a.id === 'leste' || a.id === 'sul')
            .map((a) => (
              <button
                key={a.id}
                onClick={() => aoClicarArea(a.id)}
                onMouseEnter={() => cena.current?.realcar(`area:${a.id}`)}
                onMouseLeave={() => cena.current?.realcar(null)}
                onFocus={() => cena.current?.realcar(`area:${a.id}`)}
                onBlur={() => cena.current?.realcar(null)}
                aria-label={a.nome}
                title={a.nome}
                data-area={a.id}
                style={{
                  position: 'absolute',
                  left: a.x - a.w / 2,
                  top: a.y - a.h / 2,
                  width: a.w,
                  height: a.h,
                  background: 'transparent',
                  border: 'none',
                  cursor: 'pointer',
                  padding: 0,
                }}
                className="focus-visible:ring-rust focus-visible:ring-2 focus-visible:outline-none"
              />
            ))}

          {/* Os 19 hexágonos do Governo Central. O 6 não está aqui: ele é o Leste. */}
          {SLOTS_DO_NORTE.map((n, i) => {
            const [x, y] = planta.hexCentros[i]
            const slot = porSlot.get(n)
            const r = planta.hexR

            if (!slot) return null

            const rotulo =
              slot.estado === 'vago'
                ? `Slot ${n} — vago`
                : slot.estado === 'reservado'
                  ? `Slot ${n}: ${slot.nome} — reservado, fora do MVP`
                  : slot.estado === 'em_breve'
                    ? `Slot ${n}: ${slot.nome} — em breve`
                    : `Slot ${n}: ${slot.nome}`

            const inerte = slot.estado === 'vago' || slot.estado === 'reservado'

            return (
              <button
                key={n}
                onClick={() => !inerte && aoClicarSlot(slot)}
                disabled={inerte}
                onMouseEnter={() => !inerte && cena.current?.realcar(`slot:${n}`)}
                onMouseLeave={() => cena.current?.realcar(null)}
                onFocus={() => !inerte && cena.current?.realcar(`slot:${n}`)}
                onBlur={() => cena.current?.realcar(null)}
                aria-label={rotulo}
                title={rotulo}
                data-slot-capital={n}
                style={{
                  position: 'absolute',
                  left: x - r * 0.75,
                  top: y - r * 0.75,
                  width: r * 1.5,
                  height: r * 1.5,
                  background: 'transparent',
                  border: 'none',
                  borderRadius: '50%',
                  cursor: inerte ? 'default' : 'pointer',
                  padding: 0,
                }}
                className="focus-visible:ring-rust focus-visible:ring-2 focus-visible:outline-none"
              />
            )
          })}
        </>
      )}
    </div>
  )
}
