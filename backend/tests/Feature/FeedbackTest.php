<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Models\Admin;
use App\Models\ChatMessage;
use App\Models\Colony;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Bugs/Melhorias (D-95): o jogador manda, o admin lê e responde — a resposta avisa pelo rádio,
 * remetente "Capital" (reusa o D-91).
 */
class FeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
    }

    private function colono(string $nick = 'ana'): Colony
    {
        $u = User::factory()->create([
            'name' => 'Ana', 'nickname' => $nick, 'email' => "{$nick}@fertways.test",
            'password' => Hash::make('segredo-forte-123'),
        ]);

        return app(CreateColony::class)->handle($u, 'Base', 20, 20);
    }

    private function operador(): Admin
    {
        return Admin::create([
            'name' => 'Op', 'email' => 'op@fertways.test',
            'password' => Hash::make('segredo-forte-1234'), 'role' => Admin::OPERADOR,
        ]);
    }

    // ── o jogador manda ─────────────────────────────────────────────────────────────────────────

    public function test_o_jogador_manda_e_os_dados_sao_anexados_pelo_servidor(): void
    {
        $c = $this->colono();

        $this->actingAs($c->user)->postJson('/feedback', [
            'tipo' => 'bug',
            'assunto' => 'A Muralha não constrói',
            'mensagem' => 'Cliquei em construir e nada aconteceu, sem mensagem de erro nenhuma.',
        ])->assertCreated();

        $f = Feedback::first();
        $this->assertSame($c->user->id, $f->user_id);
        $this->assertSame($c->id, $f->colony_id);
        $this->assertSame('ana@fertways.test', $f->email);
        $this->assertSame('Base', $f->colony_name);
        $this->assertSame('ana', $f->nickname);
        $this->assertNull($f->lida_at);
        $this->assertNull($f->feito_at);
    }

    public function test_tipo_fora_do_catalogo_e_recusado(): void
    {
        $c = $this->colono();

        $this->actingAs($c->user)->postJson('/feedback', [
            'tipo' => 'inventado',
            'assunto' => 'x',
            'mensagem' => 'mensagem com mais de dez caracteres',
        ])->assertStatus(422);
    }

    public function test_mensagem_curta_e_recusada(): void
    {
        $c = $this->colono();

        $this->actingAs($c->user)->postJson('/feedback', [
            'tipo' => 'bug',
            'assunto' => 'x',
            'mensagem' => 'curta',
        ])->assertStatus(422);
    }

    // ── o admin lê e age ────────────────────────────────────────────────────────────────────────

    public function test_a_aba_lista_e_filtra_por_estado_e_tipo(): void
    {
        $c = $this->colono();
        Feedback::create([
            'user_id' => $c->user->id, 'colony_id' => $c->id, 'email' => $c->user->email,
            'colony_name' => $c->name, 'nickname' => $c->user->nickname,
            'tipo' => 'bug', 'assunto' => 'Bug da Muralha', 'mensagem' => 'não constrói de jeito nenhum',
        ]);
        Feedback::create([
            'user_id' => $c->user->id, 'colony_id' => $c->id, 'email' => $c->user->email,
            'colony_name' => $c->name, 'nickname' => $c->user->nickname,
            'tipo' => 'melhoria', 'assunto' => 'Ideia de melhoria', 'mensagem' => 'seria legal ter isto',
            'lida_at' => now(),
        ]);

        $op = $this->operador();

        $this->actingAs($op, 'admin')
            ->get('/admin/feedback')
            ->assertOk()
            ->assertSee('Bug da Muralha')
            ->assertSee('Ideia de melhoria');

        $this->actingAs($op, 'admin')
            ->get('/admin/feedback?estado=nao_lida')
            ->assertOk()
            ->assertSee('Bug da Muralha')
            ->assertDontSee('Ideia de melhoria');

        $this->actingAs($op, 'admin')
            ->get('/admin/feedback?tipo=melhoria')
            ->assertOk()
            ->assertDontSee('Bug da Muralha')
            ->assertSee('Ideia de melhoria');
    }

    public function test_marcar_lida_alterna(): void
    {
        $c = $this->colono();
        $op = $this->operador();
        $f = Feedback::create([
            'user_id' => $c->user->id, 'colony_id' => $c->id, 'email' => $c->user->email,
            'colony_name' => $c->name, 'nickname' => $c->user->nickname,
            'tipo' => 'bug', 'assunto' => 'x', 'mensagem' => 'mensagem de teste bem detalhada',
        ]);

        $this->actingAs($op, 'admin')
            ->post("/admin/feedback/{$f->id}/lida")
            ->assertRedirect();
        $this->assertNotNull($f->fresh()->lida_at);

        $this->actingAs($op, 'admin')
            ->post("/admin/feedback/{$f->id}/lida")
            ->assertRedirect();
        $this->assertNull($f->fresh()->lida_at);
    }

    public function test_marcar_feito_alterna(): void
    {
        $c = $this->colono();
        $op = $this->operador();
        $f = Feedback::create([
            'user_id' => $c->user->id, 'colony_id' => $c->id, 'email' => $c->user->email,
            'colony_name' => $c->name, 'nickname' => $c->user->nickname,
            'tipo' => 'bug', 'assunto' => 'x', 'mensagem' => 'mensagem de teste bem detalhada',
        ]);

        $this->actingAs($op, 'admin')->post("/admin/feedback/{$f->id}/feito");
        $this->assertNotNull($f->fresh()->feito_at);

        $this->actingAs($op, 'admin')->post("/admin/feedback/{$f->id}/feito");
        $this->assertNull($f->fresh()->feito_at);
    }

    /**
     * O coração do D-95: responder marca lida, grava a resposta, E avisa o jogador pelo rádio —
     * remetente "Capital", a mesma conta de sistema do D-91.
     */
    public function test_responder_grava_a_resposta_marca_lida_e_avisa_pelo_radio(): void
    {
        $c = $this->colono();
        $f = Feedback::create([
            'user_id' => $c->user->id, 'colony_id' => $c->id, 'email' => $c->user->email,
            'colony_name' => $c->name, 'nickname' => $c->user->nickname,
            'tipo' => 'bug', 'assunto' => 'A Muralha não constrói', 'mensagem' => 'nada acontece',
        ]);

        $this->actingAs($this->operador(), 'admin')
            ->post("/admin/feedback/{$f->id}/responder", ['resposta' => 'Corrigido no próximo deploy!'])
            ->assertRedirect();

        $f->refresh();
        $this->assertSame('Corrigido no próximo deploy!', $f->resposta);
        $this->assertNotNull($f->respondida_at);
        $this->assertNotNull($f->lida_at);

        $capital = \App\Domain\Chat\ContaSistema::capital();
        $aviso = ChatMessage::where('channel', 'privada')
            ->where('user_id', $capital->id)
            ->where('recipient_user_id', $c->user->id)
            ->first();

        $this->assertNotNull($aviso, 'a Capital mandou o aviso');
        $this->assertStringContainsString('Corrigido no próximo deploy!', $aviso->body);
        $this->assertStringContainsString('A Muralha não constrói', $aviso->body);
    }

    public function test_resposta_vazia_e_recusada(): void
    {
        $c = $this->colono();
        $f = Feedback::create([
            'user_id' => $c->user->id, 'colony_id' => $c->id, 'email' => $c->user->email,
            'colony_name' => $c->name, 'nickname' => $c->user->nickname,
            'tipo' => 'bug', 'assunto' => 'x', 'mensagem' => 'mensagem de teste bem detalhada',
        ]);

        $this->actingAs($this->operador(), 'admin')
            ->post("/admin/feedback/{$f->id}/responder", ['resposta' => ''])
            ->assertSessionHasErrors();

        $this->assertNull($f->fresh()->resposta);
    }

    public function test_o_card_do_dashboard_so_aparece_com_mensagem_nao_lida(): void
    {
        $op = $this->operador();

        $this->actingAs($op, 'admin')
            ->get('/admin')
            ->assertOk()
            ->assertDontSee('Bugs/Melhorias — mensagens novas');

        $c = $this->colono();
        Feedback::create([
            'user_id' => $c->user->id, 'colony_id' => $c->id, 'email' => $c->user->email,
            'colony_name' => $c->name, 'nickname' => $c->user->nickname,
            'tipo' => 'bug', 'assunto' => 'x', 'mensagem' => 'mensagem de teste bem detalhada',
        ]);

        $this->actingAs($op, 'admin')
            ->get('/admin')
            ->assertOk()
            ->assertSee('Bugs/Melhorias — mensagens novas')
            ->assertSee('1');
    }
}
