<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Logistics\MapaFertways;
use App\Models\NeutralZone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O card "quem é esse colono" (D-81) — do Chat privado e do diretório de colônias.
 *
 * Mesma régua de privacidade do diretório (D-37): nome, posição, distância, porte e as zonas que
 * ele ocupa. Nada de recursos, saldo, frota ou reputação — isso nunca é exposto a terceiros em
 * lugar nenhum do jogo.
 */
class PlayerInfoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\ComponentRecipeSeeder::class);
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
    }

    private function colono(int $x, int $y, string $nick): User
    {
        $user = User::factory()->create(['nickname' => $nick, 'tutorial_completed_at' => now()]);
        app(CreateColony::class)->handle($user, "Colônia {$nick}", $x, $y);

        return $user->fresh();
    }

    public function test_o_card_traz_colonia_distancia_porte_e_zonas(): void
    {
        $eu = $this->colono(0, 3, 'eu');
        $outro = $this->colono(0, 6, 'outro');

        NeutralZone::create([
            'x' => 40, 'y' => 40, 'district' => 'NE', 'mineral' => 'metal_bruto', 'level' => 1,
            'owner_colony_id' => $outro->colony->id, 'status' => 'ocupada', 'name' => 'Posto Sentinela',
        ]);

        $this->actingAs($eu)->getJson("/players/{$outro->id}/info")
            ->assertOk()
            ->assertJsonPath('nickname', 'outro')
            ->assertJsonPath('colony.name', 'Colônia outro')
            ->assertJsonPath('colony.x', 0)
            ->assertJsonPath('colony.y', 6)
            ->assertJsonPath('colony.distance', MapaFertways::distancia(0, 3, 0, 6))
            ->assertJsonCount(1, 'zones')
            ->assertJsonPath('zones.0.name', 'Posto Sentinela');
    }

    /** Recursos, saldo, frota e reputação NUNCA aparecem — nem aqui. */
    public function test_o_card_nao_expoe_o_que_e_privado(): void
    {
        $eu = $this->colono(0, 3, 'eu');
        $outro = $this->colono(0, 6, 'outro');

        $corpo = $this->actingAs($eu)->getJson("/players/{$outro->id}/info")->assertOk()->json();

        $this->assertArrayNotHasKey('resources', $corpo['colony'] ?? []);
        $this->assertArrayNotHasKey('fert_micro', $corpo['colony'] ?? []);
        $this->assertArrayNotHasKey('confianca_comercial', $corpo);
        $this->assertArrayNotHasKey('vehicles', $corpo);
    }

    /** Guarnição e depósito das zonas alheias continuam atrás da névoa do Drone — nem aqui vazam. */
    public function test_as_zonas_nao_expoem_guarnicao_nem_deposito(): void
    {
        $eu = $this->colono(0, 3, 'eu');
        $outro = $this->colono(0, 6, 'outro');

        NeutralZone::create([
            'x' => 40, 'y' => 40, 'district' => 'NE', 'mineral' => 'metal_bruto', 'level' => 1,
            'owner_colony_id' => $outro->colony->id, 'status' => 'ocupada', 'deposit_amount' => 900,
        ]);

        $corpo = $this->actingAs($eu)->getJson("/players/{$outro->id}/info")->assertOk()->json();

        $this->assertArrayNotHasKey('deposit_amount', $corpo['zones'][0]);
        $this->assertArrayNotHasKey('garrison', $corpo['zones'][0]);
    }

    /** Sem colônia fundada, o card não quebra — só diz que não há nada a mostrar. */
    public function test_sem_colonia_o_card_vem_vazio(): void
    {
        $eu = $this->colono(0, 3, 'eu');
        $semColonia = User::factory()->create(['nickname' => 'novato']);

        $this->actingAs($eu)->getJson("/players/{$semColonia->id}/info")
            ->assertOk()
            ->assertJsonPath('colony', null)
            ->assertJsonPath('zones', []);
    }
}
