/**
 * A janela flutuante do Chat, Missões e Bugs/Melhorias — as três nasceram com a mesma classe
 * (`fixed right-4 bottom-4 w-[…] max-h-[…]`), só variando o tamanho. Mobile-first: a base ocupa
 * quase a tela inteira (`inset-4`, sem largura fixa); a partir de `sm:` volta a ser exatamente a
 * janela de canto de sempre — o desktop não muda um pixel.
 */
export const painelFlutuante = {
  /** O Chat (h-[420px] w-[340px] no desktop). */
  chat:
    'painel bg-sand-light border-rust/30 fixed inset-4 z-30 flex flex-col border shadow-lg ' +
    'sm:inset-auto sm:right-4 sm:bottom-4 sm:h-[420px] sm:w-[340px]',

  /** Missões e Bugs/Melhorias (max-h-[560px] w-[360px] no desktop). */
  grande:
    'painel bg-sand-light border-rust/30 fixed inset-4 z-30 flex flex-col border shadow-lg ' +
    'sm:inset-auto sm:right-4 sm:bottom-4 sm:max-h-[560px] sm:w-[360px]',
}
