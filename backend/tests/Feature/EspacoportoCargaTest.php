<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Espacoporto\ConcluirContratosDeRota;
use App\Domain\Espacoporto\Espacoporto;
use App\Domain\Espacoporto\FecharContratoDeRota;
use App\Domain\Guerra\Sorteio;
use App\Domain\Marco\Curva;
use App\Domain\Treasury\Tesouro;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\ContratoDeRota;
use App\Models\PlanetaNpc;
use App\Models\User;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\EspacoportoPartidaSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Database\Seeders\TechnologySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A carga interplanetária: imposto, risco e portão por planeta (D-269).
 *
 * O D-262 deixou três lacunas escritas no código: "sem imposto sobre a carga interplanetária", "o
 * risco alto não tem mecânica" e "os portões por planeta não têm critério publicado". O usuário
 * mandou seguir as recomendações:
 *
 * - **imposto**: a mesma régua do Mercado — a alíquota do recurso, sobre o valor, nas duas pernas; o
 *   que o planeta paga é tributado na volta, o que se encomenda é tributado na partida. Vai ao Tesouro;
 * - **risco**: um sinistro sorteado na viagem, que leva uma fração da carga das duas pernas;
 * - **portão**: Auryn "por marco" pede o marco 25; Solène "por ciência" pede o Método Experimental;
 *   Drakmoor "tardio" pede o marco 35 (Construtor). Com isso, os dois últimos abrem.
 */
class EspacoportoCargaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
        $this->seed(ComponentRecipeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
        $this->seed(TechnologySeeder::class);
        $this->seed(EspacoportoPartidaSeeder::class);
        $this->moeda(false);
    }

    private int $proximo = 0;

    private function colonia(int $marco = 40, int $dias = 30): Colony
    {
        $c = app(CreateColony::class)->handle(User::factory()->create(), 'C', 20 + $this->proximo++ * 3, 20);
        $c->forceFill([
            'xp' => Curva::xpDoMarco($marco),
            'fert_micro' => 10_000 * Colony::MICRO_POR_FERT,
            'created_at' => now()->subDays($dias)->subMinute(),
        ])->save();
        $c->resources()->updateOrCreate(['resource_type' => 'agua'], ['amount' => 5_000]);

        return $c->fresh();
    }

    private function planeta(string $chave): PlanetaNpc
    {
        return PlanetaNpc::where('chave', $chave)->firstOrFail();
    }

    /** A moeda do sinistro: sempre cara (acontece) ou sempre coroa (não acontece). */
    private function moeda(bool $cara): void
    {
        $this->instance(Sorteio::class, new class($cara) extends Sorteio
        {
            public function __construct(private bool $cara) {}

            public function sucesso(int $bps): bool
            {
                return $bps > 0 && $this->cara;
            }
        });
    }

    private function bps(string $recurso): int
    {
        return (int) DB::table('resource_types')->where('code', $recurso)->value('tax_bps');
    }

    private function preco(PlanetaNpc $p, string $recurso, string $coluna): int
    {
        return (int) $p->precos()->where('resource_type', $recurso)->value($coluna);
    }

    private function voltar(ContratoDeRota $k): void
    {
        app(ConcluirContratosDeRota::class)->handle($k->chega_em->copy()->addSecond());
    }

    // ── o imposto ───────────────────────────────────────────────────────────

    public function test_a_venda_ao_planeta_paga_o_imposto_do_recurso_na_volta(): void
    {
        $c = $this->colonia();
        $kalidor = $this->planeta('kalidor');
        $tesouroAntes = app(Tesouro::class)->saldoFertMicro();

        $k = app(FecharContratoDeRota::class)->handle($c, $kalidor, ['agua' => 1_000], []);
        $fertNaPartida = (int) $c->fresh()->fert_micro;
        $this->voltar($k);

        $bruto = 1_000 * $this->preco($kalidor, 'agua', 'compra_micro');
        $imposto = intdiv($bruto * $this->bps('agua'), 10_000);
        $this->assertGreaterThan(0, $imposto, 'a água tem alíquota');

        $this->assertSame($fertNaPartida + $bruto - $imposto, (int) $c->fresh()->fert_micro);
        $this->assertSame($tesouroAntes + (int) $k->aluguel_micro + $imposto, app(Tesouro::class)->saldoFertMicro());
        $this->assertSame(1, DB::table('tax_events')->where('kind', 'espacoporto_venda')->count());
    }

    public function test_a_encomenda_paga_o_imposto_de_importacao_na_partida(): void
    {
        $c = $this->colonia();
        $kalidor = $this->planeta('kalidor');
        $antes = (int) $c->fert_micro;

        $k = app(FecharContratoDeRota::class)->handle($c, $kalidor, [], ['metal_bruto' => 1_000]);

        $valor = 1_000 * $this->preco($kalidor, 'metal_bruto', 'venda_micro');
        $imposto = intdiv($valor * $this->bps('metal_bruto'), 10_000);
        $this->assertGreaterThan(0, $imposto);

        $this->assertSame($antes - (int) $k->aluguel_micro - $valor - $imposto, (int) $c->fresh()->fert_micro);
        $this->assertSame(1, DB::table('tax_events')->where('kind', 'espacoporto_importacao')->count());
    }

    /** O imposto entra na conta de quem não tem dinheiro: falta Fert$ também para ele. */
    public function test_falta_fert_para_o_imposto_de_importacao(): void
    {
        $c = $this->colonia();
        $kalidor = $this->planeta('kalidor');
        $valor = 1_000 * $this->preco($kalidor, 'metal_bruto', 'venda_micro');
        $c->forceFill(['fert_micro' => 20 * Colony::MICRO_POR_FERT + $valor])->save();

        $this->expectException(DomainRuleException::class);
        app(FecharContratoDeRota::class)->handle($c->fresh(), $kalidor, [], ['metal_bruto' => 1_000]);
    }

    // ── o risco ─────────────────────────────────────────────────────────────

    public function test_o_risco_vem_do_gdd_em_numeros(): void
    {
        $risco = PlanetaNpc::orderBy('ordem')->get()->mapWithKeys(fn ($p) => [
            $p->chave => [(int) $p->risco_chance_bps, (int) $p->risco_perda_bps],
        ])->all();

        $this->assertSame([
            'kalidor' => [0, 0], 'veyra' => [0, 0], 'auryn' => [1000, 2000],
            'solene' => [0, 0], 'drakmoor' => [3000, 5000],
        ], $risco);
    }

    public function test_o_sinistro_leva_uma_fracao_das_duas_pernas(): void
    {
        $this->moeda(true);
        $c = $this->colonia();
        $auryn = $this->planeta('auryn');
        $fertAntes = (int) $c->fert_micro;
        $silicioAntes = (int) $c->resources()->where('resource_type', 'silicio')->value('amount');

        $k = app(FecharContratoDeRota::class)->handle($c, $auryn, ['agua' => 1_000], ['silicio' => 100]);
        $fertNaPartida = (int) $c->fresh()->fert_micro;
        $this->voltar($k);

        $k->refresh();
        $this->assertSame(2000, (int) $k->sinistro_bps, 'Auryn: 20% da carga');

        // A venda: o planeta pagou só pelo que chegou — 800 de 1.000.
        $bruto = 800 * $this->preco($auryn, 'agua', 'compra_micro');
        $liquido = $bruto - intdiv($bruto * $this->bps('agua'), 10_000);
        $this->assertSame($fertNaPartida + $liquido, (int) $c->fresh()->fert_micro);

        // A encomenda: chegou 80 de 100, e o que se pagou na partida não volta.
        $this->assertSame($silicioAntes + 80, (int) $c->fresh()->resources()->where('resource_type', 'silicio')->value('amount'));
        $this->assertLessThan($fertAntes, $fertNaPartida);
    }

    public function test_sem_risco_nao_ha_sinistro_mesmo_com_a_moeda_viciada(): void
    {
        $this->moeda(true);
        $c = $this->colonia();

        $k = app(FecharContratoDeRota::class)->handle($c, $this->planeta('kalidor'), ['agua' => 1_000], []);
        $this->voltar($k);

        $this->assertSame(0, (int) $k->fresh()->sinistro_bps);
    }

    // ── os portões ──────────────────────────────────────────────────────────

    public function test_cada_planeta_tem_o_seu_portao(): void
    {
        $esp = app(Espacoporto::class);
        $desbravador = $this->colonia(marco: 22);

        $this->assertTrue($esp->portao($desbravador, $this->planeta('kalidor'))['pode']);
        $this->assertFalse($esp->portao($desbravador, $this->planeta('auryn'))['pode'], 'Auryn pede o marco 25');
        $this->assertFalse($esp->portao($this->colonia(marco: 30), $this->planeta('drakmoor'))['pode'], 'Drakmoor pede o 35');
        $this->assertTrue($esp->portao($this->colonia(marco: 35), $this->planeta('drakmoor'))['pode']);

        try {
            app(FecharContratoDeRota::class)->handle($desbravador, $this->planeta('auryn'), ['agua' => 10], []);
            $this->fail('marco 22 não vai a Auryn');
        } catch (DomainRuleException $e) {
            $this->assertSame('portao_do_planeta', $e->codigo);
            $this->assertStringContainsString('marco 25', $e->getMessage());
        }
    }

    public function test_solene_abre_pela_ciencia(): void
    {
        $esp = app(Espacoporto::class);
        $c = $this->colonia();
        $solene = $this->planeta('solene');

        $this->assertFalse($esp->portao($c, $solene)['pode']);
        $this->assertStringContainsString('Método Experimental', $esp->portao($c, $solene)['falta']);

        DB::table('colony_technologies')->insert([
            'colony_id' => $c->id,
            'technology_id' => DB::table('technologies')->where('chave', 'tec_ciencia_1')->value('id'),
            'status' => 'concluida', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertTrue($esp->portao($c, $solene)['pode']);
    }

    public function test_solene_e_drakmoor_abrem_agora_que_tem_portao_e_risco(): void
    {
        $this->assertSame(5, PlanetaNpc::where('aberto', true)->count());
    }

    /**
     * O Nióbio é o freio do exército (D-66): o governo o vende a 10× a referência. Drakmoor aberto, a
     * 1,25×, seria Nióbio a um sétimo do preço. Ele vende — é uma segunda fonte —, mas nunca abaixo do
     * governo.
     */
    public function test_drakmoor_nao_vende_niobio_abaixo_do_governo(): void
    {
        $governo = (int) \App\Models\WarSetting::singleton()->niobio_preco_micro;

        $this->assertGreaterThanOrEqual($governo, $this->preco($this->planeta('drakmoor'), 'niobio_alienigena', 'venda_micro'));
    }

    /** O mesmo vale numa produção que já tinha a partida do D-263: a migration corrige o preço. */
    public function test_a_migration_sobe_o_niobio_de_drakmoor(): void
    {
        $m = require database_path('migrations/2026_10_16_400000_carga_interplanetaria.php');
        $m->down();
        $this->planeta('drakmoor')->precos()->where('resource_type', 'niobio_alienigena')->update(['venda_micro' => 1]);

        $m->up();

        $this->assertSame(
            (int) \App\Models\WarSetting::singleton()->niobio_preco_micro,
            $this->preco($this->planeta('drakmoor'), 'niobio_alienigena', 'venda_micro'),
        );
    }

    /**
     * Em produção, a partida do D-263 já precificou os cinco e deixou dois fechados. A migration volta
     * e vai: fecha os dois e tira as colunas; reabre e escreve o risco e o portão.
     */
    public function test_a_migration_vai_e_volta_sobre_a_partida_do_d263(): void
    {
        $m = require database_path('migrations/2026_10_16_400000_carga_interplanetaria.php');

        $m->down();
        $this->assertSame(3, PlanetaNpc::where('aberto', true)->count());

        $m->up();
        $this->assertSame(5, PlanetaNpc::where('aberto', true)->count());
        $this->assertSame(35, (int) $this->planeta('drakmoor')->marco_exigido);
    }

    /** Planeta sem preço não abre: aberto sem preço seria um planeta que não compra nem vende nada. */
    public function test_a_migration_nao_reabre_o_que_nao_tem_preco(): void
    {
        $m = require database_path('migrations/2026_10_16_400000_carga_interplanetaria.php');
        $m->down();
        $this->planeta('drakmoor')->precos()->delete();

        $m->up();
        $this->assertFalse($this->planeta('drakmoor')->aberto);
    }

    /** O risco e o portão são números do operador, como o preço: o painel os escreve. */
    public function test_o_painel_escreve_risco_e_portao(): void
    {
        $dono = \App\Models\Admin::create([
            'name' => 'Dona', 'email' => 'dona@fertways.test',
            'password' => \Illuminate\Support\Facades\Hash::make('segredo-forte-1234'), 'role' => \App\Models\Admin::DONO,
        ]);
        $auryn = $this->planeta('auryn');

        $this->actingAs($dono, 'admin')->get('/admin/transportes?aba=espacoporto')
            ->assertOk()->assertSee('name="risco_chance"', false)->assertSee('name="tecnologia_exigida"', false);

        $this->actingAs($dono, 'admin')->post("/admin/transportes/espacoporto/planetas/{$auryn->id}", [
            'aberto' => 1, 'distancia_minutos' => 720, 'precos' => 'agua:0.05:',
            'risco_chance' => '15', 'risco_perda' => '25', 'marco_exigido' => '28', 'tecnologia_exigida' => 'tec_ciencia_1',
        ])->assertSessionHas('ok');

        $auryn->refresh();
        $this->assertSame([1500, 2500, 28, 'tec_ciencia_1'], [
            $auryn->risco_chance_bps, $auryn->risco_perda_bps, $auryn->marco_exigido, $auryn->tecnologia_exigida,
        ]);
    }

    /** A tela diz, por planeta, o portão e o risco — antes de o colono alugar. */
    public function test_o_estado_mostra_portao_e_risco_por_planeta(): void
    {
        $estado = app(Espacoporto::class)->estado($this->colonia(marco: 22));
        $auryn = collect($estado['planetas'])->firstWhere('chave', 'auryn');

        $this->assertFalse($auryn['portao']['pode']);
        $this->assertSame(1000, $auryn['risco_chance_bps']);
        $this->assertSame(2000, $auryn['risco_perda_bps']);
    }
}
