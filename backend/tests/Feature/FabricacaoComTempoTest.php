<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Guerra\ConcluirFabricacaoDeUnidades;
use App\Domain\Guerra\FabricarUnidade;
use App\Models\Building;
use App\Models\Colony;
use App\Models\Unit;
use App\Models\User;
use App\Models\WarSetting;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Database\Seeders\TreasurySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fabricar unidade leva tempo (D-272).
 *
 * Desde o D-66 a fabricação era instantânea — "o freio do exército é o Nióbio, não o relógio". O
 * usuário deixou a decisão em aberto e depois mandou seguir as recomendações. A recomendação: no jogo
 * em que tudo leva tempo de verdade (obra, viagem, pesquisa, upgrade de veículo), um exército pronto no
 * clique destoa, e um ataque surpresa montado em um segundo não dá ao alvo tempo nenhum de ver.
 *
 * **Uma linha de montagem por Quartel: as unidades saem uma a uma, 2 minutos × o nível de cada.** Dez
 * Sentinelas nível 1 ficam prontas em 20 minutos. O número é do operador (painel da Guerra), e zero
 * volta ao instantâneo.
 */
class FabricacaoComTempoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
        $this->seed(ComponentRecipeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
        $this->seed(TreasurySeeder::class);
    }

    private function colonia(int $quartel = 2): Colony
    {
        $c = app(CreateColony::class)->handle(User::factory()->create(), 'Base', 20, 20);
        Building::create(['colony_id' => $c->id, 'type' => 'quartel', 'level' => $quartel, 'slot' => 20]);

        foreach (['ligas_metalicas' => 100_000, 'componentes_eletronicos' => 100_000,
            'metal_bruto' => 100_000, 'niobio_alienigena' => 1_000] as $r => $q) {
            $c->resources()->where('resource_type', $r)->update(['amount' => $q]);
        }

        return $c->fresh();
    }

    private function emCasa(Colony $c): int
    {
        return Unit::where('colony_id', $c->id)->where('status', 'casa')->count();
    }

    public function test_o_padrao_e_dois_minutos_por_nivel(): void
    {
        $this->assertSame(2, (int) WarSetting::singleton()->fabricacao_minutos_por_nivel);
    }

    public function test_as_unidades_saem_uma_a_uma(): void
    {
        $c = $this->colonia();
        app(FabricarUnidade::class)->handle($c, 'sentinela', 1, 3);

        $this->assertSame(0, $this->emCasa($c), 'nada pronto no clique');
        $this->assertSame(3, Unit::where('colony_id', $c->id)->where('status', 'em_fabricacao')->count());

        app(ConcluirFabricacaoDeUnidades::class)->handle(now()->addMinutes(4)->addSecond());
        $this->assertSame(2, $this->emCasa($c), 'em 4 minutos, duas');

        app(ConcluirFabricacaoDeUnidades::class)->handle(now()->addMinutes(6)->addSecond());
        $this->assertSame(3, $this->emCasa($c));
    }

    public function test_o_nivel_multiplica_o_tempo(): void
    {
        $c = $this->colonia();
        app(FabricarUnidade::class)->handle($c, 'sentinela', 2, 1);

        app(ConcluirFabricacaoDeUnidades::class)->handle(now()->addMinutes(3));
        $this->assertSame(0, $this->emCasa($c), 'nível 2: 4 minutos');

        app(ConcluirFabricacaoDeUnidades::class)->handle(now()->addMinutes(4)->addSecond());
        $this->assertSame(1, $this->emCasa($c));
    }

    /** Uma linha só: a encomenda nova entra no fim da fila, e não ao lado. */
    public function test_a_encomenda_nova_entra_no_fim_da_fila(): void
    {
        $c = $this->colonia();
        app(FabricarUnidade::class)->handle($c, 'sentinela', 1, 2);
        app(FabricarUnidade::class)->handle($c, 'robo_minerador', 1, 1);

        $robo = Unit::where('colony_id', $c->id)->where('type', 'robo_minerador')->firstOrFail();
        $this->assertEqualsWithDelta(6 * 60, now()->diffInSeconds($robo->arrives_at), 2);
    }

    public function test_zero_e_instantaneo(): void
    {
        WarSetting::singleton()->update(['fabricacao_minutos_por_nivel' => 0]);
        $c = $this->colonia();

        app(FabricarUnidade::class)->handle($c, 'sentinela', 1, 3);

        $this->assertSame(3, $this->emCasa($c));
    }

    /** O número é do operador: o painel da Guerra o mostra e o grava. */
    public function test_o_painel_da_guerra_escreve_o_tempo(): void
    {
        $dono = \App\Models\Admin::create([
            'name' => 'Dona', 'email' => 'dona@fertways.test',
            'password' => \Illuminate\Support\Facades\Hash::make('segredo-forte-1234'), 'role' => \App\Models\Admin::DONO,
        ]);

        $this->actingAs($dono, 'admin')->get('/admin/guerra')->assertOk()
            ->assertSee('name="fabricacao_minutos_por_nivel"', false);

        // O formulário manda todos os parâmetros, como a tela; só o da fabricação muda.
        $form = collect(WarSetting::singleton()->toArray())->except(['id', 'created_at', 'updated_at'])->all();
        $this->actingAs($dono, 'admin')->post('/admin/guerra', ['fabricacao_minutos_por_nivel' => 5] + $form)
            ->assertSessionHasNoErrors()->assertSessionHas('ok');

        $this->assertSame(5, (int) WarSetting::singleton()->fresh()->fabricacao_minutos_por_nivel);
    }

    /** O tick conclui; e a tela do Quartel diz quantas estão na linha e quando sai a próxima. */
    public function test_o_tick_conclui_e_a_api_mostra_a_linha(): void
    {
        $c = $this->colonia();
        app(FabricarUnidade::class)->handle($c, 'sentinela', 1, 2);

        $this->actingAs($c->user)->getJson('/war')->assertOk()
            ->assertJsonPath('em_fabricacao.total', 2)
            ->assertJsonCount(0, 'unidades');

        $this->travelTo(now()->addMinutes(5));
        $this->artisan('fertways:tick')->assertSuccessful();

        $this->assertSame(2, $this->emCasa($c));
    }
}
