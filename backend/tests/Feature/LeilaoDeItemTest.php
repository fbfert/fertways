<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Leilao\CancelarLeilao;
use App\Domain\Leilao\DarLance;
use App\Domain\Leilao\FecharLeiloes;
use App\Domain\Leilao\ListarLeilao;
use App\Domain\Treasury\Tesouro;
use App\Models\Colony;
use App\Models\ColonyEnduranceItem;
use App\Models\EnduranceItem;
use App\Models\Ledger;
use App\Models\User;
use App\Models\XpEntry;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Leilões vendem item da Endurance (D-129 estendido pelo D-135, Fase 2) — mesma máquina de
 * lote/lance/fechamento do `LeiloesTest`, só que a posse sai/entra em `colony_endurance_items`
 * em vez de `market_accounts`, e o tributo do fechamento é zero (item da Endurance não tem
 * `tax_bps` — o preço já é arbitragem do admin).
 */
class LeilaoDeItemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ResourceTypeSeeder::class);
        $this->seed(ComponentRecipeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
    }

    private function colonia(string $nick, int $x, int $y): Colony
    {
        $user = User::factory()->create(['email' => "{$nick}@t.test", 'nickname' => $nick]);

        return app(CreateColony::class)->handle($user, "Colônia {$nick}", $x, $y)->fresh();
    }

    private function item(array $attrs = []): EnduranceItem
    {
        return EnduranceItem::create(array_merge([
            'item_key' => 'item_'.Str::random(10),
            'secao' => 'anel_habitacional',
            'nome' => 'Item de teste',
            'tipo' => EnduranceItem::COMUM,
            'quantidade_total' => 100,
            'quantidade_vendida' => 0,
            'preco_micro' => 1_000_000,
            'marco_minimo' => null,
            'vendavel_em_leilao' => true,
        ], $attrs));
    }

    private function possuir(Colony $c, EnduranceItem $item, int $quantidade): void
    {
        ColonyEnduranceItem::create(['colony_id' => $c->id, 'endurance_item_id' => $item->id, 'quantidade' => $quantidade]);
    }

    private function posse(Colony $c, EnduranceItem $item): int
    {
        return (int) (ColonyEnduranceItem::where('colony_id', $c->id)->where('endurance_item_id', $item->id)->value('quantidade') ?? 0);
    }

    #[Test]
    public function anunciar_exige_o_item_vendavel_em_leilao(): void
    {
        $a = $this->colonia('a', 10, 10);
        $item = $this->item(['vendavel_em_leilao' => false]);
        $this->possuir($a, $item, 5);

        $this->expectExceptionMessage('não pode ser vendido em Leilões');
        app(ListarLeilao::class)->handleItem($a, $item->item_key, 1, 5_000_000, 24);
    }

    #[Test]
    public function anunciar_exige_posse_suficiente(): void
    {
        $a = $this->colonia('a', 10, 10);
        $item = $this->item();
        $this->possuir($a, $item, 2);

        $this->expectExceptionMessage('Você não tem');
        app(ListarLeilao::class)->handleItem($a, $item->item_key, 3, 5_000_000, 24);
    }

    #[Test]
    public function anunciar_reserva_a_posse_em_escrow(): void
    {
        $a = $this->colonia('a', 10, 10);
        $item = $this->item();
        $this->possuir($a, $item, 5);

        $leilao = app(ListarLeilao::class)->handleItem($a, $item->item_key, 3, 5_000_000, 24);

        $this->assertSame('aberto', $leilao->status);
        $this->assertTrue($leilao->ehItem());
        $this->assertSame($item->id, $leilao->endurance_item_id);
        $this->assertNull($leilao->resource_type);
        $this->assertSame(2, $this->posse($a, $item), 'saiu 3 de 5 para o escrow');
        $this->assertSame(
            -3,
            Ledger::where('type', 'escrow_leilao')->whereNull('resource_type')->where('ref', "leilao:{$leilao->id}:anuncio")->value('amount'),
        );
    }

    #[Test]
    public function cancelar_sem_lance_devolve_a_posse(): void
    {
        $a = $this->colonia('a', 10, 10);
        $item = $this->item();
        $this->possuir($a, $item, 5);
        $leilao = app(ListarLeilao::class)->handleItem($a, $item->item_key, 3, 5_000_000, 24);

        $cancelado = app(CancelarLeilao::class)->handle($a, $leilao);

        $this->assertSame('cancelado', $cancelado->status);
        $this->assertSame(5, $this->posse($a, $item));
    }

    #[Test]
    public function o_tick_fecha_sem_lance_devolvendo_a_posse(): void
    {
        $a = $this->colonia('a', 10, 10);
        $item = $this->item();
        $this->possuir($a, $item, 5);
        $leilao = app(ListarLeilao::class)->handleItem($a, $item->item_key, 3, 5_000_000, 24);
        $leilao->forceFill(['deadline_at' => now()->subMinute()])->save();

        $resultado = app(FecharLeiloes::class)->handle();

        $this->assertSame(['arrematados' => 0, 'sem_lance' => 1], $resultado);
        $this->assertSame('sem_lance', $leilao->fresh()->status);
        $this->assertSame(5, $this->posse($a, $item));
    }

    #[Test]
    public function o_tick_fecha_arrematado_transferindo_a_posse_sem_tributo(): void
    {
        $a = $this->colonia('vendedor', 10, 10);
        $b = $this->colonia('lancador', 20, 20);
        $item = $this->item();
        $this->possuir($a, $item, 5);

        $leilao = app(ListarLeilao::class)->handleItem($a, $item->item_key, 3, 1_000_000, 24);
        app(DarLance::class)->handle($b, $leilao->id, 5_000_000);
        $leilao->forceFill(['deadline_at' => now()->subMinute()])->save();

        $tesouroAntes = app(Tesouro::class)->saldoFertMicro();

        $resultado = app(FecharLeiloes::class)->handle();

        $this->assertSame(['arrematados' => 1, 'sem_lance' => 0], $resultado);
        $this->assertSame('arrematado', $leilao->fresh()->status);

        // Sem tributo: o vendedor recebe o lance INTEIRO, não líquido de alíquota nenhuma.
        $this->assertSame(Colony::SALDO_INICIAL_MICRO + 5_000_000, (int) DB::table('colonies')->where('id', $a->id)->value('fert_micro'));
        $this->assertSame($tesouroAntes, app(Tesouro::class)->saldoFertMicro(), 'nada foi ao Tesouro — tributo zero');

        $this->assertSame(2, $this->posse($a, $item), 'os 2 que sobraram fora do leilão continuam com o vendedor');
        $this->assertSame(3, $this->posse($b, $item), 'o arrematante nunca tinha possuído este item antes');

        $taxa = DB::table('tax_events')->where('kind', 'leilao_venda')->where('colony_id', $a->id)->first();
        $this->assertNotNull($taxa);
        $this->assertNull($taxa->resource_type);
        $this->assertSame(0, (int) $taxa->tax_bps);
        $this->assertSame(0, (int) $taxa->tax_amount);
        $this->assertSame(5_000_000, (int) $taxa->base_amount);

        $this->assertSame(1, XpEntry::where('colony_id', $a->id)->where('acao', 'mercado_executado')->count());
        $this->assertSame(1, XpEntry::where('colony_id', $b->id)->where('acao', 'mercado_executado')->count());
    }
}
