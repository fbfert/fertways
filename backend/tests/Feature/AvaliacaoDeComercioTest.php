<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Trade\AvaliacaoSpecs;
use App\Domain\Trade\AvaliarComercio;
use App\Domain\Trade\VinculoDeContas;
use App\Exceptions\DomainRuleException;
use App\Models\Admin;
use App\Models\AuditEntry;
use App\Models\AvaliacaoDeComercio;
use App\Models\Colony;
use App\Models\TradeAgreement;
use App\Models\User;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A avaliação de 0 a 5 estrelas do comércio entre colonos (GDD §8.4, §26.3, §26.4; D-287).
 */
class AvaliacaoDeComercioTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
        $this->seed(ComponentRecipeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
    }

    private function colonia(): Colony
    {
        $this->n++;

        return app(CreateColony::class)->handle(
            User::factory()->create(['confianca_comercial' => 500]), "C{$this->n}", 10 + $this->n * 7, 20,
        )->fresh();
    }

    private function acordo(Colony $a, Colony $b, string $status = 'executado', int $valorFert = 10): TradeAgreement
    {
        return TradeAgreement::create([
            'colony_a_id' => $a->id, 'colony_b_id' => $b->id, 'proposer_colony_id' => $a->id,
            'terms_json' => [(string) $a->id => ['agua' => 10], (string) $b->id => ['metal_bruto' => 10]],
            'status' => $status, 'deadline_at' => now()->addDay(), 'accepted_at' => now()->subHour(),
            'delivered_json' => [], 'value_micro' => $valorFert * Colony::MICRO_POR_FERT,
            'reputation_applied' => $status !== 'aceito', 'executed_at' => $status === 'executado' ? now() : null,
        ]);
    }

    private function avaliar(Colony $autor, TradeAgreement $acordo, int $estrelas, ?string $comentario = null): AvaliacaoDeComercio
    {
        return app(AvaliarComercio::class)->handle($autor->user, $acordo, $estrelas, $comentario);
    }

    private function codigo(callable $fn): string
    {
        try {
            $fn();
        } catch (DomainRuleException $e) {
            return $e->codigo;
        }

        $this->fail('Deveria ter recusado.');
    }

    public function test_avaliar_move_a_confianca_do_avaliado_pela_tabela(): void
    {
        [$a, $b] = [$this->colonia(), $this->colonia()];
        $acordo = $this->acordo($a, $b);

        $this->avaliar($a, $acordo, 5, 'Entregou rápido.');
        $this->avaliar($b, $acordo, 1);

        $this->assertSame(500 + AvaliacaoSpecs::DELTA[5], $b->user->fresh()->confianca_comercial);
        $this->assertSame(500 + AvaliacaoSpecs::DELTA[1], $a->user->fresh()->confianca_comercial);
    }

    public function test_so_avalia_acordo_fechado_entre_as_partes_e_acima_do_piso(): void
    {
        [$a, $b, $c] = [$this->colonia(), $this->colonia(), $this->colonia()];

        $this->assertSame('acordo_em_aberto', $this->codigo(fn () => $this->avaliar($a, $this->acordo($a, $b, 'aceito'), 5)));
        $this->assertSame('abaixo_do_piso', $this->codigo(fn () => $this->avaliar($a, $this->acordo($a, $b, 'executado', 1), 5)));
        $this->assertSame('nao_e_parte', $this->codigo(fn () => $this->avaliar($c, $this->acordo($a, $b), 5)));
        $this->assertSame('estrelas_invalidas', $this->codigo(fn () => $this->avaliar($a, $this->acordo($a, $b), 6)));
    }

    public function test_o_acordo_quebrado_tambem_se_avalia_e_so_uma_vez(): void
    {
        [$a, $b] = [$this->colonia(), $this->colonia()];
        $acordo = $this->acordo($a, $b, 'quebrado');

        $this->avaliar($a, $acordo, 0, 'Calote.');

        $this->assertSame('ja_avaliou', $this->codigo(fn () => $this->avaliar($a, $acordo, 5)));
    }

    public function test_a_janela_para_avaliar_fecha(): void
    {
        [$a, $b] = [$this->colonia(), $this->colonia()];
        $acordo = $this->acordo($a, $b);

        Carbon::setTestNow(now()->addDays(AvaliacaoSpecs::JANELA_DIAS)->addMinute());

        $this->assertSame('janela_fechada', $this->codigo(fn () => $this->avaliar($a, $acordo, 5)));
    }

    public function test_uma_avaliacao_com_peso_por_par_a_cada_24_horas(): void
    {
        [$a, $b] = [$this->colonia(), $this->colonia()];

        $primeira = $this->avaliar($a, $this->acordo($a, $b), 5);
        $segunda = $this->avaliar($a, $this->acordo($a, $b), 5);

        $this->assertTrue($primeira->conta);
        $this->assertFalse($segunda->conta, 'múltiplos lotes do mesmo par não multiplicam reputação');
        $this->assertSame(500 + AvaliacaoSpecs::DELTA[5], $b->user->fresh()->confianca_comercial);

        Carbon::setTestNow(now()->addHours(25));
        $this->assertTrue($this->avaliar($a, $this->acordo($a, $b), 5)->conta);
    }

    public function test_contas_que_dividem_ip_ficam_suspeitas_ate_revisao(): void
    {
        [$a, $b] = [$this->colonia(), $this->colonia()];
        app(VinculoDeContas::class)->registrar($a->user, '10.0.0.7');
        app(VinculoDeContas::class)->registrar($b->user, '10.0.0.7');

        $av = $this->avaliar($a, $this->acordo($a, $b), 5);

        $this->assertTrue($av->suspeita);
        $this->assertSame(500, $b->user->fresh()->confianca_comercial, 'suspeita não move o índice');

        app(AvaliarComercio::class)->liberar($av);
        $this->assertSame(500 + AvaliacaoSpecs::DELTA[5], $b->user->fresh()->confianca_comercial);
    }

    public function test_confirmar_o_vinculo_zera_o_historico_mutuo_retroativamente(): void
    {
        [$a, $b, $c] = [$this->colonia(), $this->colonia(), $this->colonia()];
        $this->avaliar($a, $this->acordo($a, $b), 5);
        $this->avaliar($b, $this->acordo($a, $b), 5);
        $this->avaliar($c, $this->acordo($c, $b), 4);

        app(AvaliarComercio::class)->confirmarVinculo($a->user, $b->user);

        $this->assertSame(500, $a->user->fresh()->confianca_comercial);
        $this->assertSame(500 + AvaliacaoSpecs::DELTA[4], $b->user->fresh()->confianca_comercial, 'a de terceiro fica');
        $this->assertSame(2, AvaliacaoDeComercio::whereNotNull('anulada_em')->count());
    }

    public function test_o_ip_nao_e_guardado_em_claro(): void
    {
        $u = User::factory()->create();
        app(VinculoDeContas::class)->registrar($u, '203.0.113.9');

        $this->assertFalse(DB::table('login_ips')->where('ip_hmac', '203.0.113.9')->exists());
        $this->assertSame(64, strlen((string) DB::table('login_ips')->value('ip_hmac')));
    }

    public function test_a_api_avalia_e_o_perfil_publico_mostra_a_media(): void
    {
        [$a, $b] = [$this->colonia(), $this->colonia()];
        $acordo = $this->acordo($a, $b);

        Sanctum::actingAs($a->user);
        $this->getJson('/trade/agreements')->assertOk()->assertJsonPath('agreements.0.pode_avaliar', true);
        $this->postJson("/trade/agreements/{$acordo->id}/avaliacao", ['estrelas' => 4, 'comentario' => 'Bom.'])->assertOk();
        $this->getJson('/trade/agreements')->assertJsonPath('agreements.0.pode_avaliar', false)
            ->assertJsonPath('agreements.0.minha_avaliacao', 4);

        $r = $this->getJson("/players/{$b->user->id}/info")->assertOk();
        $this->assertEquals(4.0, $r->json('avaliacoes.media'));
        $this->assertSame(1, $r->json('avaliacoes.total'));
        $this->assertSame('Bom.', $r->json('avaliacoes.ultimas.0.comentario'));
    }

    public function test_o_painel_mostra_a_suspeita_e_a_revisao_confirma_o_vinculo(): void
    {
        [$a, $b] = [$this->colonia(), $this->colonia()];
        app(VinculoDeContas::class)->registrar($a->user, '10.0.0.8');
        app(VinculoDeContas::class)->registrar($b->user, '10.0.0.8');
        $av = $this->avaliar($a, $this->acordo($a, $b), 5);
        $admin = Admin::create([
            'name' => 'Op', 'email' => 'op@fertways.test',
            'password' => bcrypt('segredo-forte-1234'), 'role' => Admin::OPERADOR,
        ]);

        $this->actingAs($admin, 'admin')->get('/admin/ministerio')->assertOk()->assertSee('Avaliações de comércio suspeitas');
        $this->actingAs($admin, 'admin')->post("/admin/ministerio/avaliacoes/{$av->id}/revisar", ['veredito' => 'vinculadas'])->assertRedirect();

        $this->assertNotNull($av->fresh()->anulada_em);
        $this->assertTrue(AuditEntry::where('acao', 'avaliacao.vinculadas')->exists());
    }

    public function test_o_login_registra_o_vinculo(): void
    {
        $u = User::factory()->create(['email' => 'v@x.test', 'password' => bcrypt('segredo-123')]);

        $this->postJson('/login', ['email' => 'v@x.test', 'password' => 'segredo-123'])->assertOk();

        $this->assertSame(1, DB::table('login_ips')->where('user_id', $u->id)->count());
    }
}
