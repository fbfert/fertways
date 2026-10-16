<?php

namespace Tests\Feature;

use App\Domain\Building\Funcoes;
use App\Domain\Colony\CreateColony;
use App\Domain\Guerra\ChegarReforcos;
use App\Domain\Logistics\ConcluirTrechos;
use App\Domain\Logistics\DespacharVeiculo;
use App\Domain\Logistics\TransportarRobos;
use App\Domain\Logistics\VeiculoSpecs;
use App\Domain\Transport\ComprarVeiculo;
use App\Domain\Transport\Conservacao;
use App\Domain\Transport\Ministerio;
use App\Domain\Transport\UpgradeVeiculo;
use App\Domain\Zona\Estruturas;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\NeutralZone;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ErgueEstruturasDaZona;
use Tests\TestCase;

/**
 * A Nave de Transporte Planetária e as duas Plataformas de Pouso (D-281).
 *
 * O GDD a especifica inteira (§17.5, §21.8, §28.9): 4 m³ = 4.000 unidades, 10 slots por minuto,
 * 50 de Gelo de Metano por minuto de voo, risco zero. Ela vive na Plataforma de Pouso do slot e só
 * pousa em zona que tenha a Plataforma de Pouso da zona. Leva mercadoria da zona para casa e Robôs
 * Mineradores entre as zonas do mesmo colono.
 */
class NaveDeTransporteTest extends TestCase
{
    use ErgueEstruturasDaZona;
    use RefreshDatabase;

    private const NAVE = 'nave_de_transporte_planetaria';

    private int $placas = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
        $this->seed(ComponentRecipeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-17 12:00:00'));
    }

    private function colonia(int $plataforma = 1): Colony
    {
        $c = app(CreateColony::class)->handle(User::factory()->create(), 'Base', 20, 20);
        $c->resources()->update(['amount' => 0]);
        $c->resources()->updateOrCreate(['resource_type' => 'gelo_de_metano'], ['amount' => 100_000]);
        $c->resources()->updateOrCreate(['resource_type' => 'energia'], ['amount' => 100_000]);
        $c->update(['fert_micro' => 100_000 * Colony::MICRO_POR_FERT]);
        if ($plataforma > 0) {
            $c->buildings()->create(['type' => 'plataforma_de_pouso', 'level' => $plataforma, 'slot' => 30]);
        }
        $c->buildings()->create(['type' => 'central_de_transportes', 'level' => 5, 'slot' => 31]);

        return $c->fresh();
    }

    private function zona(Colony $dona, int $x, int $plataforma = 1, array $extra = []): NeutralZone
    {
        return $this->criarZonaComEstruturas(array_merge([
            'x' => $x, 'y' => 20, 'district' => 'nordeste', 'mineral' => 'metal_bruto',
            'level' => 1, 'status' => 'protegida',
            'owner_colony_id' => $dona->id, 'command_post_level' => 1,
            'productive_at' => now()->subDay(),
            'deposit_level' => 1, 'deposit_amount' => 5000,
        ], $plataforma > 0 ? ['landing_pad_level' => $plataforma] : [], $extra));
    }

    private function nave(Colony $c): Vehicle
    {
        return Vehicle::create([
            'colony_id' => $c->id, 'type' => self::NAVE, 'plate' => sprintf('FW-%05d-N', 90000 + ++$this->placas), 'level' => 1,
            'status' => 'ocioso', 'capacity' => 4000,
        ]);
    }

    private function gelo(Colony $c): int
    {
        return (int) $c->resources()->where('resource_type', 'gelo_de_metano')->value('amount');
    }

    private function fecharTrechos(): void
    {
        Carbon::setTestNow(now()->addHours(3));
        app(ConcluirTrechos::class)->handle();
        Carbon::setTestNow(now()->addHours(3));
        app(ConcluirTrechos::class)->handle();
    }

    // ── as specs do GDD ─────────────────────────────────────────────────────

    public function test_as_specs_sao_as_do_gdd(): void
    {
        // §28.9: 30 slots = 3,0 min e 150 de Gelo de Metano; 5 slots = 0,5 min e 25.
        $this->assertSame(180, VeiculoSpecs::segundosDoTrecho(self::NAVE, 30));
        $this->assertSame(150, VeiculoSpecs::energiaDasPernas(self::NAVE, [30]));
        $this->assertSame(25, VeiculoSpecs::energiaDasPernas(self::NAVE, [5]));
        $this->assertSame('gelo_de_metano', VeiculoSpecs::combustivel(self::NAVE));
        $this->assertSame('energia', VeiculoSpecs::combustivel('caminhao_de_carga'));
        $this->assertSame(4000, VeiculoSpecs::capacidade(self::NAVE));
    }

    public function test_a_nave_deprecia_como_furgao_e_caminhao(): void
    {
        $c = $this->colonia();

        $this->assertTrue(app(Conservacao::class)->deprecia($this->nave($c)));
    }

    // ── a compra e a Plataforma do slot ─────────────────────────────────────

    public function test_o_ministerio_vende_a_nave_com_o_custo_do_gdd(): void
    {
        $this->assertContains(self::NAVE, Ministerio::TIPOS);
        $this->assertSame(
            ['ligas_metalicas' => 250, 'componentes_eletronicos' => 120, 'metal_bruto' => 45],
            Ministerio::config(self::NAVE)['custo'],
        );
    }

    public function test_sem_plataforma_de_pouso_nao_se_compra_nave(): void
    {
        $c = $this->colonia(plataforma: 0);
        $this->naPrateleira();

        try {
            app(ComprarVeiculo::class)->handle($c, self::NAVE);
            $this->fail('Comprou nave sem Plataforma de Pouso.');
        } catch (DomainRuleException $e) {
            $this->assertSame('sem_plataforma_de_pouso', $e->codigo);
        }
    }

    public function test_cada_nivel_da_plataforma_estaciona_uma_nave(): void
    {
        $c = $this->colonia(plataforma: 1);
        $this->naPrateleira(2);

        app(ComprarVeiculo::class)->handle($c, self::NAVE);

        try {
            app(ComprarVeiculo::class)->handle($c->fresh(), self::NAVE);
            $this->fail('Estacionou duas naves numa Plataforma nível 1.');
        } catch (DomainRuleException $e) {
            $this->assertSame('plataforma_cheia', $e->codigo);
        }

        $c->buildings()->where('type', 'plataforma_de_pouso')->update(['level' => 2]);
        app(ComprarVeiculo::class)->handle($c->fresh(), self::NAVE);
        $this->assertSame(2, $c->vehicles()->where('type', self::NAVE)->count());
    }

    public function test_a_plataforma_encurta_o_upgrade_da_nave(): void
    {
        $c = $this->colonia(plataforma: 5);
        $upgrade = app(UpgradeVeiculo::class);

        $semPlataforma = $upgrade->segundos(2);
        $comPlataforma = $upgrade->segundosPara($this->nave($c), 2);

        // 5% por nível acima do primeiro: nível 5, 20% mais rápido.
        $this->assertSame((int) round($semPlataforma * 0.8), $comPlataforma);
    }

    private function naPrateleira(int $quantas = 1): void
    {
        for ($i = 0; $i < $quantas; $i++) {
            Vehicle::create([
                'colony_id' => null, 'type' => self::NAVE, 'plate' => 'FW-8000'.$i.'-N', 'level' => 1,
                'status' => 'estoque', 'capacity' => 4000,
            ]);
        }
    }

    // ── os voos ─────────────────────────────────────────────────────────────

    public function test_a_nave_retira_da_zona_queimando_gelo_de_metano(): void
    {
        $c = $this->colonia();
        $z = $this->zona($c, 50);
        $nave = $this->nave($c);
        $energia = (int) $c->resources()->where('resource_type', 'energia')->value('amount');

        app(DespacharVeiculo::class)->retirarDeZona($c, $nave, $z, ['metal_bruto' => 1000]);

        // 30 slots de ida e 30 de volta: 6 min de voo, 300 de Gelo de Metano. Energia, nada.
        $this->assertSame(100_000 - 300, $this->gelo($c));
        $this->assertSame($energia, (int) $c->resources()->where('resource_type', 'energia')->value('amount'));
        $this->assertSame(180, (int) now()->diffInSeconds($nave->fresh()->arrives_at));
        $this->assertTrue(DB::table('ledger')->where('type', 'combustivel_viagem')->where('resource_type', 'gelo_de_metano')->exists());

        $this->fecharTrechos();
        $this->assertSame('ocioso', $nave->fresh()->status);
        $this->assertGreaterThan(0, (int) $c->resources()->where('resource_type', 'metal_bruto')->value('amount'));
    }

    public function test_sem_gelo_de_metano_a_nave_nao_decola(): void
    {
        $c = $this->colonia();
        $c->resources()->where('resource_type', 'gelo_de_metano')->update(['amount' => 10]);
        $z = $this->zona($c, 50);

        try {
            app(DespacharVeiculo::class)->retirarDeZona($c, $this->nave($c), $z, ['metal_bruto' => 100]);
            $this->fail('Decolou sem combustível.');
        } catch (DomainRuleException $e) {
            $this->assertSame('falta_de_combustivel', $e->codigo);
        }
    }

    public function test_a_nave_so_pousa_em_zona_com_plataforma(): void
    {
        $c = $this->colonia();
        $z = $this->zona($c, 50, plataforma: 0);

        try {
            app(DespacharVeiculo::class)->retirarDeZona($c, $this->nave($c), $z, ['metal_bruto' => 100]);
            $this->fail('Pousou sem plataforma.');
        } catch (DomainRuleException $e) {
            $this->assertSame('zona_sem_plataforma', $e->codigo);
        }

        try {
            app(DespacharVeiculo::class)->reposicionarVazio($c, $this->nave($c), 'zona_neutra', $z->id);
            $this->fail('Estacionou sem plataforma.');
        } catch (DomainRuleException $e) {
            $this->assertSame('zona_sem_plataforma', $e->codigo);
        }
    }

    public function test_a_nave_leva_material_para_a_obra_da_zona(): void
    {
        $c = $this->colonia();
        $c->resources()->updateOrCreate(['resource_type' => 'ligas_metalicas'], ['amount' => 500]);
        $z = $this->zona($c, 50);

        app(DespacharVeiculo::class)->entregarMaterialNaZona($c, $this->nave($c), $z, ['ligas_metalicas' => 500]);

        $this->assertSame(100_000 - 300, $this->gelo($c));
    }

    public function test_a_nave_nao_vai_ao_mercado_nem_a_outra_colonia(): void
    {
        $c = $this->colonia();
        $c->resources()->updateOrCreate(['resource_type' => 'agua'], ['amount' => 500]);
        $nave = $this->nave($c);

        foreach ([
            fn () => app(DespacharVeiculo::class)->handle($c, $nave, 'mercado_central', null, ['agua' => 100]),
            fn () => app(DespacharVeiculo::class)->retirar($c, $nave, ['agua' => 100]),
            fn () => app(DespacharVeiculo::class)->reposicionarVazio($c, $nave, 'mercado_central', null),
        ] as $voo) {
            try {
                $voo();
                $this->fail('A Nave saiu de Fertways-zona.');
            } catch (DomainRuleException $e) {
                $this->assertSame('nave_so_voa_para_zonas', $e->codigo);
            }
        }
    }

    public function test_zona_cercada_fecha_o_ceu_tambem(): void
    {
        $c = $this->colonia();
        $z = $this->zona($c, 50, extra: ['sieged_at' => now()->subHour()]);

        $this->assertTrue($z->cercada());
        $this->expectException(DomainRuleException::class);
        app(DespacharVeiculo::class)->retirarDeZona($c, $this->nave($c), $z, ['metal_bruto' => 100]);
    }

    // ── os robôs entre zonas ────────────────────────────────────────────────

    private function robos(NeutralZone $z, int $quantos): array
    {
        $ids = [];
        for ($i = 0; $i < $quantos; $i++) {
            $ids[] = Unit::create(['zone_id' => $z->id, 'type' => 'robo_minerador', 'level' => 1, 'hp_bps' => Unit::INTEIRA, 'status' => 'na_zona'])->id;
        }

        return $ids;
    }

    /** A Nave estaciona na zona A — um voo vazio de casa, como qualquer veículo (D-109). */
    private function naveEstacionadaEm(Colony $c, NeutralZone $z): Vehicle
    {
        $nave = $this->nave($c);
        app(DespacharVeiculo::class)->reposicionarVazio($c, $nave, 'zona_neutra', $z->id);
        Carbon::setTestNow(now()->addHour());
        app(ConcluirTrechos::class)->handle();

        $nave->refresh();
        $this->assertTrue($nave->naZona());

        return $nave;
    }

    public function test_a_nave_leva_robos_de_uma_zona_a_outra(): void
    {
        $c = $this->colonia();
        $a = $this->zona($c, 50);
        $b = $this->zona($c, 60);
        $ids = $this->robos($a, 4);
        $nave = $this->naveEstacionadaEm($c, $a);
        $gelo = $this->gelo($c);

        app(TransportarRobos::class)->handle($c, $nave, $b, $ids);

        // 10 slots de A a B: 1 min de voo, 50 de Gelo de Metano. Os robôs saem de A agora.
        $this->assertSame($gelo - 50, $this->gelo($c));
        $this->assertSame(0, $a->fresh()->units()->where('status', 'na_zona')->count());
        $this->assertSame(4, Unit::whereIn('id', $ids)->where('zone_id', $b->id)->where('status', 'marchando')->count());

        Carbon::setTestNow(now()->addMinutes(2));
        app(ConcluirTrechos::class)->handle();
        app(ChegarReforcos::class)->handle();

        $this->assertSame(4, Unit::whereIn('id', $ids)->where('zone_id', $b->id)->where('status', 'na_zona')->count());
        $nave->refresh();
        $this->assertTrue($nave->naZona());
        $this->assertSame($b->id, $nave->destination_id);
    }

    public function test_pela_api_o_colono_diz_quantos_robos_e_vao_os_mais_inteiros(): void
    {
        $c = $this->colonia();
        $a = $this->zona($c, 50);
        $b = $this->zona($c, 60);
        $ids = $this->robos($a, 3);
        Unit::whereKey($ids[0])->update(['hp_bps' => 2000]);
        $nave = $this->naveEstacionadaEm($c, $a);

        $this->actingAs($c->user)->postJson("/vehicles/{$nave->id}/robos", ['zona' => $b->id, 'quantidade' => 2])
            ->assertCreated()->assertJsonPath('robos', 2);

        // O robô ferido ficou: foram os dois inteiros.
        $this->assertSame('na_zona', Unit::find($ids[0])->status);
        $this->assertSame(2, Unit::whereIn('id', [$ids[1], $ids[2]])->where('zone_id', $b->id)->count());

        $this->actingAs($c->user)->postJson("/vehicles/{$nave->id}/robos", ['zona' => $a->id, 'quantidade' => 1])
            ->assertStatus(422);
    }

    public function test_a_nave_leva_no_maximo_quatro_robos(): void
    {
        $c = $this->colonia();
        $a = $this->zona($c, 50);
        $b = $this->zona($c, 60);
        $ids = $this->robos($a, 5);
        $nave = $this->naveEstacionadaEm($c, $a);

        $this->expectException(DomainRuleException::class);
        app(TransportarRobos::class)->handle($c, $nave, $b, $ids);
    }

    public function test_robos_so_voam_entre_zonas_com_plataforma(): void
    {
        $c = $this->colonia();
        $a = $this->zona($c, 50);
        $b = $this->zona($c, 60, plataforma: 0);
        $ids = $this->robos($a, 2);
        $nave = $this->naveEstacionadaEm($c, $a);

        try {
            app(TransportarRobos::class)->handle($c, $nave, $b, $ids);
            $this->fail('Os robôs pousaram sem plataforma.');
        } catch (DomainRuleException $e) {
            $this->assertSame('zona_sem_plataforma', $e->codigo);
        }

        $this->assertSame(2, $a->fresh()->units()->where('status', 'na_zona')->count());
    }

    public function test_so_voam_robos_da_zona_onde_a_nave_esta(): void
    {
        $c = $this->colonia();
        $a = $this->zona($c, 50);
        $b = $this->zona($c, 60);
        $outra = $this->zona($c, 70);
        $ids = $this->robos($outra, 2);
        $nave = $this->naveEstacionadaEm($c, $a);

        try {
            app(TransportarRobos::class)->handle($c, $nave, $b, $ids);
            $this->fail('Levou robôs de outra zona.');
        } catch (DomainRuleException $e) {
            $this->assertSame('robos_indisponiveis', $e->codigo);
        }
    }

    public function test_robos_nao_saem_de_zona_cercada(): void
    {
        $c = $this->colonia();
        $a = $this->zona($c, 50);
        $b = $this->zona($c, 60);
        $ids = $this->robos($a, 2);
        $nave = $this->naveEstacionadaEm($c, $a);
        $a->update(['sieged_at' => now()->subMinute()]);

        $this->expectException(DomainRuleException::class);
        app(TransportarRobos::class)->handle($c, $nave, $b->fresh(), $ids);
    }

    public function test_a_nave_vai_de_uma_zona_a_outra_vazia(): void
    {
        $c = $this->colonia();
        $a = $this->zona($c, 50);
        $b = $this->zona($c, 60);
        $nave = $this->naveEstacionadaEm($c, $a);

        app(DespacharVeiculo::class)->reposicionarVazio($c, $nave, 'zona_neutra', $b->id);
        Carbon::setTestNow(now()->addMinutes(2));
        app(ConcluirTrechos::class)->handle();

        $nave->refresh();
        $this->assertTrue($nave->naZona());
        $this->assertSame($b->id, $nave->destination_id);
    }

    public function test_as_estruturas_deixam_de_ser_inertes(): void
    {
        $this->assertFalse(Estruturas::de('plataforma_de_pouso_da_zona')['inerte']);
        $this->assertNotSame('nenhum', Funcoes::de('plataforma_de_pouso')['efeito']);
    }
}
