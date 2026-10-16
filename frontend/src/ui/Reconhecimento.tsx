import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from '../api/client'
import type { MissaoDeReconhecimento, Reconhecimento as ReconhecimentoDto } from '../api/client'
import { dataHumana, nomeRecurso } from './recursos'

/**
 * O Telescópio Gagarin e as Missões de Reconhecimento (aditivo v3.4 §2, D-282), na Central de
 * Pesquisas e Notícias.
 *
 * Mostra o que é público: o estado do Gagarin, as missões em curso pela fase e pelo setor, as luas
 * já reveladas e o Diário do Colono. O planeta de uma missão planetária não tem nome até a rota abrir;
 * a assinatura do resultado lacrado, sim, desde o primeiro minuto. Contribuir é uma tarefa de pesquisa
 * com teto diário, que nada no jogo acelera: nem Fert$, nem compra.
 */
export function Reconhecimento() {
  const [dados, setDados] = useState<ReconhecimentoDto | null>(null)
  const [erro, setErro] = useState<string | null>(null)
  const [aviso, setAviso] = useState<string | null>(null)
  const [ocupado, setOcupado] = useState(false)

  const carregar = useCallback(async () => {
    try {
      setDados(await api.reconhecimento())
    } catch (e) {
      setErro(e instanceof ApiError ? e.message : 'Falha ao ler o Gagarin.')
    }
  }, [])

  useEffect(() => {
    void carregar()
  }, [carregar])

  if (erro && !dados) return <p className="text-rust text-sm">{erro}</p>
  if (!dados) return null

  const { gagarin } = dados
  const emCurso = dados.missoes.filter((m) => !m.concluida_em)
  const restam = dados.tarefas_por_dia - dados.tarefas_hoje

  async function contribuir(m: MissaoDeReconhecimento) {
    setOcupado(true)
    try {
      const r = await api.tarefaDeReconhecimento(m.id)
      setAviso(
        `Tarefa registrada: +${dados?.xp_por_tarefa ?? 0} XP. A missão está com ${r.tarefas} tarefa(s)` +
          (m.pede_meta ? ` de ${r.meta_de_tarefas}.` : '.'),
      )
      await carregar()
    } catch (e) {
      setAviso(e instanceof ApiError ? e.message : 'A tarefa não foi registrada.')
    } finally {
      setOcupado(false)
    }
  }

  return (
    <section className="border-rust/20 bg-sand space-y-3 border p-3" data-reconhecimento>
      <div>
        <div className="text-rust eyebrow">Telescópio Gagarin</div>
        {!gagarin.ativo ? (
          <p className="text-ink-soft mt-1 text-sm">
            Entra em operação com {gagarin.limiar_jogadores} jogadores cadastrados ou {gagarin.limiar_dias} dias de
            servidor, o que vier primeiro. Hoje:{' '}
            <span className="tabular-nums">
              {gagarin.jogadores} jogadores, {gagarin.dias} dia(s)
            </span>
            . <span className="text-ink font-bold">Ainda inativo.</span>
          </p>
        ) : (
          <p className="text-ink-soft mt-1 text-sm">
            <span className="text-ink font-bold">Em operação.</span>{' '}
            {gagarin.pausado
              ? 'As operações estão pausadas para manutenção.'
              : gagarin.proximo_boletim_em
                ? `Próximo boletim orbital ${dataHumana(gagarin.proximo_boletim_em)}.`
                : ''}{' '}
            Toda descoberta é pública: nada acelera ou guarda para alguém o que o Gagarin revela.
          </p>
        )}
      </div>

      {gagarin.ativo && (
        <div>
          <div className="text-ink font-black">Operações em curso</div>
          {emCurso.length === 0 ? (
            <p className="text-ink-soft text-sm">Nenhuma. O Gagarin segue lendo o céu.</p>
          ) : (
            <ul className="mt-2 space-y-2">
              {emCurso.map((m) => (
                <li key={m.id} className="border-rust/20 bg-sand-light border p-2 text-sm" data-missao-rec={m.id}>
                  <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <span className="text-ink font-bold">
                      {m.tipo === 'lunar' ? `Lua — ${m.alvo_nome ?? m.setor}` : `Anomalia ${m.anomalia} em ${m.setor}`}
                    </span>
                    <span className="text-rust eyebrow">{m.fase_nome}</span>
                  </div>
                  <p className="text-ink-soft mt-1 text-xs">
                    {m.fase_ate ? `Esta fase vai até ${dataHumana(m.fase_ate)}` : 'Sem prazo'}
                    {m.pede_meta && ` e pede ${m.meta_de_tarefas} tarefas de pesquisa: ${m.tarefas} feitas`}
                    {!m.pede_meta && m.aceita_tarefa && ` · ${m.tarefas} tarefa(s) de leitura acumulada`}.
                  </p>
                  {m.aceita_tarefa && (
                    <div className="mt-2 flex flex-wrap items-center gap-2">
                      <button
                        type="button"
                        className="botao text-xs"
                        disabled={ocupado || restam <= 0 || !dados.tem_laboratorio || gagarin.pausado}
                        data-contribuir={m.id}
                        onClick={() => void contribuir(m)}
                      >
                        Fazer uma tarefa de pesquisa (+{dados.xp_por_tarefa} XP)
                      </button>
                      <span className="text-ink-soft text-xs">
                        {!dados.tem_laboratorio
                          ? 'Pede um Laboratório na colônia.'
                          : `Você fez ${dados.tarefas_hoje} de ${dados.tarefas_por_dia} hoje (o dia vira às 07h).`}
                      </span>
                    </div>
                  )}
                  <p className="text-ink-soft mt-1 text-micro break-all">
                    Resultado lacrado · SHA-256 {m.assinatura}
                  </p>
                </li>
              ))}
            </ul>
          )}
          {aviso && <p className="text-ink mt-2 text-xs">{aviso}</p>}
        </div>
      )}

      <div>
        <div className="text-ink font-black">As oito luas</div>
        <ul className="mt-1 grid gap-1 text-xs sm:grid-cols-2">
          {dados.luas.map((l) => (
            <li key={l.chave} className={l.revelada ? 'text-ink' : 'text-ink-soft'} data-lua={l.chave}>
              <b>{l.nome}</b>
              {l.revelada ? (
                <>
                  {' '}
                  — {l.primeira_revelacao}. Recurso: {nomeRecurso(l.recurso ?? '')}. {l.gancho}
                </>
              ) : (
                ' — ainda não lida.'
              )}
            </li>
          ))}
        </ul>
        <p className="text-ink-soft mt-1 text-micro">
          As luas são informação até a Temporada 2: não há pouso, mineração nem conflito lunar.
        </p>
      </div>

      {dados.diario.length > 0 && (
        <div data-diario>
          <div className="text-ink font-black">Seu Diário do Colono</div>
          <ul className="mt-1 space-y-2">
            {dados.diario.map((d) => (
              <li key={d.id} className="border-rust/20 border-l-2 pl-2 text-sm">
                <div className="text-ink font-bold">{d.titulo}</div>
                <div className="text-ink-soft text-micro">{dataHumana(d.created_at)}</div>
                <p className="text-ink-soft text-xs">{d.corpo}</p>
              </li>
            ))}
          </ul>
        </div>
      )}
    </section>
  )
}
