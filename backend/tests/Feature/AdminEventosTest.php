<?php

namespace Tests\Feature;

use App\Domain\Eventos\EntregarCestas;
use App\Domain\Eventos\Modificadores;
use App\Domain\Logistics\RequisitosDeOcupacao;
use App\Models\Admin;
use App\Models\Colony;
use App\Models\GameEvent;
use App\Models\GameEventEntrega;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A aba de eventos do painel (D-232).
 *
 * O que estes testes guardam é o que separa a aba de um formulário perigoso:
 *
 *  - ela é **do Dono**, e a guarda é no servidor — esconder o menu sem barrar a rota faria da
 *    divisão de papéis uma sugestão (D-61);
 *  - criar **nunca ativa**: o preview antes da ativação é a §Segurança da A2.8, e um segundo clique
 *    é a versão web do `--ativar` que falta;
 *  - um evento **já entregue não se reescreve**, ou metade do mundo ficaria com uma cesta e metade
 *    com outra.
 */
class AdminEventosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\ComponentRecipeSeeder::class);
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
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

    private function colonia(int $x = 40): Colony
    {
        return app(\App\Domain\Colony\CreateColony::class)
            ->handle(User::factory()->create(), 'C'.$x, $x, 40);
    }

    /** @return array<string,mixed> */
    private function formulario(array $extra = []): array
    {
        return array_merge([
            'slug' => 'cesta_de_presente',
            'nome' => 'Cesta de Presente',
            'dias' => 30,
            'visibilidade' => 'anunciado',
            'modificador' => '',
            'efeito_bps' => '',
            'cesta' => ['energia' => 20_000, EntregarCestas::FERT => 400],
        ], $extra);
    }

    // ── a porta ──────────────────────────────────────────────────────────────

    public function test_a_aba_e_do_dono(): void
    {
        $this->actingAs($this->operador(), 'admin')->get('/admin/eventos')->assertForbidden();
        $this->actingAs($this->dono(), 'admin')->get('/admin/eventos')->assertOk();
    }

    public function test_o_operador_nao_cria_evento_nem_batendo_direto_na_rota(): void
    {
        $this->actingAs($this->operador(), 'admin')
            ->post('/admin/eventos', $this->formulario())
            ->assertForbidden();

        $this->assertSame(0, GameEvent::count());
    }

    // ── criar nunca ativa ────────────────────────────────────────────────────

    public function test_criar_grava_rascunho_e_o_rascunho_nao_entrega_nada(): void
    {
        $c = $this->colonia();
        $antes = (int) $c->resources()->where('resource_type', 'energia')->value('amount');

        $this->actingAs($this->dono(), 'admin')
            ->post('/admin/eventos', $this->formulario())
            ->assertRedirect();

        $evento = GameEvent::where('slug', 'cesta_de_presente')->firstOrFail();

        $this->assertSame('rascunho', $evento->status);
        $this->assertNull($evento->modificador, 'evento que só entrega cesta não tem modificador');
        $this->assertSame(20_000, $evento->recompensas['energia']);
        // Fert$ entra em MICRO, a mesma escala do subsídio (D-113).
        $this->assertSame(400_000_000, $evento->recompensas[EntregarCestas::FERT]);

        $this->assertSame(0, GameEventEntrega::count());
        $this->assertSame($antes, (int) $c->fresh()->resources()
            ->where('resource_type', 'energia')->value('amount'));
    }

    public function test_ativar_entrega_a_cesta_na_hora(): void
    {
        $c = $this->colonia();
        $antes = (int) $c->resources()->where('resource_type', 'energia')->value('amount');
        $dono = $this->dono();

        $this->actingAs($dono, 'admin')->post('/admin/eventos', $this->formulario());
        $evento = GameEvent::where('slug', 'cesta_de_presente')->firstOrFail();

        $this->actingAs($dono, 'admin')
            ->post("/admin/eventos/{$evento->id}/ativar")
            ->assertRedirect();

        $this->assertSame('ativo', $evento->fresh()->status);
        $this->assertSame($antes + 20_000, (int) $c->fresh()->resources()
            ->where('resource_type', 'energia')->value('amount'));
        $this->assertSame(1, GameEventEntrega::where('game_event_id', $evento->id)->count());
    }

    public function test_um_evento_ja_entregue_nao_se_reescreve(): void
    {
        $this->colonia();
        $dono = $this->dono();

        $this->actingAs($dono, 'admin')->post('/admin/eventos', $this->formulario());
        $evento = GameEvent::where('slug', 'cesta_de_presente')->firstOrFail();
        $this->actingAs($dono, 'admin')->post("/admin/eventos/{$evento->id}/ativar");

        $this->actingAs($dono, 'admin')
            ->post('/admin/eventos', $this->formulario(['cesta' => ['energia' => 999_999]]))
            ->assertSessionHasErrors('slug');

        $this->assertSame(20_000, $evento->fresh()->recompensas['energia'], 'a cesta original fica');
    }

    // ── as validações que impedem um evento sem sentido ──────────────────────

    public function test_evento_sem_modificador_e_sem_cesta_e_recusado(): void
    {
        $this->actingAs($this->dono(), 'admin')
            ->post('/admin/eventos', $this->formulario(['cesta' => []]))
            ->assertSessionHasErrors('slug');

        $this->assertSame(0, GameEvent::count());
    }

    public function test_modificador_sem_efeito_e_recusado(): void
    {
        $this->actingAs($this->dono(), 'admin')
            ->post('/admin/eventos', $this->formulario([
                'modificador' => Modificadores::OCUPACAO_MARCO,
                'efeito_bps' => '',
                'cesta' => [],
            ]))
            ->assertSessionHasErrors('efeito_bps');
    }

    public function test_o_portao_criado_pela_aba_vale_de_verdade_no_mundo(): void
    {
        $dono = $this->dono();
        $requisitos = app(RequisitosDeOcupacao::class);
        $cheio = $requisitos->xpExigido();

        $this->actingAs($dono, 'admin')->post('/admin/eventos', $this->formulario([
            'slug' => 'o_marco_cede',
            'nome' => 'O marco cede',
            'modificador' => Modificadores::OCUPACAO_MARCO,
            'efeito_bps' => -9_500,
            'cesta' => [],
        ]));

        $evento = GameEvent::where('slug', 'o_marco_cede')->firstOrFail();
        $this->assertSame($cheio, $requisitos->xpExigido(), 'rascunho não mexe no mundo');

        $this->actingAs($dono, 'admin')->post("/admin/eventos/{$evento->id}/ativar");

        $this->assertSame(intdiv($cheio, 20), $requisitos->xpExigido());
    }

    // ── cancelar ─────────────────────────────────────────────────────────────

    public function test_cancelar_fecha_o_futuro_e_nao_recolhe_a_cesta(): void
    {
        $c = $this->colonia();
        $dono = $this->dono();

        $this->actingAs($dono, 'admin')->post('/admin/eventos', $this->formulario());
        $evento = GameEvent::where('slug', 'cesta_de_presente')->firstOrFail();
        $this->actingAs($dono, 'admin')->post("/admin/eventos/{$evento->id}/ativar");

        $depois = (int) $c->fresh()->resources()->where('resource_type', 'energia')->value('amount');

        $this->actingAs($dono, 'admin')
            ->post("/admin/eventos/{$evento->id}/cancelar")
            ->assertRedirect();

        $this->assertNotNull($evento->fresh()->cancelado_em);
        $this->assertSame($depois, (int) $c->fresh()->resources()
            ->where('resource_type', 'energia')->value('amount'), 'o que foi dado é de quem recebeu');
    }

    public function test_a_acao_de_evento_deixa_rastro_na_auditoria(): void
    {
        $this->actingAs($this->dono(), 'admin')->post('/admin/eventos', $this->formulario());

        $this->assertDatabaseHas('audit_log', [
            'acao' => 'evento.criar',
            'alvo' => 'evento:cesta_de_presente',
        ]);
    }
}
