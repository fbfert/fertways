<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Espacoporto\ConcluirContratosDeRota;
use App\Domain\Espacoporto\Espacoporto;
use App\Domain\Espacoporto\FecharContratoDeRota;
use App\Domain\Marco\Curva;
use App\Exceptions\DomainRuleException;
use App\Models\Admin;
use App\Models\Colony;
use App\Models\ContratoDeRota;
use App\Models\Ledger;
use App\Models\PlanetaNpc;
use App\Models\User;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * O Espaçoporto e os cinco planetas NPC (GDD v35 §03, §05, §16.2, §21.10; D-262).
 *
 * O GDD publica o mundo — os cinco planetas, distância, risco, o que exportam e importam, o Governo
 * como operador exclusivo do Cargueiro, o portão do marco 20 + 10 dias — e **nenhum número de
 * economia**: nem preço, nem aluguel, nem capacidade, nem frota. Como a escavação (D-249), o sistema
 * nasce construído e FECHADO: cada número de economia é do operador e nasce vazio.
 */
class EspacoportoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
        $this->seed(ComponentRecipeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
    }

    private int $proximo = 0;

    /** Uma colônia no portão: marco 20 e 10 dias de idade. */
    private function colonia(int $marco = 20, int $dias = 10): Colony
    {
        $c = app(CreateColony::class)->handle(User::factory()->create(), 'C', 20 + $this->proximo++ * 3, 20);
        $c->forceFill([
            'xp' => Curva::xpDoMarco($marco),
            'fert_micro' => 1_000 * Colony::MICRO_POR_FERT,
            'created_at' => now()->subDays($dias)->subMinute(),
        ])->save();
        $c->resources()->updateOrCreate(['resource_type' => 'agua'], ['amount' => 5_000]);

        return $c->fresh();
    }

    /** O operador abre o Espaçoporto e Kalidor, com os números que ESTE teste escolhe. */
    private function abrir(): PlanetaNpc
    {
        DB::table('espacoporto_settings')->where('id', 1)->update([
            'ativo' => true, 'aluguel_micro' => 10 * Colony::MICRO_POR_FERT,
            'capacidade_unidades' => 1_000, 'cargueiros' => 1,
        ]);

        $kalidor = PlanetaNpc::where('chave', 'kalidor')->firstOrFail();
        $kalidor->update(['aberto' => true]);
        // Kalidor importa água (compra do colono) e exporta metais (vende ao colono) — §03.
        $kalidor->precos()->create(['resource_type' => 'agua', 'compra_micro' => 50_000, 'venda_micro' => null]);
        $kalidor->precos()->create(['resource_type' => 'metal_bruto', 'compra_micro' => null, 'venda_micro' => 20_000]);

        return $kalidor->fresh();
    }

    // ── o que o GDD publica ─────────────────────────────────────────────────

    public function test_os_cinco_planetas_do_gdd_nascem_no_banco_e_fechados(): void
    {
        $planetas = PlanetaNpc::orderBy('ordem')->get();

        $this->assertSame(['Kalidor', 'Veyra', 'Auryn', 'Solène', 'Drakmoor'], $planetas->pluck('nome')->all());
        $this->assertSame([240, 390, 720, 1080, 1860], $planetas->pluck('distancia_minutos')->map(fn ($m) => (int) $m)->all());
        $this->assertSame('alto', $planetas->last()->risco);
        $this->assertFalse($planetas->contains('aberto', true), 'nenhum abre sem o operador');
    }

    /** Nenhum número de economia inventado: o Espaçoporto nasce fechado, e diz por quê. */
    public function test_nasce_fechado_e_sem_numero_de_economia(): void
    {
        $estado = app(Espacoporto::class)->estado($this->colonia());

        $this->assertFalse($estado['ligado']);
        $this->assertNull(DB::table('espacoporto_settings')->value('aluguel_micro'));
        $this->assertNull(DB::table('espacoporto_settings')->value('capacidade_unidades'));
        $this->assertSame(0, DB::table('planeta_npc_precos')->count());
    }

    // ── o portão (§05) ──────────────────────────────────────────────────────

    public function test_o_portao_e_o_marco_20_com_10_dias(): void
    {
        $kalidor = $this->abrir();

        foreach ([[19, 30], [20, 9]] as [$marco, $dias]) {
            try {
                app(FecharContratoDeRota::class)->handle($this->colonia($marco, $dias), $kalidor, ['agua' => 100], []);
                $this->fail("marco {$marco} com {$dias} dias não devia passar");
            } catch (DomainRuleException $e) {
                $this->assertSame('portao_do_cargueiro', $e->codigo);
            }
        }
    }

    // ── o contrato de rota ──────────────────────────────────────────────────

    public function test_o_contrato_leva_a_carga_cobra_e_volta_no_prazo_do_planeta(): void
    {
        $kalidor = $this->abrir();
        $c = $this->colonia();
        $metalAntes = (int) $c->resources()->where('resource_type', 'metal_bruto')->value('amount');

        $contrato = app(FecharContratoDeRota::class)->handle($c, $kalidor, ['agua' => 1_000], ['metal_bruto' => 500]);

        // Sai na partida: a água, o aluguel e a compra (500 × 0,02 F$ = 10 F$).
        $this->assertSame(4_000, (int) $c->resources()->where('resource_type', 'agua')->value('amount'));
        $this->assertSame((1_000 - 10 - 10) * Colony::MICRO_POR_FERT, (int) $c->fresh()->fert_micro);
        $this->assertEqualsWithDelta(240, now()->diffInMinutes($contrato->chega_em), 1);

        $this->assertSame(0, app(ConcluirContratosDeRota::class)->handle(now()), 'antes do prazo, nada');
        $this->assertSame(1, app(ConcluirContratosDeRota::class)->handle($contrato->chega_em->copy()->addSecond()));

        // Volta: a venda (1.000 × 0,05 F$ = 50 F$) e o metal comprado.
        $c->refresh();
        $this->assertSame((980 + 50) * Colony::MICRO_POR_FERT, (int) $c->fert_micro);
        $this->assertSame($metalAntes + 500, (int) $c->resources()->where('resource_type', 'metal_bruto')->value('amount'));
        $this->assertSame(ContratoDeRota::CONCLUIDO, $contrato->fresh()->status);
        $this->assertTrue(Ledger::where('colony_id', $c->id)->where('type', 'espacoporto_venda')->exists());
    }

    /** O preço é travado na partida: o operador que muda o preço no meio não reescreve contrato fechado. */
    public function test_o_preco_e_o_da_partida(): void
    {
        $kalidor = $this->abrir();
        $c = $this->colonia();
        $contrato = app(FecharContratoDeRota::class)->handle($c, $kalidor, ['agua' => 1_000], []);

        $kalidor->precos()->where('resource_type', 'agua')->update(['compra_micro' => 1]);
        app(ConcluirContratosDeRota::class)->handle($contrato->chega_em->copy()->addSecond());

        $this->assertSame((990 + 50) * Colony::MICRO_POR_FERT, (int) $c->fresh()->fert_micro);
    }

    public function test_so_se_vende_o_que_o_planeta_compra_e_cabe_no_cargueiro(): void
    {
        $kalidor = $this->abrir();
        $c = $this->colonia();

        foreach ([
            [['energia' => 10], [], 'planeta_nao_compra'],
            [[], ['agua' => 10], 'planeta_nao_vende'],
            [['agua' => 1_001], [], 'carga_acima_da_capacidade'],
            [[], [], 'contrato_vazio'],
        ] as [$venda, $compra, $codigo]) {
            try {
                app(FecharContratoDeRota::class)->handle($c, $kalidor, $venda, $compra);
                $this->fail("devia recusar com {$codigo}");
            } catch (DomainRuleException $e) {
                $this->assertSame($codigo, $e->codigo);
            }
        }

        $pobre = $this->colonia();
        $pobre->resources()->where('resource_type', 'agua')->update(['amount' => 50]);

        try {
            app(FecharContratoDeRota::class)->handle($pobre->fresh(), $kalidor, ['agua' => 100], []);
            $this->fail('devia recusar por estoque');
        } catch (DomainRuleException $e) {
            $this->assertSame('recurso_insuficiente', $e->codigo);
        }

        $this->assertSame(0, ContratoDeRota::count(), 'nenhuma recusa deixa contrato para trás');
    }

    /** O Governo é o operador exclusivo (§16.2): contratos simultâneos ≤ cargueiros da frota. */
    public function test_nao_ha_mais_contratos_que_cargueiros(): void
    {
        $kalidor = $this->abrir();
        app(FecharContratoDeRota::class)->handle($this->colonia(), $kalidor, ['agua' => 10], []);

        $this->expectException(DomainRuleException::class);
        app(FecharContratoDeRota::class)->handle($this->colonia(), $kalidor, ['agua' => 10], []);
    }

    public function test_planeta_fechado_nao_recebe_contrato(): void
    {
        $this->abrir();
        $veyra = PlanetaNpc::where('chave', 'veyra')->firstOrFail();

        $this->expectException(DomainRuleException::class);
        app(FecharContratoDeRota::class)->handle($this->colonia(), $veyra, ['agua' => 10], []);
    }

    public function test_o_tick_conclui_o_contrato(): void
    {
        $kalidor = $this->abrir();
        $contrato = app(FecharContratoDeRota::class)->handle($this->colonia(), $kalidor, ['agua' => 10], []);

        $this->travelTo($contrato->chega_em->copy()->addMinute());
        $this->artisan('fertways:tick');

        $this->assertSame(ContratoDeRota::CONCLUIDO, $contrato->fresh()->status);
    }

    // ── a API e o painel ────────────────────────────────────────────────────

    public function test_a_api_diz_o_estado_e_fecha_o_contrato(): void
    {
        $kalidor = $this->abrir();
        $c = $this->colonia();

        $this->actingAs($c->user)->getJson('/espacoporto')
            ->assertOk()
            ->assertJsonPath('ligado', true)
            ->assertJsonPath('portao.pode', true)
            ->assertJsonPath('planetas.0.nome', 'Kalidor');

        $this->actingAs($c->user)->postJson('/espacoporto/contratos', [
            'planeta' => $kalidor->chave, 'venda' => ['agua' => 100], 'compra' => [],
        ])->assertCreated();

        $this->assertSame(1, ContratoDeRota::count());
    }

    public function test_o_painel_abre_o_espacoporto_e_os_precos(): void
    {
        $dono = Admin::create([
            'name' => 'Dona', 'email' => 'dona@fertways.test',
            'password' => Hash::make('segredo-forte-1234'), 'role' => Admin::DONO,
        ]);

        $this->actingAs($dono, 'admin')->get('/admin/transportes?aba=espacoporto')
            ->assertOk()->assertSee('name="aluguel"', false);

        $this->actingAs($dono, 'admin')->post('/admin/transportes/espacoporto', [
            'ativo' => 1, 'aluguel' => '10', 'capacidade_unidades' => 1000, 'cargueiros' => 2,
        ])->assertSessionHas('ok');

        $kalidor = PlanetaNpc::where('chave', 'kalidor')->firstOrFail();
        $this->actingAs($dono, 'admin')->post("/admin/transportes/espacoporto/planetas/{$kalidor->id}", [
            'aberto' => 1, 'distancia_minutos' => 240, 'precos' => "agua:0.05:\nmetal_bruto::0.02",
        ])->assertSessionHas('ok');

        $this->assertTrue($kalidor->fresh()->aberto);
        $this->assertSame(50_000, (int) $kalidor->precos()->where('resource_type', 'agua')->value('compra_micro'));
        $this->assertNull($kalidor->precos()->where('resource_type', 'agua')->value('venda_micro'));
        $this->assertTrue(DB::table('audit_log')->where('acao', 'espacoporto.planeta')->exists());
    }
}
