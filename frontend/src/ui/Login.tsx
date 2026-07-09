import { useState } from 'react'
import { api, ApiError, token } from '../api/client'
import { Marca } from './Marca'

export function Login({ aoEntrar }: { aoEntrar: () => void }) {
  const [modo, setModo] = useState<'login' | 'registro'>('login')
  const [f, setF] = useState({ name: '', nickname: '', email: '', password: '' })
  const [erro, setErro] = useState<string | null>(null)
  const [enviando, setEnviando] = useState(false)

  const registrando = modo === 'registro'

  async function enviar(e: React.FormEvent) {
    e.preventDefault()
    setErro(null)
    setEnviando(true)
    try {
      const s = registrando ? await api.register(f) : await api.login(f)
      token.set(s.token)
      aoEntrar()
    } catch (err) {
      setErro(err instanceof ApiError ? err.message : 'Falha de conexão com o servidor.')
    } finally {
      setEnviando(false)
    }
  }

  const campo = 'w-full border border-rust/25 bg-sand-light px-3 py-2 text-ink outline-none focus:border-rust'

  return (
    <div className="flex min-h-screen items-center justify-center p-6">
      <div className="painel bg-sand-light w-full max-w-md p-8 shadow-lg">
        <Marca />

        <h1 className="text-ink mt-8 text-2xl font-black">
          {registrando ? 'Fundar um colono.' : 'Bem-vindo de volta.'}
        </h1>
        <div className="border-rust/30 my-5 border-t" />

        <form onSubmit={enviar} className="space-y-3">
          {registrando && (
            <>
              <input
                className={campo}
                placeholder="Nome"
                value={f.name}
                onChange={(e) => setF({ ...f, name: e.target.value })}
                required
              />
              <input
                className={campo}
                placeholder="Nickname (único no servidor)"
                value={f.nickname}
                onChange={(e) => setF({ ...f, nickname: e.target.value })}
                required
              />
            </>
          )}
          <input
            className={campo}
            type="email"
            placeholder="E-mail"
            value={f.email}
            onChange={(e) => setF({ ...f, email: e.target.value })}
            required
          />
          <input
            className={campo}
            type="password"
            placeholder="Senha"
            value={f.password}
            onChange={(e) => setF({ ...f, password: e.target.value })}
            required
          />

          {erro && <p className="text-rust text-sm">{erro}</p>}

          <button
            type="submit"
            disabled={enviando}
            className="bg-rust text-sand-light hover:bg-rust-bright w-full py-3 font-bold disabled:opacity-50"
          >
            {enviando ? 'Enviando…' : registrando ? 'Criar colono' : 'Entrar'}
          </button>
        </form>

        <button
          onClick={() => {
            setModo(registrando ? 'login' : 'registro')
            setErro(null)
          }}
          className="text-ink-soft hover:text-rust mt-4 w-full text-sm"
        >
          {registrando ? 'Já tenho conta' : 'Ainda não tenho conta'}
        </button>
      </div>
    </div>
  )
}
