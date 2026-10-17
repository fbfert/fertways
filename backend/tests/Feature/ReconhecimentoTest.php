<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Espacoporto\FecharContratoDeRota;
use App\Domain\Marco\Curva;
use App\Domain\Reconhecimento\AbrirMissaoPlanetaria;
use App\Domain\Reconhecimento\Contribuir;
use App\Domain\Reconhecimento\Gagarin;
use App\Exceptions\DomainRuleException;
use App\Models\Admin;
use App\Models\Colony;
use App\Models\MissaoDeReconhecimento;
use App\Models\News;
use App\Models\PlanetaNpc;
use App\Models\User;
use Carbon\Carbon;
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
 * As Missões de Reconhecimento e o Telescópio Gagarin (aditivo v3.4 §2, D-282).
 */
class ReconhecimentoTest extends TestCase
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
        Carbon::setTestNow(Carbon::parse('2026-11-01 12:00:00'));
    }

    private function gagarin(): Gagarin
    {
        return app(Gagarin::class);
    }

    private function tick(): void
    {
        $this->gagarin()->tick(now());
    }

    private function avancar(int $horas): void
    {
        Carbon::setTestNow(now()->addHours($horas));
        $this->tick();
    }

    private function colonia(bool $laboratorio = true): Colony
    {
        $c = app(CreateColony::class)->handle(User::factory()->create(), 'C', 20 + $this->proximo++ * 3, 20);
        $c->forceFill(['xp' => Curva::xpDoMarco(40), 'fert_micro' => 10_000 * Colony::MICRO_POR_FERT,
            'created_at' => now()->subDays(30)])->save();
        if ($laboratorio) {
            $c->buildings()->create(['type' => 'laboratorio', 'level' => 1]);
        }

        return $c->fresh();
    }

    /** Liga o Gagarin pelo calendário: o servidor abriu há 45 dias. */
    private function ativar(): void
    {
        DB::table('gagarin_settings')->where('id', 1)->update(['servidor_aberto_em' => now()->subDays(45)]);
        $this->tick();
        $this->assertTrue($this->gagarin()->ativo());
    }

    private function lunar(): MissaoDeReconhecimento
    {
        return MissaoDeReconhecimento::where('tipo', 'lunar')->whereNull('concluida_em')->firstOrFail();
    }

    // ── a ativação ──────────────────────────────────────────────────────────

    public function test_o_gagarin_nasce_desligado(): void
    {
        $this->colonia();
        $this->tick();

        $this->assertFalse($this->gagarin()->ativo());
        $this->assertSame(0, MissaoDeReconhecimento::count());
    }

    public function test_ativa_com_45_dias_de_servidor(): void
    {
        $this->colonia();
        DB::table('gagarin_settings')->where('id', 1)->update(['servidor_aberto_em' => now()->subDays(44)]);
        $this->tick();
        $this->assertFalse($this->gagarin()->ativo());

        $this->avancar(24);
        $this->assertTrue($this->gagarin()->ativo());
        $this->assertTrue(News::where('author', 'Telescópio Gagarin')->exists());
    }

    public function test_ativa_com_50_jogadores(): void
    {
        DB::table('gagarin_settings')->where('id', 1)->update(['servidor_aberto_em' => now(), 'limiar_jogadores' => 3]);
        $this->colonia();
        $this->colonia();
        $this->tick();
        $this->assertFalse($this->gagarin()->ativo());

        $this->colonia();
        $this->tick();
        $this->assertTrue($this->gagarin()->ativo());
    }

    public function test_sem_data_a_abertura_e_a_primeira_colonia(): void
    {
        $c = $this->colonia();
        $c->forceFill(['created_at' => now()->subDays(50)])->save();

        $this->tick();

        $this->assertTrue($this->gagarin()->ativo());
    }

    // ── a missão lunar ──────────────────────────────────────────────────────

    public function test_o_primeiro_boletim_abre_a_leitura_da_primeira_lua(): void
    {
        $this->colonia();
        $this->ativar();

        $m = $this->lunar();
        $this->assertSame('armstrong', $m->alvo);
        $this->assertSame('leitura_orbital', $m->fase);
        $this->assertSame(hash('sha256', json_encode($m->resultado, JSON_UNESCAPED_UNICODE)), $m->assinatura);
        $this->assertTrue(DB::table('registro_de_reconhecimento')->where('missao_id', $m->id)->where('evento', 'criada')->exists());
    }

    public function test_a_lua_passa_pelas_quatro_fases_e_se_revela(): void
    {
        DB::table('gagarin_settings')->where('id', 1)->update(['meta_de_tarefas' => 2]);
        $c = $this->colonia();
        $this->ativar();
        $m = $this->lunar();

        app(Contribuir::class)->handle($c, $m);
        app(Contribuir::class)->handle($c, $m);

        // 48 h de calendário, e a meta de tarefas cumprida.
        $this->avancar(47);
        $this->assertSame('leitura_orbital', $m->fresh()->fase);
        $this->avancar(1);
        $this->assertSame('mapeamento', $m->fresh()->fase);

        $this->avancar(24);
        $this->assertSame('validacao', $m->fresh()->fase);
        // Quem contribuiu ganha a entrada do Diário no fim do mapeamento (§2.3).
        $this->assertTrue(DB::table('diario_do_colono')->where('colony_id', $c->id)->where('missao_id', $m->id)->exists());

        $this->avancar(24);
        $this->assertSame('janela_orbital', $m->fresh()->fase);
        $this->assertNotNull(DB::table('luas')->where('chave', 'armstrong')->value('revelada_em'));

        $this->avancar(72);
        $this->assertNotNull($m->fresh()->concluida_em);
    }

    public function test_sem_a_meta_a_leitura_espera(): void
    {
        DB::table('gagarin_settings')->where('id', 1)->update(['meta_de_tarefas' => 5]);
        $c = $this->colonia();
        $this->ativar();
        $m = $this->lunar();
        app(Contribuir::class)->handle($c, $m);

        $this->avancar(100);

        // "Não pode ser concluída instantaneamente" — e nem sem contribuição: calendário E meta.
        $this->assertSame('leitura_orbital', $m->fresh()->fase);
    }

    public function test_a_proxima_lua_so_abre_depois_da_anterior(): void
    {
        DB::table('gagarin_settings')->where('id', 1)->update(['meta_de_tarefas' => 0]);
        $this->colonia();
        $this->ativar();

        $this->avancar(72);
        $this->assertSame(1, MissaoDeReconhecimento::where('tipo', 'lunar')->count());

        // Mapeamento 24 + validação 24 + janela 72, com o tick de hora em hora (em produção, de minuto).
        for ($h = 0; $h < 121; $h++) {
            $this->avancar(1);
        }
        $this->assertNotNull(MissaoDeReconhecimento::where('alvo', 'armstrong')->value('concluida_em'));

        // A próxima lua só abre no boletim seguinte.
        for ($h = 0; $h < 72 && ! MissaoDeReconhecimento::where('alvo', 'tereshkova')->exists(); $h++) {
            $this->avancar(1);
        }
        $this->assertSame('tereshkova', $this->lunar()->alvo);
    }

    // ── a contribuição ──────────────────────────────────────────────────────

    public function test_duas_tarefas_por_dia_e_xp_por_cada(): void
    {
        $c = $this->colonia();
        $this->ativar();
        $m = $this->lunar();
        $xp = (int) $c->fresh()->xp;

        app(Contribuir::class)->handle($c, $m);
        app(Contribuir::class)->handle($c, $m);

        try {
            app(Contribuir::class)->handle($c, $m);
            $this->fail('A terceira tarefa do dia passou.');
        } catch (DomainRuleException $e) {
            $this->assertSame('tarefas_do_dia', $e->codigo);
        }

        $this->assertSame($xp + 2 * (int) DB::table('gagarin_settings')->value('xp_por_tarefa'), (int) $c->fresh()->xp);
        $this->assertSame(2, (int) $m->fresh()->tarefas);

        // O dia de missão vira às 07h (`Janela`): no dia seguinte, mais duas.
        Carbon::setTestNow(now()->addDay());
        app(Contribuir::class)->handle($c, $m);
        $this->assertSame(3, (int) $m->fresh()->tarefas);
    }

    public function test_tarefa_de_pesquisa_pede_laboratorio(): void
    {
        $c = $this->colonia(laboratorio: false);
        $this->ativar();

        $this->expectException(DomainRuleException::class);
        app(Contribuir::class)->handle($c, $this->lunar());
    }

    public function test_fase_sem_tarefa_recusa_a_contribuicao(): void
    {
        DB::table('gagarin_settings')->where('id', 1)->update(['meta_de_tarefas' => 0]);
        $c = $this->colonia();
        $this->ativar();
        $m = $this->lunar();
        $this->avancar(48);
        $this->assertSame('mapeamento', $m->fresh()->fase);

        try {
            app(Contribuir::class)->handle($c, $m->fresh());
            $this->fail('Contribuiu no mapeamento.');
        } catch (DomainRuleException $e) {
            $this->assertSame('fase_sem_tarefa', $e->codigo);
        }
    }

    public function test_pausado_o_relogio_para(): void
    {
        DB::table('gagarin_settings')->where('id', 1)->update(['meta_de_tarefas' => 0]);
        $this->colonia();
        $this->ativar();
        $m = $this->lunar();
        DB::table('gagarin_settings')->where('id', 1)->update(['pausado_em' => now()]);

        $this->avancar(100);

        $this->assertSame('leitura_orbital', $m->fresh()->fase);
    }

    // ── a missão planetária ─────────────────────────────────────────────────

    /** O resultado que o operador escreve no painel: o planeta inteiro, antes da fase pública. */
    private function resultado(array $mudar = []): array
    {
        return array_merge([
            'chave' => 'meridia',
            'nome' => 'Merídia',
            'distancia_minutos' => 600,
            'risco' => 'baixo',
            'risco_chance_bps' => 500,
            'risco_perda_bps' => 1000,
            'exporta' => 'cristais e gelo',
            'importa' => 'biomassa',
            'precos' => [
                'biomassa' => ['compra_micro' => 1000, 'venda_micro' => null],
                'gelo_de_metano' => ['compra_micro' => null, 'venda_micro' => 400_000],
            ],
        ], $mudar);
    }

    private function abrirPlanetaria(array $resultado = [], int $vooHoras = 5): MissaoDeReconhecimento
    {
        return app(AbrirMissaoPlanetaria::class)->handle(
            $this->resultado($resultado), 'Setor Boreal 7', 'gravitacional', 'proxima', $vooHoras,
        );
    }

    /** Três boletins depois de ligado: a condição do §2.5 para uma rota nova. */
    private function tresBoletins(): void
    {
        $this->avancar(72);
        $this->avancar(72);
        $this->avancar(72);
    }

    public function test_a_rota_nova_pede_tres_boletins(): void
    {
        $this->colonia();
        $this->ativar();

        try {
            $this->abrirPlanetaria();
            $this->fail('Abriu rota sem os três boletins.');
        } catch (DomainRuleException $e) {
            $this->assertSame('boletins_insuficientes', $e->codigo);
        }

        $this->tresBoletins();
        $this->assertSame('triangulacao', $this->abrirPlanetaria()->fase);
    }

    public function test_o_voo_respeita_a_faixa_de_distancia(): void
    {
        $this->colonia();
        $this->ativar();
        $this->tresBoletins();

        $this->expectException(DomainRuleException::class);
        $this->abrirPlanetaria(vooHoras: 9); // Próxima é 4–6 h
    }

    public function test_o_resultado_e_lacrado_e_o_nome_so_aparece_na_abertura(): void
    {
        DB::table('gagarin_settings')->where('id', 1)->update(['meta_de_tarefas' => 0]);
        $c = $this->colonia();
        $this->ativar();
        $this->tresBoletins();
        $m = $this->abrirPlanetaria();

        $this->assertSame(64, strlen($m->assinatura));
        $tela = $this->actingAs($c->user)->getJson('/reconhecimento')->assertOk()->json();
        $publica = collect($tela['missoes'])->firstWhere('id', $m->id);
        $this->assertNull($publica['alvo_nome']);
        $this->assertSame('Setor Boreal 7', $publica['setor']);
        $this->assertSame($m->assinatura, $publica['assinatura']);
        $this->assertStringNotContainsString('Merídia', json_encode($tela));
        $this->assertFalse(PlanetaNpc::where('chave', 'meridia')->exists());

        // Triangulação 48 + preparação 24 + voo 5 + validação 24.
        $this->avancar(48);
        $this->assertSame('preparacao', $m->fresh()->fase);
        $this->avancar(24);
        $this->assertSame('voo', $m->fresh()->fase);
        $this->avancar(5);
        $this->assertSame('validacao', $m->fresh()->fase);
        $this->avancar(24);

        $m->refresh();
        $this->assertNotNull($m->concluida_em);
        $planeta = PlanetaNpc::where('chave', 'meridia')->firstOrFail();
        $this->assertTrue($planeta->aberto);
        $this->assertSame($m->id, $planeta->missao_id);
        $this->assertSame(1000, (int) $planeta->precos()->where('resource_type', 'biomassa')->value('compra_micro'));
        $this->assertTrue(News::where('title', 'like', '%Merídia%')->exists());
    }

    public function test_uma_planetaria_por_vez(): void
    {
        $this->colonia();
        $this->ativar();
        $this->tresBoletins();
        $this->abrirPlanetaria();

        $this->expectException(DomainRuleException::class);
        $this->abrirPlanetaria(['chave' => 'outro', 'nome' => 'Outro']);
    }

    public function test_a_rota_seguinte_espera_14_dias_e_tres_boletins_novos(): void
    {
        DB::table('gagarin_settings')->where('id', 1)->update(['meta_de_tarefas' => 0]);
        $this->colonia();
        $this->ativar();
        $this->tresBoletins();
        $this->abrirPlanetaria();
        $this->avancar(48);
        $this->avancar(24);
        $this->avancar(5);
        $this->avancar(24);

        $this->tresBoletins();
        try {
            $this->abrirPlanetaria(['chave' => 'segunda', 'nome' => 'Segunda']);
            $this->fail('A rota seguinte abriu antes dos 14 dias.');
        } catch (DomainRuleException $e) {
            $this->assertSame('rota_recente', $e->codigo);
        }

        $this->avancar(24 * 6);
        $this->assertSame('triangulacao', $this->abrirPlanetaria(['chave' => 'segunda', 'nome' => 'Segunda'])->fase);
    }

    public function test_o_resultado_nao_pode_pagar_acima_do_governo(): void
    {
        $this->colonia();
        $this->ativar();
        $this->tresBoletins();
        DB::table('market_orders')->insert([
            'colony_id' => null, 'resource_type' => 'biomassa', 'side' => 'sell', 'price_micro' => 500,
            'qty' => 10, 'escrow_resource_qty' => 10, 'status' => 'aberta', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(DomainRuleException::class);
        $this->abrirPlanetaria();
    }

    public function test_nas_primeiras_48_horas_uma_viagem_por_colonia(): void
    {
        DB::table('gagarin_settings')->where('id', 1)->update(['meta_de_tarefas' => 0]);
        $c = $this->colonia();
        $c->resources()->updateOrCreate(['resource_type' => 'biomassa'], ['amount' => 1000]);
        $this->ativar();
        $this->tresBoletins();
        $this->abrirPlanetaria(['risco_chance_bps' => 0, 'risco_perda_bps' => 0]);
        $this->avancar(48);
        $this->avancar(24);
        $this->avancar(5);
        $this->avancar(24);
        $planeta = PlanetaNpc::where('chave', 'meridia')->firstOrFail();

        app(FecharContratoDeRota::class)->handle($c->fresh(), $planeta, ['biomassa' => 10], []);

        try {
            app(FecharContratoDeRota::class)->handle($c->fresh(), $planeta, ['biomassa' => 10], []);
            $this->fail('Duas viagens na abertura.');
        } catch (DomainRuleException $e) {
            $this->assertSame('limite_da_abertura', $e->codigo);
        }
    }

    // ── a tela e o painel ───────────────────────────────────────────────────

    public function test_a_tela_mostra_o_gagarin_as_luas_e_o_diario(): void
    {
        $c = $this->colonia();
        $this->ativar();

        $tela = $this->actingAs($c->user)->getJson('/reconhecimento')->assertOk()->json();

        $this->assertTrue($tela['gagarin']['ativo']);
        $this->assertCount(8, $tela['luas']);
        $this->assertSame(2, $tela['tarefas_por_dia']);
        $this->assertSame(0, $tela['tarefas_hoje']);
        $this->assertIsArray($tela['diario']);

        $this->actingAs($c->user)->postJson('/reconhecimento/'.$this->lunar()->id.'/tarefa')->assertCreated();
        $this->assertSame(1, $this->actingAs($c->user)->getJson('/reconhecimento')->json('tarefas_hoje'));
    }

    public function test_a_capital_diz_a_verdade_sobre_o_gagarin(): void
    {
        $c = $this->colonia();
        $this->assertFalse($this->actingAs($c->user)->getJson('/news')->json('gagarin.ativo'));

        $this->ativar();
        $this->assertTrue($this->actingAs($c->user)->getJson('/news')->json('gagarin.ativo'));
    }

    public function test_o_painel_abre_a_missao_planetaria_e_registra_a_pausa(): void
    {
        $this->colonia();
        $this->ativar();
        $this->tresBoletins();
        $dono = Admin::create([
            'name' => 'Dona', 'email' => 'dona@fertways.test',
            'password' => Hash::make('segredo-forte-1234'), 'role' => Admin::DONO,
        ]);

        $this->actingAs($dono, 'admin')->get('/admin/reconhecimento')->assertOk()->assertSee('Gagarin');

        $this->actingAs($dono, 'admin')->post('/admin/reconhecimento/planetaria', [
            'setor' => 'Setor Boreal 7', 'anomalia' => 'espectral', 'faixa' => 'media', 'voo_horas' => 10,
            'chave' => 'meridia', 'nome' => 'Merídia', 'distancia_minutos' => 600, 'risco' => 'baixo',
            'risco_chance' => 5, 'risco_perda' => 10, 'exporta' => 'cristais', 'importa' => 'biomassa',
            'precos' => "biomassa:0.001:\ngelo_de_metano::0.4",
        ])->assertSessionHas('ok');

        $m = MissaoDeReconhecimento::where('tipo', 'planetaria')->firstOrFail();
        $this->assertSame('media', $m->faixa);

        $this->actingAs($dono, 'admin')->post('/admin/reconhecimento/pausa', ['justificativa' => 'manutenção do servidor'])
            ->assertSessionHas('ok');
        $this->assertNotNull(DB::table('gagarin_settings')->value('pausado_em'));
        $this->assertTrue(DB::table('registro_de_reconhecimento')->where('evento', 'pausa')->exists());
    }
}
