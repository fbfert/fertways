<?php

return [
    /*
     * Onde as imagens das construções moram (D-68).
     *
     * ⚠️ **É configurável por uma razão que custou caro.** A raiz era uma constante
     * (`Biblioteca::RAIZ = '/home/fertways/media'`), e o `ImagensTest` — que exercita o botão de
     * apagar — chamou `unlink()` **no caminho real** e **destruiu uma imagem de produção**
     * (`reator-helios.png`). O teste passou; o arquivo sumiu.
     *
     * Agora o `phpunit.xml` aponta esta chave para uma pasta temporária, e a `Biblioteca` **recusa-se
     * a rodar** se um teste apontar para a pasta de verdade. É a mesma família de guarda do D-27, em
     * que o `migrate:fresh` de uma ferramenta apagou o banco do jogo: a trava tem de estar no código,
     * não na disciplina de quem escreve o teste.
     */
    'media_raiz' => env('FERTWAYS_MEDIA_DIR', '/home/fertways/media'),

    /*
     * O teto global de requisições à API, por jogador por minuto (D-277, A2.12).
     *
     * Medido, não chutado: o pior caso com a tela parada é o Mercado (164 num minuto) e a navegação
     * mais rápida que um humano consegue faz 167. 600 deixa caber três abas no Mercado de uma vez.
     * Se uma tela nova passar a consultar mais, é ESTE número que se revê — com nova medida.
     */
    'teto_requisicoes_por_minuto' => (int) env('FERTWAYS_TETO_REQUISICOES', 600),
];
