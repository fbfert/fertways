<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Endurance\ComprarItem;
use App\Domain\Endurance\EfeitosDaEndurance;
use App\Domain\Marco\Curva;
use App\Models\Admin;
use App\Models\Colony;
use App\Models\EnduranceItem;
use App\Models\User;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O CRUD da Loja de Peças da Endurance no painel (D-135) — catálogo dinâmico, uma aba por seção,
 * criar/editar/apagar item com efeitos empilháveis. Substitui `EnduranceAdminTest` do D-133 (o POST
 * salvava 32 linhas fixas de uma vez; agora é um CRUD de verdade).
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
        return Admin::firstOrCreate(
            ['email' => 'op@fertways.test'],
            ['name' => 'Op', 'password' => Hash::make('segredo-forte-1234'), 'role' => Admin::OPERADOR],
        );
    }

    private function colonia(string $nick, int $marco = 1): Colony
    {
        $user = User::factory()->create(['email' => "{$nick}@t.test", 'nickname' => $nick]);
        $colony = app(CreateColony::class)->handle($user, "Colônia {$nick}", 10, 10);
        $colony->forceFill(['xp' => Curva::xpDoMarco($marco)])->save();

        return $colony->fresh();
    }

    private function dadosDoItem(array $sobrepor = []): array
    {
        return array_merge([
            'secao' => 'anel_habitacional',
            'item_key' => 'reator_experimental',
            'tipo' => EnduranceItem::COMUM,
            'nome' => 'Reator Experimental',
            'quantidade_total' => 10,
            'preco' => '35.5',
            'marco' => '',
            'descricao' => 'Um item de teste.',
            'efeitos' => "producao_bonus:mina_local:2000\nvelocidade_veiculo:todos:1000",
        ], $sobrepor);
    }

    #[Test]
    public function a_tela_lista_as_abas_das_8_secoes(): void
    {
        $this->actingAs($this->operador(), 'admin')
            ->get('/admin/endurance')
            ->assertOk()
            ->assertSee('Anel Habitacional')
            ->assertSee('Silo de Suprimentos');
    }

    #[Test]
    public function o_admin_cria_um_item_com_efeitos_empilhados(): void
    {
        $this->actingAs($this->operador(), 'admin')
            ->post('/admin/endurance', $this->dadosDoItem())
            ->assertRedirect();

        $item = EnduranceItem::where('item_key', 'reator_experimental')->first();
        $this->assertNotNull($item);
        $this->assertSame('anel_habitacional', $item->secao);
        $this->assertSame(35_500_000, $item->preco_micro);
        $this->assertNull($item->marco_minimo);
        $this->assertSame(2, $item->efeitos()->count());

        $producao = $item->efeitos()->where('tipo_efeito', EfeitosDaEndurance::PRODUCAO_BONUS)->first();
        $this->assertSame('mina_local', $producao->alvo);
        $this->assertSame(2000, $producao->valor_bps);
    }

    #[Test]
    public function tipo_unico_forca_a_quantidade_total_em_1(): void
    {
        $this->actingAs($this->operador(), 'admin')
            ->post('/admin/endurance', $this->dadosDoItem(['tipo' => EnduranceItem::UNICO, 'quantidade_total' => 50]))
            ->assertRedirect();

        $item = EnduranceItem::where('item_key', 'reator_experimental')->first();
        $this->assertSame(1, $item->quantidade_total);
    }

    #[Test]
    public function item_key_duplicada_e_recusada(): void
    {
        $this->actingAs($this->operador(), 'admin')->post('/admin/endurance', $this->dadosDoItem());

        $this->actingAs($this->operador(), 'admin')
            ->post('/admin/endurance', $this->dadosDoItem(['nome' => 'Outro nome']))
            ->assertSessionHasErrors('item_key');
    }

    #[Test]
    public function efeito_com_tipo_desconhecido_e_recusado(): void
    {
        $this->actingAs($this->operador(), 'admin')
            ->post('/admin/endurance', $this->dadosDoItem(['efeitos' => 'efeito_inventado:1000']))
            ->assertSessionHas('erro');

        $this->assertFalse(EnduranceItem::where('item_key', 'reator_experimental')->exists());
    }

    #[Test]
    public function efeito_que_exige_alvo_sem_alvo_e_recusado(): void
    {
        $this->actingAs($this->operador(), 'admin')
            ->post('/admin/endurance', $this->dadosDoItem(['efeitos' => 'producao_bonus:2000']))
            ->assertSessionHas('erro');

        $this->assertFalse(EnduranceItem::where('item_key', 'reator_experimental')->exists());
    }

    #[Test]
    public function o_admin_edita_um_item_e_substitui_os_efeitos(): void
    {
        $this->actingAs($this->operador(), 'admin')->post('/admin/endurance', $this->dadosDoItem());
        $item = EnduranceItem::where('item_key', 'reator_experimental')->first();

        $this->actingAs($this->operador(), 'admin')
            ->post("/admin/endurance/{$item->id}/editar", $this->dadosDoItem([
                'nome' => 'Reator Experimental Mk2',
                'preco' => '50',
                'efeitos' => 'drone_raio:5000',
            ]))
            ->assertRedirect();

        $item = $item->fresh();
        $this->assertSame('Reator Experimental Mk2', $item->nome);
        $this->assertSame(50_000_000, $item->preco_micro);
        $this->assertSame(1, $item->efeitos()->count());
        $this->assertSame(EfeitosDaEndurance::DRONE_RAIO, $item->efeitos()->first()->tipo_efeito);
    }

    #[Test]
    public function nao_se_baixa_a_quantidade_total_abaixo_do_ja_vendido(): void
    {
        $this->actingAs($this->operador(), 'admin')->post('/admin/endurance', $this->dadosDoItem(['quantidade_total' => 3]));
        $item = EnduranceItem::where('item_key', 'reator_experimental')->first();

        $a = $this->colonia('a', 1);
        \Illuminate\Support\Facades\DB::table('colonies')->where('id', $a->id)->increment('fert_micro', 1000 * 1_000_000);
        app(ComprarItem::class)->handle($a, 'reator_experimental');
        app(ComprarItem::class)->handle($a->fresh(), 'reator_experimental');

        $this->actingAs($this->operador(), 'admin')
            ->post("/admin/endurance/{$item->id}/editar", $this->dadosDoItem(['quantidade_total' => 1]))
            ->assertSessionHas('erro');

        $this->assertSame(3, $item->fresh()->quantidade_total);
    }

    #[Test]
    public function o_admin_apaga_um_item_sem_posse(): void
    {
        $this->actingAs($this->operador(), 'admin')->post('/admin/endurance', $this->dadosDoItem());
        $item = EnduranceItem::where('item_key', 'reator_experimental')->first();

        $this->actingAs($this->operador(), 'admin')
            ->post("/admin/endurance/{$item->id}/apagar")
            ->assertRedirect();

        $this->assertFalse(EnduranceItem::where('item_key', 'reator_experimental')->exists());
    }

    #[Test]
    public function apagar_e_bloqueado_se_alguma_colonia_ja_possui(): void
    {
        $this->actingAs($this->operador(), 'admin')->post('/admin/endurance', $this->dadosDoItem());
        $item = EnduranceItem::where('item_key', 'reator_experimental')->first();

        $a = $this->colonia('a', 1);
        \Illuminate\Support\Facades\DB::table('colonies')->where('id', $a->id)->increment('fert_micro', 1000 * 1_000_000);
        app(ComprarItem::class)->handle($a, 'reator_experimental');

        $this->actingAs($this->operador(), 'admin')
            ->post("/admin/endurance/{$item->id}/apagar")
            ->assertSessionHas('erro');

        $this->assertTrue(EnduranceItem::where('item_key', 'reator_experimental')->exists());
    }

    #[Test]
    public function as_rotas_exigem_admin_autenticado(): void
    {
        $this->get('/admin/endurance')->assertRedirect('/admin/login');
        $this->post('/admin/endurance', [])->assertRedirect('/admin/login');
    }
}
