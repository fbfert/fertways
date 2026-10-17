<?php

namespace Tests\Feature;

use App\Domain\Building\Demolir;
use App\Domain\Colony\CreateColony;
use App\Domain\Espacoporto\Espacoporto;
use App\Domain\Espacoporto\FecharContratoDeRota;
use App\Domain\Marco\ConcederXp;
use App\Domain\Marco\Curva;
use App\Domain\Production\ColonyTick;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\PlanetaNpc;
use App\Models\User;
use App\Models\XpEntry;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\EspacoportoPartidaSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Database\Seeders\TechnologySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A revisão da economia (D-283): as três torneiras que a auditoria mediu.
 *
 * 1. Os planetas compravam sem limite: 10.000 Componentes Eletrônicos rendiam ~8.900 F$ numa viagem a
 *    Auryn, contra os ~360 F$/dia que o D-263 estimou para o servidor inteiro.
 * 2. Uma colônia ocupava os dois Cargueiros do Governo sozinha.
 * 3. Demolir e reerguer a mesma Mina rendia 100 XP a cada 14 minutos, sem teto.
 */
class RevisaoEconomiaTest extends TestCase
{
    use RefreshDatabase;

    private int $proximo = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
        $this->seed(ComponentRecipeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
        $this->seed(TechnologySeeder::class);
        $this->seed(EspacoportoPartidaSeeder::class);
    }

    private function colonia(): Colony
    {
        $c = app(CreateColony::class)->handle(User::factory()->create(), 'C', 20 + $this->proximo++ * 3, 20);
        $c->forceFill([
            'xp' => Curva::xpDoMarco(40),
            'fert_micro' => 100_000 * Colony::MICRO_POR_FERT,
            'created_at' => now()->subDays(30)->subMinute(),
        ])->save();
        $c->resources()->updateOrCreate(['resource_type' => 'componentes_eletronicos'], ['amount' => 20_000]);
        $c->resources()->updateOrCreate(['resource_type' => 'biomassa'], ['amount' => 20_000]);

        return $c->fresh();
    }

    private function planeta(string $chave): PlanetaNpc
    {
        return PlanetaNpc::where('chave', $chave)->firstOrFail();
    }

    // ── 1. a demanda do planeta é finita ────────────────────────────────────

    public function test_o_planeta_compra_ate_a_demanda_do_dia(): void
    {
        $c = $this->colonia();
        // Auryn paga 0,894 F$ por componente: 10.000 seriam 8.944 F$. A demanda de partida é 100 F$/dia.
        $preco = (int) $this->planeta('auryn')->precos()->where('resource_type', 'componentes_eletronicos')->value('compra_micro');

        try {
            app(FecharContratoDeRota::class)->handle($c, $this->planeta('auryn'), ['componentes_eletronicos' => 10_000], []);
            $this->fail('Auryn comprou 8.944 F$ num dia.');
        } catch (DomainRuleException $e) {
            $this->assertSame('demanda_do_planeta', $e->codigo);
        }

        // O que cabe na demanda passa.
        $cabem = intdiv(100 * Colony::MICRO_POR_FERT, $preco);
        app(FecharContratoDeRota::class)->handle($c, $this->planeta('auryn'), ['componentes_eletronicos' => $cabem], []);
    }

    public function test_a_demanda_e_do_servidor_e_renova_no_dia_seguinte(): void
    {
        $a = $this->colonia();
        $b = $this->colonia();
        $preco = (int) $this->planeta('auryn')->precos()->where('resource_type', 'componentes_eletronicos')->value('compra_micro');
        $cabem = intdiv(100 * Colony::MICRO_POR_FERT, $preco);

        app(FecharContratoDeRota::class)->handle($a, $this->planeta('auryn'), ['componentes_eletronicos' => $cabem], []);

        try {
            app(FecharContratoDeRota::class)->handle($b, $this->planeta('auryn'), ['componentes_eletronicos' => 10], []);
            $this->fail('A demanda de Auryn passou do dia.');
        } catch (DomainRuleException $e) {
            $this->assertSame('demanda_do_planeta', $e->codigo);
        }

        // O dia de missão vira às 07h: a demanda volta.
        $this->travel(1)->days();
        app(FecharContratoDeRota::class)->handle($b, $this->planeta('auryn'), ['componentes_eletronicos' => 10], []);
        $this->assertSame(2, DB::table('contratos_de_rota')->count());
    }

    public function test_sem_demanda_definida_o_planeta_compra_a_vontade(): void
    {
        $c = $this->colonia();
        $this->planeta('auryn')->forceFill(['demanda_diaria_micro' => null])->save();

        app(FecharContratoDeRota::class)->handle($c, $this->planeta('auryn'), ['componentes_eletronicos' => 10_000], []);
        $this->assertSame(1, DB::table('contratos_de_rota')->count());
    }

    public function test_so_encomendar_nao_gasta_demanda(): void
    {
        $c = $this->colonia();

        app(FecharContratoDeRota::class)->handle($c, $this->planeta('auryn'), [], ['silicio' => 100]);
        $this->assertSame(100 * Colony::MICRO_POR_FERT, app(Espacoporto::class)->demandaRestante($this->planeta('auryn')));
    }

    // ── 2. um Cargueiro por colônia ─────────────────────────────────────────

    public function test_uma_colonia_tem_um_cargueiro_no_ar_por_vez(): void
    {
        $c = $this->colonia();
        app(FecharContratoDeRota::class)->handle($c, $this->planeta('kalidor'), ['biomassa' => 100], []);

        try {
            app(FecharContratoDeRota::class)->handle($c->fresh(), $this->planeta('kalidor'), ['biomassa' => 100], []);
            $this->fail('Uma colônia ocupou os dois Cargueiros.');
        } catch (DomainRuleException $e) {
            $this->assertSame('um_cargueiro_por_colonia', $e->codigo);
        }

        // Outra colônia ainda acha o segundo Cargueiro.
        app(FecharContratoDeRota::class)->handle($this->colonia(), $this->planeta('kalidor'), ['biomassa' => 100], []);
    }

    // ── 3. o XP de obra paga o crescimento, não a obra repetida ─────────────

    private function erguerMina(Colony $c, int $slot): void
    {
        $b = $c->buildings()->create(['type' => 'mina_local', 'level' => 0, 'slot' => $slot]);
        DB::table('build_queue')->insert([
            'colony_id' => $c->id, 'building_id' => $b->id, 'target_level' => 1, 'status' => 'building',
            'position' => 1, 'quoted_cost_json' => '{}', 'subsidized' => false,
            'starts_at' => now()->subHour(), 'finishes_at' => now()->subMinute(),
        ]);
        $b->update(['upgrade_started_at' => now()->subHour(), 'upgrade_finish_at' => now()->subMinute()]);
        $c->forceFill(['last_tick_at' => now()->subMinutes(2)])->save();
        app(ColonyTick::class)->handle($c->fresh(), now());
    }

    public function test_demolir_e_reerguer_nao_rende_xp(): void
    {
        $c = $this->colonia();
        $obras = fn () => (int) XpEntry::where('colony_id', $c->id)->where('acao', 'obra_concluida')->sum('xp');

        $this->erguerMina($c, 40);
        $depoisDaPrimeira = $obras();
        $this->assertSame(100, $depoisDaPrimeira - 500, 'a primeira Mina paga');

        $mina = $c->buildings()->where('type', 'mina_local')->firstOrFail();
        app(Demolir::class)->handle($c->fresh(), $mina);
        $this->erguerMina($c->fresh(), 40);

        $this->assertSame($depoisDaPrimeira, $obras(), 'reerguer o que já existia não paga de novo');

        // Uma segunda Mina, ao lado, é crescimento: paga.
        $this->erguerMina($c->fresh(), 41);
        $this->assertSame($depoisDaPrimeira + 100, $obras());
    }

    // ── 4. o XP de Acordo ganha teto diário, como o do Mercado (D-241) ──────

    public function test_o_xp_de_acordo_tem_teto_diario(): void
    {
        $c = $this->colonia();
        $antes = (int) $c->fresh()->xp;

        for ($i = 0; $i < 5; $i++) {
            app(ConcederXp::class)->handle($c->id, 'acordo_executado', "acordo:{$i}");
        }

        $teto = (int) DB::table('milestone_settings')->value('xp_acordo_teto_diario');
        $this->assertSame(3, $teto);
        $this->assertSame($antes + 3 * 150, (int) $c->fresh()->xp);
    }
}
