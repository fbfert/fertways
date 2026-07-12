<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Logistics\OcuparZonaNeutra;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\NeutralZone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A ocupação de zona neutra (GDD §07; D-52, Fatia 1): custo pesado (Posto + 20 Robôs), tempo de
 * estabelecimento antes de extrair, e a proteção de 8 dias.
 */
class OcuparZonaNeutraTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\ComponentRecipeSeeder::class);
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
    }

    private function colonoAbastecido(): Colony
    {
        $user = User::factory()->create();
        $colony = app(CreateColony::class)->handle($user, 'Base', 20, 20);

        // O bastante para ocupar: Posto (800 MB + 300 F$) + 20 robôs (1200 ligas, 400 comp, 220 MB).
        foreach (['metal_bruto' => 5000, 'ligas_metalicas' => 5000, 'componentes_eletronicos' => 2000] as $r => $q) {
            $colony->resources()->where('resource_type', $r)->update(['amount' => $q]);
        }
        $colony->update(['fert_micro' => 1000 * 1_000_000]);

        return $colony->fresh();
    }

    private function zonaLivre(): NeutralZone
    {
        return NeutralZone::create([
            'x' => 50, 'y' => 50, 'district' => 'nordeste', 'mineral' => 'metal_bruto',
            'level' => 1, 'status' => 'livre', 'deposit_level' => 1,
        ]);
    }

    public function test_ocupar_cobra_o_posto_e_os_20_robos_e_estabelece_a_zona(): void
    {
        $colony = $this->colonoAbastecido();
        $zona = $this->zonaLivre();

        $antesMetal = $colony->resources()->where('resource_type', 'metal_bruto')->value('amount');

        app(OcuparZonaNeutra::class)->handle($colony, $zona);
        $zona->refresh();

        $this->assertSame($colony->id, $zona->owner_colony_id);
        $this->assertSame('protegida', $zona->status);
        $this->assertSame(20, $zona->guarnicao());
        $this->assertSame(1, $zona->command_post_level);

        // Metal Bruto debitado: 800 (Posto) + 20×11 (robôs) = 1020.
        $depois = $colony->resources()->where('resource_type', 'metal_bruto')->value('amount');
        $this->assertSame($antesMetal - 1020, $depois);

        // Ligas 20×60=1200, Componentes 20×20=400.
        $this->assertSame(5000 - 1200, $colony->resources()->where('resource_type', 'ligas_metalicas')->value('amount'));
        $this->assertSame(2000 - 400, $colony->resources()->where('resource_type', 'componentes_eletronicos')->value('amount'));

        // Fert$: 300 debitados.
        $this->assertSame((1000 - 300) * 1_000_000, (int) $colony->fresh()->fert_micro);

        // Ainda não produz: productive_at = agora + 8h (Posto) + 12h (ocupação) = +20h.
        $this->assertFalse($zona->estaProdutiva(now()));
        $this->assertTrue($zona->estaProdutiva(now()->addHours(20)->addMinute()));

        // Proteção de 8 dias (§ seção 0).
        $this->assertTrue($zona->protected_until->greaterThan(now()->addDays(7)));

        // Auditável no ledger.
        $this->assertTrue(Ledger::where('type', 'custo_ocupacao')->where('ref', "zona:{$zona->id}:posto")->exists());
    }

    public function test_nao_ocupa_zona_ja_ocupada(): void
    {
        $colony = $this->colonoAbastecido();
        $zona = $this->zonaLivre();
        app(OcuparZonaNeutra::class)->handle($colony, $zona);

        $this->expectException(\App\Exceptions\DomainRuleException::class);
        app(OcuparZonaNeutra::class)->handle($colony, $zona->fresh());
    }

    public function test_recusa_sem_recursos(): void
    {
        $user = User::factory()->create();
        $colony = app(CreateColony::class)->handle($user, 'Pobre', 20, 20); // nasce sem quase nada
        $zona = $this->zonaLivre();

        $this->expectException(\App\Exceptions\DomainRuleException::class);
        app(OcuparZonaNeutra::class)->handle($colony, $zona);

        // E nada foi cobrado nem a zona tomada.
        $this->assertNull($zona->fresh()->owner_colony_id);
    }

    public function test_endpoint_ocupa(): void
    {
        $colony = $this->colonoAbastecido();
        $zona = $this->zonaLivre();

        $this->actingAs($colony->user)
            ->postJson("/zones/{$zona->id}/occupy")
            ->assertCreated()
            ->assertJson(['status' => 'protegida', 'garrison' => 20]);
    }
}
