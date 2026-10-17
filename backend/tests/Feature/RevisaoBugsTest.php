<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Espacoporto\ConcluirContratosDeRota;
use App\Domain\Espacoporto\FecharContratoDeRota;
use App\Domain\Guerra\Sorteio;
use App\Domain\Logistics\ConcluirTrechos;
use App\Domain\Logistics\DespacharVeiculo;
use App\Domain\Logistics\TransportarRobos;
use App\Domain\Marco\Curva;
use App\Domain\Market\OfertarComoGoverno;
use App\Domain\Populacao\Populacao;
use App\Domain\Reconhecimento\AbrirMissaoPlanetaria;
use App\Domain\Reconhecimento\Gagarin;
use App\Domain\Treasury\Tesouro;
use App\Exceptions\DomainRuleException;
use App\Models\Admin;
use App\Models\Colony;
use App\Models\Combat;
use App\Models\NeutralZone;
use App\Models\PlanetaNpc;
use App\Models\TreasuryHolding;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\EspacoportoPartidaSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Database\Seeders\TechnologySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\ErgueEstruturasDaZona;
use Tests\TestCase;

/**
 * As correções de bug e de segurança da revisão completa do jogo (D-284). Cada teste afirma o
 * comportamento certo de um defeito que a revisão reproduziu.
 */
class RevisaoBugsTest extends TestCase
{
    use ErgueEstruturasDaZona;
    use RefreshDatabase;

    private int $proximo = 0;

    private int $placas = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
        $this->seed(ComponentRecipeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
        $this->seed(TechnologySeeder::class);
        $this->seed(EspacoportoPartidaSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-11-01 12:00:00'));
    }

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

    private function colonia(): Colony
    {
        $c = app(CreateColony::class)->handle(User::factory()->create(), 'C', 20 + $this->proximo++ * 3, 20);
        $c->forceFill([
            'xp' => Curva::xpDoMarco(40),
            'fert_micro' => 100_000 * Colony::MICRO_POR_FERT,
            'created_at' => now()->subDays(30)->subMinute(),
        ])->save();
        $c->resources()->updateOrCreate(['resource_type' => 'agua'], ['amount' => 5_000]);
        $c->resources()->updateOrCreate(['resource_type' => 'gelo_de_metano'], ['amount' => 100_000]);
        $c->buildings()->create(['type' => 'plataforma_de_pouso', 'level' => 2, 'slot' => 30]);
        $c->buildings()->create(['type' => 'central_de_transportes', 'level' => 5, 'slot' => 31]);

        return $c->fresh();
    }

    private function zona(Colony $dona, int $x): NeutralZone
    {
        return $this->criarZonaComEstruturas([
            'x' => $x, 'y' => 20, 'district' => 'nordeste', 'mineral' => 'metal_bruto',
            'level' => 1, 'status' => 'protegida', 'owner_colony_id' => $dona->id, 'command_post_level' => 1,
            'productive_at' => now()->subDay(), 'deposit_level' => 1, 'deposit_amount' => 5000, 'landing_pad_level' => 1,
        ]);
    }

    private function nave(Colony $c): Vehicle
    {
        return Vehicle::create([
            'colony_id' => $c->id, 'type' => Vehicle::NAVE, 'plate' => sprintf('FW-%05d-N', 70000 + ++$this->placas),
            'level' => 1, 'status' => 'ocioso', 'capacity' => 4000,
        ]);
    }

    private function estacionar(Colony $c, Vehicle $nave, NeutralZone $z): Vehicle
    {
        app(DespacharVeiculo::class)->reposicionarVazio($c, $nave, 'zona_neutra', $z->id);
        Carbon::setTestNow(now()->addHours(2));
        app(ConcluirTrechos::class)->handle();

        return $nave->fresh();
    }

    private function gelo(Colony $c): int
    {
        return (int) $c->resources()->where('resource_type', 'gelo_de_metano')->value('amount');
    }

    // ── o seguro e a perda travada ──────────────────────────────────────────

    public function test_a_perda_fica_travada_e_o_seguro_paga_o_que_se_perdeu(): void
    {
        TreasuryHolding::updateOrCreate(['resource_type' => Tesouro::FERT], ['amount' => 1_000_000 * Colony::MICRO_POR_FERT]);
        $this->moeda(false);
        $drakmoor = PlanetaNpc::where('chave', 'drakmoor')->firstOrFail();
        $k = app(FecharContratoDeRota::class)->handle($this->colonia(), $drakmoor, ['agua' => 1000], [], ['seguro' => true]);

        $drakmoor->update(['risco_perda_bps' => 100]);
        $this->moeda(true);
        app(ConcluirContratosDeRota::class)->handle($k->chega_em->copy()->addSecond());

        // A perda da partida (50%), não a da volta: e o seguro paga exatamente a cobertura dela.
        $this->assertSame(5000, (int) $k->fresh()->sinistro_bps);
        $this->assertSame((int) $k->seguro_cobertura_micro, (int) $k->fresh()->seguro_indenizacao_micro);
    }

    // ── o preço lacrado da missão planetária ────────────────────────────────

    private function missaoComBiomassaA(int $compraMicro): void
    {
        DB::table('gagarin_settings')->where('id', 1)->update(['meta_de_tarefas' => 0, 'servidor_aberto_em' => now()->subDays(45)]);
        $this->colonia();
        $g = app(Gagarin::class);
        $g->tick(now());
        foreach ([72, 72, 72] as $h) {
            Carbon::setTestNow(now()->addHours($h));
            $g->tick(now());
        }

        app(AbrirMissaoPlanetaria::class)->handle([
            'chave' => 'meridia', 'nome' => 'Merídia', 'distancia_minutos' => 600, 'risco' => 'nenhum',
            'risco_chance_bps' => 0, 'risco_perda_bps' => 0, 'exporta' => 'x', 'importa' => 'biomassa',
            'precos' => ['biomassa' => ['compra_micro' => $compraMicro, 'venda_micro' => null], 'agua' => ['compra_micro' => 1000, 'venda_micro' => null]],
        ], 'Setor 7', 'gravitacional', 'proxima', 5);
    }

    public function test_o_governo_nao_vende_abaixo_do_preco_lacrado_de_uma_missao(): void
    {
        $this->missaoComBiomassaA(1_000_000);
        TreasuryHolding::updateOrCreate(['resource_type' => 'biomassa'], ['amount' => 1000]);

        try {
            app(OfertarComoGoverno::class)->definir('biomassa', 1000, 500_000);
            $this->fail('O Governo vendeu abaixo do preço lacrado.');
        } catch (DomainRuleException $e) {
            $this->assertSame('arbitragem_com_planeta', $e->codigo);
        }
    }

    public function test_na_abertura_a_rota_perde_a_compra_que_ficou_acima_do_governo(): void
    {
        $this->missaoComBiomassaA(1_000_000);
        // Uma oferta do Governo que entrou por fora do painel durante a missão.
        DB::table('market_orders')->insert([
            'colony_id' => null, 'resource_type' => 'biomassa', 'side' => 'sell', 'price_micro' => 500_000,
            'qty' => 10, 'escrow_resource_qty' => 10, 'status' => 'aberta', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $g = app(Gagarin::class);
        foreach ([48, 24, 5, 24] as $h) {
            Carbon::setTestNow(now()->addHours($h));
            $g->tick(now());
        }

        $planeta = PlanetaNpc::where('chave', 'meridia')->firstOrFail();
        $this->assertTrue($planeta->aberto);
        $this->assertFalse($planeta->precos()->where('resource_type', 'biomassa')->exists(), 'a compra acima do Governo saiu');
        $this->assertTrue($planeta->precos()->where('resource_type', 'agua')->exists(), 'o resto da tabela ficou');
        $this->assertTrue(DB::table('registro_de_reconhecimento')->where('evento', 'compra_retirada')->exists());
    }

    // ── a Nave ──────────────────────────────────────────────────────────────

    public function test_nave_estacionada_noutra_zona_nao_retira_como_se_estivesse_em_casa(): void
    {
        $c = $this->colonia();
        $longe = $this->zona($c, 120);
        $perto = $this->zona($c, 30);
        $nave = $this->estacionar($c, $this->nave($c), $longe);

        try {
            app(DespacharVeiculo::class)->retirarDeZona($c, $nave, $perto, ['metal_bruto' => 1000]);
            $this->fail('A Nave se teletransportou para casa.');
        } catch (DomainRuleException $e) {
            $this->assertSame('veiculo_fora_de_casa', $e->codigo);
        }
    }

    public function test_nave_estacionada_na_zona_leva_a_carga_dela_numa_perna_so(): void
    {
        $c = $this->colonia();
        $z = $this->zona($c, 50);
        $nave = $this->estacionar($c, $this->nave($c), $z);
        $gelo = $this->gelo($c);

        app(DespacharVeiculo::class)->retirarDeZona($c, $nave, $z, ['metal_bruto' => 1000]);

        // 30 slots só de volta: 3 min de voo, 150 de Gelo de Metano.
        $this->assertSame($gelo - 150, $this->gelo($c));
        $this->assertSame('volta', $nave->fresh()->leg);

        Carbon::setTestNow(now()->addMinutes(5));
        app(ConcluirTrechos::class)->handle();
        $this->assertSame(Vehicle::EM_CASA, $nave->fresh()->local);
        $this->assertGreaterThan(0, (int) $c->resources()->where('resource_type', 'metal_bruto')->value('amount'));
    }

    public function test_veiculo_estacionado_numa_zona_nao_leva_material_de_casa(): void
    {
        $c = $this->colonia();
        $z = $this->zona($c, 50);
        $c->resources()->updateOrCreate(['resource_type' => 'ligas_metalicas'], ['amount' => 500]);
        $nave = $this->estacionar($c, $this->nave($c), $z);

        $this->expectException(DomainRuleException::class);
        app(DespacharVeiculo::class)->entregarMaterialNaZona($c, $nave, $z, ['ligas_metalicas' => 100]);
    }

    public function test_dois_despachos_do_mesmo_veiculo_nao_passam(): void
    {
        $c = $this->colonia();
        $z = $this->zona($c, 50);
        $id = $this->nave($c)->id;
        $a = Vehicle::find($id);
        $b = Vehicle::find($id);

        app(DespacharVeiculo::class)->retirarDeZona($c, $a, $z, ['metal_bruto' => 2000]);

        try {
            app(DespacharVeiculo::class)->retirarDeZona($c, $b, $z, ['metal_bruto' => 2000]);
            $this->fail('O mesmo veículo partiu duas vezes.');
        } catch (DomainRuleException $e) {
            $this->assertSame('veiculo_ocupado', $e->codigo);
        }

        $this->assertSame(3000, (int) $z->fresh()->deposit_amount);
    }

    public function test_robos_nao_fogem_de_zona_em_combate(): void
    {
        $c = $this->colonia();
        $inimigo = app(CreateColony::class)->handle(User::factory()->create(), 'Inimigo', 80, 80);
        $a = $this->zona($c, 50);
        $b = $this->zona($c, 60);
        $ids = [];
        for ($i = 0; $i < 2; $i++) {
            $ids[] = Unit::create(['zone_id' => $a->id, 'type' => 'robo_minerador', 'level' => 1, 'hp_bps' => 10000, 'status' => 'na_zona'])->id;
        }
        $nave = $this->estacionar($c, $this->nave($c), $a);
        Combat::create([
            'zone_id' => $a->id, 'attacker_colony_id' => $inimigo->id, 'defender_colony_id' => $c->id,
            'tipo' => 'invasao', 'status' => 'em_curso', 'rodada' => 1, 'chega_at' => now()->subMinutes(5),
        ]);

        try {
            app(TransportarRobos::class)->handle($c, $nave, $b, $ids);
            $this->fail('Os robôs fugiram da batalha.');
        } catch (DomainRuleException $e) {
            $this->assertSame('zona_em_combate', $e->codigo);
        }
    }

    public function test_a_nave_que_chega_a_uma_zona_perdida_volta_para_casa(): void
    {
        $c = $this->colonia();
        $outro = $this->colonia();
        $z = $this->zona($c, 50);
        $nave = $this->nave($c);
        app(DespacharVeiculo::class)->reposicionarVazio($c, $nave, 'zona_neutra', $z->id);
        $z->update(['owner_colony_id' => $outro->id]);

        Carbon::setTestNow(now()->addMinutes(10));
        app(ConcluirTrechos::class)->handle();
        $this->assertSame('volta', $nave->fresh()->leg);

        Carbon::setTestNow(now()->addMinutes(10));
        app(ConcluirTrechos::class)->handle();
        $this->assertSame(Vehicle::EM_CASA, $nave->fresh()->local);
    }

    public function test_a_api_dos_robos_nao_revela_a_zona_de_outro(): void
    {
        $dono = $this->colonia();
        $curioso = $this->colonia();
        $z = $this->zona($dono, 50);
        Unit::create(['zone_id' => $z->id, 'type' => 'robo_minerador', 'level' => 1, 'hp_bps' => 10000, 'status' => 'na_zona']);
        $nave = $this->estacionar($dono, $this->nave($dono), $z);

        $r = $this->actingAs($curioso->user)->postJson("/vehicles/{$nave->id}/robos", ['zona' => $z->id, 'quantidade' => 4]);

        $r->assertStatus(422);
        $this->assertSame('veiculo_de_outra_colonia', $r->json('code'));
        $this->assertStringNotContainsString('Robô', (string) $r->json('message'));
    }

    // ── a escolta conta colonos ─────────────────────────────────────────────

    public function test_sentinela_de_escolta_esta_em_campanha(): void
    {
        $c = $this->colonia();
        Unit::create(['colony_id' => $c->id, 'type' => 'sentinela', 'level' => 1, 'hp_bps' => 10000, 'status' => 'escolta']);

        $porUnidade = (int) (DB::table('war_settings')->value('operadores_por_unidade') ?? 1);
        $this->assertSame($porUnidade, app(Populacao::class)->alocadaEmCampanha($c));
    }

    // ── o registro público ──────────────────────────────────────────────────

    public function test_o_registro_publico_e_a_revelacao_aparecem_ao_colono(): void
    {
        DB::table('gagarin_settings')->where('id', 1)->update(['servidor_aberto_em' => now()->subDays(45)]);
        $c = $this->colonia();
        app(Gagarin::class)->tick(now());

        $tela = $this->actingAs($c->user)->getJson('/reconhecimento')->assertOk()->json();

        $this->assertNotEmpty($tela['registro']);
        $this->assertSame('criada', $tela['registro'][0]['evento']);
    }

    // ── o painel: o que é do Dono ───────────────────────────────────────────

    public function test_operador_nao_troca_senha_nem_corrige_jogador(): void
    {
        $operador = Admin::create([
            'name' => 'Op', 'email' => 'op@fertways.test',
            'password' => Hash::make('segredo-forte-1234'), 'role' => Admin::OPERADOR,
        ]);
        $alvo = $this->colonia()->user;
        $email = $alvo->email;

        $this->actingAs($operador, 'admin')->post("/admin/jogadores/{$alvo->id}/dados", [
            'email' => 'tomada@x.test', 'nickname' => $alvo->nickname,
        ])->assertForbidden();
        $this->actingAs($operador, 'admin')->post("/admin/jogadores/{$alvo->id}/senha", [
            'senha' => 'nova-senha-forte-123', 'senha_confirmation' => 'nova-senha-forte-123',
        ])->assertForbidden();

        $this->assertSame($email, $alvo->fresh()->email);
    }

    // ── o apelido não imita o sistema ───────────────────────────────────────

    public function test_o_apelido_segue_a_regra_do_cadastro_e_nao_imita_o_sistema(): void
    {
        $c = $this->colonia();
        $u = $c->user;
        $perfil = fn (string $nick) => ['name' => $u->name, 'email' => $u->email, 'nickname' => $nick];

        $this->actingAs($u)->patchJson('/profile', $perfil("Capital (oficial) \u{200B}\u{2713}"))->assertStatus(422);
        $this->actingAs($u)->patchJson('/profile', $perfil('Capital'))->assertStatus(422);
        $this->actingAs($u)->patchJson('/profile', $perfil('Mi-ssoes'))->assertStatus(422);
        $this->actingAs($u)->patchJson('/profile', $perfil('colono_novo'))->assertOk();
    }
}
