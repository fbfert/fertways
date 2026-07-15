<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Market\ExecutarOrdem;
use App\Domain\Market\OfertarComoGoverno;
use App\Domain\Treasury\Tesouro;
use App\Exceptions\DomainRuleException;
use App\Models\Admin;
use App\Models\Colony;
use App\Models\MarketOrder;
use App\Models\TreasuryHolding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * O Governo vende no Mercado Central (docs/decisoes.md D-87).
 */
class MercadoDoGovernoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\ComponentRecipeSeeder::class);
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
        TreasuryHolding::create(['resource_type' => 'metal_bruto', 'amount' => 1000]);
        TreasuryHolding::create(['resource_type' => Tesouro::FERT, 'amount' => 0]);
    }

    private function operador(): Admin
    {
        return Admin::create([
            'name' => 'Op', 'email' => 'op@fertways.test',
            'password' => Hash::make('segredo-forte-1234'), 'role' => Admin::OPERADOR,
        ]);
    }

    private function colonoAbastecido(): Colony
    {
        $user = User::factory()->create();
        $colony = app(CreateColony::class)->handle($user, 'Base', 20, 20);
        $colony->update(['fert_micro' => 1000 * 1_000_000]);

        return $colony->fresh();
    }

    public function test_definir_cria_a_oferta_e_reserva_do_tesouro(): void
    {
        $ordem = app(OfertarComoGoverno::class)->definir('metal_bruto', 100, 5_000);

        $this->assertSame('sell', $ordem->side);
        $this->assertNull($ordem->colony_id);
        $this->assertSame(100, $ordem->qty);
        $this->assertSame(100, $ordem->escrow_resource_qty);
        $this->assertSame(900, (int) TreasuryHolding::whereKey('metal_bruto')->value('amount'));
    }

    public function test_subir_a_quantidade_reserva_a_diferenca(): void
    {
        app(OfertarComoGoverno::class)->definir('metal_bruto', 100, 5_000);
        $ordem = app(OfertarComoGoverno::class)->definir('metal_bruto', 150, 5_000);

        $this->assertSame(150, $ordem->qty);
        $this->assertSame(850, (int) TreasuryHolding::whereKey('metal_bruto')->value('amount'));
    }

    public function test_descer_a_quantidade_devolve_a_diferenca(): void
    {
        app(OfertarComoGoverno::class)->definir('metal_bruto', 100, 5_000);
        $ordem = app(OfertarComoGoverno::class)->definir('metal_bruto', 40, 5_000);

        $this->assertSame(40, $ordem->qty);
        $this->assertSame(960, (int) TreasuryHolding::whereKey('metal_bruto')->value('amount'));
    }

    public function test_zerar_cancela_a_oferta_e_devolve_tudo(): void
    {
        $criada = app(OfertarComoGoverno::class)->definir('metal_bruto', 100, 5_000);
        $resultado = app(OfertarComoGoverno::class)->definir('metal_bruto', 0, 5_000);

        $this->assertNull($resultado);
        $this->assertSame('cancelada', $criada->fresh()->status);
        $this->assertSame(1000, (int) TreasuryHolding::whereKey('metal_bruto')->value('amount'));
    }

    public function test_nao_anuncia_mais_do_que_o_tesouro_tem(): void
    {
        $this->expectException(DomainRuleException::class);
        $this->expectExceptionMessage('só 1000');
        app(OfertarComoGoverno::class)->definir('metal_bruto', 5000, 5_000);
    }

    public function test_um_colono_compra_da_oferta_do_governo(): void
    {
        app(OfertarComoGoverno::class)->definir('metal_bruto', 100, 5_000);
        $ordem = MarketOrder::where('colony_id', null)->where('resource_type', 'metal_bruto')->first();

        $comprador = $this->colonoAbastecido();
        $antesFert = (int) $comprador->fert_micro;

        app(ExecutarOrdem::class)->handle($comprador, $ordem->id, 5);

        // O comprador pagou 5 × 5.000 = 25.000 micro-Fert$ (0,025 F$... na verdade a unidade de
        // price_micro já é micro-Fert$ por unidade: 5 × 5_000 = 25_000).
        $this->assertSame($antesFert - 25_000, (int) $comprador->fresh()->fert_micro);

        // A oferta caiu para 95 sozinha — a mesma execução parcial de qualquer venda.
        $this->assertSame(95, $ordem->fresh()->qty);
        $this->assertSame('parcial', $ordem->fresh()->status);

        // O recurso entrou no depósito do comprador no Mercado.
        $this->assertSame(5, (int) \Illuminate\Support\Facades\DB::table('market_accounts')
            ->where('colony_id', $comprador->id)->where('resource_type', 'metal_bruto')->value('amount'));

        // O Tesouro recebeu o valor cheio (líquido + taxa, sem colônia vendedora para separar).
        $this->assertSame(25_000, app(Tesouro::class)->saldoFertMicro());
    }

    public function test_endpoint_admin_salva_a_lista(): void
    {
        $admin = $this->operador();

        $this->actingAs($admin, 'admin')
            ->post('/admin/mercado/governo', [
                'qtd' => ['metal_bruto' => 200],
                'preco' => ['metal_bruto' => '0.005'],
            ])
            ->assertRedirect();

        $ordem = MarketOrder::whereNull('colony_id')->where('resource_type', 'metal_bruto')->first();
        $this->assertNotNull($ordem);
        $this->assertSame(200, $ordem->qty);
        $this->assertSame(5_000, $ordem->price_micro);
    }

    public function test_vitrine_mostra_governo_como_dono(): void
    {
        app(OfertarComoGoverno::class)->definir('metal_bruto', 100, 5_000);
        $comprador = $this->colonoAbastecido();

        $r = $this->actingAs($comprador->user)->getJson('/market/orders')->assertOk();
        $oferta = collect($r->json('ofertas'))->firstWhere('resource_type', 'metal_bruto');

        $this->assertSame('Governo', $oferta['colonia']);
        $this->assertTrue($oferta['e_governo']);
        $this->assertFalse($oferta['minha']);
    }
}
