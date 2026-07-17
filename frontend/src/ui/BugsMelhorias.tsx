import { useState } from 'react'
import { api, ApiError } from '../api/client'

/**
 * Bugs/Melhorias (D-95) — ao lado do Chat no cabeçalho: um formulário curto para o colono
 * reportar um bug, sugerir uma melhoria, ou perguntar algo. Dados do jogador/colônia/e-mail são
 * anexados pelo servidor, não digitados aqui — é o mesmo colono autenticado que já está jogando.
 *
 * Sem "minhas mensagens": confirmado com o usuário que o aviso de resposta é só pelo rádio
 * (D-91, "Capital"), não uma tela de acompanhamento. Só o envio, e a confirmação de que chegou.
 */
const TIPOS: { valor: string; rotulo: string }[] = [
  { valor: 'bug', rotulo: 'Bug' },
  { valor: 'melhoria', rotulo: 'Sugestão de melhoria' },
  { valor: 'duvida', rotulo: 'Dúvida' },
  { valor: 'outro', rotulo: 'Outro' },
]

export function BugsMelhorias({ aoFechar }: { aoFechar: () => void }) {
  const [tipo, setTipo] = useState('bug')
  const [assunto, setAssunto] = useState('')
  const [mensagem, setMensagem] = useState('')
  const [enviando, setEnviando] = useState(false)
  const [erro, setErro] = useState<string | null>(null)
  const [enviado, setEnviado] = useState(false)

  async function enviar() {
    setErro(null)
    setEnviando(true)
    try {
      await api.enviarFeedback(tipo, assunto, mensagem)
      setEnviado(true)
      setAssunto('')
      setMensagem('')
    } catch (e) {
      setErro(e instanceof ApiError ? e.message : 'Falha ao enviar.')
    } finally {
      setEnviando(false)
    }
  }

  return (
    <div
      className="painel bg-sand-light border-rust/30 fixed right-4 bottom-4 z-30 flex max-h-[560px] w-[360px] flex-col border shadow-lg"
      data-tela="bugs-melhorias"
    >
      <div className="border-rust/20 flex items-center justify-between border-b px-3 py-2">
        <span className="text-rust eyebrow">Bugs/Melhorias</span>
        <button
          onClick={aoFechar}
          data-fechar-bugs-melhorias
          className="text-ink-soft hover:text-rust text-xl leading-none"
        >
          ×
        </button>
      </div>

      <div className="flex-1 overflow-y-auto px-3 py-3 text-sm">
        {enviado ? (
          <div data-feedback-enviado>
            <p className="text-ink text-sm">
              Enviado. Se o Governo responder, você recebe um aviso pelo rádio, de "Capital".
            </p>
            <button onClick={() => setEnviado(false)} className="botao mt-3 w-full">
              Mandar outra mensagem
            </button>
          </div>
        ) : (
          <>
            <p className="text-ink-soft mb-3 text-xs">
              Achou um bug, tem uma ideia, ficou com uma dúvida? Escreva aqui — o Governo lê e,
              se responder, você é avisado pelo rádio do planeta.
            </p>

            <label className="text-ink-soft eyebrow">Tipo</label>
            <select
              value={tipo}
              onChange={(e) => setTipo(e.target.value)}
              data-feedback-tipo
              className="border-rust/25 bg-sand focus:border-rust mt-1 mb-2 w-full border px-2 py-1.5 text-sm outline-none"
            >
              {TIPOS.map((t) => (
                <option key={t.valor} value={t.valor}>
                  {t.rotulo}
                </option>
              ))}
            </select>

            <label className="text-ink-soft eyebrow">Assunto</label>
            <input
              value={assunto}
              onChange={(e) => setAssunto(e.target.value)}
              maxLength={120}
              data-feedback-assunto
              className="border-rust/25 bg-sand focus:border-rust mt-1 mb-2 w-full border px-2 py-1.5 text-sm outline-none"
              placeholder="Um resumo em poucas palavras"
            />

            <label className="text-ink-soft eyebrow">Mensagem</label>
            <textarea
              value={mensagem}
              onChange={(e) => setMensagem(e.target.value)}
              rows={5}
              maxLength={4000}
              data-feedback-mensagem
              className="border-rust/25 bg-sand focus:border-rust mt-1 w-full border px-2 py-1.5 text-sm outline-none"
              placeholder="Descreva com o máximo de detalhe que conseguir"
            />
            {mensagem.trim().length > 0 && mensagem.trim().length < 10 && (
              <p className="text-ink-soft mt-1 text-xs" data-feedback-mensagem-curta>
                Faltam pelo menos {10 - mensagem.trim().length} caractere(s) — o mínimo é 10.
              </p>
            )}

            {erro && <p className="text-rust mt-2 text-xs">{erro}</p>}

            <button
              onClick={() => void enviar()}
              disabled={enviando || assunto.trim() === '' || mensagem.trim().length < 10}
              data-enviar-feedback
              className="botao mt-3 w-full disabled:opacity-40"
            >
              {enviando ? 'Enviando…' : assunto.trim() === '' ? 'Escreva um assunto' : 'Enviar'}
            </button>
          </>
        )}
      </div>
    </div>
  )
}
