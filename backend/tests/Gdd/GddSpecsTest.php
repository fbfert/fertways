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
     * O tempo publicado É derivável — mas da base NÃO INTEIRA de §20.3–20.5
     * ("Gerador de Atmosfera — tempo base 3,5 min"), e com arredondamento BANCÁRIO.
     * Ancorar no nível 1 já arredondado não funciona: 4 × 1,5 = 6, e o GDD diz 5.
     *
     * Uma única célula das 14 tabelas escapa: Tanque de Combustível nível 4, onde
     * 12 × 1,5³ = 40,5 e o GDD publica 41 (half-up), não 40. Provável artefato de
     * planilha. A tabela continua sendo a fonte; a curva é só a conferência.
     */
    public function test_tempo_publicado_sai_da_base_do_gdd_com_arredondamento_bancario(): void
    {
        $bases = json_decode(
            file_get_contents(database_path('seeders/data/build_time_bases.json')), true,
        );
        $this->assertCount(14, $bases, 'os 14 tempos-base de §20.3–20.5');

        $excecoes = ['tanque_de_combustivel' => [4]];
        $conferidos = 0;

        foreach ($bases as $tipo => $base) {
            $niveis = DB::table('building_specs')->where('building_type', $tipo)
                ->orderBy('level')->pluck('build_time_seconds', 'level');

            foreach ($niveis as $level => $segundos) {
                if (in_array($level, $excecoes[$tipo] ?? [], true)) {
                    continue;
                }
                $esperado = (int) round($base * 1.5 ** ($level - 1), 0, PHP_ROUND_HALF_EVEN);
                $this->assertSame(
                    $esperado * 60,
                    $segundos,
                    "{$tipo} n{$level}: GDD=" . $segundos / 60 . "min, curva={$esperado}min",
                );
                $conferidos++;
            }
        }
        $this->assertSame(69, $conferidos, '14 tabelas × 5 níveis, menos 1 exceção');
    }

    /** A exceção existe mesmo. Se o GDD for corrigido, este teste avisa para removê-la. */
    public function test_a_unica_excecao_da_curva_de_tempo_continua_sendo_o_tanque_n4(): void
    {
        $t = DB::table('building_specs')
            ->where(['building_type' => 'tanque_de_combustivel', 'level' => 4])
            ->value('build_time_seconds');

        $this->assertSame(41 * 60, $t, 'GDD publica 41 min onde a curva bancária daria 40');
    }

    /** Custo usa half-UP, tempo usa half-EVEN. Trocar um pelo outro corrompe os dois. */
    public function test_custo_e_tempo_usam_modos_de_arredondamento_diferentes(): void
    {
        // 50 × 1,65 = 82,5. GDD diz 83 (half-up). Half-even daria 82.
        $agua = json_decode(
            DB::table('building_specs')->where(['building_type' => 'gerador_de_atmosfera', 'level' => 2])
                ->value('cost_json'), true,
        )['agua'];
        $this->assertSame(83, $agua);
        $this->assertSame(82, (int) round(50 * 1.65, 0, PHP_ROUND_HALF_EVEN));

        // 7 × 1,5 = 10,5. GDD diz 10 (half-even). Half-up daria 11.
        $reator = DB::table('building_specs')->where(['building_type' => 'reator_de_energia', 'level' => 2])
            ->value('build_time_seconds');
        $this->assertSame(10 * 60, $reator);
        $this->assertSame(11, $this->halfUp(7 * 1.5));
    }

    /** As construções que o GDD não cronometra (D-10). */
    private const SEM_TEMPO_NO_GDD = [
        'central_de_transportes', 'destilaria', 'deposito_de_zona_neutra',
        'furgao_de_comercio', 'caminhao_de_carga', 'drone_de_exploracao',
        'robo_minerador', 'infiltrador', 'predador', 'nave_de_transporte_planetaria',
    ];

    /**
     * Um tempo dessas construções só pode ser NULL ("o GDD não diz") ou explicitamente
     * marcado como derivado. Nunca zero — zero seria construção instantânea — e nunca
     * passando por tempo publicado do GDD.
     */
    public function test_construcoes_sem_tempo_no_gdd_ficam_nulas_ou_marcadas_como_derivadas(): void
    {
        foreach (self::SEM_TEMPO_NO_GDD as $tipo) {
            $linhas = DB::table('building_specs')->where('building_type', $tipo)->get();
            $this->assertNotEmpty($linhas, "{$tipo} não foi semeado");

            foreach ($linhas as $l) {
                $this->assertNotSame(0, $l->build_time_seconds, "{$tipo} n{$l->level}: tempo zero");
                if ($l->build_time_seconds !== null) {
                    $this->assertTrue(
                        (bool) $l->build_time_derivado,
                        "{$tipo} n{$l->level} ganhou tempo sem ser marcado como derivado",
                    );
                }
            }
        }
    }

    /** O inverso: nada que o GDD cronometra pode aparecer como derivado. */
    public function test_tempos_publicados_no_gdd_nunca_sao_marcados_como_derivados(): void
    {
        $derivadosIndevidos = DB::table('building_specs')
            ->where('build_time_derivado', true)
            ->whereNotIn('building_type', self::SEM_TEMPO_NO_GDD)
            ->pluck('building_type')->unique();

        $this->assertEmpty($derivadosIndevidos->all(), 'tempo do GDD marcado como derivado');
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

    /**
     * Metal Bruto é o único preço derivado (§24.8), porque nenhuma tabela de §22 o publica.
     * O teste refaz a fórmula a partir do Oxigênio e das produções máximas do próprio banco:
     * se alguém trocar o valor por um número escolhido a dedo, isto quebra.
     */
    public function test_preco_do_metal_bruto_sai_da_formula_do_gdd(): void
    {
        $ox = DB::table('resource_types')->where('code', 'oxigenio')->first();
        $mb = DB::table('resource_types')->where('code', 'metal_bruto')->first();

        $esperado = ($ox->preco_base_micro / 1_000_000) * $ox->producao_max_hora / $mb->producao_max_hora;
        $esperado4c = round($esperado, 4);

        $this->assertSame(76, $mb->producao_max_hora, 'produção máx/h da Mina Local (§19)');
        $this->assertSame((int) round($esperado4c * 1_000_000), (int) $mb->preco_base_micro);
        $this->assertTrue((bool) $mb->preco_base_derivado, 'preço derivado precisa estar marcado como tal');
    }

    /**
     * §24.8 revoga a fórmula de escassez para recursos fabricados e republica o preço dos
     * Componentes Eletrônicos. §22.2 ainda traz o valor antigo, 0,0333 — trinta e oito vezes
     * menor, e abaixo do próprio custo dos insumos. O catálogo tem de trazer o do §24.8.
     *
     * Dos três preços do §24.8, um por receita do §24.5, vale o do Componente Básico: o estoque
     * é fungível e só cabe um. Decisão do usuário em 2026-07-09, ver docs/decisoes.md D-24.
     */
    public function test_componentes_eletronicos_usam_o_preco_do_24_8_nao_o_do_22_2(): void
    {
        $ce = DB::table('resource_types')->where('code', 'componentes_eletronicos')->first();

        $this->assertSame(1_277_800, (int) $ce->preco_base_micro, '§24.8: Componente Básico, 1,2778 Fert$');
        $this->assertNotSame(33_300, (int) $ce->preco_base_micro, '33300 é o valor revogado do §22.2');

        // O preço é publicado no GDD, não calculado por nós: não é "derivado".
        $this->assertFalse((bool) $ce->preco_base_derivado);

        // Custo de insumos 0,9127 + markup de 40% (§24.8, receitas com minerais raros) dá
        // 1,27778 exatos. O GDD publica quatro casas: 1,2778. Em micro-Fert$, quatro casas são
        // múltiplos de 100 — por isso o valor termina em 00, e não em 80.
        $this->assertSame(1_277_780, (int) round(912_700 * 1.40));
        $this->assertSame(1_277_800, (int) (round(912_700 * 1.40 / 100) * 100));

        // A regra que motiva tudo isto: fabricar não pode dar prejuízo. Com 0,0333 o colono
        // ganharia mais vendendo os insumos crus do que o componente pronto.
        $this->assertGreaterThan(912_700, (int) $ce->preco_base_micro);
    }

    /**
     * O GDD tem três tabelas de preço, e elas divergem. A regra que as concilia (D-33): o §24.8
     * decide qual **família de fórmula** rege cada recurso, e o §07 só fornece número publicado
     * onde o §24.8 não impõe fórmula.
     *
     * Biocombustível: o §24.8 o chama de processado e revoga a escassez, mas não publica número.
     * O §07 publica 0,0345 — o único valor compatível. Decisão do usuário, 2026-07-09.
     */
    public function test_biocombustivel_usa_o_preco_publicado_no_07(): void
    {
        $bio = DB::table('resource_types')->where('code', 'biocombustivel')->first();

        $this->assertSame(34_500, (int) $bio->preco_base_micro, '§07: 0,0345 Fert$');
        $this->assertNotSame(16_600, (int) $bio->preco_base_micro, '16600 é o valor de escassez do §22.2');
        $this->assertFalse((bool) $bio->preco_base_derivado, 'é publicado, não derivado por nós');
    }

    /**
     * O §07 publica "Metal Bruto | Industrial estratégico | 0,1830 Fert$", cinco vezes e meia o
     * nosso valor. Mas o §24.8 mantém nominalmente a fórmula de escassez para o Metal Bruto
     * ("Aplicável a: Oxigênio, Água, Biomassa, Energia, Metal Bruto…"), e a fórmula reproduz
     * Água, Biomassa e Energia exatamente. O usuário arbitrou pelo derivado. Ver D-34.
     */
    public function test_o_preco_do_07_para_metal_bruto_foi_descartado(): void
    {
        $mb = DB::table('resource_types')->where('code', 'metal_bruto')->first();

        $this->assertSame(33_300, (int) $mb->preco_base_micro);
        $this->assertNotSame(183_000, (int) $mb->preco_base_micro, '0,1830 do §07 é o valor descartado');
    }

    public function test_apenas_o_metal_bruto_tem_preco_derivado(): void
    {
        $derivados = DB::table('resource_types')->where('preco_base_derivado', true)->pluck('code');
        $this->assertSame(['metal_bruto'], $derivados->all());

        // Nenhum recurso pode ficar sem preço: a precificação do Mercado Central depende disso.
        $this->assertSame(0, DB::table('resource_types')->whereNull('preco_base_micro')->count());
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
