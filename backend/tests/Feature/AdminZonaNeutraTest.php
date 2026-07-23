<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Models\Admin;
use App\Models\FoundingCell;
use App\Models\NeutralZone;
use App\Models\User;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * "Criar Zona Neutra" no mapa admin (D-148): as 120 zonas fixas deixam de ser a única fonte — o
 * Dôno cria mais na periferia, escolhendo o mineral. Reversível enquanto a zona estiver livre.
 */
class AdminZonaNeutraTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
        $this->seed(ComponentRecipeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
    }

    private function dono(): Admin
    {
        return Admin::create([
            'name' => 'Dona', 'email' => 'dona@t.test',
            'password' => Hash::make('segredo-forte-123'), 'role' => Admin::DONO,
        ]);
    }

    private function operador(): Admin
    {
        return Admin::create([
            'name' => 'Op', 'email' => 'op@t.test',
            'password' => Hash::make('segredo-forte-123'), 'role' => Admin::OPERADOR,
        ]);
    }

    public function test_o_dono_cria_uma_zona_com_cada_mineral(): void
    {
        $dono = $this->dono();
        $celulas = [
            [20, 20, 'metal_bruto'],
            [21, -21, 'agua'],
            [-22, -22, 'oxigenio'],
            [-23, 23, 'biomassa'],
        ];

        foreach ($celulas as [$x, $y, $mineral]) {
            $resposta = $this->actingAs($dono, 'admin')->postJson('/admin/mapa/zonas/alternar', [
                'x' => $x, 'y' => $y, 'mineral' => $mineral,
            ]);

            $resposta->assertOk()->assertJson(['criada' => true]);
            $this->assertDatabaseHas('neutral_zones', ['x' => $x, 'y' => $y, 'mineral' => $mineral, 'status' => 'livre']);
        }

        $this->assertDatabaseHas('audit_log', ['acao' => 'zona_neutra.criar']);
    }

    public function test_criar_ja_ergue_o_deposito_no_slot_1(): void
    {
        $this->actingAs($this->dono(), 'admin')->postJson('/admin/mapa/zonas/alternar', [
            'x' => 20, 'y' => 20, 'mineral' => 'metal_bruto',
        ])->assertOk();

        $zona = NeutralZone::where('x', 20)->where('y', 20)->firstOrFail();
        $this->assertDatabaseHas('zone_structures', [
            'neutral_zone_id' => $zona->id, 'type' => 'deposito_de_zona_neutra', 'level' => 1,
        ]);
    }

    public function test_o_district_de_uma_zona_nova_segue_o_quadrante(): void
    {
        $this->actingAs($this->dono(), 'admin')->postJson('/admin/mapa/zonas/alternar', [
            'x' => -22, 'y' => -22, 'mineral' => 'oxigenio',
        ])->assertOk();

        $this->assertDatabaseHas('neutral_zones', ['x' => -22, 'y' => -22, 'district' => 'sudoeste']);
    }

    public function test_criar_e_remover_a_mesma_celula_e_reversivel(): void
    {
        $dono = $this->dono();

        $this->actingAs($dono, 'admin')->postJson('/admin/mapa/zonas/alternar', [
            'x' => 20, 'y' => 20, 'mineral' => 'metal_bruto',
        ])->assertOk()->assertJson(['criada' => true]);

        $resposta = $this->actingAs($dono, 'admin')->postJson('/admin/mapa/zonas/alternar', [
            'x' => 20, 'y' => 20,
        ]);

        $resposta->assertOk()->assertJson(['criada' => false]);
        $this->assertDatabaseMissing('neutral_zones', ['x' => 20, 'y' => 20]);
        $this->assertDatabaseHas('audit_log', ['acao' => 'zona_neutra.remover']);
    }

    public function test_remover_apaga_o_deposito_junto_pelo_cascade(): void
    {
        $dono = $this->dono();

        $this->actingAs($dono, 'admin')->postJson('/admin/mapa/zonas/alternar', [
            'x' => 20, 'y' => 20, 'mineral' => 'metal_bruto',
        ])->assertOk();

        $zona = NeutralZone::where('x', 20)->where('y', 20)->firstOrFail();

        $this->actingAs($dono, 'admin')->postJson('/admin/mapa/zonas/alternar', ['x' => 20, 'y' => 20])
            ->assertOk();

        $this->assertDatabaseMissing('zone_structures', ['neutral_zone_id' => $zona->id]);
    }

    public function test_zona_com_dono_nao_e_removida(): void
    {
        $user = User::create([
            'name' => 'fulano', 'nickname' => 'fulano',
            'email' => 'fulano@t.test', 'password' => Hash::make('segredo-forte-123'),
        ]);
        $colonia = app(CreateColony::class)->handle($user, 'Colônia', 10, 10);

        $zona = NeutralZone::create([
            'x' => 20, 'y' => 20, 'district' => 'nordeste', 'mineral' => 'metal_bruto',
            'level' => 1, 'status' => 'ocupada', 'owner_colony_id' => $colonia->id, 'deposit_amount' => 0,
        ]);

        $resposta = $this->actingAs($this->dono(), 'admin')->postJson('/admin/mapa/zonas/alternar', [
            'x' => 20, 'y' => 20,
        ]);

        $resposta->assertStatus(422)->assertJson(['code' => 'zona_ocupada']);
        $this->assertDatabaseHas('neutral_zones', ['id' => $zona->id]);
    }

    public function test_um_operador_nao_cria_nem_remove_zona(): void
    {
        $this->actingAs($this->operador(), 'admin')->postJson('/admin/mapa/zonas/alternar', [
            'x' => 20, 'y' => 20, 'mineral' => 'metal_bruto',
        ])->assertForbidden();

        $this->assertDatabaseCount('neutral_zones', 0);
    }

    public function test_um_colono_nao_cria_zona(): void
    {
        $this->actingAs(User::factory()->create())->postJson('/admin/mapa/zonas/alternar', [
            'x' => 20, 'y' => 20, 'mineral' => 'metal_bruto',
        ])->assertUnauthorized();
    }

    public function test_recusa_criar_fora_da_periferia_ou_com_mineral_invalido(): void
    {
        $dono = $this->dono();

        // Capital.
        $this->actingAs($dono, 'admin')->postJson('/admin/mapa/zonas/alternar', [
            'x' => 0, 'y' => 0, 'mineral' => 'metal_bruto',
        ])->assertStatus(422)->assertJson(['code' => 'celula_da_capital']);

        // Disco de founders — zona é coisa de periferia, o disco é território de colono.
        $this->actingAs($dono, 'admin')->postJson('/admin/mapa/zonas/alternar', [
            'x' => 0, 'y' => 1, 'mineral' => 'metal_bruto',
        ])->assertStatus(422)->assertJson(['code' => 'nao_e_periferia']);

        // Anel livre.
        $this->actingAs($dono, 'admin')->postJson('/admin/mapa/zonas/alternar', [
            'x' => 3, 'y' => 3, 'mineral' => 'metal_bruto',
        ])->assertStatus(422)->assertJson(['code' => 'nao_e_periferia']);

        // Mineral fora da whitelist — a validação do request já recusa (422 de validação).
        $this->actingAs($dono, 'admin')->postJson('/admin/mapa/zonas/alternar', [
            'x' => 20, 'y' => 20, 'mineral' => 'ouro',
        ])->assertStatus(422);

        $this->assertDatabaseCount('neutral_zones', 0);
    }

    public function test_recusa_criar_em_cima_de_celula_de_fundacao_ou_de_colonia(): void
    {
        $dono = $this->dono();

        FoundingCell::create(['x' => 20, 'y' => 20]);
        $this->actingAs($dono, 'admin')->postJson('/admin/mapa/zonas/alternar', [
            'x' => 20, 'y' => 20, 'mineral' => 'metal_bruto',
        ])->assertStatus(422)->assertJson(['code' => 'celula_de_fundacao']);

        $user = User::create([
            'name' => 'sicrano', 'nickname' => 'sicrano',
            'email' => 'sicrano@t.test', 'password' => Hash::make('segredo-forte-123'),
        ]);
        app(CreateColony::class)->handle($user, 'Base', 30, 30);

        $this->actingAs($dono, 'admin')->postJson('/admin/mapa/zonas/alternar', [
            'x' => 30, 'y' => 30, 'mineral' => 'metal_bruto',
        ])->assertStatus(422)->assertJson(['code' => 'celula_ocupada_por_colonia']);

        $this->assertDatabaseCount('neutral_zones', 0);
    }
}
