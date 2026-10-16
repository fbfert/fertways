<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Endurance\ComprarItem;
use App\Models\Colony;
use App\Models\EnduranceItem;
use App\Models\GameEvent;
use App\Models\User;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A Endurance explorada por seções, e os lotes de evento ditos na tela (A2.V5; D-274).
 *
 * O mapa contava o que está à venda e o que está enterrado, mas nunca o que o jogador JÁ tirou de
 * cada destroço — "exploração por seções" sem progresso é só um menu de oito portas. E o lote que um
 * evento libera (D-249) entrava e saía da loja sem dizer que era temporário: quem deixasse para
 * comprar amanhã descobriria a peça sumida sem aviso nenhum.
 */
class EnduranceExploracaoTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
        $this->seed(ComponentRecipeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
        EnduranceItem::query()->delete();
    }

    private function colonia(): Colony
    {
        $c = app(CreateColony::class)->handle(User::factory()->create(), 'Base', 20 + $this->n++, 20);
        $c->forceFill(['xp' => 0, 'fert_micro' => 500 * Colony::MICRO_POR_FERT])->save();

        return $c->fresh();
    }

    private function peca(array $extra = []): EnduranceItem
    {
        return EnduranceItem::create(array_merge([
            'item_key' => 'peca'.$this->n++, 'secao' => 'comando', 'nome' => 'Peça',
            'tipo' => EnduranceItem::COMUM, 'origem' => EnduranceItem::LOJA,
            'quantidade_total' => 5, 'quantidade_vendida' => 0, 'preco_micro' => 1_000_000,
        ], $extra));
    }

    private function evento(array $extra = []): GameEvent
    {
        return GameEvent::create(array_merge([
            'slug' => 'lote'.$this->n++, 'nome' => 'Tempestade de Íons', 'status' => 'ativo',
            'comeca_em' => now()->subHour(), 'termina_em' => now()->addDays(2),
        ], $extra));
    }

    private function secao(Colony $c, string $chave = 'comando'): array
    {
        return collect($this->actingAs($c->user)->getJson('/endurance/secoes-mapa')->assertOk()->json('secoes'))
            ->firstWhere('chave', $chave);
    }

    public function test_o_mapa_conta_quantas_pecas_da_secao_a_colonia_ja_tem(): void
    {
        $c = $this->colonia();
        $a = $this->peca();
        $b = $this->peca();
        $this->peca();
        $this->peca(['secao' => 'modulo_medico']);

        app(ComprarItem::class)->handle($c, $a->item_key);
        app(ComprarItem::class)->handle($c, $a->item_key);
        app(ComprarItem::class)->handle($c, $b->item_key);

        $comando = $this->secao($c);
        // Tipos de peça, não unidades: duas Peças A são uma peça da coleção.
        $this->assertSame(2, $comando['minhas']);
        $this->assertSame(3, $comando['catalogo']);
        $this->assertSame(0, $this->secao($c, 'modulo_medico')['minhas']);
    }

    /** A peça esgotada que eu tenho continua sendo minha: o catálogo conta tudo que foi liberado. */
    public function test_o_catalogo_conta_a_peca_esgotada_e_a_enterrada(): void
    {
        $c = $this->colonia();
        $this->peca(['quantidade_vendida' => 5]);
        $this->peca(['origem' => EnduranceItem::ESCAVACAO]);

        $this->assertSame(2, $this->secao($c)['catalogo']);
    }

    public function test_o_mapa_avisa_o_lote_de_evento_aberto_na_secao(): void
    {
        $ev = $this->evento();
        $this->peca(['game_event_id' => $ev->id]);
        $c = $this->colonia();

        $lote = $this->secao($c)['lote_de_evento'];
        $this->assertSame('Tempestade de Íons', $lote['nome']);
        $this->assertSame($ev->termina_em->toIso8601String(), $lote['ate']);
        $this->assertNull($this->secao($c, 'modulo_medico')['lote_de_evento']);
    }

    /** O evento secreto abre a porta sem se apresentar: o lote aparece, o nome não. */
    public function test_o_lote_de_evento_secreto_nao_diz_o_nome(): void
    {
        $ev = $this->evento(['segredo' => true]);
        $this->peca(['game_event_id' => $ev->id]);

        $lote = $this->secao($this->colonia())['lote_de_evento'];
        $this->assertNull($lote['nome']);
        $this->assertNotNull($lote['ate']);
    }

    /** Cancelado vale até `cancelado_em` (D-249): a tela promete o prazo que o servidor cumpre. */
    public function test_a_peca_de_evento_diz_ate_quando_existe(): void
    {
        $ev = $this->evento(['status' => 'cancelado', 'cancelado_em' => now()->addHours(3)]);
        $p = $this->peca(['game_event_id' => $ev->id]);
        $this->peca();
        $c = $this->colonia();

        $itens = collect($this->actingAs($c->user)->getJson('/endurance/secoes/comando')->assertOk()->json('itens'));

        $doEvento = $itens->firstWhere('item_key', $p->item_key)['evento'];
        $this->assertSame('Tempestade de Íons', $doEvento['nome']);
        $this->assertSame($ev->cancelado_em->toIso8601String(), $doEvento['ate']);
        $this->assertNull($itens->firstWhere('item_key', '!=', $p->item_key)['evento']);
    }
}
