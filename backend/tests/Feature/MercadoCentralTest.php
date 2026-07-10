<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Logistics\ConcluirTrechos;
use App\Domain\Logistics\DespacharVeiculo;
use App\Models\Colony;
use App\Models\Ledger;
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
 * Conta do colono no Mercado Central (GDD §25.8): depósito, retirada e o tributo de cada um.
 *
 * A colônia fica em (30,0): 30 slots até a Capital (0,0), uma célula da tabela do §25.6. Furgão a 4
 * slots/min → 7,5 min por trecho, 15 min na viagem inteira, 15 kWh de energia.
 */
class MercadoCentralTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ResourceTypeSeeder::class);
        $this->seed(ComponentRecipeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
    }

    private function colonia(): Colony
    {
        $user = User::factory()->create(['email' => 'm@t.test', 'nickname' => 'mercante']);
        // (30,0): 30 slots exatos até a Capital, agora em (0,0) (D-51). Periferia, fundável.
        $colony = app(CreateColony::class)->handle($user, 'Colônia mercante', 30, 0);

        return $colony->fresh();
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

    #[Test]
    public function o_deposito_credita_a_conta_e_cobra_o_tributo_so_na_entrega(): void
    {
        $c = $this->colonia();
        $this->abastecer($c, ['metal_bruto' => 1_000, 'energia' => 100]);

        app(DespacharVeiculo::class)->handle($c, $this->furgao($c), 'mercado_central', null, ['metal_bruto' => 1_000]);

        // A carga saiu do estoque no despacho, mas ainda não chegou: a conta segue zerada.
        $this->assertSame(0, $this->estoque($c, 'metal_bruto'));
        $this->assertSame(85, $this->estoque($c, 'energia'));
        $this->assertSame(0, $this->saldo($c, 'metal_bruto'));
        $this->assertSame(0, DB::table('tax_events')->count());

        Carbon::setTestNow(now()->addMinutes(8));
        app(ConcluirTrechos::class)->handle();

        // Metal Bruto é primário: 3% (§8.3). 1.000 -> tributo 30, líquido 970 na conta.
        $this->assertSame(970, $this->saldo($c, 'metal_bruto'));
        $this->assertSame(0, $this->estoque($c, 'metal_bruto'), 'o depósito não volta ao estoque');

        $tributo = DB::table('tax_events')->where('colony_id', $c->id)->first();
        $this->assertSame('transporte_entrega', $tributo->kind);
        $this->assertSame(1_000, (int) $tributo->base_amount);
        $this->assertSame(30, (int) $tributo->tax_amount);

        $this->assertSame(970, Ledger::where('type', 'deposito_mercado')->value('amount'));

        Carbon::setTestNow();
    }

    #[Test]
    public function depois_do_deposito_o_veiculo_volta_vazio_e_fica_ocioso(): void
    {
        $c = $this->colonia();
        $this->abastecer($c, ['metal_bruto' => 1_000, 'energia' => 100]);

        app(DespacharVeiculo::class)->handle($c, $this->furgao($c), 'mercado_central', null, ['metal_bruto' => 500]);

        Carbon::setTestNow(now()->addMinutes(8));
        app(ConcluirTrechos::class)->handle();

        $v = $this->furgao($c)->fresh();
        $this->assertSame('volta', $v->leg);
        $this->assertNull($v->cargo_json, 'a carga ficou no Mercado');

        Carbon::setTestNow(now()->addMinutes(8));
        app(ConcluirTrechos::class)->handle();

        $v = $this->furgao($c)->fresh();
        $this->assertSame('ocioso', $v->status);
        $this->assertNull($v->trip_purpose);

        // Voltar vazio não é uma segunda entrega: um único tributo na viagem inteira.
        $this->assertSame(1, DB::table('tax_events')->count());

        Carbon::setTestNow();
    }

    #[Test]
    public function a_retirada_reserva_o_saldo_no_ato_do_despacho(): void
    {
        $c = $this->colonia();
        $this->abastecer($c, ['energia' => 100]);
        MarketAccount::create(['colony_id' => $c->id, 'resource_type' => 'metal_bruto', 'amount' => 1_000]);

        app(DespacharVeiculo::class)->retirar($c, $this->furgao($c), ['metal_bruto' => 500]);

        // Reservado já: outro veículo não pode prometer o mesmo saldo (D-32).
        $this->assertSame(500, $this->saldo($c, 'metal_bruto'));
        $this->assertSame(0, $this->estoque($c, 'metal_bruto'), 'a carga ainda está no Mercado');
        $this->assertSame(85, $this->estoque($c, 'energia'));

        $v = $this->furgao($c)->fresh();
        $this->assertSame('retirada', $v->trip_purpose);
        $this->assertSame('ida', $v->leg);
        $this->assertSame(30, $v->distance_slots);
        $this->assertSame(-500, Ledger::where('type', 'retirada_mercado')->value('amount'));
    }

    #[Test]
    public function a_retirada_so_entrega_e_tributa_quando_a_carga_chega_ao_slot(): void
    {
        $c = $this->colonia();
        $this->abastecer($c, ['energia' => 100]);
        MarketAccount::create(['colony_id' => $c->id, 'resource_type' => 'metal_bruto', 'amount' => 1_000]);

        app(DespacharVeiculo::class)->retirar($c, $this->furgao($c), ['metal_bruto' => 500]);

        // Fim da ida: o veículo chegou ao Mercado e carregou. Nada entregue, nada tributado.
        Carbon::setTestNow(now()->addMinutes(8));
        app(ConcluirTrechos::class)->handle();

        $v = $this->furgao($c)->fresh();
        $this->assertSame('volta', $v->leg);
        $this->assertSame(['metal_bruto' => 500], $v->cargo_json, 'a carga embarcou na Capital');
        $this->assertSame(0, $this->estoque($c, 'metal_bruto'));
        $this->assertSame(0, DB::table('tax_events')->count());

        // Fim da volta: §25.8, "tributo na chegada". 500 -> tributo 15, líquido 485.
        Carbon::setTestNow(now()->addMinutes(8));
        app(ConcluirTrechos::class)->handle();

        $this->assertSame(485, $this->estoque($c, 'metal_bruto'));
        $this->assertSame(500, $this->saldo($c, 'metal_bruto'), 'o resto continua no Mercado');

        $tributo = DB::table('tax_events')->first();
        $this->assertSame(500, (int) $tributo->base_amount);
        $this->assertSame(15, (int) $tributo->tax_amount);

        $v = $this->furgao($c)->fresh();
        $this->assertSame('ocioso', $v->status);
        $this->assertNull($v->cargo_json);

        Carbon::setTestNow();
    }

    #[Test]
    public function nao_se_retira_mais_do_que_a_conta_tem(): void
    {
        $c = $this->colonia();
        $this->abastecer($c, ['energia' => 100]);
        MarketAccount::create(['colony_id' => $c->id, 'resource_type' => 'metal_bruto', 'amount' => 100]);

        $this->expectExceptionMessage('Sua conta no Mercado não tem 101 de metal_bruto');
        app(DespacharVeiculo::class)->retirar($c, $this->furgao($c), ['metal_bruto' => 101]);
    }

    #[Test]
    public function retirar_recurso_que_nunca_foi_depositado_e_recusado(): void
    {
        $c = $this->colonia();
        $this->abastecer($c, ['energia' => 100]);

        // Sem linha nenhuma na conta: o débito atômico não encontra o que decrementar.
        $this->expectExceptionMessage('Sua conta no Mercado não tem');
        app(DespacharVeiculo::class)->retirar($c, $this->furgao($c), ['metal_bruto' => 1]);
    }

    #[Test]
    public function a_reserva_nao_e_devolvida_a_energia_nem_o_veiculo_se_o_pedido_falha(): void
    {
        $c = $this->colonia();
        $this->abastecer($c, ['energia' => 100]);
        MarketAccount::create(['colony_id' => $c->id, 'resource_type' => 'metal_bruto', 'amount' => 100]);

        // Um pedido com dois recursos, o segundo impossível: a transação inteira volta atrás.
        try {
            app(DespacharVeiculo::class)->retirar($c, $this->furgao($c), ['metal_bruto' => 50, 'agua' => 999]);
            $this->fail('deveria ter recusado');
        } catch (\Throwable) {
        }

        $this->assertSame(100, $this->saldo($c, 'metal_bruto'), 'o saldo do primeiro recurso não foi tocado');
        $this->assertSame(100, $this->estoque($c, 'energia'), 'a energia não foi cobrada');
        $this->assertSame('ocioso', $this->furgao($c)->fresh()->status);
    }

    #[Test]
    public function tick_repetido_nao_credita_a_conta_duas_vezes(): void
    {
        $c = $this->colonia();
        $this->abastecer($c, ['metal_bruto' => 1_000, 'energia' => 100]);

        app(DespacharVeiculo::class)->handle($c, $this->furgao($c), 'mercado_central', null, ['metal_bruto' => 1_000]);

        Carbon::setTestNow(now()->addMinutes(8));
        app(ConcluirTrechos::class)->handle();

        // Força o mesmo trecho de ida a vencer outra vez: retry do cron, ou dois crons juntos.
        $this->furgao($c)->fresh()->forceFill([
            'leg' => 'ida',
            'cargo_json' => ['metal_bruto' => 1_000],
        ])->save();
        app(ConcluirTrechos::class)->handle();

        $this->assertSame(970, $this->saldo($c, 'metal_bruto'));
        $this->assertSame(1, DB::table('tax_events')->count());
        $this->assertSame(1, Ledger::where('type', 'deposito_mercado')->count());

        Carbon::setTestNow();
    }

    #[Test]
    public function o_deposito_e_a_retirada_do_mesmo_lote_sao_dois_fatos_tributaveis(): void
    {
        $c = $this->colonia();
        $this->abastecer($c, ['metal_bruto' => 1_000, 'energia' => 100]);

        // Depósito: 1.000 -> 970 na conta.
        app(DespacharVeiculo::class)->handle($c, $this->furgao($c), 'mercado_central', null, ['metal_bruto' => 1_000]);
        Carbon::setTestNow(now()->addMinutes(16));
        app(ConcluirTrechos::class)->handle();
        app(ConcluirTrechos::class)->handle();

        // Retirada dos mesmos 970 -> tributo 29, líquido 941 de volta ao estoque.
        app(DespacharVeiculo::class)->retirar($c, $this->furgao($c)->fresh(), ['metal_bruto' => 970]);
        Carbon::setTestNow(now()->addMinutes(16));
        app(ConcluirTrechos::class)->handle();
        app(ConcluirTrechos::class)->handle();

        $this->assertSame(941, $this->estoque($c, 'metal_bruto'));
        $this->assertSame(0, $this->saldo($c, 'metal_bruto'));

        /*
         * Duas entregas físicas, dois tributos — não é dupla tributação do mesmo fato. O §25.9
         * cobra "uma única vez, no momento da entrega física", e aqui houve duas. Mandar recurso
         * ao Mercado e trazê-lo de volta sem vender custa 59 de 1.000. Ver D-32.
         */
        $this->assertSame(2, DB::table('tax_events')->count());
        $this->assertSame(59, (int) DB::table('tax_events')->sum('tax_amount'));

        Carbon::setTestNow();
    }
}
