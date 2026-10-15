<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * O jogador não lê o GDD (D-268).
 *
 * O D-266 tirou "D-nn" e "(A2.x)" do que a API serve. A revisão das telas de veterano achou o mesmo
 * defeito escrito à mão no frontend: "Drone (D-74)", "não compensa (§26.9)", "Preços de referência
 * (§06)". Para quem joga, isso é ruído — ou pior, parece que falta uma página que ele deveria ter lido.
 *
 * O teste lê o código das telas sem os comentários (onde a referência ao GDD é bem-vinda) e procura
 * as marcas no que sobra: texto, title, aria-label.
 */
class TextoDeTelaSemJargaoTest extends TestCase
{
    public function test_nenhuma_tela_cita_decisao_ou_secao_do_gdd(): void
    {
        $raiz = dirname(__DIR__, 3).'/frontend/src';
        $achados = [];

        $arquivos = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz));
        foreach ($arquivos as $arquivo) {
            if (! preg_match('/\.tsx$/', $arquivo->getFilename())) {
                continue;
            }

            $codigo = file_get_contents($arquivo->getPathname());
            $codigo = preg_replace_callback('#/\*.*?\*/#s', fn ($m) => str_repeat("\n", substr_count($m[0], "\n")), $codigo);
            $codigo = preg_replace('#(?<![:"\'])//.*$#m', '', $codigo);

            foreach (explode("\n", $codigo) as $n => $linha) {
                if (preg_match('/§\s?\d|\bD-\d{2,3}\b|\bA2\.(V?\d)/u', $linha)) {
                    $achados[] = str_replace($raiz.DIRECTORY_SEPARATOR, '', $arquivo->getPathname()).':'.($n + 1).'  '.trim($linha);
                }
            }
        }

        $this->assertSame([], $achados, "Texto de tela citando o GDD:\n".implode("\n", $achados));
    }
}
