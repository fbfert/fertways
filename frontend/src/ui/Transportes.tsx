import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from '../api/client'
import type { RegistroVeiculo, Transportes as TransportesDto } from '../api/client'
import { dataHumana, nomeRecurso, nomeVeiculo } from './recursos'
import { Usados } from './Usados'

/**
 * Ministério dos Transportes (§16) — slot 8 da Capital (D-60).
 *
 * Três coisas que o jogador não tem como adivinhar, e que a tela existe para dizer:
 *
 *  1. **Só aqui se compra caminhão.** A Central de Transportes dele não fabrica mais nada — e como
 *     o GDD (§17.2) diz que ela "produz Caminhões de Carga", quem ler o documento vai procurá-la na
 *     colônia e não achar.
 *  2. **De onde vem o teto de frota**: é o nível da Central dele.
 *  3. **O que o desgaste faz.** Um número de conservação sozinho não diz nada. O que importa é que
 *     velocidade e capacidade encolhem junto — e é isso que a linha do registro mostra.
 *
 * Os **usados** mudaram para cá no D-65. Eles moravam no Mercado, e não tinham por que: o Mercado
 * passou a ser o lugar do **recurso** (o Local, entre colonos; o Central, no governo), e veículo é
 * assunto do Ministério — é ele o cartório da placa (§16.3), e é aqui que se compra o novo, se
 * repara e se sucateia. O usado ao lado do novo.
 */
export function Transportes() {
  const [dados, setDados] = useState<TransportesDto | null>(null)
  const [erro, setErro] = useState<string | null>(null)
  const [ocupado, setOcupado] = useState(false)
  const [recibo, setRecibo] = useState<string | null>(null)

  const carregar = useCallback(async () => {
    try {
      setDados(await api.transportes())
    } catch (e) {
      setErro(e instanceof ApiError ? e.message : 'Falha ao carregar o Ministério dos Transportes.')
    }
  }, [])

  useEffect(() => {
    void carregar()
  }, [carregar])

  /** Todo ato desta tela tem a mesma forma: age, conta o que houve, recarrega. */
  async function agir(acao: () => Promise<string>) {
    setOcupado(true)
    setErro(null)
    setRecibo(null)

    try {
      setRecibo(await acao())
      await carregar()
    } catch (e) {
      setErro(e instanceof ApiError ? e.message : 'Falha na operação.')
    } finally {
      setOcupado(false)
    }
  }

  if (erro && !dados) return <p className="text-rust mt-4 text-sm font-bold">{erro}</p>
  if (!dados) return <p className="text-ink-soft mt-4 text-sm">Carregando…</p>

  const { caminhao, frota, veiculos, planeta } = dados
  const semVaga = frota.livres < 1
  const semEstoque = caminhao.em_estoque < 1

  return (
    <div className="mt-5 space-y-5" data-tela="transportes">
      {/* ---------------------------------------------------------------- a fábrica */}
      <section className="border-rust/20 bg-sand border p-4">
        <div className="flex items-start justify-between gap-4">
          <div>
            <div className="text-rust eyebrow">Fábrica do Governo</div>
            <h3 className="text-ink text-lg font-black">Caminhão de Carga</h3>
            <p className="text-ink-soft mt-1 text-sm">
              {caminhao.capacidade.toLocaleString('pt-BR')} unidades por viagem — cinco vezes o Furgão.
            </p>
          </div>
          <div className="text-right">
            <div className="text-ink text-2xl font-black">{caminhao.preco_fert} F$</div>
            <div className="text-ink-soft/70 text-xs">preço do governo</div>
          </div>
        </div>

        <p className="text-ink-soft/70 mt-3 text-xs">
          Fabricar caminhão é <strong>privativo deste Ministério</strong>. A sua Central de
          Transportes não os produz — ela define quantos veículos você pode ter.
        </p>

        <div className="mt-3 flex items-center gap-4 text-sm">
          <span className="text-ink" data-estoque={caminhao.em_estoque}>
            <strong>{caminhao.em_estoque}</strong> na prateleira
          </span>
          <span className="text-ink-soft/70">{caminhao.em_fabricacao} na linha de montagem</span>
        </div>

        {recibo && <p className="text-rust mt-3 text-sm font-bold">{recibo}</p>}
        {erro && <p className="text-rust mt-3 text-sm font-bold">{erro}</p>}

        <button
          onClick={() =>
            agir(async () => {
              const { comprado } = await api.comprarCaminhao()
              return `Caminhão ${comprado.placa} é seu. Ele vem dirigindo da Capital.`
            })
          }
          disabled={semVaga || semEstoque || ocupado}
          data-comprar-caminhao
          className="bg-rust text-sand-light hover:bg-rust-bright disabled:bg-ink-soft/30 mt-3 px-5 py-2 text-sm font-bold disabled:cursor-not-allowed"
        >
          {ocupado ? 'Aguarde…' : `Comprar por ${caminhao.preco_fert} F$`}
        </button>

        {/* Botão desabilitado sem explicação é a pior tela possível: diga POR QUE. */}
        {semVaga && (
          <p className="text-ink-soft/80 mt-2 text-xs">
            A sua frota está no teto de {frota.teto}. Suba a Central de Transportes para abrir vaga.
          </p>
        )}
        {semEstoque && !semVaga && (
          <p className="text-ink-soft/80 mt-2 text-xs">
            O governo está sem caminhão pronto — {caminhao.minutos_fabricacao} min por unidade na
            linha de montagem.
          </p>
        )}
      </section>

      {/* ---------------------------------------------------------------- o registro de placas */}
      <section>
        <div className="flex items-baseline justify-between">
          <h3 className="text-ink font-black">Registro de Placas</h3>
          <span className="text-ink-soft text-sm" data-vagas={frota.livres}>
            {frota.ocupadas} de {frota.teto} vagas
          </span>
        </div>
        <p className="text-ink-soft/70 mt-1 text-xs">{frota.regra}</p>

        <ul className="mt-3 space-y-2">
          {veiculos.map((v) => (
            <LinhaDoRegistro key={v.id} v={v} ocupado={ocupado} agir={agir} />
          ))}
        </ul>
      </section>

      {/* ---------------------------------------------------------------- os usados (D-60, D-65) */}
      <section className="border-rust/20 border-t pt-4">
        <Usados />
      </section>

      {/* ---------------------------------------------------------------- o resumo do planeta */}
      <section className="border-rust/20 border-t pt-3">
        <div className="text-ink-soft/70 text-xs">
          No planeta: <strong data-registrados={planeta.veiculos_registrados}>{planeta.veiculos_registrados}</strong>{' '}
          veículos registrados · {planeta.vendidos} vendidos · {planeta.sucateados} sucateados.
        </div>
      </section>
    </div>
  )
}

/**
 * Uma linha do registro (§16.3): placa, tipo, horas de uso, estado.
 *
 * A conservação e o **desempenho** são números diferentes de propósito, e a linha mostra os dois
 * quando divergem: abaixo do piso de 25% o veículo continua andando a 25%, porque o D-60 decidiu que
 * ele nunca trava. Esconder isso faria o jogador achar que um caminhão a 5% está morto.
 */
function LinhaDoRegistro({
  v,
  ocupado,
  agir,
}: {
  v: RegistroVeiculo
  ocupado: boolean
  agir: (acao: () => Promise<string>) => Promise<void>
}) {
  const [confirmarSucata, setConfirmarSucata] = useState(false)
  const emRota = v.status === 'em_rota'

  return (
    <li className="border-rust/20 bg-sand border p-3" data-veiculo={v.id}>
      <div className="flex items-center gap-3">
        <span className="bg-ink text-sand-light shrink-0 px-2 py-1 font-mono text-xs font-bold">
          {v.placa ?? '—'}
        </span>
        <div className="min-w-0 flex-1">
          <div className="text-ink text-sm font-bold">{nomeVeiculo(v.tipo)}</div>
          <div className="text-ink-soft/70 text-xs">
            {emRota
              ? `em rota — chega ${v.chega_em ? dataHumana(v.chega_em) : 'em breve'}`
              : `no pátio · ${v.horas_de_uso} h de uso`}
          </div>
        </div>
        {v.deprecia && (
          <div className="shrink-0 text-right">
            <div className="text-ink text-sm font-black" data-conservacao={v.conservacao}>
              {v.conservacao.toFixed(0)}%
            </div>
            <div className="text-ink-soft/60 text-xs">conservação</div>
          </div>
        )}
      </div>

      {v.deprecia && (
        <>
          {/* A barra é o que faz o número virar sensação. */}
          <div className="bg-ink/10 mt-2 h-1.5 w-full">
            <div
              className={v.conservacao < 40 ? 'bg-rust h-full' : 'bg-ink-soft h-full'}
              style={{ width: `${Math.max(2, v.conservacao)}%` }}
            />
          </div>

          <div className="text-ink-soft/70 mt-2 text-xs">
            Anda a <strong>{v.desempenho.toFixed(0)}%</strong> e carrega{' '}
            <strong>{v.capacidade_efetiva.toLocaleString('pt-BR')}</strong>.
            {v.desempenho > v.conservacao && ' Está no piso: ele nunca para de andar.'}
            {v.manutencoes > 0 && ` · ${v.manutencoes} manutenção(ões) — teto em ${v.teto_conservacao.toFixed(0)}%.`}
            {v.teto_de_revenda_fert !== null && ` · revende por até ${v.teto_de_revenda_fert} F$.`}
          </div>

          {!emRota && (
            <div className="mt-2 flex flex-wrap items-center gap-2">
              <button
                onClick={() =>
                  agir(async () => {
                    const { veiculo } = await api.repararVeiculo(v.id)
                    return `${veiculo.placa} reparado — voltou a ${veiculo.conservacao.toFixed(0)}%, e o teto caiu para ${veiculo.teto_conservacao.toFixed(0)}%.`
                  })
                }
                disabled={!v.pode_reparar || ocupado}
                data-reparar={v.id}
                className="border-rust/40 text-rust hover:bg-rust hover:text-sand-light disabled:border-ink-soft/20 disabled:text-ink-soft/40 border px-3 py-1 text-xs font-bold disabled:cursor-not-allowed disabled:hover:bg-transparent"
              >
                Manutenção
              </button>

              {v.custo_manutencao && (
                <span className="text-ink-soft/60 text-xs">
                  {Object.entries(v.custo_manutencao)
                    .map(([r, q]) => `${q} ${nomeRecurso(r)}`)
                    .join(' · ')}
                  {' — na sua Central de Transportes'}
                </span>
              )}

              {!v.pode_reparar && v.conservacao >= v.teto_conservacao && (
                <span className="text-ink-soft/60 text-xs">Já está no teto dele.</span>
              )}

              {confirmarSucata ? (
                <span className="ml-auto flex items-center gap-2">
                  <span className="text-rust text-xs font-bold">Sucatear? Não volta, e nada é devolvido.</span>
                  <button
                    onClick={() =>
                      agir(async () => {
                        await api.sucatearVeiculo(v.id)
                        return `${v.placa} foi sucateado. A vaga está livre.`
                      })
                    }
                    disabled={ocupado}
                    data-sucatear={v.id}
                    className="bg-rust text-sand-light px-3 py-1 text-xs font-bold"
                  >
                    Sim
                  </button>
                  <button
                    onClick={() => setConfirmarSucata(false)}
                    className="text-ink-soft hover:text-ink px-2 py-1 text-xs"
                  >
                    Não
                  </button>
                </span>
              ) : (
                <button
                  onClick={() => setConfirmarSucata(true)}
                  className="text-ink-soft/60 hover:text-rust ml-auto text-xs"
                >
                  Sucatear
                </button>
              )}
            </div>
          )}
        </>
      )}
    </li>
  )
}
