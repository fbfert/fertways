import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from '../api/client'
import type {
  ColoniaVizinha,
  FederationInviteDto,
  FederationListItem,
  FederationRole,
  MinhaFederacao,
  Veiculo,
} from '../api/client'
import { NEGOCIAVEIS, nomeRecurso, nomeVeiculo } from './recursos'

/**
 * O Quartel de Alianças (§04/§07), Capital slot 9 — Federação, Fatia 1 (D-114).
 *
 * Só o núcleo: fundar/entrar/sair, os quatro cargos do GDD, e o fundo (entrada por entrega física
 * de veículo — mesmo formulário de despacho que o resto do jogo já usa; saída por saque
 * administrativo do Líder/Intendente, sem veículo). Canal de chat, apoio de aliado num cerco,
 * categoria de missão e a Central de Comunicação da zona ficam para fatias seguintes.
 */
const NOME_CARGO: Record<FederationRole, string> = {
  lider: 'Líder',
  diplomata: 'Diplomata',
  intendente: 'Intendente',
  membro: 'Membro',
}

export function Federacao() {
  const [dados, setDados] = useState<MinhaFederacao | null>(null)
  const [diretorio, setDiretorio] = useState<FederationListItem[]>([])
  const [colonias, setColonias] = useState<ColoniaVizinha[]>([])
  const [veiculos, setVeiculos] = useState<Veiculo[]>([])
  const [erro, setErro] = useState<string | null>(null)
  const [recibo, setRecibo] = useState<string | null>(null)
  const [ocupado, setOcupado] = useState(false)

  const carregar = useCallback(async () => {
    try {
      const [minha, frota] = await Promise.all([api.minhaFederacao(), api.frota()])
      setDados(minha)
      setVeiculos(frota.vehicles)
    } catch (e) {
      setErro(e instanceof ApiError ? e.message : 'Falha ao carregar a Federação.')
    }
  }, [])

  useEffect(() => {
    void carregar()
  }, [carregar])

  useEffect(() => {
    api
      .federacoes()
      .then(setDiretorio)
      .catch(() => undefined)
    api
      .colonias()
      .then((r) => setColonias(r.colonies))
      .catch(() => undefined)
  }, [])

  /** Toda ação desta tela tem a mesma forma: age, avisa o que houve, recarrega. */
  async function agir(acao: () => Promise<unknown>, mensagem: string) {
    setOcupado(true)
    setErro(null)
    setRecibo(null)

    try {
      await acao()
      setRecibo(mensagem)
      await carregar()
      await api
        .federacoes()
        .then(setDiretorio)
        .catch(() => undefined)
    } catch (e) {
      setErro(e instanceof ApiError ? e.message : 'Falha na operação.')
    } finally {
      setOcupado(false)
    }
  }

  if (erro && !dados) return <p className="text-rust mt-4 text-sm font-bold">{erro}</p>
  if (!dados) return <p className="text-ink-soft mt-4 text-sm">Carregando…</p>

  return (
    <div className="mt-5 space-y-5" data-tela="federacao">
      {recibo && (
        <p className="text-rust text-sm font-bold" data-recibo>
          {recibo}
        </p>
      )}
      {erro && <p className="text-rust text-sm font-bold">{erro}</p>}

      {!dados.federation ? (
        <SemFederacao
          diretorio={diretorio}
          pendentes={dados.pending_invites}
          ocupado={ocupado}
          agir={agir}
        />
      ) : (
        <ComFederacao dados={dados} colonias={colonias} veiculos={veiculos} ocupado={ocupado} agir={agir} />
      )}
    </div>
  )
}

function SemFederacao({
  diretorio,
  pendentes,
  ocupado,
  agir,
}: {
  diretorio: FederationListItem[]
  pendentes: FederationInviteDto[]
  ocupado: boolean
  agir: (acao: () => Promise<unknown>, mensagem: string) => Promise<void>
}) {
  const [nome, setNome] = useState('')

  return (
    <>
      <section className="space-y-2">
        <div className="text-rust eyebrow">Fundar uma federação</div>
        <form
          className="flex gap-2"
          onSubmit={(e) => {
            e.preventDefault()
            if (!nome.trim()) return
            void agir(() => api.fundarFederacao(nome.trim()), `Federação «${nome.trim()}» fundada. Você é o Líder.`)
            setNome('')
          }}
        >
          <input
            type="text"
            value={nome}
            onChange={(e) => setNome(e.target.value)}
            placeholder="Nome da federação"
            maxLength={60}
            className="border-ink/20 flex-1 rounded border px-3 py-2 text-sm"
            data-campo-nome-federacao
          />
          <button
            type="submit"
            disabled={ocupado || !nome.trim()}
            className="bg-rust text-sand-light rounded px-4 py-2 text-sm font-bold disabled:opacity-40"
          >
            Fundar
          </button>
        </form>
      </section>

      {pendentes.length > 0 && (
        <section className="space-y-2">
          <div className="text-rust eyebrow">Seus convites e pedidos</div>
          {pendentes.map((p) => (
            <div key={p.id} className="border-ink/10 flex items-center justify-between rounded border p-3">
              <div>
                <p className="text-ink text-sm">
                  {p.kind === 'convite' ? 'Convite de' : 'Seu pedido a'}{' '}
                  <b>{p.federation?.name ?? '—'}</b>
                </p>
                <p className="text-ink-soft text-xs">
                  {p.kind === 'convite' ? 'Aceite para entrar.' : 'Aguardando o Líder decidir.'}
                </p>
              </div>
              {p.kind === 'convite' ? (
                <div className="flex gap-2">
                  <button
                    disabled={ocupado}
                    onClick={() => void agir(() => api.aceitarConviteDeFederacao(p.id), 'Você entrou na federação.')}
                    className="bg-rust text-sand-light rounded px-3 py-1 text-xs font-bold disabled:opacity-40"
                  >
                    Aceitar
                  </button>
                  <button
                    disabled={ocupado}
                    onClick={() => void agir(() => api.recusarConviteDeFederacao(p.id), 'Convite recusado.')}
                    className="text-ink-soft rounded border px-3 py-1 text-xs disabled:opacity-40"
                  >
                    Recusar
                  </button>
                </div>
              ) : (
                <button
                  disabled={ocupado}
                  onClick={() => void agir(() => api.cancelarConviteDeFederacao(p.id), 'Pedido cancelado.')}
                  className="text-ink-soft rounded border px-3 py-1 text-xs disabled:opacity-40"
                >
                  Desistir
                </button>
              )}
            </div>
          ))}
        </section>
      )}

      <section className="space-y-2">
        <div className="text-rust eyebrow">Pedir para entrar numa federação</div>
        {diretorio.length === 0 && <p className="text-ink-soft text-sm">Nenhuma federação fundada ainda.</p>}
        {diretorio.map((f) => (
          <div key={f.id} className="border-ink/10 flex items-center justify-between rounded border p-3">
            <div>
              <p className="text-ink text-sm font-bold">{f.name}</p>
              <p className="text-ink-soft text-xs">
                {f.membros} de 12 colônias {f.cheia && '· cheia'}
              </p>
            </div>
            <button
              disabled={ocupado || f.cheia}
              onClick={() => void agir(() => api.pedirEntradaNaFederacao(f.id), `Pedido enviado a ${f.name}.`)}
              className="bg-rust text-sand-light rounded px-3 py-1 text-xs font-bold disabled:opacity-40"
            >
              Pedir para entrar
            </button>
          </div>
        ))}
      </section>
    </>
  )
}

function ComFederacao({
  dados,
  colonias,
  veiculos,
  ocupado,
  agir,
}: {
  dados: MinhaFederacao
  colonias: ColoniaVizinha[]
  veiculos: Veiculo[]
  ocupado: boolean
  agir: (acao: () => Promise<unknown>, mensagem: string) => Promise<void>
}) {
  const { federation, my_role, members, fund, pending_invites } = dados
  const podeConvidar = my_role === 'lider' || my_role === 'diplomata'
  const podeSacar = my_role === 'lider' || my_role === 'intendente'
  const ehLider = my_role === 'lider'

  const [alvoConvite, setAlvoConvite] = useState('')
  const [veiculoEscolhido, setVeiculoEscolhido] = useState('')
  const [recursoContribuir, setRecursoContribuir] = useState(NEGOCIAVEIS[0])
  const [qtdContribuir, setQtdContribuir] = useState('')
  const [recursoSacar, setRecursoSacar] = useState('')
  const [qtdSacar, setQtdSacar] = useState('')
  const [transferirPara, setTransferirPara] = useState('')
  const [confirmandoSaida, setConfirmandoSaida] = useState(false)
  const [palavraSaida, setPalavraSaida] = useState('')

  const veiculosEmCasa = veiculos.filter((v) => v.local === 'colonia' && v.status === 'ocioso')
  const colonoDoAlvo = (id: number) => colonias.find((c) => c.id === id)

  return (
    <>
      <section>
        <div className="text-rust eyebrow">{federation?.name}</div>
        <p className="text-ink-soft text-sm">
          Seu cargo: <b>{my_role ? NOME_CARGO[my_role] : '—'}</b>
        </p>
      </section>

      <section className="space-y-2">
        <h3 className="text-ink font-black">Membros</h3>
        <div className="border-ink/10 divide-ink/10 divide-y rounded border">
          {members.map((m) => (
            <div key={m.colony_id} className="flex items-center justify-between p-3">
              <div>
                <p className="text-ink text-sm">{m.name}</p>
                <p className="text-ink-soft text-xs">{NOME_CARGO[m.role]}</p>
              </div>
              {m.colony_id !== members.find((x) => x.role === 'lider')?.colony_id && (
                <div className="flex gap-2">
                  {ehLider && (
                    <select
                      disabled={ocupado}
                      defaultValue={m.role}
                      onChange={(e) =>
                        void agir(
                          () => api.alterarCargoNaFederacao(m.colony_id, e.target.value),
                          `${m.name} agora é ${NOME_CARGO[e.target.value as FederationRole]}.`,
                        )
                      }
                      className="border-ink/20 rounded border px-2 py-1 text-xs"
                    >
                      <option value="diplomata">Diplomata</option>
                      <option value="intendente">Intendente</option>
                      <option value="membro">Membro</option>
                    </select>
                  )}
                  {podeConvidar && (
                    <button
                      disabled={ocupado}
                      onClick={() => void agir(() => api.expulsarDaFederacao(m.colony_id), `${m.name} foi expulso.`)}
                      className="text-rust rounded border px-2 py-1 text-xs disabled:opacity-40"
                    >
                      Expulsar
                    </button>
                  )}
                </div>
              )}
            </div>
          ))}
        </div>

        {ehLider && (
          <form
            className="flex gap-2"
            onSubmit={(e) => {
              e.preventDefault()
              const alvo = Number(transferirPara)
              if (!alvo) return
              const nome = colonoDoAlvo(alvo)?.nickname ?? 'a colônia'
              void agir(() => api.transferirLiderancaDaFederacao(alvo), `Liderança transferida a ${nome}.`)
              setTransferirPara('')
            }}
          >
            <select
              value={transferirPara}
              onChange={(e) => setTransferirPara(e.target.value)}
              className="border-ink/20 flex-1 rounded border px-3 py-2 text-sm"
            >
              <option value="">Transferir liderança para…</option>
              {members
                .filter((m) => m.role !== 'lider')
                .map((m) => (
                  <option key={m.colony_id} value={m.colony_id}>
                    {m.name}
                  </option>
                ))}
            </select>
            <button
              type="submit"
              disabled={ocupado || !transferirPara}
              className="bg-rust text-sand-light rounded px-4 py-2 text-sm font-bold disabled:opacity-40"
            >
              Transferir
            </button>
          </form>
        )}
      </section>

      <section className="space-y-2">
        <h3 className="text-ink font-black">Fundo</h3>
        <div className="border-ink/10 rounded border p-3">
          {fund.length === 0 ? (
            <p className="text-ink-soft text-sm">Vazio — ninguém contribuiu ainda.</p>
          ) : (
            <ul className="text-ink space-y-1 text-sm">
              {fund.map((f) => (
                <li key={f.resource_type} data-fundo={f.resource_type}>
                  {nomeRecurso(f.resource_type)}: <b>{f.amount.toLocaleString('pt-BR')}</b>
                </li>
              ))}
            </ul>
          )}
        </div>

        <p className="text-ink-soft text-xs">
          Contribuir é uma entrega física, como qualquer despacho: escolha um veículo em casa, o
          recurso e a quantidade — o tributo incide normalmente na chegada.
        </p>
        <form
          className="flex flex-wrap gap-2"
          onSubmit={(e) => {
            e.preventDefault()
            const qtd = Number(qtdContribuir)
            if (!veiculoEscolhido || !qtd || qtd <= 0) return
            void agir(
              () => api.contribuirParaFederacao(Number(veiculoEscolhido), { [recursoContribuir]: qtd }),
              'Veículo despachado para o Quartel de Alianças.',
            )
            setQtdContribuir('')
          }}
        >
          <select
            value={veiculoEscolhido}
            onChange={(e) => setVeiculoEscolhido(e.target.value)}
            className="border-ink/20 rounded border px-3 py-2 text-sm"
            data-campo-veiculo-contribuir
          >
            <option value="">Veículo…</option>
            {veiculosEmCasa.map((v) => (
              <option key={v.id} value={v.id}>
                {v.nickname ?? v.plate ?? nomeVeiculo(v.type)}
              </option>
            ))}
          </select>
          <select
            value={recursoContribuir}
            onChange={(e) => setRecursoContribuir(e.target.value)}
            className="border-ink/20 rounded border px-3 py-2 text-sm"
          >
            {NEGOCIAVEIS.map((r) => (
              <option key={r} value={r}>
                {nomeRecurso(r)}
              </option>
            ))}
          </select>
          <input
            type="number"
            min={1}
            value={qtdContribuir}
            onChange={(e) => setQtdContribuir(e.target.value)}
            placeholder="Quantidade"
            className="border-ink/20 w-28 rounded border px-3 py-2 text-sm"
          />
          <button
            type="submit"
            disabled={ocupado || veiculosEmCasa.length === 0}
            className="bg-rust text-sand-light rounded px-4 py-2 text-sm font-bold disabled:opacity-40"
          >
            Contribuir
          </button>
        </form>
        {veiculosEmCasa.length === 0 && (
          <p className="text-ink-soft text-xs">Nenhum veículo ocioso em casa agora.</p>
        )}

        {podeSacar && (
          <form
            className="flex flex-wrap gap-2 pt-2"
            onSubmit={(e) => {
              e.preventDefault()
              const qtd = Number(qtdSacar)
              if (!recursoSacar || !qtd || qtd <= 0) return
              void agir(
                () => api.sacarDoFundoDaFederacao(recursoSacar, qtd),
                `${qtd.toLocaleString('pt-BR')} de ${nomeRecurso(recursoSacar)} sacado para a sua colônia.`,
              )
              setQtdSacar('')
            }}
          >
            <select
              value={recursoSacar}
              onChange={(e) => setRecursoSacar(e.target.value)}
              className="border-ink/20 rounded border px-3 py-2 text-sm"
            >
              <option value="">Sacar recurso…</option>
              {fund.map((f) => (
                <option key={f.resource_type} value={f.resource_type}>
                  {nomeRecurso(f.resource_type)} ({f.amount.toLocaleString('pt-BR')})
                </option>
              ))}
            </select>
            <input
              type="number"
              min={1}
              value={qtdSacar}
              onChange={(e) => setQtdSacar(e.target.value)}
              placeholder="Quantidade"
              className="border-ink/20 w-28 rounded border px-3 py-2 text-sm"
            />
            <button
              type="submit"
              disabled={ocupado || fund.length === 0}
              className="rounded border px-4 py-2 text-sm font-bold disabled:opacity-40"
            >
              Sacar
            </button>
          </form>
        )}
      </section>

      {podeConvidar && (
        <section className="space-y-2">
          <h3 className="text-ink font-black">Convidar uma colônia</h3>
          <form
            className="flex gap-2"
            onSubmit={(e) => {
              e.preventDefault()
              const alvo = Number(alvoConvite)
              if (!alvo || !federation) return
              const nome = colonoDoAlvo(alvo)?.nickname ?? 'a colônia'
              void agir(() => api.convidarParaFederacao(federation.id, alvo), `Convite enviado a ${nome}.`)
              setAlvoConvite('')
            }}
          >
            <select
              value={alvoConvite}
              onChange={(e) => setAlvoConvite(e.target.value)}
              className="border-ink/20 flex-1 rounded border px-3 py-2 text-sm"
            >
              <option value="">Escolha uma colônia…</option>
              {colonias
                .filter((c) => !members.some((m) => m.colony_id === c.id))
                .map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.nickname} · a {c.distance} slots
                  </option>
                ))}
            </select>
            <button
              type="submit"
              disabled={ocupado || !alvoConvite}
              className="bg-rust text-sand-light rounded px-4 py-2 text-sm font-bold disabled:opacity-40"
            >
              Convidar
            </button>
          </form>
        </section>
      )}

      {podeConvidar && pending_invites.length > 0 && (
        <section className="space-y-2">
          <h3 className="text-ink font-black">Convites e pedidos pendentes</h3>
          {pending_invites.map((p) => (
            <div key={p.id} className="border-ink/10 flex items-center justify-between rounded border p-3">
              <p className="text-ink text-sm">
                {p.kind === 'convite' ? 'Convite enviado a' : 'Pedido de entrada de'} <b>{p.colony?.name ?? '—'}</b>
              </p>
              <div className="flex gap-2">
                {p.kind === 'pedido' && (
                  <button
                    disabled={ocupado}
                    onClick={() =>
                      void agir(() => api.aceitarConviteDeFederacao(p.id), `${p.colony?.name} entrou na federação.`)
                    }
                    className="bg-rust text-sand-light rounded px-3 py-1 text-xs font-bold disabled:opacity-40"
                  >
                    Aceitar
                  </button>
                )}
                <button
                  disabled={ocupado}
                  onClick={() =>
                    void agir(
                      () =>
                        p.kind === 'pedido'
                          ? api.recusarConviteDeFederacao(p.id)
                          : api.cancelarConviteDeFederacao(p.id),
                      p.kind === 'pedido' ? 'Pedido recusado.' : 'Convite cancelado.',
                    )
                  }
                  className="text-ink-soft rounded border px-3 py-1 text-xs disabled:opacity-40"
                >
                  {p.kind === 'pedido' ? 'Recusar' : 'Cancelar'}
                </button>
              </div>
            </div>
          ))}
        </section>
      )}

      <section>
        {!confirmandoSaida ? (
          <button
            disabled={ocupado}
            onClick={() => setConfirmandoSaida(true)}
            className="text-rust rounded border px-4 py-2 text-sm font-bold disabled:opacity-40"
            data-sair-federacao
          >
            Sair da federação
          </button>
        ) : (
          <div className="border-rust/40 max-w-sm space-y-2 rounded border p-3">
            <p className="text-ink text-sm">
              {members.length === 1
                ? 'Você é a única colônia — sair dissolve a federação.'
                : 'Tem certeza que quer sair da federação?'}
            </p>
            <label className="text-ink-soft block text-xs">
              Escreva <span className="text-rust font-bold">SAIR</span> para confirmar:
              <input
                value={palavraSaida}
                onChange={(e) => setPalavraSaida(e.target.value)}
                autoFocus
                data-palavra-sair
                className="border-ink/20 mt-1 w-full rounded border px-2 py-1 font-mono text-sm"
              />
            </label>
            <div className="flex gap-2">
              <button
                disabled={ocupado || palavraSaida !== 'SAIR'}
                onClick={() => {
                  void agir(
                    () => api.sairDaFederacao(palavraSaida),
                    members.length === 1 ? 'Você saiu — a federação foi dissolvida.' : 'Você saiu da federação.',
                  )
                  setConfirmandoSaida(false)
                  setPalavraSaida('')
                }}
                data-confirmar-sair-federacao
                className="bg-rust text-sand-light rounded px-4 py-2 text-sm font-bold disabled:opacity-40"
              >
                Sair mesmo assim
              </button>
              <button
                onClick={() => {
                  setConfirmandoSaida(false)
                  setPalavraSaida('')
                }}
                className="text-ink-soft rounded border px-4 py-2 text-sm"
              >
                Cancelar
              </button>
            </div>
          </div>
        )}
        {ehLider && members.length > 1 && (
          <p className="text-ink-soft mt-1 text-xs">
            Você é o Líder: transfira a liderança antes de sair, ou o pedido será recusado.
          </p>
        )}
      </section>
    </>
  )
}
