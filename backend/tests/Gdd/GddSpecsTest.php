<?php

namespace Tests\Gdd;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A regra de ouro do projeto: nenhum valor de jogo é inventado; tudo vem do GDD.
 * Estes testes são o que torna essa regra mecânica em vez de aspiracional.
 */
class GddSpecsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
    }

    /** half-up, não half-even: 50 * 1,65 = 82,5 e o GDD diz 83, não 82. */
    private function halfUp(float $x): int
    {
        return (int) bcadd((string) $x, '0.5', 0);
    }

    public function test_custo_de_todo_nivel_segue_a_curva_1_65_do_gdd(): void
    {
        $tipos = DB::table('building_specs')->distinct()->pluck('building_type');
        $this->assertGreaterThan(0, $tipos->count());

        $conferidos = 0;
        foreach ($tipos as $tipo) {
            $niveis = DB::table('building_specs')->where('building_type', $tipo)
                ->orderBy('level')->get();
            $base = json_decode($niveis->first()->cost_json, true);

            foreach ($niveis as $n) {
                $custo = json_decode($n->cost_json, true);
                foreach ($base as $recurso => $valorBase) {
                    $esperado = $this->halfUp($valorBase * 1.65 ** ($n->level - 1));
                    $this->assertSame(
                        $esperado,
                        $custo[$recurso],
                        "{$tipo}.{$recurso} nível {$n->level}: GDD={$custo[$recurso]}, curva={$esperado}",
                    );
                    $conferidos++;
                }
            }
        }
        $this->assertGreaterThan(200, $conferidos, 'poucas células conferidas — seed suspeito');
    }

    /**
     * O tempo NÃO segue a curva 1,50×. Se algum dia passar a seguir, é porque alguém
     * substituiu a tabela do GDD por uma fórmula — e este teste avisa.
     */
    public function test_tempo_nao_e_derivavel_da_curva_1_50(): void
    {
        $gerador = DB::table('building_specs')->where('building_type', 'gerador_de_atmosfera')
            ->orderBy('level')->pluck('build_time_seconds', 'level');

        $this->assertSame([240, 300, 480, 720, 1080], array_values($gerador->toArray()));

        $base = $gerador[1];
        $porFormula = $this->halfUp($base * 1.5 ** (2 - 1));
        $this->assertNotSame($porFormula, $gerador[2], 'tempo virou fórmula — a tabela do GDD foi perdida');
    }

    public function test_construcoes_sem_tempo_no_gdd_ficam_nulas_e_nao_zeradas(): void
    {
        // NULL = "o GDD não publica". Zero significaria construção instantânea.
        foreach (['central_de_transportes', 'destilaria', 'furgao_de_comercio', 'caminhao_de_carga'] as $tipo) {
            $tempos = DB::table('building_specs')->where('building_type', $tipo)
                ->pluck('build_time_seconds');
            $this->assertGreaterThan(0, $tempos->count(), "{$tipo} não foi semeado");
            $this->assertTrue($tempos->every(fn ($t) => $t === null), "{$tipo} ganhou tempo inventado");
        }
    }

    public function test_niveis_maximos_batem_com_o_gdd(): void
    {
        $esperado = [
            'gerador_de_atmosfera' => 5,
            'estrutura_de_sobrevivencia' => 5,
            'fazenda' => 5,
            'reator_de_energia' => 5,
            'captacao_de_agua' => 5,
            'central_de_transportes' => 10,
            'deposito_de_zona_neutra' => 10,
            'destilaria' => 10,
        ];
        foreach ($esperado as $tipo => $max) {
            $this->assertSame(
                $max,
                DB::table('building_specs')->where('building_type', $tipo)->max('level'),
                "nível máximo de {$tipo}",
            );
        }
    }

    public function test_aliquotas_do_catalogo_batem_com_a_classe(): void
    {
        $bps = ['primario' => 300, 'secundario' => 200, 'raro' => 100];
        foreach (DB::table('resource_types')->get() as $r) {
            $this->assertSame($bps[$r->tax_class], $r->tax_bps, "alíquota de {$r->code}");
        }
    }

    /** Todo recurso citado em custo precisa existir no catálogo, senão as FKs quebram. */
    public function test_todo_recurso_usado_em_custo_existe_no_catalogo(): void
    {
        $catalogo = DB::table('resource_types')->pluck('code')->flip();
        foreach (DB::table('building_specs')->pluck('cost_json') as $json) {
            foreach (array_keys(json_decode($json, true)) as $recurso) {
                $this->assertTrue($catalogo->has($recurso), "recurso '{$recurso}' ausente de resource_types");
            }
        }
    }
}
