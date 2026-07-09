<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Semeia o catálogo de recursos a partir de data/resource_types.json, que é gerado por
 * tools/extract_gdd_specs.py lendo o HTML do GDD (§22.2, §22.3, §22.4).
 *
 * Nenhum valor é digitado aqui. Regenerar com:
 *   python3 tools/extract_gdd_specs.py docs/FERTWAYS_GDD_v35_MESTRE_UNIFICADO.html \
 *       backend/database/seeders/data/building_specs.json \
 *       backend/database/seeders/data/resource_types.json
 */
class ResourceTypeSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/resource_types.json');
        $recursos = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $agora = now();
        $linhas = array_map(fn (array $r) => [
            'code' => $r['code'],
            'nome' => $r['nome'],
            'tax_class' => $r['tax_class'],
            'tax_bps' => $r['tax_bps'],
            'preco_base_micro' => $r['preco_base_micro'],
            'producao_max_hora' => $r['producao_max_hora'],
            'created_at' => $agora,
            'updated_at' => $agora,
        ], $recursos);

        DB::table('resource_types')->upsert(
            $linhas,
            ['code'],
            ['nome', 'tax_class', 'tax_bps', 'preco_base_micro', 'producao_max_hora', 'updated_at'],
        );

        $this->command->info(sprintf('resource_types: %d recursos semeados.', count($linhas)));
    }
}
