<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Logistics\ConcluirTrechos;
use App\Domain\Logistics\DespacharVeiculo;
use App\Models\Colony;
use App\Models\Federation;
use App\Models\FederationHolding;
use App\Models\FederationLedger;
use App\Models\TreasuryHolding;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O fundo da federação (docs/decisoes.md D-114): entrada por entrega física de veículo (decisão do
 * usuário), saída por saque administrativo do Líder/Intendente.
 */
class FederacaoFundoTest extends TestCase
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
        $colony = app(CreateColony::class)->handle($user, "Colônia {$nick}", 10 + $this->proximoSlot++, 20)->fresh();
        $colony->resources()->where('resource_type', 'energia')->update(['amount' => 100_000]);
        $colony->resources()->where('resource_type', 'agua')->update(['amount' => 10_000]);

        return $colony->fresh();
    }

    /**
     * SEMPRE um `User` recém-lido do banco, nunca `$colony->user` reaproveitado — mesma razão de
     * `FederacaoNucleoTest::user()`: `actingAs()` injeta o objeto PHP direto na guarda, e a
     * relação `colony` cacheada nele releria estado de antes da última ação dentro do teste.
     */
    private function user(Colony $colony): User
    {
        return User::findOrFail($colony->user_id);
    }

    private function concluirTick(): void
    {
        Carbon::setTestNow(Carbon::now()->addDay());
        app(ConcluirTrechos::class)->handle();
        Carbon::setTestNow();
    }

    private function fundar(Colony $lider, string $nome = 'Aliança'): Federation
    {
        return Federation::find(
            $this->actingAs($this->user($lider))->postJson('/federations', ['name' => $nome])->json('id'),
        );
    }

    // ── Contribuição (entrega física) ───────────────────────────────────────

    public function test_despacho_com_carga_credita_o_fundo_liquido_de_tributo(): void
    {
        $colonia = $this->colonia('a');
        $this->fundar($colonia);
        $veiculo = $colonia->vehicles()->first();

        $this->actingAs($this->user($colonia))->postJson("/vehicles/{$veiculo->id}/dispatch", [
            'destination_type' => 'federacao',
            'cargo' => ['agua' => 1000],
        ])->assertCreated();

        $this->concluirTick();

        $bps = \App\Models\ResourceType::whereKey('agua')->value('tax_bps');
        $tributo = intdiv(1000 * $bps, 10_000);
        $liquido = 1000 - $tributo;

        $saldo = FederationHolding::where('federation_id', $colonia->fresh()->federation_id)
            ->where('resource_type', 'agua')->value('amount');

        $this->assertSame($liquido, $saldo);
        $this->assertSame($tributo, (int) TreasuryHolding::whereKey('agua')->value('amount'));

        $lancamento = FederationLedger::where('federation_id', $colonia->fresh()->federation_id)
            ->where('type', 'credito')->first();
        $this->assertSame($liquido, $lancamento->amount);
        $this->assertSame($colonia->id, $lancamento->colony_id);
    }

    public function test_veiculo_estaciona_no_patio_ao_final(): void
    {
        $colonia = $this->colonia('a');
        $this->fundar($colonia);
        $veiculo = $colonia->vehicles()->first();

        $this->actingAs($this->user($colonia))->postJson("/vehicles/{$veiculo->id}/dispatch", [
            'destination_type' => 'federacao',
            'cargo' => ['agua' => 500],
        ])->assertCreated();

        $this->concluirTick();

        $veiculo->refresh();
        $this->assertSame(Vehicle::NO_PATIO, $veiculo->local);
        $this->assertSame('ocioso', $veiculo->status);
    }

    public function test_sem_federacao_nao_despacha_para_federacao(): void
    {
        $colonia = $this->colonia('a');
        $veiculo = $colonia->vehicles()->first();

        $this->actingAs($this->user($colonia))->postJson("/vehicles/{$veiculo->id}/dispatch", [
            'destination_type' => 'federacao',
            'cargo' => ['agua' => 500],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'sem_federacao');
    }

    public function test_destination_id_do_cliente_e_ignorado(): void
    {
        $colonia = $this->colonia('a');
        $outra = $this->colonia('outra');
        $fedDaColonia = $this->fundar($colonia);
        $this->fundar($outra, 'Outra Aliança');
        $veiculo = $colonia->vehicles()->first();

        // Manda um destination_id que não é nem da própria federação — o domínio resolve sozinho.
        $this->actingAs($this->user($colonia))->postJson("/vehicles/{$veiculo->id}/dispatch", [
            'destination_type' => 'federacao',
            'destination_id' => 999999,
            'cargo' => ['agua' => 300],
        ])->assertCreated();

        $this->concluirTick();

        $this->assertGreaterThan(0, FederationHolding::where('federation_id', $fedDaColonia->id)->sum('amount'));
    }

    // ── Saque ────────────────────────────────────────────────────────────────

    private function federacaoComFundo(int $agua = 1000): array
    {
        $colonia = $this->colonia('a');
        $this->fundar($colonia);
        $veiculo = $colonia->vehicles()->first();

        $this->actingAs($this->user($colonia))->postJson("/vehicles/{$veiculo->id}/dispatch", [
            'destination_type' => 'federacao',
            'cargo' => ['agua' => $agua],
        ]);
        $this->concluirTick();

        return [$colonia->fresh(), Federation::find($colonia->fresh()->federation_id)];
    }

    public function test_lider_saca_e_credita_o_proprio_estoque(): void
    {
        [$colonia] = $this->federacaoComFundo(1000);
        $antes = (int) $colonia->resources()->where('resource_type', 'agua')->value('amount');
        $saldoFundo = FederationHolding::where('federation_id', $colonia->federation_id)
            ->where('resource_type', 'agua')->value('amount');

        $this->actingAs($this->user($colonia))->postJson('/federation/withdraw', [
            'resource_type' => 'agua', 'amount' => $saldoFundo,
        ])->assertOk();

        $depois = (int) $colonia->fresh()->resources()->where('resource_type', 'agua')->value('amount');
        $this->assertSame($antes + $saldoFundo, $depois);
        $this->assertSame(0, (int) FederationHolding::where('federation_id', $colonia->federation_id)
            ->where('resource_type', 'agua')->value('amount'));
    }

    public function test_intendente_tambem_saca(): void
    {
        [$lider] = $this->federacaoComFundo(1000);
        $intendente = $this->colonia('intendente');

        $inviteId = $this->actingAs($this->user($lider))
            ->postJson("/federations/{$lider->federation_id}/invite", ['colony_id' => $intendente->id])->json('id');
        $this->actingAs($this->user($intendente))->postJson("/federation/invites/{$inviteId}/accept");
        $this->actingAs($this->user($lider))
            ->patchJson("/federation/members/{$intendente->id}/role", ['role' => Federation::INTENDENTE]);

        $this->actingAs($this->user($intendente))->postJson('/federation/withdraw', [
            'resource_type' => 'agua', 'amount' => 10,
        ])->assertOk();
    }

    public function test_membro_comum_nao_saca(): void
    {
        [$lider] = $this->federacaoComFundo(1000);
        $membro = $this->colonia('membro');

        $inviteId = $this->actingAs($this->user($lider))
            ->postJson("/federations/{$lider->federation_id}/invite", ['colony_id' => $membro->id])->json('id');
        $this->actingAs($this->user($membro))->postJson("/federation/invites/{$inviteId}/accept");

        $this->actingAs($this->user($membro))->postJson('/federation/withdraw', [
            'resource_type' => 'agua', 'amount' => 10,
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'sem_permissao');
    }

    public function test_saque_maior_que_o_saldo_e_recusado_sem_debitar_nada(): void
    {
        [$colonia] = $this->federacaoComFundo(1000);
        $saldoFundo = (int) FederationHolding::where('federation_id', $colonia->federation_id)
            ->where('resource_type', 'agua')->value('amount');

        $this->actingAs($this->user($colonia))->postJson('/federation/withdraw', [
            'resource_type' => 'agua', 'amount' => $saldoFundo + 1,
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'fundo_insuficiente');

        $this->assertSame($saldoFundo, (int) FederationHolding::where('federation_id', $colonia->federation_id)
            ->where('resource_type', 'agua')->value('amount'));
    }

    // ── Dissolução credita o Tesouro ─────────────────────────────────────────

    public function test_dissolucao_credita_o_saldo_remanescente_no_tesouro(): void
    {
        [$colonia, $fed] = $this->federacaoComFundo(1000);
        $saldoFundo = (int) FederationHolding::where('federation_id', $fed->id)
            ->where('resource_type', 'agua')->value('amount');
        $tesouroAntes = (int) TreasuryHolding::whereKey('agua')->value('amount');

        $this->actingAs($this->user($colonia))
            ->postJson('/federation/leave', ['confirmacao' => 'SAIR'])
            ->assertOk();

        $this->assertSame($tesouroAntes + $saldoFundo, (int) TreasuryHolding::whereKey('agua')->value('amount'));
        $this->assertSame(0, (int) FederationHolding::where('federation_id', $fed->id)
            ->where('resource_type', 'agua')->value('amount'));
        $this->assertNotNull($fed->fresh()->disbanded_at);
    }
}
