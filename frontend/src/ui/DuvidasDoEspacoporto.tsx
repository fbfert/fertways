import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from '../api/client'
import type { DuvidasEspacoporto } from '../api/client'
import { Botao } from './sistema'

/**
 * As respostas prontas do Atendente — os "fluxos predefinidos" do §14.2 (D-270). São ponto de
 * partida: o Atendente escolhe uma e edita antes de enviar.
 */
const RESPOSTAS_PRONTAS: { rotulo: string; texto: string }[] = [
  {
    rotulo: 'Portão',
    texto:
      'O Cargueiro abre no marco 20, com 10 dias de colônia. Cada planeta pode pedir mais: Auryn o marco 25, Drakmoor o 35, e Solène a pesquisa Método Experimental. A tela de cada planeta diz o que falta.',
  },
  {
    rotulo: 'Imposto',
    texto:
      'A carga paga o mesmo imposto do Mercado Central: a encomenda na partida, a venda na volta, sobre o que o planeta pagou. As peças de alfândega da Endurance dão desconto.',
  },
  {
    rotulo: 'Risco',
    texto:
      'Auryn e Drakmoor têm risco: em parte das viagens há um sinistro que leva uma fração da carga das duas pernas. O que se pagou não volta. Kalidor, Veyra e Solène não têm risco.',
  },
  {
    rotulo: 'Cargueiro ocupado',
    texto:
      'O Governo tem poucos Cargueiros, e cada viagem ocupa um até voltar. Se todos estão no ar, espere um pousar — a tela mostra quantos estão no chão.',
  },
]

/**
 * As dúvidas do Espaçoporto (D-270): o colono pergunta e diz se a resposta ajudou; o Atendente — um
 * colono nomeado pela equipe — responde ou encaminha à equipe.
 */
export function DuvidasDoEspacoporto() {
  const [dados, setDados] = useState<DuvidasEspacoporto | null>(null)
  const [pergunta, setPergunta] = useState('')
  const [aviso, setAviso] = useState<string | null>(null)

  const carregar = useCallback(async () => {
    try {
      setDados(await api.duvidasEspacoporto())
    } catch {
      setDados(null)
    }
  }, [])

  useEffect(() => {
    void carregar()
  }, [carregar])

  const agir = async (acao: () => Promise<unknown>, ok: string) => {
    try {
      await acao()
      setAviso(ok)
      await carregar()
    } catch (e) {
      setAviso(e instanceof ApiError ? e.message : 'Não deu certo.')
    }
  }

  if (!dados) return null

  return (
    <section className="border-rust/20 border p-4" data-secao="duvidas-espacoporto">
      <h3 className="text-ink font-black">Dúvidas sobre o Espaçoporto</h3>
      <p className="text-ink-soft mt-1 text-xs">
        Pergunte ao Atendente do Espaçoporto, um colono nomeado pela equipe. Se a resposta ajudar,
        diga: é isso que conta para ele.
      </p>
      {aviso && <p className="text-ink mt-2 text-sm">{aviso}</p>}

      <div className="mt-3 flex flex-col gap-2 sm:flex-row">
        <input
          value={pergunta}
          onChange={(e) => setPergunta(e.target.value)}
          maxLength={500}
          placeholder="Sua dúvida sobre rotas, preços, imposto…"
          className="border-rust/30 flex-1 border px-2 py-1 text-sm"
          data-pergunta-espacoporto
        />
        <Botao
          variante="secundaria"
          tamanho="pequeno"
          disabled={pergunta.trim().length < 5}
          onClick={() =>
            void agir(async () => {
              await api.perguntarEspacoporto(pergunta)
              setPergunta('')
            }, 'Dúvida enviada ao Atendente.')
          }
        >
          Perguntar
        </Botao>
      </div>

      {dados.minhas.length > 0 && (
        <ul className="mt-3 space-y-2 text-sm">
          {dados.minhas.map((d) => (
            <li key={d.id} className="bg-sand p-2" data-minha-duvida={d.id}>
              <div className="text-ink">{d.pergunta}</div>
              {d.resposta ? (
                <div className="text-ink-soft mt-1">
                  <b>Atendente:</b> {d.resposta}
                  {d.ajudou === null ? (
                    <span className="ml-2 inline-flex gap-2">
                      <button
                        className="text-rust text-xs font-bold underline"
                        onClick={() =>
                          void agir(() => api.avaliarDuvida(d.id, true), 'Obrigado — o Atendente recebe por isso.')
                        }
                      >
                        Ajudou
                      </button>
                      <button
                        className="text-ink-soft text-xs underline"
                        onClick={() => void agir(() => api.avaliarDuvida(d.id, false), 'Anotado.')}
                      >
                        Não ajudou
                      </button>
                    </span>
                  ) : (
                    <span className="text-ink-soft/70 ml-2 text-xs">
                      {d.ajudou ? 'você disse que ajudou' : 'você disse que não ajudou'}
                    </span>
                  )}
                </div>
              ) : (
                <div className="text-ink-soft/70 mt-1 text-xs">
                  {d.encaminhada
                    ? 'Encaminhada à equipe — a resposta vem pela aba Bugs/Melhorias.'
                    : 'Esperando o Atendente.'}
                </div>
              )}
            </li>
          ))}
        </ul>
      )}

      {dados.sou_atendente && <FilaDoAtendente fila={dados.fila} agir={agir} />}
    </section>
  )
}

function FilaDoAtendente({
  fila,
  agir,
}: {
  fila: DuvidasEspacoporto['fila']
  agir: (acao: () => Promise<unknown>, ok: string) => Promise<void>
}) {
  const [respostas, setRespostas] = useState<Record<number, string>>({})

  return (
    <div className="border-rust/20 mt-4 border-t pt-3" data-fila-atendente>
      <div className="text-rust eyebrow">Você é o Atendente — {fila.length} dúvida(s) na fila</div>
      <p className="text-ink-soft mt-1 text-xs">
        Responda, ou encaminhe à equipe o que você não pode resolver. Você não altera contratos nem rotas.
      </p>
      <ul className="mt-2 space-y-3">
        {fila.map((d) => (
          <li key={d.id} className="bg-sand p-2 text-sm" data-duvida-fila={d.id}>
            <div className="text-ink">
              <b>{d.de ?? 'colono'}:</b> {d.pergunta}
            </div>
            <div className="mt-1 flex flex-wrap gap-1">
              {RESPOSTAS_PRONTAS.map((r) => (
                <button
                  key={r.rotulo}
                  className="border-rust/30 text-ink-soft border px-1.5 text-xs"
                  onClick={() => setRespostas({ ...respostas, [d.id]: r.texto })}
                >
                  {r.rotulo}
                </button>
              ))}
            </div>
            <textarea
              rows={2}
              value={respostas[d.id] ?? ''}
              onChange={(e) => setRespostas({ ...respostas, [d.id]: e.target.value })}
              className="border-rust/30 mt-1 w-full border px-2 py-1 text-sm"
            />
            <div className="mt-1 flex gap-3">
              <Botao
                variante="secundaria"
                tamanho="pequeno"
                disabled={(respostas[d.id] ?? '').trim().length < 2}
                onClick={() => void agir(() => api.responderDuvida(d.id, respostas[d.id] ?? ''), 'Resposta enviada.')}
              >
                Responder
              </Botao>
              <button
                className="text-ink-soft text-xs underline"
                onClick={() => void agir(() => api.encaminharDuvida(d.id), 'Encaminhada à equipe.')}
              >
                Encaminhar à equipe
              </button>
            </div>
          </li>
        ))}
      </ul>
    </div>
  )
}
