<?php

namespace App\Domain\Cargos;

use App\Models\Colony;
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
        /*
         * D-284: chamado dentro da transação de quem vai pagar, trava a colônia do beneficiário antes
         * de somar, e soma com leitura travada. Sem isto, N publicações ao mesmo tempo liam o mesmo
         * total e pagavam N bônus acima do teto — no InnoDB, a leitura simples enxerga a foto do começo
         * da transação, e travar outra linha não basta.
         */
        $colonia = Colony::where('user_id', $userId)->lockForUpdate()->first();

        // A leitura travada só alcança as linhas da colônia do beneficiário (índice em `colony_id`):
        // travar pelo `ref LIKE` varreria o ledger do servidor inteiro.
        $pago = (int) Ledger::where('colony_id', $colonia?->id)
            ->whereIn('type', ['salario_cargo_civico', 'bonus_cargo_civico'])
            ->lockForUpdate()
            ->where('ref', 'like', "cargo:{$kind}:{$userId}:%")
            ->where('created_at', '>=', now()->subDays(7))
            ->sum('amount');

        return max(0, CargosCivicosSpecs::TETO_SEMANAL_MICRO - $pago);
    }
}
