<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Market\ColocarOrdem;
use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Api\CapitalController;
use App\Models\Colony;
use App\Models\MarketAccount;
use App\Models\News;
use App\Models\PriceIntervention;
use App\Models\User;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A Capital — Tesouro (slot 2), Finanças (slot 4) e Notícias (slot 3). Ver docs/decisoes.md.
 *
 * O Tesouro acumula e exibe o tributo (§8.3), não gasta (D-50): o saldo é a soma de `tax_events`.
 * A intervenção de preço da Secretaria (§06) prende o Mercado à faixa enquanto vigente (D-35).
 */
class CapitalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ResourceTypeSeeder::class);
        $this->seed(ComponentRecipeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
    }

    private function colonia(string $nick, int $x, int $y): Colony
    {
        $user = User::factory()->create(['email' => "{$nick}@t.test", 'nickname' => $nick]);

        return app(CreateColony::class)->handle($user, "Colônia {$nick}", $x, $y)->fresh();
    }

    private function treasury(): array
    {
        return (new CapitalController())->treasury()->getData(true);
    }

    // ── Tesouro ──────────────────────────────────────────────────────────────

    #[Test]
    public function o_tesouro_soma_o_tributo_de_venda_em_fert(): void
    {
        $a = $this->colonia('vendedor', 10, 10);
        $b = $this->colonia('comprador', 20, 20);
        MarketAccount::create(['colony_id' => $a->id, 'resource_type' => 'metal_bruto', 'amount' => 1_000]);

        // Venda de 100 a 50.000 micro = 5 Fert$. Taxa de 3% (primário) = 150.000 micro.
        app(ColocarOrdem::class)->handle($a, 'sell', 'metal_bruto', 100, 50_000);
        app(ColocarOrdem::class)->handle($b, 'buy', 'metal_bruto', 100, 60_000);

        $this->assertSame(150_000, $this->treasury()['fert_micro']);
    }

    #[Test]
    public function o_saldo_do_tesouro_e_a_soma_dos_tax_events(): void
    {
        // Insere tributos de transporte (unidades) direto: é o que `ConcluirTrechos` grava na entrega.
        DB::table('tax_events')->insert([
            ['economic_event_key' => 'e1', 'kind' => 'transporte_entrega', 'colony_id' => $this->colonia('c1', 10, 10)->id,
                'resource_type' => 'agua', 'base_amount' => 1_000, 'tax_bps' => 300, 'tax_amount' => 30, 'created_at' => now()],
            ['economic_event_key' => 'e2', 'kind' => 'transporte_entrega', 'colony_id' => $this->colonia('c2', 20, 20)->id,
                'resource_type' => 'agua', 'base_amount' => 500, 'tax_bps' => 300, 'tax_amount' => 15, 'created_at' => now()],
        ]);

        $recursos = collect($this->treasury()['recursos']);
        $agua = $recursos->firstWhere('code', 'agua');

        $this->assertSame(45, $agua['total'], '30 + 15 unidades de Água no Tesouro');
        $this->assertSame(0, $this->treasury()['fert_micro'], 'nenhuma venda de mercado ainda');
    }

    // ── Finanças: intervenção de preço (§06) ─────────────────────────────────

    #[Test]
    public function a_intervencao_recusa_ordem_acima_do_teto(): void
    {
        $a = $this->colonia('vendedor', 10, 10);
        MarketAccount::create(['colony_id' => $a->id, 'resource_type' => 'metal_bruto', 'amount' => 1_000]);
        PriceIntervention::create([
            'resource_type' => 'metal_bruto', 'floor_micro' => 20_000, 'ceil_micro' => 50_000,
            'reason' => 'teste', 'starts_at' => now()->subHour(), 'expires_at' => now()->addDay(),
        ]);

        $this->expectException(DomainRuleException::class);
        $this->expectExceptionMessage('teto');
        app(ColocarOrdem::class)->handle($a, 'sell', 'metal_bruto', 10, 60_000);
    }

    #[Test]
    public function a_intervencao_recusa_ordem_abaixo_do_piso(): void
    {
        $a = $this->colonia('vendedor', 10, 10);
        MarketAccount::create(['colony_id' => $a->id, 'resource_type' => 'metal_bruto', 'amount' => 1_000]);
        PriceIntervention::create([
            'resource_type' => 'metal_bruto', 'floor_micro' => 20_000, 'ceil_micro' => 50_000,
            'reason' => 'teste', 'starts_at' => now()->subHour(), 'expires_at' => now()->addDay(),
        ]);

        $this->expectException(DomainRuleException::class);
        $this->expectExceptionMessage('piso');
        app(ColocarOrdem::class)->handle($a, 'sell', 'metal_bruto', 10, 10_000);
    }

    #[Test]
    public function dentro_da_faixa_a_ordem_passa(): void
    {
        $a = $this->colonia('vendedor', 10, 10);
        MarketAccount::create(['colony_id' => $a->id, 'resource_type' => 'metal_bruto', 'amount' => 1_000]);
        PriceIntervention::create([
            'resource_type' => 'metal_bruto', 'floor_micro' => 20_000, 'ceil_micro' => 50_000,
            'reason' => 'teste', 'starts_at' => now()->subHour(), 'expires_at' => now()->addDay(),
        ]);

        $ordem = app(ColocarOrdem::class)->handle($a, 'sell', 'metal_bruto', 10, 50_000);
        $this->assertSame('aberta', $ordem->status);
    }

    #[Test]
    public function sem_intervencao_o_mercado_e_livre(): void
    {
        $a = $this->colonia('vendedor', 10, 10);
        MarketAccount::create(['colony_id' => $a->id, 'resource_type' => 'metal_bruto', 'amount' => 1_000]);

        $ordem = app(ColocarOrdem::class)->handle($a, 'sell', 'metal_bruto', 10, 999_000);
        $this->assertSame('aberta', $ordem->status, 'preço alto passa sem intervenção');
    }

    #[Test]
    public function intervencao_expirada_nao_prende_o_preco(): void
    {
        $a = $this->colonia('vendedor', 10, 10);
        MarketAccount::create(['colony_id' => $a->id, 'resource_type' => 'metal_bruto', 'amount' => 1_000]);
        PriceIntervention::create([
            'resource_type' => 'metal_bruto', 'floor_micro' => 20_000, 'ceil_micro' => 50_000,
            'reason' => 'velha', 'starts_at' => now()->subDays(2), 'expires_at' => now()->subDay(),
        ]);

        $ordem = app(ColocarOrdem::class)->handle($a, 'sell', 'metal_bruto', 10, 60_000);
        $this->assertSame('aberta', $ordem->status, 'faixa expirada não vale');
    }

    #[Test]
    public function o_painel_de_financas_traz_precos_e_indicadores(): void
    {
        $this->colonia('c', 10, 10);

        $dados = (new CapitalController())->finance()->getData(true);

        $this->assertNotEmpty($dados['precos']);
        $this->assertSame(1, $dados['indicadores']['colonias']);
        $this->assertGreaterThan(0, $dados['indicadores']['fert_em_circulacao_micro']);
    }

    // ── Notícias (slot 3) ────────────────────────────────────────────────────

    #[Test]
    public function o_mural_lista_comunicados_e_o_gagarin_esta_inativo(): void
    {
        News::create(['title' => 'Abertura', 'body' => 'Bem-vindos.', 'kind' => 'comunicado', 'author' => 'Equipe', 'published_at' => now()]);

        $dados = (new CapitalController())->news()->getData(true);

        $this->assertSame('Abertura', $dados['noticias'][0]['title']);
        $this->assertFalse($dados['gagarin']['ativo']);
        $this->assertSame(50, $dados['gagarin']['limiar_jogadores']);
    }

    // ── Comandos do operador ─────────────────────────────────────────────────

    #[Test]
    public function o_comando_noticia_publica_no_mural(): void
    {
        $this->artisan('fertways:noticia', ['--publicar' => true, '--titulo' => 'Aviso', '--corpo' => 'Texto do aviso.'])
            ->assertExitCode(0);

        $this->assertDatabaseHas('news', ['title' => 'Aviso']);
    }

    #[Test]
    public function o_comando_intervencao_declara_e_revoga(): void
    {
        $this->artisan('fertways:intervencao', ['recurso' => 'metal_bruto', '--teto' => '0.05', '--motivo' => 'pico', '--dias' => '3'])
            ->assertExitCode(0);

        $vigente = PriceIntervention::vigenteDe('metal_bruto');
        $this->assertNotNull($vigente);
        $this->assertSame(50_000, $vigente->ceil_micro, '0,05 Fert$ = 50.000 micro');

        $this->artisan('fertways:intervencao', ['recurso' => 'metal_bruto', '--revogar' => true])->assertExitCode(0);
        $this->assertNull(PriceIntervention::vigenteDe('metal_bruto'));
    }
}
