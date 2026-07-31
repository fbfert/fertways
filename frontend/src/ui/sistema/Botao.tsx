/**
 * O botão do design system (A2.V1).
 *
 * Existia um botão por tela: 171 `<button>` espalhados por 26 arquivos, cada um
 * remontando à mão o mesmo fundo `rust` com o mesmo arredondamento. Não estavam
 * errados — a disciplina de cor deste código é boa —, mas cada cópia é uma
 * chance de alguém escolher o par de cores que reprova no contraste.
 *
 * **O par de cores não é parâmetro.** `tools/valida_contraste.py` mediu todas as
 * combinações da paleta, e duas delas são armadilhas:
 *
 * - fundo `rust` com texto `ink` dá **3,08:1** e reprova; com texto `sand-light`
 *   dá 4,94:1 e passa.
 * - fundo `ember` é o inverso: com texto claro dá **1,74:1** e reprova; só
 *   aceita texto `ink` (8,71:1).
 *
 * Por isso a variante escolhe o fundo E o texto junto. Quem usa escolhe a
 * intenção, não a cor.
 */

type Variante = 'primaria' | 'secundaria' | 'perigo' | 'fantasma'

const VARIANTES: Record<Variante, string> = {
  /* 4,94:1 — o texto claro não é gosto, é o que faz passar. */
  primaria: 'bg-rust text-sand-light hover:bg-rust-bright',

  secundaria: 'bg-sand-light text-rust border border-hairline-forte hover:border-rust',

  /* 9,69:1. Ver o comentário sobre o glifo, abaixo — cor aqui não basta. */
  perigo: 'bg-perigo text-sand-light hover:bg-ink',

  fantasma: 'text-rust hover:text-rust-bright',
}

const TAMANHOS = {
  pequeno: 'px-2 py-1 text-xs',
  normal: 'px-3 py-1.5 text-sm',
  grande: 'px-5 py-2.5 text-base',
}

export function Botao({
  variante = 'primaria',
  tamanho = 'normal',
  carregando = false,
  disabled,
  className = '',
  children,
  ...resto
}: {
  variante?: Variante
  tamanho?: keyof typeof TAMANHOS
  carregando?: boolean
  children: React.ReactNode
} & React.ButtonHTMLAttributes<HTMLButtonElement>) {
  const base = variante === 'fantasma' ? '' : 'rounded font-bold'

  return (
    <button
      /*
       * `disabled` durante o carregamento, e não só um spinner: sem isto o
       * colono clica duas vezes em "despachar" e manda dois veículos.
       */
      disabled={disabled || carregando}
      /*
       * O leitor de tela precisa saber que o botão está ocupado. O spinner é
       * visual e não chega a quem não vê a tela.
       */
      aria-busy={carregando || undefined}
      className={`${base} ${VARIANTES[variante]} ${TAMANHOS[tamanho]} inline-flex items-center justify-center gap-1.5 transition-colors disabled:cursor-not-allowed disabled:opacity-50 ${className}`}
      {...resto}
    >
      {/*
       * ⚠️ O GLIFO NÃO É ENFEITE, e não deve ser removido "porque polui".
       *
       * A paleta do FERTWAYS é quente por identidade — não existe vermelho frio
       * nela. O vermelho que o deck usa fica a **14° de matiz** do `rust` da
       * marca. Medido, não achado. Quer dizer que num relance, ou para quem tem
       * deficiência de visão de cor, "apagar para sempre" e "confirmar" são o
       * mesmo botão.
       *
       * A regra que sai daí: **destrutivo nunca se anuncia só por cor.** O
       * triângulo é o segundo canal, e é ele que carrega o aviso quando a cor
       * falha.
       */}
      {variante === 'perigo' && !carregando && <span aria-hidden="true">▲</span>}

      {carregando && (
        <span
          aria-hidden="true"
          /*
           * `border-current` com o topo transparente, e não uma cor fixa: o
           * spinner tem que funcionar tanto sobre o fundo `rust` da primária
           * quanto no texto da fantasma. Herdar a cor do texto é o único jeito
           * de valer para as quatro variantes sem uma tabela paralela.
           */
          className="size-3 animate-spin rounded-full border-2 border-current border-t-transparent"
        />
      )}

      {children}
    </button>
  )
}
