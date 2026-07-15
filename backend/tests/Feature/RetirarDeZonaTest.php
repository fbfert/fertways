<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Logistics\ConcluirTrechos;
use App\Domain\Logistics\DespacharVeiculo;
use App\Models\Colony;
use App\Models\NeutralZone;
use App\Models\ResourceType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A retirada do mineral de uma zona neutra (§07, §24.4, §25.2; D-52): o veículo vai vazio, carrega
 * o Depósito da zona e volta; o tributo incide na chegada ao slot, como qualquer entrega.
 */
class RetirarDeZonaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\ComponentRecipeSeeder::class);
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
    }

    private function colonia(): Colony
    {
        $colony = app(CreateColony::class)->handle(User::factory()->create(), 'Base', 20, 20);
        // Estoque limpo (não o kit inicial, D-85): só energia, do jeito que o cenário pede.
        $colony->resources()->update(['amount' => 0]);
        $colony->resources()->where('resource_type', 'energia')->update(['amount' => 100000]);

        return $colony->fresh();
    }

    private function zonaComDeposito(Colony $dona, int $amount): NeutralZone
    {
        return NeutralZone::create([
            'x' => 50, 'y' => 50, 'district' => 'nordeste', 'mineral' => 'metal_bruto',
            'level' => 1, 'status' => 'protegida',
            'owner_colony_id' => $dona->id, 'command_post_level' => 1,
            'productive_at' => now()->subDay(),
            'deposit_level' => 1, 'deposit_amount' => $amount,
        ]);
    }

    public function test_retirada_reserva_o_deposito_e_entrega_em_casa_com_tributo(): void
    {
        $t0 = Carbon::parse('2026-07-10 12:00:00');
        Carbon::setTestNow($t0);

        $colony = $this->colonia();
        $zona = $this->zonaComDeposito($colony, 500);
        $furgao = $colony->vehicles()->first();

        app(DespacharVeiculo::class)->retirarDeZona($colony, $furgao, $zona, ['metal_bruto' => 100]);

        // Reservado no despacho: o Depósito já caiu.
        $this->assertSame(400, $zona->fresh()->deposit_amount);
        $this->assertSame('em_rota', $furgao->fresh()->status);

        // Fecha a ida e depois a volta (o veículo é lento; dois trechos).
        Carbon::setTestNow($t0->copy()->addHour());
        app(ConcluirTrechos::class)->handle();   // ida: chega na zona, embarca, inicia a volta
        Carbon::setTestNow($t0->copy()->addHours(2));
        app(ConcluirTrechos::class)->handle();   // volta: entrega no slot, com tributo

        $furgao->refresh();
        $this->assertSame('ocioso', $furgao->status);

        // Chegou em casa o líquido: 100 menos o tributo de transporte do Metal Bruto (§25.2).
        $bps = ResourceType::find('metal_bruto')->tax_bps;
        $liquido = 100 - intdiv(100 * $bps, 10_000);
        $this->assertSame($liquido, (int) $colony->resources()->where('resource_type', 'metal_bruto')->value('amount'));

        Carbon::setTestNow();
    }

    public function test_nao_retira_de_zona_alheia(): void
    {
        $minha = $this->colonia();
        $outra = app(CreateColony::class)->handle(User::factory()->create(), 'Outra', 30, 30);
        $zona = $this->zonaComDeposito($outra, 500);

        $this->expectException(\App\Exceptions\DomainRuleException::class);
        app(DespacharVeiculo::class)->retirarDeZona($minha, $minha->vehicles()->first(), $zona, ['metal_bruto' => 100]);
    }

    public function test_so_o_mineral_da_zona_sai_dela(): void
    {
        $colony = $this->colonia();
        $zona = $this->zonaComDeposito($colony, 500);

        $this->expectException(\App\Exceptions\DomainRuleException::class);
        app(DespacharVeiculo::class)->retirarDeZona($colony, $colony->vehicles()->first(), $zona, ['agua' => 100]);
    }

    public function test_deposito_insuficiente(): void
    {
        $colony = $this->colonia();
        $zona = $this->zonaComDeposito($colony, 50);

        $this->expectException(\App\Exceptions\DomainRuleException::class);
        app(DespacharVeiculo::class)->retirarDeZona($colony, $colony->vehicles()->first(), $zona, ['metal_bruto' => 100]);
    }

    public function test_endpoint_de_retirada(): void
    {
        $colony = $this->colonia();
        $zona = $this->zonaComDeposito($colony, 500);
        $furgao = $colony->vehicles()->first();

        $this->actingAs($colony->user)
            ->postJson("/zones/{$zona->id}/withdraw", ['vehicle_id' => $furgao->id, 'cargo' => ['metal_bruto' => 100]])
            ->assertCreated()
            ->assertJson(['status' => 'em_rota', 'trip_purpose' => 'retirada']);
    }
}
