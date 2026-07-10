<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Logistics\ExtrairZonasNeutras;
use App\Models\Colony;
use App\Models\NeutralZone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A extração das zonas neutras no tick (§07, §24.4; D-52): 100/h para o Depósito, sem perder fração
 * em ticks de um minuto, parando quando o Depósito lota.
 */
class ExtrairZonasNeutrasTest extends TestCase
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
        return app(CreateColony::class)->handle(User::factory()->create(), 'Dona', 20, 20);
    }

    private function zonaProdutivaDesde($productiveAt, int $depositLevel = 1): NeutralZone
    {
        return NeutralZone::create([
            'x' => 50, 'y' => 50, 'district' => 'nordeste', 'mineral' => 'metal_bruto',
            'level' => 1, 'status' => 'protegida',
            'owner_colony_id' => $this->colonia()->id,
            'command_post_level' => 1, 'garrison' => 20,
            'productive_at' => $productiveAt, 'last_extraction_at' => $productiveAt,
            'deposit_level' => $depositLevel, 'deposit_amount' => 0,
        ]);
    }

    public function test_extrai_100_por_hora(): void
    {
        $agora = now();
        $zona = $this->zonaProdutivaDesde($agora->copy()->subHour());

        $creditadas = app(ExtrairZonasNeutras::class)->handle($agora);

        $this->assertSame(1, $creditadas);
        $this->assertSame(100, $zona->fresh()->deposit_amount);
    }

    public function test_zona_nao_produtiva_nao_extrai(): void
    {
        $agora = now();
        // Ocupada, mas ainda estabelecendo: productive_at no futuro.
        $zona = $this->zonaProdutivaDesde($agora->copy()->addHours(5));

        app(ExtrairZonasNeutras::class)->handle($agora);

        $this->assertSame(0, $zona->fresh()->deposit_amount);
    }

    public function test_nao_perde_fracao_em_ticks_de_um_minuto(): void
    {
        $inicio = now();
        $zona = $this->zonaProdutivaDesde($inicio);

        // Dez ticks de um minuto. 100/h × 10 min = 16,67 -> 16 unidades inteiras, sem perder o resto
        // por truncar cada minuto (que daria só 10).
        for ($i = 1; $i <= 10; $i++) {
            app(ExtrairZonasNeutras::class)->handle($inicio->copy()->addMinutes($i));
        }

        $this->assertSame(16, $zona->fresh()->deposit_amount);
    }

    public function test_para_quando_o_deposito_lota(): void
    {
        $agora = now();
        // Produtiva há 10 h: 1000 unidades caberiam, mas o Depósito nível 1 tem cap 500.
        $zona = $this->zonaProdutivaDesde($agora->copy()->subHours(10));

        app(ExtrairZonasNeutras::class)->handle($agora);

        $zona->refresh();
        $this->assertSame(500, $zona->deposit_amount);       // travou no cap (§07)
        // Relógio parado em agora (ao segundo: a coluna timestamp não guarda micros).
        $this->assertSame($agora->timestamp, $zona->last_extraction_at->timestamp);
    }

    public function test_extrai_pelo_tick(): void
    {
        $agora = now();
        $zona = $this->zonaProdutivaDesde($agora->copy()->subHour());

        $this->artisan('fertways:tick')->assertSuccessful();

        $this->assertGreaterThanOrEqual(100, $zona->fresh()->deposit_amount);
    }
}
