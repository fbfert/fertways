<?php

namespace App\Domain\Cargos;

use App\Models\Ledger;

/**
 * O teto semanal do §14.2/v32 ("remuneração moderada, com teto semanal", sem número — arbitrado em
 * `CargosCivicosSpecs::TETO_SEMANAL_MICRO`).
 *
 * Sem coluna nova para "quanto já ganhou esta semana": o ledger já é a fonte — soma-se os
 * lançamentos dos últimos 7 dias, do mesmo jeito que o D-129 leu o histórico de leilão pelo ledger
 * em vez de guardar um contador redundante.
 */
class TetoSemanal
{
    /** Quanto falta até o teto, para este colono e este cargo, nos últimos 7 dias. */
    public static function livre(int $userId, string $kind): int
    {
        $pago = (int) Ledger::whereIn('type', ['salario_cargo_civico', 'bonus_cargo_civico'])
            ->where('ref', 'like', "cargo:{$kind}:{$userId}:%")
            ->where('created_at', '>=', now()->subDays(7))
            ->sum('amount');

        return max(0, CargosCivicosSpecs::TETO_SEMANAL_MICRO - $pago);
    }
}
