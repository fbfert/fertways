<?php

namespace Database\Seeders;

use App\Domain\Frete\Garagem;
use App\Domain\Transport\Placas;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;

/**
 * A Garagem do Governo nasce com 10 caminhões (D-76) — arbitragem do usuário.
 *
 * **Idempotente por complemento**: conta os caminhões de garagem existentes e completa até 10.
 * Rodar duas vezes não duplica; e se o operador encomendou mais pelo painel, o seeder não os
 * apaga — só garante o piso inicial. Roda à mão em produção, como o NeutralZoneSeeder (D-52):
 * o deploy.sh não roda seeders.
 */
class GaragemSeeder extends Seeder
{
    public const FROTA_INICIAL = 10;

    public function run(): void
    {
        $faltam = self::FROTA_INICIAL - Garagem::frota()->count();

        for ($i = 0; $i < $faltam; $i++) {
            app(Placas::class)->registrar(Vehicle::create([
                'colony_id' => null,
                'type' => 'caminhao_de_carga',
                'level' => 1,
                'status' => 'ocioso',
                'local' => Vehicle::NO_PATIO,   // a Garagem fica na Capital
                'capacity' => Vehicle::CAPACIDADE['caminhao_de_carga'],
            ]));
        }
    }
}
