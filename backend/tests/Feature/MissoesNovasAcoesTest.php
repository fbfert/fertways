<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Logistics\ConcluirTrechos;
use App\Domain\Market\ExecutarOrdem;
use App\Domain\Market\OfertarComoGoverno;
use App\Domain\Missoes\Acoes;
use App\Domain\Transport\FabricarCaminhoes;
use App\Domain\Transport\MercadoDeUsados;
use App\Domain\Transport\Ministerio;
use App\Models\Colony;
use App\Models\MissionAssignment;
use App\Models\MissionTemplate;
use App\Models\TreasuryHolding;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Quatro ações novas no catálogo de missões (pedido do usuário, 2026-07-16): comprar do Governo no
 * Mercado Central, comprar um veículo novo, comprar um usado, e vender um usado. Cada uma precisa
 * de um gancho de verdade em quem faz o evento acontecer — senão é a mesma "missão impossível" que
 * o D-72 já documentou: um molde com ação que nada dispara, 0/N para sempre, em silêncio.
 */
class MissoesNovasAcoesTest extends TestCase
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

    private function colono(): User
    {
        $user = User::factory()->create(['tutorial_completed_at' => now()]);
        app(CreateColony::class)->handle($user, 'Colônia', 10 + $this->proximoSlot++, 20);

        return $user->fresh();
    }

    private function molde(string $acao): MissionTemplate
    {
        return MissionTemplate::create([
            'chave' => "teste_{$acao}", 'categoria' => 'eventuais', 'titulo' => 'Teste',
            'descricao' => 'x', 'acao' => $acao, 'meta' => 1,
            'recompensa_fert_micro' => 0, 'recompensa_xp' => 0, 'recompensa_recursos' => null,
            'ativa' => true,
        ]);
    }

    /**
     * `expires_at` folgado de propósito: o teste da venda de usado avança o relógio 2 dias para a
     * entrega do veículo terminar, e uma missão de 1 dia já teria expirado (`scopeAtiva`) antes de
     * `Progresso::registrar` sequer olhar para ela — silenciosamente, como o D-72 já ensinou.
     */
    private function atribuir(Colony $colony, MissionTemplate $t): MissionAssignment
    {
        return MissionAssignment::create([
            'colony_id' => $colony->id, 'template_id' => $t->id, 'categoria' => $t->categoria,
            'acao' => $t->acao, 'progresso' => 0, 'meta' => $t->meta, 'status' => 'ativa',
            'expires_at' => now()->addDays(7), 'created_at' => now(),
        ]);
    }

    public function test_as_quatro_acoes_estao_no_catalogo(): void
    {
        $this->assertArrayHasKey('compra_governo_mercado', Acoes::TODAS);
        $this->assertArrayHasKey('compra_veiculo_novo', Acoes::TODAS);
        $this->assertArrayHasKey('compra_veiculo_usado', Acoes::TODAS);
        $this->assertArrayHasKey('venda_veiculo_usado', Acoes::TODAS);
    }

    // ---------------------------------------------------------------- comprar do Governo

    public function test_comprar_do_governo_no_mercado_central_completa_a_missao(): void
    {
        TreasuryHolding::create(['resource_type' => 'metal_bruto', 'amount' => 2000]);
        TreasuryHolding::create(['resource_type' => \App\Domain\Treasury\Tesouro::FERT, 'amount' => 0]);
        app(OfertarComoGoverno::class)->definir('metal_bruto', 1000, 1_000_000);
        $ordem = \App\Models\MarketOrder::where('colony_id', null)->where('resource_type', 'metal_bruto')->first();

        $comprador = $this->colono();
        $comprador->colony->update(['fert_micro' => 1000 * Colony::MICRO_POR_FERT]);

        $missao = $this->atribuir($comprador->colony, $this->molde('compra_governo_mercado'));

        // 600 × 1 Fert$ = 600 Fert$, acima do piso de reputação (500) que libera XP/missão.
        app(ExecutarOrdem::class)->handle($comprador->colony->fresh(), $ordem->id, 600);

        $this->assertSame('concluida', $missao->fresh()->status);
    }

    public function test_comprar_de_outro_colono_nao_completa_a_missao_do_governo(): void
    {
        $vendedor = $this->colono();
        $vendedor->colony->resources()->where('resource_type', 'metal_bruto')->update(['amount' => 2000]);
        \Illuminate\Support\Facades\DB::table('market_accounts')->insert([
            'colony_id' => $vendedor->colony->id, 'resource_type' => 'metal_bruto', 'amount' => 1000,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $ordem = app(\App\Domain\Market\ColocarOrdem::class)->handle(
            $vendedor->colony->fresh(), 'sell', 'metal_bruto', 1000, 1_000_000,
        );

        $comprador = $this->colono();
        $comprador->colony->update(['fert_micro' => 1000 * Colony::MICRO_POR_FERT]);
        $missao = $this->atribuir($comprador->colony, $this->molde('compra_governo_mercado'));

        app(ExecutarOrdem::class)->handle($comprador->colony->fresh(), $ordem->id, 600);

        $this->assertSame('ativa', $missao->fresh()->status, 'comprar de outro colono não é comprar do Governo');
    }

    // ---------------------------------------------------------------- veículo novo

    public function test_comprar_veiculo_novo_completa_a_missao(): void
    {
        foreach (Ministerio::custoFabricacao() as $recurso => $qtd) {
            TreasuryHolding::updateOrCreate(['resource_type' => $recurso], ['amount' => $qtd * 5]);
        }
        app(FabricarCaminhoes::class)->handle();
        $this->travelTo(now()->addMinutes(Ministerio::MINUTOS_FABRICACAO + 1));
        app(FabricarCaminhoes::class)->handle();

        $user = $this->colono();
        $this->erguerPredio($user->colony, 'central_de_transportes', 2);
        $user->colony->update(['fert_micro' => Ministerio::PRECO_MICRO]);

        $missao = $this->atribuir($user->colony, $this->molde('compra_veiculo_novo'));

        $this->actingAs($user)->postJson('/transport/buy')->assertCreated();

        $this->assertSame('concluida', $missao->fresh()->status);
    }

    // ---------------------------------------------------------------- veículo usado

    public function test_comprar_veiculo_usado_completa_a_missao_do_comprador(): void
    {
        $vendedor = $this->colono();
        $this->erguerPredio($vendedor->colony, 'central_de_transportes', 3);
        $comprador = $this->colono();
        $this->erguerPredio($comprador->colony, 'central_de_transportes', 3);
        $comprador->colony->update(['fert_micro' => 200 * Colony::MICRO_POR_FERT]);

        $v = $vendedor->colony->vehicles()->where('type', 'furgao_de_comercio')->first();
        $anuncio = app(MercadoDeUsados::class)->anunciar($vendedor->colony, $v, 50 * Colony::MICRO_POR_FERT);

        $missaoComprador = $this->atribuir($comprador->colony, $this->molde('compra_veiculo_usado'));

        $this->actingAs($comprador)->postJson("/transport/listings/{$anuncio->id}/buy")->assertCreated();

        $this->assertSame('concluida', $missaoComprador->fresh()->status);
    }

    public function test_vender_veiculo_usado_completa_a_missao_do_vendedor_so_na_entrega(): void
    {
        $vendedor = $this->colono();
        $this->erguerPredio($vendedor->colony, 'central_de_transportes', 3);
        $comprador = $this->colono();
        $this->erguerPredio($comprador->colony, 'central_de_transportes', 3);
        $comprador->colony->update(['fert_micro' => 200 * Colony::MICRO_POR_FERT]);

        $v = $vendedor->colony->vehicles()->where('type', 'furgao_de_comercio')->first();
        $anuncio = app(MercadoDeUsados::class)->anunciar($vendedor->colony, $v, 50 * Colony::MICRO_POR_FERT);

        $missaoVendedor = $this->atribuir($vendedor->colony, $this->molde('venda_veiculo_usado'));

        $this->actingAs($comprador)->postJson("/transport/listings/{$anuncio->id}/buy")->assertCreated();

        $this->assertSame('ativa', $missaoVendedor->fresh()->status, 'o veículo ainda está a caminho — só a entrega paga');

        $this->travelTo(now()->addDays(2));
        app(ConcluirTrechos::class)->handle();

        $this->assertSame('concluida', $missaoVendedor->fresh()->status);
    }
}
