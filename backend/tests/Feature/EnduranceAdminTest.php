<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Endurance\ComprarPeca;
use App\Domain\Endurance\DescontoDeEndurance;
use App\Domain\Marco\Curva;
use App\Models\Admin;
use App\Models\Colony;
use App\Models\EndurancePieceSpec;
use App\Models\User;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * O CRUD da Loja de Peças da Endurance no painel (D-133) — preço, marco e o efeito (desconto de
 * tributo) de cada uma das 32 peças, antes fixo em `EnduranceSpecs` (D-132).
 */
class EnduranceAdminTest extends TestCase
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

    private function colonia(string $nick, int $marco = 1): Colony
    {
        $user = User::factory()->create(['email' => "{$nick}@t.test", 'nickname' => $nick]);
        $colony = app(CreateColony::class)->handle($user, "Colônia {$nick}", 10, 10);
        $colony->forceFill(['xp' => Curva::xpDoMarco($marco)])->save();

        return $colony->fresh();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function a_migration_semeia_as_32_pecas_com_os_valores_de_antes(): void
    {
        $this->assertSame(32, EndurancePieceSpec::count());

        $comum = EndurancePieceSpec::where('peca_key', 'anel_habitacional:comum')->first();
        $this->assertSame(10, $comum->marco_minimo);
        $this->assertSame(20_000_000, $comum->preco_micro);
        $this->assertSame(100, $comum->desconto_tributo_bps);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function a_tela_lista_as_pecas_agrupadas_por_secao(): void
    {
        $this->actingAs($this->operador(), 'admin')
            ->get('/admin/endurance')
            ->assertOk()
            ->assertSee('Anel Habitacional')
            ->assertSee('Silo de Suprimentos');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function o_admin_edita_preco_marco_e_desconto_de_uma_peca(): void
    {
        $dados = EndurancePieceSpec::pluck('peca_key')->mapWithKeys(fn ($k) => [$k => true])->all();

        $preco = array_fill_keys(array_keys($dados), '20');
        $marco = array_fill_keys(array_keys($dados), 10);
        $desconto = array_fill_keys(array_keys($dados), 100);

        // Só uma linha muda de verdade: as outras 31 recebem o mesmo valor que já tinham.
        $preco['anel_habitacional:comum'] = '35.5';
        $marco['anel_habitacional:comum'] = 15;
        $desconto['anel_habitacional:comum'] = 200;

        $this->actingAs($this->operador(), 'admin')
            ->post('/admin/endurance', compact('preco', 'marco', 'desconto'))
            ->assertRedirect();

        $peca = EndurancePieceSpec::where('peca_key', 'anel_habitacional:comum')->first();
        $this->assertSame(35_500_000, $peca->preco_micro);
        $this->assertSame(15, $peca->marco_minimo);
        $this->assertSame(200, $peca->desconto_tributo_bps);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function a_edicao_do_admin_reflete_na_compra_de_verdade(): void
    {
        EndurancePieceSpec::where('peca_key', 'anel_habitacional:comum')->update([
            'preco_micro' => 999_000_000, // 999 Fert$: acima do que a colônia tem
        ]);

        $a = $this->colonia('a', 10);

        $this->expectExceptionMessage('Faltam');
        app(ComprarPeca::class)->handle($a, 'anel_habitacional:comum');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function a_edicao_do_desconto_reflete_no_bonus_de_tributo(): void
    {
        EndurancePieceSpec::where('peca_key', 'anel_habitacional:comum')->update([
            'desconto_tributo_bps' => 777,
        ]);

        $a = $this->colonia('a', 10);
        app(ComprarPeca::class)->handle($a, 'anel_habitacional:comum');

        $this->assertSame(777, app(DescontoDeEndurance::class)->desconto($a->fresh()));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function chave_forjada_no_post_nao_cria_linha_nova(): void
    {
        $antes = EndurancePieceSpec::count();

        $this->actingAs($this->operador(), 'admin')
            ->post('/admin/endurance', [
                'preco' => ['peca_inventada' => '20'],
                'marco' => ['peca_inventada' => 10],
                'desconto' => ['peca_inventada' => 100],
            ])
            ->assertRedirect();

        $this->assertSame($antes, EndurancePieceSpec::count());
        $this->assertFalse(EndurancePieceSpec::where('peca_key', 'peca_inventada')->exists());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function as_duas_rotas_exigem_admin_autenticado(): void
    {
        $this->get('/admin/endurance')->assertRedirect('/admin/login');
        $this->post('/admin/endurance', [])->assertRedirect('/admin/login');
    }
}
