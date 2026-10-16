import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from '../api/client'
import type { ContratoDeRota, EstadoEspacoporto, PlanetaNpc } from '../api/client'
import { dataHumana, nomeRecurso } from './recursos'
import { Botao } from './sistema'
import { DuvidasDoEspacoporto } from './DuvidasDoEspacoporto'

/**
 * O Espaçoporto — a área Sul da Capital (D-63, D-262).
 *
 * **Conta a verdade**, como sempre contou: os cinco planetas são do GDD (§03), e agora vêm do banco
 * em vez de transcritos aqui. O que mudou é que as rotas **podem** abrir — mas cada número de
 * economia (preço em cada planeta, aluguel e capacidade do Cargueiro, frota) é do operador, e nasce
 * vazio. Enquanto falta um, a tela diz qual.
 */
const fert = (micro: number) =>
  (micro / 1_000_000).toLocaleString('pt-BR', { maximumFractionDigits: 6 })

const pct = (bps: number) => `${(bps / 100).toLocaleString('pt-BR')}%`

const horas = (min: number) =>
  min >= 60 ? `${(min / 60).toLocaleString('pt-BR', { maximumFractionDigits: 1 })} h` : `${min} min`

export function Espacoporto() {
  const [estado, setEstado] = useState<EstadoEspacoporto | null>(null)
  const [erro, setErro] = useState<string | null>(null)
  const [aviso, setAviso] = useState<string | null>(null)

  const carregar = useCallback(async () => {
    try {
      setEstado(await api.espacoporto())
      setErro(null)
    } catch (e) {
      setErro(e instanceof ApiError ? e.message : 'Não foi possível abrir o Espaçoporto.')
    }
  }, [])

  useEffect(() => {
    void carregar()
  }, [carregar])

  if (erro) return <p className="text-rust mt-5 text-sm">{erro}</p>
  if (!estado) return <p className="text-ink-soft mt-5 text-sm">Abrindo o Espaçoporto…</p>

  const emViagem = estado.contratos.filter((k) => k.status === 'em_viagem')
  const voltaram = estado.contratos.filter((k) => k.status === 'concluido').slice(0, 5)

  return (
    <div className="mt-5 space-y-5" data-tela="espacoporto">
      {!estado.ligado ? (
        <section className="border-rust/40 bg-sand-light border border-dashed p-4" data-espacoporto-fechado>
          <div className="text-rust eyebrow">As rotas não abriram</div>
          <p className="text-ink-soft mt-1 text-sm leading-relaxed">
            <b>Ninguém viaja daqui ainda.</b> {estado.motivo_fechado} Viajar é alugar o{' '}
            <b>Cargueiro Interplanetário</b> — frota do Governo, que o GDD não deixa ninguém fabricar.
          </p>
        </section>
      ) : (
        <section className="border-rust/20 bg-sand border p-4" data-espacoporto-aberto>
          <div className="text-rust eyebrow">O Cargueiro está alugando</div>
          <p className="text-ink-soft mt-1 text-sm leading-relaxed">
            Aluguel de <b>{fert(estado.aluguel_micro ?? 0)} Fert$</b> por viagem, até{' '}
            <b>{(estado.capacidade_unidades ?? 0).toLocaleString('pt-BR')} unidades</b> em cada perna.{' '}
            {estado.cargueiros_livres > 0
              ? `${estado.cargueiros_livres} de ${estado.cargueiros} cargueiro(s) no chão.`
              : 'Todos os cargueiros estão no ar — espere um pousar.'}
          </p>
          {!estado.portao.pode && (
            <p className="text-rust mt-2 text-sm" data-portao-cargueiro>
              O Cargueiro abre no marco {estado.portao.marco_exigido}, com {estado.portao.dias_exigidos} dias
              de colônia. Você está no marco {estado.portao.marco}, com {estado.portao.dias} dia(s).
            </p>
          )}
        </section>
      )}

      {aviso && <p className="text-ink text-sm">{aviso}</p>}

      {emViagem.length > 0 && (
        <section>
          <h3 className="text-ink font-black">Seus cargueiros no ar</h3>
          <ul className="mt-2 space-y-1 text-sm">
            {emViagem.map((k) => (
              <Viagem key={k.id} contrato={k} />
            ))}
          </ul>
        </section>
      )}

      {voltaram.length > 0 && (
        <section>
          <h3 className="text-ink font-black">Últimas viagens</h3>
          <ul className="mt-2 space-y-1 text-sm">
            {voltaram.map((k) => (
              <li key={k.id} className="text-ink-soft" data-viagem-concluida={k.id}>
                <b className="text-ink">{k.planeta}</b> —{' '}
                {(k.sinistro_bps ?? 0) > 0 ? (
                  <span className="text-rust font-bold">
                    sinistro na viagem: perdeu {pct(k.sinistro_bps ?? 0)} da carga
                  </span>
                ) : (
                  'chegou inteiro'
                )}
                . Imposto: {fert(k.imposto_importacao_micro + (k.imposto_venda_micro ?? 0))} Fert$.
              </li>
            ))}
          </ul>
        </section>
      )}

      <section>
        <h3 className="text-ink font-black">Os cinco planetas</h3>
        <ul className="mt-3 space-y-2">
          {estado.planetas.map((p) => (
            <Planeta
              key={p.chave}
              planeta={p}
              podeContratar={estado.ligado && p.portao.pode && estado.cargueiros_livres > 0}
              capacidade={estado.capacidade_unidades ?? 0}
              aoContratar={async (venda, compra) => {
                try {
                  const { contrato } = await api.contratarRota(p.chave, venda, compra)
                  setAviso(`O Cargueiro partiu para ${p.nome} — pousa em ${dataHumana(contrato.chega_em)}.`)
                  await carregar()
                } catch (e) {
                  setAviso(e instanceof ApiError ? e.message : 'O contrato não fechou.')
                }
              }}
            />
          ))}
        </ul>
      </section>

      <p className="text-ink-soft/60 text-xs" data-imposto-interplanetario>
        A carga paga imposto como no Mercado Central: a encomenda, na partida; a venda, na volta, sobre o
        que o planeta pagou. As peças de alfândega da Endurance dão desconto. Nos planetas com risco, um
        sinistro na viagem pode levar parte da carga das duas pernas — o que se pagou não volta.
      </p>

      <DuvidasDoEspacoporto />
    </div>
  )
}

function Viagem({ contrato: k }: { contrato: ContratoDeRota }) {
  const leva = Object.entries(k.venda).map(([r, v]) => `${v.qtd} ${nomeRecurso(r)}`)
  const traz = Object.entries(k.compra).map(([r, v]) => `${v.qtd} ${nomeRecurso(r)}`)

  return (
    <li className="text-ink-soft" data-contrato={k.id}>
      <b className="text-ink">{k.planeta}</b> — leva {leva.join(', ') || 'nada'}, traz{' '}
      {traz.join(', ') || 'nada'}. Pousa em {dataHumana(k.chega_em)}.
      {k.imposto_importacao_micro > 0 && ` Imposto de importação pago: ${fert(k.imposto_importacao_micro)} Fert$.`}
    </li>
  )
}

function Planeta({
  planeta: p,
  podeContratar,
  capacidade,
  aoContratar,
}: {
  planeta: PlanetaNpc
  podeContratar: boolean
  capacidade: number
  aoContratar: (venda: Record<string, number>, compra: Record<string, number>) => Promise<void>
}) {
  const [venda, setVenda] = useState<Record<string, number>>({})
  const [compra, setCompra] = useState<Record<string, number>>({})
  const [ocupado, setOcupado] = useState(false)
  const negocia = Object.keys(p.compra).length + Object.keys(p.venda).length > 0
  const aberto = p.aberto && negocia

  return (
    <li className="border-rust/20 bg-sand border p-3" data-planeta={p.nome}>
      <div className="flex items-baseline justify-between gap-3">
        <div className="text-ink font-black">{p.nome}</div>
        <div className="text-ink-soft shrink-0 text-sm">
          {horas(p.distancia_minutos)} ·{' '}
          <span className={p.risco === 'alto' ? 'text-rust font-bold' : ''}>risco {p.risco}</span>
        </div>
      </div>
      <div className="text-ink-soft/80 mt-1 text-xs">
        <b>Exporta:</b> {p.exporta} · <b>Importa:</b> {p.importa}
      </div>
      {p.risco_chance_bps > 0 && (
        <div className="text-ink-soft mt-1 text-xs" data-risco={p.chave}>
          Em {pct(p.risco_chance_bps)} das viagens há sinistro, e ele leva {pct(p.risco_perda_bps)} da carga.
        </div>
      )}
      {p.aberto && !p.portao.pode && p.portao.falta && (
        <div className="text-rust mt-1 text-xs" data-portao-planeta={p.chave}>
          {p.portao.falta}
        </div>
      )}

      {!aberto ? (
        <div className="text-ink-soft/60 mt-2 text-xs">Rota fechada.</div>
      ) : (
        <div className="mt-3 grid gap-3 sm:grid-cols-2">
          <Perna
            titulo="Levar para vender"
            precos={p.compra}
            valores={venda}
            mudar={setVenda}
            rotuloPreco="paga"
          />
          <Perna
            titulo="Encomendar"
            precos={p.venda}
            valores={compra}
            mudar={setCompra}
            rotuloPreco="cobra"
          />
          {podeContratar && (
            <div className="sm:col-span-2">
              <Botao
                variante="secundaria"
                tamanho="pequeno"
                disabled={ocupado}
                data-contratar={p.chave}
                onClick={async () => {
                  setOcupado(true)
                  await aoContratar(venda, compra)
                  setVenda({})
                  setCompra({})
                  setOcupado(false)
                }}
              >
                Alugar o Cargueiro para {p.nome}
              </Botao>
              <span className="text-ink-soft/70 ml-2 text-xs">
                até {capacidade.toLocaleString('pt-BR')} unidades em cada perna; o preço fica travado na partida.
              </span>
            </div>
          )}
        </div>
      )}
    </li>
  )
}

function Perna({
  titulo,
  precos,
  valores,
  mudar,
  rotuloPreco,
}: {
  titulo: string
  precos: Record<string, number>
  valores: Record<string, number>
  mudar: (v: Record<string, number>) => void
  rotuloPreco: string
}) {
  const recursos = Object.keys(precos)

  return (
    <div>
      <div className="eyebrow text-ink-soft">{titulo}</div>
      {recursos.length === 0 ? (
        <div className="text-ink-soft/60 text-xs">Nada, neste planeta.</div>
      ) : (
        <ul className="mt-1 space-y-1">
          {recursos.map((r) => (
            <li key={r} className="flex items-center gap-2 text-xs">
              <input
                type="number"
                min={0}
                value={valores[r] ?? ''}
                onChange={(e) => mudar({ ...valores, [r]: Math.max(0, Number(e.target.value) || 0) })}
                className="border-rust/30 w-20 border px-1 py-0.5 text-right tabular-nums"
              />
              <span className="text-ink">{nomeRecurso(r)}</span>
              <span className="text-ink-soft/70">
                — {rotuloPreco} {fert(precos[r])} Fert$/un.
              </span>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
