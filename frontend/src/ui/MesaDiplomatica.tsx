import { useCallback, useEffect, useState } from 'react'
import { api } from '../api/client'
import type { MesaDiplomatica as Dados } from '../api/client'
import { Botao, Selo } from './sistema'

/**
 * A mesa diplomática (A2.5, item 7).
 *
 * ## O cargo que não tinha sistema
 *
 * *"Diplomata"* existe desde o D-114 e só sabia convidar colônia. Nunca houve tratado nem aliança
 * **entre** federações — o D-174 registrou isso ao fechar a fatia anterior da fase. Esta tela é a
 * porta do sistema que faltava.
 *
 * ## Os dois descontos aparecem juntos, e isso é o desenho
 *
 * Filiar-se rende mais que aliar-se, e o jogador precisa **ver** isso para que a escolha exista. Se
 * a tela mostrasse só "aliança dá desconto", aliar-se pareceria substituto de entrar na federação —
 * e o teto de 12 membros, que existe para limitar concentração, viraria letra morta.
 *
 * ## ⚠️ E o aviso de que o antimonopólio passa a contar o bloco
 *
 * É a consequência que menos se espera e mais importa: aliar-se **aproxima do teto de zonas**,
 * porque o limite do §04 passa a somar as zonas de todas as aliadas. Esconder isso faria a aliança
 * parecer só vantagem, e o jogador descobriria o custo no instante em que uma ocupação fosse negada.
 */
export function MesaDiplomatica() {
  const [d, setD] = useState<Dados | null>(null)
  const [ocupado, setOcupado] = useState(false)
  const [recado, setRecado] = useState<string | null>(null)
  const [alvo, setAlvo] = useState<string>('')

  const carregar = useCallback(() => {
    api
      .mesaDiplomatica()
      .then(setD)
      .catch(() => {})
  }, [])

  useEffect(carregar, [carregar])

  if (!d?.tem_federacao) return null

  const agir = async (fn: () => Promise<unknown>, sucesso: string) => {
    setOcupado(true)
    try {
      await fn()
      setRecado(sucesso)
      carregar()
    } catch (e) {
      setRecado(e instanceof Error ? e.message : 'Não deu.')
    } finally {
      setOcupado(false)
    }
  }

  const noTeto = (d.aliadas ?? 0) >= (d.max_aliadas ?? 0)

  return (
    <section data-secao="diplomacia" className="painel bg-sand mt-4 p-4">
      <h3 className="eyebrow text-rust mb-2">Diplomacia entre federações</h3>

      <p className="text-ink-soft mb-3 text-sm">
        Uma aliança desconta <strong>{d.desconto_alianca}%</strong> do tributo entre as duas
        federações. Filiar-se à mesma federação desconta <strong>{d.desconto_interno}%</strong> —
        aliar-se aproxima, não substitui.
      </p>

      {/*
        O custo da aliança, dito antes de ela ser feita. Ver o docblock: escondê-lo faria a aliança
        parecer só vantagem, e o preço apareceria como uma ocupação negada sem explicação.
      */}
      <p className="text-ink-soft/70 border-ink/10 mb-3 border-l-2 pl-2 text-xs">
        ⚠️ O limite antimonopólio do §04 passa a contar as zonas de <strong>todas as aliadas
        somadas</strong>. Aliar-se aproxima do teto de ocupação.
      </p>

      <div className="text-ink-soft mb-3 text-sm" data-aliadas={d.aliadas}>
        {d.aliadas} de {d.max_aliadas} aliada(s).
      </div>

      {(d.relacoes ?? []).length > 0 && (
        <ul className="mb-3 flex flex-col gap-2">
          {(d.relacoes ?? []).map((r) => (
            <li key={r.id} className="border-ink/10 flex flex-wrap items-center gap-2 border-t pt-2">
              <span className="text-ink font-bold">{r.nome}</span>

              {r.status === 'aceita' ? (
                <Selo estado="sucesso">aliada</Selo>
              ) : (
                <Selo estado="info">{r.propus ? 'proposta enviada' : 'proposta recebida'}</Selo>
              )}

              {/*
                Quem propôs não vê "Aceitar": a trava é do domínio, e repeti-la aqui evita oferecer
                um botão que só serviria para produzir um erro.
              */}
              {r.status === 'proposta' && !r.propus && (
                <Botao
                  variante="secundaria"
                  tamanho="pequeno"
                  onClick={() => agir(() => api.aceitarAlianca(r.id), `Aliança com ${r.nome} firmada.`)}
                  disabled={ocupado}
                  data-aceitar-alianca={r.id}
                >
                  Aceitar
                </Botao>
              )}

              {d.pode_tratar && (
                <Botao
                  variante="fantasma"
                  tamanho="pequeno"
                  onClick={() =>
                    agir(
                      () => api.romperAlianca(r.id),
                      r.status === 'aceita' ? `Aliança com ${r.nome} rompida.` : 'Proposta recusada.',
                    )
                  }
                  disabled={ocupado}
                  data-romper-alianca={r.id}
                  className="ml-auto"
                >
                  {r.status === 'aceita' ? 'Romper' : 'Recusar'}
                </Botao>
              )}
            </li>
          ))}
        </ul>
      )}

      {d.pode_tratar ? (
        <div className="flex flex-wrap items-center gap-2">
          <select
            value={alvo}
            onChange={(e) => setAlvo(e.target.value)}
            disabled={ocupado || noTeto || (d.disponiveis ?? []).length === 0}
            data-alvo-alianca
            className="border-ink/30 bg-sand-light text-ink border px-2 py-1 text-sm"
          >
            <option value="">Escolha uma federação…</option>
            {(d.disponiveis ?? []).map((f) => (
              <option key={f.id} value={f.id}>
                {f.nome}
              </option>
            ))}
          </select>

          <Botao
            tamanho="pequeno"
            onClick={() => agir(() => api.proporAlianca(Number(alvo)), 'Proposta enviada.')}
            disabled={ocupado || !alvo || noTeto}
            data-propor-alianca
          >
            Propor aliança
          </Botao>

          {noTeto && (
            <span className="text-ink-soft/60 text-xs">
              No limite de aliadas — rompa uma para propor outra.
            </span>
          )}
        </div>
      ) : (
        <p className="text-ink-soft/60 text-xs">
          Só o Líder ou o Diplomata tratam de aliança com outra federação.
        </p>
      )}

      {recado && (
        <p className="text-ink mt-2 text-sm" data-recado-diplomacia>
          {recado}
        </p>
      )}
    </section>
  )
}
