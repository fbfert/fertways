import { useCallback, useEffect, useState } from 'react'
import { api } from '../api/client'
import type { GuerraEmCurso, MesaDiplomatica as Dados } from '../api/client'
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

      {/*
        A2.10: a mesa de guerra.

        ⚠️ Mostra o custo AGORA, e não o de tabela: um evento de mobilização pode tê-lo mudado, e o
        jogador precisa ver o preço que vai pagar — não o que estava no manual.
      */}
      {d.guerra && (
        <div className="border-perigo/30 bg-sand-light mb-3 border-l-4 px-3 py-2" data-mesa-guerra>
          {d.guerra.tregua ? (
            <p className="text-ink text-sm">
              ▲ <strong>Trégua do Governo.</strong> Nenhuma declaração de guerra pode ser aberta agora.
            </p>
          ) : (
            <p className="text-ink-soft text-sm">
              Declarar guerra custa <strong>{d.guerra.custo_fert.toLocaleString('pt-BR')} F$</strong> e{' '}
              <strong>{d.guerra.custo_niobio}</strong> de Nióbio, <strong>do fundo</strong> — que tem{' '}
              {(d.fundo_fert ?? 0).toLocaleString('pt-BR')} F$. A campanha dura 7 dias e{' '}
              <strong>o outro lado não pode recusar</strong>.
            </p>
          )}

          {/*
            O ranking federativo (D-207). Fica ao lado do custo da guerra de propósito: é a
            consequência de longo prazo dela, e um número que só aparecesse depois de perder não
            ensinaria nada a quem ainda está a decidir se declara.

            ⚠️ A tela diz que o rating CAI, e por quê. Sem isso, a primeira derrota pareceria bug —
            e o motivo (soma zero, que é o que torna a guerra encenada inútil) é a coisa mais
            importante a saber sobre este número.
          */}
          <p className="text-ink-soft mt-2 text-sm" data-rating-federativo={d.guerra.rating}>
            Ranking federativo: <strong>{d.guerra.rating}</strong> —{' '}
            {d.guerra.posicao.lugar}º de {d.guerra.posicao.de}.{' '}
            <span className="text-ink-soft/70 text-xs">
              Sobe ao vencer e <strong>cai ao perder</strong>: o que uma federação ganha, a outra
              perde. Vencer quem é mais forte rende muito mais do que vencer quem é fraco.
            </span>
          </p>

          {/*
            A neutralidade, e a carência dita ANTES de o jogador pedir para sair.

            ⚠️ Descobrir depois que o escudo ainda vale por um dia seria descobrir tarde — e a
            carência é justamente o que impede largá-lo na hora do ataque.
          */}
          <div className="mt-2 flex flex-wrap items-center gap-2">
            {d.guerra.neutra ? (
              <>
                <Selo estado="info">neutra</Selo>
                {d.guerra.saindo_em ? (
                  <span className="text-ink-soft text-xs" data-neutra-saindo>
                    Deixa de ser neutra em {new Date(d.guerra.saindo_em).toLocaleString('pt-BR')} —
                    até lá continua protegida.
                  </span>
                ) : (
                  <Botao
                    variante="fantasma"
                    tamanho="pequeno"
                    onClick={() =>
                      agir(
                        () => api.encerrarNeutralidade(),
                        `Saída pedida. A proteção ainda vale por ${d.guerra?.carencia_horas} h.`,
                      )
                    }
                    disabled={ocupado || !d.pode_tratar}
                    data-encerrar-neutralidade
                    title={`A saída só vale depois de ${d.guerra.carencia_horas} h.`}
                  >
                    Encerrar neutralidade
                  </Botao>
                )}
              </>
            ) : (
              d.pode_tratar && (
                <Botao
                  variante="secundaria"
                  tamanho="pequeno"
                  onClick={() =>
                    agir(() => api.declararNeutralidade(), 'Neutralidade declarada.')
                  }
                  disabled={ocupado}
                  data-declarar-neutralidade
                  title="Neutra não pode ser declarada — e não declara. A saída tem carência."
                >
                  Declarar neutralidade
                </Botao>
              )
            )}
          </div>

          {d.guerra.em_guerra_com.map((g) => (
            <div key={g.id} className="border-ink/10 mt-2 border-t pt-2" data-em-guerra={g.id}>
              <p className="text-perigo text-sm">
                ▲ Em guerra com <strong>{g.nome}</strong> até{' '}
                {new Date(g.termina_em).toLocaleString('pt-BR')}
                {g.eu_declarei ? ' (você declarou)' : ' (declararam a você)'}.
              </p>

              {d.pode_tratar && (
                <SaidasDaGuerra
                  guerra={g}
                  precoFert={d.guerra?.capitulacao_fert ?? 0}
                  ocupado={ocupado}
                  agir={agir}
                />
              )}
            </div>
          ))}
        </div>
      )}

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

              {/*
                Declarar guerra fica ao lado de romper, e não escondido noutra tela: são os dois atos
                que quebram uma relação, e o jogador decide entre eles no mesmo lugar.

                ⚠️ O aviso de que declarar ROMPE a aliança está no `title`, porque a decisão 8 do
                D-193 diz que a tela tem de avisar antes — e descobrir depois seria traição do jogo,
                não do jogador.
              */}
              {d.pode_tratar && !d.guerra?.tregua && !d.guerra?.neutra && (
                <Botao
                  variante="perigo"
                  tamanho="pequeno"
                  onClick={() =>
                    agir(
                      () => api.declararGuerra(r.id),
                      `Guerra declarada a ${r.nome}. Sete dias.`,
                    )
                  }
                  disabled={ocupado}
                  data-declarar-guerra={r.id}
                  title={
                    r.status === 'aceita'
                      ? 'Declarar guerra ROMPE a aliança automaticamente.'
                      : 'A campanha dura 7 dias e o outro lado não pode recusar.'
                  }
                >
                  Declarar guerra
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

/**
 * As duas saídas antecipadas de uma guerra (A2.10, decisões 8 e 9; D-206).
 *
 * O §8 lista três jeitos de uma guerra acabar — *"prazo, capitulação ou tratado de paz"* — e até
 * aqui só o prazo existia. Esta é a porta dos outros dois, e ela precisa dizer o que o GDD diz e a
 * tela sozinha não deixaria adivinhar:
 *
 *  - **capitular é oferecer sem saber o preço.** A decisão 9 dá a escolha ao vencedor, então quem
 *    se rende precisa ver, antes de clicar, que aceita perder uma zona *ou* o valor do fundo — não
 *    escolhe qual;
 *  - **o tratado não move nada.** Sem isso as duas saídas pareceriam a mesma, e ninguém entenderia
 *    por que existiriam duas;
 *  - **quem propôs não responde.** Por isso os botões saem de `de_mim`, e não do lado da guerra:
 *    mostrar "aceitar" a quem propôs seria um clique que o servidor recusaria com 422.
 */
function SaidasDaGuerra({
  guerra: g,
  precoFert,
  ocupado,
  agir,
}: {
  guerra: GuerraEmCurso
  precoFert: number
  ocupado: boolean
  agir: (fn: () => Promise<unknown>, sucesso: string) => Promise<void>
}) {
  const [zona, setZona] = useState<string>('')

  return (
    <div className="mt-2 flex flex-col gap-2 text-sm" data-saidas={g.war_id}>
      {/* ── capitulação ─────────────────────────────────────────────────────────────────── */}
      {g.capitulacao.pendente ? (
        g.capitulacao.de_mim ? (
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-ink-soft">
              Você ofereceu capitulação. Espera-se que <strong>{g.nome}</strong> escolha o espólio.
            </span>
            <Botao
              variante="perigo"
              disabled={ocupado}
              data-retirar-capitulacao={g.war_id}
              onClick={() =>
                void agir(() => api.retirarCapitulacao(g.war_id), 'Capitulação retirada.')
              }
            >
              Retirar
            </Botao>
          </div>
        ) : (
          <div className="border-perigo/30 bg-sand-light border-l-4 px-2 py-2">
            <p className="text-ink">
              <strong>{g.nome}</strong> ofereceu capitulação. <strong>Você escolhe o espólio</strong>{' '}
              — uma zona dela, ou {precoFert.toLocaleString('pt-BR')} F$ do fundo dela. A guerra
              acaba no ato.
            </p>

            <div className="mt-2 flex flex-wrap items-center gap-2">
              <select
                value={zona}
                onChange={(e) => setZona(e.target.value)}
                data-zona-do-espolio={g.war_id}
                className="border-rust/25 bg-sand-light border px-2 py-1 text-sm"
              >
                <option value="">— escolher uma zona —</option>
                {g.zonas_do_inimigo.map((z) => (
                  <option key={z.id} value={z.id}>
                    {z.nome} · {z.mineral} · nível {z.nivel}
                  </option>
                ))}
              </select>

              <Botao
                disabled={ocupado || zona === ''}
                data-exigir-zona={g.war_id}
                onClick={() =>
                  void agir(
                    () => api.aceitarCapitulacao(g.war_id, 'zona', Number(zona)),
                    'Capitulação aceita: a zona é sua e a guerra acabou.',
                  )
                }
              >
                Exigir a zona
              </Botao>

              <Botao
                disabled={ocupado}
                data-exigir-fert={g.war_id}
                onClick={() =>
                  void agir(
                    () => api.aceitarCapitulacao(g.war_id, 'fert'),
                    'Capitulação aceita: o valor caiu no fundo e a guerra acabou.',
                  )
                }
              >
                Exigir {precoFert.toLocaleString('pt-BR')} F$
              </Botao>
            </div>

            {/* ⚠️ O limite existe e o vencedor tem de saber: se o fundo dela não cobrir, ele
                recebe menos — e a guerra acaba do mesmo jeito. Prometer o valor cheio e entregar
                menos seria a tela mentindo. */}
            <p className="text-ink-soft/70 mt-1 text-xs">
              Se o fundo dela tiver menos, você leva o que houver — a guerra acaba na mesma.
              {g.zonas_do_inimigo.length === 0 && ' Ela não tem zona livre de cerco para ceder.'}
            </p>
          </div>
        )
      ) : (
        <div className="flex flex-wrap items-center gap-2">
          <Botao
            variante="perigo"
            disabled={ocupado}
            data-capitular={g.war_id}
            title="Quem escolhe o espólio é quem venceu — uma zona sua, ou o valor do seu fundo."
            onClick={() =>
              void agir(
                () => api.proporCapitulacao(g.war_id),
                'Capitulação oferecida. Cabe a eles escolher o espólio.',
              )
            }
          >
            Capitular
          </Botao>
          <span className="text-ink-soft/70 text-xs">
            Acaba a guerra no ato. <strong>Eles</strong> escolhem: uma zona sua, ou{' '}
            {precoFert.toLocaleString('pt-BR')} F$ do seu fundo.
          </span>
        </div>
      )}

      {/* ── tratado de paz ──────────────────────────────────────────────────────────────── */}
      {g.tratado.pendente ? (
        g.tratado.de_mim ? (
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-ink-soft">Paz proposta. Aguardando {g.nome}.</span>
            <Botao
              disabled={ocupado}
              data-retirar-tratado={g.war_id}
              onClick={() => void agir(() => api.retirarTratado(g.war_id), 'Proposta retirada.')}
            >
              Retirar
            </Botao>
          </div>
        ) : (
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-ink">
              <strong>{g.nome}</strong> propôs paz. Nada muda de mãos.
            </span>
            <Botao
              disabled={ocupado}
              data-aceitar-tratado={g.war_id}
              onClick={() =>
                void agir(() => api.aceitarTratado(g.war_id), 'Paz assinada. A guerra acabou.')
              }
            >
              Aceitar a paz
            </Botao>
            <Botao
              variante="perigo"
              disabled={ocupado}
              data-recusar-tratado={g.war_id}
              onClick={() =>
                void agir(() => api.recusarTratado(g.war_id), 'Paz recusada. A guerra continua.')
              }
            >
              Recusar
            </Botao>
          </div>
        )
      ) : (
        <div className="flex flex-wrap items-center gap-2">
          <Botao
            disabled={ocupado}
            data-propor-tratado={g.war_id}
            onClick={() =>
              void agir(() => api.proporTratado(g.war_id), `Paz proposta a ${g.nome}.`)
            }
          >
            Propor paz
          </Botao>
          <span className="text-ink-soft/70 text-xs">
            Acaba antes do prazo, <strong>sem espólio</strong> para nenhum dos dois. Exige o aceite
            deles.
          </span>
        </div>
      )}
    </div>
  )
}
