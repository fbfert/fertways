import { useEffect, useState } from 'react'
import { api, ApiError } from '../api/client'
import type { ResumoDeRetorno as Resumo } from '../api/client'
import { Popup } from './Popup'
import { IconeRecurso } from './IconeRecurso'
import { fert, nomeRecurso } from './recursos'
import { Botao, Selo } from './sistema'
import { rotulo } from '../game/ColonyScene'

/**
 * "Desde sua última visita" (A2.0.3; janela do GDD ALPHA 2 §5.1).
 *
 * **A primeira tela nova da Alpha 2**, e por isso a primeira construída sobre o design system da
 * A2.V1 — `Botao` e `Selo`. Era exatamente a dependência que fez a A2.V1 vir antes da A2.0 no
 * roadmap: se os tokens viessem depois, esta tela nasceria fora do sistema e teria de ser refeita.
 *
 * ## O critério de aceite é de leitura, não de dados
 *
 * "O jogador deve entender em menos de um minuto o que mudou." Por isso ela mostra **poucos
 * números grandes** em vez de uma tabela: produção por recurso (no máximo seis), o que entrou e
 * saiu de Fert$, e as obras que terminaram. O resto do jogo já tem telas próprias para detalhe.
 *
 * ## Fechar é o que move a janela
 *
 * O `GET` monta e não marca nada; só o fechamento chama `resumoVisto`. Se abrir já consumisse a
 * janela, quem abrisse sem ler perderia para sempre o que aconteceu enquanto esteve fora.
 *
 * ⚠️ A marcação é disparada e **não esperada** — ver `fechar()`. Se ela falhar, o resumo reaparece
 * na próxima visita, o que é bem melhor do que um botão pendurado esperando um POST.
 */
export function ResumoDeRetorno({
  aoFechar,
  reabrindo = false,
}: {
  aoFechar: () => void
  /**
   * Veio do clique em "Ver o que aconteceu desde sua última visita", e não do convite automático.
   *
   * Muda **duas** coisas, e as duas são necessárias para o botão funcionar: o servidor devolve a
   * janela ANTERIOR (o marcador já avançou ao fechar, então pedir "desde a última visita" daria um
   * intervalo de zero minuto) e ignora o piso de uma hora, que existe para conter o popup que se
   * convida sozinho. E fechar **não** marca nada: reler não consome janela.
   */
  reabrindo?: boolean
}) {
  const [dados, setDados] = useState<Resumo | null>(null)
  const [erro, setErro] = useState<string | null>(null)

  useEffect(() => {
    let vivo = true

    api
      .resumo(reabrindo)
      .then((r) => {
        if (!vivo) return
        // O servidor decide se aparece (primeira visita, piso de uma hora, sem colônia). A tela
        // não repete essa regra — duplicá-la aqui seria duas verdades sobre a mesma janela.
        if (!r.mostrar) aoFechar()
        else setDados(r)
      })
      .catch((e) => vivo && setErro(e instanceof ApiError ? e.message : 'Falha ao carregar.'))

    return () => {
      vivo = false
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  /**
   * Fecha na hora e marca depois — **não espera o servidor responder**.
   *
   * A versão anterior dava `await` no POST antes de fechar, e o botão ficava alguns décimos
   * pendurado num pedido cuja resposta não muda nada na tela. Pior: numa rede ruim, o colono
   * clicaria de novo achando que não pegou.
   *
   * O `fetch` já partiu quando o componente desmonta, então a marcação chega ao servidor de
   * qualquer forma. E se falhar, o pior que acontece é o resumo reaparecer na próxima visita —
   * irritante, e muito melhor do que um botão que parece travado.
   */
  function fechar() {
    // Reabrir é reler, e reler não consome: marcar aqui empurraria o marcador de novo e apagaria
    // justamente a janela que o botão acabou de mostrar — ele deixaria de funcionar na segunda vez.
    if (!reabrindo) void api.resumoVisto().catch(() => {})
    aoFechar()
  }

  /*
   * Nada na tela enquanto carrega, e nada se falhar — nem "carregando", nem erro.
   *
   * Esta é a única tela do jogo que o colono **não pediu**: ela se convida. Um modal de
   * "carregando" apareceria e sumiria a cada carga de página, inclusive para quem está dentro do
   * piso de uma hora e não veria resumo nenhum — um flash em toda visita, para nada.
   *
   * E o erro também some: nagar o jogador com "falha ao carregar" por causa de um resumo opcional
   * troca uma conveniência perdida por uma interrupção. O erro aparece no log do servidor, que é
   * onde alguém pode fazer algo a respeito.
   */
  if (erro || !dados) return null

  return (
    <Popup titulo="Desde sua última visita" eyebrow={intervalo(dados)} aoFechar={fechar}>
      <div className="space-y-4" data-resumo>
        {dados.vazio ? (
          /*
           * "Nada aconteceu" é dito, e não escondido. Quem passou dois dias fora com a colônia
           * parada PRECISA ver que não produziu nada — um resumo que só aparece quando há boa
           * notícia esconde exatamente o que mais importa.
           */
          <p className="text-ink-soft text-sm" data-resumo-vazio>
            A colônia ficou parada nesse período: nada foi produzido, nada foi concluído. Vale olhar
            se falta energia ou insumo.
          </p>
        ) : (
          <>
            {/*
             * ⚠️ O presente vem PRIMEIRO, e em seção própria (A2.V6, D-235).
             *
             * Primeiro porque é a maior coisa que aconteceu na janela em que aconteceu: 20.000 de
             * energia caindo do céu domina qualquer produção do período, e enterrá-lo abaixo de uma
             * lista de recursos faria o jogador rolar a tela atrás da própria notícia.
             *
             * Em seção própria porque somá-lo à produção mentiria sobre a única conta que esta tela
             * existe para dar. Uma colônia parada que recebeu a cesta apareceria como a mais
             * produtiva do planeta.
             */}
            {dados.presentes.length > 0 && (
              <section data-resumo-presentes>
                <h3 className="text-rust eyebrow mb-2">O Governo enviou</h3>
                <div className="space-y-3">
                  {dados.presentes.map((p) => (
                    <div key={p.evento}>
                      <p className="text-ink mb-1 text-sm font-black">{p.nome}</p>
                      <ul className="space-y-1">
                        {p.itens.map((i, n) => (
                          <li
                            key={`${p.evento}-${i.recurso ?? 'fert'}-${n}`}
                            className="flex items-center gap-2 text-sm"
                          >
                            {/* `recurso` nulo é Fert$ em micro — a convenção do ledger. */}
                            {i.recurso ? <IconeRecurso codigo={i.recurso} /> : null}
                            <span className="text-ink-soft flex-1">
                              {i.recurso ? nomeRecurso(i.recurso) : 'Fert$'}
                            </span>
                            <span className="text-ink font-black">
                              {i.recurso
                                ? i.quantidade.toLocaleString('pt-BR')
                                : fert(i.quantidade, 2)}
                            </span>
                          </li>
                        ))}
                      </ul>
                    </div>
                  ))}
                </div>
              </section>
            )}

            {dados.producao.length > 0 && (
              <section>
                <h3 className="text-rust eyebrow mb-2">Produziu</h3>
                <ul className="space-y-1">
                  {dados.producao.map((p) => (
                    <li key={p.recurso} className="flex items-center gap-2 text-sm">
                      <IconeRecurso codigo={p.recurso} />
                      <span className="text-ink-soft flex-1">{nomeRecurso(p.recurso)}</span>
                      <span className="text-ink font-black">
                        {p.quantidade.toLocaleString('pt-BR')}
                      </span>
                    </li>
                  ))}
                </ul>
              </section>
            )}

            {(dados.fert_ganho_micro > 0 || dados.fert_gasto_micro > 0) && (
              <section>
                <h3 className="text-rust eyebrow mb-2">Fert$</h3>
                <div className="flex gap-4 text-sm">
                  {/*
                   * Os selos usam `sucesso` e `perigo`, mas o RÓTULO carrega o sentido — "entrou" e
                   * "saiu" estão escritos. É a regra do design system: a cor da paleta é quente e o
                   * vermelho fica a 14° do laranja da marca, então cor nunca é o único sinal.
                   */}
                  <Selo estado="sucesso">entrou {fert(dados.fert_ganho_micro, 2)}</Selo>
                  <Selo estado="perigo">saiu {fert(dados.fert_gasto_micro, 2)}</Selo>
                </div>
              </section>
            )}

            {dados.obras_concluidas.length > 0 && (
              <section>
                <h3 className="text-rust eyebrow mb-2">
                  Obras concluídas ({dados.obras_concluidas.length})
                </h3>
                <ul className="space-y-1">
                  {dados.obras_concluidas.map((o, i) => (
                    <li key={`${o.tipo}-${o.nivel}-${i}`} className="text-ink-soft text-sm">
                      {rotulo(o.tipo)}{' '}
                      <span className="text-ink font-bold">nível {o.nivel}</span>
                    </li>
                  ))}
                </ul>
              </section>
            )}
          </>
        )}

        <Botao onClick={fechar} data-resumo-fechar className="w-full" tamanho="grande">
          Continuar
        </Botao>
      </div>
    </Popup>
  )
}

/** "há 5 horas", "há 2 dias" — a janela em linguagem de gente, não um par de carimbos ISO. */
function intervalo(r: Resumo): string {
  if (!r.desde) return 'Resumo'

  const minutos = Math.round((Date.parse(r.ate) - Date.parse(r.desde)) / 60000)

  if (minutos < 90) return `nas últimas ${Math.max(1, Math.round(minutos / 60))} h`

  const horas = Math.round(minutos / 60)
  if (horas < 48) return `nas últimas ${horas} h`

  return `nos últimos ${Math.round(horas / 24)} dias`
}
