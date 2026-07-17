<?php

namespace Tests\Feature;

use App\Domain\Chat\ContaSistema;
use App\Domain\Colony\CreateColony;
use App\Domain\Missoes\Janela;
use App\Domain\Missoes\Progresso;
use App\Models\ChatMessage;
use App\Models\MissionAssignment;
use App\Models\MissionTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A conta de sistema "Missões" avisa pelo rádio quando uma missão é concluída, e o que ela pagou
 * (pedido do usuário) — mesmo desenho do aviso do Pátio (D-91), disparado uma vez, na conclusão.
 */
class MissoesAvisoRadioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\ComponentRecipeSeeder::class);
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
        $this->seed(\Database\Seeders\MissionTemplateSeeder::class);
    }

    private function colono(): User
    {
        $user = User::factory()->create(['tutorial_completed_at' => now()]);
        app(CreateColony::class)->handle($user, 'Base', 20, 20);

        return $user->fresh();
    }

    private function ultimoAvisoPara(User $destinatario): ?ChatMessage
    {
        return ChatMessage::where('channel', 'privada')
            ->where('recipient_user_id', $destinatario->id)
            ->orderByDesc('id')
            ->first();
    }

    /** A conta reservada pela migration existe, sem colônia e sem senha jogável. */
    public function test_a_conta_missoes_existe_e_nao_tem_colonia(): void
    {
        $missoes = ContaSistema::missoes();

        $this->assertSame('Missões', $missoes->nickname);
        $this->assertNull($missoes->colony()->first());
    }

    public function test_ao_concluir_uma_missao_o_radio_avisa_o_titulo_e_o_fert_pago(): void
    {
        $user = $this->colono();
        $colony = $user->colony;
        MissionAssignment::where('colony_id', $colony->id)->delete(); // fora a tutoria

        $template = MissionTemplate::where('chave', 'dia_obra_1')->firstOrFail(); // 6 F$, sem XP, sem recurso
        MissionAssignment::create([
            'colony_id' => $colony->id, 'template_id' => $template->id, 'categoria' => 'diaria',
            'acao' => 'obra_concluida', 'progresso' => 0, 'meta' => 1, 'status' => 'ativa',
            'expires_at' => Janela::proximoDia(), 'created_at' => now(),
        ]);

        app(Progresso::class)->registrar($colony->id, 'obra_concluida');

        $aviso = $this->ultimoAvisoPara($user);

        $this->assertNotNull($aviso, 'a conta Missões mandou um aviso ao concluir');
        $this->assertSame(ContaSistema::missoes()->id, $aviso->user_id);
        $this->assertStringContainsString('Canteiro vivo', $aviso->body);
        $this->assertStringContainsString('6,00 Fert$', $aviso->body);
    }

    public function test_o_aviso_lista_recursos_e_xp_quando_e_o_que_a_missao_paga(): void
    {
        $user = $this->colono();
        $colony = $user->colony;
        MissionAssignment::where('colony_id', $colony->id)->delete();

        $template = MissionTemplate::where('chave', 'dia_obra_3')->firstOrFail(); // sem F$, recurso: metal_bruto 800
        MissionAssignment::create([
            'colony_id' => $colony->id, 'template_id' => $template->id, 'categoria' => 'diaria',
            'acao' => 'obra_concluida', 'progresso' => 0, 'meta' => 3, 'status' => 'ativa',
            'expires_at' => Janela::proximoDia(), 'created_at' => now(),
        ]);

        app(Progresso::class)->registrar($colony->id, 'obra_concluida', 3);

        $aviso = $this->ultimoAvisoPara($user);

        $this->assertStringContainsString('800x Metal Bruto', $aviso->body);
        $this->assertStringNotContainsString('Fert$', $aviso->body, 'esta missão não paga Fert$');
    }
}
