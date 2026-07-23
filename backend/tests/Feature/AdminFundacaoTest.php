<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AuditEntry;
use App\Models\FoundingCell;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * "Liberar Fundação" no mapa admin (D-147): só o Dono, alterna na hora, sem confirmação — porque
 * é reversível e não mexe em nenhuma colônia já existente.
 */
class AdminFundacaoTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_o_dono_libera_e_depois_fecha_a_mesma_celula(): void
    {
        $dono = $this->dono();

        $resposta = $this->actingAs($dono, 'admin')
            ->postJson('/admin/mapa/fundacao/alternar', ['x' => 40, 'y' => 40]);

        $resposta->assertOk()->assertJson(['liberada' => true]);
        $this->assertDatabaseHas('founding_cells', ['x' => 40, 'y' => 40]);
        $this->assertDatabaseHas('audit_log', ['acao' => 'fundacao.liberar']);

        $resposta = $this->actingAs($dono, 'admin')
            ->postJson('/admin/mapa/fundacao/alternar', ['x' => 40, 'y' => 40]);

        $resposta->assertOk()->assertJson(['liberada' => false]);
        $this->assertDatabaseMissing('founding_cells', ['x' => 40, 'y' => 40]);
        $this->assertDatabaseHas('audit_log', ['acao' => 'fundacao.trancar']);
    }

    public function test_um_operador_nao_libera_celula(): void
    {
        $this->actingAs($this->operador(), 'admin')
            ->postJson('/admin/mapa/fundacao/alternar', ['x' => 40, 'y' => 40])
            ->assertForbidden();

        $this->assertDatabaseMissing('founding_cells', ['x' => 40, 'y' => 40]);
    }

    public function test_um_colono_nao_libera_celula(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/admin/mapa/fundacao/alternar', ['x' => 40, 'y' => 40])
            ->assertUnauthorized();
    }

    public function test_recusa_celulas_que_nao_sao_periferia_de_verdade(): void
    {
        $dono = $this->dono();

        // Capital.
        $this->actingAs($dono, 'admin')
            ->postJson('/admin/mapa/fundacao/alternar', ['x' => 0, 'y' => 0])
            ->assertStatus(422)->assertJson(['code' => 'celula_da_capital']);

        // Anel livre (d=4,24) — nem chega a ser periferia.
        $this->actingAs($dono, 'admin')
            ->postJson('/admin/mapa/fundacao/alternar', ['x' => 3, 'y' => 3])
            ->assertStatus(422)->assertJson(['code' => 'nao_e_periferia']);

        // Disco de founders — continua com a regra automática de sempre (D-51), não a lista.
        $this->actingAs($dono, 'admin')
            ->postJson('/admin/mapa/fundacao/alternar', ['x' => 0, 'y' => 1])
            ->assertStatus(422)->assertJson(['code' => 'nao_e_periferia']);

        // Zona neutra — periferia geometricamente, mas trava estrutural (D-52).
        $this->actingAs($dono, 'admin')
            ->postJson('/admin/mapa/fundacao/alternar', ['x' => 50, 'y' => 50])
            ->assertStatus(422)->assertJson(['code' => 'celula_de_zona_neutra']);

        // Fora do mapa.
        $this->actingAs($dono, 'admin')
            ->postJson('/admin/mapa/fundacao/alternar', ['x' => 90, 'y' => 90])
            ->assertStatus(422);

        $this->assertDatabaseCount('founding_cells', 0);
    }

    public function test_a_aba_mapa_mostra_a_celula_ja_liberada(): void
    {
        FoundingCell::create(['x' => 40, 'y' => 40]);

        $this->actingAs($this->dono(), 'admin')->get('/admin/mapa')
            ->assertOk()
            ->assertSee('data-celula-fundacao="40:40"', false)
            ->assertSee('data-marcar-fundacao', false);

        $this->actingAs($this->operador(), 'admin')->get('/admin/mapa')
            ->assertOk()
            ->assertSee('data-celula-fundacao="40:40"', false)
            ->assertDontSee('data-marcar-fundacao', false);
    }
}
