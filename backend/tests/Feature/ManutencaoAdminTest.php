<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Gestão de Construções — Manutenção (D-112): consumo extra de recursos por hora, por
 * construção, editável pelo operador.
 */
class ManutencaoAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\ComponentRecipeSeeder::class);
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
    }

    private function operador(): Admin
    {
        return Admin::create([
            'name' => 'Op', 'email' => 'op@fertways.test',
            'password' => Hash::make('segredo-forte-1234'), 'role' => Admin::OPERADOR,
        ]);
    }

    public function test_admin_configura_consumo_extra_de_uma_construcao(): void
    {
        $this->actingAs($this->operador(), 'admin')
            ->post('/admin/construcoes/manutencao', [
                'building_type' => 'laboratorio',
                'recursos' => "energia:5\nagua:2",
            ])
            ->assertRedirect();

        $linhas = DB::table('manutencao_estruturas')
            ->where('building_type', 'laboratorio')->pluck('qtd_hora', 'resource_type');

        $this->assertSame(5, $linhas['energia']);
        $this->assertSame(2, $linhas['agua']);
    }

    public function test_salvar_de_novo_substitui_o_conjunto_inteiro(): void
    {
        DB::table('manutencao_estruturas')->insert([
            ['building_type' => 'laboratorio', 'resource_type' => 'energia', 'qtd_hora' => 5, 'created_at' => now(), 'updated_at' => now()],
            ['building_type' => 'laboratorio', 'resource_type' => 'agua', 'qtd_hora' => 2, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->actingAs($this->operador(), 'admin')
            ->post('/admin/construcoes/manutencao', [
                'building_type' => 'laboratorio',
                'recursos' => "energia:9",
            ])
            ->assertRedirect();

        $linhas = DB::table('manutencao_estruturas')->where('building_type', 'laboratorio')->get();

        $this->assertCount(1, $linhas, 'a linha de água, que não veio na segunda submissão, foi removida');
        $this->assertSame(9, (int) $linhas->first()->qtd_hora);
    }

    public function test_textarea_vazia_zera_a_manutencao_da_construcao(): void
    {
        DB::table('manutencao_estruturas')->insert([
            'building_type' => 'laboratorio', 'resource_type' => 'energia', 'qtd_hora' => 5,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->operador(), 'admin')
            ->post('/admin/construcoes/manutencao', [
                'building_type' => 'laboratorio',
                'recursos' => '',
            ])
            ->assertRedirect();

        $this->assertSame(0, DB::table('manutencao_estruturas')->where('building_type', 'laboratorio')->count());
    }

    public function test_recurso_raro_e_recusado(): void
    {
        $raro = DB::table('resource_types')->where('tax_class', 'raro')->value('code');

        $this->actingAs($this->operador(), 'admin')
            ->post('/admin/construcoes/manutencao', [
                'building_type' => 'laboratorio',
                'recursos' => "{$raro}:1",
            ])
            ->assertSessionHasErrors('recursos');

        $this->assertSame(0, DB::table('manutencao_estruturas')->count());
    }

    public function test_construcao_inexistente_e_recusada(): void
    {
        $this->actingAs($this->operador(), 'admin')
            ->post('/admin/construcoes/manutencao', [
                'building_type' => 'castelo_de_areia',
                'recursos' => 'energia:1',
            ])
            ->assertSessionHasErrors('building_type');
    }

    public function test_a_rota_exige_admin_autenticado(): void
    {
        $this->post('/admin/construcoes/manutencao', [
            'building_type' => 'laboratorio', 'recursos' => 'energia:1',
        ])->assertRedirect('/admin/login');
    }

    public function test_a_aba_manutencao_aparece_na_tela(): void
    {
        $this->actingAs($this->operador(), 'admin')
            ->get('/admin/construcoes?aba=manutencao')
            ->assertOk()
            ->assertSee('Manutenção')
            ->assertSee('Laboratório');
    }
}
