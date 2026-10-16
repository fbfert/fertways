<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Guerra\Atacar;
use App\Domain\Guerra\ResolverCombates;
use App\Models\Colony;
use App\Models\Combat;
use App\Models\NeutralZone;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ErgueEstruturasDaZona;
use Tests\TestCase;

/**
 * Os estados territoriais no mapa do planeta (A2.V4; D-276).
 *
 * A V4 parou em agosto "por falta de jogo": não havia cerco, combate nem marcha para desenhar. O
 * motor existe e o banco local já tem batalha — e o mapa continuava sem desenhar nada disso: a zona
 * sitiada e a zona em batalha eram quadradinhos iguais aos outros.
 *
 * `cercada` e `em_combate` são PÚBLICOS: um cerco fecha as rotas à vista de todos (o D-116 até avisa
 * a federação), e uma batalha de duas horas numa zona não é segredo de ninguém. O que continua
 * privado é a marcha a caminho — essa é da Torre de Vigia (D-67), e o mapa a lê do `/war/combats`.
 */
class EstadoTerritorialNoMapaTest extends TestCase
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
        [$x, $y] = [[20, 20], [-20, 20], [20, -20]][$this->cel++];
        $c = app(CreateColony::class)->handle(User::factory()->create(), $nome, $x, $y);
        $c->update(['fert_micro' => 10_000 * 1_000_000]);

        return $c->fresh();
    }

    private function zonaDe(Colony $dono): NeutralZone
    {
        $zona = $this->criarZonaComEstruturas([
            'x' => 47, 'y' => 47, 'district' => 'NE', 'mineral' => 'metal_bruto', 'level' => 1,
            'owner_colony_id' => $dono->id, 'status' => 'ocupada',
            'occupied_at' => now()->subDays(30), 'protected_until' => now()->subDays(20),
            'command_post_level' => 1, 'productive_at' => now()->subDays(20),
            'deposit_level' => 1, 'deposit_amount' => 0, 'last_extraction_at' => now(),
        ]);

        for ($i = 0; $i < 20; $i++) {
            Unit::create(['zone_id' => $zona->id, 'type' => 'robo_minerador', 'level' => 1,
                'hp_bps' => Unit::INTEIRA, 'status' => 'na_zona']);
        }

        return $zona->fresh();
    }

    private function atacar(Colony $atk, NeutralZone $zona, string $tipo): Combat
    {
        $ids = collect(range(1, 2))->map(fn () => Unit::create([
            'colony_id' => $atk->id, 'type' => 'sentinela', 'level' => 1,
            'hp_bps' => Unit::INTEIRA, 'status' => 'casa',
        ])->id)->all();

        return app(Atacar::class)->handle($atk, $zona, $tipo, $ids);
    }

    private function noMapa(Colony $quem, NeutralZone $zona): array
    {
        return collect($this->actingAs($quem->user)->getJson('/zones')->assertOk()->json('zones'))
            ->firstWhere('id', $zona->id);
    }

    public function test_a_zona_em_paz_nao_tem_estado(): void
    {
        $zona = $this->zonaDe($this->colono('Dona'));
        $z = $this->noMapa($this->colono('Alheia'), $zona);

        $this->assertFalse($z['em_combate']);
        $this->assertFalse($z['cercada']);
    }

    /** A marcha a caminho NÃO acende nada para quem olha de fora: ela é da Torre de Vigia (D-67). */
    public function test_a_marcha_a_caminho_nao_aparece_no_mapa_publico(): void
    {
        $zona = $this->zonaDe($this->colono('Dona'));
        $this->atacar($this->colono('Atacante'), $zona, 'invasao');

        $this->assertFalse($this->noMapa($this->colono('Alheia'), $zona)['em_combate']);
    }

    public function test_a_batalha_em_curso_e_publica(): void
    {
        $zona = $this->zonaDe($this->colono('Dona'));
        $combate = $this->atacar($atk = $this->colono('Atacante'), $zona, 'invasao');
        $alheia = $this->colono('Alheia');

        $this->travelTo($combate->chega_at);
        app(ResolverCombates::class)->handle(now());
        $this->assertSame('em_curso', $combate->fresh()->status);

        $this->assertTrue($this->noMapa($alheia, $zona)['em_combate']);
    }

    public function test_o_cerco_e_publico(): void
    {
        $zona = $this->zonaDe($this->colono('Dona'));
        $combate = $this->atacar($this->colono('Atacante'), $zona, 'cerco');
        $alheia = $this->colono('Alheia');

        $this->travelTo($combate->chega_at);
        app(ResolverCombates::class)->handle(now());

        $z = $this->noMapa($alheia, $zona);
        $this->assertTrue($z['cercada']);
        $this->assertTrue($z['em_combate']);
    }
}
