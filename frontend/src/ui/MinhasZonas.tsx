import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api/client'
import type { MinhaZona } from '../api/client'
import { dataHumana, nomeRecurso } from './recursos'

/**
 * As zonas neutras do colono, na barra lateral da colônia (docs/decisoes.md D-69).
 *
 * Elas eram invisíveis: para saber que uma zona sua estava **cercada**, ou que tinha 3.000 unidades
 * **expostas ao saque**, era preciso abrir o mapa, aproximar, achar a célula e clicar. Uma zona que
 * exige ação urgente não pode estar a quatro cliques de distância.
 *
 * Cada linha mostra o que decide se ele precisa largar o que está fazendo:
 *
 *  - **exposto** — o que a guerra pode levar. Só o que EXCEDE o Depósito é saqueável (D-66), e é o
 *    único número da tela que significa "vá agora".
 *  - **cercada** — nada entra nem sai, e o que se extrai **se perde** (§28.10).
 *  - **obra** — o que está sendo erguido, e quando fica pronto.
 */
export function MinhasZonas() {
  const [zonas, setZonas] = useState<MinhaZona[]>([])

  const carregar = useCallback(async () => {
    try {
      setZonas((await api.minhasZonas()).zones)
    } catch {
      // Sem zonas, sem lista. Uma falha aqui não pode derrubar a colônia.
      setZonas([])
    }
  }, [])

  useEffect(() => {
    void carregar()
    // O cerco e o saque acontecem no tick, de minuto em minuto. Meio minuto é folgado o bastante
    // para não martelar a API e apertado o bastante para o alerta chegar antes do estrago.
    const t = setInterval(() => void carregar(), 30_000)

    return () => clearInterval(t)
  }, [carregar])

  // Sem zonas, a barra não aparece. Um cartão vazio dizendo "nenhuma zona" é ruído.
  if (zonas.length === 0) return null

  return (
    <div className="painel bg-sand-light p-3" data-secao="minhas-zonas">
      <div className="text-rust eyebrow">Zonas neutras</div>

      <ul className="mt-2 space-y-2">
        {zonas.map((z) => (
          <li key={z.id}>
            <Link
              to={`/zona/${z.id}`}
              data-zona={z.id}
              className={`hover:bg-sand block border-l-2 py-1 pl-2 ${
                z.cercada ? 'border-rust bg-rust/5' : 'border-ember'
              }`}
            >
              <div className="text-ink text-sm font-bold">
                ({z.x}, {z.y}){' '}
                <span className="text-ink-soft text-xs font-normal">
                  {nomeRecurso(z.mineral)}
                </span>
              </div>

              {/* ⚠️ O cerco vem primeiro: é a única coisa aqui que está destruindo valor AGORA. */}
              {z.cercada && (
                <div className="text-rust text-xs font-bold">
                  ⚠ CERCADA — nada entra nem sai, e a extração se perde
                </div>
              )}

              {!z.produtiva && !z.cercada && (
                <div className="text-ink-soft text-xs">estabelecendo…</div>
              )}

              <div className="text-ink-soft text-xs">
                depósito {z.deposito} / {z.capacidade}
              </div>

              {/* O número que significa "vá buscar antes que alguém venha". */}
              {z.exposto > 0 && (
                <div className="text-rust text-xs font-bold">
                  {z.exposto} exposto ao saque
                </div>
              )}

              {z.obra && (
                <div className="text-ink-soft text-xs">
                  obra: {z.obra.nome} n{z.obra.nivel} · {dataHumana(z.obra.termina_at)}
                </div>
              )}
            </Link>
          </li>
        ))}
      </ul>
    </div>
  )
}
