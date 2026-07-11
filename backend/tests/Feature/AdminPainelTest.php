<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Ministry\AbrirDenuncia;
use App\Domain\Trade\ProporAcordo;
use App\Models\Admin;
use App\Models\Colony;
use App\Models\News;
use App\Models\PriceIntervention;
use App\Models\Report;
use App\Models\TradeAgreement;
use App\Models\User;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Painel de administração da equipe (D-56). Guard `admin` isolado, ações que reusam o domínio.
 */
class AdminPainelTest extends TestCase
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

    private int $slot = 0;

    private function colonia(string $nick): Colony
    {
        $user = User::factory()->create(['email' => "{$nick}@t.test", 'nickname' => $nick]);

        return app(CreateColony::class)->handle($user, "Colônia {$nick}", 10 + 2 * $this->slot++, 10)->fresh();
    }

    private function casoNaEquipe(): Report
    {
        $a = $this->colonia('autora');
        $b = $this->colonia('re');
        $acordo = app(ProporAcordo::class)->handle($a, $b, ['metal_bruto' => 10], ['agua' => 10], now()->addDays(2));
        $acordo->forceFill(['status' => 'quebrado'])->save();

        // 'calote_reincidente' é grave → vai direto à equipe (§9.2).
        return app(AbrirDenuncia::class)->handle($a, $b, 'calote_reincidente', 'Prometeu e não entregou.', 'acordo_expirado', $acordo->id);
    }

    // ── Fronteira de autenticação ────────────────────────────────────────────

    #[Test]
    public function o_convidado_e_levado_ao_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    #[Test]
    public function a_tela_de_login_abre(): void
    {
        $this->get('/admin/login')->assertOk()->assertSee('Entrar');
    }

    #[Test]
    public function um_colono_nao_e_admin(): void
    {
        // Autenticar como colono (guard padrão) não abre o painel: o guard `admin` é outro.
        $this->actingAs(User::factory()->create())->get('/admin')->assertRedirect('/admin/login');
    }

    #[Test]
    public function login_correto_entra_no_painel(): void
    {
        $admin = $this->admin();

        $this->post('/admin/login', ['email' => 'eq@t.test', 'password' => 'segredo-forte-123'])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin, 'admin');
    }

    #[Test]
    public function login_errado_e_recusado(): void
    {
        $this->admin();

        $this->post('/admin/login', ['email' => 'eq@t.test', 'password' => 'errada'])
            ->assertSessionHasErrors('email');

        $this->assertGuest('admin');
    }

    #[Test]
    public function o_dashboard_carrega_para_o_admin(): void
    {
        $this->actingAs($this->admin(), 'admin')->get('/admin')->assertOk()->assertSee('Panorama');
    }

    #[Test]
    public function sair_encerra_a_sessao(): void
    {
        $this->actingAs($this->admin(), 'admin')->post('/admin/logout')->assertRedirect('/admin/login');
        $this->assertGuest('admin');
    }

    // ── Ações (reusam o domínio) ─────────────────────────────────────────────

    #[Test]
    public function a_equipe_julga_um_caso_pelo_painel(): void
    {
        $caso = $this->casoNaEquipe();
        $this->assertSame('na_equipe', $caso->status);

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.julgar', $caso), ['procedente' => '1'])
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('ok');

        $this->assertSame('decidido', $caso->fresh()->status);
        $this->assertDatabaseHas('punishments', ['report_id' => $caso->id]);
    }

    #[Test]
    public function o_painel_nomeia_conciliador(): void
    {
        $this->colonia('fulano');

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.conciliador.nomear'), ['nickname' => 'fulano'])
            ->assertSessionHas('ok');

        $this->assertNotNull(User::where('nickname', 'fulano')->first()->conciliador_desde);
    }

    #[Test]
    public function o_painel_declara_intervencao_de_preco(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.intervencao'), [
                'resource_type' => 'metal_bruto', 'teto' => '0.05', 'piso' => '0.02',
                'motivo' => 'pico', 'dias' => '3',
            ])->assertSessionHas('ok');

        $vigente = PriceIntervention::vigenteDe('metal_bruto');
        $this->assertNotNull($vigente);
        $this->assertSame(50_000, $vigente->ceil_micro);
    }

    #[Test]
    public function o_painel_publica_um_comunicado(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.noticia'), ['titulo' => 'Aviso', 'corpo' => 'Texto do aviso.'])
            ->assertSessionHas('ok');

        $this->assertDatabaseHas('news', ['title' => 'Aviso']);
    }

    #[Test]
    public function o_painel_dispara_o_tick(): void
    {
        $this->actingAs($this->admin(), 'admin')->post(route('admin.tick'))
            ->assertRedirect(route('admin.dashboard'))->assertSessionHas('ok');
    }

    // ── Comando de bootstrap ─────────────────────────────────────────────────

    #[Test]
    public function o_comando_cria_admin(): void
    {
        $this->artisan('fertways:admin', ['--criar' => true, '--email' => 'novo@t.test', '--nome' => 'Novo', '--senha' => 'segredo-forte-123'])
            ->assertExitCode(0);

        $this->assertDatabaseHas('admins', ['email' => 'novo@t.test']);
    }
}
