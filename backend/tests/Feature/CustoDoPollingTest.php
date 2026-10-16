<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Market\Deposito;
use App\Models\Colony;
use App\Models\NeutralZone;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ErgueEstruturasDaZona;
use Tests\TestCase;

/**
 * O custo dos endpoints que o cliente consulta em laço (A2.12, carga; D-277).
 *
 * A carga do jogo não vem de clique: vem do polling. Cada jogador logado bate em `/colony`,
 * `/buildings` e `/queue` a cada 5 s em qualquer tela, e cada tela soma os seus. A medida no banco
 * local achou dois endpoints cujo custo crescia com o tamanho do MUNDO, não do pedido:
 *
 *  - `/zones` fazia 2 consultas por zona do planeta (estruturas e guarnição, lidas uma a uma): 250
 *    consultas e 414 ms para 120 zonas — e o planeta só cresce;
 *  - `/market/account` fazia 7 por tipo de recurso: 190 consultas a cada abertura do Mercado.
 *
 * Estes testes fixam a propriedade, não um número: o custo não cresce com o que há no mundo.
 */
class CustoDoPollingTest extends TestCase
{
    use ErgueEstruturasDaZona;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
        $this->seed(ComponentRecipeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
    }

    private int $cel = 0;

    private function colono(string $nome): Colony
    {
        $this->cel++;

        return app(CreateColony::class)->handle(User::factory()->create(), $nome, 10 + $this->cel * 3, 10);
    }

    /** Zonas de três jeitos — livre, de outro colono com guarnição, minha — para cobrir cada ramo da névoa. */
    private function zonas(int $n, Colony $eu, Colony $outro): void
    {
        for ($i = 0; $i < $n; $i++) {
            $dono = [null, $outro, $eu][$i % 3];
            $zona = $this->criarZonaComEstruturas([
                'x' => 40 + intdiv(NeutralZone::count(), 10), 'y' => 40 + NeutralZone::count() % 10,
                'district' => 'NE', 'mineral' => 'metal_bruto', 'level' => 1,
                'owner_colony_id' => $dono?->id, 'status' => $dono ? 'ocupada' : 'livre',
                'deposit_level' => 1, 'deposit_amount' => 0, 'command_post_level' => 1,
            ]);

            if ($dono) {
                Unit::create(['zone_id' => $zona->id, 'type' => 'robo_minerador', 'level' => 1,
                    'hp_bps' => Unit::INTEIRA, 'status' => 'na_zona']);
            }
        }
    }

    private function consultas(callable $pedido): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $pedido();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    }

    public function test_o_mapa_nao_custa_uma_consulta_por_zona(): void
    {
        $eu = $this->colono('Eu');
        $outro = $this->colono('Outro');
        $this->zonas(3, $eu, $outro);
        // Aquece: a primeira chamada cria linhas de configuração e resolve o login — custo de uma vez.
        $this->actingAs($eu->user->fresh())->getJson('/zones')->assertOk();

        $poucas = $this->consultas(fn () => $this->actingAs($eu->user->fresh())->getJson('/zones')->assertOk());

        $this->zonas(12, $eu, $outro);
        $muitas = $this->consultas(fn () => $this->actingAs($eu->user->fresh())->getJson('/zones')->assertOk());

        // Nem as obras das minhas zonas: tudo vem em lote.
        $this->assertSame($poucas, $muitas, "3 zonas: {$poucas} consultas; 15 zonas: {$muitas}");
    }

    /** O carregamento em lote não pode mudar o que o mapa diz: a guarnição viva, e só a viva. */
    public function test_a_guarnicao_do_mapa_conta_so_o_robo_vivo(): void
    {
        $eu = $this->colono('Eu');
        $zona = $this->criarZonaComEstruturas([
            'x' => 50, 'y' => 50, 'district' => 'NE', 'mineral' => 'metal_bruto', 'level' => 1,
            'owner_colony_id' => $eu->id, 'status' => 'ocupada', 'deposit_level' => 1, 'deposit_amount' => 0,
        ]);
        foreach ([Unit::INTEIRA, Unit::INTEIRA, 0] as $hp) {
            Unit::create(['zone_id' => $zona->id, 'type' => 'robo_minerador', 'level' => 1, 'hp_bps' => $hp, 'status' => 'na_zona']);
        }
        Unit::create(['zone_id' => $zona->id, 'type' => 'sentinela', 'level' => 1, 'hp_bps' => Unit::INTEIRA, 'status' => 'na_zona']);

        $z = collect($this->actingAs($eu->user->fresh())->getJson('/zones')->json('zones'))->firstWhere('id', $zona->id);

        $this->assertSame(2, $z['garrison']);
        $this->assertSame($zona->capacidadeDeposito(), $z['deposit_cap']);
    }

    /** O `/buildings` entra no laço de 5 s de toda tela: o custo não pode crescer com a colônia. */
    public function test_as_construcoes_nao_custam_consultas_por_construcao(): void
    {
        $eu = $this->colono('Eu');
        $eu->buildings()->create(['type' => 'quartel', 'level' => 1]);
        $this->actingAs($eu->user->fresh())->getJson('/buildings')->assertOk();

        $poucas = $this->consultas(fn () => $this->actingAs($eu->user->fresh())->getJson('/buildings')->assertOk());

        // Sem a Oficina: a receita dela é uma leitura a mais, mas uma por colônia (só há uma), e não
        // cresce com o número de construções.
        foreach (['mina_local', 'mina_local', 'laboratorio', 'antena_de_comunicacao'] as $tipo) {
            $eu->buildings()->create(['type' => $tipo, 'level' => 1]);
        }
        $muitas = $this->consultas(fn () => $this->actingAs($eu->user->fresh())->getJson('/buildings')->assertOk());

        $this->assertSame($poucas, $muitas, "antes: {$poucas} consultas; com 4 construções a mais: {$muitas}");
    }

    /** E o pré-carregamento dá o mesmo custo e o mesmo tempo da leitura uma a uma, com override do admin. */
    public function test_as_construcoes_dizem_o_mesmo_custo_que_a_ficha(): void
    {
        $eu = $this->colono('Eu');
        $eu->buildings()->create(['type' => 'quartel', 'level' => 1]);
        DB::table('building_specs_overrides')->insert([
            'building_type' => 'quartel', 'level' => 2, 'build_time_seconds' => 4321,
            'cost_json' => json_encode(['metal_bruto' => 7]), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $q = collect($this->actingAs($eu->user->fresh())->getJson('/buildings')->json())->firstWhere('type', 'quartel');
        $ficha = app(\App\Domain\Building\BuildingSpecs::class);

        $this->assertSame(['metal_bruto' => 7], $q['cost']);
        $this->assertSame(4321, $q['build_time_seconds']);
        $this->assertSame($ficha->nivelMaximo('quartel'), $q['max_level']);

        foreach (collect($this->actingAs($eu->user->fresh())->getJson('/buildings')->json())->whereNotNull('next_level') as $b) {
            $this->assertSame($ficha->para($b['type'], $b['next_level'])['custo'], $b['cost'], $b['type']);
        }
    }

    /** A vitrine do Mercado roda a cada 3 s: o catálogo não pode ler o teto recurso a recurso. */
    public function test_a_vitrine_do_mercado_nao_custa_consultas_por_recurso(): void
    {
        $eu = $this->colono('Eu');

        $n = $this->consultas(fn () => $this->actingAs($eu->user->fresh())->getJson('/market/orders')->assertOk());
        $catalogo = collect($this->actingAs($eu->user->fresh())->getJson('/market/orders')->json('catalogo'));

        $this->assertLessThanOrEqual(10, $n, "a vitrine fez {$n} consultas");
        foreach ($catalogo as $t) {
            $this->assertSame(Deposito::teto($t['code']), $t['teto_deposito'], $t['code']);
        }
    }

    public function test_a_conta_do_mercado_nao_custa_consultas_por_recurso(): void
    {
        $eu = $this->colono('Eu');

        $n = $this->consultas(fn () => $this->actingAs($eu->user->fresh())->getJson('/market/account')->assertOk());

        $this->assertLessThanOrEqual(20, $n, "a conta do Mercado fez {$n} consultas");
    }

    /** E o lote dá os mesmos números que o cálculo recurso a recurso, que o resto do jogo usa. */
    public function test_a_conta_do_mercado_bate_com_o_deposito(): void
    {
        $eu = $this->colono('Eu');
        DB::table('market_accounts')->insert([
            ['colony_id' => $eu->id, 'resource_type' => 'metal_bruto', 'amount' => 700, 'created_at' => now(), 'updated_at' => now()],
            ['colony_id' => $eu->id, 'resource_type' => 'ligas_metalicas', 'amount' => 30, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('market_orders')->insert([
            'colony_id' => $eu->id, 'resource_type' => 'metal_bruto', 'side' => 'sell', 'status' => 'aberta',
            'qty' => 200, 'escrow_resource_qty' => 200, 'price_micro' => 1_000_000,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $linhas = collect($this->actingAs($eu->user->fresh())->getJson('/market/account')->json('deposito'))->keyBy('resource_type');

        foreach (['metal_bruto', 'ligas_metalicas', 'cristal_raro'] as $r) {
            if (! $linhas->has($r)) {
                continue;
            }
            $this->assertSame(Deposito::teto($r), $linhas[$r]['teto'], $r);
            $this->assertSame(Deposito::livre($eu->id, $r), $linhas[$r]['livre'], $r);
            $this->assertSame(Deposito::ocupado($eu->id, $r) - $linhas[$r]['no_deposito'], $linhas[$r]['em_ofertas'], $r);
        }
        $this->assertSame(700, $linhas['metal_bruto']['no_deposito']);
        $this->assertSame(200, $linhas['metal_bruto']['em_ofertas']);
    }
}
