<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Logistics\ConcluirTrechos;
use App\Domain\Logistics\DespacharVeiculo;
use App\Models\Colony;
use App\Models\Federation;
use App\Models\FederationSetting;
use App\Models\MarketAccount;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * O desconto de tributo entre aliados (§04/§07; docs/decisoes.md D-114, D-120) — "50% de desconto
 * nos tributos entre aliadas" (v3.0), a última ponta que o D-114 tinha deixado de fora de propósito
 * ("a contribuição ao fundo é tributada NORMALMENTE... deixando o terreno pronto para o desconto
 * entrar depois").
 *
 * Só vale no COMÉRCIO entre DUAS colônias diferentes da mesma federação — não na contribuição de
 * uma colônia ao próprio fundo (decisão do usuário: ver D-120), nem em qualquer entrega que seja a
 * colônia recebendo o próprio lote (frete público, retirada do Mercado).
 */
class DescontoDeTributoEntreAliadosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\ComponentRecipeSeeder::class);
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
    }

    private function colonia(string $email, string $nick, int $x, int $y, ?Federation $fed = null): Colony
    {
        $user = User::factory()->create(['email' => $email, 'nickname' => $nick]);
        $colony = app(CreateColony::class)->handle($user, "Colônia {$nick}", $x, $y);
        $colony->resources()->update(['amount' => 0]);

        if ($fed) {
            $colony->update(['federation_id' => $fed->id, 'federation_role' => Federation::MEMBRO]);
        }

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

    public function test_entrega_entre_aliados_paga_metade_do_tributo(): void
    {
        $fed = Federation::create(['name' => 'Aliança']);
        $a = $this->colonia('a@t.test', 'alfa', 10, 10, $fed);
        $b = $this->colonia('b@t.test', 'beta', 40, 10, $fed);
        $this->abastecer($a, ['metal_bruto' => 1_000, 'energia' => 100]);

        app(DespacharVeiculo::class)->handle($a, $this->furgao($a), 'colonia', $b->id, ['metal_bruto' => 1_000]);

        Carbon::setTestNow(now()->addMinutes(8));
        app(ConcluirTrechos::class)->handle();
        Carbon::setTestNow();

        // Metal Bruto: 3% cheio (300 bps), 50% de desconto entre aliados = 150 bps. 1.000 -> 15, não 30.
        $this->assertSame(985, (int) $b->resources()->where('resource_type', 'metal_bruto')->value('amount'));

        $tributo = DB::table('tax_events')->where('colony_id', $a->id)->first();
        $this->assertSame(150, (int) $tributo->tax_bps);
        $this->assertSame(15, (int) $tributo->tax_amount);
    }

    public function test_entrega_entre_federacoes_diferentes_paga_cheio(): void
    {
        $fedA = Federation::create(['name' => 'Aliança']);
        $fedB = Federation::create(['name' => 'Outra']);
        $a = $this->colonia('a@t.test', 'alfa', 10, 10, $fedA);
        $b = $this->colonia('b@t.test', 'beta', 40, 10, $fedB);
        $this->abastecer($a, ['metal_bruto' => 1_000, 'energia' => 100]);

        app(DespacharVeiculo::class)->handle($a, $this->furgao($a), 'colonia', $b->id, ['metal_bruto' => 1_000]);

        Carbon::setTestNow(now()->addMinutes(8));
        app(ConcluirTrechos::class)->handle();
        Carbon::setTestNow();

        $this->assertSame(970, (int) $b->resources()->where('resource_type', 'metal_bruto')->value('amount'));
    }

    /** A retirada do Mercado (§25.8): a colônia recebe o PRÓPRIO lote — não é comércio com ninguém. */
    public function test_retirada_do_mercado_nao_ganha_desconto_mesmo_sendo_federada(): void
    {
        $fed = Federation::create(['name' => 'Aliança']);
        $c = $this->colonia('c@t.test', 'gama', 30, 0, $fed);
        $this->abastecer($c, ['energia' => 100]);
        MarketAccount::create(['colony_id' => $c->id, 'resource_type' => 'metal_bruto', 'amount' => 1_000]);

        app(DespacharVeiculo::class)->retirar($c, $this->furgao($c), ['metal_bruto' => 1_000]);

        Carbon::setTestNow(now()->addMinutes(8));
        app(ConcluirTrechos::class)->handle();   // ida: carrega, não tributa
        Carbon::setTestNow(now()->addMinutes(16));
        app(ConcluirTrechos::class)->handle();   // volta: tributa
        Carbon::setTestNow();

        // 3% cheio, sem desconto: 1.000 -> 30, líquido 970. A colônia é federada, mas está
        // retirando de SI MESMA — `origem === destino`, o guard exclui de propósito.
        $tributo = DB::table('tax_events')->first();
        $this->assertSame(300, (int) $tributo->tax_bps);
        $this->assertSame(30, (int) $tributo->tax_amount);
    }

    public function test_o_painel_ajusta_o_desconto(): void
    {
        FederationSetting::singleton()->update(['desconto_tributo_aliados_bps' => 10_000]); // 100%: isento

        $fed = Federation::create(['name' => 'Aliança']);
        $a = $this->colonia('a@t.test', 'alfa', 10, 10, $fed);
        $b = $this->colonia('b@t.test', 'beta', 40, 10, $fed);
        $this->abastecer($a, ['metal_bruto' => 1_000, 'energia' => 100]);

        app(DespacharVeiculo::class)->handle($a, $this->furgao($a), 'colonia', $b->id, ['metal_bruto' => 1_000]);

        Carbon::setTestNow(now()->addMinutes(8));
        app(ConcluirTrechos::class)->handle();
        Carbon::setTestNow();

        // 100% de desconto: tributo zero, o líquido chega inteiro.
        $this->assertSame(1_000, (int) $b->resources()->where('resource_type', 'metal_bruto')->value('amount'));
    }
}
