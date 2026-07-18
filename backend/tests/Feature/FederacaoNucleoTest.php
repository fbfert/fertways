<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Models\Colony;
use App\Models\Federation;
use App\Models\FederationInvite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Federação (GDD §04/§07; docs/decisoes.md D-114), Fatia 1 — criar, convidar/pedir entrada,
 * aceitar/recusar, transferir liderança, expulsar, alterar cargo e sair (com dissolução).
 */
class FederacaoNucleoTest extends TestCase
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

    /**
     * SEMPRE um `User` recém-lido do banco, nunca `$colony->user` reaproveitado. `actingAs()`
     * injeta o objeto PHP direto na guarda, e a relação `colony` fica em cache NELE: reusar o
     * mesmo objeto depois de uma ação que muda `federation_id`/`federation_role` releria o estado
     * de ANTES da ação. Em produção isso nunca acontece — cada request HTTP resolve o usuário do
     * zero — mas dentro de um teste que simula vários requests em sequência, é preciso forçar.
     */
    private function user(Colony $colony): User
    {
        return User::findOrFail($colony->user_id);
    }

    // ── Criar ────────────────────────────────────────────────────────────────

    public function test_funda_uma_federacao_e_vira_lider(): void
    {
        $a = $this->colonia('a');

        $resp = $this->actingAs($this->user($a))->postJson('/federations', ['name' => 'Aliança do Norte'])
            ->assertCreated();

        $this->assertSame(Federation::LIDER, $a->fresh()->federation_role);
        $this->assertSame($resp->json('id'), $a->fresh()->federation_id);
    }

    public function test_colonia_ja_federada_nao_funda_outra(): void
    {
        $a = $this->colonia('a');
        $this->actingAs($this->user($a))->postJson('/federations', ['name' => 'Primeira'])->assertCreated();

        $this->actingAs($this->user($a))->postJson('/federations', ['name' => 'Segunda'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ja_tem_federacao');
    }

    public function test_nome_duplicado_e_recusado(): void
    {
        $a = $this->colonia('a');
        $b = $this->colonia('b');

        $this->actingAs($this->user($a))->postJson('/federations', ['name' => 'Aliança'])->assertCreated();
        $this->actingAs($this->user($b))->postJson('/federations', ['name' => 'Aliança'])->assertStatus(422);
    }

    // ── Convite e pedido ─────────────────────────────────────────────────────

    public function test_lider_convida_e_a_colonia_aceita_e_entra(): void
    {
        $lider = $this->colonia('lider');
        $convidada = $this->colonia('convidada');
        $fed = Federation::find($this->actingAs($this->user($lider))->postJson('/federations', ['name' => 'Aliança'])->json('id'));

        $inviteId = $this->actingAs($this->user($lider))
            ->postJson("/federations/{$fed->id}/invite", ['colony_id' => $convidada->id])
            ->assertCreated()->json('id');

        $this->actingAs($this->user($convidada))->postJson("/federation/invites/{$inviteId}/accept")->assertOk();

        $convidada->refresh();
        $this->assertSame($fed->id, $convidada->federation_id);
        $this->assertSame(Federation::MEMBRO, $convidada->federation_role);
    }

    public function test_colonia_pede_entrada_e_lider_aceita(): void
    {
        $lider = $this->colonia('lider');
        $candidata = $this->colonia('candidata');
        $fed = Federation::find($this->actingAs($this->user($lider))->postJson('/federations', ['name' => 'Aliança'])->json('id'));

        $inviteId = $this->actingAs($this->user($candidata))
            ->postJson("/federations/{$fed->id}/apply")
            ->assertCreated()->json('id');

        $this->actingAs($this->user($lider))->postJson("/federation/invites/{$inviteId}/accept")->assertOk();

        $this->assertSame($fed->id, $candidata->fresh()->federation_id);
    }

    public function test_diplomata_tambem_convida_e_aceita_pedido(): void
    {
        $lider = $this->colonia('lider');
        $diplomata = $this->colonia('diplomata');
        $candidata = $this->colonia('candidata');
        $fed = Federation::find($this->actingAs($this->user($lider))->postJson('/federations', ['name' => 'Aliança'])->json('id'));

        // Diplomata entra primeiro como convidado, depois é promovido.
        $inviteId = $this->actingAs($this->user($lider))
            ->postJson("/federations/{$fed->id}/invite", ['colony_id' => $diplomata->id])->json('id');
        $this->actingAs($this->user($diplomata))->postJson("/federation/invites/{$inviteId}/accept")->assertOk();
        $this->actingAs($this->user($lider))
            ->patchJson("/federation/members/{$diplomata->id}/role", ['role' => Federation::DIPLOMATA])
            ->assertOk();

        $pedidoId = $this->actingAs($this->user($candidata))->postJson("/federations/{$fed->id}/apply")->json('id');
        $this->actingAs($this->user($diplomata))->postJson("/federation/invites/{$pedidoId}/accept")->assertOk();

        $this->assertSame($fed->id, $candidata->fresh()->federation_id);
    }

    public function test_membro_comum_nao_convida(): void
    {
        $lider = $this->colonia('lider');
        $membro = $this->colonia('membro');
        $alvo = $this->colonia('alvo');
        $fed = Federation::find($this->actingAs($this->user($lider))->postJson('/federations', ['name' => 'Aliança'])->json('id'));

        $inviteId = $this->actingAs($this->user($lider))
            ->postJson("/federations/{$fed->id}/invite", ['colony_id' => $membro->id])->json('id');
        $this->actingAs($this->user($membro))->postJson("/federation/invites/{$inviteId}/accept");

        $this->actingAs($this->user($membro))
            ->postJson("/federations/{$fed->id}/invite", ['colony_id' => $alvo->id])
            ->assertStatus(422)
            ->assertJsonPath('code', 'sem_permissao');
    }

    public function test_teto_de_doze_colonias_recusa_novo_convite(): void
    {
        $lider = $this->colonia('lider');
        $fed = Federation::find($this->actingAs($this->user($lider))->postJson('/federations', ['name' => 'Aliança'])->json('id'));

        for ($i = 0; $i < Federation::MAX_COLONIAS - 1; $i++) {
            $c = $this->colonia("m{$i}");
            $inviteId = $this->actingAs($this->user($lider))
                ->postJson("/federations/{$fed->id}/invite", ['colony_id' => $c->id])->json('id');
            $this->actingAs($this->user($c))->postJson("/federation/invites/{$inviteId}/accept");
        }

        $this->assertSame(Federation::MAX_COLONIAS, Colony::where('federation_id', $fed->id)->count());

        $extra = $this->colonia('extra');
        $this->actingAs($this->user($lider))
            ->postJson("/federations/{$fed->id}/invite", ['colony_id' => $extra->id])
            ->assertStatus(422);
    }

    public function test_aceitar_cancela_outros_pendentes_da_mesma_colonia(): void
    {
        $liderA = $this->colonia('liderA');
        $liderB = $this->colonia('liderB');
        $candidata = $this->colonia('candidata');

        $fedA = Federation::find($this->actingAs($this->user($liderA))->postJson('/federations', ['name' => 'A'])->json('id'));
        $fedB = Federation::find($this->actingAs($this->user($liderB))->postJson('/federations', ['name' => 'B'])->json('id'));

        $conviteA = $this->actingAs($this->user($liderA))
            ->postJson("/federations/{$fedA->id}/invite", ['colony_id' => $candidata->id])->json('id');
        $conviteB = $this->actingAs($this->user($liderB))
            ->postJson("/federations/{$fedB->id}/invite", ['colony_id' => $candidata->id])->json('id');

        $this->actingAs($this->user($candidata))->postJson("/federation/invites/{$conviteA}/accept")->assertOk();

        $this->assertSame(FederationInvite::CANCELADO, FederationInvite::find($conviteB)->status);
    }

    public function test_cancela_o_proprio_convite_pendente(): void
    {
        $lider = $this->colonia('lider');
        $alvo = $this->colonia('alvo');
        $fed = Federation::find($this->actingAs($this->user($lider))->postJson('/federations', ['name' => 'Aliança'])->json('id'));

        $inviteId = $this->actingAs($this->user($lider))
            ->postJson("/federations/{$fed->id}/invite", ['colony_id' => $alvo->id])->json('id');

        $this->actingAs($this->user($lider))->deleteJson("/federation/invites/{$inviteId}")->assertOk();

        $this->assertSame(FederationInvite::CANCELADO, FederationInvite::find($inviteId)->status);
    }

    // ── Transferir liderança ─────────────────────────────────────────────────

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

    public function test_lider_transfere_a_lideranca(): void
    {
        [$lider, $membro] = $this->federacaoComMembro();

        $this->actingAs($this->user($lider))
            ->postJson('/federation/transfer-leadership', ['colony_id' => $membro->id])
            ->assertOk();

        $this->assertSame(Federation::LIDER, $membro->fresh()->federation_role);
        $this->assertSame(Federation::MEMBRO, $lider->fresh()->federation_role);
    }

    public function test_nao_lider_nao_transfere(): void
    {
        [, $membro] = $this->federacaoComMembro();

        $this->actingAs($this->user($membro))
            ->postJson('/federation/transfer-leadership', ['colony_id' => $membro->id])
            ->assertStatus(422);
    }

    // ── Expulsar e alterar cargo ─────────────────────────────────────────────

    public function test_lider_expulsa_membro(): void
    {
        [$lider, $membro] = $this->federacaoComMembro();

        $this->actingAs($this->user($lider))->postJson("/federation/members/{$membro->id}/kick")->assertOk();

        $this->assertNull($membro->fresh()->federation_id);
    }

    public function test_ninguem_expulsa_o_lider(): void
    {
        [$lider, $membro] = $this->federacaoComMembro();

        $this->actingAs($this->user($membro))->postJson("/federation/members/{$lider->id}/kick")
            ->assertStatus(422);
    }

    public function test_lider_promove_e_rebaixa(): void
    {
        [$lider, $membro] = $this->federacaoComMembro();

        $this->actingAs($this->user($lider))
            ->patchJson("/federation/members/{$membro->id}/role", ['role' => Federation::INTENDENTE])
            ->assertOk();
        $this->assertSame(Federation::INTENDENTE, $membro->fresh()->federation_role);

        $this->actingAs($this->user($lider))
            ->patchJson("/federation/members/{$membro->id}/role", ['role' => Federation::MEMBRO])
            ->assertOk();
        $this->assertSame(Federation::MEMBRO, $membro->fresh()->federation_role);
    }

    public function test_nao_lider_nao_altera_cargo(): void
    {
        [, $membro] = $this->federacaoComMembro();

        $this->actingAs($this->user($membro))
            ->patchJson("/federation/members/{$membro->id}/role", ['role' => Federation::DIPLOMATA])
            ->assertStatus(422);
    }

    // ── Sair e dissolver ─────────────────────────────────────────────────────

    public function test_membro_comum_sai_livremente(): void
    {
        [, $membro] = $this->federacaoComMembro();

        $this->actingAs($this->user($membro))->postJson('/federation/leave')->assertOk();

        $this->assertNull($membro->fresh()->federation_id);
    }

    public function test_lider_com_outros_membros_e_bloqueado_ate_transferir(): void
    {
        [$lider] = $this->federacaoComMembro();

        $this->actingAs($this->user($lider))->postJson('/federation/leave')->assertStatus(422);
        $this->assertNotNull($lider->fresh()->federation_id);
    }

    public function test_ultimo_membro_sai_e_a_federacao_dissolve(): void
    {
        $lider = $this->colonia('lider');
        $fed = Federation::find($this->actingAs($this->user($lider))->postJson('/federations', ['name' => 'Solo'])->json('id'));

        $this->actingAs($this->user($lider))->postJson('/federation/leave')->assertOk();

        $this->assertNull($lider->fresh()->federation_id);
        $this->assertNotNull($fed->fresh()->disbanded_at);
    }
}
