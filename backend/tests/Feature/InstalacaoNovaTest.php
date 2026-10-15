<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Uma instalação nova — `migrate:fresh --seed`, o que o e2e faz a cada execução — nasce com o jogo
 * inteiro, e não com metade dele.
 *
 * ⚠️ É a terceira vez que uma tabela fica de fora do `DatabaseSeeder` e a regra nasce inerte em
 * silêncio: os operadores (D-184), e agora a árvore de pesquisa (D-257). Em produção os seeders rodam
 * à mão, e é justamente isso que mascara o esquecimento. Este teste é o lugar onde ele aparece.
 */
class InstalacaoNovaTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_instalacao_nova_traz_as_tabelas_que_as_regras_leem(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach ([
            'resource_types', 'building_specs', 'building_operator_requirements', 'neutral_zones',
            'mission_templates', 'endurance_items', 'technologies',
        ] as $tabela) {
            $this->assertGreaterThan(0, DB::table($tabela)->count(), "a instalação nova nasce sem {$tabela}");
        }
    }
}
