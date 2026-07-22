<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Models\Colony;
use App\Models\NeutralZone;
use App\Models\User;
use App\Models\ZoneBuild;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\ErgueEstruturasDaZona;
use Tests\TestCase;

/**
 * O perfil do colono e a lista das zonas dele (docs/decisoes.md D-69).
 *
 * O colono podia jogar, guerrear e comerciar — e **não podia trocar a própria senha**. A única forma
 * de mudar qualquer coisa da conta era pedir a um operador.
 */
class PerfilTest extends TestCase
{
    use RefreshDatabase;
    use ErgueEstruturasDaZona;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
    }

    private function colono(string $senha = 'segredo-forte-123'): Colony
    {
        $u = User::factory()->create([
            'name' => 'Ana', 'nickname' => 'ana', 'email' => 'ana@fertways.test',
            'password' => Hash::make($senha),
        ]);

        return app(CreateColony::class)->handle($u, 'Base', 20, 20);
    }

    // ── ver ─────────────────────────────────────────────────────────────────────────────────────

    public function test_o_perfil_mostra_a_conta_e_a_reputacao(): void
    {
        $c = $this->colono();

        $this->actingAs($c->user)
            ->getJson('/profile')
            ->assertOk()
            ->assertJson([
                'name' => 'Ana',
                'nickname' => 'ana',
                'email' => 'ana@fertways.test',
                'colony_name' => 'Base',
                'conciliador' => false,
            ])
            // Os quatro índices do §26.2, e todos nascem em 500 (D-49).
            ->assertJsonPath('reputacao.confianca_comercial', 500)
            ->assertJsonPath('reputacao.honra_militar_diplomatica', 500);
    }

    // ── editar ──────────────────────────────────────────────────────────────────────────────────

    public function test_edita_nome_nickname_e_nome_da_colonia_sem_senha(): void
    {
        $c = $this->colono();

        $this->actingAs($c->user)
            ->patchJson('/profile', [
                'name' => 'Ana Souza',
                'nickname' => 'anasouza',
                'email' => 'ana@fertways.test',   // não mudou
                'colony_name' => 'Nova Base',
            ])
            ->assertOk();

        $this->assertSame('anasouza', $c->user->fresh()->nickname);
        $this->assertSame('Nova Base', $c->fresh()->name);
    }

    /**
     * ⚠️ **Trocar o e-mail exige a senha atual, e trocar o nome não.**
     *
     * A diferença não é capricho: o e-mail é com o que se **entra** no jogo. Quem pegasse uma sessão
     * aberta num computador esquecido poderia trocá-lo, trocar a senha, e o dono nunca mais entraria
     * — **não há recuperação de conta em Fertways**. Um nome mal escolhido se corrige; uma conta
     * tomada, não.
     */
    public function test_trocar_o_email_exige_a_senha_atual(): void
    {
        $c = $this->colono();

        $this->actingAs($c->user)
            ->patchJson('/profile', [
                'name' => 'Ana', 'nickname' => 'ana',
                'email' => 'outro@fertways.test',
                // sem `senha_atual`
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'senha_atual_incorreta');

        $this->assertSame('ana@fertways.test', $c->user->fresh()->email, 'o e-mail não mudou');

        // Com a senha certa, passa.
        $this->actingAs($c->user)
            ->patchJson('/profile', [
                'name' => 'Ana', 'nickname' => 'ana',
                'email' => 'outro@fertways.test',
                'senha_atual' => 'segredo-forte-123',
            ])
            ->assertOk();

        $this->assertSame('outro@fertways.test', $c->user->fresh()->email);
    }

    public function test_nickname_duplicado_e_recusado(): void
    {
        $this->colono();
        $outro = User::factory()->create(['nickname' => 'bob', 'email' => 'bob@fertways.test']);

        $this->actingAs(User::where('nickname', 'ana')->first())
            ->patchJson('/profile', [
                'name' => 'Ana', 'nickname' => 'bob', 'email' => 'ana@fertways.test',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('nickname');

        $this->assertSame('bob', $outro->fresh()->nickname);
    }

    // ── a senha ─────────────────────────────────────────────────────────────────────────────────

    /**
     * **Trocar a senha REVOGA as outras sessões — e isso é o ponto, não um detalhe.**
     *
     * Se o colono está trocando a senha porque desconfia que alguém entrou na conta dele, uma senha
     * nova **sem revogar os tokens não expulsa ninguém**: o token do Sanctum não expira, e o invasor
     * continua dentro com a chave antiga. É a lição do D-53, o logout que não revogava.
     */
    public function test_trocar_a_senha_revoga_as_outras_sessoes_e_mantem_a_atual(): void
    {
        $c = $this->colono();
        $u = $c->user;

        // Três sessões abertas: um computador, um celular, e o invasor.
        $outra = $u->createToken('celular')->plainTextToken;
        $u->createToken('invasor');
        $this->assertSame(2, $u->tokens()->count());

        // A sessão que faz a troca é a do celular — e ela tem de sobreviver.
        $r = $this->withHeader('Authorization', "Bearer {$outra}")
            ->postJson('/profile/password', [
                'senha_atual' => 'segredo-forte-123',
                'senha' => 'nova-senha-fortissima',
                'senha_confirmation' => 'nova-senha-fortissima',
            ])
            ->assertOk()
            ->assertJsonPath('sessoes_revogadas', 1);

        $this->assertTrue(Hash::check('nova-senha-fortissima', $u->fresh()->password));

        // Sobrou UM token: o de quem trocou a senha. O invasor foi posto para fora.
        $this->assertSame(1, $u->fresh()->tokens()->count());

        // E a sessão que trocou continua entrando.
        $this->withHeader('Authorization', "Bearer {$outra}")
            ->getJson('/profile')->assertOk();

        $this->assertNotNull($r);
    }

    public function test_a_senha_atual_errada_nao_troca_nada(): void
    {
        $c = $this->colono();

        $this->actingAs($c->user)
            ->postJson('/profile/password', [
                'senha_atual' => 'chute',
                'senha' => 'nova-senha-fortissima',
                'senha_confirmation' => 'nova-senha-fortissima',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'senha_atual_incorreta');

        $this->assertTrue(Hash::check('segredo-forte-123', $c->user->fresh()->password));
    }

    /** Os quatro índices do §26.2 NÃO se editam: seria o colono apagar as próprias condenações. */
    public function test_a_reputacao_nao_se_edita_pelo_perfil(): void
    {
        $c = $this->colono();

        $this->actingAs($c->user)->patchJson('/profile', [
            'name' => 'Ana', 'nickname' => 'ana', 'email' => 'ana@fertways.test',
            'confianca_comercial' => 1000,
            'reputacao' => ['confianca_comercial' => 1000],
        ])->assertOk();

        $this->assertSame(500, $c->user->fresh()->confianca_comercial, 'a reputação é do Ministério');
    }

    // ── as minhas zonas ─────────────────────────────────────────────────────────────────────────

    /**
     * A lista da barra lateral. O que ela mostra é o que **exige ação**: o exposto ao saque, o cerco
     * e a obra em curso.
     */
    public function test_a_lista_das_minhas_zonas(): void
    {
        $c = $this->colono();
        $outro = app(CreateColony::class)->handle(User::factory()->create(), 'Outra', 25, 25);

        // A minha: 900 no depósito, 500 protegidos → 400 EXPOSTOS. Cercada, e com obra.
        $minha = $this->criarZonaComEstruturas([
            'x' => 47, 'y' => 47, 'district' => 'NE', 'mineral' => 'metal_bruto', 'level' => 1,
            'owner_colony_id' => $c->id, 'status' => 'ocupada',
            'occupied_at' => now()->subDays(30), 'productive_at' => now()->subDays(20),
            'command_post_level' => 1, 'deposit_level' => 1, 'deposit_amount' => 900,
            'last_extraction_at' => now(), 'sieged_at' => now(),
        ]);

        ZoneBuild::create([
            'zone_id' => $minha->id, 'structure' => 'muralha_de_perimetro',
            'target_level' => 1, 'finishes_at' => now()->addHours(4),
        ]);

        // Guarnição e canteiro (D-88): o card da lateral também passou a mostrar isto.
        \App\Models\Unit::insert(array_fill(0, 3, [
            'zone_id' => $minha->id, 'colony_id' => null, 'type' => 'robo_minerador', 'level' => 1,
            'hp_bps' => \App\Models\Unit::INTEIRA, 'status' => 'na_zona',
            'created_at' => now(), 'updated_at' => now(),
        ]));
        \App\Models\ZoneMaterial::create([
            'zone_id' => $minha->id, 'resource_type' => 'metal_bruto', 'amount' => 250,
        ]);

        // A do vizinho: NÃO pode aparecer na minha lista.
        $this->criarZonaComEstruturas([
            'x' => 48, 'y' => 48, 'district' => 'NE', 'mineral' => 'metal_bruto', 'level' => 1,
            'owner_colony_id' => $outro->id, 'status' => 'ocupada',
            'occupied_at' => now(), 'command_post_level' => 1, 'deposit_level' => 1,
        ]);

        $this->actingAs($c->user)
            ->getJson('/zones/minhas')
            ->assertOk()
            ->assertJsonCount(1, 'zones')
            ->assertJsonPath('zones.0.id', $minha->id)
            ->assertJsonPath('zones.0.exposto', 400)     // é isto que um invasor leva
            ->assertJsonPath('zones.0.cercada', true)    // e isto é a urgência maior
            ->assertJsonPath('zones.0.obra.nome', 'Muralha de Perímetro')
            // D-88: mais informação no card, sem precisar abrir a zona.
            ->assertJsonPath('zones.0.level', 1)
            ->assertJsonPath('zones.0.upgrade', null)
            ->assertJsonPath('zones.0.guarnicao.robos', 3)
            ->assertJsonPath('zones.0.manutencao.inadimplente_desde', null)
            ->assertJsonPath('zones.0.canteiro.0.resource_type', 'metal_bruto')
            ->assertJsonPath('zones.0.canteiro.0.amount', 250);
    }

    /** `/zones/minhas` não pode ser confundido com uma zona de id "minhas" — a armadilha da rota. */
    public function test_a_rota_minhas_nao_colide_com_a_de_id(): void
    {
        $c = $this->colono();

        $this->actingAs($c->user)->getJson('/zones/minhas')
            ->assertOk()
            ->assertJsonStructure(['zones']);
    }

    // ── o extrato bancário ──────────────────────────────────────────────────────────────────────

    /**
     * Só Fert$ — não o ledger inteiro. `saldo_inicial` (resource_type nulo) entra;
     * `kit_inicial` de um recurso qualquer (resource_type preenchido) fica de fora.
     */
    public function test_o_extrato_so_traz_lancamentos_em_fert(): void
    {
        $c = $this->colono();

        \App\Models\Ledger::create([
            'colony_id' => $c->id, 'type' => 'venda_mercado', 'amount' => 5_000_000,
            'resource_type' => null, 'ref' => 'mercado:1', 'created_at' => now(),
        ]);
        \App\Models\Ledger::create([
            'colony_id' => $c->id, 'type' => 'kit_inicial', 'amount' => 500,
            'resource_type' => 'metal_bruto', 'ref' => 'onboarding:kit_inicial', 'created_at' => now(),
        ]);

        $resposta = $this->actingAs($c->user)->getJson('/profile/extrato')->assertOk();

        // saldo_inicial (da fundação) + venda_mercado lançada acima — nunca o kit_inicial de recurso.
        $tipos = collect($resposta->json('lancamentos'))->pluck('tipo');
        $this->assertTrue($tipos->contains('saldo_inicial'));
        $this->assertTrue($tipos->contains('venda_mercado'));
        $this->assertFalse($tipos->contains('kit_inicial'));
    }

    public function test_o_extrato_converte_micro_fert_para_fert(): void
    {
        $c = $this->colono();

        $resposta = $this->actingAs($c->user)->getJson('/profile/extrato')->assertOk();

        $saldoInicial = collect($resposta->json('lancamentos'))->firstWhere('tipo', 'saldo_inicial');
        // PHP devolve int quando a divisão é exata (100_000_000 / 1_000_000) — o JSON não distingue.
        $this->assertEquals(100, $saldoInicial['fert']);
    }

    /** Mais novo primeiro, e paginado — o extrato só cresce. */
    public function test_o_extrato_vem_paginado_do_mais_novo_para_o_mais_velho(): void
    {
        $c = $this->colono();

        foreach (range(1, 35) as $i) {
            \App\Models\Ledger::create([
                'colony_id' => $c->id, 'type' => 'venda_mercado', 'amount' => $i * 1_000_000,
                'resource_type' => null, 'ref' => "mercado:{$i}", 'created_at' => now()->addSeconds($i),
            ]);
        }

        $resposta = $this->actingAs($c->user)->getJson('/profile/extrato')->assertOk();

        $lancamentos = $resposta->json('lancamentos');
        $this->assertCount(30, $lancamentos, '30 por página');
        $this->assertSame(2, $resposta->json('ultima_pagina'));
        // 36 no total: os 35 de cima + o saldo_inicial da fundação.
        $this->assertSame(36, $resposta->json('total'));
        $this->assertSame('venda_mercado', $lancamentos[0]['tipo']);
        $this->assertEquals(35, $lancamentos[0]['fert'], 'o mais recente vem primeiro');
    }

    public function test_o_extrato_exige_colonia(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/profile/extrato')
            ->assertStatus(422)
            ->assertJsonPath('code', 'sem_colonia');
    }
}
