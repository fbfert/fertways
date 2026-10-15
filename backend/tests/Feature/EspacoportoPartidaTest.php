<?php

namespace Tests\Feature;

use App\Models\Colony;
use App\Models\PlanetaNpc;
use Database\Seeders\EspacoportoPartidaSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A partida do Espaçoporto que o usuário aprovou em 15/10 (D-263).
 *
 * Um planeta **paga 0,80×** o preço de referência pelo que importa (Auryn, o generalista, 0,70×) e
 * **cobra 1,25×** pelo que exporta. O que estes testes guardam não é o número — é a PROPRIEDADE que o
 * número garante: nenhum ciclo entre planetas cria dinheiro.
 */
class EspacoportoPartidaTest extends TestCase
{
    use RefreshDatabase;

    /** Como numa instalação nova: o catálogo primeiro, a partida depois — a ordem do `DatabaseSeeder`. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
        $this->seed(EspacoportoPartidaSeeder::class);
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_16_100000_espacoporto_com_os_numeros_de_partida.php');
    }

    public function test_o_cargueiro_nasce_com_os_numeros_aprovados(): void
    {
        $c = DB::table('espacoporto_settings')->find(1);

        $this->assertTrue((bool) $c->ativo);
        $this->assertSame(20 * Colony::MICRO_POR_FERT, (int) $c->aluguel_micro);
        $this->assertSame(10_000, (int) $c->capacidade_unidades);
        $this->assertSame(2, (int) $c->cargueiros);
    }

    public function test_abrem_kalidor_veyra_e_auryn_e_ficam_fechados_solene_e_drakmoor(): void
    {
        $this->assertSame(
            ['Kalidor' => true, 'Veyra' => true, 'Auryn' => true, 'Solène' => false, 'Drakmoor' => false],
            PlanetaNpc::orderBy('ordem')->pluck('aberto', 'nome')->map(fn ($a) => (bool) $a)->all(),
        );
    }

    /** O preço sai do preço de referência do catálogo, pelo multiplicador — e não é digitado. */
    public function test_o_preco_e_o_multiplicador_sobre_a_referencia(): void
    {
        $base = fn (string $r) => (int) DB::table('resource_types')->where('code', $r)->value('preco_base_micro');
        $preco = fn (string $planeta, string $r) => DB::table('planeta_npc_precos as p')
            ->join('planetas_npc as n', 'n.id', '=', 'p.planeta_npc_id')
            ->where('n.chave', $planeta)->where('p.resource_type', $r)->first(['compra_micro', 'venda_micro']);

        $this->assertSame((int) round($base('agua') * 0.80), (int) $preco('kalidor', 'agua')->compra_micro);
        $this->assertSame((int) round($base('metal_bruto') * 1.25), (int) $preco('kalidor', 'metal_bruto')->venda_micro);
        $this->assertSame((int) round($base('agua') * 0.70), (int) $preco('auryn', 'agua')->compra_micro);
        $this->assertSame((int) round($base('silicio') * 1.25), (int) $preco('auryn', 'silicio')->venda_micro);
    }

    /**
     * ⚠️ A propriedade que importa: para TODO recurso, o menor preço de venda de qualquer planeta é
     * maior que o maior preço de compra de qualquer planeta. Comprar num e vender noutro sempre perde.
     */
    public function test_nenhum_ciclo_entre_planetas_cria_dinheiro(): void
    {
        $precos = DB::table('planeta_npc_precos')->get()->groupBy('resource_type');

        foreach ($precos as $recurso => $linhas) {
            $maiorCompra = $linhas->max('compra_micro');
            $menorVenda = $linhas->whereNotNull('venda_micro')->min('venda_micro');

            if ($maiorCompra !== null && $menorVenda !== null) {
                $this->assertLessThan($menorVenda, $maiorCompra, "{$recurso}: comprar num planeta e vender noutro daria lucro");
            }
        }
    }

    /** Um deploy não desfaz o painel: planeta já mexido e Cargueiro já configurado ficam como estão. */
    public function test_a_partida_nao_sobrescreve_o_que_o_operador_mudou(): void
    {
        $kalidor = PlanetaNpc::where('chave', 'kalidor')->firstOrFail();
        DB::table('planeta_npc_precos')->where('planeta_npc_id', $kalidor->id)->delete();
        $kalidor->precos()->create(['resource_type' => 'agua', 'compra_micro' => 1, 'venda_micro' => null]);
        $kalidor->update(['aberto' => false]);
        DB::table('espacoporto_settings')->where('id', 1)->update(['aluguel_micro' => 7]);

        $this->migration()->up();

        $this->assertSame(1, (int) $kalidor->precos()->where('resource_type', 'agua')->value('compra_micro'));
        $this->assertSame(1, $kalidor->precos()->count());
        $this->assertFalse($kalidor->fresh()->aberto);
        $this->assertSame(7, (int) DB::table('espacoporto_settings')->value('aluguel_micro'));
    }
}
