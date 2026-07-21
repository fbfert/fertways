<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Zona\ConstruirNaZona;
use App\Domain\Zona\DemolirEstruturaDaZona;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\NeutralZone;
use App\Models\User;
use App\Models\ZoneBuild;
use App\Models\ZoneEvent;
use App\Models\ZoneMaterial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Demolir estrutura da zona (docs/decisoes.md D-138) — o espelho de `Domain\Building\Demolir`
 * (D-59) que a revisão de 2026-07-19 (D-122/D-123, achado 7) encontrou faltando, sem nunca ter
 * sido decidido nem discutido.
 */
class DemolirEstruturaDaZonaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\ComponentRecipeSeeder::class);
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
    }

    private function colono(int $x = 20, int $y = 20): Colony
    {
        $c = app(CreateColony::class)->handle(User::factory()->create(), 'Base', $x, $y);
        $c->update(['fert_micro' => 10_000 * 1_000_000]);

        foreach (['metal_bruto' => 20000, 'ligas_metalicas' => 20000,
                  'componentes_eletronicos' => 5000, 'energia' => 20000] as $r => $q) {
            $c->resources()->where('resource_type', $r)->update(['amount' => $q]);
        }

        return $c->fresh();
    }

    private function zonaDe(Colony $dono, array $extra = []): NeutralZone
    {
        return NeutralZone::create(array_merge([
            'x' => 47, 'y' => 47, 'district' => 'NE', 'mineral' => 'metal_bruto', 'level' => 1,
            'owner_colony_id' => $dono->id,
            'status' => 'ocupada',
            'occupied_at' => now()->subDays(30),
            'protected_until' => now()->subDays(20),
            'command_post_level' => 1,
            'productive_at' => now()->subDays(20),
            'deposit_level' => 1,
            'deposit_amount' => 0,
            'last_extraction_at' => now(),
        ], $extra));
    }

    public function test_demole_uma_estrutura_erguida_e_zera_o_nivel(): void
    {
        $colono = $this->colono();
        $zona = $this->zonaDe($colono, ['wall_level' => 3]);

        app(DemolirEstruturaDaZona::class)->handle($colono, $zona, 'muralha_de_perimetro');

        $this->assertSame(0, $zona->fresh()->wall_level);
    }

    public function test_grava_um_zone_event_com_o_nivel_perdido(): void
    {
        $colono = $this->colono();
        $zona = $this->zonaDe($colono, ['watchtower_level' => 2]);

        app(DemolirEstruturaDaZona::class)->handle($colono, $zona, 'torre_de_vigia');

        $evento = ZoneEvent::where('zone_id', $zona->id)->where('type', 'estrutura_demolida')->first();
        $this->assertNotNull($evento);
        $this->assertSame($colono->id, $evento->colony_id);
        $this->assertSame('torre_de_vigia', $evento->meta['estrutura']);
        $this->assertSame(2, $evento->meta['nivel_perdido']);
    }

    public function test_nao_devolve_material_do_canteiro(): void
    {
        $colono = $this->colono();
        $zona = $this->zonaDe($colono, ['bastion_level' => 1]);

        ZoneMaterial::create(['zone_id' => $zona->id, 'resource_type' => 'metal_bruto', 'amount' => 777]);

        app(DemolirEstruturaDaZona::class)->handle($colono, $zona, 'bastiao');

        $this->assertSame(777, (int) $zona->materiais()->where('resource_type', 'metal_bruto')->value('amount'));
    }

    public function test_a_manutencao_nao_muda_porque_nunca_dependeu_da_estrutura_demolida(): void
    {
        $colono = $this->colono();
        $zona = $this->zonaDe($colono, ['wall_level' => 5, 'watchtower_level' => 5, 'level' => 3]);

        $antes = $zona->fresh()->custoDeManutencao();

        app(DemolirEstruturaDaZona::class)->handle($colono, $zona, 'muralha_de_perimetro');

        $this->assertSame($antes, $zona->fresh()->custoDeManutencao());
    }

    public function test_posto_de_comando_e_indemolivel(): void
    {
        $colono = $this->colono();
        $zona = $this->zonaDe($colono);

        $this->expectException(DomainRuleException::class);
        $this->expectExceptionMessageMatches('/Posto de Comando/');

        app(DemolirEstruturaDaZona::class)->handle($colono, $zona, 'posto_de_comando');
    }

    public function test_nao_se_demole_estrutura_nunca_erguida(): void
    {
        $colono = $this->colono();
        $zona = $this->zonaDe($colono);

        try {
            app(DemolirEstruturaDaZona::class)->handle($colono, $zona, 'muralha_de_perimetro');
            $this->fail('deveria ter recusado');
        } catch (DomainRuleException $e) {
            $this->assertSame('nada_para_demolir', $e->codigo);
        }
    }

    public function test_nao_se_demole_o_que_esta_em_obra(): void
    {
        $colono = $this->colono();
        $zona = $this->zonaDe($colono, ['wall_level' => 1]);

        ZoneBuild::create([
            'zone_id' => $zona->id, 'structure' => 'muralha_de_perimetro',
            'target_level' => 2, 'finishes_at' => now()->addHours(4),
        ]);

        try {
            app(DemolirEstruturaDaZona::class)->handle($colono, $zona, 'muralha_de_perimetro');
            $this->fail('deveria ter recusado');
        } catch (DomainRuleException $e) {
            $this->assertSame('demolir_em_obra', $e->codigo);
        }
    }

    public function test_nao_se_demole_sob_cerco(): void
    {
        $colono = $this->colono();
        $zona = $this->zonaDe($colono, ['wall_level' => 1, 'sieged_at' => now()]);

        try {
            app(DemolirEstruturaDaZona::class)->handle($colono, $zona, 'muralha_de_perimetro');
            $this->fail('deveria ter recusado');
        } catch (DomainRuleException $e) {
            $this->assertSame('zona_cercada', $e->codigo);
        }
    }

    public function test_nao_se_demole_zona_de_outro_dono(): void
    {
        $dono = $this->colono();
        $outro = $this->colono(21, 20);
        $zona = $this->zonaDe($dono, ['wall_level' => 1]);

        try {
            app(DemolirEstruturaDaZona::class)->handle($outro, $zona, 'muralha_de_perimetro');
            $this->fail('deveria ter recusado');
        } catch (DomainRuleException $e) {
            $this->assertSame('zona_nao_e_sua', $e->codigo);
        }
    }

    // ── a rota HTTP: mesma palavra que a colônia já exige (D-61) ───────────────────────────────

    public function test_a_rota_exige_a_palavra_demolir(): void
    {
        $colono = $this->colono();
        $zona = $this->zonaDe($colono, ['wall_level' => 1]);

        foreach ([[], ['confirmacao' => 'sim'], ['confirmacao' => 'demolir']] as $tentativa) {
            $this->actingAs($colono->user)
                ->deleteJson("/zones/{$zona->id}/build/muralha_de_perimetro", $tentativa)
                ->assertStatus(422)
                ->assertJsonPath('code', 'confirmacao_invalida');
        }

        $this->assertSame(1, $zona->fresh()->wall_level, 'nada deve ter demolido ainda');

        $this->actingAs($colono->user)
            ->deleteJson("/zones/{$zona->id}/build/muralha_de_perimetro", ['confirmacao' => 'DEMOLIR'])
            ->assertOk()
            ->assertJsonPath('demolida', true);

        $this->assertSame(0, $zona->fresh()->wall_level);
    }

    public function test_a_obra_seguinte_comeca_do_zero_depois_da_demolicao(): void
    {
        $colono = $this->colono();
        $zona = $this->zonaDe($colono, ['wall_level' => 2]);

        app(DemolirEstruturaDaZona::class)->handle($colono, $zona, 'muralha_de_perimetro');

        ZoneMaterial::create(['zone_id' => $zona->id, 'resource_type' => 'metal_bruto', 'amount' => 400]);
        ZoneMaterial::create(['zone_id' => $zona->id, 'resource_type' => 'ligas_metalicas', 'amount' => 100]);

        app(ConstruirNaZona::class)->handle($colono, $zona->fresh(), 'muralha_de_perimetro');

        $obra = ZoneBuild::where('zone_id', $zona->id)->where('structure', 'muralha_de_perimetro')->first();
        $this->assertSame(1, $obra->target_level, 'a próxima obra é o nível 1, como se nunca tivesse sido erguida');
    }
}
