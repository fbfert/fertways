<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Guerra\Atacar;
use App\Domain\Guerra\ResolverCombates;
use App\Domain\Logistics\ConcluirTrechos;
use App\Domain\Logistics\DespacharVeiculo;
use App\Domain\Logistics\OcuparZonaNeutra;
use App\Domain\Zona\CobrarManutencaoTerritorial;
use App\Domain\Zona\ConcluirObrasDaZona;
use App\Domain\Zona\ConstruirNaZona;
use App\Domain\Zona\SubirNivelDaZona;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Combat;
use App\Models\NeutralZone;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\ZoneBuild;
use App\Models\ZoneEvent;
use App\Models\ZoneMaterial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Revisão de 2026-07-19 nas Zonas Neutras: o canteiro de obras (`zone_materials`) e o ciclo de
 * abandono/upgrade tinham quatro problemas reais (docs/decisoes.md D-122):
 *
 *  1. O abandono por manutenção não limpava o canteiro nem a fila de obras — uma obra em curso
 *     sobrevivia e erguia a estrutura para quem quer que reocupasse a zona, de graça (a mesma
 *     "lavagem de zona" que o D-84 já dizia estar impedindo).
 *  2. O canteiro não era saqueável — imune à guerra, ao contrário do Depósito de verdade. (O
 *     D-122 também tinha dado um teto de REJEIÇÃO à entrega, capacidadeDeposito(); quebrou uma
 *     zona real em produção que já tinha mais que isso acumulado, e foi corrigido no D-124: sem
 *     teto de entrega, só saqueável — o mesmo caminho que o D-66 já tinha escolhido para a
 *     extração.)
 *  3. O Depósito de Zona Neutra tinha `build_time_seconds` NULL nos 10 níveis: construí-lo pela
 *     zona era instantâneo, sem passar pela trava que o lado da colônia já tinha para esse caso.
 *  4. Um upgrade de nível já pago (Metal Bruto + Fert$) se perdia em silêncio se a manutenção
 *     vencesse no meio do prazo — sem estorno (o reset do D-84 é deliberado), mas também sem
 *     nenhum registro de que aconteceu.
 */
class RevisaoDoCanteiroTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\ComponentRecipeSeeder::class);
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
    }

    private int $proximoSlot = 0;

    private function colonoAbastecido(): Colony
    {
        $user = User::factory()->create();
        $colony = app(CreateColony::class)->handle($user, 'Base', 20 + $this->proximoSlot, 20 - $this->proximoSlot);
        $this->proximoSlot += 5;

        foreach ([
            'metal_bruto' => 50_000, 'ligas_metalicas' => 50_000, 'componentes_eletronicos' => 20_000,
            'compostos_quimicos' => 50_000, 'biomassa' => 10_000, 'energia' => 20_000,
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
        return app(OcuparZonaNeutra::class)->handle($colony, $this->zonaLivre($x, $y));
    }

    /** Zona direta, sem passar pela ocupação — para os testes de canteiro que não precisam dela. */
    private function zonaDe(Colony $dono, array $extra = []): NeutralZone
    {
        return NeutralZone::create(array_merge([
            'x' => 47, 'y' => 47, 'district' => 'NE', 'mineral' => 'metal_bruto', 'level' => 1,
            'owner_colony_id' => $dono->id,
            'status' => 'ocupada',
            'occupied_at' => now()->subDays(30),
            'protected_until' => now()->subDays(20),
            'command_post_level' => 1,
            'productive_at' => now()->subDays(20),
            'deposit_level' => 1,
            'deposit_amount' => 0,
            'last_extraction_at' => now(),
        ], $extra));
    }

    private function encherCanteiro(NeutralZone $zona, array $material): void
    {
        foreach ($material as $r => $q) {
            ZoneMaterial::create(['zone_id' => $zona->id, 'resource_type' => $r, 'amount' => $q]);
        }
    }

    // ── 1: abandono limpa canteiro e fila ──────────────────────────────────────────────────────

    public function test_abandono_limpa_o_canteiro_e_a_fila_e_nao_ergue_a_obra_fantasma(): void
    {
        $colono = $this->colonoAbastecido();
        $zona = $this->zonaOcupada($colono);

        $this->encherCanteiro($zona, ['metal_bruto' => 400, 'ligas_metalicas' => 100]);
        app(ConstruirNaZona::class)->handle($colono, $zona, 'muralha_de_perimetro');

        $this->assertTrue($zona->fresh()->obraEmCurso(), 'a obra tem de estar em curso pro teste valer algo');

        $zona->update([
            'maintenance_next_due_at' => now()->subMinute(),
            'maintenance_unpaid_since' => now()->subHours(73),
        ]);

        $resultado = app(CobrarManutencaoTerritorial::class)->handle();
        $this->assertSame(1, $resultado['abandonadas']);

        $zona->refresh();
        $this->assertNull($zona->owner_colony_id);
        $this->assertSame(0, ZoneMaterial::where('zone_id', $zona->id)->count(), 'o canteiro tem de esvaziar');
        $this->assertSame(0, ZoneBuild::where('zone_id', $zona->id)->count(), 'a fila de obras tem de esvaziar');

        // Reocupa com OUTRA colônia (o cenário de lavagem: outra conta reocupa e herdaria a obra).
        $outra = $this->colonoAbastecido();
        app(OcuparZonaNeutra::class)->handle($outra, $zona->fresh());

        // Passado o prazo que a Muralha levaria, a obra fantasma NÃO conclui — porque não existe mais.
        $this->travelTo(now()->addHours(5));
        app(ConcluirObrasDaZona::class)->handle();

        $this->assertSame(0, $zona->fresh()->wall_level, 'sem lavagem: quem reocupou não herda muralha nenhuma de graça');
    }

    // ── 4: upgrade perdido no abandono fica auditável ──────────────────────────────────────────

    public function test_upgrade_pendente_perdido_no_abandono_vira_zoneevent_com_o_custo(): void
    {
        $colono = $this->colonoAbastecido();
        $zona = $this->zonaOcupada($colono);

        app(SubirNivelDaZona::class)->handle($colono->fresh(), $zona->fresh());
        $this->assertSame(2, $zona->fresh()->level_target, 'o upgrade tem de estar em curso pro teste valer algo');

        $zona->fresh()->update([
            'maintenance_next_due_at' => now()->subMinute(),
            'maintenance_unpaid_since' => now()->subHours(73),
        ]);

        app(CobrarManutencaoTerritorial::class)->handle();

        $evento = ZoneEvent::where('zone_id', $zona->id)->where('type', 'upgrade_perdido_no_abandono')->first();
        $this->assertNotNull($evento, 'o upgrade perdido tem de deixar rastro');
        $this->assertSame(2, $evento->meta['nivel_alvo']);
        $this->assertSame(NeutralZone::custoDeUpgrade(2), $evento->meta['custo_perdido']);

        // Sem estorno: o reset do abandono é deliberado (D-84) — o registro é só pra não ser silencioso.
        $this->assertNull($zona->fresh()->level_target);
        $this->assertSame(1, $zona->fresh()->level);
    }

    public function test_abandono_sem_upgrade_pendente_nao_cria_o_evento(): void
    {
        $colono = $this->colonoAbastecido();
        $zona = $this->zonaOcupada($colono);

        $zona->update([
            'maintenance_next_due_at' => now()->subMinute(),
            'maintenance_unpaid_since' => now()->subHours(73),
        ]);

        app(CobrarManutencaoTerritorial::class)->handle();

        $this->assertSame(
            0,
            ZoneEvent::where('zone_id', $zona->id)->where('type', 'upgrade_perdido_no_abandono')->count(),
        );
    }

    // ── 2: o canteiro NÃO tem teto de rejeição (corrigido no D-124) e vira saqueável ───────────

    public function test_o_canteiro_nao_trava(): void
    {
        $colono = $this->colonoAbastecido();
        $zona = $this->zonaOcupada($colono);

        $furgao = $colono->vehicles()->where('type', 'furgao_de_comercio')->firstOrFail();
        app(DespacharVeiculo::class)->entregarMaterialNaZona($colono, $furgao, $zona, ['metal_bruto' => 300]);

        $this->travelTo(now()->addHours(2));
        app(ConcluirTrechos::class)->handle();

        $this->assertSame(300, (int) ZoneMaterial::where('zone_id', $zona->id)->where('resource_type', 'metal_bruto')->value('amount'));
        $this->assertNull($furgao->fresh()->cargo_json, 'nada sobrou — não há carga de volta');
    }

    /**
     * D-124: o D-122 tinha dado um teto de REJEIÇÃO ao canteiro (capacidadeDeposito()) — e uma
     * zona real de produção já tinha mais que isso acumulado de antes (herança de quando não
     * havia teto nenhum). `capacidade - ocupado` negativo travava QUALQUER entrega nova, sem
     * aviso. Corrigido seguindo o MESMO caminho que o D-66 já tinha escolhido para a extração:
     * sem teto de entrega — o risco é o saque, não uma porta fechada.
     */
    public function test_o_canteiro_aceita_alem_da_capacidade_do_deposito_sem_travar(): void
    {
        $colono = $this->colonoAbastecido();
        $zona = $this->zonaOcupada($colono);

        // deposit_level 1 => capacidade 500 (§19.6) — mas isso não limita mais o canteiro.
        $this->encherCanteiro($zona, ['metal_bruto' => 1350]);
        $this->assertSame(500, $zona->fresh()->capacidadeDeposito());

        $furgao = $colono->vehicles()->where('type', 'furgao_de_comercio')->firstOrFail();
        app(DespacharVeiculo::class)->entregarMaterialNaZona($colono, $furgao, $zona, ['metal_bruto' => 300]);

        $this->travelTo(now()->addHours(2));
        app(ConcluirTrechos::class)->handle();

        $this->assertSame(1650, (int) ZoneMaterial::where('zone_id', $zona->id)->where('resource_type', 'metal_bruto')->value('amount'), 'a entrega inteira foi aceita, mesmo já estando bem acima da capacidade do Depósito');
        $this->assertNull($furgao->fresh()->cargo_json, 'nada volta na carroceria — não há mais rejeição');
    }

    public function test_o_canteiro_e_saqueado_na_invasao_como_o_resto_do_estoque(): void
    {
        $atacante = $this->colonoAbastecido();
        $defensor = $this->colonoAbastecido();
        $zona = $this->zonaDe($defensor);

        $this->encherCanteiro($zona, ['ligas_metalicas' => 1000]);

        // Guarnição fraca — a invasão vence de fato (força bruta, determinística, D-66).
        for ($i = 0; $i < 5; $i++) {
            Unit::create(['zone_id' => $zona->id, 'type' => 'robo_minerador', 'level' => 1, 'hp_bps' => Unit::INTEIRA, 'status' => 'na_zona']);
        }

        $sentinelas = [];
        for ($i = 0; $i < 20; $i++) {
            $sentinelas[] = Unit::create([
                'colony_id' => $atacante->id, 'type' => 'sentinela',
                'level' => 5, 'hp_bps' => Unit::INTEIRA, 'status' => 'casa',
            ])->id;
        }

        $combate = app(Atacar::class)->handle($atacante, $zona->fresh(), 'invasao', $sentinelas);

        $motor = app(ResolverCombates::class);
        for ($h = 0; $h < 72 * 6; $h++) {
            $combate->refresh();
            if (! $combate->vivo()) {
                break;
            }
            $this->travelTo(now()->addMinutes(Combat::RODADA_MINUTOS));
            $motor->handle(now());
        }

        $this->assertSame('vitoria_atacante', $combate->fresh()->status);

        // 50% do canteiro exposto (Combat::SAQUE_BPS) — o canteiro nunca é "protegido", é sempre exposto.
        $ligasNoCanteiro = (int) ZoneMaterial::where('zone_id', $zona->id)->where('resource_type', 'ligas_metalicas')->value('amount');
        $this->assertSame(500, $ligasNoCanteiro, 'metade do canteiro foi saqueada');

        $ligasDoAtacante = (int) $atacante->fresh()->resources()->where('resource_type', 'ligas_metalicas')->value('amount');
        $this->assertGreaterThanOrEqual(500, $ligasDoAtacante, 'o atacante ganhou o que foi saqueado do canteiro');
    }

    // ── 3: Depósito de Zona Neutra tem tempo de construção real ───────────────────────────────

    public function test_deposito_de_zona_neutra_nao_e_mais_instantaneo(): void
    {
        $colono = $this->colonoAbastecido();
        $zona = $this->zonaOcupada($colono);

        $this->encherCanteiro($zona, ['ligas_metalicas' => 200, 'compostos_quimicos' => 60]);
        app(ConstruirNaZona::class)->handle($colono, $zona, 'deposito_de_zona_neutra');

        $spec = \Illuminate\Support\Facades\DB::table('building_specs')
            ->where('building_type', 'deposito_de_zona_neutra')->where('level', 2)->first();
        $this->assertNotNull($spec->build_time_seconds, 'o seeder tem de ter dado um tempo real');
        $this->assertGreaterThan(0, $spec->build_time_seconds);

        // Sem o tempo passar, a obra NÃO conclui — era exatamente isso que faltava.
        app(ConcluirObrasDaZona::class)->handle();
        $this->assertSame(1, $zona->fresh()->deposit_level, 'nao concluiu na hora — antes concluía');

        $this->travelTo(now()->addSeconds($spec->build_time_seconds + 60));
        app(ConcluirObrasDaZona::class)->handle();
        $this->assertSame(2, $zona->fresh()->deposit_level, 'passado o tempo de verdade, conclui');
    }

    // ── item 6 da revisão: ConstruirNaZona passa a obedecer building_specs_overrides ───────────

    public function test_o_ajuste_do_admin_em_building_specs_overrides_vale_para_estrutura_de_zona(): void
    {
        $colono = $this->colonoAbastecido();
        $zona = $this->zonaOcupada($colono);

        // Até o D-122 isto NÃO tinha efeito nenhum na zona — só na colônia.
        \Illuminate\Support\Facades\DB::table('building_specs_overrides')->insert([
            'building_type' => 'muralha_de_perimetro', 'level' => 1,
            'build_time_seconds' => 30, 'cost_json' => json_encode(['metal_bruto' => 1]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->encherCanteiro($zona, ['metal_bruto' => 1]);
        app(ConstruirNaZona::class)->handle($colono, $zona, 'muralha_de_perimetro');

        $this->assertSame(0, (int) ZoneMaterial::where('zone_id', $zona->id)->where('resource_type', 'metal_bruto')->value('amount'), 'debitou o custo do OVERRIDE (1), não o do GDD (400)');

        $this->travelTo(now()->addSeconds(31));
        app(ConcluirObrasDaZona::class)->handle();
        $this->assertSame(1, $zona->fresh()->wall_level, 'concluiu no tempo do OVERRIDE (30s), não nas 4h do GDD');
    }
}
