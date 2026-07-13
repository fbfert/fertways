<?php

namespace App\Domain\Marco;

/**
 * A curva do Marco (D-75): **XP acumulado para o marco N = 50 × N²**.
 *
 * Arbitragem do usuário (2026-07-13). O GDD publica curvas só para 5 níveis de construção (1,5× e
 * 1,65×) — esticadas a 100 níveis elas explodem (1,5^99). A quadrática sobe sempre, mas o começo
 * anda rápido (retenção, que é o título do §05) e a Lenda é projeto de temporada:
 *
 *      marco   5 →   1.250 XP      marco  20 →  20.000 XP
 *      marco  10 →   5.000 XP      marco 100 → 500.000 XP
 *
 * ⚠️ A BASE é constante de código, não parâmetro de painel — de propósito, ao contrário dos valores
 * por ato. Mudá-la reescala o marco de todo mundo de uma vez: é arbitragem, não balanceamento.
 */
final class Curva
{
    public const BASE = 50;

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
