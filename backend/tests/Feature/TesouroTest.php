<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Treasury\Tesouro;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\TreasuryHolding;
use App\Models\User;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Database\Seeders\TreasurySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Ministério do Tesouro (D-57): a dotação, o crédito pelo tributo, a distribuição do admin.
 *
 * O kit fixo de recursos por colônia que o D-57 também criava morreu no D-85 — substituído pelo
 * kit inicial único de `Domain\Colony\KitInicial`, coberto em `ColonyCreationTest`.
 */
class TesouroTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
        $this->seed(ComponentRecipeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
    }

    private function colonia(string $nick): Colony
    {
        $user = User::factory()->create(['email' => "{$nick}@t.test", 'nickname' => $nick]);

        return app(CreateColony::class)->handle($user, "Colônia {$nick}", 10, 10)->fresh();
    }

    private function estoque(Colony $c, string $recurso): int
    {
        return (int) $c->resources()->where('resource_type', $recurso)->value('amount');
    }

    // ── Dotação ──────────────────────────────────────────────────────────────

    #[Test]
    public function a_dotacao_inicial_e_10_mil_de_cada_e_um_milhao_de_fert(): void
    {
        $this->seed(TreasurySeeder::class);

        $this->assertSame(10_000, (int) TreasuryHolding::whereKey('metal_bruto')->value('amount'));
        $this->assertSame(1_000_000 * Colony::MICRO_POR_FERT, app(Tesouro::class)->saldoFertMicro());
        // Uma linha por recurso do catálogo, mais a de Fert$.
        $this->assertSame(\App\Models\ResourceType::count() + 1, TreasuryHolding::count());
    }

    #[Test]
    public function a_dotacao_e_idempotente(): void
    {
        $this->seed(TreasurySeeder::class);
        app(Tesouro::class)->creditarRecurso('metal_bruto', 500); // o tributo já mexeu
        $this->seed(TreasurySeeder::class); // não deve zerar nem somar

        $this->assertSame(10_500, (int) TreasuryHolding::whereKey('metal_bruto')->value('amount'));
    }

    // ── Crédito pelo tributo ─────────────────────────────────────────────────

    #[Test]
    public function o_tributo_credita_recurso_e_fert(): void
    {
        app(Tesouro::class)->creditarRecurso('agua', 30);
        app(Tesouro::class)->creditarFert(150_000);

        $this->assertSame(30, (int) TreasuryHolding::whereKey('agua')->value('amount'));
        $this->assertSame(150_000, app(Tesouro::class)->saldoFertMicro());
    }

    // ── Distribuição do admin ────────────────────────────────────────────────

    #[Test]
    public function o_tesouro_distribui_recurso_a_um_colono(): void
    {
        $this->seed(TreasurySeeder::class);
        $c = $this->colonia('alfa');
        $antes = $this->estoque($c, 'metal_bruto');

        app(Tesouro::class)->distribuir($c, 'metal_bruto', 500);

        $this->assertSame(9_500, (int) TreasuryHolding::whereKey('metal_bruto')->value('amount'));
        $this->assertSame($antes + 500, $this->estoque($c->fresh(), 'metal_bruto'));
        $this->assertDatabaseHas('ledger', ['colony_id' => $c->id, 'type' => 'transferencia_tesouro', 'resource_type' => 'metal_bruto']);
    }

    #[Test]
    public function o_tesouro_distribui_fert_a_um_colono(): void
    {
        $this->seed(TreasurySeeder::class);
        $c = $this->colonia('beta');
        $antes = (int) DB::table('colonies')->where('id', $c->id)->value('fert_micro');

        app(Tesouro::class)->distribuir($c, Tesouro::FERT, 5 * Colony::MICRO_POR_FERT);

        $this->assertSame($antes + 5 * Colony::MICRO_POR_FERT, (int) DB::table('colonies')->where('id', $c->id)->value('fert_micro'));
        $this->assertSame(1_000_000 * Colony::MICRO_POR_FERT - 5 * Colony::MICRO_POR_FERT, app(Tesouro::class)->saldoFertMicro());
    }

    #[Test]
    public function o_tesouro_nao_distribui_alem_do_saldo(): void
    {
        $this->seed(TreasurySeeder::class);
        $c = $this->colonia('gama');

        $this->expectException(DomainRuleException::class);
        $this->expectExceptionMessage('não tem esse saldo');
        app(Tesouro::class)->distribuir($c, 'metal_bruto', 20_000); // só há 10 mil
    }
}
