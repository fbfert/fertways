<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Ordem importa: resources, ledger e tax_events têm FK para resource_types.code,
        // e os cost_json de building_specs referenciam esses mesmos códigos.
        $this->call([
            ResourceTypeSeeder::class,
            ComponentRecipeSeeder::class,
            BuildingSpecSeeder::class,
            // As 120 zonas neutras nos 4 distritos do D-51 (D-52). Sem FK para os demais, mas
            // depois deles por clareza: o mineral de cada zona é um código de resource_types.
            NeutralZoneSeeder::class,
            // A dotação do Ministério do Tesouro (D-57): 10 mil de cada recurso + 1M Fert$.
            // Depende de resource_types. Em produção (banco já migrado) rode-o à mão após o deploy.
            TreasurySeeder::class,
            TransportSettingSeeder::class,
        ]);
    }
}
