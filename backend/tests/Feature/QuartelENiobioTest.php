<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Guerra\ComprarNiobio;
use App\Domain\Guerra\FabricarUnidade;
use App\Domain\Treasury\Tesouro;
use App\Exceptions\DomainRuleException;
use App\Models\Building;
use App\Models\Colony;
use App\Models\Unit;
use App\Models\User;
use App\Models\WarSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O Quartel fabrica, e o governo vende o Nióbio (docs/decisoes.md D-66).
 *
 * **É este par que torna a guerra alcançável.** Sem ele, a Sentinela existe só na tabela: nada no
 * jogo a fabricava (o Quartel era "promessa pura" desde o D-59) e nada no jogo produz o Nióbio que
 * ela custa. Ver o D-66 para a conta que mostra que atacar seria matematicamente impossível.
 */
class QuartelENiobioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\ComponentRecipeSeeder::class);
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
        $this->seed(\Database\Seeders\TreasurySeeder::class);
    }

    private function colonoComQuartel(int $nivel = 1): Colony
    {
        $colony = app(CreateColony::class)->handle(User::factory()->create(), 'Base', 20, 20);
        $colony->update(['fert_micro' => 10_000 * 1_000_000]);

        Building::create([
            'colony_id' => $colony->id, 'type' => 'quartel', 'level' => $nivel, 'slot' => 20,
        ]);

        foreach (['ligas_metalicas' => 10000, 'componentes_eletronicos' => 5000,
                  'metal_bruto' => 10000, 'niobio_alienigena' => 0] as $r => $q) {
            $colony->resources()->where('resource_type', $r)->update(['amount' => $q]);
        }

        return $colony->fresh();
    }

    // ── o Nióbio ────────────────────────────────────────────────────────────────────────────────

    /** Sem Nióbio, a Sentinela é inalcançável — e é exatamente esse o estado inicial da colônia. */
    public function test_sem_niobio_a_sentinela_nao_sai_do_quartel(): void
    {
        $colony = $this->colonoComQuartel();

        $this->expectException(DomainRuleException::class);
        // A mensagem diz onde comprar: o freio é de propósito, mas não é um beco sem saída.
        $this->expectExceptionMessageMatches('/Nióbio|niobio/');

        app(FabricarUnidade::class)->handle($colony, 'sentinela', 1, 1);
    }

    /** O governo vende do caixa do Tesouro, e o Fert$ do colono vai para lá (D-17, D-66). */
    public function test_o_governo_vende_niobio_e_o_fert_vai_para_o_tesouro(): void
    {
        $colony = $this->colonoComQuartel();
        $tesouro = app(Tesouro::class);

        $niobioNoTesouro = $tesouro->saldos()->firstWhere('resource_type', 'niobio_alienigena')->amount;
        $fertNoTesouro = $tesouro->saldoFertMicro();
        $preco = WarSetting::singleton()->niobio_preco_micro;

        app(ComprarNiobio::class)->handle($colony, 10);

        $colony->refresh();

        $this->assertSame(
            10,
            $colony->resources()->where('resource_type', 'niobio_alienigena')->value('amount'),
        );
        // Pagou 10 × 3,163 Fert$ = 31,63.
        $this->assertSame(10_000 * 1_000_000 - 10 * $preco, $colony->fert_micro);

        // O Tesouro perdeu o Nióbio e ganhou o Fert$: o caixa é a fonte, e ele pode secar.
        $this->assertSame(
            $niobioNoTesouro - 10,
            app(Tesouro::class)->saldos()->firstWhere('resource_type', 'niobio_alienigena')->amount,
        );
        $this->assertSame($fertNoTesouro + 10 * $preco, app(Tesouro::class)->saldoFertMicro());
    }

    /**
     * O preço nasce em 10× o de referência do §06 (0,3163 Fert$), e é do operador.
     *
     * E a PRIMEIRA leitura já traz os números — não a segunda. O `firstOrCreate([])` devolvia o
     * modelo sem os defaults que o banco aplicara (o Eloquent insere e não relê), então a primeira
     * chamada depois da migration lia zero em tudo: Muralha que não protege, Nióbio de graça. Este
     * teste existe porque foi assim que nasceu, e nada reclamava.
     */
    public function test_a_primeira_leitura_dos_parametros_ja_traz_os_numeros_do_d66(): void
    {
        $this->assertSame(0, WarSetting::count(), 'a tabela tem de estar vazia: é esse o ponto');

        $c = WarSetting::singleton();   // a PRIMEIRA chamada de todas

        $this->assertSame(2000, $c->muralha_bonus_bps);
        $this->assertSame(3000, $c->torre_bonus_bps);
        $this->assertSame(5000, $c->bastiao_bonus_bps);
        $this->assertSame(1500, $c->torre_deteccao_bps_por_nivel);
        $this->assertSame(5000, $c->predador_base_bps);
        $this->assertSame(3_163_000, $c->niobio_preco_micro);
    }

    /** O preço de referência do §06, e o múltiplo do D-66. */
    public function test_o_preco_do_niobio_nasce_em_dez_vezes_o_de_referencia(): void
    {
        $referencia = (int) \DB::table('resource_types')
            ->where('code', 'niobio_alienigena')->value('preco_base_micro');

        $this->assertSame(316_300, $referencia);                          // §06
        $this->assertSame(3_163_000, WarSetting::singleton()->niobio_preco_micro);  // × 10 (D-66)
    }

    /** O caixa seca, e aí não há caminhão nem Nióbio — o mesmo desenho do D-60. */
    public function test_o_tesouro_sem_niobio_recusa_a_venda(): void
    {
        $colony = $this->colonoComQuartel();

        \DB::table('treasury_holdings')
            ->where('resource_type', 'niobio_alienigena')
            ->update(['amount' => 2]);

        $this->expectException(DomainRuleException::class);
        app(ComprarNiobio::class)->handle($colony, 3);
    }

    // ── o Quartel ───────────────────────────────────────────────────────────────────────────────

    /** Comprado o Nióbio, a Sentinela enfim sai — e é o laço que destrava a guerra inteira. */
    public function test_com_niobio_comprado_a_sentinela_sai_do_quartel(): void
    {
        $colony = $this->colonoComQuartel();

        app(ComprarNiobio::class)->handle($colony, 9);
        app(FabricarUnidade::class)->handle($colony->fresh(), 'sentinela', 1, 3);

        $sentinelas = Unit::where('colony_id', $colony->id)->where('type', 'sentinela')->get();

        $this->assertCount(3, $sentinelas);

        foreach ($sentinelas as $s) {
            $this->assertSame('casa', $s->status);
            $this->assertSame(Unit::INTEIRA, $s->hp_bps);
            $this->assertSame(80, $s->ataque());    // §27.1, nível 1
            $this->assertSame(100, $s->defesa());
        }

        // Os 9 Nióbio foram-se: 3 por Sentinela (§27.1).
        $this->assertSame(
            0,
            $colony->fresh()->resources()->where('resource_type', 'niobio_alienigena')->value('amount'),
        );
    }

    public function test_sem_quartel_nao_se_fabrica_nada(): void
    {
        $colony = app(CreateColony::class)->handle(User::factory()->create(), 'Base', 20, 20);

        $this->expectException(DomainRuleException::class);
        app(FabricarUnidade::class)->handle($colony, 'robo_minerador', 1, 1);
    }

    /**
     * O nível do Quartel é o teto do nível da unidade. **Não está no GDD** (D-66): é a leitura óbvia
     * de uma fábrica com níveis, e o mesmo desenho da Central de Transportes do D-60. Sem o teto, os
     * níveis do Quartel não serviriam para nada.
     */
    public function test_o_quartel_nao_produz_unidade_acima_do_proprio_nivel(): void
    {
        $colony = $this->colonoComQuartel(2);
        app(ComprarNiobio::class)->handle($colony, 100);

        // Nível 2 passa.
        app(FabricarUnidade::class)->handle($colony->fresh(), 'sentinela', 2, 1);
        $this->assertSame(120, Unit::where('colony_id', $colony->id)->first()->ataque());

        // Nível 3, não.
        $this->expectException(DomainRuleException::class);
        app(FabricarUnidade::class)->handle($colony->fresh(), 'sentinela', 3, 1);
    }

    // ── a API ───────────────────────────────────────────────────────────────────────────────────

    public function test_o_endpoint_da_guerra_mostra_o_exercito_e_o_preco_do_niobio(): void
    {
        $colony = $this->colonoComQuartel();
        app(ComprarNiobio::class)->handle($colony, 3);
        app(FabricarUnidade::class)->handle($colony->fresh(), 'sentinela', 1, 1);

        $this->actingAs($colony->user)
            ->getJson('/war')
            ->assertOk()
            ->assertJson([
                'quartel_nivel' => 1,
                'niobio' => ['em_estoque' => 0, 'preco_fert' => 3.163],
                'bonus_defensivos' => [
                    'muralha_pct_por_nivel' => 20,
                    'torre_de_vigia_pct_por_nivel' => 30,
                    'bastiao_pct_por_nivel' => 50,
                ],
            ])
            ->assertJsonCount(1, 'unidades')
            ->assertJsonPath('unidades.0.ataque', 80);
    }

    public function test_o_endpoint_compra_niobio_e_fabrica(): void
    {
        $colony = $this->colonoComQuartel();

        $this->actingAs($colony->user)
            ->postJson('/war/niobio', ['quantidade' => 6])
            ->assertCreated()
            ->assertJson(['comprado' => 6]);

        $this->actingAs($colony->user)
            ->postJson('/war/units', ['type' => 'sentinela', 'level' => 1, 'quantidade' => 2])
            ->assertCreated()
            ->assertJson(['fabricadas' => 2]);

        $this->assertSame(2, Unit::where('colony_id', $colony->id)->where('type', 'sentinela')->count());
    }
}
