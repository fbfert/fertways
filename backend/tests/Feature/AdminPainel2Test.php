<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Models\Admin;
use App\Models\AuditEntry;
use App\Models\News;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A segunda leva do painel (2026-07-13): a aba Reputações, a busca de conciliador, a paginação, o
 * card de enviar recursos, a aba Notícias com estado, a frota com placa, e a realocação que deixou
 * de ser um clique no escuro.
 *
 * O que se afirma aqui é, sobretudo, o que **não se vê olhando a tela**: que ocultar uma notícia a
 * tira do mural do COLONO (e não só da lista do painel), que tudo deixa rastro na auditoria, e que a
 * realocação em massa passou a exigir o dono — porque até aqui não exigia, e isso era um descuido.
 */
class AdminPainel2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
        $this->seed(\Database\Seeders\TransportSettingSeeder::class);
    }

    private function dono(): Admin
    {
        return Admin::create([
            'name' => 'Dona', 'email' => 'dona@fertways.test',
            'password' => Hash::make('segredo-forte-1234'), 'role' => Admin::DONO,
        ]);
    }

    private function operador(): Admin
    {
        return Admin::create([
            'name' => 'Op', 'email' => 'op@fertways.test',
            'password' => Hash::make('segredo-forte-1234'), 'role' => Admin::OPERADOR,
        ]);
    }

    private function noticia(array $extra = []): News
    {
        return News::create(array_merge([
            'title' => 'Boletim orbital',
            'body' => 'O Gagarin identificou uma anomalia no setor 7.',
            'kind' => 'comunicado',
            'author' => 'Administração Pública',
            'published_at' => now()->subHour(),
            'created_at' => now()->subHour(),
        ], $extra));
    }

    // ── as abas abrem ───────────────────────────────────────────────────────────────────────────

    public function test_a_aba_de_noticias_existe_e_abre(): void
    {
        $this->noticia();

        $this->actingAs($this->operador(), 'admin')
            ->get('/admin/noticias')
            ->assertOk()
            ->assertSee('Boletim orbital')
            ->assertSee('Publicar comunicado');
    }

    /** A aba chama-se **Reputações**: há três ministérios no jogo, e "Ministério" sozinho não dizia qual. */
    public function test_a_aba_do_ministerio_chama_se_reputacoes(): void
    {
        $this->actingAs($this->operador(), 'admin')
            ->get('/admin/ministerio')
            ->assertOk()
            ->assertSee('Reputações')
            ->assertDontSee('Justiça');   // esse ministério não existe em Fertways
    }

    // ── notícias: o estado ──────────────────────────────────────────────────────────────────────

    /**
     * **O teste que mais importa desta leva.** Ocultar tem de tirar a notícia do mural do COLONO — não
     * só da lista do painel. Um "ocultar" que só esconde do operador é um botão que mente.
     */
    public function test_ocultar_tira_a_noticia_do_mural_do_colono(): void
    {
        $n = $this->noticia();
        $colono = User::factory()->create();
        app(CreateColony::class)->handle($colono, 'Base', 20, 20);

        // Antes: o colono a vê.
        $this->actingAs($colono)->getJson('/news')
            ->assertOk()->assertJsonCount(1, 'noticias');

        $this->actingAs($this->operador(), 'admin')
            ->post("/admin/noticias/{$n->id}/ocultar")
            ->assertRedirect();

        // Depois: sumiu do mural — e continua existindo.
        $this->actingAs($colono)->getJson('/news')
            ->assertOk()->assertJsonCount(0, 'noticias');

        $this->assertNotNull($n->fresh()->hidden_at);
        $this->assertSame('oculta', $n->fresh()->estado());
    }

    /** Ocultar é REVERSÍVEL: é o botão do erro de redação, não o do fim de vida. */
    public function test_reexibir_devolve_a_noticia_ao_mural(): void
    {
        $n = $this->noticia(['hidden_at' => now()]);

        $this->actingAs($this->operador(), 'admin')->post("/admin/noticias/{$n->id}/ocultar");

        $this->assertNull($n->fresh()->hidden_at);
        $this->assertSame('no mural', $n->fresh()->estado());
    }

    /** Inativar é FIM DE VIDA — e preserva o histórico, em vez de destruí-lo como apagar faria. */
    public function test_inativar_arquiva_sem_apagar(): void
    {
        $n = $this->noticia();

        $this->actingAs($this->operador(), 'admin')->post("/admin/noticias/{$n->id}/inativar");

        $n->refresh();
        $this->assertNotNull($n->inactive_at);
        $this->assertSame('inativa', $n->estado());
        // Continua existindo: a prova de que a coisa foi dita, e quando, não se perde.
        $this->assertDatabaseHas('news', ['id' => $n->id, 'title' => 'Boletim orbital']);
    }

    /** Inativa vence oculta: fim de vida é mais forte que "espere um pouco". */
    public function test_inativa_vence_oculta_no_rotulo(): void
    {
        $n = $this->noticia(['hidden_at' => now(), 'inactive_at' => now()]);

        $this->assertSame('inativa', $n->estado());
    }

    /** A notícia AGENDADA (data futura) não vaza para o mural no instante em que é escrita. */
    public function test_a_noticia_agendada_nao_aparece_ao_colono(): void
    {
        $this->noticia(['published_at' => now()->addDay()]);

        $colono = User::factory()->create();
        app(CreateColony::class)->handle($colono, 'Base', 20, 20);

        $this->actingAs($colono)->getJson('/news')->assertOk()->assertJsonCount(0, 'noticias');
    }

    public function test_editar_reescreve_e_guarda_o_antes_na_auditoria(): void
    {
        $n = $this->noticia();

        $this->actingAs($this->operador(), 'admin')
            ->post("/admin/noticias/{$n->id}/editar", [
                'titulo' => 'Boletim orbital — corrigido',
                'corpo' => 'Era o setor 8.',
                'autor' => 'Central de Pesquisas',
            ])
            ->assertRedirect();

        $n->refresh();
        $this->assertSame('Boletim orbital — corrigido', $n->title);
        $this->assertNotNull($n->updated_at, 'o rastro de que o texto foi reescrito');

        // A auditoria guarda o ANTES: é a única defesa contra a acusação de reescrever a história.
        $ato = AuditEntry::where('acao', 'noticia.editar')->latest('id')->first();
        $this->assertNotNull($ato);
        $this->assertStringContainsString('Boletim orbital', $ato->resumo);
        $this->assertStringContainsString('corrigido', $ato->resumo);
    }

    public function test_os_filtros_das_noticias_funcionam(): void
    {
        $this->noticia(['title' => 'No mural']);
        $this->noticia(['title' => 'Escondida', 'hidden_at' => now()]);
        $this->noticia(['title' => 'Velha', 'inactive_at' => now()]);

        $admin = $this->operador();

        $this->actingAs($admin, 'admin')->get('/admin/noticias?estado=mural')
            ->assertSee('No mural')->assertDontSee('Escondida');

        $this->actingAs($admin, 'admin')->get('/admin/noticias?estado=oculta')
            ->assertSee('Escondida')->assertDontSee('No mural');

        $this->actingAs($admin, 'admin')->get('/admin/noticias?estado=inativa')
            ->assertSee('Velha')->assertDontSee('No mural');

        $this->actingAs($admin, 'admin')->get('/admin/noticias?q=Escond')
            ->assertSee('Escondida')->assertDontSee('No mural');
    }

    // ── conciliadores: a busca ──────────────────────────────────────────────────────────────────

    /**
     * A nomeação era um `<select>` com **todos** os jogadores, e depois um nickname digitado às cegas.
     * Agora é busca — e ela não devolve nada até que se procure algo.
     */
    public function test_a_busca_de_conciliador_so_lista_quem_casa_e_nao_lista_quem_ja_e(): void
    {
        $achavel = User::factory()->create(['name' => 'Marina Costa', 'nickname' => 'marina']);
        User::factory()->create(['name' => 'Outro Alguém', 'nickname' => 'outro']);
        $jaEh = User::factory()->create(['name' => 'Marina Antiga', 'nickname' => 'marina2',
            'conciliador_desde' => now()]);

        $admin = $this->operador();

        // Sem busca, nenhum candidato na tela.
        $this->actingAs($admin, 'admin')->get('/admin/ministerio')
            ->assertOk()->assertDontSee('Marina Costa');

        $this->actingAs($admin, 'admin')->get('/admin/ministerio?qc=Marina')
            ->assertOk()
            ->assertSee('Marina Costa')
            ->assertDontSee('Outro Alguém')
            // Quem JÁ é conciliador não aparece como candidato — nomeá-lo de novo não faz sentido.
            ->assertDontSee('Marina Antiga');

        $this->assertNotNull($jaEh->conciliador_desde);
    }

    // ── jogadores: paginação ────────────────────────────────────────────────────────────────────

    /** 50 por página, e os controles aparecem — antes o `links()` saía sem estilo e ninguém os via. */
    public function test_jogadores_pagina_de_50_em_50(): void
    {
        User::factory()->count(55)->create();

        $this->actingAs($this->operador(), 'admin')
            ->get('/admin/jogadores')
            ->assertOk()
            ->assertSee('próxima')          // o controle existe e é visível
            ->assertSee('de 55');           // e diz de quantos
    }

    // ── transportes: a frota com placa ──────────────────────────────────────────────────────────

    public function test_a_frota_do_planeta_lista_a_placa_e_a_busca_por_ela(): void
    {
        $colono = User::factory()->create();
        $colonia = app(CreateColony::class)->handle($colono, 'Base', 20, 20);

        // A fundação já dá um Furgão; damos-lhe placa, como o backfill do D-60 faz.
        $v = $colonia->vehicles()->first();
        $v->update(['plate' => 'FW-00042-F']);

        $admin = $this->operador();

        $this->actingAs($admin, 'admin')->get('/admin/transportes')
            ->assertOk()->assertSee('FW-00042-F')->assertSee('Frota do planeta');

        $this->actingAs($admin, 'admin')->get('/admin/transportes?placa=00042')
            ->assertOk()->assertSee('FW-00042-F');

        $this->actingAs($admin, 'admin')->get('/admin/transportes?placa=99999')
            ->assertOk()->assertSee('Nenhum veículo com placa');
    }

    // ── operação: a realocação ──────────────────────────────────────────────────────────────────

    /**
     * **Não há realocação em massa pelo painel** — decisão do usuário (2026-07-13).
     *
     * Existiu um botão "Realocar founders" que movia todas as colônias do jogo de uma vez. Ele era a
     * ferramenta de uma migração histórica (D-51) e ficara pendurado na tela de Operação, ao lado do
     * "Disparar tick", como se fosse coisa que se faz. Realocar é ato sobre UM jogador escolhido.
     *
     * Este teste existe para que ninguém o traga de volta sem querer: a rota não existe mais, e a tela
     * não a oferece.
     */
    public function test_nao_ha_realocacao_em_massa_pelo_painel(): void
    {
        $this->assertFalse(
            \Illuminate\Support\Facades\Route::has("admin.realocar"),
            "a rota da realocação em massa foi retirada de propósito",
        );

        $this->actingAs($this->dono(), "admin")
            ->post("/admin/realocar-founders", ["confirmacao" => "REALOCAR"])
            ->assertNotFound();
    }

    /** A tela de Operação oferece a realocação PONTUAL, e nenhuma outra. */
    public function test_a_operacao_so_oferece_a_realocacao_pontual(): void
    {
        $colono = User::factory()->create(["nickname" => "fulano"]);
        app(CreateColony::class)->handle($colono, "Colônia Distante", 30, 30);

        $this->actingAs($this->dono(), "admin")
            ->get("/admin/operacao")
            ->assertOk()
            ->assertSee("Realocar uma colônia")
            ->assertSee("fulano")               // escolhe-se o jogador
            ->assertSee("(30, 30)")             // e vê-se de onde ele sai
            ->assertDontSee("Realocar para slots de founder")
            ->assertDontSee("Aplicar o plano");
    }
    /** A realocação manual: esta colônia, para este x,y. Reusa o RealocarColonia da ficha (D-61). */
    public function test_a_realocacao_manual_move_uma_colonia_e_audita(): void
    {
        $colono = User::factory()->create();
        $colonia = app(CreateColony::class)->handle($colono, 'Base', 20, 20);

        $this->actingAs($this->dono(), 'admin')
            ->post('/admin/realocar-manual', [
                'colony_id' => $colonia->id,
                'x' => 25, 'y' => 25,
                'motivo' => 'pedido do colono',
                'confirmacao' => 'REALOCAR',
            ])
            ->assertRedirect();

        $colonia->refresh();
        $this->assertSame(25, $colonia->x);
        $this->assertSame(25, $colonia->y);

        // O RealocarColonia audita por dentro, com o antes e o depois.
        $this->assertTrue(AuditEntry::query()->where('alvo', "colony:{$colonia->id}")->exists());
    }

    public function test_a_realocacao_manual_exige_a_palavra(): void
    {
        $colono = User::factory()->create();
        $colonia = app(CreateColony::class)->handle($colono, 'Base', 20, 20);

        $this->actingAs($this->dono(), 'admin')
            ->post('/admin/realocar-manual', [
                'colony_id' => $colonia->id,
                'x' => 25, 'y' => 25,
                'motivo' => 'sem a palavra',
                'confirmacao' => 'ok',
            ])
            ->assertSessionHas('erro');

        $this->assertSame(20, $colonia->fresh()->x);
    }

    // ── economia: o card próprio ────────────────────────────────────────────────────────────────

    public function test_enviar_recursos_e_um_card_com_nome(): void
    {
        $this->seed(\Database\Seeders\TreasurySeeder::class);

        $this->actingAs($this->operador(), 'admin')
            ->get('/admin/economia')
            ->assertOk()
            ->assertSee('Enviar recursos')
            // E as notícias saíram daqui: viraram aba própria.
            ->assertDontSee('Central de Notícias');
    }

    // ── transportes: os cinco parâmetros gravam (D-73) ─────────────────────────────────────────

    /**
     * O formulário nunca tinha teste — e a lição do D-70 é que um campo fora do `$fillable` faria a
     * tela dizer "atualizado" e descartar o valor **em silêncio**. Os sete gravam: os quatro do
     * D-60, a âncora do Furgão (D-73) e os dois do frete público (D-76).
     */
    public function test_o_painel_grava_os_sete_parametros_do_transporte(): void
    {
        $this->actingAs($this->operador(), 'admin')
            ->post('/admin/transporte', [
                'desgaste_bps_por_hora' => 60,
                'piso_desempenho_bps' => 3_000,
                'manutencao_bps_do_custo' => 1_500,
                'perda_de_teto_bps' => 400,
                'furgao_preco_referencia_micro' => 80_000_000,
                'frete_base_micro' => 1_500_000,
                'frete_por_slot_micro' => 30_000,
            ])->assertRedirect();

        $c = \App\Models\TransportSetting::singleton()->fresh();
        $this->assertSame(60, $c->desgaste_bps_por_hora);
        $this->assertSame(3_000, $c->piso_desempenho_bps);
        $this->assertSame(1_500, $c->manutencao_bps_do_custo);
        $this->assertSame(400, $c->perda_de_teto_bps);
        $this->assertSame(80_000_000, $c->furgao_preco_referencia_micro);
        $this->assertSame(1_500_000, $c->frete_base_micro);
        $this->assertSame(30_000, $c->frete_por_slot_micro);
    }

    /** Zero reabriria a lavagem por baixo: teto 0 recusaria TODO anúncio de Furgão. O painel recusa. */
    public function test_a_referencia_do_furgao_nao_aceita_zero(): void
    {
        $this->actingAs($this->operador(), 'admin')
            ->post('/admin/transporte', [
                'desgaste_bps_por_hora' => 50,
                'piso_desempenho_bps' => 2_500,
                'manutencao_bps_do_custo' => 1_000,
                'perda_de_teto_bps' => 500,
                'furgao_preco_referencia_micro' => 0,
            ])->assertSessionHasErrors('furgao_preco_referencia_micro');

        $this->assertSame(
            60_000_000,
            \App\Models\TransportSetting::singleton()->fresh()->furgao_preco_referencia_micro,
            'e o valor não mudou',
        );
    }
}
