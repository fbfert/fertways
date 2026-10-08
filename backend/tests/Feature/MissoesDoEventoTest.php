<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Missoes\Atribuir;
use App\Domain\Missoes\Progresso;
use App\Models\Admin;
use App\Models\Colony;
use App\Models\GameEvent;
use App\Models\MissionAssignment;
use App\Models\MissionTemplate;
use App\Models\User;
use App\Models\XpEntry;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * As missões que um evento traz (A2.8 §12.2 "missões relacionadas"; D-250).
 */
class MissoesDoEventoTest extends TestCase
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
        $c = app(CreateColony::class)->handle(User::factory()->create(), 'Base', 20 + $this->proximo++, 20);
        // Só as do evento: os testes daqui contam linhas exatas.
        MissionAssignment::where('colony_id', $c->id)->delete();

        return $c->fresh();
    }

    private function molde(string $categoria = 'eventuais', array $extra = []): MissionTemplate
    {
        return MissionTemplate::create(array_merge([
            'chave' => 'molde'.$this->proximo++, 'categoria' => $categoria, 'titulo' => 'Caça ao tesouro',
            'descricao' => 'Compre uma peça da Endurance.', 'acao' => 'comprar_item_endurance',
            'meta' => 1, 'recompensa_xp' => 50, 'ativa' => true,
        ], $extra));
    }

    private function evento(array $missoes, array $extra = []): GameEvent
    {
        return GameEvent::create(array_merge([
            'slug' => 'ev'.$this->proximo++, 'nome' => 'Festival da Endurance', 'status' => 'ativo',
            'comeca_em' => now()->subHour(), 'termina_em' => now()->addDays(2), 'missoes' => $missoes,
        ], $extra));
    }

    private function doEvento(Colony $c)
    {
        return MissionAssignment::where('colony_id', $c->id)->where('categoria', 'eventuais')->get();
    }

    // ── a entrega ───────────────────────────────────────────────────────────

    public function test_o_evento_entrega_cada_missao_uma_vez_com_prazo_no_fim_dele(): void
    {
        $m = $this->molde();
        $ev = $this->evento([$m->id]);
        $c = $this->colonia();

        $this->assertSame(1, app(Atribuir::class)->garantirEventos($c));
        $this->assertSame(0, app(Atribuir::class)->garantirEventos($c), 'a segunda porta colide no índice');

        $linha = $this->doEvento($c)->sole();
        $this->assertSame($ev->id, (int) $linha->game_event_id);
        $this->assertSame($ev->termina_em->getTimestamp(), $linha->expires_at->getTimestamp());
    }

    public function test_rascunho_encerrado_e_cancelado_nao_entregam(): void
    {
        $m = $this->molde();
        $this->evento([$m->id], ['status' => 'rascunho']);
        $this->evento([$m->id], ['comeca_em' => now()->subDays(3), 'termina_em' => now()->subDay()]);
        $this->evento([$m->id], ['status' => 'cancelado', 'cancelado_em' => now()->subMinute()]);

        $this->assertSame(0, app(Atribuir::class)->garantirEventos($this->colonia()));
    }

    /** Uma diária listada num evento chegaria por dois caminhos, com dois prazos. */
    public function test_so_molde_eventual_chega_pelo_evento(): void
    {
        $this->evento([$this->molde('diaria')->id]);

        $this->assertSame(0, app(Atribuir::class)->garantirEventos($this->colonia()));
    }

    public function test_evento_de_colonia_so_chega_a_ela(): void
    {
        $alvo = $this->colonia();
        $outra = $this->colonia();
        $this->evento([$this->molde()->id], ['escopo' => 'colonia', 'colony_id' => $alvo->id]);

        $this->assertSame(1, app(Atribuir::class)->garantirEventos($alvo));
        $this->assertSame(0, app(Atribuir::class)->garantirEventos($outra));
    }

    /** O mesmo molde pode voltar num evento futuro — o índice é por evento. */
    public function test_o_mesmo_molde_volta_noutro_evento(): void
    {
        $m = $this->molde();
        $this->evento([$m->id]);
        $this->evento([$m->id]);

        $this->assertSame(2, app(Atribuir::class)->garantirEventos($this->colonia()));
    }

    // ── o ciclo ─────────────────────────────────────────────────────────────

    public function test_a_missao_do_evento_conclui_pelo_motor_de_sempre(): void
    {
        $this->evento([$this->molde()->id]);
        $c = $this->colonia();
        app(Atribuir::class)->garantirEventos($c);

        app(Progresso::class)->registrar($c->id, 'comprar_item_endurance');

        $this->assertSame('concluida', $this->doEvento($c)->sole()->status);
    }

    public function test_cancelar_vence_as_abertas_e_preserva_as_concluidas(): void
    {
        $aberta = $this->molde();
        $feita = $this->molde('eventuais', ['acao' => 'mercado_executado']);
        $ev = $this->evento([$aberta->id, $feita->id], ['slug' => 'festival']);
        $c = $this->colonia();
        app(Atribuir::class)->garantirEventos($c);
        app(Progresso::class)->registrar($c->id, 'mercado_executado');

        $this->artisan('fertways:evento', ['slug' => 'festival', '--cancelar' => true])->assertSuccessful();

        $linhas = $this->doEvento($c)->keyBy('template_id');
        $this->assertFalse($linhas[$aberta->id]->expires_at->isFuture(), 'a aberta venceu no cancelamento');
        $this->assertSame('concluida', $linhas[$feita->id]->status, 'a concluída ficou');
        $this->assertSame(0, MissionAssignment::ativa()->where('game_event_id', $ev->id)->count());
    }

    /** De cinco em cinco minutos, mas só a quem agiu nos últimos 7 dias (a régua do D-243). */
    public function test_o_comando_de_cinco_minutos_entrega_a_quem_joga(): void
    {
        $this->evento([$this->molde()->id]);
        $ativa = $this->colonia();
        $parada = $this->colonia();
        XpEntry::create(['colony_id' => $ativa->id, 'acao' => 'obra_concluida', 'xp' => 10, 'ref' => 't', 'created_at' => now()]);
        // Fundar já dá XP; a parada é quem não age há um mês. (DB cru: o ledger de XP é append-only.)
        DB::table('xp_entries')->where('colony_id', $parada->id)
            ->update(['created_at' => now()->subMonth()]);

        $this->artisan('fertways:eventos-entregar')->assertSuccessful();

        $this->assertCount(1, $this->doEvento($ativa));
        $this->assertCount(0, $this->doEvento($parada), 'quem não joga recebe pela tela, quando voltar');
    }

    // ── a tela ──────────────────────────────────────────────────────────────

    public function test_a_tela_de_missoes_mostra_a_do_evento_com_o_nome_dele(): void
    {
        $this->evento([$this->molde()->id]);
        $c = $this->colonia();

        $r = $this->actingAs($c->user)->getJson('/missions')->assertOk();
        $linha = collect($r->json('missoes'))->firstWhere('categoria', 'eventuais');

        $this->assertNotNull($linha, 'a tela entrega quem não estava no comando');
        $this->assertSame('Festival da Endurance', $linha['evento']);
    }

    public function test_evento_secreto_nao_vaza_o_nome_pela_missao(): void
    {
        $this->evento([$this->molde()->id], ['segredo' => true, 'visibilidade' => 'secreto']);
        $c = $this->colonia();

        $linha = collect($this->actingAs($c->user)->getJson('/missions')->json('missoes'))->firstWhere('categoria', 'eventuais');

        $this->assertNotNull($linha);
        $this->assertNull($linha['evento']);
    }

    public function test_evento_com_missao_favorece_o_jogador(): void
    {
        $this->assertTrue((new GameEvent(['missoes' => [1]]))->favoreceOJogador());
    }

    // ── o operador ──────────────────────────────────────────────────────────

    public function test_o_comando_aceita_missoes_pela_chave(): void
    {
        $m = $this->molde('eventuais', ['chave' => 'caca_ao_tesouro']);

        $this->artisan('fertways:evento', ['slug' => 'caca', '--missoes' => 'caca_ao_tesouro'])->assertSuccessful();
        $this->assertSame([$m->id], GameEvent::where('slug', 'caca')->value('missoes'));

        $this->molde('diaria', ['chave' => 'uma_diaria']);
        $this->artisan('fertways:evento', ['slug' => 'errado', '--missoes' => 'uma_diaria'])->assertFailed();
    }

    public function test_o_painel_grava_evento_so_com_missoes_e_recusa_molde_que_nao_e_eventual(): void
    {
        $dono = Admin::create([
            'name' => 'Dona', 'email' => 'dona@fertways.test',
            'password' => Hash::make('segredo-forte-1234'), 'role' => Admin::DONO,
        ]);
        $base = ['nome' => 'Festival', 'dias' => 3, 'visibilidade' => 'anunciado', 'modificador' => '', 'efeito_bps' => ''];

        $this->actingAs($dono, 'admin')
            ->post('/admin/eventos', $base + ['slug' => 'so-missao', 'missoes' => [$this->molde()->id]])
            ->assertSessionHasNoErrors();
        $this->assertNotNull(GameEvent::where('slug', 'so-missao')->value('missoes'));

        $this->actingAs($dono, 'admin')
            ->post('/admin/eventos', $base + ['slug' => 'com-diaria', 'missoes' => [$this->molde('diaria')->id]])
            ->assertSessionHasErrors('missoes.0');
    }
}
