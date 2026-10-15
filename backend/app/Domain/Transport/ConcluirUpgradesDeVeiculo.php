<?php

namespace App\Domain\Transport;

use App\Models\Vehicle;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Devolve ao pátio, no nível novo, o veículo cujo serviço de upgrade venceu (D-259).
 *
 * O nível e a capacidade só mudam aqui, e não na entrada: enquanto o serviço corre, o veículo é o que
 * era — e não carrega nada, porque está `em_upgrade`. A capacidade é reescrita da base do tipo pela
 * mesma conta do `UpgradeVeiculo`, que é a regra de sempre (D-175).
 */
class ConcluirUpgradesDeVeiculo
{
    public function __construct(private readonly UpgradeVeiculo $upgrade) {}

    /** @return int quantos veículos saíram do serviço */
    public function handle(CarbonInterface $agora): int
    {
        $ids = Vehicle::where('status', Vehicle::EM_UPGRADE)
            ->where('ready_at', '<=', $agora)
            ->pluck('id');

        $concluidos = 0;

        foreach ($ids as $id) {
            $concluidos += DB::transaction(function () use ($id) {
                $v = Vehicle::whereKey($id)->where('status', Vehicle::EM_UPGRADE)->lockForUpdate()->first();

                if (! $v) {
                    return 0;
                }

                $nivel = (int) $v->level + 1;

                $v->update([
                    'level' => $nivel,
                    'capacity' => $this->upgrade->capacidade($v->type, $nivel),
                    'status' => 'ocioso',
                    'ready_at' => null,
                ]);

                return 1;
            });
        }

        return $concluidos;
    }
}
