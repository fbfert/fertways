import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from '../api/client'
import type { Noticias as NoticiasDto } from '../api/client'
import { dataHumana } from './recursos'

/**
 * Central de Pesquisas e Notícias (slot 3). Só leitura.
 *
 * O mural de comunicados oficiais, publicados pela equipe (§02: Governo operado pela equipe). O
 * Telescópio Gagarin — que traria boletins automáticos — só ativa com 50 jogadores ou 45 dias
 * (§12.1); até lá, o painel diz isso honestamente.
 */
export function Noticias() {
  const [dados, setDados] = useState<NoticiasDto | null>(null)
  const [erro, setErro] = useState<string | null>(null)

  const carregar = useCallback(async () => {
    try {
      setDados(await api.noticias())
    } catch (e) {
      setErro(e instanceof ApiError ? e.message : 'Falha ao carregar as Notícias.')
    }
  }, [])

  useEffect(() => {
    void carregar()
  }, [carregar])

  if (erro) return <p className="text-rust mt-4 text-sm font-bold">{erro}</p>
  if (!dados) return <p className="text-ink-soft mt-4 text-sm">Carregando…</p>

  return (
    <div className="mt-5 space-y-5">
      {/* Estado do Gagarin: inativo até o gatilho do §12.1. */}
      <div className="border-rust/20 bg-sand border p-3">
        <div className="text-rust eyebrow">Telescópio Gagarin</div>
        <p className="text-ink-soft mt-1 text-sm">
          {dados.gagarin.regra} No ar: <span className="tabular-nums">{dados.gagarin.jogadores}</span>/
          {dados.gagarin.limiar_jogadores} jogadores.{' '}
          <span className="text-ink font-bold">
            {dados.gagarin.ativo ? 'Ativo.' : 'Ainda inativo — sem boletins automáticos.'}
          </span>
        </p>
      </div>

      {/* O mural. */}
      {dados.noticias.length > 0 ? (
        <ul className="space-y-3" data-noticias>
          {dados.noticias.map((n) => (
            <li key={n.id} className="border-rust/20 border-l-2 pl-3">
              <div className="text-ink font-black">{n.title}</div>
              <div className="text-ink-soft/60 eyebrow text-[0.6rem]">
                {n.author} · {dataHumana(n.published_at)}
              </div>
              <p className="text-ink-soft mt-1 whitespace-pre-line text-sm">{n.body}</p>
            </li>
          ))}
        </ul>
      ) : (
        <p className="text-ink-soft/70 text-sm">Nenhum comunicado ainda.</p>
      )}
    </div>
  )
}
