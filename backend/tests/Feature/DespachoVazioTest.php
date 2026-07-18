<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Logistics\ConcluirTrechos;
use App\Domain\Logistics\DespacharVeiculo;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\NeutralZone;
use App\Models\Vehicle;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O despacho vazio (D-109) — a função que substitui o antigo "Chamar de volta" (D-91): do Pátio,
 * pra casa ou pra uma zona neutra sua; de casa, pra Capital ou pra uma zona neutra sua; de uma
 * zona neutra sua, só de volta pra casa. Sempre vazio, sempre só de ida, e o veículo FICA onde
 * chega — inclusive numa zona, o terceiro lugar novo (`Vehicle::NA_ZONA`).
 */
class DespachoVazioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\ComponentRecipeSeeder::class);
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
    }

    private function colonia(int $x = 20, int $y = 20): Colony
    {
        $colony = app(CreateColony::class)->handle(User::factory()->create(), 'Base', $x, $y);
        $colony->resources()->where('resource_type', 'energia')->update(['amount' => 100_000]);

        return $colony->fresh();
    }

    private function zonaPropria(Colony $dona, int $x = 50, int $y = 50): NeutralZone
    {
        return NeutralZone::create([
            'x' => $x, 'y' => $y, 'district' => 'nordeste', 'mineral' => 'metal_bruto',
            'level' => 1, 'status' => 'protegida',
            'owner_colony_id' => $dona->id, 'command_post_level' => 1,
            'productive_at' => now()->subDay(),
            'deposit_level' => 1, 'deposit_amount' => 0,
        ]);
    }

    /** Deixa o veículo estacionado no Pátio, como se tivesse acabado de entregar carga lá. */
    private function noPatio(Vehicle $v): Vehicle
    {
        $v->forceFill(['status' => 'ocioso', 'local' => Vehicle::NO_PATIO])->save();

        return $v->fresh();
    }

    private function concluir(Vehicle $v): Vehicle
    {
        Carbon::setTestNow(Carbon::now()->addDay());
        app(ConcluirTrechos::class)->handle();
        Carbon::setTestNow();

        return $v->fresh();
    }

    // ---------------------------------------------------------------- do Pátio

    public function test_do_patio_para_casa_continua_funcionando(): void
    {
        $colony = $this->colonia();
        $v = $this->noPatio($colony->vehicles()->first());

        $resultado = app(DespacharVeiculo::class)->reposicionarVazio($colony, $v, 'colonia', $colony->id);
        $this->assertSame('em_rota', $resultado->status);

        $v = $this->concluir($v);
        $this->assertSame('ocioso', $v->status);
        $this->assertSame(Vehicle::EM_CASA, $v->local);
        $this->assertNull($v->destination_type);
        $this->assertNull($v->destination_id);
    }

    public function test_do_patio_para_zona_propria_estaciona_la(): void
    {
        $colony = $this->colonia();
        $zona = $this->zonaPropria($colony);
        $v = $this->noPatio($colony->vehicles()->first());

        app(DespacharVeiculo::class)->reposicionarVazio($colony, $v, 'zona_neutra', $zona->id);
        $v = $this->concluir($v);

        $this->assertSame('ocioso', $v->status);
        $this->assertSame(Vehicle::NA_ZONA, $v->local);
        $this->assertTrue($v->naZona());
        $this->assertSame('zona_neutra', $v->destination_type, 'preserva onde está, não limpa');
        $this->assertSame($zona->id, $v->destination_id);
    }

    public function test_do_patio_para_zona_alheia_e_recusado(): void
    {
        $colony = $this->colonia();
        $outra = $this->colonia(30, 30);
        $zonaAlheia = $this->zonaPropria($outra);
        $v = $this->noPatio($colony->vehicles()->first());

        $this->expectException(DomainRuleException::class);
        app(DespacharVeiculo::class)->reposicionarVazio($colony, $v, 'zona_neutra', $zonaAlheia->id);
    }

    public function test_do_patio_so_pra_propria_colonia_nao_pra_outra(): void
    {
        $colony = $this->colonia();
        $outra = $this->colonia(30, 30);
        $v = $this->noPatio($colony->vehicles()->first());

        $this->expectException(DomainRuleException::class);
        app(DespacharVeiculo::class)->reposicionarVazio($colony, $v, 'colonia', $outra->id);
    }

    // ---------------------------------------------------------------- de casa

    public function test_de_casa_para_capital_estaciona_no_patio(): void
    {
        $colony = $this->colonia();
        $v = $colony->vehicles()->first();

        app(DespacharVeiculo::class)->reposicionarVazio($colony, $v, 'mercado_central', null);
        $v = $this->concluir($v);

        $this->assertSame('ocioso', $v->status);
        $this->assertSame(Vehicle::NO_PATIO, $v->local);
        $this->assertNotNull($v->parked_at, 'o relógio da tarifa do Pátio começa a correr, como qualquer chegada lá');
    }

    public function test_de_casa_para_zona_propria_estaciona_la(): void
    {
        $colony = $this->colonia();
        $zona = $this->zonaPropria($colony);
        $v = $colony->vehicles()->first();

        app(DespacharVeiculo::class)->reposicionarVazio($colony, $v, 'zona_neutra', $zona->id);
        $v = $this->concluir($v);

        $this->assertSame(Vehicle::NA_ZONA, $v->local);
        $this->assertSame($zona->id, $v->destination_id);
    }

    public function test_de_casa_para_zona_alheia_e_recusado(): void
    {
        $colony = $this->colonia();
        $outra = $this->colonia(30, 30);
        $zonaAlheia = $this->zonaPropria($outra);

        $this->expectException(DomainRuleException::class);
        app(DespacharVeiculo::class)->reposicionarVazio($colony, $colony->vehicles()->first(), 'zona_neutra', $zonaAlheia->id);
    }

    // ---------------------------------------------------------------- da zona

    public function test_da_zona_volta_para_casa(): void
    {
        $colony = $this->colonia();
        $zona = $this->zonaPropria($colony);
        $v = $colony->vehicles()->first();

        app(DespacharVeiculo::class)->reposicionarVazio($colony, $v, 'zona_neutra', $zona->id);
        $v = $this->concluir($v);
        $this->assertTrue($v->naZona());

        app(DespacharVeiculo::class)->reposicionarVazio($colony, $v, 'colonia', $colony->id);
        $v = $this->concluir($v);

        $this->assertSame(Vehicle::EM_CASA, $v->local);
        $this->assertNull($v->destination_type, 'de volta pra casa, limpa de vez');
    }

    /** Fora de escopo desta entrega: da zona só se volta pra casa — não pra Capital nem pra outra zona. */
    public function test_da_zona_nao_vai_para_a_capital(): void
    {
        $colony = $this->colonia();
        $zona = $this->zonaPropria($colony);
        $v = $colony->vehicles()->first();

        app(DespacharVeiculo::class)->reposicionarVazio($colony, $v, 'zona_neutra', $zona->id);
        $v = $this->concluir($v);

        $this->expectException(DomainRuleException::class);
        app(DespacharVeiculo::class)->reposicionarVazio($colony, $v, 'mercado_central', null);
    }

    public function test_da_zona_nao_vai_para_outra_zona(): void
    {
        $colony = $this->colonia();
        $zona = $this->zonaPropria($colony, 50, 50);
        $outraZona = $this->zonaPropria($colony, 60, 60);
        $v = $colony->vehicles()->first();

        app(DespacharVeiculo::class)->reposicionarVazio($colony, $v, 'zona_neutra', $zona->id);
        $v = $this->concluir($v);

        $this->expectException(DomainRuleException::class);
        app(DespacharVeiculo::class)->reposicionarVazio($colony, $v, 'zona_neutra', $outraZona->id);
    }

    // ---------------------------------------------------------------- energia, sem carga

    public function test_reposicionamento_gasta_energia_mas_nao_toca_em_mais_nada(): void
    {
        $colony = $this->colonia();
        $antes = $colony->resources->pluck('amount', 'resource_type')->except('energia')->all();

        app(DespacharVeiculo::class)->reposicionarVazio($colony, $colony->vehicles()->first(), 'mercado_central', null);

        $depois = $colony->fresh()->resources->pluck('amount', 'resource_type')->except('energia')->all();
        $this->assertSame($antes, $depois, 'nenhum recurso além de energia muda no despacho vazio');

        $energiaDepois = (int) $colony->fresh()->resources()->where('resource_type', 'energia')->value('amount');
        $this->assertLessThan(100_000, $energiaDepois, 'a energia da viagem foi debitada');
    }

    // ---------------------------------------------------------------- via endpoint HTTP

    public function test_endpoint_de_despacho_roteia_vazio_para_reposicionar(): void
    {
        $colony = $this->colonia();
        $zona = $this->zonaPropria($colony);
        $v = $colony->vehicles()->first();

        $this->actingAs($colony->user)
            ->postJson("/vehicles/{$v->id}/dispatch", [
                'destination_type' => 'zona_neutra',
                'destination_id' => $zona->id,
                'cargo' => [],
            ])
            ->assertCreated()
            ->assertJson(['status' => 'em_rota']);

        $v = $this->concluir($v);
        $this->assertTrue($v->naZona());
    }
}
