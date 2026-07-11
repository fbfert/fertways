<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Market\ColocarOrdem;
use App\Domain\Market\ExecutarOrdem;
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
        // D-58: a oferta repousa e o comprador a executa; não há mais casamento automático.
        $venda = app(ColocarOrdem::class)->handle($a, 'sell', 'metal_bruto', 100, 50_000);
        app(ExecutarOrdem::class)->handle($b, $venda->id, 100);

        $this->assertSame(150_000, $this->treasury()['fert_micro']);
    }

    #[Test]
    public function o_tributo_de_recurso_credita_o_caixa_do_tesouro(): void
    {
        // É o que `ConcluirTrechos` faz na entrega: o recurso retido entra no Tesouro (D-57).
        app(\App\Domain\Treasury\Tesouro::class)->creditarRecurso('agua', 30);
        app(\App\Domain\Treasury\Tesouro::class)->creditarRecurso('agua', 15);

        $agua = collect($this->treasury()['recursos'])->firstWhere('code', 'agua');
        $this->assertSame(45, $agua['total'], '30 + 15 unidades de Água no Tesouro');
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
