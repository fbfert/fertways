import { useEffect, useState } from 'react'
import { api, ApiError } from '../api/client'
import type { JogadorInfo } from '../api/client'
import { Popup } from './Popup'
import { nomeRecurso } from './recursos'

/**
 * O card "quem é esse colono" (docs/decisoes.md D-81) — aberto do Chat privado e do diretório de
 * colônias no Mapa.
 *
 * **Mesma régua de privacidade do diretório** (D-37): nome, posição, distância, porte e as zonas
 * que ele ocupa — tudo já público hoje em algum canto do jogo. Recursos, saldo, frota e reputação
 * NUNCA aparecem aqui, porque nunca aparecem a terceiro em lugar nenhum do jogo.
 *
 * **Popup, não tela** (D-69): faz sentido olhar de relance, sem sair de onde se estava — do Chat
 * ou do Mapa.
 */
export function InfoJogador({
  userId,
  aoFechar,
  aoConversar,
}: {
  userId: number
  aoFechar: () => void
  /** Abre o chat privado com este jogador — omitido, o botão "Conversar" nem aparece. */
  aoConversar?: (id: number, nickname: string) => void
}) {
  const [info, setInfo] = useState<JogadorInfo | null>(null)
  const [erro, setErro] = useState<string | null>(null)

  useEffect(() => {
    let vivo = true
    setInfo(null)
    setErro(null)
    api
      .jogador(userId)
      .then((d) => vivo && setInfo(d))
      .catch((e) => vivo && setErro(e instanceof ApiError ? e.message : 'Falha ao carregar.'))
    return () => {
      vivo = false
    }
  }, [userId])

  return (
    <Popup titulo={info?.nickname ?? 'Carregando…'} eyebrow="Colono" aoFechar={aoFechar}>
      {erro && <p className="text-rust text-sm">{erro}</p>}

      {info && aoConversar && (
        <button
          onClick={() => aoConversar(userId, info.nickname)}
          data-conversar={userId}
          className="botao mb-3 w-full"
        >
          Conversar
        </button>
      )}

      {info && (
        <div className="space-y-3 text-sm" data-info-jogador>
          {info.colony ? (
            <>
              <div>
                <div className="text-ink font-black">{info.colony.name}</div>
                <div className="text-ink-soft text-xs">
                  ({info.colony.x}, {info.colony.y})
                  {info.colony.distance !== null && ` · ${info.colony.distance} slots`} · porte{' '}
                  {info.colony.building_levels_sum}
                </div>
              </div>

              <div>
                <div className="text-ink eyebrow">Zonas neutras ocupadas ({info.zones.length})</div>
                {info.zones.length === 0 ? (
                  <p className="text-ink-soft mt-1 text-xs">Nenhuma.</p>
                ) : (
                  <ul className="mt-1 space-y-1">
                    {info.zones.map((z) => (
                      <li key={z.id} className="text-xs" data-zona-do-jogador={z.id}>
                        {z.name ?? `(${z.x}, ${z.y})`}{' '}
                        <span className="text-ink-soft">
                          {z.name && `(${z.x}, ${z.y}) · `}
                          {nomeRecurso(z.mineral)}
                        </span>
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            </>
          ) : (
            <p className="text-ink-soft text-xs">Ainda não fundou colônia.</p>
          )}
        </div>
      )}
    </Popup>
  )
}
