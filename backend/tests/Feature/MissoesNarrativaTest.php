<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Endurance\ComprarItem;
use App\Domain\Missoes\Atribuir;
use App\Domain\Missoes\Progresso;
use App\Models\Colony;
use App\Models\EnduranceItem;
use App\Models\MissionAssignment;
use App\Models\MissionTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Missões narrativas da Endurance (docs/decisoes.md D-140) — a categoria que o D-78 deixou de
 * fora de propósito ("Narrativa (...) espera o seu sistema"). Sem ciclo: um capítulo, uma vez,
 * encadeado por `requer_template_id` — o capítulo N+1 só chega quando o N está concluído.
 */
class MissoesNarrativaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\ComponentRecipeSeeder::class);
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
        $this->seed(\Database\Seeders\MissionTemplateSeeder::class);
    }

    private function colonia(): Colony
    {
        $c = app(CreateColony::class)->handle(User::factory()->create(), 'Base', 20, 20);

        // Só a narrativa: os testes daqui afirmam contagens exatas dela.
        MissionAssignment::where('colony_id', $c->id)->delete();

        return $c->fresh();
    }

    public function test_o_seeder_encadeia_os_4_capitulos_em_ordem(): void
    {
        $capitulos = MissionTemplate::where('categoria', 'narrativa')->orderBy('id')->get();

        $this->assertSame(4, $capitulos->count());
        $this->assertNull($capitulos[0]->requer_template_id, 'o 1º capítulo não tem pré-requisito');

        for ($i = 1; $i < 4; $i++) {
            $this->assertSame(
                $capitulos[$i - 1]->id, $capitulos[$i]->requer_template_id,
                "o capítulo {$i} deveria exigir o capítulo anterior",
            );
        }
    }

    public function test_so_o_primeiro_capitulo_chega_de_saida(): void
    {
        $colonia = $this->colonia();

        app(Atribuir::class)->garantirNarrativa($colonia);

        $entregues = MissionAssignment::where('colony_id', $colonia->id)->where('categoria', 'narrativa')->get();
        $this->assertCount(1, $entregues);
        $this->assertSame('end_cap1_primeiro_achado', $entregues->first()->template->chave);
    }

    public function test_completar_o_primeiro_capitulo_libera_o_segundo(): void
    {
        $colonia = $this->colonia();
        app(Atribuir::class)->garantirNarrativa($colonia);

        app(Progresso::class)->registrar($colonia->id, 'comprar_item_endurance');

        $cap1 = MissionAssignment::where('colony_id', $colonia->id)
            ->whereHas('template', fn ($q) => $q->where('chave', 'end_cap1_primeiro_achado'))->first();
        $this->assertSame('concluida', $cap1->status);

        // O 2º só chega no PRÓXIMO pedido — a mesma preguiça lazy do resto do motor (D-78).
        $this->assertSame(1, MissionAssignment::where('colony_id', $colonia->id)->where('categoria', 'narrativa')->count());

        app(Atribuir::class)->garantirNarrativa($colonia);

        $entregues = MissionAssignment::where('colony_id', $colonia->id)->where('categoria', 'narrativa')
            ->with('template')->get()->pluck('template.chave');
        $this->assertContains('end_cap2_preco_da_escavacao', $entregues);
        $this->assertCount(2, $entregues);
    }

    public function test_nao_pula_capitulo_o_terceiro_nao_chega_sem_o_segundo(): void
    {
        $colonia = $this->colonia();
        app(Atribuir::class)->garantirNarrativa($colonia);
        app(Progresso::class)->registrar($colonia->id, 'comprar_item_endurance');
        app(Atribuir::class)->garantirNarrativa($colonia);

        // Cap 2 está ativo, mas não concluído — cap 3 não deve aparecer.
        app(Atribuir::class)->garantirNarrativa($colonia);
        $chaves = MissionAssignment::where('colony_id', $colonia->id)->where('categoria', 'narrativa')
            ->with('template')->get()->pluck('template.chave');
        $this->assertNotContains('end_cap3_reconstrucao', $chaves);
    }

    public function test_a_cadeia_inteira_conclui_e_paga_o_ultimo_capitulo(): void
    {
        $colonia = $this->colonia();
        $colonia->update(['fert_micro' => 10_000 * 1_000_000]);

        $avancar = function () use ($colonia) {
            app(Atribuir::class)->garantirNarrativa($colonia);
        };

        // Cap 1: comprar item da Endurance.
        $avancar();
        $item = EnduranceItem::create([
            'item_key' => 'reator_teste', 'secao' => 'comando', 'nome' => 'Reator',
            'tipo' => EnduranceItem::COMUM, 'quantidade_total' => 5, 'quantidade_vendida' => 0,
            'preco_micro' => 1_000_000, 'marco_minimo' => null, 'vendavel_em_leilao' => false,
        ]);
        app(ComprarItem::class)->handle($colonia, $item->item_key);

        // Cap 2: 3 negócios no Mercado Central.
        $avancar();
        app(Progresso::class)->registrar($colonia->id, 'mercado_executado', 3);

        // Cap 3: 2 níveis de construção.
        $avancar();
        app(Progresso::class)->registrar($colonia->id, 'obra_concluida', 2);

        // Cap 4: 2 despachos — o final da cadeia.
        $avancar();
        $antes = $colonia->fresh()->fert_micro;
        app(Progresso::class)->registrar($colonia->id, 'despacho', 2);

        $cap4 = MissionAssignment::where('colony_id', $colonia->id)
            ->whereHas('template', fn ($q) => $q->where('chave', 'end_cap4_o_legado'))->first();
        $this->assertNotNull($cap4, 'o 4º capítulo deveria ter chegado depois do 3º concluído');
        $this->assertSame('concluida', $cap4->status);
        $this->assertGreaterThan($antes, $colonia->fresh()->fert_micro, 'o capítulo final paga Fert$');

        $avancar();
        $this->assertSame(
            4, MissionAssignment::where('colony_id', $colonia->id)->where('categoria', 'narrativa')->count(),
            'a cadeia tem exatamente 4 capítulos — não sobra um 5º inexistente',
        );
    }

    public function test_um_capitulo_ja_entregue_nunca_e_entregue_de_novo(): void
    {
        $colonia = $this->colonia();
        app(Atribuir::class)->garantirNarrativa($colonia);
        app(Atribuir::class)->garantirNarrativa($colonia);
        app(Atribuir::class)->garantirNarrativa($colonia);

        $this->assertSame(1, MissionAssignment::where('colony_id', $colonia->id)->where('categoria', 'narrativa')->count());
    }

    public function test_narrativa_nao_expira(): void
    {
        $colonia = $this->colonia();
        app(Atribuir::class)->garantirNarrativa($colonia);

        $cap1 = MissionAssignment::where('colony_id', $colonia->id)->where('categoria', 'narrativa')->first();
        $this->assertNull($cap1->expires_at);
    }

    public function test_a_rota_de_missoes_entrega_o_capitulo_ativo(): void
    {
        $colonia = $this->colonia();
        $user = $colonia->user;

        $resp = $this->actingAs($user)->getJson('/missions')->assertOk();

        $titulos = collect($resp->json('missoes'))->pluck('titulo');
        $this->assertContains('O Primeiro Achado', $titulos);
    }
}
