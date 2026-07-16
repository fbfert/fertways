<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Models\Admin;
use App\Models\AuditEntry;
use App\Models\User;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Visão Geral ganhou abas (D-96): Panorama, Últimos atos, Colônias, Logística — a página era uma
 * coisa só, e crescia sem parar toda vez que uma seção nova entrava.
 */
class AdminDashboardAbasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
        $this->seed(ComponentRecipeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
    }

    private function operador(): Admin
    {
        return Admin::create([
            'name' => 'Op', 'email' => 'op@fertways.test',
            'password' => Hash::make('segredo-forte-1234'), 'role' => Admin::OPERADOR,
        ]);
    }

    public function test_o_panorama_e_o_padrao_e_as_outras_abas_existem(): void
    {
        $admin = $this->operador();

        $this->actingAs($admin, 'admin')->get('/admin')
            ->assertOk()->assertSee('Panorama')->assertDontSee('Últimos atos do painel');

        $this->actingAs($admin, 'admin')->get('/admin?aba=atos')
            ->assertOk()->assertSee('Últimos atos do painel');

        $this->actingAs($admin, 'admin')->get('/admin?aba=colonias')
            ->assertOk()->assertSee('Colônias');

        $this->actingAs($admin, 'admin')->get('/admin?aba=logistica')
            ->assertOk()->assertSee('Logística');
    }

    public function test_a_aba_de_atos_lista_o_que_o_painel_fez(): void
    {
        AuditEntry::create([
            'admin_email' => 'op@fertways.test', 'acao' => 'teste.acao',
            'alvo' => 'user:1', 'resumo' => 'Um ato de teste bem específico',
        ]);

        $this->actingAs($this->operador(), 'admin')
            ->get('/admin?aba=atos')
            ->assertOk()
            ->assertSee('teste.acao')
            ->assertSee('Um ato de teste bem específico');
    }

    public function test_a_aba_de_colonias_lista_a_colonia(): void
    {
        $user = User::factory()->create();
        app(CreateColony::class)->handle($user, 'Colônia Visível', 30, 30);

        $this->actingAs($this->operador(), 'admin')
            ->get('/admin?aba=colonias')
            ->assertOk()
            ->assertSee('Colônia Visível');
    }
}
