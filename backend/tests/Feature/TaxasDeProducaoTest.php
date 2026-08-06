<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Endurance\ComprarItem;
use App\Domain\Endurance\EfeitosDaEndurance;
use App\Domain\Production\TaxasDeProducao;
use App\Models\Colony;
use App\Models\EnduranceItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * O card "Recursos por hora" (docs/decisoes.md D-153): taxa NOMINAL, produzido e consumido
 * separados por recurso — não o que o tick vai creditar de fato (isso depende do insumo
 * disponível), é a capacidade plena. Os números batem com os já provados em `TickColoniesTest`/
 * `EnduranceItemsTest`/`ComponentRecipesTest` — é a mesma conta, só não liquidada.
 */
class TaxasDeProducaoTest extends TestCase
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
        $colony = app(CreateColony::class)->handle($user, 'Nova Aurora', 10 + $this->proximoSlot++, 20);

        return $user->fresh();
    }

    private function taxas(Colony $colony): array
    {
        return app(TaxasDeProducao::class)->porRecurso($colony->fresh());
    }

    public function test_as_cinco_essenciais_aparecem_como_produzido_e_energia_como_consumido(): void
    {
        $user = $this->colono();

        $r = $this->taxas($user->colony);

        // As taxas do §19.2, no nível 1 — as mesmas que `TickColoniesTest::
        // test_produz_pela_taxa_do_gdd_em_uma_hora` já prova pelo tick de verdade.
        $this->assertSame(['produzido' => 100, 'consumido' => 0], $r['oxigenio']);   // Gerador
        $this->assertSame(['produzido' => 80, 'consumido' => 0], $r['agua']);        // Captação
        $this->assertSame(['produzido' => 60, 'consumido' => 0], $r['biomassa']);    // Fazenda
        // 150 do Reator (§19.8) menos 62 do consumo operacional das 5 essenciais = saldo 88.
        $this->assertSame(['produzido' => 150, 'consumido' => 62], $r['energia']);
        $this->assertArrayNotHasKey('metal_bruto', $r); // sem Mina, sem entrada nenhuma
    }

    // ─────────────── o balanço OPERACIONAL de energia (D-220), que não é o da linha acima

    /**
     * ⚠️ A distinção inteira deste método em um teste.
     *
     * `porRecurso()['energia']['consumido']` soma **duas coisas de naturezas diferentes**: o que as
     * construções debitam por hora só para existir, e o que as receitas pediriam se rodassem. A
     * segunda parcela é nominal — e quando falta energia ela justamente **não acontece**.
     *
     * Mostrar o déficit somando as duas seria tratar taxa nominal como previsão, que é o erro que o
     * D-219 quase publicou. O saldo daqui é o que acontece toda hora.
     */
    public function test_o_saldo_operacional_ignora_a_energia_das_receitas(): void
    {
        $user = $this->colono();
        // A Destilaria pede 3 de energia por lote e produz 20/h: 60/h de energia NOMINAL na receita.
        $this->erguerPredio($user->colony, 'destilaria', 1);

        $colony = $user->colony->fresh();
        $r = app(TaxasDeProducao::class)->porRecurso($colony);
        $e = app(TaxasDeProducao::class)->energiaOperacional($colony);

        // A linha do card soma receita + operação...
        $this->assertGreaterThan($e['operacional'], $r['energia']['consumido']);

        // ...e o balanço operacional não: só o que toda construção debita por existir.
        $this->assertSame(150, $e['gerada']);
        $this->assertSame($e['gerada'] - $e['operacional'], $e['saldo']);
        $this->assertGreaterThan(0, $e['saldo'], 'cinco essenciais + Destilaria ainda cabem no Reator 1');
    }

    /**
     * O caso que manda a mensagem aparecer: colônia construída além do que o Reator sustenta.
     *
     * Medido em produção quando este saldo nasceu: **17 das 29 colônias** estavam assim, quase todas
     * com o Reator ainda no nível 1 — e nenhuma tela dizia isso.
     */
    public function test_colonia_construida_alem_do_reator_tem_saldo_negativo(): void
    {
        $user = $this->colono();
        // O Reator 1 dá 150/h e as cinco essenciais consomem 62 — sobram 88. Quatro Oficinas
        // (25 cada) passam disso. ⚠️ A Mina não serviria: ela consome **zero** de energia.
        foreach (range(1, 4) as $ignorado) {
            $this->erguerPredio($user->colony, 'oficina', 1);
        }

        $e = app(TaxasDeProducao::class)->energiaOperacional($user->colony->fresh());

        $this->assertLessThan(0, $e['saldo']);
        $this->assertSame($e['gerada'] - $e['operacional'], $e['saldo']);
    }

    public function test_duas_minas_somam_o_produzido_por_linha_nao_por_tipo(): void
    {
        $user = $this->colono();
        $this->erguerPredio($user->colony, 'mina_local', 1); // 15 metal_bruto/h
        $this->erguerPredio($user->colony, 'mina_local', 1); // repetível (D-59): uma segunda cópia

        $r = $this->taxas($user->colony);

        $this->assertSame(30, $r['metal_bruto']['produzido']);
    }

    public function test_destilaria_expande_em_consumido_e_produzido_separados(): void
    {
        $user = $this->colono();
        $this->erguerPredio($user->colony, 'destilaria', 1); // 20 biocombustível/h

        $r = $this->taxas($user->colony);
        $consumoOperacionalDaDestilaria = DB::table('building_specs')
            ->where('building_type', 'destilaria')->where('level', 1)
            ->value('energia_consumo_hora');

        $this->assertSame(20, $r['biocombustivel']['produzido']);
        // Biomassa: a Fazenda do miolo PRODUZ 60/h, a Destilaria CONSOME 2×20=40/h — os dois lados
        // do mesmo recurso, é exatamente o que o card precisa mostrar separado.
        $this->assertSame(['produzido' => 60, 'consumido' => 40], $r['biomassa']);
        // Energia: Reator produz 150/h; consumido = 62 (essenciais) + consumo próprio da Destilaria
        // + 3×20=60 da receita (§18.2).
        $this->assertSame(150, $r['energia']['produzido']);
        $this->assertSame(62 + $consumoOperacionalDaDestilaria + 60, $r['energia']['consumido']);
    }

    public function test_siderurgica_expande_a_taxa_em_metal_bruto_consumido_e_seis_saidas_produzidas(): void
    {
        $user = $this->colono();
        $this->erguerPredio($user->colony, 'industria_siderurgica', 1); // processa 15 metal_bruto/h

        $r = $this->taxas($user->colony);

        // 15/h nominal — bem abaixo de um lote de 1.000 (D-82): a média por hora é fracionária, e
        // arredonda pra baixo nos minerais mais raros (cobre/estanho/ouro/tungstênio somem do
        // array, honestamente — a essa taxa eles não rendem 1 unidade sequer por hora).
        $this->assertSame(15, $r['metal_bruto']['consumido']);
        $this->assertSame(5, $r['ligas_metalicas']['produzido']);  // round(15/1000×350) = round(5,25)
        $this->assertSame(1, $r['aluminio']['produzido']);         // round(15/1000×35)  = round(0,525)
        $this->assertArrayNotHasKey('cobre', $r);
        $this->assertArrayNotHasKey('estanho', $r);
        $this->assertArrayNotHasKey('ouro', $r);
        $this->assertArrayNotHasKey('tungstenio', $r);
    }

    public function test_oficina_expande_pela_receita_escolhida(): void
    {
        $user = $this->colono();
        $this->erguerPredio($user->colony, 'oficina', 1); // 15 componentes/h, receita padrão 'basica'

        $r = $this->taxas($user->colony);

        // Receita Básica (§24.5): 8 estanho, 8 cobre, 6 silício, 5 alumínio, 5 água — por unidade.
        $this->assertSame(15, $r['componentes_eletronicos']['produzido']);
        $this->assertSame(15 * 8, $r['estanho']['consumido']);
        $this->assertSame(15 * 8, $r['cobre']['consumido']);
        $this->assertSame(15 * 6, $r['silicio']['consumido']);
        // Água: a Captação do miolo PRODUZ 80/h, a Oficina CONSOME 5×15=75/h.
        $this->assertSame(['produzido' => 80, 'consumido' => 15 * 5], $r['agua']);
    }

    public function test_bonus_de_producao_da_endurance_aumenta_o_produzido(): void
    {
        $user = $this->colono();
        $colony = $user->colony;
        $this->erguerPredio($colony, 'mina_local', 1); // 15 metal_bruto/h
        DB::table('colonies')->where('id', $colony->id)->increment('fert_micro', 1000 * 1_000_000);

        $item = EnduranceItem::create([
            'item_key' => 'item_bonus_teste',
            'secao' => 'anel_habitacional',
            'nome' => 'Item de teste',
            'tipo' => EnduranceItem::COMUM,
            'quantidade_total' => 10,
            'quantidade_vendida' => 0,
            'preco_micro' => 1_000_000,
        ]);
        $item->efeitos()->create([
            'tipo_efeito' => EfeitosDaEndurance::PRODUCAO_BONUS,
            'alvo' => 'mina_local',
            'valor_bps' => 2000, // 20%
        ]);
        app(ComprarItem::class)->handle($colony->fresh(), $item->item_key);

        // 15 × 1,2 = 18 — mesmo número que `EnduranceItemsTest::
        // bonus_de_producao_e_de_graca_numa_construcao_sem_insumo` já prova pelo tick de verdade.
        $this->assertSame(18, $this->taxas($colony)['metal_bruto']['produzido']);
    }
}
