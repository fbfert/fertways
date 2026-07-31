/**
 * O design system da Alpha 2 (A2.V1).
 *
 * Importe daqui, não do arquivo: `import { Botao, Selo } from './sistema'`.
 *
 * O que existe e por quê está em `docs/design-tokens.md`. O resumo curto:
 * nenhuma cor foi escolhida a olho — as de marca saíram do deck por
 * `tools/sample_png.py`, as de estado por `tools/amostra_estados.py`, e todos os
 * pares foram medidos por `tools/valida_contraste.py`, que roda e pode ser
 * rodado de novo quando alguém quiser mexer na paleta.
 *
 * O `Popup` (modal) não mora aqui: ele é anterior ao design system, já resolve
 * bem as três formas de fechar (fora, Esc, ×) e é usado por meia dúzia de telas.
 * Fica onde está até a A2.V2, que é quando as telas migram.
 */
export { Botao } from './Botao'
export { Cartao } from './Cartao'
export { Selo } from './Selo'
export { Carregando, Vazio, Erro } from './Estado'
