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

        $resposta = $this->actingAs($user)->postJson('/colony', ['name' => 'Nova Aurora', 'x' => 0, 'y' => 1]);

        $resposta->assertCreated();

        $colony = $user->colony;

        // A colônia nasce na célula escolhida (D-51), não numa sorteada.
        $this->assertSame(0, $colony->x);
        $this->assertSame(1, $colony->y);

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
        $this->actingAs($user)->postJson('/colony', ['name' => 'Sem Teto', 'x' => 0, 'y' => 1])->assertCreated();

        $this->assertTrue($user->colony->resources->every(fn ($r) => $r->storage_cap === null));
    }

    /** O saldo entra como lançamento auditável, não como número mudo na coluna. */
    public function test_saldo_inicial_vira_lancamento_no_ledger(): void
    {
        $user = $this->colono();
        $this->actingAs($user)->postJson('/colony', ['name' => 'Auditada', 'x' => 0, 'y' => 1])->assertCreated();

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
        $this->actingAs($user)->postJson('/colony', ['name' => 'Colônia', 'x' => 0, 'y' => 1])->assertCreated();

        $l = Ledger::first();

        $this->expectException(RuntimeException::class);
        $l->update(['amount' => 1]);
    }

    public function test_ledger_recusa_tipo_de_lancamento_desconhecido(): void
    {
        $user = $this->colono();
        $this->actingAs($user)->postJson('/colony', ['name' => 'Colônia', 'x' => 0, 'y' => 1])->assertCreated();

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
                app(CreateColony::class)->handle($user, 'Vai Falhar', 0, 1);
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
        $this->actingAs($user)->postJson('/colony', ['name' => 'Primeira', 'x' => 0, 'y' => 1])->assertCreated();
        $this->actingAs($user)->postJson('/colony', ['name' => 'Segunda', 'x' => 0, 'y' => -1])->assertStatus(422);

        $this->assertSame(1, Colony::where('user_id', $user->id)->count());
    }

    /**
     * A fundação por escolha do D-51: o colono só funda em slot de founder populável ou na
     * periferia. Capital, anel livre e slots reservados são recusados com erro de domínio; a
     * célula já ocupada, também.
     */
    public function test_funda_so_em_celula_valida_e_livre(): void
    {
        // Periferia é fundável.
        $this->actingAs($this->colono())
            ->postJson('/colony', ['name' => 'Periférica', 'x' => 40, 'y' => 40])
            ->assertCreated();

        // A Capital (0,0), não.
        $this->actingAs($this->colono())
            ->postJson('/colony', ['name' => 'Na Capital', 'x' => 0, 'y' => 0])
            ->assertStatus(422)->assertJson(['code' => 'celula_invalida']);

        // O anel livre (d=4,24), não.
        $this->actingAs($this->colono())
            ->postJson('/colony', ['name' => 'No Anel', 'x' => 3, 'y' => 3])
            ->assertStatus(422)->assertJson(['code' => 'celula_invalida']);

        // Um slot de founder reservado, não: (1,0) é o índice 0 da ordem canônica, reservado.
        $this->actingAs($this->colono())
            ->postJson('/colony', ['name' => 'Reservado', 'x' => 1, 'y' => 0])
            ->assertStatus(422)->assertJson(['code' => 'celula_invalida']);

        // Fora do mapa, 422 na validação do request (|coord| > 50).
        $this->actingAs($this->colono())
            ->postJson('/colony', ['name' => 'Fora', 'x' => 60, 'y' => 0])
            ->assertStatus(422);
    }

    public function test_nao_funda_em_celula_ja_ocupada(): void
    {
        $this->actingAs($this->colono())
            ->postJson('/colony', ['name' => 'Primeiro', 'x' => 0, 'y' => 1])
            ->assertCreated();

        // Outro colono, mesma célula: recusado.
        $this->actingAs($this->colono())
            ->postJson('/colony', ['name' => 'Segundo', 'x' => 0, 'y' => 1])
            ->assertStatus(422)->assertJson(['code' => 'celula_ocupada']);
    }

    public function test_map_lista_slots_de_founder_e_ocupacao(): void
    {
        // Uma colônia num slot de founder populável (0,1) e outra na periferia (40,40).
        $dono = $this->colono();
        $this->actingAs($dono)->postJson('/colony', ['name' => 'Founder', 'x' => 0, 'y' => 1])->assertCreated();

        $resposta = $this->actingAs($this->colono())->getJson('/map')->assertOk();

        $resposta->assertJson(['side' => 101, 'capital' => ['x' => 0, 'y' => 0]]);
        $this->assertCount(48, $resposta->json('founder_slots'));

        $slots = collect($resposta->json('founder_slots'));
        $reservados = $slots->where('reservado', true);
        $this->assertCount(20, $reservados);

        // O slot (0,1) aparece ocupado; (0,-1) livre.
        $this->assertTrue($slots->firstWhere(fn ($s) => $s['x'] === 0 && $s['y'] === 1)['ocupado']);
        $this->assertFalse($slots->firstWhere(fn ($s) => $s['x'] === 0 && $s['y'] === -1)['ocupado']);
    }

    /** Sem colônia ainda, o colono precisa ver o mapa para escolher onde fundar. */
    public function test_map_nao_exige_colonia(): void
    {
        $this->actingAs($this->colono())->getJson('/map')->assertOk();
    }

    public function test_colonia_exige_autenticacao(): void
    {
        $this->postJson('/colony', ['name' => 'Anônima'])->assertUnauthorized();
        $this->getJson('/colony')->assertUnauthorized();
    }

    public function test_nickname_e_unico_no_servidor(): void
    {
        User::factory()->create(['nickname' => 'colono1']);

        $this->postJson('/register', [
            'name' => 'Outro', 'nickname' => 'colono1',
            'email' => 'outro@t.test', 'password' => 'SenhaForte#2026',
        ])->assertStatus(422)->assertJsonValidationErrors('nickname');
    }
}
