<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * O tema "casco" da Endurance (A2.V5; D-274) — a nave com identidade visual própria, sem cor nova.
 *
 * A regra da casa (docs/design-tokens.md): **cor não se escolhe a olho**. As sete cores de marca
 * foram amostradas do deck, e o deck não está nesta máquina para amostrar mais. Então o casco não
 * inventa cor: ele **troca de lugar** as que existem — o `ink` vira chão, o `sand` vira letra e o
 * `ember`, que sobre areia é ilegível (1,62:1), vira o acento que finalmente pode ser texto.
 *
 * O `tools/valida_contraste.py` é quem guarda os pares da paleta clara; ele roda em Python, que nem
 * toda máquina de trabalho tem. Este teste guarda os pares do casco lendo o próprio `index.css`: se
 * alguém mexer num valor do escopo e algum par cair abaixo de 4,5:1, a suíte para.
 */
class TemaDoCascoTest extends TestCase
{
    private const CSS = __DIR__.'/../../../frontend/src/index.css';

    /** Letra sobre superfície, como os componentes da Endurance pintam. */
    private const PARES = [
        ['ink', 'sand'], ['ink', 'sand-light'],
        ['ink-soft', 'sand'], ['ink-soft', 'sand-light'],
        ['rust', 'sand'], ['rust', 'sand-light'],          // eyebrow, links, erro
        ['ember', 'sand'], ['ember', 'sand-light'],        // efeitos, ◈ do único
        ['sand-light', 'rust'], ['sand-light', 'rust-bright'], // texto do botão e o hover dele
    ];

    /** @return array<string,string> token => hex */
    private function paleta(?string $escopo): array
    {
        $css = file_get_contents(self::CSS);
        preg_match('/@theme\s*\{(.*?)\n\}/s', $css, $tema);
        $cores = $this->cores($tema[1]);

        if ($escopo !== null) {
            $this->assertSame(1, preg_match('/\[data-tema=[\'"]?'.$escopo.'[\'"]?\]\s*\{(.*?)\}/s', $css, $bloco),
                "o escopo [data-tema={$escopo}] não existe no index.css");
            $cores = $this->cores($bloco[1]) + $cores;
        }

        return $cores;
    }

    /** @return array<string,string> */
    private function cores(string $bloco): array
    {
        preg_match_all('/--color-([a-z-]+):\s*(#[0-9a-f]{6})\b/i', $bloco, $m, PREG_SET_ORDER);

        return collect($m)->mapWithKeys(fn ($x) => [$x[1] => strtolower($x[2])])->all();
    }

    private function luminancia(string $hex): float
    {
        [$r, $g, $b] = array_map(function ($i) use ($hex) {
            $v = hexdec(substr($hex, $i, 2)) / 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, [1, 3, 5]);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    private function contraste(string $a, string $b): float
    {
        [$x, $y] = [$this->luminancia($a), $this->luminancia($b)];

        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }

    public function test_todo_par_do_casco_passa_aa(): void
    {
        $casco = $this->paleta('casco');

        foreach (self::PARES as [$letra, $fundo]) {
            $c = $this->contraste($casco[$letra], $casco[$fundo]);
            $this->assertGreaterThanOrEqual(4.5, $c, sprintf('%s sobre %s no casco: %.2f:1', $letra, $fundo, $c));
        }
    }

    /** Nenhuma cor nova: cada valor do casco já é uma das sete cores de marca. */
    public function test_o_casco_nao_inventa_cor(): void
    {
        $marca = array_intersect_key($this->paleta(null),
            array_flip(['rust', 'rust-bright', 'ember', 'sand', 'sand-light', 'ink', 'ink-soft']));

        foreach ($this->paleta('casco') as $token => $hex) {
            if (! array_key_exists($token, $marca)) {
                continue;
            }
            $this->assertContains($hex, $marca, "--color-{$token} do casco ({$hex}) não é cor de marca");
        }
    }

    /** E o casco é de fato escuro: a superfície fica mais escura do que a letra. */
    public function test_o_casco_e_escuro(): void
    {
        $casco = $this->paleta('casco');

        $this->assertLessThan($this->luminancia($casco['ink']), $this->luminancia($casco['sand']));
    }
}
