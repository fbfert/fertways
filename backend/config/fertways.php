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
];
