<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Models\Colony;
use App\Models\NeutralZone;
use App\Models\User;
use App\Models\ZoneBuild;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
        $minha = NeutralZone::create([
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

        // A do vizinho: NÃO pode aparecer na minha lista.
        NeutralZone::create([
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
            ->assertJsonPath('zones.0.obra.nome', 'Muralha de Perímetro');
    }

    /** `/zones/minhas` não pode ser confundido com uma zona de id "minhas" — a armadilha da rota. */
    public function test_a_rota_minhas_nao_colide_com_a_de_id(): void
    {
        $c = $this->colono();

        $this->actingAs($c->user)->getJson('/zones/minhas')
            ->assertOk()
            ->assertJsonStructure(['zones']);
    }
}
