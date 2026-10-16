<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Espacoporto\ConcluirContratosDeRota;
use App\Domain\Espacoporto\FecharContratoDeRota;
use App\Domain\Guerra\Sorteio;
use App\Domain\Marco\Curva;
use App\Domain\Treasury\Tesouro;
use App\Exceptions\DomainRuleException;
use App\Models\Admin;
use App\Models\Colony;
use App\Models\ContratoDeRota;
use App\Models\PlanetaNpc;
use App\Models\TreasuryHolding;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\EspacoportoPartidaSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Database\Seeders\TechnologySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A escolta e o seguro de carga do Espaçoporto (D-280).
 *
 * O GDD as chama de "mecânicas de temporada" (v3.2) e dá a Drakmoor "Alto — escolta opcional", sem
 * número nenhum. Os números são de partida, no painel:
 * - **seguro**: o prêmio é a perda esperada da apólice mais 25% de carregamento. O Tesouro reserva a
 *   indenização inteira na partida, e só vende o seguro se tiver como reservar;
 * - **escolta**: as Sentinelas da colônia acompanham o Cargueiro e cortam a chance de sinistro,
 *   até 75% com poder pleno (800 de ataque, dez Sentinelas nível 1). Elas ficam fora de casa a
 *   viagem inteira.
 */
class EspacoportoSeguroEscoltaTest extends TestCase
{
    use RefreshDatabase;

    /** A última chance que o Cargueiro sorteou, em bps. */
    public ?int $sorteada = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
        $this->seed(ComponentRecipeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
        $this->seed(TechnologySeeder::class);
        $this->seed(EspacoportoPartidaSeeder::class);
        TreasuryHolding::updateOrCreate(['resource_type' => Tesouro::FERT], ['amount' => 1_000_000 * Colony::MICRO_POR_FERT]);
        $this->moeda(false);
    }

    private function moeda(bool $cara): void
    {
        $teste = $this;
        $this->instance(Sorteio::class, new class($cara, $teste) extends Sorteio
        {
            public function __construct(private bool $cara, private EspacoportoSeguroEscoltaTest $teste) {}

            public function sucesso(int $bps): bool
            {
                $this->teste->sorteada = $bps;

                return $bps > 0 && $this->cara;
            }
        });
    }

    private int $proximo = 0;

    private function colonia(): Colony
    {
        $c = app(CreateColony::class)->handle(User::factory()->create(), 'C', 20 + $this->proximo++ * 3, 20);
        $c->forceFill([
            'xp' => Curva::xpDoMarco(40),
            'fert_micro' => 100_000 * Colony::MICRO_POR_FERT,
            'created_at' => now()->subDays(30)->subMinute(),
        ])->save();
        $c->resources()->updateOrCreate(['resource_type' => 'agua'], ['amount' => 5_000]);

        return $c->fresh();
    }

    private function planeta(string $chave): PlanetaNpc
    {
        return PlanetaNpc::where('chave', $chave)->firstOrFail();
    }

    private function sentinelas(Colony $c, int $quantas, int $nivel = 1): void
    {
        for ($i = 0; $i < $quantas; $i++) {
            Unit::create(['colony_id' => $c->id, 'type' => 'sentinela', 'level' => $nivel, 'hp_bps' => Unit::INTEIRA, 'status' => 'casa']);
        }
    }

    private function preco(string $planeta, string $recurso, string $coluna): int
    {
        return (int) $this->planeta($planeta)->precos()->where('resource_type', $recurso)->value($coluna);
    }

    private function tesouro(): int
    {
        return app(Tesouro::class)->saldoFertMicro();
    }

    /** Drakmoor: leva 1.000 de água, traz 100 de Gelo de Metano. */
    private function contratar(Colony $c, array $opcoes = []): ContratoDeRota
    {
        return app(FecharContratoDeRota::class)->handle($c, $this->planeta('drakmoor'), ['agua' => 1000], ['gelo_de_metano' => 100], $opcoes);
    }

    private function voltar(ContratoDeRota $k): void
    {
        app(ConcluirContratosDeRota::class)->handle($k->chega_em->copy()->addSecond());
    }

    /** A cobertura de Drakmoor (50% de perda) para a carga de `contratar()`, sem escolta. */
    private function coberturaEsperada(Colony $c): int
    {
        $agua = $this->preco('drakmoor', 'agua', 'compra_micro');
        $gelo = $this->preco('drakmoor', 'gelo_de_metano', 'venda_micro');
        $bpsAgua = (int) DB::table('resource_types')->where('code', 'agua')->value('tax_bps');

        $vendaPerdida = 500 * $agua;
        $vendaLiquida = $vendaPerdida - intdiv($vendaPerdida * $bpsAgua, 10_000);

        return $vendaLiquida + 50 * $gelo;
    }

    // ── o seguro ────────────────────────────────────────────────────────────

    public function test_o_seguro_cobra_a_perda_esperada_mais_o_carregamento_e_reserva_a_cobertura(): void
    {
        $c = $this->colonia();
        $antes = $this->tesouro();

        $k = $this->contratar($c, ['seguro' => true]);

        $cobertura = $this->coberturaEsperada($c);
        // A perda esperada (30% da cobertura), arredondada para cima, e mais 25% sobre ela.
        $esperada = intdiv($cobertura * 3000 + 9_999, 10_000);
        $premio = intdiv($esperada * 12_500 + 9_999, 10_000);

        $this->assertSame($cobertura, (int) $k->seguro_cobertura_micro);
        $this->assertSame($premio, (int) $k->seguro_premio_micro);
        // O Tesouro recebe o prêmio e reserva a cobertura: o saldo livre cai pela diferença.
        $this->assertSame($antes + $premio - $cobertura + (int) $k->aluguel_micro + (int) $k->imposto_importacao_micro, $this->tesouro());
    }

    public function test_com_sinistro_o_seguro_paga_o_que_se_perdeu(): void
    {
        $c = $this->colonia();
        $k = $this->contratar($c, ['seguro' => true]);
        $fertNaPartida = (int) $c->fresh()->fert_micro;
        $tesouroNaPartida = $this->tesouro();

        $this->moeda(true);
        $this->voltar($k);
        $k->refresh();

        $this->assertSame(5000, $k->sinistro_bps);
        $this->assertSame((int) $k->seguro_cobertura_micro, (int) $k->seguro_indenizacao_micro);
        // O colono recebe a venda do que chegou e a indenização do que se perdeu: o mesmo que receberia
        // da venda inteira, líquida do imposto.
        $agua = $this->preco('drakmoor', 'agua', 'compra_micro');
        $bps = (int) DB::table('resource_types')->where('code', 'agua')->value('tax_bps');
        $vendaChegou = 500 * $agua - intdiv(500 * $agua * $bps, 10_000);
        $this->assertSame($fertNaPartida + $vendaChegou + (int) $k->seguro_indenizacao_micro, (int) $c->fresh()->fert_micro);
        // A reserva já tinha saído do Tesouro: pagar a indenização não o mexe de novo, só o imposto entra.
        $this->assertSame($tesouroNaPartida + (int) $k->imposto_venda_micro, $this->tesouro());
    }

    public function test_sem_sinistro_a_reserva_volta_ao_tesouro(): void
    {
        $c = $this->colonia();
        $k = $this->contratar($c, ['seguro' => true]);
        $tesouroNaPartida = $this->tesouro();

        $this->voltar($k);
        $k->refresh();

        $this->assertSame(0, $k->sinistro_bps);
        $this->assertSame(0, (int) $k->seguro_indenizacao_micro);
        $this->assertSame($tesouroNaPartida + (int) $k->seguro_cobertura_micro + (int) $k->imposto_venda_micro, $this->tesouro());
    }

    public function test_sem_lastro_no_tesouro_o_seguro_nao_e_vendido(): void
    {
        TreasuryHolding::whereKey(Tesouro::FERT)->update(['amount' => 1]);
        $c = $this->colonia();
        $fert = (int) $c->fert_micro;

        try {
            $this->contratar($c, ['seguro' => true]);
            $this->fail('O seguro foi vendido sem lastro.');
        } catch (DomainRuleException $e) {
            $this->assertSame('seguro_sem_lastro', $e->codigo);
        }

        // Nada saiu: nem o aluguel, nem a água.
        $this->assertSame($fert, (int) $c->fresh()->fert_micro);
        $this->assertSame(5000, (int) $c->resources()->where('resource_type', 'agua')->value('amount'));
        $this->assertSame(0, ContratoDeRota::count());
    }

    public function test_planeta_sem_risco_nao_vende_seguro(): void
    {
        $c = $this->colonia();

        try {
            app(FecharContratoDeRota::class)->handle($c, $this->planeta('kalidor'), ['agua' => 100], [], ['seguro' => true]);
            $this->fail('Vendeu seguro numa rota sem risco.');
        } catch (DomainRuleException $e) {
            $this->assertSame('sem_risco', $e->codigo);
        }
    }

    public function test_sem_seguro_o_contrato_e_o_de_sempre(): void
    {
        $c = $this->colonia();
        $k = $this->contratar($c);

        $this->assertSame(0, (int) $k->seguro_premio_micro);
        $this->assertSame(0, (int) $k->seguro_cobertura_micro);

        $this->moeda(true);
        $this->voltar($k);
        $this->assertNull($k->fresh()->seguro_indenizacao_micro);
    }

    // ── a escolta ───────────────────────────────────────────────────────────

    public function test_a_escolta_leva_as_sentinelas_e_as_devolve_no_pouso(): void
    {
        $c = $this->colonia();
        $this->sentinelas($c, 12);

        $k = $this->contratar($c, ['escolta' => 10]);

        $this->assertSame(10, Unit::where('colony_id', $c->id)->where('status', 'escolta')->count());
        $this->assertSame(2, Unit::where('colony_id', $c->id)->where('status', 'casa')->count());
        $this->assertCount(10, $k->escolta_unit_ids);

        $this->voltar($k);

        $this->assertSame(12, Unit::where('colony_id', $c->id)->where('status', 'casa')->count());
    }

    public function test_a_escolta_plena_corta_tres_quartos_da_chance(): void
    {
        $c = $this->colonia();
        $this->sentinelas($c, 10);

        $k = $this->contratar($c, ['escolta' => 10]);
        $this->voltar($k);

        // 10 Sentinelas nível 1 = 800 de ataque = escolta plena: 30% × 25% = 7,5%.
        $this->assertSame(750, (int) $k->fresh()->risco_chance_bps);
        $this->assertSame(750, $this->sorteada);
    }

    public function test_meia_escolta_corta_a_metade_do_maximo(): void
    {
        $c = $this->colonia();
        $this->sentinelas($c, 5);

        $k = $this->contratar($c, ['escolta' => 5]);

        // 400 de 800 de poder: corta 37,5% da chance. 3000 × 0,625 = 1875.
        $this->assertSame(1875, (int) $k->risco_chance_bps);
    }

    public function test_sentinela_ferida_escolta_menos(): void
    {
        $c = $this->colonia();
        $this->sentinelas($c, 10);
        Unit::where('colony_id', $c->id)->update(['hp_bps' => 5000]);

        $k = $this->contratar($c, ['escolta' => 10]);

        $this->assertSame(1875, (int) $k->risco_chance_bps);
    }

    public function test_a_escolta_leva_as_mais_fortes_primeiro(): void
    {
        $c = $this->colonia();
        $this->sentinelas($c, 3, 1);
        $this->sentinelas($c, 2, 3);

        $k = $this->contratar($c, ['escolta' => 2]);

        $this->assertSame([3, 3], Unit::whereIn('id', $k->escolta_unit_ids)->pluck('level')->all());
    }

    public function test_nao_se_escolta_com_sentinelas_que_nao_estao_em_casa(): void
    {
        $c = $this->colonia();
        $this->sentinelas($c, 3);

        try {
            $this->contratar($c, ['escolta' => 4]);
            $this->fail('Escoltou com Sentinela que não existe.');
        } catch (DomainRuleException $e) {
            $this->assertSame('escolta_insuficiente', $e->codigo);
        }

        $this->assertSame(3, Unit::where('colony_id', $c->id)->where('status', 'casa')->count());
    }

    public function test_planeta_sem_risco_nao_aceita_escolta(): void
    {
        $c = $this->colonia();
        $this->sentinelas($c, 3);

        try {
            app(FecharContratoDeRota::class)->handle($c, $this->planeta('kalidor'), ['agua' => 100], [], ['escolta' => 3]);
            $this->fail('Escoltou uma rota sem risco.');
        } catch (DomainRuleException $e) {
            $this->assertSame('sem_risco', $e->codigo);
        }
    }

    public function test_a_escolta_barateia_o_seguro(): void
    {
        $a = $this->colonia();
        $semEscolta = $this->contratar($a, ['seguro' => true]);

        $b = $this->colonia();
        $this->sentinelas($b, 10);
        $comEscolta = $this->contratar($b, ['seguro' => true, 'escolta' => 10]);

        $this->assertSame((int) $semEscolta->seguro_cobertura_micro, (int) $comEscolta->seguro_cobertura_micro);
        $this->assertLessThan((int) $semEscolta->seguro_premio_micro / 3, (int) $comEscolta->seguro_premio_micro);
    }

    public function test_a_chance_fica_travada_na_partida(): void
    {
        $c = $this->colonia();
        $k = $this->contratar($c);
        $this->planeta('drakmoor')->update(['risco_chance_bps' => 9000]);

        $this->voltar($k);

        $this->assertSame(3000, $this->sorteada);
    }

    // ── a cotação e o painel ────────────────────────────────────────────────

    public function test_a_cotacao_diz_antes_o_que_o_contrato_cobra(): void
    {
        $c = $this->colonia();
        $this->sentinelas($c, 10);

        $cotacao = $this->actingAs($c->user)->postJson('/espacoporto/cotacao', [
            'planeta' => 'drakmoor', 'venda' => ['agua' => 1000], 'compra' => ['gelo_de_metano' => 100],
            'seguro' => true, 'escolta' => 10,
        ])->assertOk()->json();

        $k = $this->contratar($c, ['seguro' => true, 'escolta' => 10]);

        $this->assertSame((int) $k->risco_chance_bps, $cotacao['risco_chance_bps']);
        $this->assertSame((int) $k->seguro_premio_micro, $cotacao['seguro_premio_micro']);
        $this->assertSame((int) $k->seguro_cobertura_micro, $cotacao['seguro_cobertura_micro']);
        $this->assertSame(10, $cotacao['escolta_sentinelas']);
    }

    public function test_a_tela_mostra_as_regras_e_as_sentinelas_disponiveis(): void
    {
        $c = $this->colonia();
        $this->sentinelas($c, 4);

        $estado = $this->actingAs($c->user)->getJson('/espacoporto')->assertOk()->json();

        $this->assertSame(2500, $estado['seguro_carregamento_bps']);
        $this->assertSame(800, $estado['escolta_poder_pleno']);
        $this->assertSame(7500, $estado['escolta_reducao_max_bps']);
        $this->assertSame(4, $estado['sentinelas_em_casa']);
    }

    public function test_o_painel_escreve_os_tres_numeros(): void
    {
        $dono = Admin::create([
            'name' => 'Dona', 'email' => 'dona@fertways.test',
            'password' => Hash::make('segredo-forte-1234'), 'role' => Admin::DONO,
        ]);

        $this->actingAs($dono, 'admin')->post('/admin/transportes/espacoporto', [
            'ativo' => 1, 'aluguel' => '20', 'capacidade_unidades' => 10000, 'cargueiros' => 2,
            'seguro_carregamento' => '40', 'escolta_poder_pleno' => 1200, 'escolta_reducao_max' => '60',
        ])->assertSessionHas('ok');

        $cfg = DB::table('espacoporto_settings')->find(1);
        $this->assertSame(4000, (int) $cfg->seguro_carregamento_bps);
        $this->assertSame(1200, (int) $cfg->escolta_poder_pleno);
        $this->assertSame(6000, (int) $cfg->escolta_reducao_max_bps);
    }
}
