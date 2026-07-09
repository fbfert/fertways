<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Semeia building_specs a partir de data/building_specs.json, gerado por
 * tools/extract_gdd_specs.py lendo as tabelas de §4.2 e §4.3 do GDD.
 *
 * build_time_seconds fica NULL para Central de Transportes, Destilaria, Depósito de
 * Zona Neutra e todos os veículos: o GDD não publica tempo de construção para eles.
 * NULL significa "o GDD não diz", não "instantâneo". Ver docs/decisoes.md D-10.
 */
class BuildingSpecSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/building_specs.json');
        $tabelas = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $linhas = [];
        foreach ($tabelas as $t) {
            foreach ($t['levels'] as $lv) {
                $linhas[] = [
                    'building_type' => $t['type'],
                    'level' => $lv['level'],
                    'build_time_seconds' => $lv['build_time_seconds'],
                    'cost_json' => json_encode($lv['cost'], JSON_UNESCAPED_UNICODE),
                    'energia_consumo_hora' => 0,
                    'producao_hora_json' => null,
                ];
            }
        }

        DB::table('building_specs')->upsert(
            $linhas,
            ['building_type', 'level'],
            ['build_time_seconds', 'cost_json'],
        );

        $semTempo = collect($linhas)->whereNull('build_time_seconds')
            ->pluck('building_type')->unique()->sort()->values();

        $this->command->info(sprintf(
            'building_specs: %d linhas (%d construções). Sem tempo no GDD: %s',
            count($linhas),
            count($tabelas),
            $semTempo->isEmpty() ? 'nenhuma' : $semTempo->implode(', '),
        ));
    }
}
