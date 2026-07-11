<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Market\CancelarOrdem;
use App\Domain\Market\ColocarOrdem;
use App\Domain\Market\ExecutarOrdem;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\MarketAccount;
use App\Models\MarketOrder;
use App\Models\User;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Livro de ofertas do Mercado Central (GDD §07).
 *
 * O Mercado casa ordens; não compra nem vende. Metal Bruto é primário: taxa de 3% (§8.3),
 * cobrada em Fert$ sobre o valor do negócio, e paga pelo vendedor ("crédito líquido ao
 * vendedor", §07). Saldo inicial de cada colônia: 50 Fert$ = 50.000.000 micro.
 */
class LivroDeOfertasTest extends TestCase
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
        // O colono escolhe a célula (D-51). Coords de periferia, fundáveis.
        $colony = app(CreateColony::class)->handle($user, "Colônia {$nick}", $x, $y);

        return $colony->fresh();
    }

    /** Vendedor com carga já na doca, comprador com Fert$ do onboarding. */
    private function vendedorEComprador(int $naDoca = 1_000): array
    {
        $a = $this->colonia('vendedor', 10, 10);
        $b = $this->colonia('comprador', 20, 20);
        MarketAccount::create(['colony_id' => $a->id, 'resource_type' => 'metal_bruto', 'amount' => $naDoca]);

        return [$a, $b];
    }

    private function fert(Colony $c): int
    {
        return (int) DB::table('colonies')->where('id', $c->id)->value('fert_micro');
    }

    private function naDoca(Colony $c, string $recurso = 'metal_bruto'): int
    {
        return (int) MarketAccount::where('colony_id', $c->id)
            ->where('resource_type', $recurso)->value('amount');
    }

    #[Test]
    public function nao_se_vende_o_que_nao_esta_na_doca(): void
    {
        $a = $this->colonia('sozinho', 10, 10);

        // §07: "O vendedor transporta o lote até a doca de mercado. Ao chegar, o lote é
        // reservado em escrow e a listagem é criada." Sem entrega física, não há listagem.
        $this->expectExceptionMessage('Entregue a carga na doca primeiro');
        app(ColocarOrdem::class)->handle($a, 'sell', 'metal_bruto', 100, 50_000);
    }

    #[Test]
    public function a_ordem_de_venda_reserva_o_recurso_em_escrow(): void
    {
        [$a] = $this->vendedorEComprador();

        $ordem = app(ColocarOrdem::class)->handle($a, 'sell', 'metal_bruto', 100, 50_000);

        $this->assertSame('aberta', $ordem->status);
        $this->assertSame(100, $ordem->escrow_resource_qty);
        $this->assertSame(900, $this->naDoca($a), 'saiu da conta e entrou no escrow');
        $this->assertSame(-100, Ledger::where('type', 'escrow_mercado')->value('amount'));
    }

    #[Test]
    public function a_ordem_de_compra_reserva_fert_em_escrow(): void
    {
        [, $b] = $this->vendedorEComprador();

        $ordem = app(ColocarOrdem::class)->handle($b, 'buy', 'metal_bruto', 100, 60_000);

        $this->assertSame(6_000_000, $ordem->escrow_fert_micro);
        $this->assertSame(44_000_000, $this->fert($b), '50 Fert$ menos os 6 reservados');
    }

    #[Test]
    public function comprar_sem_fert_suficiente_e_recusado(): void
    {
        [, $b] = $this->vendedorEComprador();

        // 1.000 × 60.000 micro = 60 Fert$, acima dos 50 do onboarding.
        $this->expectExceptionMessage('Fert$ insuficiente');
        app(ColocarOrdem::class)->handle($b, 'buy', 'metal_bruto', 1_000, 60_000);
    }

    /**
     * O coração do D-58. Antes, esta oferta seria consumida no ato de nascer e ninguém a veria —
     * era essa a razão de a vitrine parecer deserta.
     */
    #[Test]
    public function precos_que_se_cruzam_nao_casam_mais_sozinhos_a_oferta_repousa(): void
    {
        [$a, $b] = $this->vendedorEComprador();

        $venda = app(ColocarOrdem::class)->handle($a, 'sell', 'metal_bruto', 100, 50_000);
        // O comprador oferece MAIS do que o vendedor pede. No livro antigo, isto executava sozinho.
        $compra = app(ColocarOrdem::class)->handle($b, 'buy', 'metal_bruto', 100, 60_000);

        $this->assertSame('aberta', $venda->fresh()->status);
        $this->assertSame('aberta', $compra->status);
        $this->assertSame(0, DB::table('tax_events')->count(), 'nada foi negociado');
        $this->assertSame(0, $this->naDoca($b));
        $this->assertSame(2, MarketOrder::where('status', 'aberta')->count(), 'as duas ficam na vitrine');
    }

    #[Test]
    public function executar_uma_oferta_de_venda_paga_o_preco_dela_e_entrega_no_deposito(): void
    {
        [$a, $b] = $this->vendedorEComprador();

        $venda = app(ColocarOrdem::class)->handle($a, 'sell', 'metal_bruto', 100, 50_000);
        app(ExecutarOrdem::class)->handle($b, $venda->id, 100);

        // Valor = 100 × 50.000 = 5 Fert$. Taxa de 3% (primário) = 150.000. O vendedor recebe 4.850.000.
        $this->assertSame(50_000_000 + 4_850_000, $this->fert($a));
        // O tomador paga o preço da oferta, e só ele: não há escrow dele nem devolução.
        $this->assertSame(50_000_000 - 5_000_000, $this->fert($b));

        // §25.8: o recurso comprado entra no depósito do comprador, não no estoque.
        $this->assertSame(100, $this->naDoca($b));
        $this->assertSame(0, (int) $b->resources()->where('resource_type', 'metal_bruto')->value('amount'));

        $taxa = DB::table('tax_events')->where('kind', 'mercado_venda')->first();
        $this->assertSame($a->id, (int) $taxa->colony_id, 'a taxa recai sobre quem vende');
        $this->assertSame(5_000_000, (int) $taxa->base_amount, 'a base é o valor em micro-Fert$');
        $this->assertSame(300, (int) $taxa->tax_bps);
        $this->assertSame(150_000, (int) $taxa->tax_amount);
    }

    #[Test]
    public function executar_uma_oferta_de_compra_entrega_do_deposito_e_paga_do_escrow_dela(): void
    {
        [$a, $b] = $this->vendedorEComprador();

        // Quem anuncia é o comprador; quem executa é o vendedor, entregando do seu depósito.
        $compra = app(ColocarOrdem::class)->handle($b, 'buy', 'metal_bruto', 100, 60_000);
        app(ExecutarOrdem::class)->handle($a, $compra->id, 100);

        // Valor = 6 Fert$; taxa de 3% = 180.000; o vendedor recebe 5.820.000.
        $this->assertSame(50_000_000 + 5_820_000, $this->fert($a));
        // O comprador já pagara ao anunciar, no escrow: nada sai do bolso dele agora.
        $this->assertSame(50_000_000 - 6_000_000, $this->fert($b));
        $this->assertSame(900, $this->naDoca($a), 'saiu do depósito de quem executou');
        $this->assertSame(100, $this->naDoca($b));
    }

    #[Test]
    public function ninguem_executa_a_propria_oferta(): void
    {
        [$a] = $this->vendedorEComprador();
        $venda = app(ColocarOrdem::class)->handle($a, 'sell', 'metal_bruto', 100, 50_000);

        // §26.4 trata conta-alternativa como fraude; fechar consigo mesmo é a versão trivial disso.
        $this->expectExceptionMessage('não pode executar a sua própria oferta');
        app(ExecutarOrdem::class)->handle($a, $venda->id, 100);
    }

    #[Test]
    public function nao_se_executa_mais_do_que_a_oferta_tem(): void
    {
        [$a, $b] = $this->vendedorEComprador();
        $venda = app(ColocarOrdem::class)->handle($a, 'sell', 'metal_bruto', 100, 50_000);

        $this->expectExceptionMessage('só 100 unidade(s) restante(s)');
        app(ExecutarOrdem::class)->handle($b, $venda->id, 101);
    }

    #[Test]
    public function execucao_parcial_deixa_a_oferta_aberta_com_o_resto(): void
    {
        [$a, $b] = $this->vendedorEComprador();

        $venda = app(ColocarOrdem::class)->handle($a, 'sell', 'metal_bruto', 100, 50_000);
        app(ExecutarOrdem::class)->handle($b, $venda->id, 40);

        $venda = $venda->fresh();
        $this->assertSame('parcial', $venda->status);
        $this->assertSame(60, $venda->qty, 'qty é o que resta, não o original');
        $this->assertSame(60, $venda->escrow_resource_qty);
        $this->assertSame(40, $this->naDoca($b));

        // E o resto continua executável: a vitrine não perde o saldo.
        app(ExecutarOrdem::class)->handle($b, $venda->id, 60);
        $this->assertSame('executada', $venda->fresh()->status);
        $this->assertSame(100, $this->naDoca($b));
        $this->assertSame(2, DB::table('tax_events')->where('kind', 'mercado_venda')->count(),
            'duas execuções, dois fatos econômicos — a chave deriva do qty de antes de cada uma');
    }

    #[Test]
    public function cancelar_devolve_o_escrow_do_que_sobrou(): void
    {
        [$a, $b] = $this->vendedorEComprador();

        $venda = app(ColocarOrdem::class)->handle($a, 'sell', 'metal_bruto', 100, 50_000);
        app(ExecutarOrdem::class)->handle($b, $venda->id, 40);

        $cancelada = app(CancelarOrdem::class)->handle($a, $venda->fresh());

        $this->assertSame('cancelada', $cancelada->status);
        $this->assertSame(0, $cancelada->escrow_resource_qty);

        // 1.000 - 100 no escrow, +60 devolvidos. O recurso volta à doca, não ao estoque.
        $this->assertSame(960, $this->naDoca($a));
        $this->assertSame(0, (int) $a->resources()->where('resource_type', 'metal_bruto')->value('amount'));
    }

    #[Test]
    public function cancelar_uma_ordem_de_compra_devolve_o_fert(): void
    {
        [, $b] = $this->vendedorEComprador();

        $compra = app(ColocarOrdem::class)->handle($b, 'buy', 'metal_bruto', 100, 60_000);
        app(CancelarOrdem::class)->handle($b, $compra);

        $this->assertSame(50_000_000, $this->fert($b), 'o escrow inteiro voltou');
    }

    #[Test]
    public function ninguem_cancela_a_ordem_alheia(): void
    {
        [$a, $b] = $this->vendedorEComprador();
        $venda = app(ColocarOrdem::class)->handle($a, 'sell', 'metal_bruto', 100, 50_000);

        $this->expectExceptionMessage('Esta ordem não é sua');
        app(CancelarOrdem::class)->handle($b, $venda);
    }

    #[Test]
    public function a_ordem_ja_cancelada_nao_devolve_escrow_duas_vezes(): void
    {
        [$a] = $this->vendedorEComprador();
        $venda = app(ColocarOrdem::class)->handle($a, 'sell', 'metal_bruto', 100, 50_000);

        app(CancelarOrdem::class)->handle($a, $venda);
        $this->assertSame(1_000, $this->naDoca($a));

        $this->expectExceptionMessage('já foi executada ou cancelada');
        app(CancelarOrdem::class)->handle($a, $venda->fresh());
    }

    #[Test]
    public function a_taxa_de_venda_usa_a_aliquota_da_categoria_do_recurso(): void
    {
        $a = $this->colonia('vendedor', 10, 10);
        $b = $this->colonia('comprador', 20, 20);

        // Nióbio Alienígena é raro: 1% (§8.3), contra os 3% do Metal Bruto primário.
        MarketAccount::create(['colony_id' => $a->id, 'resource_type' => 'niobio_alienigena', 'amount' => 100]);

        $venda = app(ColocarOrdem::class)->handle($a, 'sell', 'niobio_alienigena', 100, 50_000);
        app(ExecutarOrdem::class)->handle($b, $venda->id, 100);

        $taxa = DB::table('tax_events')->where('kind', 'mercado_venda')->first();
        $this->assertSame(100, (int) $taxa->tax_bps, 'raro: 1%');
        $this->assertSame(50_000, (int) $taxa->tax_amount, '1% de 5 Fert$');
        $this->assertSame(50_000_000 + 4_950_000, $this->fert($a));
    }

    #[Test]
    public function a_venda_nao_gera_tributo_de_volume_sobre_o_recurso(): void
    {
        [$a, $b] = $this->vendedorEComprador();

        $venda = app(ColocarOrdem::class)->handle($a, 'sell', 'metal_bruto', 100, 50_000);
        app(ExecutarOrdem::class)->handle($b, $venda->id, 100);

        /*
         * §25.8: "Uma vez o recurso depositado na conta do Mercado, a venda por Fert$ não gera
         * novo tributo de volume — apenas a movimentação física já foi tributada." O comprador
         * recebe as 100 unidades inteiras na doca; só o Fert$ do vendedor foi taxado.
         */
        $this->assertSame(100, $this->naDoca($b));
        $this->assertSame(0, DB::table('tax_events')->where('kind', 'transporte_entrega')->count());
        $this->assertSame(1, DB::table('tax_events')->where('kind', 'mercado_venda')->count());
    }
}
