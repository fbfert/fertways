<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Semeia building_specs a partir de data/building_specs.json, gerado por
 * tools/extract_gdd_specs.py lendo as tabelas de §4.2 e §4.3 do GDD.
 *
 * build_time_seconds fica NULL para o que não tem entrada em `build_times_base.json`: o GDD não
 * publica tempo de construção para eles. NULL significa "o GDD não diz", não "instantâneo". Ver
 * docs/decisoes.md D-10.
 *
 * ⚠️ **O Depósito de Zona Neutra ficou NULL por um bom tempo sem que ninguém notasse** (revisão de
 * 2026-07-19, D-122): `ConstruirNaZona` nunca passava por `BuildingSpecs::para()` — a classe que
 * bloqueia enfileiramento com tempo indefinido do lado da colônia —, então em vez de falhar
 * explicitamente, `(int) null` virava `0` e a estrutura concluía no PRÓXIMO tick, de graça.
 * Corrigido nos dois lados: um tempo-base entrou aqui, e `ConstruirNaZona` passou a recusar
 * também, caso outra estrutura de zona um dia fique sem tempo por engano.
 */
class BuildingSpecSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/building_specs.json');
        $tabelas = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        // Tempos-base definidos fora do GDD, para as construções que ele não cronometra.
        // Vazio por padrão: sem entrada aqui, o tempo permanece NULL.
        $override = json_decode(
            file_get_contents(database_path('seeders/data/build_times_base.json')),
            true, flags: JSON_THROW_ON_ERROR,
        )['tempos_base_minutos'] ?? [];

        // §19.2–§19.5: produção e consumo de energia por hora, por nível. Extraídos do GDD.
        $prod = json_decode(
            file_get_contents(database_path('seeders/data/production.json')),
            true, flags: JSON_THROW_ON_ERROR,
        );

        $linhas = [];
        foreach ($tabelas as $t) {
            $baseMin = $override[$t['type']] ?? null;
            $p = $prod[$t['type']] ?? ['producao' => [], 'consumo_energia' => []];

            foreach ($t['levels'] as $lv) {
                $tempo = $lv['build_time_seconds'];
                $derivado = false;

                if ($tempo === null && $baseMin !== null) {
                    // half-EVEN: é a convenção que o GDD usa para tempo. Confere em 13 das
                    // 14 tabelas cujo tempo-base ele publica em §20.3–20.5 (Reator base 7
                    // -> 10,5 -> 10, não 11). Custo, ao contrário, usa half-UP (82,5 -> 83).
                    // Os dois modos convivem no mesmo documento; não são intercambiáveis.
                    $minutos = (int) round($baseMin * 1.5 ** ($lv['level'] - 1), 0, PHP_ROUND_HALF_EVEN);
                    $tempo = $minutos * 60;
                    $derivado = true;
                }

                $nivel = (string) $lv['level'];
                $producao = $p['producao'][$nivel] ?? null;

                $linhas[] = [
                    'building_type' => $t['type'],
                    'level' => $lv['level'],
                    'build_time_seconds' => $tempo,
                    'build_time_derivado' => $derivado,
                    'cost_json' => json_encode($lv['cost'], JSON_UNESCAPED_UNICODE),
                    'energia_consumo_hora' => $p['consumo_energia'][$nivel] ?? 0,
                    'producao_hora_json' => $producao ? json_encode($producao) : null,
                ];
            }
        }

        DB::table('building_specs')->upsert(
            $linhas,
            ['building_type', 'level'],
            ['build_time_seconds', 'build_time_derivado', 'cost_json',
                'energia_consumo_hora', 'producao_hora_json'],
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
