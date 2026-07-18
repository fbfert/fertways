<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Treasury\Tesouro;
use App\Models\Admin;
use App\Models\Colony;
use App\Models\TreasuryHolding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Subsídios (D-113): a aba Economia troca "Enviar Recursos" (um recurso, uma colônia, por vez) por
 * duas ações — mandar vários recursos de uma vez pra um colono, ou a mesma cesta pra todas as
 * colônias — ambas todo-ou-nada.
 */
class SubsidiosAdminTest extends TestCase
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

    private int $proximoSlot = 0;

    private function colonia(string $nick): Colony
    {
        $user = User::factory()->create(['email' => "{$nick}@t.test", 'nickname' => $nick]);

        return app(CreateColony::class)->handle($user, "Colônia {$nick}", 10 + $this->proximoSlot++, 20)->fresh();
    }

    private function estoque(Colony $c, string $recurso): int
    {
        return (int) $c->resources()->where('resource_type', $recurso)->value('amount');
    }

    private function tesouro(string $recurso, int $qtd): void
    {
        TreasuryHolding::updateOrCreate(['resource_type' => $recurso], ['amount' => $qtd]);
    }

    // ── Mandar pra um colono ────────────────────────────────────────────────

    public function test_manda_varios_recursos_de_uma_vez_pra_um_colono(): void
    {
        $c = $this->colonia('alvo');
        $ligasAntes = $this->estoque($c, 'ligas_metalicas');
        $aguaAntes = $this->estoque($c, 'agua');
        $this->tesouro('ligas_metalicas', 1_000);
        $this->tesouro('agua', 1_000);

        $this->actingAs($this->operador(), 'admin')
            ->post(route('admin.tesouro.subsidio_colono'), [
                'colony_id' => $c->id,
                'quantidade' => ['ligas_metalicas' => 100, 'agua' => 50],
            ])
            ->assertSessionHas('ok');

        $this->assertSame($ligasAntes + 100, $this->estoque($c, 'ligas_metalicas'));
        $this->assertSame($aguaAntes + 50, $this->estoque($c, 'agua'));
        $this->assertSame(900, (int) TreasuryHolding::whereKey('ligas_metalicas')->value('amount'));
    }

    public function test_manda_fert_junto_com_recursos_pro_mesmo_colono(): void
    {
        $c = $this->colonia('alvo');
        $saldoFertAntes = $c->fert_micro;
        $aguaAntes = $this->estoque($c, 'agua');
        TreasuryHolding::updateOrCreate(['resource_type' => Tesouro::FERT], ['amount' => 10 * Colony::MICRO_POR_FERT]);
        $this->tesouro('agua', 1_000);

        $this->actingAs($this->operador(), 'admin')
            ->post(route('admin.tesouro.subsidio_colono'), [
                'colony_id' => $c->id,
                'quantidade' => [Tesouro::FERT => '2.5', 'agua' => 50],
            ])
            ->assertSessionHas('ok');

        $this->assertSame($saldoFertAntes + (int) round(2.5 * Colony::MICRO_POR_FERT), $c->fresh()->fert_micro);
        $this->assertSame($aguaAntes + 50, $this->estoque($c, 'agua'));
    }

    public function test_se_um_recurso_nao_couber_nenhum_e_entregue(): void
    {
        $c = $this->colonia('alvo');
        $ligasAntes = $this->estoque($c, 'ligas_metalicas');
        $aguaAntes = $this->estoque($c, 'agua');
        $this->tesouro('ligas_metalicas', 1_000);
        $this->tesouro('agua', 10);   // menos do que vai ser pedido

        $this->actingAs($this->operador(), 'admin')
            ->post(route('admin.tesouro.subsidio_colono'), [
                'colony_id' => $c->id,
                'quantidade' => ['ligas_metalicas' => 100, 'agua' => 50],
            ])
            ->assertSessionHas('erro');

        // Todo-ou-nada: mesmo as Ligas, que o Tesouro tinha de sobra, não foram entregues.
        $this->assertSame($ligasAntes, $this->estoque($c, 'ligas_metalicas'));
        $this->assertSame($aguaAntes, $this->estoque($c, 'agua'));
        $this->assertSame(1_000, (int) TreasuryHolding::whereKey('ligas_metalicas')->value('amount'));
    }

    public function test_recurso_fora_do_catalogo_e_ignorado_silenciosamente(): void
    {
        $c = $this->colonia('alvo');
        $aguaAntes = $this->estoque($c, 'agua');
        $this->tesouro('agua', 1_000);

        $this->actingAs($this->operador(), 'admin')
            ->post(route('admin.tesouro.subsidio_colono'), [
                'colony_id' => $c->id,
                'quantidade' => ['agua' => 50, 'recurso_fantasma' => 999],
            ])
            ->assertSessionHas('ok');

        $this->assertSame($aguaAntes + 50, $this->estoque($c, 'agua'));
    }

    public function test_sem_nenhum_recurso_com_quantidade_positiva_e_recusado(): void
    {
        $c = $this->colonia('alvo');

        $this->actingAs($this->operador(), 'admin')
            ->post(route('admin.tesouro.subsidio_colono'), [
                'colony_id' => $c->id,
                'quantidade' => ['agua' => 0],
            ])
            ->assertSessionHasErrors('quantidade');
    }

    // ── Mandar para todos colonos ───────────────────────────────────────────

    public function test_manda_a_mesma_cesta_pra_todas_as_colonias(): void
    {
        $a = $this->colonia('a');
        $b = $this->colonia('b');
        $aAntes = $this->estoque($a, 'agua');
        $bAntes = $this->estoque($b, 'agua');
        $this->tesouro('agua', 1_000);

        $this->actingAs($this->operador(), 'admin')
            ->post(route('admin.tesouro.subsidio_todos'), ['quantidade' => ['agua' => 100]])
            ->assertSessionHas('ok');

        $this->assertSame($aAntes + 100, $this->estoque($a, 'agua'));
        $this->assertSame($bAntes + 100, $this->estoque($b, 'agua'));
        $this->assertSame(800, (int) TreasuryHolding::whereKey('agua')->value('amount'));
    }

    public function test_sem_saldo_pro_total_agregado_ninguem_recebe(): void
    {
        $a = $this->colonia('a');
        $b = $this->colonia('b');
        $aAntes = $this->estoque($a, 'agua');
        $bAntes = $this->estoque($b, 'agua');
        // 150 no Tesouro, mas 100 × 2 colônias = 200 pedidos — não comporta.
        $this->tesouro('agua', 150);

        $this->actingAs($this->operador(), 'admin')
            ->post(route('admin.tesouro.subsidio_todos'), ['quantidade' => ['agua' => 100]])
            ->assertSessionHasErrors('quantidade');

        $this->assertSame($aAntes, $this->estoque($a, 'agua'));
        $this->assertSame($bAntes, $this->estoque($b, 'agua'));
        $this->assertSame(150, (int) TreasuryHolding::whereKey('agua')->value('amount'));
    }

    public function test_sem_colonias_fundadas_e_recusado(): void
    {
        $this->tesouro('agua', 1_000);

        $this->actingAs($this->operador(), 'admin')
            ->post(route('admin.tesouro.subsidio_todos'), ['quantidade' => ['agua' => 100]])
            ->assertSessionHasErrors('quantidade');
    }

    // ── Comum ────────────────────────────────────────────────────────────────

    public function test_as_duas_rotas_exigem_admin_autenticado(): void
    {
        $c = $this->colonia('alvo');

        $this->post(route('admin.tesouro.subsidio_colono'), [
            'colony_id' => $c->id, 'quantidade' => ['agua' => 10],
        ])->assertRedirect('/admin/login');

        $this->post(route('admin.tesouro.subsidio_todos'), [
            'quantidade' => ['agua' => 10],
        ])->assertRedirect('/admin/login');
    }

    public function test_a_aba_subsidios_aparece_na_tela_com_os_dois_modos(): void
    {
        $this->seed(\Database\Seeders\TreasurySeeder::class);

        $this->actingAs($this->operador(), 'admin')
            ->get('/admin/economia?aba=subsidios')
            ->assertOk()
            ->assertSee('Subsídios')
            ->assertSee('Mandar pra um colono')
            ->assertSee('Mandar para todos colonos');
    }
}
