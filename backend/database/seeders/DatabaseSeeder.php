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
            BuildingSpecSeeder::class,
        ]);
    }
}
