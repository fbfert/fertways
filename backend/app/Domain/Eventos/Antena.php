<?php

namespace App\Domain\Eventos;

use App\Models\Colony;

/**
 * O alcance da Antena de Comunicação (D-271): quantas horas antes de começar um evento do mundo a
 * colônia fica sabendo dele.
 *
 * O GDD promete "comunicação com a Capital, alertas, eventos" (§17.2) sem número. A recomendação que o
 * usuário aprovou: **12 horas por nível**. Sem Antena, zero — o evento aparece quando começa.
 */
final class Antena
{
    public const HORAS_POR_NIVEL = 12;

    public static function horas(?Colony $colonia): int
    {
        if (! $colonia) {
            return 0;
        }

        $nivel = (int) $colonia->buildings()->where('type', 'antena_de_comunicacao')->max('level');

        return $nivel * self::HORAS_POR_NIVEL;
    }
}
