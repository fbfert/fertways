<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Marco\ConcederXp;
use App\Domain\Marco\Curva;
use App\Domain\Missoes\Janela;
use App\Domain\Trade\AcordoSpecs;
use App\Domain\Trade\Reputacao;
use App\Models\Admin;
use App\Models\Colony;
use App\Models\GameEvent;
use App\Models\MilestoneSetting;
use App\Models\User;
use App\Models\XpEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\ErgueEstruturasDaZona;
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
    use ErgueEstruturasDaZona;

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

    // ----------------------------- o que o Marco abre e de onde vem XP (D-247)

    /**
     * ⚠️ O defeito que este teste guarda: o Marco **cobrava e não dizia como se sobe nem para quê**.
     *
     * O cabeçalho mostrava número, título e XP desde o D-75 e nada mais. Deixou de ser detalhe
     * quando o D-241 mediu 7 das 9 colônias humanas travadas no marco, com o planeta fazendo 900 XP
     * por semana: parte da seca é de informação.
     */
    public function test_o_payload_diz_de_onde_vem_xp(): void
    {
        $user = $this->colono();

        $fontes = collect(
            $this->actingAs($user)->getJson('/colony')->assertOk()->json('marco.fontes_de_xp')
        );

        $this->assertNotEmpty($fontes);
        $this->assertContains('obra_concluida', $fontes->pluck('acao')->all());

        // Os valores são os do operador, não uma cópia na tela.
        $this->assertSame(
            (int) MilestoneSetting::singleton()->xp_obra_por_nivel,
            $fontes->firstWhere('acao', 'obra_concluida')['xp'],
        );

        // O teto diário do Mercado vai junto: sem ele o jogador conclui que basta negociar mil vezes.
        $this->assertStringContainsString(
            (string) MilestoneSetting::singleton()->xp_mercado_teto_diario,
            (string) $fontes->firstWhere('acao', 'mercado_executado')['nota'],
        );
    }

    /** ⚠️ Fonte desligada não aparece: anunciar "0 XP por combate" parece defeito, não regra. */
    public function test_a_fonte_desligada_nao_aparece(): void
    {
        $user = $this->colono();
        MilestoneSetting::singleton()->update(['xp_combate_vencido' => 0]);

        $acoes = collect(
            $this->actingAs($user)->getJson('/colony')->assertOk()->json('marco.fontes_de_xp')
        )->pluck('acao');

        $this->assertNotContains('combate_vencido', $acoes->all());
    }

    /** O que ainda está fechado, do mais perto ao mais longe — e nada do que já abriu. */
    public function test_o_payload_lista_o_que_o_marco_ainda_nao_abriu(): void
    {
        $user = $this->colono();

        $lista = collect(
            $this->actingAs($user)->getJson('/colony')->assertOk()->json('marco.proximos_desbloqueios')
        );

        $this->assertNotEmpty($lista);
        $marcoAtual = Curva::marco((int) $user->colony->xp);

        foreach ($lista as $d) {
            $this->assertGreaterThan($marcoAtual, $d['marco'], "{$d['o_que']} já está aberto e aparece como fechado");
            $this->assertSame(Curva::xpDoMarco($d['marco']), $d['xp']);
        }

        $this->assertSame($lista->pluck('marco')->sort()->values()->all(), $lista->pluck('marco')->all());
        $this->assertContains('Ocupar zona neutra', $lista->pluck('o_que')->all());
    }

    /**
     * ⚠️ **O portão do território é o de HOJE.** A régua dele é dobrável por evento (D-232), e a
     * lista sai do `RequisitosDeOcupacao` justamente para dizer o que vale agora — uma cópia aqui
     * anunciaria o marco 20 durante uma Cesta que já o baixou para 1.
     */
    public function test_o_portao_do_territorio_acompanha_o_evento(): void
    {
        $user = $this->colono();
        /*
         * XP baixo de propósito: com os 500 da fundação, a régua reduzida do evento cai ABAIXO do
         * marco desta colônia e o portão some da lista — que é o certo (ela já passa), mas esconde o
         * que este teste quer ver. Aqui os dois lados precisam continuar do lado de fora.
         */
        $this->darXp($user->colony, 100);

        $antes = collect($this->actingAs($user)->getJson('/colony')->json('marco.proximos_desbloqueios'))
            ->firstWhere('o_que', 'Ocupar zona neutra');

        GameEvent::create([
            'slug' => 'cesta', 'nome' => 'Cesta',
            'comeca_em' => now()->subHour(), 'termina_em' => now()->addDay(),
            'status' => 'ativo', 'visibilidade' => 'anunciado', 'escopo' => 'mundo',
            'modificador' => 'ocupacao_marco', 'efeito_bps' => -9_500,
        ]);

        $depois = collect($this->actingAs($user)->getJson('/colony')->json('marco.proximos_desbloqueios'))
            ->firstWhere('o_que', 'Ocupar zona neutra');

        $this->assertLessThan($antes['marco'], $depois['marco'], 'o evento abaixou a régua e a tela não viu');
    }

    // ------------------------------------------- o teto diário do Mercado (D-241)

    /** Quantos lançamentos de XP esta colônia tem por um ato. */
    private function lancamentos(Colony $colony, string $acao): int
    {
        return XpEntry::where('colony_id', $colony->id)->where('acao', $acao)->count();
    }

    /**
     * ⚠️ **O defeito que este teste guarda: uma execução miúda passou a render XP.**
     *
     * O XP do Mercado ficava atrás do piso anti-farm da reputação (5 Fert$, D-43/D-117). Medido nas
     * 13.551 execuções da produção, **100,0% ficavam abaixo dele** — a execução média vale 0,05
     * Fert$, e a regra "comerciar rende XP" disparou **três vezes em 1.507 ordens**. O piso estava
     * cem vezes acima do comércio que existe.
     */
    public function test_a_execucao_miuda_rende_xp(): void
    {
        $c = $this->colono()->colony;
        // A colônia nasce com XP: `CreateColony` concede as 5 essenciais (D-75). Mede-se a DIFERENÇA.
        $antes = (int) $c->xp;

        app(ConcederXp::class)->handle($c->id, 'mercado_executado', 'exec:1:1');

        $this->assertSame(
            (int) MilestoneSetting::singleton()->xp_mercado_executado,
            (int) $c->fresh()->xp - $antes,
            'o valor da troca não decide mais se ela conta',
        );
    }

    /**
     * ⚠️ **E o anti-farm continua de pé, por outro instrumento.**
     *
     * O piso nunca deteve o ataque que o justificava: num mercado **o preço é das partes**, e dois
     * cúmplices anunciam uma unidade por 100 Fert$ para passar dele. O teto não depende de valor —
     * farmar rende no máximo o teto, faça-se uma troca ou mil.
     */
    public function test_o_mercado_para_de_render_no_teto_do_dia(): void
    {
        $c = $this->colono()->colony;
        $teto = (int) MilestoneSetting::singleton()->xp_mercado_teto_diario;
        $porVez = (int) MilestoneSetting::singleton()->xp_mercado_executado;
        $antes = (int) $c->xp;

        foreach (range(1, $teto + 5) as $n) {
            app(ConcederXp::class)->handle($c->id, 'mercado_executado', "exec:{$n}:1");
        }

        $this->assertSame($teto, $this->lancamentos($c, 'mercado_executado'));
        $this->assertSame($teto * $porVez, (int) $c->fresh()->xp - $antes);
    }

    /** O teto é do dia de missão (07h→07h): amanhã a colônia comercia e sobe de novo. */
    public function test_o_teto_do_mercado_vira_com_o_dia_de_missao(): void
    {
        $c = $this->colono()->colony;
        $teto = (int) MilestoneSetting::singleton()->xp_mercado_teto_diario;

        foreach (range(1, $teto) as $n) {
            app(ConcederXp::class)->handle($c->id, 'mercado_executado', "hoje:{$n}");
        }

        $this->travelTo(Janela::proximoDia()->addMinute());
        app(ConcederXp::class)->handle($c->id, 'mercado_executado', 'amanha:1');

        $this->assertSame($teto + 1, $this->lancamentos($c, 'mercado_executado'));
    }

    /** ⚠️ Teto zero é "sem limite", e não "fonte desligada" — quem desliga é o XP por execução. */
    public function test_teto_zero_nao_desliga_a_fonte(): void
    {
        $c = $this->colono()->colony;
        MilestoneSetting::singleton()->update(['xp_mercado_teto_diario' => 0]);

        foreach (range(1, 8) as $n) {
            app(ConcederXp::class)->handle($c->id, 'mercado_executado', "sem_teto:{$n}");
        }

        $this->assertSame(8, $this->lancamentos($c, 'mercado_executado'));
    }

    /** As outras fontes não ganharam teto nenhum: uma obra concluída sempre conta. */
    public function test_o_teto_nao_vaza_para_as_outras_fontes(): void
    {
        $c = $this->colono()->colony;

        $antes = $this->lancamentos($c, 'obra_concluida');

        foreach (range(1, 8) as $n) {
            app(ConcederXp::class)->handle($c->id, 'obra_concluida', "obra:{$n}");
        }

        $this->assertSame($antes + 8, $this->lancamentos($c, 'obra_concluida'));
    }

    // ---------------------------------------------------------------- a curva (BASE×N²)

    public function test_a_curva_e_os_titulos_publicados(): void
    {
        // Todo colono nasce no 1: Sobrevivente é quem chegou.
        $this->assertSame(1, Curva::marco(0));
        $this->assertSame('Sobrevivente', Curva::titulo(1));

        // Os degraus da arbitragem, recalibrados contra o campo no D-223: BASE 50 → 15.
        $this->assertSame(375, Curva::xpDoMarco(5));
        $this->assertSame(1_500, Curva::xpDoMarco(10));
        $this->assertSame(6_000, Curva::xpDoMarco(20));
        $this->assertSame(150_000, Curva::xpDoMarco(100));

        // E os oito nomes do §03/§05, por faixa.
        $this->assertSame('Colono', Curva::titulo(Curva::marco(375)));
        $this->assertSame('Pioneiro', Curva::titulo(Curva::marco(1_500)));
        $this->assertSame('Desbravador', Curva::titulo(Curva::marco(6_000)));
        $this->assertSame('Lenda de Fertways', Curva::titulo(Curva::marco(150_000)));

        // O teto é 100: não existe marco 101, por mais XP que se acumule.
        $this->assertSame(100, Curva::marco(9_999_999));
    }

    /**
     * ⚠️ A âncora do D-223, e ela é do CAMPO — o que este teste guarda não é o 15, é a razão dele.
     *
     * A colônia mais avançada do mundo tinha **6.900 XP** depois de 24 dias, com o XP semanal do
     * planeta caindo 98,5% (69.100 → 1.000). Com BASE 50 ela ficava no marco 11, e o §05 dá o
     * território ao marco 20: o portão pedia **3× o total de vida do melhor jogador**, alimentado por
     * uma fonte que decai (96% do XP é obra concluída, e a colônia só se ergue uma vez).
     *
     * A calibragem põe o jogador mais avançado do mundo **na faixa que o GDD associa a território**.
     * Se alguém mexer na BASE de novo, é este número que precisa continuar fazendo sentido.
     */
    public function test_a_base_poe_o_jogador_mais_avancado_na_faixa_do_territorio(): void
    {
        $marcoDoLider = Curva::marco(6_900);

        $this->assertGreaterThanOrEqual(20, $marcoDoLider, 'o líder do campo tem de alcançar o Desbravador');
        $this->assertSame('Desbravador', Curva::titulo($marcoDoLider));

        // E a mediana medida (2.600) fica abaixo dele: território é conquista, não piso.
        $this->assertLessThan(20, Curva::marco(2_600));
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
        $zona = $this->criarZonaComEstruturas([
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

        $zona = $this->criarZonaComEstruturas([
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
        $this->criarZonaComEstruturas([
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

    public function test_o_painel_de_jogadores_mostra_o_marco(): void
    {
        $user = $this->colono();
        // Derivado da curva, e não o número cru: o que o teste quer é "uma colônia no marco 10", e
        // isso precisa continuar verdade quando a BASE for recalibrada de novo (D-223).
        $this->darXp($user->colony, Curva::xpDoMarco(10));

        $admin = Admin::create([
            'name' => 'Op2', 'email' => 'op2@fertways.test',
            'password' => Hash::make('segredo-forte-1234'), 'role' => Admin::OPERADOR,
        ]);

        // A lista e a ficha: o operador vê o marco sem SQL (auditoria do painel, D-75).
        $this->actingAs($admin, 'admin')->get('/admin/jogadores')
            ->assertOk()->assertSee('Pioneiro');
        $this->actingAs($admin, 'admin')->get("/admin/jogadores/{$user->id}")
            ->assertOk()->assertSee('Marco 10');
    }

    public function test_a_aba_guerra_mostra_os_drones(): void
    {
        $user = $this->colono();
        $colony = $user->colony;
        $colony->buildings()->create(['type' => 'oficina', 'level' => 1, 'slot' => 0]);
        foreach (['componentes_eletronicos' => 200, 'compostos_quimicos' => 100, 'metal_bruto' => 100] as $r => $q) {
            $colony->resources()->where('resource_type', $r)->update(['amount' => $q]);
        }
        $drone = app(\App\Domain\Drone\FabricarDrone::class)->handle($colony, 1);

        $admin = Admin::create([
            'name' => 'Op3', 'email' => 'op3@fertways.test',
            'password' => Hash::make('segredo-forte-1234'), 'role' => Admin::OPERADOR,
        ]);

        $this->actingAs($admin, 'admin')->get('/admin/guerra')
            ->assertOk()
            ->assertSee('Drones de Exploração')
            ->assertSee($drone->plate);
    }

    // ---------------------------------------------------------------- o payload

    public function test_a_colonia_publica_o_marco_no_payload(): void
    {
        $user = $this->colono();
        $this->darXp($user->colony, Curva::xpDoMarco(10));

        $this->actingAs($user)->getJson('/colony')
            ->assertOk()
            ->assertJsonPath('marco.numero', 10)
            ->assertJsonPath('marco.titulo', 'Pioneiro')
            ->assertJsonPath('marco.xp', Curva::xpDoMarco(10))
            ->assertJsonPath('marco.xp_do_proximo', Curva::xpDoMarco(11));
    }
}
