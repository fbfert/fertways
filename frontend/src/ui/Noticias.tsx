import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from '../api/client'
import type { Noticias as NoticiasDto } from '../api/client'
import { dataHumana } from './recursos'

/**
 * Central de Pesquisas e Notícias (slot 3).
 *
 * O mural de comunicados oficiais, publicados pela equipe (§02: Governo operado pela equipe), mais
 * — desde o D-130 — os boletins do Repórter (§14.2): mesmo mural, `kind: 'boletim'` em vez de
 * `'comunicado'`, e só quem ocupa o cargo vê o formulário. O Telescópio Gagarin — que traria
 * boletins automáticos — só ativa com 50 jogadores ou 45 dias (§12.1); até lá, o painel diz isso
 * honestamente.
 */
export function Noticias() {
  const [dados, setDados] = useState<NoticiasDto | null>(null)
  const [erro, setErro] = useState<string | null>(null)
  const [titulo, setTitulo] = useState('')
  const [corpo, setCorpo] = useState('')
  const [enviando, setEnviando] = useState(false)

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

  async function publicar() {
    setEnviando(true)
    setErro(null)
    try {
      await api.publicarMateria(titulo, corpo)
      setTitulo('')
      setCorpo('')
      await carregar()
    } catch (e) {
      setErro(e instanceof ApiError ? e.message : 'Falha ao publicar.')
    } finally {
      setEnviando(false)
    }
  }

  if (erro && !dados) return <p className="text-rust mt-4 text-sm font-bold">{erro}</p>
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

      {/* Só quem é Repórter vê isto (§14.2, D-130). */}
      {dados.posso_publicar && (
        <div className="border-rust/20 bg-sand border p-3" data-form="publicar-materia">
          <div className="text-rust eyebrow">Publicar matéria</div>
          <p className="text-ink-soft mt-1 text-xs">
            Você é Repórter: o que publicar aqui entra no mural como boletim, com o seu nome.
          </p>
          <input
            className="border-rust/25 bg-sand-light focus:border-rust mt-2 w-full border px-2 py-1.5 text-sm outline-none"
            placeholder="Título"
            value={titulo}
            onChange={(e) => setTitulo(e.target.value)}
          />
          <textarea
            className="border-rust/25 bg-sand-light focus:border-rust mt-2 w-full border px-2 py-1.5 text-sm outline-none"
            placeholder="Corpo da matéria"
            rows={3}
            value={corpo}
            onChange={(e) => setCorpo(e.target.value)}
          />
          <button
            disabled={enviando || titulo.trim() === '' || corpo.trim() === ''}
            onClick={() => void publicar()}
            className="bg-rust text-sand-light hover:bg-rust-bright mt-2 px-4 py-1.5 text-sm font-bold disabled:cursor-not-allowed disabled:opacity-40"
          >
            Publicar
          </button>
        </div>
      )}

      {erro && <p className="text-rust text-sm font-bold">{erro}</p>}

      {/* O mural. */}
      {dados.noticias.length > 0 ? (
        <ul className="space-y-3" data-noticias>
          {dados.noticias.map((n) => (
            <li key={n.id} className="border-rust/20 border-l-2 pl-3">
              <div className="text-ink font-black">
                {n.title}
                {n.kind === 'boletim' && (
                  <span className="text-rust ml-2 text-[0.6rem] font-bold uppercase">boletim</span>
                )}
              </div>
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
