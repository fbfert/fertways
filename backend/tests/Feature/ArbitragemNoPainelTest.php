<?php

namespace Tests\Feature;

use App\Domain\Market\OfertarComoGoverno;
use App\Exceptions\DomainRuleException;
use App\Models\Admin;
use App\Models\MarketOrder;
use App\Models\PlanetaNpc;
use App\Models\TreasuryHolding;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A guarda do D-278 vale também no painel (D-279). Um planeta não paga por um recurso mais do que o
 * Governo cobra por ele no Mercado Central. Se pagasse, comprar do Governo e revender ao planeta daria
 * lucro garantido. A partida já limpava os conflitos, mas o painel podia criá-los de novo pelos dois
 * lados: subindo a compra do planeta ou baixando o preço do Governo.
 */
class ArbitragemNoPainelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
        $this->seed(ComponentRecipeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
        DB::table('planeta_npc_precos')->delete();
        TreasuryHolding::create(['resource_type' => 'estanho', 'amount' => 1000]);
    }

    private function dono(): Admin
    {
        return Admin::create([
            'name' => 'Dona', 'email' => 'dona@fertways.test',
            'password' => Hash::make('segredo-forte-1234'), 'role' => Admin::DONO,
        ]);
    }

    private function kalidor(): PlanetaNpc
    {
        return PlanetaNpc::where('chave', 'kalidor')->firstOrFail();
    }

    private function salvarKalidor(string $precos)
    {
        return $this->actingAs($this->dono(), 'admin')
            ->post("/admin/transportes/espacoporto/planetas/{$this->kalidor()->id}", [
                'aberto' => 1, 'distancia_minutos' => 240, 'precos' => $precos,
            ]);
    }

    /** O Governo vende estanho a 0,10 Fert$. */
    private function governoVendeEstanho(): void
    {
        app(OfertarComoGoverno::class)->definir('estanho', 100, 100_000);
    }

    // ── pelo lado do planeta ────────────────────────────────────────────────

    public function test_o_planeta_nao_compra_acima_do_governo(): void
    {
        $this->governoVendeEstanho();
        $this->kalidor()->precos()->create(['resource_type' => 'agua', 'compra_micro' => 50_000]);

        $this->salvarKalidor("agua:0.06:\nestanho:0.2:")
            ->assertSessionHas('erro', fn ($m) => str_contains($m, 'estanho') && str_contains($m, '0,1000'));

        // Nada foi salvo: a tabela antiga continua inteira.
        $this->assertSame(50_000, (int) $this->kalidor()->precos()->where('resource_type', 'agua')->value('compra_micro'));
        $this->assertFalse($this->kalidor()->precos()->where('resource_type', 'estanho')->exists());
        $this->assertFalse(DB::table('audit_log')->where('acao', 'espacoporto.planeta')->exists());
    }

    public function test_o_planeta_pode_pagar_o_mesmo_que_o_governo_cobra(): void
    {
        $this->governoVendeEstanho();

        $this->salvarKalidor('estanho:0.1:')->assertSessionHas('ok');

        $this->assertSame(100_000, (int) $this->kalidor()->precos()->where('resource_type', 'estanho')->value('compra_micro'));
    }

    public function test_a_venda_do_planeta_nao_e_limitada_pelo_governo(): void
    {
        $this->governoVendeEstanho();

        $this->salvarKalidor('estanho::0.05')->assertSessionHas('ok');
    }

    public function test_sem_oferta_do_governo_o_planeta_compra_a_qualquer_preco(): void
    {
        $this->governoVendeEstanho();
        app(OfertarComoGoverno::class)->definir('estanho', 0, 0);

        $this->salvarKalidor('estanho:5:')->assertSessionHas('ok');
    }

    // ── pelo lado do Governo ────────────────────────────────────────────────

    public function test_o_governo_nao_vende_abaixo_do_que_um_planeta_paga(): void
    {
        $this->kalidor()->precos()->create(['resource_type' => 'estanho', 'compra_micro' => 200_000]);

        try {
            app(OfertarComoGoverno::class)->definir('estanho', 100, 100_000);
            $this->fail('O Governo vendeu abaixo do planeta.');
        } catch (DomainRuleException $e) {
            $this->assertSame('arbitragem_com_planeta', $e->codigo);
            $this->assertStringContainsString('Kalidor', $e->getMessage());
        }

        $this->assertFalse(MarketOrder::whereNull('colony_id')->where('resource_type', 'estanho')->exists());
        $this->assertSame(1000, (int) TreasuryHolding::whereKey('estanho')->value('amount'));
    }

    public function test_baixar_uma_oferta_existente_tambem_e_recusado(): void
    {
        $this->governoVendeEstanho();
        $this->kalidor()->precos()->create(['resource_type' => 'estanho', 'compra_micro' => 100_000]);

        $this->expectException(DomainRuleException::class);
        app(OfertarComoGoverno::class)->definir('estanho', 100, 99_999);
    }

    public function test_tirar_a_oferta_do_ar_continua_livre(): void
    {
        $this->governoVendeEstanho();
        $this->kalidor()->precos()->create(['resource_type' => 'estanho', 'compra_micro' => 200_000]);

        $this->assertNull(app(OfertarComoGoverno::class)->definir('estanho', 0, 0));
    }

    public function test_o_formulario_do_mercado_avisa_qual_planeta_impede(): void
    {
        $this->kalidor()->precos()->create(['resource_type' => 'estanho', 'compra_micro' => 200_000]);

        $this->actingAs($this->dono(), 'admin')
            ->post('/admin/mercado/governo', ['qtd' => ['estanho' => 100], 'preco' => ['estanho' => '0.1']])
            ->assertSessionHas('erro', fn ($m) => str_contains($m, 'Kalidor') && str_contains($m, 'estanho'));
    }
}
