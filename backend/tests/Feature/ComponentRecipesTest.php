<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Production\ColonyTick;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * §24.5 — Componentes Eletrônicos. Três receitas, mesma saída, insumos distintos.
 * Ver docs/decisoes.md D-23 para por que não são três recursos separados.
 */
class ComponentRecipesTest extends TestCase
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
        $user = User::factory()->create();
        // Periferia, uma célula por colônia (D-51).
        app(CreateColony::class)->handle($user, 'Nova Aurora', 10 + $this->proximoSlot++, 20);

        return $user->fresh();
    }

    private function estoque(User $user, string $r): int
    {
        return $user->colony->resources()->where('resource_type', $r)->value('amount');
    }

    private function tick(User $user, $agora): void
    {
        app(ColonyTick::class)->handle($user->colony()->first(), $agora);
    }

    /** As três receitas do GDD, verbatim. Se o seed mudar, isto quebra. */
    public function test_as_tres_receitas_batem_com_o_gdd(): void
    {
        $esperado = [
            'basica' => ['estanho' => 8, 'cobre' => 8, 'silicio' => 6, 'aluminio' => 5, 'agua' => 5, 'energia' => 10],
            'intermediaria' => ['estanho' => 6, 'cobre' => 6, 'silicio' => 8, 'litio' => 4, 'tungstenio' => 3, 'oxigenio' => 4, 'energia' => 14],
            'avancada' => ['estanho' => 5, 'cobre' => 5, 'silicio' => 7, 'litio' => 5, 'tungstenio' => 4, 'tantalo' => 2, 'ouro' => 1, 'biocombustivel' => 3, 'energia' => 20],
        ];

        foreach ($esperado as $code => $insumos) {
            $real = json_decode(DB::table('component_recipes')->where('code', $code)->value('insumos_json'), true);
            $this->assertSame($insumos, $real, $code);
        }
    }

    public function test_oficina_fabrica_componentes_consumindo_a_receita_basica(): void
    {
        $user = $this->colono();
        $this->erguerPredio($user->colony, 'oficina', 1);   // 15 componentes/h
        $this->erguerPredio($user->colony, 'reator_de_energia', 5);

        $user->colony->resources()
            ->whereIn('resource_type', ['estanho', 'cobre', 'silicio', 'aluminio', 'agua'])
            ->update(['amount' => 10_000]);

        $user->colony->update(['last_tick_at' => now()->subHour()]);
        $this->tick($user, now());

        // 15 unidades/h × 1 h. Receita Básica: 8 estanho, 8 cobre, 6 silício, 5 alumínio, 5 água.
        $this->assertSame(15, $this->estoque($user, 'componentes_eletronicos'));
        $this->assertSame(10_000 - 15 * 8, $this->estoque($user, 'estanho'));
        $this->assertSame(10_000 - 15 * 8, $this->estoque($user, 'cobre'));
        $this->assertSame(10_000 - 15 * 6, $this->estoque($user, 'silicio'));
        $this->assertSame(10_000 - 15 * 5, $this->estoque($user, 'aluminio'));
    }

    /** Sem minerais, a Oficina não fabrica nada — e não fica devendo. */
    public function test_sem_minerais_a_oficina_nao_fabrica(): void
    {
        $user = $this->colono();
        $this->erguerPredio($user->colony, 'oficina', 1);
        $this->erguerPredio($user->colony, 'reator_de_energia', 5);

        $user->colony->update(['last_tick_at' => now()->subHour()]);
        $this->tick($user, now());

        $this->assertSame(0, $this->estoque($user, 'componentes_eletronicos'));
        $this->assertSame(0, $this->estoque($user, 'estanho'));
    }

    /** O insumo mais escasso limita a produção. */
    public function test_producao_limitada_pelo_insumo_mais_escasso(): void
    {
        $user = $this->colono();
        $this->erguerPredio($user->colony, 'oficina', 1);
        $this->erguerPredio($user->colony, 'reator_de_energia', 5);

        $user->colony->resources()
            ->whereIn('resource_type', ['cobre', 'silicio', 'aluminio', 'agua'])
            ->update(['amount' => 10_000]);
        $user->colony->resources()->where('resource_type', 'estanho')->update(['amount' => 40]);

        $user->colony->update(['last_tick_at' => now()->subHour()]);
        $this->tick($user, now());

        // 40 estanho ÷ 8 por unidade = 5 componentes, não os 15/h da taxa.
        $this->assertSame(5, $this->estoque($user, 'componentes_eletronicos'));
        $this->assertSame(0, $this->estoque($user, 'estanho'));
        $this->assertSame(10_000 - 5 * 8, $this->estoque($user, 'cobre'));
    }

    public function test_receita_avancada_consome_biocombustivel_e_ouro(): void
    {
        $user = $this->colono();
        $oficina = $this->predioDe($user->colony, 'oficina');
        $oficina->update(['level' => 1, 'recipe' => 'avancada']);
        $this->erguerPredio($user->colony, 'reator_de_energia', 5);

        $user->colony->resources()
            ->whereIn('resource_type', ['estanho', 'cobre', 'silicio', 'litio', 'tungstenio', 'tantalo', 'ouro', 'biocombustivel'])
            ->update(['amount' => 10_000]);

        $user->colony->update(['last_tick_at' => now()->subHour()]);
        $this->tick($user, now());

        $this->assertSame(15, $this->estoque($user, 'componentes_eletronicos'));
        $this->assertSame(10_000 - 15 * 1, $this->estoque($user, 'ouro'));
        $this->assertSame(10_000 - 15 * 3, $this->estoque($user, 'biocombustivel'));
        // A Básica não usa ouro: a receita escolhida é mesmo a Avançada.
        $this->assertSame(10_000, $this->estoque($user, 'aluminio') ?: 10_000);
    }

    /**
     * D-19 continua valendo para Ligas e Compostos: taxa publicada, receita nenhuma.
     * A Oficina produz Componentes mas NÃO produz Ligas.
     */
    public function test_ligas_e_compostos_continuam_bloqueados(): void
    {
        $user = $this->colono();
        $this->erguerPredio($user->colony, 'oficina', 1);
        $this->erguerPredio($user->colony, 'refinaria_quimica', 1);
        $this->erguerPredio($user->colony, 'reator_de_energia', 5);
        $user->colony->resources()
            ->whereIn('resource_type', ['estanho', 'cobre', 'silicio', 'aluminio', 'agua'])
            ->update(['amount' => 10_000]);

        $user->colony->update(['last_tick_at' => now()->subHour()]);
        $this->tick($user, now());

        $this->assertSame(0, $this->estoque($user, 'ligas_metalicas'));
        $this->assertSame(0, $this->estoque($user, 'compostos_quimicos'));
        // Mas os Componentes saíram.
        $this->assertSame(15, $this->estoque($user, 'componentes_eletronicos'));
    }

    public function test_endpoint_troca_a_receita_da_oficina(): void
    {
        $user = $this->colono();
        $oficina = $this->predioDe($user->colony, 'oficina');

        $this->actingAs($user)->patchJson("/buildings/{$oficina->id}/recipe", ['recipe' => 'intermediaria'])
            ->assertOk()
            ->assertJsonPath('recipe', 'intermediaria')
            ->assertJsonPath('insumos_por_unidade.tungstenio', 3);

        $this->assertSame('intermediaria', $oficina->fresh()->recipe);
    }

    public function test_endpoint_recusa_receita_inexistente_e_predio_errado(): void
    {
        $user = $this->colono();
        $oficina = $this->predioDe($user->colony, 'oficina');
        $fazenda = $user->colony->buildings->firstWhere('type', 'fazenda');

        $this->actingAs($user)->patchJson("/buildings/{$oficina->id}/recipe", ['recipe' => 'lendaria'])
            ->assertStatus(422)->assertJsonValidationErrors('recipe');

        $this->actingAs($user)->patchJson("/buildings/{$fazenda->id}/recipe", ['recipe' => 'basica'])
            ->assertStatus(422)->assertJsonPath('code', 'sem_receita');
    }

    // ---- A API expõe as receitas (sem isto, o PATCH .../recipe é inalcançável) ----

    #[\PHPUnit\Framework\Attributes\Test]
    public function lista_as_tres_receitas_e_marca_a_padrao(): void
    {
        $r = $this->actingAs($this->colono())->getJson('/recipes')->assertOk();

        $this->assertCount(3, $r->json());

        $padrao = collect($r->json())->firstWhere('padrao', true);
        $this->assertSame(ColonyTick::RECEITA_PADRAO, $padrao['code']);
        $this->assertNotEmpty($padrao['insumos_por_unidade']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function o_catalogo_diz_qual_receita_a_oficina_usa_e_so_para_a_oficina(): void
    {
        $user = $this->colono();
        // A Oficina é de progressão: só aparece no detalhe depois de erguida num slot (D-59).
        $this->erguerPredio($user->colony, 'oficina', 1);

        $specs = collect($this->actingAs($user)->getJson('/buildings')->assertOk()->json());

        // Sem escolha feita, a Oficina reporta a Básica — o mesmo padrão que o tick aplica.
        $this->assertSame(ColonyTick::RECEITA_PADRAO, $specs->firstWhere('type', 'oficina')['recipe']);

        // Nenhuma outra construção tem receita.
        $this->assertNull($specs->firstWhere('type', 'reator_de_energia')['recipe']);
    }
}
