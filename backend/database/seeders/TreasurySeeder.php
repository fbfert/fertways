<?php

namespace Database\Seeders;

use App\Domain\Treasury\Tesouro;
use App\Models\Colony;
use App\Models\ResourceType;
use App\Models\TreasuryHolding;
use Illuminate\Database\Seeder;

/**
 * A dotação inicial do Ministério do Tesouro (D-57): 10 mil de cada recurso + 1.000.000 Fert$.
 *
 * Idempotente: só cria a linha que ainda não existe (não zera nem soma sobre o que o tributo já
 * acumulou). Rode logo após o deploy, à mão, como o NeutralZoneSeeder do D-52 — antes que o tributo
 * comece a creditar, para a dotação nascer completa.
 */
class TreasurySeeder extends Seeder
{
    public const RESERVA_POR_RECURSO = 10_000;

    public const RESERVA_FERT_MICRO = 1_000_000 * Colony::MICRO_POR_FERT; // 1.000.000 Fert$

    public function run(): void
    {
        foreach (ResourceType::pluck('code') as $code) {
            TreasuryHolding::firstOrCreate(['resource_type' => $code], ['amount' => self::RESERVA_POR_RECURSO]);
        }

        TreasuryHolding::firstOrCreate(['resource_type' => Tesouro::FERT], ['amount' => self::RESERVA_FERT_MICRO]);
    }
}
