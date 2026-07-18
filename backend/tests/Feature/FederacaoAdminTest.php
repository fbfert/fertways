<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Models\Admin;
use App\Models\Colony;
use App\Models\Federation;
use App\Models\FederationHolding;
use App\Models\TreasuryHolding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Federação — o painel do operador (docs/decisoes.md D-114): leitura + a alavanca de emergência
 * "Dissolver". Sem criar federação nem mover membro pelo admin — só observação e o extremo.
 */
class FederacaoAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\ComponentRecipeSeeder::class);
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
    }

    private int $proximoSlot = 0;

    private function colonia(string $nick): Colony
    {
        $user = User::factory()->create(['email' => "{$nick}@t.test", 'nickname' => $nick]);

        return app(CreateColony::class)->handle($user, "Colônia {$nick}", 10 + $this->proximoSlot++, 20)->fresh();
    }

    private function operador(): Admin
    {
        return Admin::create([
            'name' => 'Op', 'email' => 'op@fertways.test',
            'password' => Hash::make('segredo-forte-1234'), 'role' => Admin::OPERADOR,
        ]);
    }

    public function test_a_lista_de_federacoes_aparece_na_tela(): void
    {
        $lider = $this->colonia('lider');
        $lider->update(['federation_id' => Federation::create(['name' => 'Aliança do Painel'])->id, 'federation_role' => Federation::LIDER]);

        $this->actingAs($this->operador(), 'admin')
            ->get('/admin/federacoes')
            ->assertOk()
            ->assertSee('Aliança do Painel')
            ->assertSee('Federações');
    }

    public function test_o_detalhe_mostra_membros_e_fundo(): void
    {
        $fed = Federation::create(['name' => 'Detalhada']);
        $lider = $this->colonia('lider');
        $lider->update(['federation_id' => $fed->id, 'federation_role' => Federation::LIDER]);
        FederationHolding::create(['federation_id' => $fed->id, 'resource_type' => 'agua', 'amount' => 250]);

        $this->actingAs($this->operador(), 'admin')
            ->get("/admin/federacoes?ver={$fed->id}")
            ->assertOk()
            ->assertSee('Detalhada')
            ->assertSee('Líder')
            ->assertSee('250');
    }

    public function test_dissolver_exige_a_palavra_exata(): void
    {
        $fed = Federation::create(['name' => 'Vai Ficar']);
        $lider = $this->colonia('lider');
        $lider->update(['federation_id' => $fed->id, 'federation_role' => Federation::LIDER]);

        $this->actingAs($this->operador(), 'admin')
            ->post("/admin/federacoes/{$fed->id}/dissolver", ['confirmacao' => 'dissolver'])
            ->assertSessionHas('erro');

        $this->assertNull($fed->fresh()->disbanded_at);
    }

    public function test_dissolver_desliga_todos_os_membros_e_credita_o_tesouro(): void
    {
        $fed = Federation::create(['name' => 'Vai Dissolver']);
        $lider = $this->colonia('lider');
        $membro = $this->colonia('membro');
        $lider->update(['federation_id' => $fed->id, 'federation_role' => Federation::LIDER]);
        $membro->update(['federation_id' => $fed->id, 'federation_role' => Federation::MEMBRO]);
        FederationHolding::create(['federation_id' => $fed->id, 'resource_type' => 'agua', 'amount' => 400]);
        $tesouroAntes = (int) TreasuryHolding::whereKey('agua')->value('amount');

        $this->actingAs($this->operador(), 'admin')
            ->post("/admin/federacoes/{$fed->id}/dissolver", ['confirmacao' => 'DISSOLVER'])
            ->assertSessionHas('ok');

        $this->assertNotNull($fed->fresh()->disbanded_at);
        $this->assertNull($lider->fresh()->federation_id);
        $this->assertNull($membro->fresh()->federation_id);
        $this->assertSame($tesouroAntes + 400, (int) TreasuryHolding::whereKey('agua')->value('amount'));
    }

    public function test_a_rota_exige_admin_autenticado(): void
    {
        $this->get('/admin/federacoes')->assertRedirect('/admin/login');
    }
}
