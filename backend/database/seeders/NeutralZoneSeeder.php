<?php

namespace Database\Seeders;

use App\Domain\Logistics\ZonasNeutras;
use App\Models\NeutralZone;
use Illuminate\Database\Seeder;

/**
 * Semeia as 120 zonas neutras nos 4 distritos do D-51 (docs/decisoes.md D-52, Fatia 1).
 *
 * A geometria e o mineral vêm de `ZonasNeutras`, determinísticos. Idempotente: pula a célula que já
 * existe, então rodar de novo não duplica nem sobrescreve o estado de uma zona já ocupada. As zonas
 * nascem livres, no nível 1, sem dono.
 */
class NeutralZoneSeeder extends Seeder
{
    public function run(): void
    {
        foreach (ZonasNeutras::todas() as $zona) {
            if (NeutralZone::where('x', $zona['x'])->where('y', $zona['y'])->exists()) {
                continue;
            }

            $criada = NeutralZone::create([
                'x' => $zona['x'],
                'y' => $zona['y'],
                'district' => $zona['distrito'],
                'mineral' => $zona['mineral'],
                'level' => 1,
                'status' => 'livre',
                'deposit_amount' => 0,
            ]);

            // O Depósito nasce erguido mesmo numa zona livre (D-52) — a colmeia de slots (D-144)
            // não muda isso, só move onde o nível mora.
            $criada->zoneStructures()->create([
                'slot' => \App\Domain\Zona\ZonaSlots::NIVEL1_SLOTS[0],
                'type' => 'deposito_de_zona_neutra',
                'level' => 1,
            ]);
        }
    }
}
