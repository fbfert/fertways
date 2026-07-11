<?php

namespace App\Console\Commands;

use App\Domain\Colony\KitInicialDeRecursos;
use App\Models\Colony;
use Illuminate\Console\Command;

/**
 * Backfill do kit fixo de recursos (D-57) para as colônias que já existem. As novas recebem no
 * CreateColony. Idempotente: pula quem já recebeu (ledger-ref). Passo à parte do deploy, como o
 * NeutralZoneSeeder.
 *
 *   artisan fertways:kit-recursos            # simula, lista quem receberia
 *   artisan fertways:kit-recursos --aplicar  # concede de verdade
 */
class KitRecursos extends Command
{
    protected $signature = 'fertways:kit-recursos {--aplicar : concede de verdade; sem isto, só simula}';

    protected $description = 'Concede o kit fixo de recursos (D-57) às colônias existentes';

    public function handle(KitInicialDeRecursos $kit): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $concedidos = 0;
        $pulados = 0;

        foreach (Colony::orderBy('id')->get() as $colony) {
            if (! $aplicar) {
                $this->line("colônia {$colony->id} ({$colony->name})");
                $concedidos++;

                continue;
            }

            if ($kit->conceder($colony)) {
                $this->info("colônia {$colony->id} ({$colony->name}): kit concedido.");
                $concedidos++;
            } else {
                $pulados++;
            }
        }

        $this->line($aplicar
            ? "Concedido a {$concedidos}, pulados {$pulados} (já tinham)."
            : "Simulação: {$concedidos} colônia(s). Rode com --aplicar para conceder.");

        return self::SUCCESS;
    }
}
