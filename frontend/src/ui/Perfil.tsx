import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from '../api/client'
import type { Perfil as PerfilDto } from '../api/client'

/**
 * O perfil do colono (docs/decisoes.md D-69).
 *
 * Ele podia jogar, guerrear e comerciar — e **não podia trocar a própria senha**. A única forma de
 * mudar qualquer coisa da conta era pedir a um operador, pelo painel de admin.
 *
 * Três coisas que esta tela precisa dizer, e que o colono não tem como adivinhar:
 *
 *  1. **Trocar o e-mail exige a senha atual, e trocar o nome não.** Não é capricho: o e-mail é com o
 *     que se entra, e **não há recuperação de conta em Fertways**. Um nome mal escolhido se corrige;
 *     uma conta tomada, não.
 *  2. **Trocar a senha derruba as outras sessões.** É o ponto, e não um efeito colateral: se ele
 *     está trocando porque desconfia que alguém entrou, uma senha nova sem revogar os tokens não
 *     expulsa ninguém.
 *  3. **Os quatro índices de reputação NÃO se editam.** Eles são o histórico dele no Ministério.
 *     Aparecem porque ele tem direito de os ver.
 */
const INDICES: { chave: keyof PerfilDto['reputacao']; nome: string; oque: string }[] = [
  {
    chave: 'confianca_comercial',
    nome: 'Confiança Comercial',
    oque: 'Cai quando você quebra um Acordo de Troca. Abaixo do limiar, o Mercado se fecha.',
  },
  {
    chave: 'conduta_social',
    nome: 'Conduta Social',
    oque: 'O seu comportamento com os outros colonos, julgado pelo Ministério.',
  },
  { chave: 'status_civico', nome: 'Status Cívico', oque: 'A sua participação na vida pública.' },
  {
    chave: 'honra_militar_diplomatica',
    nome: 'Honra Militar-Diplomática',
    oque: 'Guerra e tratados. Inerte enquanto não houver federações.',
  },
]

export function Perfil({ aoFechar, aoSalvar }: { aoFechar: () => void; aoSalvar: () => void }) {
  const [p, setP] = useState<PerfilDto | null>(null)
  const [erro, setErro] = useState<string | null>(null)
  const [recibo, setRecibo] = useState<string | null>(null)
  const [ocupado, setOcupado] = useState(false)

  const [nome, setNome] = useState('')
  const [nick, setNick] = useState('')
  const [email, setEmail] = useState('')
  const [colonia, setColonia] = useState('')
  const [senhaAtual, setSenhaAtual] = useState('')

  const [senhaVelha, setSenhaVelha] = useState('')
  const [senhaNova, setSenhaNova] = useState('')

  const carregar = useCallback(async () => {
    try {
      const d = await api.perfil()
      setP(d)
      setNome(d.name)
      setNick(d.nickname)
      setEmail(d.email)
      setColonia(d.colony_name ?? '')
    } catch (e) {
      setErro(e instanceof ApiError ? e.message : 'Falha ao carregar o perfil.')
    }
  }, [])

  useEffect(() => {
    void carregar()
  }, [carregar])

  async function agir(acao: () => Promise<string>) {
    setOcupado(true)
    setErro(null)
    setRecibo(null)
    try {
      setRecibo(await acao())
      await carregar()
      aoSalvar()
    } catch (e) {
      setErro(e instanceof ApiError ? e.message : 'Falha na operação.')
    } finally {
      setOcupado(false)
    }
  }

  const moldura = (dentro: React.ReactNode) => (
    <div className="bg-sand fixed inset-0 z-20 overflow-y-auto" data-tela="perfil">
      <div className="bg-sand-light mx-auto min-h-screen w-full max-w-2xl p-6">
        <div className="mb-4 flex items-start justify-between">
          <div>
            <div className="text-rust eyebrow">Colono</div>
            <h2 className="text-2xl font-black">{p?.name ?? 'Perfil'}</h2>
          </div>
          <button
            onClick={aoFechar}
            data-fechar-perfil
            className="text-ink-soft hover:text-rust text-2xl leading-none"
          >
            ×
          </button>
        </div>
        {dentro}
      </div>
    </div>
  )

  if (erro && !p) return moldura(<p className="text-rust text-sm font-bold">{erro}</p>)
  if (!p) return moldura(<p className="text-ink-soft text-sm">Carregando…</p>)

  const trocouEmail = email !== p.email

  return moldura(
    <div className="space-y-6">
      {/* ── a conta ──────────────────────────────────────────────────────────────────────── */}
      <section className="painel bg-sand p-4" data-secao="conta">
        <h3 className="font-bold">A sua conta</h3>

        <div className="mt-3 grid gap-3 sm:grid-cols-2">
          <label className="text-sm">
            Nome
            <input
              value={nome}
              onChange={(e) => setNome(e.target.value)}
              data-campo-nome
              className="border-rust/25 bg-sand-light focus:border-rust mt-1 w-full border px-2 py-1 outline-none"
            />
          </label>

          <label className="text-sm">
            Nickname
            <input
              value={nick}
              onChange={(e) => setNick(e.target.value)}
              data-campo-nickname
              className="border-rust/25 bg-sand-light focus:border-rust mt-1 w-full border px-2 py-1 outline-none"
            />
            <span className="text-ink-soft block text-xs">
              É por ele que os outros colonos o conhecem — no diretório, nos acordos e no Ministério.
            </span>
          </label>

          <label className="text-sm">
            Nome da colônia
            <input
              value={colonia}
              onChange={(e) => setColonia(e.target.value)}
              data-campo-colonia
              className="border-rust/25 bg-sand-light focus:border-rust mt-1 w-full border px-2 py-1 outline-none"
            />
          </label>

          <label className="text-sm">
            E-mail
            <input
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              data-campo-email
              className="border-rust/25 bg-sand-light focus:border-rust mt-1 w-full border px-2 py-1 outline-none"
            />
          </label>
        </div>

        {/*
          O campo da senha só aparece quando o e-mail muda — e a explicação vem junto. Pedi-la sempre
          seria atrito; não a pedir nunca seria deixar uma sessão esquecida virar uma conta perdida.
        */}
        {trocouEmail && (
          <div className="border-rust bg-rust/5 mt-3 border-l-4 p-3" data-exige-senha>
            <p className="text-sm">
              <strong>Você está trocando o e-mail.</strong> É com ele que você entra, e{' '}
              <strong>não há recuperação de conta em Fertways</strong>. Confirme com a sua senha.
            </p>
            <input
              type="password"
              value={senhaAtual}
              onChange={(e) => setSenhaAtual(e.target.value)}
              placeholder="senha atual"
              data-campo-senha-atual
              className="border-rust/25 bg-sand-light focus:border-rust mt-2 w-full border px-2 py-1 outline-none"
            />
          </div>
        )}

        <button
          className="botao mt-3 w-full"
          disabled={ocupado || (trocouEmail && !senhaAtual)}
          data-salvar-perfil
          onClick={() =>
            void agir(async () => {
              await api.salvarPerfil({
                name: nome,
                nickname: nick,
                email,
                colony_name: colonia || undefined,
                senha_atual: trocouEmail ? senhaAtual : undefined,
              })
              setSenhaAtual('')

              return 'Perfil salvo.'
            })
          }
        >
          Salvar
        </button>
      </section>

      {/* ── a senha ──────────────────────────────────────────────────────────────────────── */}
      <section className="painel bg-sand p-4" data-secao="senha">
        <h3 className="font-bold">Trocar a senha</h3>
        <p className="text-ink-soft mt-1 text-sm">
          <strong>Trocar a senha derruba as suas outras sessões.</strong> Se você está trocando porque
          desconfia que alguém entrou na sua conta, é justamente isso que o põe para fora — uma senha
          nova, sozinha, não expulsa ninguém. A sessão em que você está agora continua.
        </p>

        <div className="mt-3 grid gap-3 sm:grid-cols-2">
          <label className="text-sm">
            Senha atual
            <input
              type="password"
              value={senhaVelha}
              onChange={(e) => setSenhaVelha(e.target.value)}
              data-senha-velha
              className="border-rust/25 bg-sand-light focus:border-rust mt-1 w-full border px-2 py-1 outline-none"
            />
          </label>
          <label className="text-sm">
            Senha nova (mínimo 8)
            <input
              type="password"
              value={senhaNova}
              onChange={(e) => setSenhaNova(e.target.value)}
              data-senha-nova
              className="border-rust/25 bg-sand-light focus:border-rust mt-1 w-full border px-2 py-1 outline-none"
            />
          </label>
        </div>

        <button
          className="botao mt-3 w-full"
          disabled={ocupado || !senhaVelha || senhaNova.length < 8}
          data-trocar-senha
          onClick={() =>
            void agir(async () => {
              const r = await api.trocarSenha(senhaVelha, senhaNova)
              setSenhaVelha('')
              setSenhaNova('')

              return r.sessoes_revogadas > 0
                ? `Senha trocada. ${r.sessoes_revogadas} outra(s) sessão(ões) foram derrubadas.`
                : 'Senha trocada. Não havia outras sessões abertas.'
            })
          }
        >
          Trocar a senha
        </button>
      </section>

      {/* ── a reputação ──────────────────────────────────────────────────────────────────── */}
      <section data-secao="reputacao">
        <h3 className="font-bold">A sua reputação</h3>
        <p className="text-ink-soft mt-1 text-xs">
          Os quatro índices do Ministério. <strong>Não se editam</strong> — são o seu histórico, e são{' '}
          <strong>isolados</strong>: ser um bom cidadão não paga uma dívida comercial.
        </p>

        <div className="mt-2 space-y-2">
          {INDICES.map(({ chave, nome, oque }) => {
            const v = p.reputacao[chave]
            const baixo = chave === 'confianca_comercial' && v < p.limiar_bloqueio

            return (
              <div key={chave} className="painel bg-sand p-3" data-indice={chave}>
                <div className="flex items-baseline justify-between text-sm">
                  <strong>{nome}</strong>
                  <span className={baixo ? 'text-rust font-black' : 'font-black'}>{v}</span>
                </div>
                <div className="bg-ink-soft/15 mt-1 h-1.5 w-full">
                  <div
                    className={baixo ? 'bg-rust h-full' : 'bg-ember h-full'}
                    style={{ width: `${Math.min(100, (v / 1000) * 100)}%` }}
                  />
                </div>
                <p className="text-ink-soft mt-1 text-xs">{oque}</p>
                {baixo && (
                  <p className="text-rust mt-1 text-xs font-bold">
                    Abaixo de {p.limiar_bloqueio}: o Mercado Central está fechado para você.
                  </p>
                )}
              </div>
            )
          })}
        </div>

        {p.conciliador && (
          <p className="mt-2 text-sm">
            Você é <strong>conciliador</strong> do Ministério das Reputações: julga denúncias e recebe
            salário diário do Tesouro.
          </p>
        )}
      </section>

      {erro && <p className="text-rust text-sm font-bold">{erro}</p>}
      {recibo && <p className="text-sm font-bold">{recibo}</p>}
    </div>,
  )
}
