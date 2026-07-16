<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Colony\Slots;
use App\Domain\Production\ColonyTick;
use App\Models\Building;
use App\Models\BuildQueue;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Os 21 slots, o miolo erguido, a repetição e a demolição (D-59).
 *
 * Nada disto está no GDD — ele não tem conceito de slot, não demole e não repete. É tudo
 * arbitragem do usuário, e é por isso que precisa de teste: a única coisa que a segura é este
 * arquivo.
 */
class SlotsDaColoniaTest extends TestCase
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

    private function colono(): User
    {
        $user = User::factory()->create(['tutorial_completed_at' => now()]);
        app(CreateColony::class)->handle($user, 'Nova Aurora', 10 + $this->proximoSlot++, 20);

        return $user->fresh();
    }

    private function darRecursos(Colony $colony, int $qtd = 100_000): void
    {
        $colony->resources()->update(['amount' => $qtd]);
    }

    // ---- O miolo ----

    public function test_a_colonia_nasce_com_as_cinco_essenciais_erguidas_no_miolo(): void
    {
        $colony = $this->colono()->colony;

        $this->assertCount(5, $colony->buildings);

        foreach (Slots::MIOLO as $tipo => $slot) {
            $b = $colony->buildings->firstWhere('type', $tipo);
            $this->assertSame(1, $b->level, $tipo);
            $this->assertSame($slot, $b->slot, $tipo);
        }

        // O trio que o usuário nomeou fica no centro da linha do meio.
        $this->assertSame(10, Slots::MIOLO['reator_de_energia']);
        $this->assertSame([9, 11], [Slots::MIOLO['gerador_de_atmosfera'], Slots::MIOLO['estrutura_de_sobrevivencia']]);
    }

    /** O miolo é emissão do Governo: aparece no ledger, como todo Fert$ e todo recurso do jogo. */
    public function test_o_miolo_e_lancado_como_subsidio_no_ledger(): void
    {
        $colony = $this->colono()->colony;

        foreach (Building::ESSENCIAIS as $tipo) {
            $this->assertTrue(
                Ledger::where(['colony_id' => $colony->id, 'type' => 'subsidio_governo'])
                    ->where('ref', "build:{$tipo}:n1")->exists(),
                $tipo,
            );
        }
    }

    public function test_nenhuma_construcao_de_progressao_nasce_com_a_colonia(): void
    {
        $colony = $this->colono()->colony;

        foreach (Building::PROGRESSAO as $tipo) {
            $this->assertNull($colony->buildings->firstWhere('type', $tipo), $tipo);
        }
    }

    // ---- Construir num slot ----

    public function test_erguer_ocupa_o_slot_escolhido_e_enfileira_o_nivel_1(): void
    {
        $user = $this->colono();
        $this->darRecursos($user->colony);

        $this->actingAs($user)->postJson('/buildings', ['type' => 'mina_local', 'slot' => 0])
            ->assertCreated()
            ->assertJsonPath('slot', 0)
            ->assertJsonPath('target_level', 1);

        $mina = $user->colony->buildings()->where('type', 'mina_local')->first();
        $this->assertSame(0, $mina->slot);
        $this->assertSame(0, $mina->level);   // ainda em obra
        $this->assertSame('building', BuildQueue::where('building_id', $mina->id)->value('status'));
    }

    public function test_dois_predios_nao_cabem_no_mesmo_slot(): void
    {
        $user = $this->colono();
        $this->darRecursos($user->colony);

        $this->actingAs($user)->postJson('/buildings', ['type' => 'mina_local', 'slot' => 3])->assertCreated();

        $this->actingAs($user)->postJson('/buildings', ['type' => 'laboratorio', 'slot' => 3])
            ->assertStatus(422)
            ->assertJsonPath('code', 'slot_ocupado');
    }

    public function test_o_miolo_nao_e_do_colono(): void
    {
        $user = $this->colono();
        $this->darRecursos($user->colony);

        $this->actingAs($user)->postJson('/buildings', ['type' => 'mina_local', 'slot' => Slots::MIOLO['reator_de_energia']])
            ->assertStatus(422)
            ->assertJsonPath('code', 'slot_do_miolo');
    }

    public function test_essencial_nao_se_ergue_de_novo(): void
    {
        $user = $this->colono();
        $this->darRecursos($user->colony);

        // Se isto passasse, o subsídio do §24.7 viraria torneira: a segunda Fazenda de graça.
        $this->actingAs($user)->postJson('/buildings', ['type' => 'fazenda', 'slot' => 0])
            ->assertStatus(422)
            ->assertJsonPath('code', 'essencial_ja_existe');
    }

    public function test_slot_fora_da_colmeia_e_recusado(): void
    {
        $user = $this->colono();
        $this->darRecursos($user->colony);

        $this->actingAs($user)->postJson('/buildings', ['type' => 'mina_local', 'slot' => Slots::TOTAL])
            ->assertStatus(422);
    }

    /**
     * A obra falha por falta de recurso DEPOIS de a linha ser criada. Se a transação não a
     * desfizesse, o slot ficaria ocupado por um prédio fantasma que ninguém está construindo.
     */
    public function test_obra_que_nao_paga_nao_deixa_predio_fantasma_no_slot(): void
    {
        $user = $this->colono();   // sem recursos, fora os raros do kit

        $this->actingAs($user)->postJson('/buildings', ['type' => 'mina_local', 'slot' => 0])
            ->assertStatus(422)
            ->assertJsonPath('code', 'recursos_insuficientes');

        $this->assertNull($user->colony->fresh()->buildings->firstWhere('slot', 0));
    }

    // ---- Repetição ----

    public function test_produtora_de_progressao_repete_e_a_producao_soma(): void
    {
        $user = $this->colono();
        $colony = $user->colony;
        $this->zerarMiolo($colony);   // isola a Mina: o miolo produziria por fora

        // Duas Minas nível 1: 15 + 15 = 30 Metal Bruto/h (§04).
        $this->erguerPredio($colony, 'mina_local', 1);
        $this->erguerPredio($colony, 'mina_local', 1);

        $this->assertSame(2, $colony->fresh()->buildings()->where('type', 'mina_local')->count());

        $colony->resources()->where('resource_type', 'metal_bruto')->update(['amount' => 0]);
        $colony->update(['last_tick_at' => now()->subHour()]);
        app(ColonyTick::class)->handle($colony->fresh(), now());

        // O bug que este teste existe para pegar: indexar as specs por TIPO faria a segunda Mina
        // sumir em silêncio, e a produção sairia 15.
        $this->assertSame(30, $colony->resources()->where('resource_type', 'metal_bruto')->value('amount'));
    }

    public function test_construcao_unica_nao_repete(): void
    {
        $user = $this->colono();
        $this->darRecursos($user->colony);

        $this->actingAs($user)->postJson('/buildings', ['type' => 'laboratorio', 'slot' => 0])->assertCreated();

        $this->actingAs($user)->postJson('/buildings', ['type' => 'laboratorio', 'slot' => 1])
            ->assertStatus(422)
            ->assertJsonPath('code', 'construcao_unica');
    }

    /** Cada Oficina tem a sua receita (§24.5), e duas Oficinas não misturam as contas. */
    public function test_duas_oficinas_com_receitas_distintas_convertem_cada_uma_a_sua(): void
    {
        $user = $this->colono();
        $colony = $user->colony;
        $this->zerarMiolo($colony);

        $a = $this->erguerPredio($colony, 'oficina', 1);
        $b = $this->erguerPredio($colony, 'oficina', 1);
        $a->update(['recipe' => 'basica']);
        $b->update(['recipe' => 'avancada']);

        $this->darRecursos($colony);
        $colony->update(['last_tick_at' => now()->subHour()]);
        app(ColonyTick::class)->handle($colony->fresh(), now());

        // 15/h cada uma: 30 Componentes na hora.
        $this->assertSame(
            100_000 + 30,
            $colony->resources()->where('resource_type', 'componentes_eletronicos')->value('amount'),
        );

        // E cada receita comeu o SEU insumo: o Ouro só entra na Avançada.
        $ouro = $colony->resources()->where('resource_type', 'ouro')->value('amount');
        $this->assertLessThan(100_000, $ouro);
    }

    // ---- Demolição ----

    public function test_demolir_libera_o_slot(): void
    {
        $user = $this->colono();
        $colony = $user->colony;
        $mina = $this->erguerPredio($colony, 'mina_local', 3);
        $slot = $mina->slot;

        /*
         * D-61: sem a palavra escrita, a API RECUSA — e é aqui que a guarda tem de estar.
         *
         * Uma confirmação que só existisse no React protegeria contra o dedo escorregando e contra
         * mais nada: quem chamasse a API direto demoliria sem digitar coisa alguma. Estes três casos
         * (nada, palavra errada, minúscula) são as três maneiras de tentar passar por cima dela.
         */
        foreach ([[], ['confirmacao' => 'sim'], ['confirmacao' => 'demolir']] as $tentativa) {
            $this->actingAs($user)->deleteJson("/buildings/{$mina->id}", $tentativa)
                ->assertStatus(422)
                ->assertJsonPath('code', 'confirmacao_invalida');
        }

        $this->assertNotNull($colony->fresh()->buildings->firstWhere('slot', $slot), 'e a Mina continua de pé');

        $this->actingAs($user)->deleteJson("/buildings/{$mina->id}", ["confirmacao" => "DEMOLIR"])
            ->assertOk()
            ->assertJsonPath('demolida', true);

        $this->assertNull($colony->fresh()->buildings->firstWhere('slot', $slot));

        // O slot vazio aceita outra construção — e o investido não volta (nenhum crédito no ledger).
        $this->darRecursos($colony);
        $this->actingAs($user)->postJson('/buildings', ['type' => 'laboratorio', 'slot' => $slot])
            ->assertCreated();
    }

    public function test_essencial_nao_se_demole(): void
    {
        $user = $this->colono();
        $reator = $user->colony->buildings->firstWhere('type', 'reator_de_energia');

        // Demolir o Reator deixaria a colônia sem energia, e o GDD não diz o que acontece então.
        $this->actingAs($user)->deleteJson("/buildings/{$reator->id}", ["confirmacao" => "DEMOLIR"])
            ->assertStatus(422)
            ->assertJsonPath('code', 'essencial_indemolivel');

        $this->assertNotNull($user->colony->fresh()->buildings->firstWhere('type', 'reator_de_energia'));
    }

    public function test_nao_se_demole_o_que_esta_em_obra(): void
    {
        $user = $this->colono();
        $this->darRecursos($user->colony);

        $this->actingAs($user)->postJson('/buildings', ['type' => 'mina_local', 'slot' => 0])->assertCreated();
        $mina = $user->colony->fresh()->buildings->firstWhere('type', 'mina_local');

        $this->actingAs($user)->deleteJson("/buildings/{$mina->id}", ["confirmacao" => "DEMOLIR"])
            ->assertStatus(422)
            ->assertJsonPath('code', 'demolir_em_obra');
    }

    // ---- O que a tela precisa ----

    public function test_o_catalogo_diz_o_que_cada_construcao_faz_e_o_que_ainda_nao_faz(): void
    {
        $user = $this->colono();

        $r = $this->actingAs($user)->getJson('/buildings/catalogo')->assertOk();

        $this->assertSame([4, 4, 5, 4, 4], $r->json('slots.linhas'));
        $this->assertSame(21, $r->json('slots.total'));
        // Os cinco do miolo já estão ocupados na fundação.
        $this->assertCount(5, $r->json('ocupados'));

        $catalogo = collect($r->json('buildings'))->keyBy('type');

        // As 12 de progressão — e nenhuma essencial, que não se ergue de novo.
        $this->assertCount(count(Building::PROGRESSAO), $catalogo);
        $this->assertNull($catalogo->get('fazenda'));

        // A frase do GDD, com a fonte.
        $this->assertSame('Pesquisa tecnológica.', $catalogo['laboratorio']['funcao']['frase']);
        $this->assertSame('§17.2', $catalogo['laboratorio']['funcao']['fonte']);

        // E a verdade sobre o que ela faz HOJE, que é nada.
        $this->assertStringContainsString('não existe', $catalogo['laboratorio']['funcao']['nota']);
        $this->assertSame('nenhum', $catalogo['laboratorio']['funcao']['efeito']);

        // A Mina produz de verdade, e pode repetir.
        $this->assertSame('produz', $catalogo['mina_local']['funcao']['efeito']);
        $this->assertTrue($catalogo['mina_local']['repetivel']);
        $this->assertFalse($catalogo['laboratorio']['repetivel']);
    }

    public function test_a_unica_erguida_some_do_catalogo_e_a_repetivel_fica(): void
    {
        $user = $this->colono();
        $this->erguerPredio($user->colony, 'laboratorio', 1);
        $this->erguerPredio($user->colony, 'mina_local', 1);

        $catalogo = collect($this->actingAs($user)->getJson('/buildings/catalogo')->json('buildings'))
            ->keyBy('type');

        $this->assertFalse($catalogo['laboratorio']['disponivel']);
        $this->assertTrue($catalogo['mina_local']['disponivel']);
        $this->assertSame(1, $catalogo['mina_local']['quantas']);
    }

    public function test_o_detalhe_traz_a_funcao_o_slot_e_o_efeito_por_nivel(): void
    {
        $user = $this->colono();

        $detalhe = collect($this->actingAs($user)->getJson('/buildings')->assertOk()->json())
            ->keyBy('type');

        $reator = $detalhe['reator_de_energia'];

        $this->assertSame(Slots::MIOLO['reator_de_energia'], $reator['slot']);
        $this->assertTrue($reator['essencial']);
        $this->assertFalse($reator['demolivel']);
        $this->assertSame('Produção de energia do slot.', $reator['funcao']['frase']);

        // O que ele faz agora, e o que passaria a fazer — §19.2: 150 no nível 1, 225 no 2.
        $this->assertSame(150, $reator['efeito_atual']['producao_hora']['energia']);
        $this->assertSame(225, $reator['efeito_proximo']['producao_hora']['energia']);

        // O custo existe, mas é a tela que decide só mostrá-lo atrás do botão Evoluir.
        $this->assertSame(2, $reator['next_level']);
        $this->assertTrue($reator['subsidized']);
    }

    /**
     * A Indústria Siderúrgica (D-82) reaproveita a chave `metal_bruto` da Mina Local dentro de
     * `producao_hora_json`, mas ali é o que ela PROCESSA por hora, não o que produz. Sem
     * distinção, o painel dizia "Produz por hora: Metal Bruto: 15" — o oposto do que ela faz.
     * `producao_hora` tem de vir vazio, e o insumo tem de vir à parte, em `insumo_hora`.
     */
    public function test_a_siderurgica_nao_aparece_como_produtora_de_metal_bruto(): void
    {
        $user = $this->colono();
        $this->erguerPredio($user->colony, 'industria_siderurgica', 1);

        $siderurgica = collect($this->actingAs($user)->getJson('/buildings')->assertOk()->json())
            ->keyBy('type')['industria_siderurgica'];

        $this->assertNull($siderurgica['efeito_atual']['producao_hora']);
        $this->assertSame(15, $siderurgica['efeito_atual']['insumo_hora']['metal_bruto']);
    }

    // ---- O backfill das colônias antigas ----

    public function test_o_backfill_ergue_o_miolo_preserva_niveis_e_apaga_o_nivel_zero(): void
    {
        $user = $this->colono();
        $colony = $user->colony;

        // Reconstrói o mundo velho: tudo no nível 0, sem slot, como era antes do D-59.
        $colony->buildings()->delete();
        $colony->buildings()->createMany(
            array_map(fn (string $t) => ['type' => $t, 'level' => 0, 'slot' => null], Building::MVP),
        );
        // ...com uma Oficina já erguida, que não pode perder o nível.
        $colony->buildings()->where('type', 'oficina')->update(['level' => 4]);

        $this->artisan('fertways:slots --aplicar')->assertSuccessful();

        $colony = $colony->fresh();

        // O miolo subiu ao nível 1 e foi para o lugar dele.
        foreach (Slots::MIOLO as $tipo => $slot) {
            $b = $colony->buildings->firstWhere('type', $tipo);
            $this->assertSame(1, $b->level, $tipo);
            $this->assertSame($slot, $b->slot, $tipo);
        }

        // A Oficina manteve o nível 4 e ganhou um slot de fora do miolo.
        $oficina = $colony->buildings->firstWhere('type', 'oficina');
        $this->assertSame(4, $oficina->level);
        $this->assertContains($oficina->slot, Slots::livres());

        // As de nível 0 sumiram: agora significam "slot vazio".
        $this->assertCount(6, $colony->buildings);   // 5 do miolo + a Oficina
    }

    public function test_o_backfill_e_idempotente(): void
    {
        $user = $this->colono();
        $this->erguerPredio($user->colony, 'mina_local', 2);

        $this->artisan('fertways:slots --aplicar')->assertSuccessful();
        $antes = $user->colony->fresh()->buildings->map(fn ($b) => "{$b->type}:{$b->level}:{$b->slot}")->sort()->values();
        $subsidios = Ledger::where('type', 'subsidio_governo')->count();

        $this->artisan('fertways:slots --aplicar')->assertSuccessful();
        $depois = $user->colony->fresh()->buildings->map(fn ($b) => "{$b->type}:{$b->level}:{$b->slot}")->sort()->values();

        $this->assertSame($antes->all(), $depois->all());
        // E não concedeu o subsídio do miolo duas vezes.
        $this->assertSame($subsidios, Ledger::where('type', 'subsidio_governo')->count());
    }

    public function test_o_backfill_nao_cancela_obra_em_curso(): void
    {
        $user = $this->colono();
        $this->darRecursos($user->colony);

        $this->actingAs($user)->postJson('/buildings', ['type' => 'destilaria', 'slot' => 2])->assertCreated();
        $destilaria = $user->colony->fresh()->buildings->firstWhere('type', 'destilaria');

        $this->artisan('fertways:slots --aplicar')->assertSuccessful();

        // Está no nível 0, mas está NA FILA: apagá-la seria roubar a obra do colono.
        $this->assertNotNull($destilaria->fresh());
        $this->assertSame('building', BuildQueue::where('building_id', $destilaria->id)->value('status'));
    }
}
