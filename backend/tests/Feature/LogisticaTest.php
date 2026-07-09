<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Logistics\ConcluirTrechos;
use App\Domain\Logistics\DespacharVeiculo;
use App\Domain\Logistics\VeiculoSpecs;
use App\Models\Colony;
use App\Models\Ledger;
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

class LogisticaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // O catálogo de recursos e as specs são pré-requisito: sem eles não há alíquota nem
        // linha de recurso para debitar.
        $this->seed(ResourceTypeSeeder::class);
        $this->seed(ComponentRecipeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
    }

    /** Duas colônias a uma distância conhecida: 30 slots, uma célula da tabela do §25.6. */
    private function duasColonias(): array
    {
        $a = $this->colonia('a@t.test', 'alfa', 10, 10);
        $b = $this->colonia('b@t.test', 'beta', 40, 10);

        return [$a, $b];
    }

    private function colonia(string $email, string $nick, int $x, int $y): Colony
    {
        $user = User::factory()->create(['email' => $email, 'nickname' => $nick]);
        $colony = app(CreateColony::class)->handle($user, "Colônia {$nick}");
        $colony->forceFill(['x' => $x, 'y' => $y])->save();

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

    #[Test]
    public function despacho_debita_carga_e_energia_da_origem_no_ato(): void
    {
        [$a, $b] = $this->duasColonias();
        $this->abastecer($a, ['metal_bruto' => 1_000, 'energia' => 100]);

        app(DespacharVeiculo::class)->handle($a, $this->furgao($a), 'colonia', $b->id, ['metal_bruto' => 400]);

        // 30 slots de furgão: 7,5 kWh por trecho, 15 na viagem — exato, sem arredondar.
        $this->assertSame(15, VeiculoSpecs::energiaDaViagem('furgao_de_comercio', 30));
        $this->assertSame(600, (int) $a->resources()->where('resource_type', 'metal_bruto')->value('amount'));
        $this->assertSame(85, (int) $a->resources()->where('resource_type', 'energia')->value('amount'));

        // A carga ainda não chegou: o destino não recebeu nada.
        $this->assertSame(0, (int) $b->resources()->where('resource_type', 'metal_bruto')->value('amount'));
    }

    #[Test]
    public function veiculo_em_rota_nao_aceita_novo_despacho(): void
    {
        [$a, $b] = $this->duasColonias();
        $this->abastecer($a, ['metal_bruto' => 1_000, 'energia' => 100]);
        $despachar = app(DespacharVeiculo::class);

        $despachar->handle($a, $this->furgao($a), 'colonia', $b->id, ['metal_bruto' => 100]);

        // §25.5: "Um colono com apenas 1 veículo não pode realizar duas entregas ao mesmo tempo."
        $this->expectExceptionMessage('O veículo está em rota.');
        $despachar->handle($a, $this->furgao($a)->fresh(), 'colonia', $b->id, ['metal_bruto' => 100]);
    }

    #[Test]
    public function carga_acima_da_capacidade_e_recusada(): void
    {
        [$a, $b] = $this->duasColonias();
        $this->abastecer($a, ['metal_bruto' => 10_000, 'energia' => 100]);

        // Furgão: 6 m³ = 6.000 unidades (§25.4).
        $this->expectExceptionMessage('excede a capacidade');
        app(DespacharVeiculo::class)->handle($a, $this->furgao($a), 'colonia', $b->id, ['metal_bruto' => 6_001]);
    }

    #[Test]
    public function sem_energia_o_veiculo_nao_parte(): void
    {
        [$a, $b] = $this->duasColonias();
        $this->abastecer($a, ['metal_bruto' => 1_000, 'energia' => 3]);

        $this->expectExceptionMessage('Falta energia');
        app(DespacharVeiculo::class)->handle($a, $this->furgao($a), 'colonia', $b->id, ['metal_bruto' => 100]);
    }

    #[Test]
    public function entrega_credita_o_destino_e_cobra_o_tributo_na_chegada(): void
    {
        [$a, $b] = $this->duasColonias();
        $this->abastecer($a, ['metal_bruto' => 1_000, 'energia' => 100]);

        app(DespacharVeiculo::class)->handle($a, $this->furgao($a), 'colonia', $b->id, ['metal_bruto' => 1_000]);

        // 30 slots / 4 slots por min = 7,5 min de ida.
        Carbon::setTestNow(now()->addMinutes(8));
        app(ConcluirTrechos::class)->handle();

        // Metal Bruto é primário: alíquota 3% (§8.3). 1.000 -> tributo 30, líquido 970.
        $this->assertSame(970, (int) $b->resources()->where('resource_type', 'metal_bruto')->value('amount'));

        $tributo = DB::table('tax_events')->where('colony_id', $a->id)->first();
        $this->assertSame('transporte_entrega', $tributo->kind);
        $this->assertSame(1_000, (int) $tributo->base_amount);
        $this->assertSame(300, (int) $tributo->tax_bps);
        $this->assertSame(30, (int) $tributo->tax_amount);

        // O veículo entregou, mas ainda está voltando: indisponível (§25.5).
        $this->assertSame('em_rota', $this->furgao($a)->fresh()->status);
        $this->assertSame('volta', $this->furgao($a)->fresh()->leg);

        Carbon::setTestNow();
    }

    #[Test]
    public function o_veiculo_so_fica_ocioso_ao_terminar_a_volta(): void
    {
        [$a, $b] = $this->duasColonias();
        $this->abastecer($a, ['metal_bruto' => 1_000, 'energia' => 100]);

        app(DespacharVeiculo::class)->handle($a, $this->furgao($a), 'colonia', $b->id, ['metal_bruto' => 100]);

        // Ida (7,5 min) + volta (7,5 min) = 15 min.
        Carbon::setTestNow(now()->addMinutes(16));
        app(ConcluirTrechos::class)->handle(); // fecha a ida
        app(ConcluirTrechos::class)->handle(); // fecha a volta

        $v = $this->furgao($a)->fresh();
        $this->assertSame('ocioso', $v->status);
        $this->assertNull($v->leg);
        $this->assertNull($v->cargo_json);
        $this->assertNull($v->arrives_at);

        Carbon::setTestNow();
    }

    #[Test]
    public function tick_repetido_nao_tributa_nem_credita_duas_vezes(): void
    {
        [$a, $b] = $this->duasColonias();
        $this->abastecer($a, ['metal_bruto' => 1_000, 'energia' => 100]);

        app(DespacharVeiculo::class)->handle($a, $this->furgao($a), 'colonia', $b->id, ['metal_bruto' => 1_000]);

        Carbon::setTestNow(now()->addMinutes(8));
        app(ConcluirTrechos::class)->handle();

        // Força o mesmo trecho de ida a "vencer" outra vez: é o cenário de um retry do cron
        // ou de dois crons concorrentes. A chave única de tax_events tem de segurar.
        $v = $this->furgao($a)->fresh();
        $v->forceFill(['leg' => 'ida', 'cargo_json' => ['metal_bruto' => 1_000]])->save();
        app(ConcluirTrechos::class)->handle();

        $this->assertSame(970, (int) $b->resources()->where('resource_type', 'metal_bruto')->value('amount'));
        $this->assertSame(1, DB::table('tax_events')->count());
        $this->assertSame(1, Ledger::where('type', 'tributo')->count());

        Carbon::setTestNow();
    }

    #[Test]
    public function mercado_central_ainda_nao_aceita_deposito(): void
    {
        [$a] = $this->duasColonias();
        $this->abastecer($a, ['metal_bruto' => 1_000, 'energia' => 100]);

        $this->expectExceptionMessage('Mercado Central ainda não está implementado');
        app(DespacharVeiculo::class)->handle($a, $this->furgao($a), 'mercado_central', null, ['metal_bruto' => 100]);
    }

    #[Test]
    public function toda_colonia_nasce_com_coordenada_unica_fora_da_capital(): void
    {
        $c = $this->colonia('c@t.test', 'gama', 7, 7);
        $this->assertNotNull($c->x);
        $this->assertNotNull($c->y);

        $novo = User::factory()->create(['email' => 'd@t.test', 'nickname' => 'delta']);
        $outra = app(CreateColony::class)->handle($novo, 'Colônia delta');

        $this->assertFalse($outra->x === 50 && $outra->y === 50, 'ninguém funda sobre a Capital');
        $this->assertFalse($outra->x === $c->x && $outra->y === $c->y, 'coordenada colidiu');
    }
}
