<?php

namespace Tests\Feature;

use App\Domain\Chat\PurgarMensagens;
use App\Domain\Colony\CreateColony;
use App\Models\ChatMessage;
use App\Models\Federation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * O canal de chat da Federação (§10.1/§10.2; docs/decisoes.md D-115) — congela o pertencimento no
 * envio, como a vizinhança congela a posição; isento de filtro E de silêncio (julgamento do
 * desenvolvedor, ver D-115).
 */
class ChatFederacaoTest extends TestCase
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

    private function colono(string $nick): User
    {
        $user = User::factory()->create(['nickname' => $nick, 'tutorial_completed_at' => now()]);
        app(CreateColony::class)->handle($user, "Colônia {$nick}", 10 + $this->proximoSlot++, 20);

        return $user->fresh();
    }

    private function naFederacao(User $user, int $federationId, string $role = Federation::MEMBRO): void
    {
        $user->colony->update(['federation_id' => $federationId, 'federation_role' => $role]);
    }

    public function test_membro_escreve_e_le_no_canal_da_propria_federacao(): void
    {
        $fed = Federation::create(['name' => 'Aliança']);
        $a = $this->colono('a');
        $b = $this->colono('b');
        $this->naFederacao($a, $fed->id, Federation::LIDER);
        $this->naFederacao($b, $fed->id);

        $this->actingAs($a)->postJson('/chat/federacao', ['body' => 'Alguém pode reforçar a zona 12?'])
            ->assertCreated();

        $this->actingAs($b)->getJson('/chat/federacao')
            ->assertOk()
            ->assertJsonPath('mensagens.0.body', 'Alguém pode reforçar a zona 12?')
            ->assertJsonPath('mensagens.0.de.nickname', 'a');
    }

    public function test_quem_nao_tem_federacao_nao_escreve_nem_le(): void
    {
        $sem = $this->colono('sem');

        $this->actingAs($sem)->postJson('/chat/federacao', ['body' => 'oi'])
            ->assertStatus(422)->assertJsonPath('code', 'sem_federacao');

        $this->actingAs($sem)->getJson('/chat/federacao')
            ->assertStatus(422)->assertJsonPath('code', 'sem_federacao');
    }

    public function test_duas_federacoes_nao_vazam_mensagem_uma_pra_outra(): void
    {
        $fedA = Federation::create(['name' => 'A']);
        $fedB = Federation::create(['name' => 'B']);
        $a = $this->colono('a');
        $b = $this->colono('b');
        $this->naFederacao($a, $fedA->id, Federation::LIDER);
        $this->naFederacao($b, $fedB->id, Federation::LIDER);

        $this->actingAs($a)->postJson('/chat/federacao', ['body' => 'segredo da A'])->assertCreated();

        $this->actingAs($b)->getJson('/chat/federacao')->assertOk()->assertJsonCount(0, 'mensagens');
    }

    public function test_termo_vedado_nao_bloqueia_federacao(): void
    {
        $fed = Federation::create(['name' => 'Aliança']);
        $a = $this->colono('a');
        $this->naFederacao($a, $fed->id, Federation::LIDER);

        \App\Models\ChatSetting::singleton()->update(['termos_vedados' => ['proibidissimo']]);

        // O mesmo termo bloquearia no Global (§10.2), mas federação é isenta, como a privada.
        $this->actingAs($a)->postJson('/chat/federacao', ['body' => 'isso aqui é proibidissimo'])
            ->assertCreated();
    }

    public function test_silencio_nao_bloqueia_federacao(): void
    {
        $fed = Federation::create(['name' => 'Aliança']);
        $a = $this->colono('a');
        $this->naFederacao($a, $fed->id, Federation::LIDER);

        \App\Models\Punishment::create([
            'user_id' => $a->id, 'kind' => \App\Domain\Ministry\PunicaoSpecs::SILENCIO,
            'index_name' => 'conduta_social', 'points' => 0,
            'applied_at' => now(), 'expires_at' => now()->addDay(),
        ]);

        $this->actingAs($a)->postJson('/chat/global', ['body' => 'oi'])
            ->assertStatus(422)->assertJsonPath('code', 'silenciado');

        // A mesma pena não alcança a federação — círculo de aliados, não a praça.
        $this->actingAs($a)->postJson('/chat/federacao', ['body' => 'oi'])->assertCreated();
    }

    public function test_mencao_na_federacao_acende_o_selo(): void
    {
        $fed = Federation::create(['name' => 'Aliança']);
        $a = $this->colono('a');
        $b = $this->colono('aliada');
        $this->naFederacao($a, $fed->id, Federation::LIDER);
        $this->naFederacao($b, $fed->id);

        $this->actingAs($a)->postJson('/chat/federacao', ['body' => 'e aí @aliada, bora?'])->assertCreated();

        $this->actingAs($b)->getJson('/chat/pendencias')
            ->assertOk()
            ->assertJsonPath('mencoes_por_canal.federacao', 1);
    }

    public function test_purga_respeita_o_prazo_de_180_dias_da_federacao(): void
    {
        $fed = Federation::create(['name' => 'Aliança']);
        $a = $this->colono('a');
        $this->naFederacao($a, $fed->id, Federation::LIDER);

        $velha = ChatMessage::create([
            'user_id' => $a->id, 'channel' => 'federacao', 'federation_id' => $fed->id,
            'body' => 'antiga', 'created_at' => now()->subDays(181),
        ]);
        $nova = ChatMessage::create([
            'user_id' => $a->id, 'channel' => 'federacao', 'federation_id' => $fed->id,
            'body' => 'recente', 'created_at' => now()->subDays(10),
        ]);

        app(PurgarMensagens::class)->handle(Carbon::now());

        $this->assertModelMissing($velha);
        $this->assertModelExists($nova);
    }
}
