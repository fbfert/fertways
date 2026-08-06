<?php

namespace App\Domain\Marco;

/**
 * A curva do Marco (D-75): **XP acumulado para o marco N = BASE × N²**.
 *
 * Arbitragem do usuário (2026-07-13). O GDD publica curvas só para 5 níveis de construção (1,5× e
 * 1,65×) — esticadas a 100 níveis elas explodem (1,5^99). A quadrática sobe sempre, mas o começo
 * anda rápido (retenção, que é o título do §05) e a Lenda é projeto de temporada.
 *
 * ⚠️ A BASE é constante de código, não parâmetro de painel — de propósito, ao contrário dos valores
 * por ato. Mudá-la reescala o marco de todo mundo de uma vez: é arbitragem, não balanceamento.
 *
 * ## BASE 50 → 15 (D-223, 2026-08-06): recalibrada contra o campo
 *
 * O 50 foi escolhido em 2026-07-13, **sem campo nenhum** — o Marco tinha acabado de nascer. Vinte e
 * quatro dias depois, com 617 lançamentos no ledger, a medida:
 *
 *      XP do mundo por semana:  69.100 → 8.150 → 2.200 → 1.000     (queda de 98,5%)
 *      maior XP do planeta:     6.900                              (marco 11 no 50)
 *      mediana:                 2.600
 *      marco 20 (ocupar zona):  20.000 — 3× o total de vida do melhor jogador
 *
 * **96% do XP vem de `obra_concluida`**, que é uma fonte de largada: a colônia se ergue na primeira
 * semana e depois a curva de custo (1,5×/1,65×) engasga o ritmo. A quadrática sobe; a fonte que a
 * alimenta desce. Com o 50, o marco 20 não era distante — era **assintótico**.
 *
 * A âncora do 15 é o campo, não o gosto: `50 × N²` põe a colônia mais avançada do mundo no marco 11,
 * quando o §05 dá o território ao marco 20 (Desbravador). `15 × N²` a põe no **21** — ou seja, o
 * jogador mais avançado acabou de alcançar a faixa que o GDD associa a território, que é o que o
 * documento descreve.
 *
 *      marco   5 →     375 XP      marco  20 →   6.000 XP
 *      marco  10 →   1.500 XP      marco 100 → 150.000 XP
 *
 * ⚠️ **Isto sozinho NÃO destrava território, e a medida diz por quê.** Ocupar uma zona também exige
 * 300 Fert$ + 1.020 Metal Bruto + 1.200 Ligas + 400 Componentes **e população livre**, e os dois
 * líderes humanos estão em **0 e −9 colonos livres**. O marco era o portão mais fora de escala, não
 * o único. Ver o D-223.
 */
final class Curva
{
    public const BASE = 15;

    /** Os oito nomes do §03/§05, pelo PISO de cada faixa. Publicados — não mexa. */
    public const TITULOS = [
        1 => 'Sobrevivente',
        5 => 'Colono',
        10 => 'Pioneiro',
        20 => 'Desbravador',
        35 => 'Construtor',
        50 => 'Arquiteto',
        75 => 'Guardião',
        100 => 'Lenda de Fertways',
    ];

    /** O marco de quem tem este XP. Todo colono nasce no 1 — Sobrevivente é quem chegou. */
    public static function marco(int $xp): int
    {
        return max(1, min(100, (int) floor(sqrt($xp / self::BASE))));
    }

    /** O XP acumulado que o marco N exige. */
    public static function xpDoMarco(int $marco): int
    {
        return self::BASE * $marco * $marco;
    }

    public static function titulo(int $marco): string
    {
        $titulo = self::TITULOS[1];

        foreach (self::TITULOS as $piso => $nome) {
            if ($marco >= $piso) {
                $titulo = $nome;
            }
        }

        return $titulo;
    }
}
