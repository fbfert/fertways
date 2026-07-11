<?php

namespace App\Domain\Colony;

use App\Models\Colony;
use App\Models\Ledger;
use Illuminate\Support\Facades\DB;

/**
 * Kit fixo de recursos concedido a cada colônia (D-57). Decisão de balanceamento do usuário —
 * **não vem do GDD** (o kit do GDD é 50 Fert$ + Furgão + raros). É **emissão do governo**: some ao
 * estoque da colônia e lança no ledger, sem debitar da reserva do Tesouro.
 *
 * Vale para as colônias existentes (backfill por `fertways:kit-recursos`) e para toda nova (chamado
 * no CreateColony). Idempotente: a marca é um lançamento com `ref = onboarding:kit_recursos`.
 */
class KitInicialDeRecursos
{
    /** As quantidades decididas pelo usuário. */
    public const KIT = [
        'metal_bruto' => 1000,
        'ligas_metalicas' => 1000,
        'compostos_quimicos' => 500,
        'biocombustivel' => 300,
        'componentes_eletronicos' => 500,
    ];

    private const REF = 'onboarding:kit_recursos';

    /** Concede o kit uma vez. Devolve false se a colônia já o recebeu. */
    public function conceder(Colony $colony): bool
    {
        if (Ledger::where('colony_id', $colony->id)->where('ref', self::REF)->exists()) {
            return false;
        }

        DB::transaction(function () use ($colony) {
            $agora = now();

            foreach (self::KIT as $recurso => $qtd) {
                $colony->resources()->where('resource_type', $recurso)->increment('amount', $qtd);

                Ledger::create([
                    'colony_id' => $colony->id,
                    'type' => 'kit_recursos',
                    'amount' => $qtd,
                    'resource_type' => $recurso,
                    'ref' => self::REF,
                    'created_at' => $agora,
                ]);
            }
        });

        return true;
    }
}
