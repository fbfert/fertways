/**
 * Os três estados que toda tela que busca dados tem (A2.V1): carregando, vazio,
 * e deu erro.
 *
 * Estavam remontados à mão em toda parte — 120 menções a "erro", 43 a "vazio",
 * 20 a "carregando" espalhadas pelos componentes —, cada uma com sua própria
 * altura, seu próprio tom e sua própria decisão sobre o que dizer. O custo disso
 * não é o código repetido: é a tela que fica em branco sem explicar por quê.
 */

/**
 * ⚠️ O `role="status"` não é decorativo. Sem ele, quem usa leitor de tela clica,
 * a tela não muda de forma audível, e a pessoa fica sem saber se o clique
 * funcionou. `aria-live` polido: anuncia quando houver uma pausa, sem cortar.
 */
export function Carregando({ children = 'Carregando…' }: { children?: React.ReactNode }) {
  return (
    <div
      role="status"
      aria-live="polite"
      className="text-ink-soft/70 flex items-center justify-center gap-2 p-6 text-sm"
    >
      <span
        aria-hidden="true"
        className="border-rust/30 border-t-rust size-4 animate-spin rounded-full border-2"
      />
      {children}
    </div>
  )
}

/**
 * O vazio precisa dizer o que fazer, não só que está vazio.
 *
 * "Nenhuma oferta" deixa o colono parado; "Nenhuma oferta — anuncie a sua pelo
 * Mercado Local" o move. Por isso `acao` existe e por isso vale preenchê-la.
 */
export function Vazio({
  children,
  acao,
}: {
  children: React.ReactNode
  acao?: React.ReactNode
}) {
  return (
    <div className="text-ink-soft/70 flex flex-col items-center gap-2 p-6 text-center text-sm">
      <div>{children}</div>
      {acao}
    </div>
  )
}

/**
 * O erro fala com o colono, não com o programador.
 *
 * `role="alert"` porque erro interrompe: o leitor de tela deve anunciá-lo na
 * hora, sem esperar pausa — ao contrário do "carregando".
 *
 * A cor sozinha não diz que é erro (o vermelho da paleta fica a 14° do laranja
 * da marca — ver `Botao.tsx`), então o rótulo "Erro" vai escrito.
 */
export function Erro({
  children,
  aoTentarDeNovo,
}: {
  children: React.ReactNode
  aoTentarDeNovo?: () => void
}) {
  return (
    <div
      role="alert"
      className="border-perigo/40 bg-sand-light flex flex-col items-start gap-2 rounded border-l-4 p-4"
    >
      <div className="text-perigo eyebrow">Erro</div>
      <div className="text-ink text-sm">{children}</div>
      {aoTentarDeNovo && (
        <button
          onClick={aoTentarDeNovo}
          className="text-rust hover:text-rust-bright text-sm font-bold underline"
        >
          Tentar de novo
        </button>
      )}
    </div>
  )
}
