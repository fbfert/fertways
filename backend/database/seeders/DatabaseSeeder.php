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
        ]);
    }
}
