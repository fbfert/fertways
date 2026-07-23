<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Models\Admin;
use App\Models\Colony;
use App\Models\NeutralZone;
use App\Models\User;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A aba Mapa do painel admin (D-145): o planeta 101×101 inteiro, sem névoa, só leitura.
 */
class AdminMapaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
        $this->seed(ComponentRecipeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
    }

    private function admin(): Admin
    {
        return Admin::create(['name' => 'Equipe', 'email' => 'eq@t.test', 'password' => Hash::make('segredo-forte-123')]);
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

    private function colonia(string $nick, int $x, int $y): Colony
    {
        $user = User::create([
            'name' => $nick, 'nickname' => $nick,
            'email' => "{$nick}@t.test", 'password' => Hash::make('segredo-forte-123'),
        ]);

        return app(CreateColony::class)->handle($user, "Colônia {$nick}", $x, $y)->fresh();
    }

    public function test_um_colono_nao_entra_no_mapa_do_admin(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/mapa')->assertRedirect('/admin/login');
    }

    public function test_o_admin_ve_o_mapa_com_colonias_e_zonas(): void
    {
        $c = $this->colonia('fulano', 12, -8);
        $zona = NeutralZone::create([
            'x' => 48, 'y' => 48, 'district' => 'nordeste', 'mineral' => 'metal_bruto',
            'owner_colony_id' => $c->id, 'status' => 'ocupada',
        ]);

        $resposta = $this->actingAs($this->admin(), 'admin')->get('/admin/mapa');

        $resposta->assertOk();
        $resposta->assertViewHas('lado', 101);
        $resposta->assertViewHas('colonias', fn ($colonias) => $colonias->contains('id', $c->id));
        $resposta->assertViewHas('zonas', fn ($zonas) => $zonas->contains('id', $zona->id));
        $resposta->assertSee('Mapa');
        $resposta->assertSee('data-mapa-admin', false);
    }

    public function test_so_o_dono_ve_o_botao_de_mover_colonias(): void
    {
        $this->actingAs($this->dono(), 'admin')->get('/admin/mapa')
            ->assertSee('data-mover-colonias', false);

        $this->actingAs($this->operador(), 'admin')->get('/admin/mapa')
            ->assertDontSee('data-mover-colonias', false);
    }

    public function test_o_dono_move_uma_colonia_pelo_mapa(): void
    {
        $c = $this->colonia('fulano', 12, -8);

        $resposta = $this->actingAs($this->dono(), 'admin')->post('/admin/mapa/realocar', [
            'colony_id' => $c->id, 'x' => 20, 'y' => 30,
            'motivo' => 'teste de realocação pelo mapa', 'confirmacao' => 'REALOCAR',
        ]);

        $resposta->assertRedirect(route('admin.mapa'));
        $resposta->assertSessionHas('ok');
        $this->assertSame([20, 30], [$c->fresh()->x, $c->fresh()->y]);
    }

    public function test_um_operador_nao_move_colonia_pelo_mapa(): void
    {
        $c = $this->colonia('fulano', 12, -8);

        $this->actingAs($this->operador(), 'admin')->post('/admin/mapa/realocar', [
            'colony_id' => $c->id, 'x' => 20, 'y' => 30,
            'motivo' => 'teste', 'confirmacao' => 'REALOCAR',
        ])->assertForbidden();

        $this->assertSame([12, -8], [$c->fresh()->x, $c->fresh()->y]);
    }

    public function test_confirmacao_errada_recusa_o_movimento_pelo_mapa(): void
    {
        $c = $this->colonia('fulano', 12, -8);

        $resposta = $this->actingAs($this->dono(), 'admin')->post('/admin/mapa/realocar', [
            'colony_id' => $c->id, 'x' => 20, 'y' => 30,
            'motivo' => 'teste', 'confirmacao' => 'errado',
        ]);

        $resposta->assertSessionHas('erro');
        $this->assertSame([12, -8], [$c->fresh()->x, $c->fresh()->y]);
    }

    public function test_nao_se_move_para_cima_de_outra_colonia_pelo_mapa(): void
    {
        $a = $this->colonia('fulano', 12, -8);
        $b = $this->colonia('sicrano', 20, 30);

        $resposta = $this->actingAs($this->dono(), 'admin')->post('/admin/mapa/realocar', [
            'colony_id' => $a->id, 'x' => $b->x, 'y' => $b->y,
            'motivo' => 'teste', 'confirmacao' => 'REALOCAR',
        ]);

        $resposta->assertSessionHas('erro');
        $this->assertSame([12, -8], [$a->fresh()->x, $a->fresh()->y]);
    }
}
