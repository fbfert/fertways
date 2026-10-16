<?php

namespace App\Domain\Guerra;

use App\Models\Unit;
use Carbon\CarbonInterface;

/**
 * A unidade sai da linha de montagem do Quartel (D-272). Roda no tick.
 */
class ConcluirFabricacaoDeUnidades
{
    /** @return int quantas ficaram prontas */
    public function handle(CarbonInterface $agora): int
    {
        return Unit::where('status', 'em_fabricacao')
            ->where('arrives_at', '<=', $agora)
            ->update(['status' => 'casa', 'arrives_at' => null, 'updated_at' => $agora]);
    }
}
