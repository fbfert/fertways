<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Models\Building;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ColonyCreationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `resources` tem FK para `resource_types.code`, então nenhuma colônia pode ser fundada
     * num banco sem catálogo. Vale em produção também: `db:seed` é pré-requisito do primeiro
     * jogador, não um detalhe de teste.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\ComponentRecipeSeeder::class);
        // O kit de raros é somado de building_specs, então o catálogo de construções
        // também é pré-requisito para fundar colônia. Ver D-17.
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
    }

    private function colono(): User
    {
        return User::factory()->create();
    }

    public function test_funda_colonia_com_o_kit_inicial_do_gdd(): void
    {
        $user = $this->colono();

        $resposta = $this->actingAs($user)->postJson('/api/colony', ['name' => 'Nova Aurora']);

        $resposta->assertCreated();

        $colony = $user->colony;

        // 50 Fert$ de saldo inicial (GDD, onboarding).
        $this->assertSame(Colony::SALDO_INICIAL_MICRO, $colony->fert_micro);
        $this->assertSame(50.0, (float) $resposta->json('fert'));

        // 16 construções do MVP, todas no nível 0 (não concedidas prontas).
        $this->assertCount(count(Building::MVP), $colony->buildings);
        $this->assertTrue($colony->buildings->every(fn ($b) => $b->level === 0));

        // Uma linha por recurso do catálogo. Não-raros zerados: o colono compra o primeiro
        // lote de Ligas no Mercado Central com os 50 Fert$ (§24.7).
        $this->assertCount(count(Resource::daColonia()), $colony->resources);

        $raros = \App\Models\ResourceType::where('tax_class', 'raro')->pluck('code');
        $naoRaros = $colony->resources->whereNotIn('resource_type', $raros);
        $this->assertTrue($naoRaros->every(fn ($r) => $r->amount === 0));

        // Kit de raros (D-17): exatamente a soma dos custos de nível 1 das 16 construções.
        $esperado = ['ferro_vermelho' => 1, 'gelo_de_metano' => 3, 'niobio_alienigena' => 5,
            'quartzo_piezoeletrico' => 3, 'resina_organica' => 3];
        foreach ($esperado as $codigo => $qtd) {
            $this->assertSame($qtd, $colony->resources->firstWhere('resource_type', $codigo)->amount, $codigo);
        }
        // Os demais raros do catálogo não entram no kit.
        $this->assertSame(0, $colony->resources->firstWhere('resource_type', 'plasma_fossilizado')->amount);

        // "Todo colono começa com um" Furgão (GDD, kit inicial). 6 m³ = 6.000 un (§25.4).
        $this->assertCount(1, $colony->vehicles);
        $this->assertSame('furgao_de_comercio', $colony->vehicles->first()->type);
        $this->assertSame(6_000, $colony->vehicles->first()->capacity);
    }

    /** O GDD não define teto de armazenamento do slot principal. NULL, nunca um número inventado. */
    public function test_storage_cap_fica_nulo_porque_o_gdd_nao_o_define(): void
    {
        $user = $this->colono();
        $this->actingAs($user)->postJson('/api/colony', ['name' => 'Sem Teto'])->assertCreated();

        $this->assertTrue($user->colony->resources->every(fn ($r) => $r->storage_cap === null));
    }

    /** O saldo entra como lançamento auditável, não como número mudo na coluna. */
    public function test_saldo_inicial_vira_lancamento_no_ledger(): void
    {
        $user = $this->colono();
        $this->actingAs($user)->postJson('/api/colony', ['name' => 'Auditada'])->assertCreated();

        $saldo = Ledger::where(['colony_id' => $user->colony->id, 'type' => 'saldo_inicial'])->get();
        $this->assertCount(1, $saldo);
        $this->assertSame(Colony::SALDO_INICIAL_MICRO, $saldo->first()->amount);

        // O kit de raros também é auditável: cinco lançamentos, um por raro concedido.
        $kit = Ledger::where(['colony_id' => $user->colony->id, 'type' => 'kit_inicial'])->get();
        $this->assertCount(5, $kit);
        $this->assertTrue($kit->every(fn ($l) => $l->amount > 0 && $l->resource_type !== null));
    }

    public function test_ledger_e_append_only(): void
    {
        $user = $this->colono();
        $this->actingAs($user)->postJson('/api/colony', ['name' => 'Colônia'])->assertCreated();

        $l = Ledger::first();

        $this->expectException(RuntimeException::class);
        $l->update(['amount' => 1]);
    }

    public function test_ledger_recusa_tipo_de_lancamento_desconhecido(): void
    {
        $user = $this->colono();
        $this->actingAs($user)->postJson('/api/colony', ['name' => 'Colônia'])->assertCreated();

        $this->expectException(RuntimeException::class);
        Ledger::create([
            'colony_id' => $user->colony->id,
            'type' => 'inventado',
            'amount' => 1,
            'created_at' => now(),
        ]);
    }

    /**
     * Falha no meio da fundação não pode deixar colônia sem recursos ou sem veículo:
     * são estados que nenhuma outra parte do jogo sabe consertar.
     */
    public function test_falha_no_meio_da_fundacao_nao_deixa_estado_parcial(): void
    {
        $user = $this->colono();

        $contar = fn () => [
            DB::table('colonies')->count(), DB::table('buildings')->count(),
            DB::table('resources')->count(), DB::table('vehicles')->count(),
            DB::table('ledger')->count(),
        ];
        $antes = $contar();

        $dentro = null;
        try {
            DB::transaction(function () use ($user, $contar, &$dentro) {
                app(CreateColony::class)->handle($user, 'Vai Falhar');
                // Sem isto o teste passaria mesmo se handle() não criasse nada.
                $dentro = $contar();
                throw new RuntimeException('falha simulada');
            });
        } catch (RuntimeException) {
            // esperado
        }

        $this->assertNotSame($antes, $dentro, 'handle() não criou nada — teste seria vacuoso');
        $this->assertSame($antes, $contar(), 'sobrou estado parcial após rollback');
    }

    public function test_um_jogador_funda_no_maximo_uma_colonia(): void
    {
        $user = $this->colono();
        $this->actingAs($user)->postJson('/api/colony', ['name' => 'Primeira'])->assertCreated();
        $this->actingAs($user)->postJson('/api/colony', ['name' => 'Segunda'])->assertStatus(422);

        $this->assertSame(1, Colony::where('user_id', $user->id)->count());
    }

    public function test_colonia_exige_autenticacao(): void
    {
        $this->postJson('/api/colony', ['name' => 'Anônima'])->assertUnauthorized();
        $this->getJson('/api/colony')->assertUnauthorized();
    }

    public function test_nickname_e_unico_no_servidor(): void
    {
        User::factory()->create(['nickname' => 'colono1']);

        $this->postJson('/api/register', [
            'name' => 'Outro', 'nickname' => 'colono1',
            'email' => 'outro@t.test', 'password' => 'SenhaForte#2026',
        ])->assertStatus(422)->assertJsonValidationErrors('nickname');
    }
}
