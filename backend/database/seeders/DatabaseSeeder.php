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

            /*
             * ⚠️ A2.2.4/A2.6: sem isto, `building_operator_requirements` nasce VAZIA em qualquer
             * instalação nova — e a mecânica de população inteira fica inerte: nada exige operador,
             * o grandfathering concede o piso de 1 colono a todo mundo, e nenhuma zona pode ser
             * ocupada por falta de gente livre.
             *
             * Em produção eu o rodei à mão (`db:seed --class=...`), e foi isso que mascarou o
             * defeito. Quem pegou foi o e2e, que recria o banco do zero a cada execução.
             *
             * Depende de `BuildingSpecSeeder`: as linhas saem das construções com
             * `producao_hora_json`.
             */
            BuildingOperatorRequirementSeeder::class,
            // As 120 zonas neutras nos 4 distritos do D-51 (D-52). Sem FK para os demais, mas
            // depois deles por clareza: o mineral de cada zona é um código de resource_types.
            NeutralZoneSeeder::class,
            // A dotação do Ministério do Tesouro (D-57): 10 mil de cada recurso + 1M Fert$.
            // Depende de resource_types. Em produção (banco já migrado) rode-o à mão após o deploy.
            TreasurySeeder::class,
            TransportSettingSeeder::class,
            // O catálogo de missões do §06 (D-78) — o baralho das diárias e a tutoria.
            MissionTemplateSeeder::class,
        ]);
    }
}
