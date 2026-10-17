<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Embaixada\Credenciais;
use App\Domain\Embaixada\EmitirCredencial;
use App\Domain\Espacoporto\ConcluirContratosDeRota;
use App\Domain\Espacoporto\FecharContratoDeRota;
use App\Domain\Guerra\Sorteio;
use App\Domain\Marco\Curva;
use App\Domain\Treasury\Tesouro;
use App\Exceptions\DomainRuleException;
use App\Models\Admin;
use App\Models\Colony;
use App\Models\PlanetaNpc;
use App\Models\TreasuryHolding;
use App\Models\User;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\EspacoportoPartidaSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Database\Seeders\TechnologySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A Embaixada Interplanetária, slot 9 da Capital (D-289): a credencial comercial por planeta.
 */
class EmbaixadaInterplanetariaTest extends TestCase
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
        TreasuryHolding::updateOrCreate(['resource_type' => Tesouro::FERT], ['amount' => 1_000_000 * Colony::MICRO_POR_FERT]);
        // Sem sinistro: o que se mede aqui é o imposto.
        $this->instance(Sorteio::class, new class extends Sorteio
        {
            public function sucesso(int $bps): bool
            {
                return false;
            }
        });
    }

    private function colonia(int $marco = 40): Colony
    {
        $c = app(CreateColony::class)->handle(User::factory()->create(), 'C', 20 + $this->proximo++ * 3, 20);
        $c->forceFill([
            'xp' => Curva::xpDoMarco($marco),
            'fert_micro' => 10_000 * Colony::MICRO_POR_FERT,
            'created_at' => now()->subDays(30)->subMinute(),
        ])->save();
        $c->resources()->updateOrCreate(['resource_type' => 'agua'], ['amount' => 5_000]);

        return $c->fresh();
    }

    private function kalidor(): PlanetaNpc
    {
        return PlanetaNpc::where('chave', 'kalidor')->firstOrFail();
    }

    private function codigo(callable $fn): string
    {
        try {
            $fn();
        } catch (DomainRuleException $e) {
            return $e->codigo;
        }

        $this->fail('Deveria ter recusado.');
    }

    public function test_emitir_a_credencial_cobra_ao_tesouro_e_vale_pelo_prazo(): void
    {
        $c = $this->colonia();
        $k = $this->kalidor();
        $preco = (int) $k->credencial_preco_micro;
        $tesouro = app(Tesouro::class)->saldoFertMicro();

        $cred = app(EmitirCredencial::class)->handle($c, $k);

        $this->assertGreaterThan(0, $preco, 'a partida dá preço a todo planeta');
        $this->assertSame(10_000 * Colony::MICRO_POR_FERT - $preco, (int) $c->fresh()->fert_micro);
        $this->assertSame($tesouro + $preco, app(Tesouro::class)->saldoFertMicro());
        $this->assertTrue(DB::table('ledger')->where('colony_id', $c->id)->where('type', 'embaixada_credencial')->exists());
        $dias = (int) DB::table('espacoporto_settings')->value('credencial_dias');
        $this->assertSame(now()->addDays($dias)->getTimestamp(), $cred->valida_ate->getTimestamp());
    }

    public function test_com_credencial_o_imposto_das_duas_pernas_cai_pelo_desconto(): void
    {
        $sem = $this->colonia();
        $com = $this->colonia();
        $k = $this->kalidor();
        app(EmitirCredencial::class)->handle($com, $k);

        $kSem = app(FecharContratoDeRota::class)->handle($sem, $k, ['agua' => 1_000], ['metal_bruto' => 1_000]);
        $kCom = app(FecharContratoDeRota::class)->handle($com, $k, ['agua' => 1_000], ['metal_bruto' => 1_000]);

        $desconto = (int) $k->credencial_desconto_bps;
        $this->assertGreaterThan(0, (int) $kSem->imposto_importacao_micro);
        $this->assertEqualsWithDelta(
            (int) $kSem->imposto_importacao_micro * (10_000 - $desconto) / 10_000,
            (int) $kCom->imposto_importacao_micro, 2,
        );

        app(ConcluirContratosDeRota::class)->handle($kCom->chega_em->copy()->addMinute());
        $vendaSem = (int) $kSem->fresh()->imposto_venda_micro;
        $this->assertGreaterThan(0, $vendaSem);
        $this->assertEqualsWithDelta($vendaSem * (10_000 - $desconto) / 10_000, (int) $kCom->fresh()->imposto_venda_micro, 2);
    }

    public function test_credencial_vencida_nao_desconta(): void
    {
        $c = $this->colonia();
        $k = $this->kalidor();
        app(EmitirCredencial::class)->handle($c, $k);

        $this->assertGreaterThan(0, app(Credenciais::class)->descontoBps($c, $k->id, now()));

        Carbon::setTestNow(now()->addDays(31));
        $this->assertSame(0, app(Credenciais::class)->descontoBps($c, $k->id, now()));
    }

    public function test_as_recusas(): void
    {
        $k = $this->kalidor();

        $this->assertSame('portao_fechado', $this->codigo(fn () => app(EmitirCredencial::class)->handle($this->colonia(marco: 5), $k)));

        $pobre = $this->colonia();
        $pobre->forceFill(['fert_micro' => 0])->save();
        $this->assertSame('sem_fert', $this->codigo(fn () => app(EmitirCredencial::class)->handle($pobre->fresh(), $k)));

        $c = $this->colonia();
        app(EmitirCredencial::class)->handle($c, $k);
        $this->assertSame('credencial_vigente', $this->codigo(fn () => app(EmitirCredencial::class)->handle($c->fresh(), $k)));

        $k->forceFill(['credencial_preco_micro' => null])->save();
        $this->assertSame('planeta_sem_credencial', $this->codigo(fn () => app(EmitirCredencial::class)->handle($this->colonia(), $k->fresh())));
    }

    public function test_renovar_perto_do_fim_soma_o_prazo_ao_que_resta(): void
    {
        $c = $this->colonia();
        $k = $this->kalidor();
        $primeira = app(EmitirCredencial::class)->handle($c, $k);

        Carbon::setTestNow($primeira->valida_ate->copy()->subDays(3));
        $segunda = app(EmitirCredencial::class)->handle($c->fresh(), $k);

        $dias = (int) DB::table('espacoporto_settings')->value('credencial_dias');
        $this->assertSame($primeira->valida_ate->copy()->addDays($dias)->getTimestamp(), $segunda->valida_ate->getTimestamp());
    }

    public function test_a_api_da_embaixada(): void
    {
        $c = $this->colonia();
        Sanctum::actingAs($c->user);

        $this->getJson('/embaixada')->assertOk()
            ->assertJsonPath('planetas.0.credencial', null)
            ->assertJsonStructure(['dias', 'planetas' => [['chave', 'nome', 'preco_micro', 'desconto_bps', 'portao', 'credencial']]]);

        $this->postJson('/embaixada/credenciais', ['planeta' => 'kalidor'])->assertOk();

        $kalidor = collect($this->getJson('/embaixada')->json('planetas'))->firstWhere('chave', 'kalidor');
        $this->assertNotNull($kalidor['credencial']['valida_ate']);
    }

    public function test_o_painel_define_o_preco_e_o_desconto(): void
    {
        $dono = Admin::create(['name' => 'D', 'email' => 'd@fertways.test', 'password' => Hash::make('segredo-forte-1234'), 'role' => Admin::DONO]);
        $k = $this->kalidor();
        $precos = $k->precos->map(fn ($p) => $p->resource_type.':'.($p->compra_micro === null ? '' : $p->compra_micro / 1e6).':'.($p->venda_micro === null ? '' : $p->venda_micro / 1e6))->implode("\n");

        $this->actingAs($dono, 'admin')->post("/admin/transportes/espacoporto/planetas/{$k->id}", [
            'aberto' => 1, 'distancia_minutos' => $k->distancia_minutos, 'precos' => $precos,
            'credencial_preco' => 75, 'credencial_desconto' => 40,
        ])->assertRedirect();

        $this->assertSame(75 * Colony::MICRO_POR_FERT, (int) $k->fresh()->credencial_preco_micro);
        $this->assertSame(4_000, (int) $k->fresh()->credencial_desconto_bps);
    }
}
