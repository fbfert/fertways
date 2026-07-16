<?php

namespace Tests\Feature;

use App\Domain\Capital\Patio;
use App\Domain\Colony\CreateColony;
use App\Domain\Logistics\ConcluirTrechos;
use App\Domain\Logistics\DespacharVeiculo;
use App\Domain\Treasury\Tesouro;
use App\Models\Colony;
use App\Models\MarketAccount;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O Pátio Logístico da Capital (D-65): o veículo que fica, a hora que se paga e a viagem que sai
 * de lá.
 *
 * A colônia mora em (30,0) — 30 slots até a Capital (0,0). Furgão a 4 slots/min: 7,5 min e 7,5 kWh
 * por perna. Uma perna custa 8 kWh (arredonda para cima); ida e volta, 15.
 */
class PatioDaCapitalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ResourceTypeSeeder::class);
        $this->seed(ComponentRecipeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
    }

    private function colonia(string $nick = 'patio', int $x = 30, int $y = 0): Colony
    {
        $user = User::factory()->create(['email' => "{$nick}@t.test", 'nickname' => $nick]);

        return app(CreateColony::class)->handle($user, "Colônia {$nick}", $x, $y)->fresh();
    }

    private function abastecer(Colony $c, array $recursos): void
    {
        foreach ($recursos as $recurso => $qtd) {
            $c->resources()->where('resource_type', $recurso)->update(['amount' => $qtd]);
        }
    }

    private function furgao(Colony $c): Vehicle
    {
        return $c->vehicles()->where('type', 'furgao_de_comercio')->firstOrFail();
    }

    private function estoque(Colony $c, string $recurso): int
    {
        return (int) $c->resources()->where('resource_type', $recurso)->value('amount');
    }

    private function saldo(Colony $c, string $recurso): int
    {
        return (int) MarketAccount::where('colony_id', $c->id)
            ->where('resource_type', $recurso)
            ->value('amount');
    }

    /** Põe um furgão do colono estacionado no Pátio, como se ele tivesse acabado de depositar. */
    private function estacionar(Colony $c, ?Carbon $desde = null): Vehicle
    {
        $desde = $desde ?? now();
        $v = $this->furgao($c);

        $v->forceFill([
            'local' => Vehicle::NO_PATIO,
            'status' => 'ocioso',
            'parked_at' => $desde,
            'patio_cobrado_ate' => $desde,
        ])->save();

        return $v->fresh();
    }

    // ------------------------------------------------------------------ a carga com vários recursos

    /**
     * O §25.4 mede a capacidade em **unidades somadas**, não em "um recurso por viagem" — e o
     * domínio sempre soube disso (`array_sum`). Quem só sabia levar um era a tela. Este teste trava
     * o que o D-65 passou a oferecer: um despacho com três recursos na mesma carroceria.
     */
    #[Test]
    public function um_veiculo_leva_varios_recursos_de_uma_vez(): void
    {
        $c = $this->colonia();
        $destino = $this->colonia('vizinha', 33, 0);
        $this->abastecer($c, ['metal_bruto' => 3_000, 'agua' => 2_000, 'oxigenio' => 1_000, 'energia' => 100]);

        app(DespacharVeiculo::class)->handle($c, $this->furgao($c), 'colonia', $destino->id, [
            'metal_bruto' => 3_000,
            'agua' => 2_000,
            'oxigenio' => 1_000,
        ]);

        // 6.000 = a capacidade do Furgão, na trave.
        $this->assertSame(['metal_bruto' => 3_000, 'agua' => 2_000, 'oxigenio' => 1_000], $this->furgao($c)->fresh()->cargo_json);

        Carbon::setTestNow(now()->addMinutes(2));
        app(ConcluirTrechos::class)->handle();

        // Três lotes, três tributos: o fato tributável é o lote, não a viagem (§25.9).
        $this->assertSame(3, DB::table('tax_events')->count());
        $this->assertSame(2_910, $this->estoque($destino, 'metal_bruto'), '3% de 3.000 = 90');

        Carbon::setTestNow();
    }

    // ------------------------------------------------------------------ o veículo que fica

    #[Test]
    public function o_deposito_paga_uma_perna_de_energia_e_nao_duas(): void
    {
        $c = $this->colonia();
        $this->abastecer($c, ['metal_bruto' => 500, 'energia' => 100]);

        app(DespacharVeiculo::class)->handle($c, $this->furgao($c), 'mercado_central', null, ['metal_bruto' => 500]);

        // 8, e não 15: ele não volta. Cada perna paga a sua (D-65).
        $this->assertSame(92, $this->estoque($c, 'energia'));
        $this->assertNull($this->furgao($c)->fresh()->return_distance_slots, 'viagem só de ida');
    }

    // ------------------------------------------------------------------ sair do Pátio

    /**
     * Do Pátio para casa: a carga sai do **depósito** (não do estoque, que está a 30 slots dali),
     * a viagem é só de ida, e o tributo incide na chegada ao slot — como em qualquer entrega
     * física (§25.2). É a retirada do §25.8 ao contrário: em vez de mandar um veículo de casa
     * buscar, você já tinha um lá.
     */
    #[Test]
    public function do_patio_para_casa_a_carga_sai_do_deposito_e_o_veiculo_fica_em_casa(): void
    {
        $c = $this->colonia();
        $this->abastecer($c, ['energia' => 100]);
        MarketAccount::create(['colony_id' => $c->id, 'resource_type' => 'metal_bruto', 'amount' => 1_000]);
        $v = $this->estacionar($c);

        app(DespacharVeiculo::class)->handle($c, $v, 'colonia', $c->id, ['metal_bruto' => 1_000]);

        // Reservado no despacho, como toda saída do depósito (D-32).
        $this->assertSame(0, $this->saldo($c, 'metal_bruto'));
        $this->assertSame(92, $this->estoque($c, 'energia'), 'uma perna: Capital → casa');
        $this->assertNull($v->fresh()->return_distance_slots);

        Carbon::setTestNow(now()->addMinutes(8));
        app(ConcluirTrechos::class)->handle();

        // 3% de tributo na entrega: 1.000 → 970.
        $this->assertSame(970, $this->estoque($c, 'metal_bruto'));

        $v = $v->fresh();
        $this->assertSame('ocioso', $v->status);
        $this->assertSame(Vehicle::EM_CASA, $v->local, 'chegou em casa e ficou');
        $this->assertNull($v->parked_at, 'saiu da vaga');

        Carbon::setTestNow();
    }

    /**
     * Do Pátio para outro colono: entrega lá e **segue para casa**. Três pontos, duas distâncias —
     * foi esta decisão do usuário que obrigou a viagem a ter pernas independentes.
     *
     * A vizinha mora em (0,10): 10 slots da Capital, e 31 dela até a nossa colônia em (30,0)
     * (√(900+100) = 31,6 → 32... a euclidiana half-up dá 32). A ida e a volta são distâncias
     * diferentes, e é isso que o teste trava.
     */
    #[Test]
    public function do_patio_para_outro_colono_entrega_e_segue_para_casa(): void
    {
        $c = $this->colonia();
        $vizinha = $this->colonia('vizinha', 0, 10);
        $this->abastecer($c, ['energia' => 200]);
        MarketAccount::create(['colony_id' => $c->id, 'resource_type' => 'metal_bruto', 'amount' => 1_000]);
        $v = $this->estacionar($c);

        app(DespacharVeiculo::class)->handle($c, $v, 'colonia', $vizinha->id, ['metal_bruto' => 1_000]);

        $v = $v->fresh();
        $this->assertSame(10, $v->distance_slots, 'a ida é Capital → vizinha');
        $this->assertSame(32, $v->return_distance_slots, 'a volta é vizinha → casa, e é outra distância');

        Carbon::setTestNow(now()->addMinutes(3));
        app(ConcluirTrechos::class)->handle();

        $this->assertSame(970, $this->estoque($vizinha, 'metal_bruto'), 'entregou na vizinha, com tributo');

        $v = $v->fresh();
        $this->assertSame('volta', $v->leg);
        $this->assertSame(32, $v->distance_slots, 'a perna que ele roda agora é a da volta');

        Carbon::setTestNow(now()->addMinutes(9));
        app(ConcluirTrechos::class)->handle();

        $v = $v->fresh();
        $this->assertSame('ocioso', $v->status);
        $this->assertSame(Vehicle::EM_CASA, $v->local, 'a volta é sempre para casa, não para a vaga');

        Carbon::setTestNow();
    }

    #[Test]
    public function do_patio_nao_se_despacha_para_a_capital(): void
    {
        $c = $this->colonia();
        $this->abastecer($c, ['energia' => 100]);
        MarketAccount::create(['colony_id' => $c->id, 'resource_type' => 'metal_bruto', 'amount' => 100]);
        $v = $this->estacionar($c);

        $this->expectExceptionMessage('já está na Capital');

        app(DespacharVeiculo::class)->handle($c, $v, 'mercado_central', null, ['metal_bruto' => 100]);
    }

    #[Test]
    public function do_patio_nao_se_despacha_alem_do_saldo_do_deposito(): void
    {
        $c = $this->colonia();
        $this->abastecer($c, ['energia' => 100]);
        MarketAccount::create(['colony_id' => $c->id, 'resource_type' => 'metal_bruto', 'amount' => 100]);
        $v = $this->estacionar($c);

        try {
            app(DespacharVeiculo::class)->handle($c, $v, 'colonia', $c->id, ['metal_bruto' => 500]);
            $this->fail('despachou 500 com 100 no depósito');
        } catch (\App\Exceptions\DomainRuleException $e) {
            $this->assertSame('saldo_mercado_insuficiente', $e->codigo);
        }

        $this->assertSame(100, $this->saldo($c, 'metal_bruto'), 'o saldo não foi tocado');
        $this->assertTrue($v->fresh()->noPatio(), 'o veículo continua na vaga');
    }

    // ------------------------------------------------------------------ a hora do estacionamento

    /**
     * O GDD publica "cobrança por hora" no slot 6 e **nunca o preço**; o usuário arbitrou 0,005
     * Fert$/hora (D-65). A hora vai para o Tesouro, como o tributo (§2.1, D-57).
     */
    #[Test]
    public function a_hora_parada_e_cobrada_do_colono_e_vai_ao_tesouro(): void
    {
        $c = $this->colonia();
        $c->forceFill(['fert_micro' => 1_000_000])->save(); // 1 Fert$
        $this->estacionar($c, now());

        // Três horas e meia paradas: cobram-se as TRÊS horas cheias. A meia fica para o próximo tick.
        Carbon::setTestNow(now()->addMinutes(210));
        $fora = app(Patio::class)->handle();

        $this->assertSame(['cobrados' => 1, 'rebocados' => 0], $fora);
        $this->assertSame(1_000_000 - 3 * Patio::TARIFA_MICRO_HORA, (int) $c->fresh()->fert_micro);
        $this->assertSame(3 * Patio::TARIFA_MICRO_HORA, app(Tesouro::class)->saldoFertMicro());

        // O tick seguinte, no mesmo minuto, não cobra de novo.
        app(Patio::class)->handle();
        $this->assertSame(1_000_000 - 3 * Patio::TARIFA_MICRO_HORA, (int) $c->fresh()->fert_micro);

        Carbon::setTestNow();
    }

    #[Test]
    public function a_fracao_de_hora_nao_e_cobrada(): void
    {
        $c = $this->colonia();
        $c->forceFill(['fert_micro' => 1_000_000])->save();
        $this->estacionar($c, now());

        Carbon::setTestNow(now()->addMinutes(59));
        app(Patio::class)->handle();

        $this->assertSame(1_000_000, (int) $c->fresh()->fert_micro, 'a hora só fecha aos 60 minutos');

        Carbon::setTestNow();
    }

    /**
     * Sem Fert$, o veículo é rebocado para casa (D-65). Ninguém fica devendo e ninguém perde o
     * veículo — perde a vaga. O reboque é de graça: cobrar a energia de quem não tinha nem a hora
     * seria empurrar a dívida para o outro estoque.
     */
    #[Test]
    public function sem_fert_o_veiculo_e_rebocado_para_casa_de_graca(): void
    {
        $c = $this->colonia();
        $c->forceFill(['fert_micro' => 0])->save();
        $this->abastecer($c, ['energia' => 50]);
        $v = $this->estacionar($c, now());

        Carbon::setTestNow(now()->addMinutes(60));
        $fora = app(Patio::class)->handle();

        $this->assertSame(['cobrados' => 0, 'rebocados' => 1], $fora);

        $v = $v->fresh();
        $this->assertSame('em_rota', $v->status);
        $this->assertSame('reboque', $v->trip_purpose);
        $this->assertSame(30, $v->distance_slots);
        $this->assertNull($v->return_distance_slots, 'o reboque é só de ida: ele chega em casa e fica');
        $this->assertSame(50, $this->estoque($c, 'energia'), 'o reboque não cobra energia');
        $this->assertSame(0, (int) $c->fresh()->fert_micro, 'e não deixa dívida');

        // Chega em casa e fica ocioso, no slot.
        Carbon::setTestNow(now()->addMinutes(8));
        app(ConcluirTrechos::class)->handle();

        $v = $v->fresh();
        $this->assertSame('ocioso', $v->status);
        $this->assertSame(Vehicle::EM_CASA, $v->local);

        Carbon::setTestNow();
    }

    // ------------------------------------------------------------------ chamar de volta vazio (D-91)

    /**
     * Resgatar o próprio veículo vazio não é usar o Mercado (D-91): mesmo com Confiança Comercial
     * abaixo do limiar — que fecharia qualquer outro despacho do Pátio — o colono chama de volta.
     * Paga a energia da perna, como qualquer despacho: só a exigência de carga e a trava de
     * Confiança são dispensadas, não o custo.
     */
    #[Test]
    public function chamar_de_volta_vazio_nao_exige_confianca_comercial_e_paga_energia(): void
    {
        $c = $this->colonia();
        $this->abastecer($c, ['energia' => 100]);
        $c->user->forceFill(['confianca_comercial' => 0])->save(); // abaixo do limiar de 200
        $v = $this->estacionar($c);

        app(DespacharVeiculo::class)->handle($c, $v, 'colonia', $c->id, []);

        $this->assertSame(92, $this->estoque($c, 'energia'), 'uma perna: Capital → casa, mesmo vazio');
        $this->assertNull($v->fresh()->cargo_json, 'vazio grava null, não um array vazio');

        Carbon::setTestNow(now()->addMinutes(8));
        app(ConcluirTrechos::class)->handle();

        $v = $v->fresh();
        $this->assertSame('ocioso', $v->status);
        $this->assertSame(Vehicle::EM_CASA, $v->local);

        Carbon::setTestNow();
    }

    /** Com carga de verdade, a trava de Confiança Comercial continua valendo — só a volta VAZIA escapa dela. */
    #[Test]
    public function com_carga_de_verdade_a_trava_de_confianca_comercial_continua(): void
    {
        $c = $this->colonia();
        $this->abastecer($c, ['energia' => 100]);
        MarketAccount::create(['colony_id' => $c->id, 'resource_type' => 'metal_bruto', 'amount' => 1_000]);
        $c->user->forceFill(['confianca_comercial' => 0])->save();
        $v = $this->estacionar($c);

        try {
            app(DespacharVeiculo::class)->handle($c, $v, 'colonia', $c->id, ['metal_bruto' => 1_000]);
            $this->fail('despachou carga com Confiança Comercial abaixo do limiar');
        } catch (\App\Exceptions\DomainRuleException $e) {
            $this->assertSame('confianca_comercial_baixa', $e->codigo);
        }
    }

    /** Vazio só é isenção para a PRÓPRIA colônia — para outro colono continua sendo "o veículo não sai vazio". */
    #[Test]
    public function vazio_nao_se_despacha_para_outro_colono(): void
    {
        $c = $this->colonia();
        $vizinha = $this->colonia('vizinha', 0, 10);
        $this->abastecer($c, ['energia' => 100]);
        $v = $this->estacionar($c);

        try {
            app(DespacharVeiculo::class)->handle($c, $v, 'colonia', $vizinha->id, []);
            $this->fail('despachou vazio para outro colono');
        } catch (\App\Exceptions\DomainRuleException $e) {
            $this->assertSame('carga_vazia', $e->codigo);
        }
    }

    #[Test]
    public function veiculo_em_rota_nao_paga_a_hora_do_patio(): void
    {
        $c = $this->colonia();
        $c->forceFill(['fert_micro' => 1_000_000])->save();
        $this->abastecer($c, ['metal_bruto' => 500, 'energia' => 100]);

        // Em rota para a Capital: ainda não estacionou.
        app(DespacharVeiculo::class)->handle($c, $this->furgao($c), 'mercado_central', null, ['metal_bruto' => 500]);

        Carbon::setTestNow(now()->addMinutes(60));
        $this->assertSame(['cobrados' => 0, 'rebocados' => 0], app(Patio::class)->handle());
        $this->assertSame(1_000_000, (int) $c->fresh()->fert_micro);

        Carbon::setTestNow();
    }
}
