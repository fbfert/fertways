<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Frete\FretePublico;
use App\Domain\Frete\Garagem;
use App\Domain\Logistics\ConcluirTrechos;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\MarketAccount;
use App\Models\TreasuryHolding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O serviço logístico público do §07 (D-76).
 *
 * "O comprador agenda retirada com veículo próprio ou paga serviço logístico público." As
 * arbitragens do usuário (2026-07-13): Garagem REAL de 10 caminhões (expansível), 1 F$ + 0,02
 * F$/slot (painel do operador), só a doca do Mercado. E a regra que ninguém arbitrou porque já
 * estava arbitrada: **a entrega paga tributo na chegada** (D-32) — frete não é rota de fuga.
 */
class FretePublicoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\ComponentRecipeSeeder::class);
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
        $this->seed(\Database\Seeders\TransportSettingSeeder::class);
        $this->seed(\Database\Seeders\TreasurySeeder::class);
        $this->seed(\Database\Seeders\GaragemSeeder::class);
    }

    private int $proximo = 0;

    /** Colônia a 40 slots da Capital, com lote na doca e Fert$ no caixa. */
    private function colono(int $deposito = 5_000): User
    {
        $user = User::factory()->create(['tutorial_completed_at' => now()]);
        $colony = app(CreateColony::class)->handle($user, 'Base', 40, 0 + $this->proximo++);

        MarketAccount::create([
            'colony_id' => $colony->id, 'resource_type' => 'metal_bruto', 'amount' => $deposito,
        ]);

        return $user->fresh();
    }

    // ---------------------------------------------------------------- a Garagem

    public function test_a_garagem_nasce_com_dez_caminhoes_e_o_seeder_e_idempotente(): void
    {
        $this->assertSame(10, Garagem::frota()->count());
        $this->assertSame(10, Garagem::livres()->count());

        // Rodar de novo não duplica: o seeder completa até 10, não soma 10.
        $this->seed(\Database\Seeders\GaragemSeeder::class);
        $this->assertSame(10, Garagem::frota()->count());

        // E são caminhões de verdade, com placa — mas de NINGUÉM: nem Pátio cobra, nem Vagas conta.
        $this->assertStringEndsWith('-C', Garagem::livres()->first()->plate);
        $this->assertNull(Garagem::livres()->first()->colony_id);
    }

    public function test_a_garagem_nao_se_confunde_com_a_prateleira_de_venda(): void
    {
        // Um caminhão à venda (status estoque) não é frota de frete.
        \App\Models\Vehicle::create([
            'colony_id' => null, 'type' => 'caminhao_de_carga', 'level' => 1,
            'status' => 'estoque', 'capacity' => \App\Models\Vehicle::CAPACIDADE['caminhao_de_carga'],
        ]);

        $this->assertSame(10, Garagem::frota()->count(), 'a prateleira não engorda a Garagem');
    }

    // ---------------------------------------------------------------- o frete

    public function test_o_frete_cobra_o_preco_do_operador_e_credita_o_tesouro(): void
    {
        $user = $this->colono();
        $colony = $user->colony;
        $fertAntes = (int) $colony->fert_micro;
        $tesouroAntes = (int) TreasuryHolding::whereKey(\App\Domain\Treasury\Tesouro::FERT)->value('amount');

        // A 40 slots: 1 F$ + 40 × 0,02 = 1,80 F$ (os padrões do painel — arbitragem do usuário).
        $orcamento = app(FretePublico::class)->orcamento($colony);
        $this->assertSame(1_800_000, $orcamento['preco_micro']);

        $this->actingAs($user)->postJson('/market/freight', ['cargo' => ['metal_bruto' => 3_000]])
            ->assertCreated();

        $this->assertSame($fertAntes - 1_800_000, (int) $colony->fresh()->fert_micro);
        $this->assertSame(
            $tesouroAntes + 1_800_000,
            (int) TreasuryHolding::whereKey(\App\Domain\Treasury\Tesouro::FERT)->value('amount'),
            'o frete é receita de serviço público: vai ao Tesouro (§07)',
        );

        // A carga saiu do depósito no embarque, como na retirada própria.
        $this->assertSame(2_000, (int) MarketAccount::where('colony_id', $colony->id)
            ->where('resource_type', 'metal_bruto')->value('amount'));

        $this->assertSame(9, Garagem::livres()->count(), 'um caminhão saiu da Garagem');
    }

    public function test_a_entrega_chega_com_tributo_e_o_caminhao_volta_a_garagem(): void
    {
        $user = $this->colono();
        $colony = $user->colony;
        $estoqueAntes = (int) $colony->resources()->where('resource_type', 'metal_bruto')->value('amount');

        app(FretePublico::class)->despachar($colony, ['metal_bruto' => 1_000]);

        // 40 slots a 1,5 slots/min ≈ 27 min. Ida:
        $this->travelTo(now()->addMinutes(28));
        app(ConcluirTrechos::class)->handle();

        /*
         * O TRIBUTO na chegada (D-32): 3% de 1.000 = 30 retidos; 970 entram. Um frete isento
         * seria rota de fuga — bastaria "fretar" em vez de buscar com veículo próprio.
         */
        $this->assertSame(
            $estoqueAntes + 970,
            (int) $colony->resources()->where('resource_type', 'metal_bruto')->value('amount'),
        );

        // E a volta: o caminhão devolve-se à Garagem, livre e sem desgaste (o governo absorve).
        $this->travelTo(now()->addMinutes(28));
        app(ConcluirTrechos::class)->handle();

        $caminhao = Garagem::livres()->orderByDesc('id')->first();
        $this->assertSame(10, Garagem::livres()->count(), 'a frota inteira de volta');
        $this->assertSame(10_000, (int) $caminhao->conservacao_bps, 'o frete não desgasta a frota pública');
    }

    public function test_com_a_garagem_toda_na_estrada_o_frete_recusa(): void
    {
        $user = $this->colono(50_000);

        // Dez fretes esvaziam a Garagem…
        for ($i = 0; $i < 10; $i++) {
            app(FretePublico::class)->despachar($user->colony->fresh(), ['metal_bruto' => 100]);
        }

        // …e o décimo primeiro bate na porta.
        try {
            app(FretePublico::class)->despachar($user->colony->fresh(), ['metal_bruto' => 100]);
            $this->fail('Fretou sem caminhão livre.');
        } catch (DomainRuleException $e) {
            $this->assertSame('garagem_vazia', $e->codigo);
        }
    }

    public function test_as_recusas_honestas(): void
    {
        $user = $this->colono();
        $colony = $user->colony;

        // Mais que a capacidade do caminhão (30.000).
        $this->actingAs($user)->postJson('/market/freight', ['cargo' => ['metal_bruto' => 30_001]])
            ->assertStatus(422)->assertJsonPath('code', 'carga_excede_capacidade');

        // Mais do que há no depósito.
        $this->actingAs($user)->postJson('/market/freight', ['cargo' => ['metal_bruto' => 6_000]])
            ->assertStatus(422)->assertJsonPath('code', 'saldo_mercado_insuficiente');

        // Sem Fert$ para o frete.
        $colony->update(['fert_micro' => 0]);
        $this->actingAs($user)->postJson('/market/freight', ['cargo' => ['metal_bruto' => 100]])
            ->assertStatus(422)->assertJsonPath('code', 'fert_insuficiente');
    }

    public function test_a_recusa_de_fert_nao_leva_a_carga_nem_o_caminhao(): void
    {
        $user = $this->colono();
        $colony = $user->colony;
        $colony->update(['fert_micro' => 0]);

        try {
            app(FretePublico::class)->despachar($colony->fresh(), ['metal_bruto' => 100]);
        } catch (DomainRuleException) {
        }

        // A transação desfez tudo: depósito intacto, Garagem cheia.
        $this->assertSame(5_000, (int) MarketAccount::where('colony_id', $colony->id)
            ->where('resource_type', 'metal_bruto')->value('amount'));
        $this->assertSame(10, Garagem::livres()->count());
    }

    // ---------------------------------------------------------------- o painel e a conta

    public function test_a_conta_do_mercado_publica_o_orcamento_do_frete(): void
    {
        $this->actingAs($this->colono())->getJson('/market/account')
            ->assertOk()
            ->assertJsonPath('frete.preco_fert', 1.8)
            ->assertJsonPath('frete.capacidade', 30_000)
            ->assertJsonPath('frete.caminhoes_livres', 10);
    }

    public function test_o_painel_grava_os_sete_parametros_e_a_garagem_encomenda(): void
    {
        $admin = \App\Models\Admin::create([
            'name' => 'Op', 'email' => 'op@fertways.test',
            'password' => \Illuminate\Support\Facades\Hash::make('segredo-forte-1234'),
            'role' => \App\Models\Admin::OPERADOR,
        ]);

        $this->actingAs($admin, 'admin')->post('/admin/transporte', [
            'desgaste_bps_por_hora' => 50,
            'piso_desempenho_bps' => 2_500,
            'manutencao_bps_do_custo' => 1_000,
            'perda_de_teto_bps' => 500,
            'furgao_preco_referencia_micro' => 60_000_000,
            'frete_base_micro' => 2_000_000,
            'frete_por_slot_micro' => 50_000,
        ])->assertRedirect();

        $c = \App\Models\TransportSetting::singleton()->fresh();
        $this->assertSame(2_000_000, $c->frete_base_micro);
        $this->assertSame(50_000, $c->frete_por_slot_micro);

        // E a Garagem cresce por encomenda, conforme a demanda (arbitragem do usuário).
        $this->actingAs($admin, 'admin')->post('/admin/garagem')->assertRedirect();
        $this->assertSame(11, Garagem::frota()->count());
        $this->assertDatabaseHas('audit_log', ['acao' => 'garagem.encomendar']);
    }
}
