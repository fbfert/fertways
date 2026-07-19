<?php

namespace Tests\Feature;

use App\Domain\Chat\ContaSistema;
use App\Domain\Colony\CreateColony;
use App\Models\ChatMessage;
use App\Models\Colony;
use App\Models\Federation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Os avisos sociais da Federação (docs/decisoes.md D-121) — as 9 ações de `App\Domain\Federacao\*`
 * eram todas SILENCIOSAS: nenhuma avisava ninguém, e um membro só descobria que foi expulso, que
 * o cargo mudou, ou que a federação sumiu, ao tropeçar num "sem_federacao" tentando usar alguma
 * coisa. A conta de sistema "Federação" (`ContaSistema::federacao()`, já existia desde o D-116, só
 * usada no aviso de cerco) agora também avisa: convite recebido, entrada de um novo membro, saída,
 * expulsão, mudança de cargo, transferência de liderança e dissolução.
 *
 * `SacarDoFundo`, `CriarFederacao` e `pedir()` (pedido de entrada) ficaram de fora de propósito —
 * ver D-121: o primeiro já é visível no extrato do fundo, o segundo não tem quem avisar (a colônia
 * está sozinha), e o terceiro exigiria notificar vários Líderes/Diplomatas de uma vez, escopo maior
 * do que o pedido original cobria.
 */
class AvisosSociaisDaFederacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\ComponentRecipeSeeder::class);
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
    }

    private int $proximoSlot = 0;

    private function colonia(string $nick): Colony
    {
        $user = User::factory()->create(['email' => "{$nick}@t.test", 'nickname' => $nick]);

        return app(CreateColony::class)->handle($user, "Colônia {$nick}", 10 + $this->proximoSlot++, 20)->fresh();
    }

    private function user(Colony $colony): User
    {
        return User::findOrFail($colony->user_id);
    }

    /** @return array{0: Colony, 1: Colony, 2: Federation} */
    private function federacaoComMembro(): array
    {
        $lider = $this->colonia('lider');
        $membro = $this->colonia('membro');
        $fed = Federation::find($this->actingAs($this->user($lider))->postJson('/federations', ['name' => 'Aliança'])->json('id'));

        $inviteId = $this->actingAs($this->user($lider))
            ->postJson("/federations/{$fed->id}/invite", ['colony_id' => $membro->id])->json('id');
        $this->actingAs($this->user($membro))->postJson("/federation/invites/{$inviteId}/accept");

        return [$lider, $membro, $fed];
    }

    private function ultimaMensagemPara(Colony $destinatario): ?ChatMessage
    {
        return ChatMessage::where('user_id', ContaSistema::federacao()->id)
            ->where('recipient_user_id', $destinatario->user_id)
            ->latest('id')
            ->first();
    }

    public function test_convite_avisa_a_colonia_convidada(): void
    {
        $lider = $this->colonia('lider');
        $alvo = $this->colonia('alvo');
        $fed = Federation::find($this->actingAs($this->user($lider))->postJson('/federations', ['name' => 'Aliança'])->json('id'));

        $this->actingAs($this->user($lider))->postJson("/federations/{$fed->id}/invite", ['colony_id' => $alvo->id]);

        $msg = $this->ultimaMensagemPara($alvo);
        $this->assertNotNull($msg);
        $this->assertStringContainsString('convidado', $msg->body);
        $this->assertStringContainsString('Aliança', $msg->body);
    }

    public function test_aceitar_avisa_quem_ja_estava_na_federacao(): void
    {
        [$lider, $membro] = $this->federacaoComMembro();

        $msg = $this->ultimaMensagemPara($lider);
        $this->assertNotNull($msg);
        $this->assertStringContainsString('Colônia membro', $msg->body);
        $this->assertStringContainsString('entrou', $msg->body);
    }

    public function test_sair_avisa_quem_ficou(): void
    {
        [$lider, $membro] = $this->federacaoComMembro();

        $this->actingAs($this->user($membro))
            ->postJson('/federation/leave', ['confirmacao' => 'SAIR']);

        $msg = $this->ultimaMensagemPara($lider);
        $this->assertNotNull($msg);
        $this->assertStringContainsString('Colônia membro', $msg->body);
        $this->assertStringContainsString('saiu', $msg->body);
    }

    public function test_expulsar_avisa_o_expulso(): void
    {
        [$lider, $membro] = $this->federacaoComMembro();

        $this->actingAs($this->user($lider))
            ->postJson("/federation/members/{$membro->id}/kick");

        $msg = $this->ultimaMensagemPara($membro);
        $this->assertNotNull($msg);
        $this->assertStringContainsString('removido', $msg->body);
        $this->assertStringContainsString('Aliança', $msg->body);
    }

    public function test_alterar_cargo_avisa_o_afetado(): void
    {
        [$lider, $membro] = $this->federacaoComMembro();

        $this->actingAs($this->user($lider))
            ->patchJson("/federation/members/{$membro->id}/role", ['role' => Federation::DIPLOMATA]);

        $msg = $this->ultimaMensagemPara($membro);
        $this->assertNotNull($msg);
        $this->assertStringContainsString('Diplomata', $msg->body);
    }

    public function test_transferir_lideranca_avisa_o_novo_lider(): void
    {
        [$lider, $membro] = $this->federacaoComMembro();

        $this->actingAs($this->user($lider))
            ->postJson('/federation/transfer-leadership', ['colony_id' => $membro->id]);

        $msg = $this->ultimaMensagemPara($membro);
        $this->assertNotNull($msg);
        $this->assertStringContainsString('Líder', $msg->body);
        $this->assertStringContainsString('Aliança', $msg->body);
    }

    /**
     * O caminho normal (o último membro sai e `SairDaFederacao` dissolve) não tem ninguém mais
     * para avisar — quem saiu já foi avisado pelo próprio "Sair". É a dissolução de EMERGÊNCIA
     * pelo admin, com membros ainda ativos, que prova o aviso de verdade.
     */
    public function test_dissolucao_pelo_admin_avisa_todos_os_membros_ativos(): void
    {
        [$lider, $membro, $fed] = $this->federacaoComMembro();

        $admin = \App\Models\Admin::create([
            'name' => 'Operador', 'email' => 'op@fertways.test',
            'password' => \Illuminate\Support\Facades\Hash::make('segredo-forte-1234'),
            'role' => \App\Models\Admin::OPERADOR,
        ]);

        $this->actingAs($admin, 'admin')
            ->post("/admin/federacoes/{$fed->id}/dissolver", ['confirmacao' => 'DISSOLVER']);

        foreach ([$lider, $membro] as $c) {
            $msg = $this->ultimaMensagemPara($c);
            $this->assertNotNull($msg, "colônia {$c->name} deveria ter sido avisada");
            $this->assertStringContainsString('dissolvida', $msg->body);
        }
    }
}
