<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Semeia as três receitas de §24.5 a partir de data/component_recipes.json, gerado pelo
 * extrator lendo o HTML do GDD. Nenhuma quantidade é digitada aqui.
 */
class ComponentRecipeSeeder extends Seeder
{
    public function run(): void
    {
        $receitas = json_decode(
            file_get_contents(database_path('seeders/data/component_recipes.json')),
            true, flags: JSON_THROW_ON_ERROR,
        );

        $linhas = [];
        foreach ($receitas as $code => $r) {
            $linhas[] = [
                'code' => $code,
                'nome' => $r['nome'],
                'contexto' => $r['contexto'],
                'insumos_json' => json_encode($r['insumos'], JSON_UNESCAPED_UNICODE),
            ];
        }

        DB::table('component_recipes')->upsert($linhas, ['code'], ['nome', 'contexto', 'insumos_json']);

        $this->command->info(sprintf('component_recipes: %d receitas semeadas.', count($linhas)));
    }
}
