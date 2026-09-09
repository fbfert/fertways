<?php

namespace Tests\Feature;

use App\Domain\Endurance\EfeitosDaEndurance as Efeitos;
use App\Models\EnduranceItem;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\EnduranceItemSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * O catálogo da Endurance (§11, D-244).
 *
 * ⚠️ O que estes testes guardam **não é a lista de itens** — ela vai crescer, e o painel do operador
 * existe para isso. É o que decidiu o desenho: as escalas derivadas do único item que existia, os
 * alvos que precisam ser reais para o efeito não nascer inerte, e a escassez do único.
 */
class EnduranceCatalogoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
        $this->seed(ComponentRecipeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
        $this->seed(EnduranceItemSeeder::class);
    }

    /** As oito seções do casco têm o que vender. Era uma, e o resto do mapa era decoração. */
    public function test_todas_as_secoes_do_casco_tem_item(): void
    {
        $comItem = EnduranceItem::distinct()->pluck('secao')->sort()->values()->all();

        $this->assertSame(array_keys(EnduranceItem::SECOES), $comItem);
    }

    /**
     * ⚠️ **O alvo do efeito precisa EXISTIR no jogo.**
     *
     * `producao_bonus` casa por `building_type` e `velocidade_veiculo` por tipo de veículo. Um alvo
     * escrito errado não falha em lugar nenhum: o item é vendido, o colono paga, e o bônus
     * simplesmente nunca incide. É a família de defeito que esta Alpha achou nove vezes — dado
     * servido sem consumidor —, e aqui ele seria pior, porque o jogador pagou por ele.
     */
    public function test_todo_efeito_aponta_para_algo_que_existe(): void
    {
        $construcoes = DB::table('building_specs')->whereNotNull('producao_hora_json')
            ->distinct()->pluck('building_type')->all();

        $efeitos = DB::table('endurance_item_effects as e')
            ->join('endurance_items as i', 'i.id', '=', 'e.endurance_item_id')
            ->get(['i.nome', 'e.tipo_efeito', 'e.alvo']);

        $this->assertNotEmpty($efeitos);

        foreach ($efeitos as $ef) {
            $this->assertContains($ef->tipo_efeito, Efeitos::TIPOS, "{$ef->nome}: tipo de efeito fora dos 6 ligados ao motor");

            if ($ef->tipo_efeito === Efeitos::PRODUCAO_BONUS) {
                $this->assertContains($ef->alvo, $construcoes, "{$ef->nome}: bônus de produção para uma construção que não produz");
            }

            if (in_array($ef->tipo_efeito, [Efeitos::VELOCIDADE_VEICULO, Efeitos::CAPACIDADE_VEICULO], true)) {
                $this->assertContains(
                    $ef->alvo,
                    [Efeitos::ALVO_TODOS_OS_VEICULOS, 'furgao_de_comercio', 'caminhao_de_carga'],
                    "{$ef->nome}: efeito de veículo para um veículo que não existe",
                );
            }
        }
    }

    /**
     * ⚠️ **O único é melhor que o raro e NÃO chega ao teto do tipo.**
     *
     * É a regra inteira da escala. No teto, uma peça lendária sozinha zeraria o valor de todo comum
     * e todo raro do mesmo alvo — ela apagaria o catálogo em vez de coroá-lo. Abaixo dele, é a
     * melhor do mundo e ainda vale a pena empilhar com as outras.
     */
    public function test_o_unico_supera_o_raro_e_fica_abaixo_do_teto(): void
    {
        foreach (EnduranceItem::where('tipo', EnduranceItem::UNICO)->get() as $unico) {
            $bps = fn (EnduranceItem $i) => (int) DB::table('endurance_item_effects')
                ->where('endurance_item_id', $i->id)->value('valor_bps');

            $tipoEfeito = DB::table('endurance_item_effects')
                ->where('endurance_item_id', $unico->id)->value('tipo_efeito');

            $raro = EnduranceItem::where('secao', $unico->secao)->where('tipo', EnduranceItem::RARO)->firstOrFail();

            $this->assertGreaterThan($bps($raro), $bps($unico), "{$unico->nome} não supera o raro da própria seção");
            $this->assertLessThan(
                Efeitos::tetoBps($tipoEfeito),
                $bps($unico),
                "{$unico->nome} sozinho bate o teto do tipo e apaga o resto do catálogo",
            );
        }
    }

    /**
     * ⚠️ O §11.1 pede que "único" não vire "mais uma categoria de drop repetível": ele é **uma
     * unidade**, e mora em poucas seções.
     */
    public function test_o_unico_e_escasso_de_verdade(): void
    {
        $unicos = EnduranceItem::where('tipo', EnduranceItem::UNICO)->get();

        $this->assertNotEmpty($unicos);
        $this->assertLessThan(
            EnduranceItem::where('tipo', '!=', EnduranceItem::UNICO)->count(),
            $unicos->count(),
            'único não pode ser a maior parte do catálogo',
        );

        foreach ($unicos as $u) {
            $this->assertSame(1, (int) $u->quantidade_total, "{$u->nome} não é único: há mais de um");
        }
    }

    /** Re-semear não dobra bônus nem duplica item: a `item_key` é o contrato. */
    public function test_semear_duas_vezes_nao_duplica_nem_dobra(): void
    {
        $itens = EnduranceItem::count();
        $efeitos = DB::table('endurance_item_effects')->count();

        $this->seed(EnduranceItemSeeder::class);

        $this->assertSame($itens, EnduranceItem::count());
        $this->assertSame($efeitos, DB::table('endurance_item_effects')->count());
    }
}
