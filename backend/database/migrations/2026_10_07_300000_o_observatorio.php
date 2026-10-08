<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * O Observatório entra no jogo (GDD_ALPHA2 §7.2; D-252).
 *
 * ## Migration, e não seeder
 *
 * O `deploy.sh` não roda seeder — e o esquecimento é silencioso: a tabela fica sem a linha e a regra
 * nasce inerte (Tesouro D-57, zonas D-52, transporte D-60). Re-rodar o `BuildingSpecSeeder` em
 * produção também não serve: ele reescreve a especificação de TODOS os prédios, e desfaria qualquer
 * divergência que a produção tenha. Esta migration insere só as linhas do Observatório, a partir do
 * mesmo JSON que o seeder lê — uma fonte só para os números.
 *
 * `insertOrIgnore`: num banco semeado depois deste commit, o seeder já pôs as linhas, e esta
 * migration não faz nada. Idempotente pelos dois lados.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tabelas = json_decode(
            file_get_contents(database_path('seeders/data/building_specs.json')), true, flags: JSON_THROW_ON_ERROR,
        );
        $prod = json_decode(
            file_get_contents(database_path('seeders/data/production.json')), true, flags: JSON_THROW_ON_ERROR,
        );

        $obs = collect($tabelas)->firstWhere('type', 'observatorio');

        foreach ($obs['levels'] as $lv) {
            DB::table('building_specs')->insertOrIgnore([
                'building_type' => 'observatorio',
                'level' => $lv['level'],
                'build_time_seconds' => $lv['build_time_seconds'],
                'build_time_derivado' => false,
                'cost_json' => json_encode($lv['cost'], JSON_UNESCAPED_UNICODE),
                'energia_consumo_hora' => $prod['observatorio']['consumo_energia'][(string) $lv['level']] ?? 0,
                'producao_hora_json' => null,
            ]);
        }
    }

    public function down(): void
    {
        // Só a especificação. Um Observatório já erguido continuaria de pé, sem tabela de nível —
        // e por isso o down recusa quando há algum: apagar a regra de um prédio existente é pior
        // do que não desfazer a migration.
        if (DB::table('buildings')->where('type', 'observatorio')->exists()) {
            throw new RuntimeException('Há Observatório erguido. Demola-o antes de desfazer esta migration.');
        }

        DB::table('building_specs')->where('building_type', 'observatorio')->delete();
    }
};
