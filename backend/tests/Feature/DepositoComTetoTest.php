<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Logistics\ConcluirTrechos;
use App\Domain\Logistics\DespacharVeiculo;
use App\Domain\Market\ColocarOrdem;
use App\Domain\Market\Deposito;
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
 * O teto do depósito na Capital (D-58).
 *
 * 10.000 por recurso primário, 2.500 por secundário, 100 por raro. São **arbitragem do usuário**,
 * não valores do GDD — por isso vivem em `Deposito`, e não no catálogo gerado do GDD.
 *
 * A colônia fica em (30,0): 30 slots até a Capital, Furgão a 4 slots/min → 7,5 min por trecho.
 */
class DepositoComTetoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ResourceTypeSeeder::class);
        $this->seed(ComponentRecipeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
    }

    private function colonia(string $nick = 'mercante', int $x = 30, int $y = 0): Colony
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

    private function saldo(Colony $c, string $recurso): int
    {
        return (int) MarketAccount::where('colony_id', $c->id)
            ->where('resource_type', $recurso)->value('amount');
    }

    private function estoque(Colony $c, string $recurso): int
    {
        return (int) $c->resources()->where('resource_type', $recurso)->value('amount');
    }

    #[Test]
    public function o_teto_vem_da_classe_tributaria_do_recurso(): void
    {
        // As três classes do §8.3, que já separavam exatamente os grupos que o usuário quis.
        $this->assertSame(10_000, Deposito::teto('metal_bruto'), 'primário');
        $this->assertSame(2_500, Deposito::teto('componentes_eletronicos'), 'secundário (§18.2)');
        $this->assertSame(2_500, Deposito::teto('ouro'), 'mineral do §18.3 também é secundário');
        $this->assertSame(100, Deposito::teto('niobio_alienigena'), 'raro');
    }

    #[Test]
    public function o_despacho_e_barrado_quando_a_carga_nao_cabe_no_deposito(): void
    {
        $c = $this->colonia();
        $this->abastecer($c, ['metal_bruto' => 1_000, 'energia' => 100]);
        MarketAccount::create(['colony_id' => $c->id, 'resource_type' => 'metal_bruto', 'amount' => 9_900]);

        // Cabem 100; o líquido de 1.000 seria 970. O colono não deve perder a viagem para descobrir.
        $this->expectExceptionMessage('comporta 100 unidade(s) a mais');
        app(DespacharVeiculo::class)->handle($c, $this->furgao($c), 'mercado_central', null, ['metal_bruto' => 1_000]);
    }

    #[Test]
    public function a_carga_nao_sai_do_estoque_quando_o_despacho_e_barrado(): void
    {
        $c = $this->colonia();
        $this->abastecer($c, ['metal_bruto' => 1_000, 'energia' => 100]);
        MarketAccount::create(['colony_id' => $c->id, 'resource_type' => 'metal_bruto', 'amount' => 9_900]);

        try {
            app(DespacharVeiculo::class)->handle($c, $this->furgao($c), 'mercado_central', null, ['metal_bruto' => 1_000]);
        } catch (\Throwable) {
            // esperado
        }

        $this->assertSame(1_000, $this->estoque($c, 'metal_bruto'), 'nada foi debitado');
        $this->assertSame(100, $this->estoque($c, 'energia'), 'nem a energia da viagem');
        $this->assertSame('ocioso', $this->furgao($c)->fresh()->status);
    }

    /**
     * O caso difícil: o despacho cabia, mas o depósito encheu **durante** a viagem. Entra o que
     * couber, e o resto volta na carroceria — sem tributo, porque não foi entregue (D-58).
     */
    #[Test]
    public function o_excedente_que_nao_coube_volta_no_veiculo_e_nao_paga_tributo(): void
    {
        $c = $this->colonia();
        $this->abastecer($c, ['metal_bruto' => 1_000, 'energia' => 100]);

        app(DespacharVeiculo::class)->handle($c, $this->furgao($c), 'mercado_central', null, ['metal_bruto' => 1_000]);
        $this->assertSame(0, $this->estoque($c, 'metal_bruto'), 'saiu do estoque na partida');

        // Enquanto o furgão viaja, o depósito enche por outra via. Sobram 200 de espaço.
        MarketAccount::updateOrCreate(
            ['colony_id' => $c->id, 'resource_type' => 'metal_bruto'],
            ['amount' => 9_800],
        );

        Carbon::setTestNow(now()->addMinutes(8));
        app(ConcluirTrechos::class)->handle();

        /*
         * Cabem 200 líquidos. Como o tributo é 3% do bruto, o maior bruto entregável é 206:
         * 206 - trunc(206 × 3%) = 206 - 6 = 200. Excedente = 1.000 - 206 = 794.
         */
        $this->assertSame(10_000, $this->saldo($c, 'metal_bruto'), 'encheu exatamente até o teto');

        $tributo = DB::table('tax_events')->where('kind', 'transporte_entrega')->first();
        $this->assertSame(206, (int) $tributo->base_amount, 'tributa só o que foi entregue de fato');
        $this->assertSame(6, (int) $tributo->tax_amount);

        // O excedente ainda está no veículo: não voltou ao estoque nem se perdeu.
        $this->assertSame(0, $this->estoque($c, 'metal_bruto'));
        $this->assertSame(['metal_bruto' => 794], $this->furgao($c)->fresh()->cargo_json);

        Carbon::setTestNow(now()->addMinutes(8));
        app(ConcluirTrechos::class)->handle();

        $this->assertSame(794, $this->estoque($c, 'metal_bruto'), 'o excedente voltou inteiro');
        $this->assertSame(1, DB::table('tax_events')->count(), 'a volta não é um novo fato tributável');
        $this->assertSame(794, Ledger::where('type', 'devolucao_deposito')->value('amount'));
        $this->assertSame('ocioso', $this->furgao($c)->fresh()->status);

        Carbon::setTestNow();
    }

    #[Test]
    public function nada_se_perde_quando_o_deposito_ja_esta_cheio_na_chegada(): void
    {
        $c = $this->colonia();
        $this->abastecer($c, ['niobio_alienigena' => 50, 'energia' => 100]);

        app(DespacharVeiculo::class)->handle($c, $this->furgao($c), 'mercado_central', null, ['niobio_alienigena' => 50]);

        // Raro: teto de 100. O depósito lota antes de o veículo chegar.
        MarketAccount::updateOrCreate(
            ['colony_id' => $c->id, 'resource_type' => 'niobio_alienigena'],
            ['amount' => 100],
        );

        Carbon::setTestNow(now()->addMinutes(16));
        app(ConcluirTrechos::class)->handle();
        app(ConcluirTrechos::class)->handle();

        $this->assertSame(100, $this->saldo($c, 'niobio_alienigena'), 'o teto não foi ultrapassado');
        $this->assertSame(0, DB::table('tax_events')->count(), 'nada entrou, nada foi tributado');
        $this->assertSame(50, $this->estoque($c, 'niobio_alienigena'), 'a carga inteira voltou');

        Carbon::setTestNow();
    }

    #[Test]
    public function a_oferta_de_venda_continua_ocupando_espaco_no_teto(): void
    {
        $c = $this->colonia();
        MarketAccount::create(['colony_id' => $c->id, 'resource_type' => 'metal_bruto', 'amount' => 10_000]);

        app(ColocarOrdem::class)->handle($c, 'sell', 'metal_bruto', 4_000, 50_000);

        // O saldo caiu (foi ao escrow), mas o espaço NÃO se liberou: senão bastaria anunciar tudo a
        // preço absurdo para esvaziar o depósito e trazer mais carga.
        $this->assertSame(6_000, $this->saldo($c, 'metal_bruto'));
        $this->assertSame(10_000, Deposito::ocupado($c->id, 'metal_bruto'));
        $this->assertSame(0, Deposito::livre($c->id, 'metal_bruto'));
    }

    #[Test]
    public function a_oferta_de_compra_reserva_o_espaco_que_vai_receber(): void
    {
        $c = $this->colonia();
        MarketAccount::create(['colony_id' => $c->id, 'resource_type' => 'metal_bruto', 'amount' => 9_000]);

        app(ColocarOrdem::class)->handle($c, 'buy', 'metal_bruto', 1_000, 1_000);

        $this->assertSame(10_000, Deposito::ocupado($c->id, 'metal_bruto'), 'a compra reservou o que vai chegar');

        // E a próxima não cabe mais: a execução do vendedor não pode falhar por culpa do comprador.
        $this->expectExceptionMessage('comporta 0 unidade(s) a mais');
        app(ColocarOrdem::class)->handle($c, 'buy', 'metal_bruto', 1, 1_000);
    }
}
