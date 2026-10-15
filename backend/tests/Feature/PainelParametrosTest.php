<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\TransportSetting;
use App\Models\WarSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Os parâmetros que o jogo dizia "do painel" e que o painel não tinha (D-258).
 *
 * Cada migration que os criou promete "mudar sem deploy"; sem o campo, a promessa era falsa e o número
 * só mudava por SQL à mão em produção. Os testes guardam as três portas ao mesmo tempo — o campo na
 * tela, a validação e a gravação —, porque o D-206 já tinha aberto duas e esquecido a terceira.
 */
class PainelParametrosTest extends TestCase
{
    use RefreshDatabase;

    private function dono(): Admin
    {
        return Admin::create([
            'name' => 'Dona', 'email' => 'dona@fertways.test',
            'password' => Hash::make('segredo-forte-1234'), 'role' => Admin::DONO,
        ]);
    }

    // ── População, teto de estoque e pesquisa: na aba Operação ──────────────

    public function test_a_operacao_mostra_os_numeros_da_populacao_do_estoque_e_da_pesquisa(): void
    {
        $resposta = $this->actingAs($this->dono(), 'admin')->get('/admin/operacao')->assertOk();

        foreach ([
            'capacidade_base', 'capacidade_fator_milesimos', 'crescimento_bps_hora', 'agua_milli_por_colono_hora',
            'oxigenio_milli_por_colono_hora', 'biomassa_milli_por_colono_hora', 'energia_milli_por_colono_hora',
            'escassez_eficiencia_bps', 'crescimento_min_suprimento_bps', 'zona_operadores_por_nivel',
            'estoque_capacidade_base', 'estoque_capacidade_fator_milesimos', 'estoque_grandfather_folga_bps',
            'vagas_base', 'vagas_por_niveis_de_laboratorio', 'vagas_teto',
        ] as $campo) {
            $resposta->assertSee('name="'.$campo.'"', false);
        }
    }

    public function test_a_populacao_grava_e_audita(): void
    {
        $this->actingAs($this->dono(), 'admin')->post('/admin/operacao/populacao', [
            'capacidade_base' => 12, 'capacidade_fator_milesimos' => 1800, 'crescimento_bps_hora' => 90,
            'agua_milli_por_colono_hora' => 110, 'oxigenio_milli_por_colono_hora' => 130,
            'biomassa_milli_por_colono_hora' => 70, 'energia_milli_por_colono_hora' => 5,
            'escassez_eficiencia_bps' => 4000, 'crescimento_min_suprimento_bps' => 7000,
            'zona_operadores_por_nivel' => "1:2\n2:4\n3:6",
        ])->assertSessionHas('ok');

        $p = DB::table('population_settings')->find(1);
        $this->assertSame(1800, (int) $p->capacidade_fator_milesimos);
        $this->assertSame([1 => 2, 2 => 4, 3 => 6], json_decode($p->zona_operadores_por_nivel, true));
        $this->assertTrue(DB::table('audit_log')->where('acao', 'populacao.parametros')->exists());
    }

    /** O fator abaixo de 1× faria o teto encolher a cada nível: subir o prédio abrigaria menos gente. */
    public function test_a_populacao_recusa_fator_que_encolhe(): void
    {
        $this->actingAs($this->dono(), 'admin')->post('/admin/operacao/populacao', [
            'capacidade_base' => 10, 'capacidade_fator_milesimos' => 900, 'crescimento_bps_hora' => 70,
            'agua_milli_por_colono_hora' => 100, 'oxigenio_milli_por_colono_hora' => 120,
            'biomassa_milli_por_colono_hora' => 80, 'energia_milli_por_colono_hora' => 0,
            'escassez_eficiencia_bps' => 5000, 'crescimento_min_suprimento_bps' => 8000,
            'zona_operadores_por_nivel' => '1:2',
        ])->assertSessionHasErrors('capacidade_fator_milesimos');
    }

    public function test_o_estoque_grava_os_numeros_e_nao_mexe_na_chave(): void
    {
        DB::table('estoque_settings')->where('id', 1)->update(['ativo' => false]);

        $this->actingAs($this->dono(), 'admin')->post('/admin/operacao/estoque', [
            'estoque_capacidade_base' => 12000, 'estoque_capacidade_fator_milesimos' => 1300,
            'estoque_grandfather_folga_bps' => 2500, 'ativo' => 1,
        ])->assertSessionHas('ok');

        $e = DB::table('estoque_settings')->find(1);
        $this->assertSame(12000, (int) $e->capacidade_base);
        $this->assertSame(1300, (int) $e->capacidade_fator_milesimos);
        // ⚠️ Ligar o teto sem o grandfather pararia as colônias (D-191): a chave não é do formulário.
        $this->assertSame(0, (int) $e->ativo);
    }

    public function test_a_pesquisa_grava_e_recusa_teto_abaixo_da_base(): void
    {
        $dono = $this->dono();

        $this->actingAs($dono, 'admin')->post('/admin/operacao/pesquisa', [
            'vagas_base' => 1, 'vagas_por_niveis_de_laboratorio' => 4, 'vagas_teto' => 5,
        ])->assertSessionHas('ok');
        $this->assertSame(5, (int) DB::table('research_settings')->value('vagas_teto'));

        $this->actingAs($dono, 'admin')->post('/admin/operacao/pesquisa', [
            'vagas_base' => 3, 'vagas_por_niveis_de_laboratorio' => 4, 'vagas_teto' => 2,
        ])->assertSessionHasErrors('vagas_teto');
    }

    // ── Upgrade de veículo: na aba Transportes ───────────────────────────────

    private function transporteBase(): array
    {
        $t = TransportSetting::singleton();

        return $t->only([
            'desgaste_bps_por_hora', 'piso_desempenho_bps', 'manutencao_bps_do_custo', 'perda_de_teto_bps',
            'furgao_preco_referencia_micro', 'frete_base_micro', 'frete_por_slot_micro',
        ]);
    }

    public function test_os_transportes_mostram_e_gravam_o_upgrade(): void
    {
        $dono = $this->dono();
        $tela = $this->actingAs($dono, 'admin')->get('/admin/transportes')->assertOk();

        foreach (['upgrade_nivel_maximo', 'upgrade_capacidade_bps_por_nivel', 'upgrade_manutencao_bps_por_nivel',
            'upgrade_custo_bps_do_custo', 'upgrade_segundos_por_nivel'] as $campo) {
            $tela->assertSee('name="'.$campo.'"', false);
        }

        $this->actingAs($dono, 'admin')->post('/admin/transporte', $this->transporteBase() + [
            'upgrade_nivel_maximo' => 6, 'upgrade_capacidade_bps_por_nivel' => 1200,
            'upgrade_manutencao_bps_por_nivel' => 1800, 'upgrade_custo_bps_do_custo' => 3500,
            'upgrade_segundos_por_nivel' => 1800,
        ])->assertSessionHas('ok');

        $t = TransportSetting::singleton()->fresh();
        $this->assertSame(6, (int) $t->upgrade_nivel_maximo);
        $this->assertSame(1800, (int) $t->upgrade_segundos_por_nivel);
    }

    /**
     * D-175: a manutenção sobe MAIS que a capacidade, ou o upgrade vira aumento nominal em vez de
     * escolha. O painel não deixa um dedo escorregado inverter os dois.
     */
    public function test_o_upgrade_recusa_manutencao_que_nao_supera_a_capacidade(): void
    {
        $this->actingAs($this->dono(), 'admin')->post('/admin/transporte', $this->transporteBase() + [
            'upgrade_nivel_maximo' => 5, 'upgrade_capacidade_bps_por_nivel' => 2000,
            'upgrade_manutencao_bps_por_nivel' => 1500, 'upgrade_custo_bps_do_custo' => 4000,
            'upgrade_segundos_por_nivel' => 3600,
        ])->assertSessionHasErrors('upgrade_manutencao_bps_por_nivel');
    }

    // ── A guerra federativa e a Torre de Defesa: na aba Guerra ───────────────

    public function test_a_guerra_mostra_e_grava_os_parametros_federativos(): void
    {
        $dono = $this->dono();
        $tela = $this->actingAs($dono, 'admin')->get('/admin/guerra')->assertOk();

        $campos = [
            'federativa_duracao_horas' => 120, 'federativa_cooldown_horas' => 96,
            'federativa_custo_fert_micro' => 400_000_000, 'federativa_custo_niobio' => 40,
            'neutralidade_carencia_horas' => 12, 'capitulacao_fert_micro' => 300_000_000,
            'torre_defesa_reducao_bps_por_nivel' => 800, 'torre_defesa_reducao_teto_bps' => 6000,
            'rating_k' => 24,
            // D-260: a proporção da decisão 7.
            'operadores_por_unidade' => 2,
        ];

        foreach (array_keys($campos) as $campo) {
            $tela->assertSee('name="'.$campo.'"', false);
        }

        $base = WarSetting::singleton()->only([
            'muralha_bonus_bps', 'torre_bonus_bps', 'bastiao_bonus_bps', 'torre_deteccao_bps_por_nivel',
            'torre_aviso_minutos_por_nivel', 'predador_base_bps', 'predador_por_nivel_bps',
            'predador_min_bps', 'predador_max_bps', 'niobio_preco_micro', 'reparo_bps_do_custo',
        ]);

        $this->actingAs($dono, 'admin')->post('/admin/guerra', $base + $campos)->assertSessionHas('ok');

        $g = WarSetting::singleton()->fresh();
        foreach ($campos as $campo => $valor) {
            $this->assertSame($valor, (int) $g->{$campo}, $campo);
        }
    }
}
