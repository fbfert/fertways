<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\BuildQueue;
use App\Models\FilaSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Gestão de Construções — Fila (D-111): quantos itens cabem na fila da colônia e quantas obras a
 * zona neutra comporta em curso ao mesmo tempo — do operador, não mais cravado em código.
 */
class FilaAdminTest extends TestCase
{
    use RefreshDatabase;

    private function operador(): Admin
    {
        return Admin::create([
            'name' => 'Op', 'email' => 'op@fertways.test',
            'password' => Hash::make('segredo-forte-1234'), 'role' => Admin::OPERADOR,
        ]);
    }

    public function test_os_padroes_reproduzem_o_comportamento_de_sempre(): void
    {
        $config = FilaSetting::singleton();

        $this->assertSame(2, $config->colonia_vagas_novato, 'era o hardcoded de sempre pra conta nova');
        $this->assertSame(1, $config->colonia_vagas_padrao, 'era o hardcoded de sempre pra conta padrão');
        $this->assertSame(1, $config->zona_vagas, 'era "uma obra por vez", o comportamento de sempre');
    }

    public function test_admin_ajusta_as_vagas_da_colonia_e_vagasde_reflete(): void
    {
        $this->actingAs($this->operador(), 'admin')
            ->post('/admin/construcoes/fila', [
                'colonia_vagas_novato' => 4,
                'colonia_vagas_padrao' => 2,
                'zona_vagas' => 1,
            ])
            ->assertRedirect();

        $novato = User::factory()->create(['created_at' => now()]);
        $veterano = User::factory()->create(['created_at' => now()->subDays(10)]);

        $this->assertSame(4, BuildQueue::vagasDe($novato));
        $this->assertSame(2, BuildQueue::vagasDe($veterano));
    }

    public function test_admin_ajusta_as_vagas_da_zona(): void
    {
        $this->actingAs($this->operador(), 'admin')
            ->post('/admin/construcoes/fila', [
                'colonia_vagas_novato' => 2,
                'colonia_vagas_padrao' => 1,
                'zona_vagas' => 3,
            ])
            ->assertRedirect();

        $this->assertSame(3, FilaSetting::singleton()->fresh()->zona_vagas);
    }

    public function test_vagas_abaixo_de_um_e_recusado(): void
    {
        $this->actingAs($this->operador(), 'admin')
            ->post('/admin/construcoes/fila', [
                'colonia_vagas_novato' => 0,
                'colonia_vagas_padrao' => 1,
                'zona_vagas' => 1,
            ])
            ->assertSessionHasErrors();

        $this->assertSame(2, FilaSetting::singleton()->colonia_vagas_novato, 'nada foi salvo');
    }

    public function test_a_rota_exige_admin_autenticado(): void
    {
        $this->post('/admin/construcoes/fila', [
            'colonia_vagas_novato' => 3, 'colonia_vagas_padrao' => 2, 'zona_vagas' => 2,
        ])->assertRedirect('/admin/login');
    }

    public function test_a_aba_fila_aparece_na_tela(): void
    {
        $this->actingAs($this->operador(), 'admin')
            ->get('/admin/construcoes?aba=fila')
            ->assertOk()
            ->assertSee('Fila')
            ->assertSee('Obras simultâneas por zona');
    }
}
