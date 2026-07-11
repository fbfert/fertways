<?php

namespace Tests\Feature;

use App\Domain\Building\BuildingSpecs;
use App\Domain\Colony\CreateColony;
use App\Exceptions\DomainRuleException;
use App\Models\BuildQueue;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BuildQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\ComponentRecipeSeeder::class);
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
    }

    /** Colono com tutoria concluída, colônia fundada. */
    private int $proximoSlot = 0;

    private function colono(array $attrs = []): User
    {
        $user = User::factory()->create($attrs + ['tutorial_completed_at' => now()]);
        // Periferia, uma célula por colônia (D-51).
        app(CreateColony::class)->handle($user, 'Nova Aurora', 10 + $this->proximoSlot++, 20);

        return $user->fresh();
    }

    /**
     * Desde o D-59 a construção de progressão não existe até o colono escolher o slot dela, e a
     * essencial já nasce no nível 1. O helper do TestCase dá conta dos dois casos.
     */
    private function predio(User $user, string $tipo)
    {
        return $this->predioDe($user->colony, $tipo);
    }

    private function darRecursos(Colony $colony, int $qtd = 10_000): void
    {
        $colony->resources()->update(['amount' => $qtd]);
    }

    // ---- Subsídio (§24.7) ----

    public function test_essencial_ate_nivel_3_e_subsidiada_e_nao_gasta_recurso(): void
    {
        $user = $this->colono();
        $gerador = $this->predio($user, 'gerador_de_atmosfera');

        // Desde o D-59 a essencial NASCE no nível 1, no miolo: o primeiro upgrade que o colono
        // pede é o 1 -> 2, e o subsídio do §24.7 continua valendo (vai até o 3).
        $this->assertSame(1, $gerador->level);

        $r = $this->actingAs($user)->postJson("/buildings/{$gerador->id}/upgrade");

        $r->assertCreated()
            ->assertJsonPath('target_level', 2)
            ->assertJsonPath('subsidized', true)
            ->assertJsonPath('subsidy_message', 'Esta construção será custeada pelo Governo Central até o nível 3')
            // "o custo aparece normalmente na interface": custo do GDD, mesmo subsidiado.
            ->assertJsonPath('cost.agua', 83)
            ->assertJsonPath('cost.biomassa', 50);

        // Nenhum recurso é debitado: o Gerador n1 custa água/biomassa/energia/oxigênio,
        // e o colono continua com 0 de todos eles (o kit inicial só traz raros).
        $colony = $user->colony()->first();
        foreach (['agua', 'biomassa', 'energia', 'oxigenio'] as $r) {
            $this->assertSame(0, $colony->resources->firstWhere('resource_type', $r)->amount, $r);
        }
        $this->assertSame(0, Ledger::where('type', 'custo_construcao')->count());
    }

    public function test_essencial_no_nivel_4_deixa_de_ser_subsidiada(): void
    {
        $user = $this->colono();
        $gerador = $this->predio($user, 'gerador_de_atmosfera');
        $gerador->update(['level' => 3]);
        $this->darRecursos($user->colony);

        $this->actingAs($user)->postJson("/buildings/{$gerador->id}/upgrade")
            ->assertCreated()
            ->assertJsonPath('target_level', 4)
            ->assertJsonPath('subsidized', false);

        // Custo do nível 4 do GDD: água 225, biomassa 135, energia 45, oxigênio 22.
        $agua = $user->colony->resources()->where('resource_type', 'agua')->value('amount');
        $this->assertSame(10_000 - 225, $agua);
    }

    /**
     * D-17: a Oficina custa 1 de Ferro Vermelho no nível 1, e não há fonte de raros no MVP.
     * O kit inicial cobre exatamente o custo de nível 1 de cada construção. Sem ele, a
     * Oficina — única fonte de Ligas Metálicas — seria inconstruível.
     */
    public function test_kit_inicial_de_raros_destrava_a_oficina(): void
    {
        $user = $this->colono();
        $colony = $user->colony;

        $this->assertSame(1, $colony->resources->firstWhere('resource_type', 'ferro_vermelho')->amount);

        // Dá os não-raros; os raros vêm do kit.
        $colony->resources()->whereIn('resource_type', ['biomassa', 'ligas_metalicas', 'compostos_quimicos', 'agua', 'energia'])
            ->update(['amount' => 1000]);

        $this->actingAs($user)->postJson('/buildings/' . $this->predio($user, 'oficina')->id . '/upgrade')
            ->assertCreated();

        // O Ferro Vermelho foi consumido: o kit dá o suficiente para exatamente uma vez.
        $this->assertSame(0, $colony->fresh()->resources->firstWhere('resource_type', 'ferro_vermelho')->amount);
    }

    public function test_construcao_de_progressao_nunca_e_subsidiada(): void
    {
        $user = $this->colono();
        $oficina = $this->predio($user, 'oficina');
        $this->darRecursos($user->colony);

        $this->actingAs($user)->postJson("/buildings/{$oficina->id}/upgrade")
            ->assertCreated()
            ->assertJsonPath('subsidized', false);
    }

    /**
     * A regra do GDD ("mediante conclusão da tutoria") continua ativa no código. Hoje a
     * fundação marca a tutoria como concluída, porque as missões estão fora do MVP (D-18).
     * Aqui desfazemos essa marcação para provar que a regra existe e morde.
     */
    public function test_sem_tutoria_o_subsidio_nao_vale_e_falta_recurso(): void
    {
        $user = $this->colono();
        $user->forceFill(['tutorial_completed_at' => null])->save();

        $gerador = $this->predio($user->fresh(), 'gerador_de_atmosfera');

        $this->actingAs($user->fresh())->postJson("/buildings/{$gerador->id}/upgrade")
            ->assertStatus(422)
            ->assertJsonPath('code', 'recursos_insuficientes');
    }

    /** A fundação destrava a tutoria (stub do MVP), senão nada é construível. */
    public function test_fundacao_marca_tutoria_como_concluida(): void
    {
        $user = $this->colono();
        $this->assertNotNull($user->tutorial_completed_at);
        $this->assertTrue($user->tutoriaConcluida());
    }

    // ---- Custo congelado (§4.1) ----

    public function test_custo_e_congelado_na_confirmacao(): void
    {
        $user = $this->colono();
        $oficina = $this->predio($user, 'oficina');
        $this->darRecursos($user->colony);

        $this->actingAs($user)->postJson("/buildings/{$oficina->id}/upgrade")->assertCreated();
        $cotado = BuildQueue::first()->quoted_cost_json;

        // Rebalanceamento posterior não pode recotar o que já está na fila.
        DB::table('building_specs')->where(['building_type' => 'oficina', 'level' => 1])
            ->update(['cost_json' => json_encode(['ligas_metalicas' => 99999])]);

        $this->assertSame($cotado, BuildQueue::first()->quoted_cost_json);
        $this->assertSame(50, $cotado['ligas_metalicas']);
    }

    // ---- Largura da fila (onboarding) ----

    public function test_fila_dupla_nos_primeiros_5_dias(): void
    {
        $user = $this->colono(['created_at' => now()->subDays(2)]);
        $this->darRecursos($user->colony);

        $this->assertSame(2, BuildQueue::vagasDe($user));

        $this->actingAs($user)->postJson('/buildings/' . $this->predio($user, 'gerador_de_atmosfera')->id . '/upgrade')->assertCreated();
        $this->actingAs($user)->postJson('/buildings/' . $this->predio($user, 'fazenda')->id . '/upgrade')->assertCreated();

        // A terceira não cabe.
        $this->actingAs($user)->postJson('/buildings/' . $this->predio($user, 'oficina')->id . '/upgrade')
            ->assertStatus(422)->assertJsonPath('code', 'fila_cheia');

        // Só a primeira constrói; a segunda espera.
        $itens = BuildQueue::orderBy('position')->get();
        $this->assertSame(['building', 'queued'], $itens->pluck('status')->all());
        $this->assertNull($itens[1]->finishes_at);
    }

    public function test_a_partir_do_sexto_dia_a_fila_volta_a_ser_unica(): void
    {
        $user = $this->colono(['created_at' => now()->subDays(6)]);
        $this->darRecursos($user->colony);

        $this->assertSame(1, BuildQueue::vagasDe($user));

        $this->actingAs($user)->postJson('/buildings/' . $this->predio($user, 'gerador_de_atmosfera')->id . '/upgrade')->assertCreated();
        $this->actingAs($user)->postJson('/buildings/' . $this->predio($user, 'fazenda')->id . '/upgrade')
            ->assertStatus(422)->assertJsonPath('code', 'fila_cheia');
    }

    /** Exatamente 5 dias ainda é "dentro dos primeiros 5 dias completos". */
    public function test_fronteira_dos_5_dias(): void
    {
        $quaseSeis = User::factory()->make(['created_at' => now()->subDays(5)->addMinute()]);
        $seisDias = User::factory()->make(['created_at' => now()->subDays(5)->subMinute()]);

        $this->assertSame(2, BuildQueue::vagasDe($quaseSeis));
        $this->assertSame(1, BuildQueue::vagasDe($seisDias));
    }

    // ---- Guardas ----

    public function test_nao_enfileira_a_mesma_construcao_duas_vezes(): void
    {
        $user = $this->colono();
        $gerador = $this->predio($user, 'gerador_de_atmosfera');

        $this->actingAs($user)->postJson("/buildings/{$gerador->id}/upgrade")->assertCreated();
        $this->actingAs($user)->postJson("/buildings/{$gerador->id}/upgrade")
            ->assertStatus(422)->assertJsonPath('code', 'ja_na_fila');
    }

    public function test_nao_passa_do_nivel_maximo_do_gdd(): void
    {
        $user = $this->colono();
        $gerador = $this->predio($user, 'gerador_de_atmosfera');
        $gerador->update(['level' => 5]);   // máximo do Gerador no GDD
        $this->darRecursos($user->colony);

        $this->actingAs($user)->postJson("/buildings/{$gerador->id}/upgrade")
            ->assertStatus(422)->assertJsonPath('code', 'nivel_maximo');
    }

    public function test_nao_enfileira_construcao_de_outro_jogador(): void
    {
        $a = $this->colono();
        $b = $this->colono(['email' => 'b@t.test', 'nickname' => 'colonoB']);
        $predioDeB = $this->predio($b, 'gerador_de_atmosfera');

        $this->actingAs($a)->postJson("/buildings/{$predioDeB->id}/upgrade")
            ->assertStatus(422)->assertJsonPath('code', 'construcao_de_outra_colonia');
    }

    /** D-10: construção sem tempo publicado no GDD não pode ser enfileirada. */
    public function test_construcao_sem_tempo_no_gdd_e_bloqueada(): void
    {
        $specs = app(BuildingSpecs::class);

        $this->expectException(DomainRuleException::class);
        $this->expectExceptionMessage('O GDD não define tempo de construção para deposito_de_zona_neutra');

        $specs->para('deposito_de_zona_neutra', 1);
    }

    public function test_debito_de_recurso_vira_lancamento_no_ledger(): void
    {
        $user = $this->colono();
        $oficina = $this->predio($user, 'oficina');
        $this->darRecursos($user->colony);

        $this->actingAs($user)->postJson("/buildings/{$oficina->id}/upgrade")->assertCreated();

        $lancamentos = Ledger::where('type', 'custo_construcao')->get();
        $this->assertNotEmpty($lancamentos);
        // Sinal negativo: débito. E o ref aponta para o alvo.
        $this->assertTrue($lancamentos->every(fn ($l) => $l->amount < 0));
        $this->assertSame('build:oficina:n1', $lancamentos->first()->ref);
    }

    public function test_recursos_insuficientes_nao_deixam_debito_parcial(): void
    {
        $user = $this->colono();
        $oficina = $this->predio($user, 'oficina');
        // Oficina n1 exige biomassa 30, ligas 50, compostos 15. Damos biomassa de sobra
        // e ligas de menos: o débito da biomassa não pode persistir.
        $user->colony->resources()->where('resource_type', 'biomassa')->update(['amount' => 500]);

        $this->actingAs($user)->postJson("/buildings/{$oficina->id}/upgrade")
            ->assertStatus(422)->assertJsonPath('code', 'recursos_insuficientes');

        $this->assertSame(500, $user->colony->resources()->where('resource_type', 'biomassa')->value('amount'));
        $this->assertSame(0, Ledger::where('type', 'custo_construcao')->count());
        $this->assertSame(0, BuildQueue::count());
    }

    // ---- A fila esvaziada volta a aceitar itens (regressão) ----

    /**
     * Concluir um item deixava a linha na tabela com a sua `position`, e o índice único
     * `(colony_id, position)` vale para a tabela inteira. Como a próxima posição era o máximo
     * **entre os ativos**, a fila vazia recomeçava em 1 e colidia com o item já concluído.
     * Resultado: toda colônia que já tivesse construído algo travava com 500 ao enfileirar de novo.
     */
    public function test_enfileira_de_novo_depois_que_a_fila_esvazia(): void
    {
        $user = $this->colono();
        $gerador = $this->predio($user, 'gerador_de_atmosfera');

        $this->actingAs($user)->postJson("/buildings/{$gerador->id}/upgrade")->assertCreated();

        // O tick conclui o item: a linha vira `done` e a fila esvazia.
        $colony = $user->colony()->first();
        $colony->update(['last_tick_at' => now()]);
        app(\App\Domain\Production\ColonyTick::class)->handle($colony, now()->addDay());

        $this->assertSame('done', BuildQueue::first()->status);
        $this->assertSame(0, BuildQueue::ativos()->count());

        // Enfileirar o próximo nível do mesmo prédio não pode colidir com o já concluído. O
        // Gerador nasce no 1 (D-59), sobe ao 2 acima e vai ao 3 aqui.
        $this->actingAs($user)->postJson("/buildings/{$gerador->id}/upgrade")
            ->assertCreated()
            ->assertJsonPath('target_level', 3);

        $this->assertSame(2, BuildQueue::count());
    }
}
