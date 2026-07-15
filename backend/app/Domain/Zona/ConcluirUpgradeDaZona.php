<?php

namespace App\Domain\Zona;

use App\Models\NeutralZone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fecha os upgrades de nível de zona que venceram (docs/decisoes.md D-84). Roda no tick, igual
 * `ConcluirObrasDaZona` — o custo já foi pago ao pedir o upgrade; aqui só se sobe o nível.
 */
class ConcluirUpgradeDaZona
{
    /** @return int quantos upgrades terminaram */
    public function handle(?Carbon $agora = null): int
    {
        $agora ??= now();

        $ids = NeutralZone::whereNotNull('level_target')
            ->where('level_upgrade_finishes_at', '<=', $agora)
            ->pluck('id');

        $feitos = 0;

        foreach ($ids as $id) {
            if ($this->concluir($id)) {
                $feitos++;
            }
        }

        return $feitos;
    }

    private function concluir(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $zona = NeutralZone::whereKey($id)->lockForUpdate()->first();

            if (! $zona || $zona->level_target === null) {
                return false;   // outro processo já a fechou, ou a zona sumiu.
            }

            // `max`, como `ConcluirObrasDaZona`: uma zona conquistada no meio do upgrade não desce.
            $zona->update([
                'level' => max($zona->level, $zona->level_target),
                'level_target' => null,
                'level_upgrade_finishes_at' => null,
            ]);

            return true;
        });
    }
}
