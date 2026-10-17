<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Guerra\Atacar;
use App\Domain\Guerra\Cemiterio;
use App\Domain\Guerra\ResolverCombates;
use App\Models\Colony;
use App\Models\Combat;
use App\Models\NeutralZone;
use App\Models\RoboEnterrado;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ErgueEstruturasDaZona;
use Tests\TestCase;

/**
 * O Cemitério de Robôs (D-290): quem morre em combate é enterrado na zona onde caiu, e o Cemitério
 * erguido mostra os mortos. Sem função mecânica, como o GDD quer.
 */
class CemiterioDeRobosTest extends TestCase
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

    private const CELULAS = [[20, 20], [-20, 20], [20, -20], [-20, -20]];

    private int $cel = 0;

    private int $zonas = 0;

    private function colono(string $nome): Colony
    {
        [$x, $y] = self::CELULAS[$this->cel++];
        $c = app(CreateColony::class)->handle(User::factory()->create(), $nome, $x, $y);
        $c->update(['fert_micro' => 10_000 * 1_000_000]);

        return $c->fresh();
    }

    private function zonaDe(Colony $dono, int $robos, array $extra = []): NeutralZone
    {
        $cel = [[47, 47], [48, 48], [49, 49]][$this->zonas++];

        $zona = $this->criarZonaComEstruturas(array_merge([
            'x' => $cel[0], 'y' => $cel[1], 'district' => 'NE', 'mineral' => 'metal_bruto', 'level' => 1,
            'owner_colony_id' => $dono->id, 'status' => 'ocupada',
            'occupied_at' => now()->subDays(30), 'protected_until' => now()->subDays(20),
            'command_post_level' => 1, 'productive_at' => now()->subDays(20),
            'deposit_level' => 1, 'deposit_amount' => 0, 'last_extraction_at' => now(),
        ], $extra));

        for ($i = 0; $i < $robos; $i++) {
            Unit::create(['zone_id' => $zona->id, 'type' => 'robo_minerador', 'level' => 1,
                'hp_bps' => Unit::INTEIRA, 'status' => 'na_zona']);
        }

        return $zona->fresh();
    }

    private function sentinelas(Colony $c, int $quantas): array
    {
        return collect(range(1, $quantas))->map(fn () => Unit::create([
            'colony_id' => $c->id, 'type' => 'sentinela', 'level' => 1,
            'hp_bps' => Unit::INTEIRA, 'status' => 'casa',
        ])->id)->all();
    }

    private function correr(Combat $combate): Combat
    {
        $motor = app(ResolverCombates::class);

        for ($i = 0; $i < 72 * 6; $i++) {
            if (! $combate->fresh()->vivo()) {
                break;
            }
            $this->travelTo(now()->addMinutes(Combat::RODADA_MINUTOS));
            $motor->handle(now());
        }

        return $combate->fresh();
    }

    public function test_a_invasao_vencida_enterra_a_guarnicao_e_os_atacantes_que_cairam(): void
    {
        $atk = $this->colono('Atacante');
        $def = $this->colono('Defensor');
        $zona = $this->zonaDe($def, 32);

        $fim = $this->correr(app(Atacar::class)->handle($atk, $zona, 'invasao', $this->sentinelas($atk, 12)));
        $this->assertSame('vitoria_atacante', $fim->status);

        // As baixas do atacante batem com o relatório da batalha (D-275): quem não voltou, morreu.
        $mortosDoAtacante = 12 - Unit::where('colony_id', $atk->id)->count();
        $this->assertSame($fim->resultado['atacantes_inicio'] - $fim->resultado['atacantes_de_pe'], $mortosDoAtacante);

        $this->assertSame(32, RoboEnterrado::where('zone_id', $zona->id)->where('colony_id', $def->id)
            ->where('type', 'robo_minerador')->count(), 'a guarnição inteira, com o dono de antes da conquista');
        $this->assertSame($mortosDoAtacante, RoboEnterrado::where('zone_id', $zona->id)->where('colony_id', $atk->id)
            ->where('type', 'sentinela')->count());
        $this->assertSame(32 + $mortosDoAtacante, RoboEnterrado::where('combat_id', $fim->id)->count());
    }

    public function test_o_ataque_repelido_enterra_so_quem_morreu(): void
    {
        $atk = $this->colono('Atacante');
        $zona = $this->zonaDe($this->colono('Defensor'), 20);

        $fim = $this->correr(app(Atacar::class)->handle($atk, $zona, 'invasao', $this->sentinelas($atk, 1)));
        $this->assertSame('repelido', $fim->status);

        $this->assertSame(1, RoboEnterrado::where('zone_id', $zona->id)->count(), 'ferido não é morto');
        $this->assertSame($atk->id, RoboEnterrado::where('zone_id', $zona->id)->value('colony_id'));
    }

    public function test_sem_cemiterio_nao_ha_lapide_mas_o_enterro_acontece(): void
    {
        $atk = $this->colono('Atacante');
        $def = $this->colono('Defensor');
        $zona = $this->zonaDe($def, 20);

        $this->correr(app(Atacar::class)->handle($atk, $zona, 'invasao', $this->sentinelas($atk, 1)));

        $this->assertNull(app(Cemiterio::class)->lapide($zona->fresh()));
        $this->assertNull($this->actingAs($def->user)->getJson("/zones/{$zona->id}")->assertOk()->json('cemiterio'));

        // Erguido depois, ele encontra os mortos de antes: o cemitério é a lápide, não a cova.
        $this->ergueEstruturas($zona, ['cemiterio_de_robos' => 1]);
        $this->assertSame(1, app(Cemiterio::class)->lapide($zona->fresh())['total']);
    }

    public function test_a_lapide_conta_por_tipo_por_dono_e_por_batalha(): void
    {
        $atk = $this->colono('Atacante');
        $def = $this->colono('Defensor');
        $zona = $this->zonaDe($def, 20, ['cemetery_level' => 1]);

        $primeira = $this->correr(app(Atacar::class)->handle($atk, $zona, 'invasao', $this->sentinelas($atk, 1)));
        $this->travelTo(now()->addHours(Combat::COOLDOWN_HORAS + 1));
        $segunda = $this->correr(app(Atacar::class)->handle($atk, $zona->fresh(), 'invasao', $this->sentinelas($atk, 1)));

        $l = $this->actingAs($def->user)->getJson("/zones/{$zona->id}")->assertOk()->json('cemiterio');

        $this->assertSame(2, $l['total']);
        $this->assertSame(['sentinela' => 2], $l['por_tipo']);
        $this->assertSame([['colonia' => 'Atacante', 'mortos' => 2]], $l['por_colonia']);
        $this->assertSame([$segunda->id, $primeira->id], array_column($l['batalhas'], 'combat_id'));
        $this->assertSame('invasao', $l['batalhas'][0]['tipo']);
        $this->assertNotNull($l['desde']);
    }

    /** O peso histórico à vista de todos: o mapa mostra os mortos das zonas que têm Cemitério. */
    public function test_o_mapa_mostra_os_mortos_so_onde_ha_cemiterio(): void
    {
        $atk = $this->colono('Atacante');
        $def = $this->colono('Defensor');
        $com = $this->zonaDe($def, 20, ['cemetery_level' => 1]);
        $sem = $this->zonaDe($def, 20);

        $this->correr(app(Atacar::class)->handle($atk, $com, 'invasao', $this->sentinelas($atk, 1)));
        $this->correr(app(Atacar::class)->handle($atk, $sem, 'invasao', $this->sentinelas($atk, 1)));

        $zonas = collect($this->actingAs($this->colono('Curioso')->user)->getJson('/zones')->assertOk()->json('zones'))->keyBy('id');

        $this->assertSame(1, $zonas[$com->id]['mortos']);
        $this->assertNull($zonas[$sem->id]['mortos']);
    }
}
