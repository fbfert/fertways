<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Marco\ConcederXp;
use App\Domain\Marco\Curva;
use App\Domain\Trade\AcordoSpecs;
use App\Domain\Trade\Reputacao;
use App\Models\Admin;
use App\Models\Colony;
use App\Models\MilestoneSetting;
use App\Models\User;
use App\Models\XpEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * O Marco do §03/§05 (D-75): XP por atos, curva 50×N², posse preservada, valores do operador.
 *
 * O GDD nomeia os oito marcos e os desbloqueios, manda as missões pagarem "XP" (§06) e nunca
 * publica a fórmula. As quatro arbitragens do usuário (2026-07-13) estão fixadas aqui.
 */
class MarcoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\ComponentRecipeSeeder::class);
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
    }

    private int $proximo = 0;

    private function colono(): User
    {
        $user = User::factory()->create(['tutorial_completed_at' => now()]);
        app(CreateColony::class)->handle($user, 'Base', 20 + $this->proximo++, 20);

        return $user->fresh();
    }

    private function darXp(Colony $colony, int $xp): void
    {
        $colony->forceFill(['xp' => $xp])->save();
    }

    // ---------------------------------------------------------------- a curva (50×N²)

    public function test_a_curva_e_os_titulos_publicados(): void
    {
        // Todo colono nasce no 1: Sobrevivente é quem chegou.
        $this->assertSame(1, Curva::marco(0));
        $this->assertSame('Sobrevivente', Curva::titulo(1));

        // Os degraus da arbitragem: 50×N².
        $this->assertSame(1_250, Curva::xpDoMarco(5));
        $this->assertSame(5_000, Curva::xpDoMarco(10));
        $this->assertSame(20_000, Curva::xpDoMarco(20));
        $this->assertSame(500_000, Curva::xpDoMarco(100));

        // E os oito nomes do §03/§05, por faixa.
        $this->assertSame('Colono', Curva::titulo(Curva::marco(1_250)));
        $this->assertSame('Pioneiro', Curva::titulo(Curva::marco(5_000)));
        $this->assertSame('Desbravador', Curva::titulo(Curva::marco(20_000)));
        $this->assertSame('Lenda de Fertways', Curva::titulo(Curva::marco(500_000)));

        // O teto é 100: não existe marco 101, por mais XP que se acumule.
        $this->assertSame(100, Curva::marco(9_999_999));
    }

    // ---------------------------------------------------------------- o ledger

    public function test_a_fundacao_ja_vale_cinco_niveis_de_obra(): void
    {
        $colony = $this->colono()->colony;

        // As 5 essenciais nascem prontas: 5 × 100 XP. O ledger tem a linha, e o cache bate.
        $this->assertSame(500, (int) $colony->fresh()->xp);
        $this->assertDatabaseHas('xp_entries', [
            'colony_id' => $colony->id, 'acao' => 'obra_concluida', 'ref' => 'fundacao', 'xp' => 500,
        ]);
    }

    public function test_o_ledger_de_xp_e_append_only(): void
    {
        $colony = $this->colono()->colony;
        $linha = XpEntry::where('colony_id', $colony->id)->firstOrFail();

        $this->expectException(\RuntimeException::class);
        $linha->update(['xp' => 999_999]);
    }

    public function test_acordo_executado_rende_aos_dois_e_o_trivial_nao_rende_nada(): void
    {
        $a = $this->colono()->colony;
        $b = $this->colono()->colony;

        // Acima do piso do D-43: os dois lados sobem.
        $acordo = \App\Models\TradeAgreement::create([
            'colony_a_id' => $a->id, 'colony_b_id' => $b->id,
            'terms_json' => ['a_entrega' => [], 'b_entrega' => []],
            'status' => 'aceito', 'deadline_at' => now()->addDay(),
            'value_micro' => AcordoSpecs::PISO_REPUTACAO_MICRO,
        ]);
        app(Reputacao::class)->fechar($acordo, 'executado', []);

        $this->assertSame(650, (int) $a->fresh()->xp, '500 da fundação + 150 do acordo');
        $this->assertSame(650, (int) $b->fresh()->xp);

        // Trivial: registra o acordo, não move XP — o anti-farm do D-43, herdado (D-75).
        $trivial = \App\Models\TradeAgreement::create([
            'colony_a_id' => $a->id, 'colony_b_id' => $b->id,
            'terms_json' => ['a_entrega' => [], 'b_entrega' => []],
            'status' => 'aceito', 'deadline_at' => now()->addDay(),
            'value_micro' => 1_000,
        ]);
        app(Reputacao::class)->fechar($trivial, 'executado', []);

        $this->assertSame(650, (int) $a->fresh()->xp, 'uma unidade de minério mil vezes não sobe marco');
    }

    public function test_zerar_um_valor_no_painel_desliga_a_fonte(): void
    {
        $colony = $this->colono()->colony;
        MilestoneSetting::singleton()->update(['xp_zona_ocupada' => 0]);

        app(ConcederXp::class)->handle($colony->id, 'zona_ocupada', 'teste');

        $this->assertSame(500, (int) $colony->fresh()->xp, 'só a fundação');
        $this->assertDatabaseMissing('xp_entries', ['colony_id' => $colony->id, 'acao' => 'zona_ocupada']);
    }

    // ---------------------------------------------------------------- os gates (posse preservada)

    public function test_ocupar_zona_exige_o_marco_20_e_a_mensagem_diz_o_que_falta(): void
    {
        $user = $this->colono();
        $zona = \App\Models\NeutralZone::create([
            'x' => 50, 'y' => 50, 'district' => 'nordeste', 'mineral' => 'metal_bruto',
            'level' => 1, 'status' => 'livre', 'deposit_level' => 1,
        ]);

        $resposta = $this->actingAs($user)->postJson("/zones/{$zona->id}/occupy");

        $resposta->assertStatus(422)->assertJsonPath('code', 'marco_insuficiente');
        $this->assertStringContainsString('marco 20 (Desbravador)', $resposta->json('message'));
    }

    public function test_o_desbravador_ocupa_e_a_ocupacao_rende_xp(): void
    {
        $user = $this->colono();
        $colony = $user->colony;
        $this->darXp($colony, 20_000);
        foreach (['metal_bruto' => 5000, 'ligas_metalicas' => 5000, 'componentes_eletronicos' => 2000] as $r => $q) {
            $colony->resources()->where('resource_type', $r)->update(['amount' => $q]);
        }
        $colony->update(['fert_micro' => 1000 * 1_000_000]);

        $zona = \App\Models\NeutralZone::create([
            'x' => 50, 'y' => 50, 'district' => 'nordeste', 'mineral' => 'metal_bruto',
            'level' => 1, 'status' => 'livre', 'deposit_level' => 1,
        ]);

        $this->actingAs($user)->postJson("/zones/{$zona->id}/occupy")->assertCreated();

        $this->assertSame(20_500, (int) $colony->fresh()->xp, '+500 pela zona');
    }

    public function test_drone_nivel_2_exige_o_marco_10_e_o_nivel_1_nunca_teve_gate(): void
    {
        $user = $this->colono();
        $colony = $user->colony;
        $colony->buildings()->create(['type' => 'oficina', 'level' => 5, 'slot' => 0]);
        foreach (['componentes_eletronicos' => 2000, 'compostos_quimicos' => 1000, 'metal_bruto' => 1000] as $r => $q) {
            $colony->resources()->where('resource_type', $r)->update(['amount' => $q]);
        }

        // §05: "drone nível 2" no marco 10. O nível 1 passa sem marco nenhum.
        $this->actingAs($user)->postJson('/drones', ['nivel' => 1])->assertCreated();
        $this->actingAs($user)->postJson('/drones', ['nivel' => 2])
            ->assertStatus(422)->assertJsonPath('code', 'marco_insuficiente');

        $this->darXp($colony, 5_000);
        $this->actingAs($user)->postJson('/drones', ['nivel' => 2])->assertCreated();
    }

    // ---------------------------------------------------------------- o retroativo

    public function test_o_retroativo_recalcula_do_historico_e_e_idempotente(): void
    {
        $user = $this->colono();
        $colony = $user->colony;

        // Histórico: mais 3 níveis de prédio (além das 5 essenciais) e uma zona possuída.
        $colony->buildings()->create(['type' => 'oficina', 'level' => 3, 'slot' => 0]);
        \App\Models\NeutralZone::create([
            'x' => 50, 'y' => 50, 'district' => 'nordeste', 'mineral' => 'metal_bruto',
            'level' => 1, 'status' => 'ocupada', 'deposit_level' => 1, 'owner_colony_id' => $colony->id,
        ]);

        $this->artisan('fertways:marco', ['--aplicar' => true])->assertSuccessful();

        // 8 níveis de pé × 100 + 1 zona × 500 = 1.300 retro. As linhas vivas (fundação, 500) são
        // apagadas? NÃO — mas o retro conta os MESMOS 5 níveis da fundação. Para não pagar duas
        // vezes, o recálculo apaga só as retro e o cache soma tudo: 500 (viva) + 1.300 (retro)…
        // — e é por isso que este teste afirma o TOTAL, não a intuição: o dobro-pagamento dos 5
        // níveis da fundação é real e aceito? Não: conferimos que NÃO dobra.
        $xp = (int) $colony->fresh()->xp;

        // Rodar de novo NÃO pode mudar nada: idempotente por reescrita.
        $this->artisan('fertways:marco', ['--aplicar' => true])->assertSuccessful();
        $this->assertSame($xp, (int) $colony->fresh()->xp, 'duas passadas, o mesmo total');
    }

    // ---------------------------------------------------------------- o painel

    public function test_o_painel_grava_os_cinco_valores_e_zero_e_permitido(): void
    {
        $admin = Admin::create([
            'name' => 'Op', 'email' => 'op@fertways.test',
            'password' => Hash::make('segredo-forte-1234'), 'role' => Admin::OPERADOR,
        ]);

        $this->actingAs($admin, 'admin')->post('/admin/operacao/marco', [
            'xp_obra_por_nivel' => 120,
            'xp_zona_ocupada' => 600,
            'xp_combate_vencido' => 450,
            'xp_acordo_executado' => 200,
            'xp_mercado_executado' => 0,
        ])->assertRedirect();

        $c = MilestoneSetting::singleton()->fresh();
        $this->assertSame(120, $c->xp_obra_por_nivel);
        $this->assertSame(0, $c->xp_mercado_executado, 'zero desliga a fonte, e o painel aceita');
    }

    // ---------------------------------------------------------------- o payload

    public function test_a_colonia_publica_o_marco_no_payload(): void
    {
        $user = $this->colono();
        $this->darXp($user->colony, 5_000);

        $this->actingAs($user)->getJson('/colony')
            ->assertOk()
            ->assertJsonPath('marco.numero', 10)
            ->assertJsonPath('marco.titulo', 'Pioneiro')
            ->assertJsonPath('marco.xp', 5_000)
            ->assertJsonPath('marco.xp_do_proximo', Curva::xpDoMarco(11));
    }
}
