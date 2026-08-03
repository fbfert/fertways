<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Eventos\Modificadores;
use App\Domain\Production\ColonyTick;
use App\Models\Colony;
use App\Models\GameEvent;
use App\Models\Ledger;
use App\Models\User;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Motor de Eventos (A2.8).
 *
 * O que estes testes guardam são as três promessas do roadmap: o evento **não escreve no ledger**, o
 * modificador é **reconstruível no passado**, e cancelar **não apaga o histórico**.
 */
class MotorDeEventosTest extends TestCase
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

    private function colonia(): Colony
    {
        $u = User::factory()->create();
        $c = app(CreateColony::class)->handle($u, 'Ev', 10 + $this->proximo++, 20);
        $c->resources()->update(['amount' => 0]);
        $this->erguerPredio($c, 'mina_local', 5);

        return $c->fresh();
    }

    private function evento(array $extra = []): GameEvent
    {
        return GameEvent::create(array_merge([
            'slug' => 'ev'.$this->proximo++,
            'nome' => 'Evento',
            'comeca_em' => now()->subHour(),
            'termina_em' => now()->addDay(),
            'status' => 'ativo',
            'modificador' => Modificadores::PRODUCAO,
            'efeito_bps' => -2_000,
        ], $extra));
    }

    private function estoque(Colony $c, string $r = 'metal_bruto'): int
    {
        return (int) $c->resources()->where('resource_type', $r)->value('amount');
    }

    // ────────────────────────────────────────────── o efeito

    public function test_um_evento_de_producao_negativa_reduz_o_que_a_colonia_produz(): void
    {
        $sem = $this->colonia();
        $com = $this->colonia();

        $this->evento(['escopo' => 'colonia', 'colony_id' => $com->id]);

        app(ColonyTick::class)->handle($sem, now()->addHours(6));
        app(ColonyTick::class)->handle($com, now()->addHours(6));

        $this->assertGreaterThan($this->estoque($com), $this->estoque($sem), 'o evento morde');
        $this->assertGreaterThan(0, $this->estoque($com), 'mas não zera: −20% é −20%');
    }

    public function test_rascunho_nao_vale_nada_no_mundo(): void
    {
        $sem = $this->colonia();
        $com = $this->colonia();

        $this->evento(['escopo' => 'colonia', 'colony_id' => $com->id, 'status' => 'rascunho']);

        app(ColonyTick::class)->handle($sem, now()->addHours(6));
        app(ColonyTick::class)->handle($com, now()->addHours(6));

        $this->assertSame($this->estoque($sem), $this->estoque($com), 'rascunho não muda o mundo');
    }

    /** Dois eventos SOMAM: −30% e −30% dão −60%, e não 0,49×. */
    public function test_os_efeitos_somam(): void
    {
        $c = $this->colonia();
        $this->evento(['escopo' => 'colonia', 'colony_id' => $c->id, 'efeito_bps' => -3_000]);
        $this->evento(['escopo' => 'colonia', 'colony_id' => $c->id, 'efeito_bps' => -3_000]);

        $bps = app(Modificadores::class)->para(
            $c, Modificadores::PRODUCAO, now(), now()->addHour(), 'metal_bruto',
        );

        $this->assertSame(4_000, $bps, 'soma: 10.000 − 3.000 − 3.000');
    }

    /** O piso é −100%: a taxa nunca fica negativa e a "produção" nunca passa a consumir. */
    public function test_o_piso_e_menos_cem_por_cento(): void
    {
        $c = $this->colonia();
        $this->evento(['escopo' => 'colonia', 'colony_id' => $c->id, 'efeito_bps' => -50_000]);

        $this->assertSame(0, app(Modificadores::class)->para(
            $c, Modificadores::PRODUCAO, now(), now()->addHour(), 'metal_bruto',
        ));
    }

    public function test_o_evento_por_recurso_nao_toca_os_outros(): void
    {
        $c = $this->colonia();
        $this->evento([
            'escopo' => 'colonia', 'colony_id' => $c->id, 'resource_type' => 'agua', 'efeito_bps' => -5_000,
        ]);

        $mod = app(Modificadores::class);

        $this->assertSame(5_000, $mod->para($c, Modificadores::PRODUCAO, now(), now()->addHour(), 'agua'));
        $this->assertSame(10_000, $mod->para($c, Modificadores::PRODUCAO, now(), now()->addHour(), 'metal_bruto'));
    }

    public function test_o_evento_de_colonia_nao_atinge_a_vizinha(): void
    {
        $alvo = $this->colonia();
        $outra = $this->colonia();
        $this->evento(['escopo' => 'colonia', 'colony_id' => $alvo->id]);

        $mod = app(Modificadores::class);

        $this->assertSame(8_000, $mod->para($alvo, Modificadores::PRODUCAO, now(), now()->addHour()));
        $this->assertSame(10_000, $mod->para($outra, Modificadores::PRODUCAO, now(), now()->addHour()));
    }

    // ────────────────────────────────────────────── ⚠️ as três promessas do roadmap

    /**
     * ⚠️ **O evento NUNCA escreve no ledger.**
     *
     * Exigência literal do roadmap. O ledger é o registro do que **aconteceu**, e um evento não faz
     * nada acontecer — ele muda a taxa. Se ele lançasse, criaria receita do nada e a telemetria que
     * deriva do ledger (D-163) passaria a mentir.
     */
    public function test_o_evento_nunca_escreve_no_ledger(): void
    {
        $c = $this->colonia();
        $this->evento(['escopo' => 'colonia', 'colony_id' => $c->id]);

        app(ColonyTick::class)->handle($c, now()->addHours(6));

        $this->assertSame(
            0,
            Ledger::where('colony_id', $c->id)->where('ref', 'like', '%evento%')->count(),
            'nenhum lançamento de evento, nem com outro nome',
        );
        $this->assertFalse(
            Ledger::where('colony_id', $c->id)->get()->contains(fn ($l) => str_contains($l->type, 'evento')),
            'e nenhum TIPO de lançamento novo apareceu',
        );
    }

    /**
     * ⚠️ **Reconstruível no passado**, que é o que o "Desde sua última visita" precisa para explicar
     * por que a produção caiu.
     *
     * O intervalo perguntado é inteiramente PASSADO, e o evento já acabou.
     */
    public function test_o_modificador_do_passado_continua_calculavel(): void
    {
        $c = $this->colonia();
        $this->evento([
            'escopo' => 'colonia', 'colony_id' => $c->id,
            'comeca_em' => now()->subDays(5), 'termina_em' => now()->subDays(4),
        ]);

        $bps = app(Modificadores::class)->para(
            $c, Modificadores::PRODUCAO, now()->subDays(5), now()->subDays(4),
        );

        $this->assertSame(8_000, $bps, 'o passado se reconstrói inteiro');
        $this->assertSame(
            10_000,
            app(Modificadores::class)->para($c, Modificadores::PRODUCAO, now(), now()->addHour()),
            'e não vaza para o presente',
        );
    }

    /**
     * ⚠️ **A média ponderada pelo tempo, que é o coração do motor.**
     *
     * Um evento de −20% que cobre METADE do intervalo vale −10% naquele intervalo. É o que torna
     * desnecessário fatiar o tick nas bordas do evento — e é EXATO, porque produção é linear no
     * tempo.
     */
    public function test_o_efeito_e_ponderado_pelo_tempo_que_cobriu(): void
    {
        $c = $this->colonia();
        $de = Carbon::parse('2026-01-01 00:00:00');
        $ate = $de->copy()->addHours(10);

        $this->evento([
            'escopo' => 'colonia', 'colony_id' => $c->id, 'efeito_bps' => -2_000,
            'comeca_em' => $de, 'termina_em' => $de->copy()->addHours(5),
        ]);

        $this->assertSame(
            9_000,
            app(Modificadores::class)->para($c, Modificadores::PRODUCAO, $de, $ate),
            'metade do intervalo com −20% = −10% no intervalo',
        );
    }

    /**
     * ⚠️ **Cancelar encerra o futuro e PRESERVA o passado** — o rollback lógico da §Segurança.
     *
     * Apagar a linha faria o resumo de retorno dizer que a produção caiu sem motivo, e um jogo que
     * não consegue explicar a própria economia perde a confiança do jogador de um jeito que não se
     * recupera.
     */
    public function test_cancelar_encerra_o_futuro_e_preserva_o_passado(): void
    {
        $c = $this->colonia();
        $de = Carbon::parse('2026-01-01 00:00:00');

        $evento = $this->evento([
            'escopo' => 'colonia', 'colony_id' => $c->id, 'efeito_bps' => -2_000,
            'comeca_em' => $de, 'termina_em' => $de->copy()->addHours(10),
        ]);

        $evento->update(['status' => 'cancelado', 'cancelado_em' => $de->copy()->addHours(5)]);

        $mod = app(Modificadores::class);

        $this->assertSame(
            8_000,
            $mod->para($c, Modificadores::PRODUCAO, $de, $de->copy()->addHours(5)),
            'antes do cancelamento, valeu inteiro',
        );
        $this->assertSame(
            10_000,
            $mod->para($c, Modificadores::PRODUCAO, $de->copy()->addHours(5), $de->copy()->addHours(10)),
            'depois, não vale mais nada',
        );
        // E a linha CONTINUA lá: cancelar é rollback lógico, nunca exclusão.
        $this->assertDatabaseHas('game_events', ['id' => $evento->id]);
    }

    // ────────────────────────────────────────────── o comando do operador

    /** Sem `--ativar`, não ativa. A §Segurança pede preview antes de ativar. */
    public function test_o_comando_sem_ativar_deixa_em_rascunho(): void
    {
        $this->artisan('fertways:evento', [
            'slug' => 'seca', '--nome' => 'Seca', '--producao' => -1_500,
        ])->assertSuccessful();

        $this->assertSame('rascunho', GameEvent::where('slug', 'seca')->value('status'));
    }

    public function test_o_comando_com_ativar_ativa(): void
    {
        $this->artisan('fertways:evento', [
            'slug' => 'seca', '--nome' => 'Seca', '--producao' => -1_500, '--ativar' => true,
        ])->assertSuccessful();

        $this->assertSame('ativo', GameEvent::where('slug', 'seca')->value('status'));
    }

    /** Um evento, um modificador: dois numa linha só tornariam impossível cancelar metade. */
    public function test_o_comando_recusa_dois_modificadores(): void
    {
        $this->artisan('fertways:evento', [
            'slug' => 'x', '--producao' => -1_000, '--consumo' => 1_000,
        ])->assertFailed();
    }

    public function test_o_comando_cancela_sem_apagar(): void
    {
        $this->evento(['slug' => 'chuva']);

        $this->artisan('fertways:evento', ['slug' => 'chuva', '--cancelar' => true])->assertSuccessful();

        $e = GameEvent::where('slug', 'chuva')->first();
        $this->assertNotNull($e, 'a linha continua lá');
        $this->assertSame('cancelado', $e->status);
        $this->assertNotNull($e->cancelado_em);
    }

    // ────────────────────────────────────────────── ⚠️ o modificador de guerra (A2.10 §17)

    /**
     * ⚠️ **A distinção que sustenta esta entrega: portão não se mede por média.**
     *
     * O motor calcula média ponderada pelo tempo, e isso é EXATO para produção porque taxa é linear
     * no tempo. Guerra não é taxa: *"há trégua agora?"* é pergunta de instante. Uma trégua cobrindo
     * metade do intervalo viraria "meio bloqueada", que não significa coisa alguma — e o pior é que
     * seria um número plausível, que ninguém desconfiaria.
     *
     * Por isso `para()` **recusa** em vez de converter em silêncio.
     */
    public function test_para_recusa_medir_um_modificador_pontual(): void
    {
        $this->expectException(\LogicException::class);

        app(Modificadores::class)->para(
            null, Modificadores::GUERRA_DECLARACAO, now(), now()->addHour(),
        );
    }

    /** A trégua fecha o portão: −10000 é −100%, e o piso do motor é zero. */
    public function test_a_tregua_bloqueia_a_declaracao_de_guerra(): void
    {
        $mod = app(Modificadores::class);

        $this->assertFalse($mod->guerraBloqueada(null, now()), 'sem evento, ninguém está em trégua');

        $this->evento([
            'modificador' => Modificadores::GUERRA_DECLARACAO,
            'efeito_bps' => -10_000,
        ]);

        $this->assertTrue($mod->guerraBloqueada(null, now()));
        $this->assertSame(0, $mod->em(null, Modificadores::GUERRA_DECLARACAO, now()));
    }

    /**
     * ⚠️ E a trégua vale por INSTANTE, não pelo intervalo.
     *
     * Antes de começar não bloqueia; durante, bloqueia; depois de acabar, não bloqueia mais. É a
     * prova de que não há média nenhuma acontecendo por baixo.
     */
    public function test_a_tregua_vale_por_instante_e_nao_pelo_intervalo(): void
    {
        $inicio = Carbon::parse('2026-03-01 12:00:00');

        $this->evento([
            'modificador' => Modificadores::GUERRA_DECLARACAO,
            'efeito_bps' => -10_000,
            'comeca_em' => $inicio,
            'termina_em' => $inicio->copy()->addHours(4),
        ]);

        $mod = app(Modificadores::class);

        $this->assertFalse($mod->guerraBloqueada(null, $inicio->copy()->subMinute()), 'antes, não');
        $this->assertTrue($mod->guerraBloqueada(null, $inicio->copy()->addHours(2)), 'durante, sim');
        $this->assertFalse($mod->guerraBloqueada(null, $inicio->copy()->addHours(5)), 'depois, não');
    }

    /** Cancelar encerra a trégua no instante do cancelamento — o rollback lógico da A2.8 vale aqui. */
    public function test_cancelar_a_tregua_reabre_a_declaracao(): void
    {
        $inicio = Carbon::parse('2026-03-01 12:00:00');

        $e = $this->evento([
            'modificador' => Modificadores::GUERRA_DECLARACAO,
            'efeito_bps' => -10_000,
            'comeca_em' => $inicio,
            'termina_em' => $inicio->copy()->addHours(10),
        ]);
        $e->update(['status' => 'cancelado', 'cancelado_em' => $inicio->copy()->addHours(3)]);

        $mod = app(Modificadores::class);

        $this->assertTrue($mod->guerraBloqueada(null, $inicio->copy()->addHour()), 'antes do cancelamento');
        $this->assertFalse($mod->guerraBloqueada(null, $inicio->copy()->addHours(4)), 'depois, reabriu');
    }

    /** O custo de mobilização é multiplicador, e o piso continua sendo zero. */
    public function test_o_custo_de_guerra_e_multiplicador(): void
    {
        $this->evento([
            'modificador' => Modificadores::GUERRA_CUSTO,
            'efeito_bps' => 5_000,
        ]);

        $this->assertSame(
            15_000,
            app(Modificadores::class)->em(null, Modificadores::GUERRA_CUSTO, now()),
            'declarar passa a custar 150% do normal',
        );
    }

    /** Rascunho não vale nada, nem para guerra. */
    public function test_tregua_em_rascunho_nao_bloqueia(): void
    {
        $this->evento([
            'modificador' => Modificadores::GUERRA_DECLARACAO,
            'efeito_bps' => -10_000,
            'status' => 'rascunho',
        ]);

        $this->assertFalse(app(Modificadores::class)->guerraBloqueada(null, now()));
    }

    public function test_o_comando_cria_uma_tregua(): void
    {
        $this->artisan('fertways:evento', [
            'slug' => 'tregua', '--nome' => 'Trégua',
            '--guerra-declaracao' => -10_000, '--ativar' => true,
        ])->assertSuccessful();

        $this->assertTrue(app(Modificadores::class)->guerraBloqueada(null, now()));
    }

    // ────────────────────────────────────────────── segredo

    /** `segredo` e `visibilidade` são afirmações separadas: quem quer segredo diz duas vezes. */
    public function test_segredo_e_visibilidade_sao_travas_independentes(): void
    {
        $this->assertFalse($this->evento(['segredo' => true])->visivelAoJogador());
        $this->assertFalse($this->evento(['visibilidade' => 'secreto'])->visivelAoJogador());
        $this->assertTrue($this->evento()->visivelAoJogador());
    }
}
