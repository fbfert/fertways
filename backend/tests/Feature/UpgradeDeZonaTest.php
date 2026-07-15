<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Guerra\Forcas;
use App\Domain\Logistics\OcuparZonaNeutra;
use App\Domain\Zona\CobrarManutencaoTerritorial;
use App\Domain\Zona\ConcluirUpgradeDaZona;
use App\Domain\Zona\SubirNivelDaZona;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\NeutralZone;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teto de zonas, upgrade de nível e manutenção territorial (GDD §07/§27.12; D-84).
 */
class UpgradeDeZonaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\ComponentRecipeSeeder::class);
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
    }

    private function colonoAbastecido(): Colony
    {
        $user = User::factory()->create();
        $colony = app(CreateColony::class)->handle($user, 'Base', 20, 20);

        foreach ([
            'metal_bruto' => 50_000, 'ligas_metalicas' => 50_000, 'componentes_eletronicos' => 20_000,
            'biomassa' => 10_000, 'energia' => 10_000,
        ] as $r => $q) {
            $colony->resources()->where('resource_type', $r)->update(['amount' => $q]);
        }
        $colony->update(['fert_micro' => 100_000 * 1_000_000]);
        $colony->forceFill(['xp' => 20_000])->save();

        return $colony->fresh();
    }

    private function zonaLivre(int $x, int $y): NeutralZone
    {
        return NeutralZone::create([
            'x' => $x, 'y' => $y, 'district' => 'nordeste', 'mineral' => 'metal_bruto',
            'level' => 1, 'status' => 'livre', 'deposit_level' => 1,
        ]);
    }

    private function zonaOcupada(Colony $colony, int $x = 50, int $y = 50): NeutralZone
    {
        $zona = $this->zonaLivre($x, $y);

        return app(OcuparZonaNeutra::class)->handle($colony, $zona);
    }

    // ---------------------------------------------------------------- teto de zonas

    public function test_teto_de_cinco_zonas_por_colonia(): void
    {
        $colony = $this->colonoAbastecido();

        for ($i = 0; $i < 5; $i++) {
            $this->zonaOcupada($colony, 40 + $i, 40);
        }

        $this->assertSame(5, NeutralZone::where('owner_colony_id', $colony->id)->count());

        $sexta = $this->zonaLivre(60, 60);
        $this->expectException(DomainRuleException::class);
        app(OcuparZonaNeutra::class)->handle($colony->fresh(), $sexta);
    }

    // ---------------------------------------------------------------- upgrade de nível

    public function test_upgrade_cobra_custo_e_guarnicao_e_arma_o_relogio(): void
    {
        $colony = $this->colonoAbastecido();
        $zona = $this->zonaOcupada($colony);

        $antesMetal = $colony->fresh()->resources()->where('resource_type', 'metal_bruto')->value('amount');
        $antesFert = (int) $colony->fresh()->fert_micro;

        $zona = app(SubirNivelDaZona::class)->handle($colony->fresh(), $zona);

        $this->assertSame(2, $zona->level_target);
        $this->assertNotNull($zona->level_upgrade_finishes_at);
        $this->assertSame(1, $zona->level, 'o nível só sobe no tick');

        // Custo do nível 2: round(800×1.65)=1320 MB, round(300×1.65)=495 F$.
        // Guarnição-alvo do nível 2: round(20×1.65)=33 — faltam 13 Robôs (11 MB, 60 ligas, 20 comp cada).
        $depoisMetal = $colony->fresh()->resources()->where('resource_type', 'metal_bruto')->value('amount');
        $this->assertSame($antesMetal - 1320 - 13 * 11, $depoisMetal);
        $this->assertSame($antesFert - 495 * 1_000_000, (int) $colony->fresh()->fert_micro);
        $this->assertSame(33, $zona->guarnicao());

        $this->assertTrue(
            Ledger::where('type', 'custo_upgrade_zona')->where('ref', "zona:{$zona->id}:nivel:2")->exists(),
        );
    }

    public function test_tick_conclui_o_upgrade_e_a_extracao_sobe(): void
    {
        $colony = $this->colonoAbastecido();
        $zona = $this->zonaOcupada($colony);
        $zona = app(SubirNivelDaZona::class)->handle($colony->fresh(), $zona);

        $feitos = app(ConcluirUpgradeDaZona::class)->handle($zona->level_upgrade_finishes_at->addMinute());

        $this->assertSame(1, $feitos);
        $zona->refresh();
        $this->assertSame(2, $zona->level);
        $this->assertNull($zona->level_target);
        $this->assertSame(150, $zona->extracaoPorHora(), 'round(100×1.5^1) = 150');
    }

    public function test_nao_upa_duas_vezes_ao_mesmo_tempo_nem_alem_do_nivel_maximo(): void
    {
        $colony = $this->colonoAbastecido();
        $zona = $this->zonaOcupada($colony);
        app(SubirNivelDaZona::class)->handle($colony->fresh(), $zona);

        $this->expectException(DomainRuleException::class);
        app(SubirNivelDaZona::class)->handle($colony->fresh(), $zona->fresh());
    }

    public function test_endpoint_upgrade(): void
    {
        $colony = $this->colonoAbastecido();
        $zona = $this->zonaOcupada($colony);

        $this->actingAs($colony->user)
            ->postJson("/zones/{$zona->id}/upgrade")
            ->assertCreated()
            ->assertJson(['level' => 1, 'level_target' => 2]);
    }

    // ---------------------------------------------------------------- manutenção territorial

    public function test_manutencao_cobra_e_avanca_o_vencimento(): void
    {
        $colony = $this->colonoAbastecido();
        $zona = $this->zonaOcupada($colony);
        $zona->update(['maintenance_next_due_at' => now()->subMinute()]);

        $antes = $colony->fresh()->resources()->where('resource_type', 'biomassa')->value('amount');

        $resultado = app(CobrarManutencaoTerritorial::class)->handle();

        $this->assertSame(1, $resultado['cobradas']);
        $this->assertSame(0, $resultado['abandonadas']);

        $depois = $colony->fresh()->resources()->where('resource_type', 'biomassa')->value('amount');
        $this->assertSame($antes - 50, $depois, 'nível 1 custa 50 Biomassa/dia');

        $zona->refresh();
        $this->assertNull($zona->maintenance_unpaid_since);
        $this->assertTrue($zona->maintenance_next_due_at->greaterThan(now()->addHours(23)));

        $this->assertTrue(Ledger::where('type', 'manutencao_territorial')->where('ref', "zona:{$zona->id}:manutencao")->exists());
    }

    public function test_manutencao_nao_paga_marca_inadimplencia_sem_derrubar_a_zona(): void
    {
        $colony = $this->colonoAbastecido();
        $zona = $this->zonaOcupada($colony);
        $colony->resources()->whereIn('resource_type', ['biomassa', 'energia'])->update(['amount' => 0]);

        $vencimento = now()->subMinute();
        $zona->update(['maintenance_next_due_at' => $vencimento]);

        $resultado = app(CobrarManutencaoTerritorial::class)->handle();

        $this->assertSame(0, $resultado['cobradas']);
        $this->assertSame(0, $resultado['abandonadas']);

        $zona->refresh();
        $this->assertNotNull($zona->maintenance_unpaid_since);
        $this->assertSame($vencimento->format('Y-m-d H:i:s'), $zona->maintenance_unpaid_since->format('Y-m-d H:i:s'));
        $this->assertSame($colony->id, $zona->owner_colony_id, 'ainda não abandonou — só 1 dia de atraso');
    }

    public function test_penalidade_de_manutencao_e_zero_na_carencia_e_cresce_5_por_dia(): void
    {
        $zona = new NeutralZone(['level' => 1]);

        $zona->maintenance_unpaid_since = now()->subHours(10);
        $this->assertSame(0, $zona->penalidadeManutencaoBps(), 'dentro da carência de 24h');

        $zona->maintenance_unpaid_since = now()->subHours(25);
        $this->assertSame(500, $zona->penalidadeManutencaoBps(), '5% no primeiro dia de atraso');

        $zona->maintenance_unpaid_since = now()->subHours(49);
        $this->assertSame(1000, $zona->penalidadeManutencaoBps(), '10% no segundo dia');
    }

    public function test_abandona_automaticamente_apos_72h_sem_pagar(): void
    {
        $colony = $this->colonoAbastecido();
        $zona = $this->zonaOcupada($colony);
        $colony->resources()->whereIn('resource_type', ['biomassa', 'energia'])->update(['amount' => 0]);

        $zona->update([
            'maintenance_next_due_at' => now()->subMinute(),
            'maintenance_unpaid_since' => now()->subHours(73),
        ]);

        $resultado = app(CobrarManutencaoTerritorial::class)->handle();

        $this->assertSame(1, $resultado['abandonadas']);

        $zona->refresh();
        $this->assertNull($zona->owner_colony_id);
        $this->assertSame('livre', $zona->status);
        $this->assertSame(1, $zona->level);
        $this->assertSame(0, $zona->command_post_level);
        $this->assertSame(0, Unit::where('zone_id', $zona->id)->count());
    }

    public function test_manutencao_em_atraso_reduz_a_forca_defensiva(): void
    {
        $colony = $this->colonoAbastecido();
        $zona = $this->zonaOcupada($colony);

        $cheia = app(Forcas::class)->defensiva($zona->fresh());

        $zona->update(['maintenance_unpaid_since' => now()->subHours(25)]);
        $comAtraso = app(Forcas::class)->defensiva($zona->fresh());

        // 5% a menos na base, antes do bônus de construção (que aqui é zero — sem muralha/torre/bastião).
        $this->assertSame(intdiv($cheia * 9500, 10_000), $comAtraso);
        $this->assertLessThan($cheia, $comAtraso);
    }
}
