<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Logistics\OcuparZonaNeutra;
use App\Domain\Zona\CobrarManutencaoTerritorial;
use App\Models\Colony;
use App\Models\Combat;
use App\Models\Ledger;
use App\Models\NeutralZone;
use App\Models\User;
use App\Models\ZoneEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O Histórico da zona (docs/decisoes.md D-86): posse (ZoneEvent), financeiro (Ledger) e guerra
 * (Combat), numa linha do tempo só. Só o dono vê.
 */
class HistoricoDaZonaTest extends TestCase
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

        foreach (['metal_bruto' => 50_000, 'ligas_metalicas' => 50_000, 'componentes_eletronicos' => 20_000] as $r => $q) {
            $colony->resources()->where('resource_type', $r)->update(['amount' => $q]);
        }
        $colony->update(['fert_micro' => 100_000 * 1_000_000]);
        $colony->forceFill(['xp' => 20_000])->save();

        return $colony->fresh();
    }

    public function test_ocupar_grava_o_evento_de_posse(): void
    {
        $colony = $this->colonoAbastecido();
        $zona = NeutralZone::create([
            'x' => 50, 'y' => 50, 'district' => 'nordeste', 'mineral' => 'metal_bruto',
            'level' => 1, 'status' => 'livre', 'deposit_level' => 1,
        ]);

        app(OcuparZonaNeutra::class)->handle($colony, $zona);

        $this->assertDatabaseHas('zone_events', [
            'zone_id' => $zona->id, 'type' => 'ocupada', 'colony_id' => $colony->id,
        ]);
    }

    public function test_abandonar_grava_o_evento_com_o_dono_que_perdeu(): void
    {
        $colony = $this->colonoAbastecido();
        $zona = NeutralZone::create([
            'x' => 50, 'y' => 50, 'district' => 'nordeste', 'mineral' => 'metal_bruto',
            'level' => 1, 'status' => 'protegida', 'owner_colony_id' => $colony->id,
            'productive_at' => now()->subDay(), 'deposit_level' => 1,
            'maintenance_next_due_at' => now()->subMinute(),
            'maintenance_unpaid_since' => now()->subHours(73),
        ]);

        $resultado = app(CobrarManutencaoTerritorial::class)->handle();
        $this->assertSame(1, $resultado['abandonadas']);

        $this->assertDatabaseHas('zone_events', [
            'zone_id' => $zona->id, 'type' => 'abandonada', 'colony_id' => $colony->id,
        ]);
    }

    public function test_historico_junta_posse_financeiro_e_guerra_ordenado_do_mais_novo(): void
    {
        $dono = $this->colonoAbastecido();
        $outro = app(CreateColony::class)->handle(User::factory()->create(), 'Rival', 30, 30);

        $zona = NeutralZone::create([
            'x' => 50, 'y' => 50, 'district' => 'nordeste', 'mineral' => 'metal_bruto',
            'level' => 1, 'status' => 'protegida', 'owner_colony_id' => $dono->id,
            'deposit_level' => 1,
        ]);

        ZoneEvent::create([
            'zone_id' => $zona->id, 'type' => 'ocupada', 'colony_id' => $dono->id,
            'created_at' => now()->subDays(3),
        ]);

        Ledger::create([
            'colony_id' => $dono->id, 'type' => 'custo_upgrade_zona', 'amount' => -1320,
            'resource_type' => 'metal_bruto', 'ref' => "zona:{$zona->id}:nivel:2",
            'created_at' => now()->subDays(2),
        ]);

        Combat::create([
            'zone_id' => $zona->id, 'attacker_colony_id' => $outro->id, 'defender_colony_id' => $dono->id,
            'tipo' => 'invasao', 'status' => 'vencido_defensor', 'rodada' => 3,
            'chega_at' => now()->subDay(), 'resultado' => ['vencedor' => 'defensor'],
        ]);

        $r = $this->actingAs($dono->user)->getJson("/zones/{$zona->id}/historico")->assertOk();
        $eventos = $r->json('eventos');

        $this->assertCount(3, $eventos);
        // Do mais novo para o mais velho: o combate (ontem), o upgrade (2 dias), a ocupação (3 dias).
        $this->assertSame('guerra', $eventos[0]['categoria']);
        $this->assertSame('financeiro', $eventos[1]['categoria']);
        $this->assertSame('posse', $eventos[2]['categoria']);
        $this->assertSame('Rival', $eventos[0]['atacante']);
        $this->assertSame('Base', $eventos[0]['defensor']);
    }

    /**
     * O Fert$ do Posto de Comando é debitado em MICRO no Ledger (`OcuparZonaNeutra`,
     * `SubirNivelDaZona` — mesma escala de `colonies.fert_micro`), como qualquer lançamento de
     * Fert$ no jogo (`ProfileController::extrato()` já converte assim). Sem a conversão, o
     * Histórico mostrava "-300000000" em vez de "-300" — a tela nunca escondeu o zero a mais,
     * só ninguém tinha convertido de volta.
     */
    public function test_o_custo_em_fert_vem_convertido_de_micro(): void
    {
        $dono = $this->colonoAbastecido();

        $zona = NeutralZone::create([
            'x' => 50, 'y' => 50, 'district' => 'nordeste', 'mineral' => 'metal_bruto',
            'level' => 1, 'status' => 'protegida', 'owner_colony_id' => $dono->id, 'deposit_level' => 1,
        ]);

        Ledger::create([
            'colony_id' => $dono->id, 'type' => 'custo_ocupacao', 'amount' => -300 * 1_000_000,
            'resource_type' => null, 'ref' => "zona:{$zona->id}:posto",
        ]);

        $eventos = $this->actingAs($dono->user)->getJson("/zones/{$zona->id}/historico")
            ->assertOk()->json('eventos');

        $this->assertNull($eventos[0]['recurso']);
        $this->assertSame(-300, $eventos[0]['quantidade']);
    }

    public function test_historico_e_so_do_dono(): void
    {
        $dono = $this->colonoAbastecido();
        $outro = app(CreateColony::class)->handle(User::factory()->create(), 'Curioso', 30, 30);

        $zona = NeutralZone::create([
            'x' => 50, 'y' => 50, 'district' => 'nordeste', 'mineral' => 'metal_bruto',
            'level' => 1, 'status' => 'protegida', 'owner_colony_id' => $dono->id, 'deposit_level' => 1,
        ]);

        $this->actingAs($outro->user)->getJson("/zones/{$zona->id}/historico")
            ->assertStatus(403)->assertJsonPath('code', 'zona_nao_e_sua');
    }
}
