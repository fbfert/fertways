/**
 * O cartão do design system (A2.V1) — a superfície com o entalhe do deck.
 *
 * A classe `.painel` (o chanfro) já existia em `index.css` e é usada solta em
 * dezenas de lugares; o que faltava era o cabeçalho padrão, que o `Popup` já
 * tinha resolvido bem: um `eyebrow` em versalete por cima de um título forte.
 * Este componente é aquele cabeçalho extraído, para o popup e a tela pararem de
 * divergir.
 *
 * `titulo` é opcional de propósito: metade dos painéis do jogo são superfície
 * pura, sem cabeçalho nenhum.
 */
export function Cartao({
  titulo,
  eyebrow,
  acao,
  className = '',
  children,
}: {
  titulo?: string
  eyebrow?: string
  /** Canto superior direito — o botão que age sobre o cartão inteiro. */
  acao?: React.ReactNode
  className?: string
  children: React.ReactNode
}) {
  return (
    <div className={`painel bg-sand-light p-4 ${className}`}>
      {(titulo || eyebrow || acao) && (
        <div className="mb-3 flex items-start justify-between gap-3">
          <div>
            {eyebrow && <div className="text-rust eyebrow">{eyebrow}</div>}
            {/*
             * `h2` e não `div`: o cartão é uma seção de verdade da página, e a
             * navegação por cabeçalhos do leitor de tela é como se pula entre
             * elas. Um `div` estilizado de negrito deixaria a tela sem esqueleto.
             */}
            {titulo && <h2 className="text-ink text-lg leading-tight font-black">{titulo}</h2>}
          </div>
          {acao}
        </div>
      )}

      {children}
    </div>
  )
}
