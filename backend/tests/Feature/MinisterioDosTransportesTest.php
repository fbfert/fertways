<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Transport\FabricarCaminhoes;
use App\Domain\Transport\Ministerio;
use App\Domain\Transport\Vagas;
use App\Models\Colony;
use App\Models\TreasuryHolding;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O Ministério dos Transportes (D-60): placas, vagas e a fábrica única de caminhões.
 */
class MinisterioDosTransportesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
    }

    private int $proximoSlot = 0;

    private function colono(): User
    {
        $user = User::factory()->create(['tutorial_completed_at' => now()]);
        app(CreateColony::class)->handle($user, 'Nova Aurora', 10 + $this->proximoSlot++, 20);

        return $user->fresh();
    }

    /** O caixa do governo, dotado para N caminhões. */
    private function dotarTesouro(int $vezes = 10): void
    {
        foreach (Ministerio::custoFabricacao() as $recurso => $qtd) {
            TreasuryHolding::updateOrCreate(['resource_type' => $recurso], ['amount' => $qtd * $vezes]);
        }
    }

    /** Fabrica e deixa os 5 prontos na prateleira. */
    private function prepararPrateleira(): void
    {
        $this->dotarTesouro();
        app(FabricarCaminhoes::class)->handle();
        $this->travelTo(now()->addMinutes(Ministerio::MINUTOS_FABRICACAO + 1));
        app(FabricarCaminhoes::class)->handle();
    }

    /** Um colono com Central de Transportes (logo, com vaga) e dinheiro no bolso. */
    private function compradorPronto(int $nivelDaCentral = 2, int $fertMicro = Ministerio::PRECO_MICRO): User
    {
        $user = $this->colono();
        $this->erguerPredio($user->colony, 'central_de_transportes', $nivelDaCentral);
        $user->colony->update(['fert_micro' => $fertMicro]);

        return $user;
    }

    // ---------------------------------------------------------------- placas (§16.3)

    public function test_o_furgao_do_kit_nasce_com_placa(): void
    {
        $furgao = $this->colono()->colony->vehicles()->first();

        $this->assertSame('FW-00001-F', $furgao->plate, 'o F é a inicial do Furgão');
    }

    public function test_a_placa_do_caminhao_termina_em_C(): void
    {
        $this->dotarTesouro();
        app(FabricarCaminhoes::class)->handle();

        $this->assertMatchesRegularExpression(
            '/^FW-\d{5}-C$/',
            Vehicle::whereNull('colony_id')->first()->plate,
        );
    }

    public function test_a_placa_e_sequencial_e_nao_se_repete(): void
    {
        $this->colono();
        $this->dotarTesouro();
        app(FabricarCaminhoes::class)->handle();

        $placas = Vehicle::whereNotNull('plate')->pluck('plate');

        $this->assertCount(Ministerio::ESTOQUE_ALVO + 1, $placas, 'os 5 do governo mais o Furgão do kit');
        $this->assertCount($placas->count(), $placas->unique(), 'nenhuma placa se repete');
    }

    // ---------------------------------------------------------------- a vaga (D-28, D-60)

    public function test_colonia_sem_central_de_transportes_tem_teto_de_um(): void
    {
        $colony = $this->colono()->colony;

        // O D-59 fez com que construção não erguida não exista: a colônia nova NÃO tem Central.
        $this->assertSame(0, $colony->buildings()->where('type', 'central_de_transportes')->count());

        // E mesmo assim o Furgão do kit cabe. É o piso de 1 que o D-60 pôs para resolver isto.
        $this->assertSame(1, app(Vagas::class)->teto($colony));
        $this->assertSame(0, app(Vagas::class)->livres($colony));
    }

    public function test_o_teto_e_o_nivel_da_central(): void
    {
        $colony = $this->colono()->colony;
        $this->erguerPredio($colony, 'central_de_transportes', 4);

        $this->assertSame(4, app(Vagas::class)->teto($colony));
        $this->assertSame(3, app(Vagas::class)->livres($colony), 'o Furgão do kit já ocupa uma');
    }

    // ---------------------------------------------------------------- a fábrica

    public function test_o_ministerio_repoe_a_prateleira_consumindo_o_tesouro(): void
    {
        $this->dotarTesouro();

        $resultado = app(FabricarCaminhoes::class)->handle();

        $this->assertSame(Ministerio::ESTOQUE_ALVO, $resultado['encomendados']);
        $this->assertSame(Ministerio::ESTOQUE_ALVO, Vehicle::whereNull('colony_id')->where('status', 'fabricando')->count());

        // O Tesouro pagou por cada um: dotámo-lo para 10, saíram 5.
        $this->assertSame(
            Ministerio::custoFabricacao()['ligas_metalicas'] * 5,
            (int) TreasuryHolding::whereKey('ligas_metalicas')->value('amount'),
        );
    }

    public function test_sem_tesouro_nao_ha_caminhao(): void
    {
        // Caixa vazio: o governo não fabrica o que não pode pagar (D-60).
        $this->assertSame(0, app(FabricarCaminhoes::class)->handle()['encomendados']);
        $this->assertSame(0, Vehicle::whereNull('colony_id')->count());
    }

    public function test_o_tesouro_incompleto_nao_debita_nada(): void
    {
        // Ligas de sobra, mas nem um Componente: a fabricação tem de falhar INTEIRA.
        TreasuryHolding::updateOrCreate(['resource_type' => 'ligas_metalicas'], ['amount' => 10_000]);
        TreasuryHolding::updateOrCreate(['resource_type' => 'componentes_eletronicos'], ['amount' => 0]);
        TreasuryHolding::updateOrCreate(['resource_type' => 'metal_bruto'], ['amount' => 10_000]);

        app(FabricarCaminhoes::class)->handle();

        $this->assertSame(0, Vehicle::whereNull('colony_id')->count());
        $this->assertSame(
            10_000,
            (int) TreasuryHolding::whereKey('ligas_metalicas')->value('amount'),
            'as Ligas não podem ter sido debitadas por um caminhão que não nasceu',
        );
    }

    public function test_a_prateleira_nao_passa_do_alvo(): void
    {
        $this->dotarTesouro(50);

        app(FabricarCaminhoes::class)->handle();
        app(FabricarCaminhoes::class)->handle();

        $this->assertSame(Ministerio::ESTOQUE_ALVO, Vehicle::whereNull('colony_id')->count());
    }

    public function test_o_caminhao_pronto_vai_para_a_prateleira(): void
    {
        $this->dotarTesouro();
        app(FabricarCaminhoes::class)->handle();

        $this->travelTo(now()->addMinutes(Ministerio::MINUTOS_FABRICACAO + 1));

        $this->assertSame(Ministerio::ESTOQUE_ALVO, app(FabricarCaminhoes::class)->handle()['prontos']);
        $this->assertSame(Ministerio::ESTOQUE_ALVO, Vehicle::whereNull('colony_id')->where('status', 'estoque')->count());
    }

    // ---------------------------------------------------------------- a compra

    public function test_comprar_debita_o_fert_e_o_caminhao_vem_dirigindo(): void
    {
        $this->prepararPrateleira();
        $user = $this->compradorPronto();

        $resposta = $this->actingAs($user)->postJson('/transport/buy')
            ->assertCreated()
            ->assertJsonPath('comprado.a_caminho', true);

        $caminhao = Vehicle::find($resposta->json('comprado.id'));

        $this->assertSame($user->colony->id, $caminhao->colony_id, 'o caminhão ganhou dono');
        $this->assertSame('em_rota', $caminhao->status, 'ele vem dirigindo da Capital');
        $this->assertSame('entrega_de_fabrica', $caminhao->trip_purpose);
        $this->assertSame(0, (int) $user->colony->fresh()->fert_micro, 'os 300 Fert$ saíram');

        // E entraram no Tesouro (D-57): o dreno de Fert$ que o D-60 queria.
        $this->assertSame(
            Ministerio::PRECO_MICRO,
            (int) TreasuryHolding::whereKey(\App\Domain\Treasury\Tesouro::FERT)->value('amount'),
        );
    }

    /**
     * O refetch da tela depois da compra (D-74, ao caçar um e2e vermelho): a prateleira baixa e a
     * vaga fecha JÁ NA PRÓXIMA LEITURA — se isto passa e a tela mostra o número velho, o problema
     * é a corrida do navegador, não o servidor.
     */
    public function test_depois_da_compra_a_vitrine_ja_mostra_a_prateleira_menor(): void
    {
        $this->prepararPrateleira();
        $user = $this->compradorPronto();

        $antes = $this->actingAs($user)->getJson('/transport')->json();

        $this->actingAs($user)->postJson('/transport/buy')->assertCreated();

        $this->actingAs($user)->getJson('/transport')
            ->assertOk()
            ->assertJsonPath('caminhao.em_estoque', $antes['caminhao']['em_estoque'] - 1)
            ->assertJsonPath('frota.livres', $antes['frota']['livres'] - 1);
    }

    public function test_a_venda_nao_cria_veiculo_e_preserva_a_placa(): void
    {
        $this->prepararPrateleira();
        $user = $this->compradorPronto();

        $antes = Vehicle::count();
        $placaNaPrateleira = Vehicle::whereNull('colony_id')->orderBy('id')->value('plate');

        $resposta = $this->actingAs($user)->postJson('/transport/buy')->assertCreated();

        $this->assertSame($antes, Vehicle::count(), 'a venda dá dono a um veículo, não cria outro');
        $this->assertSame($placaNaPrateleira, $resposta->json('comprado.placa'), 'a placa é do veículo, não do dono');
    }

    public function test_o_caminhao_entregue_fica_ocioso_na_colonia(): void
    {
        $this->prepararPrateleira();
        $user = $this->compradorPronto();

        $id = $this->actingAs($user)->postJson('/transport/buy')->json('comprado.id');

        // A viagem de entrega é fechada pela mesma máquina de sempre — sem uma linha nova.
        $this->travelTo(now()->addDays(2));
        app(\App\Domain\Logistics\ConcluirTrechos::class)->handle();

        $caminhao = Vehicle::find($id);

        $this->assertSame('ocioso', $caminhao->status);
        $this->assertNull($caminhao->trip_purpose);
        $this->assertSame($user->colony->id, $caminhao->colony_id);
    }

    public function test_sem_vaga_nao_compra_e_o_fert_nao_sai(): void
    {
        $this->prepararPrateleira();

        // Sem Central de Transportes: teto 1, e o Furgão do kit já o ocupa.
        $user = $this->colono();
        $user->colony->update(['fert_micro' => Ministerio::PRECO_MICRO * 10]);

        $this->actingAs($user)->postJson('/transport/buy')
            ->assertStatus(422)
            ->assertJsonPath('code', 'frota_cheia');

        $this->assertSame(
            Ministerio::PRECO_MICRO * 10,
            (int) $user->colony->fresh()->fert_micro,
            'a vaga é conferida ANTES de o Fert$ sair',
        );
    }

    public function test_sem_fert_nao_compra(): void
    {
        $this->prepararPrateleira();
        $user = $this->compradorPronto(fertMicro: Ministerio::PRECO_MICRO - 1);

        $this->actingAs($user)->postJson('/transport/buy')
            ->assertStatus(422)
            ->assertJsonPath('code', 'fert_insuficiente');
    }

    public function test_prateleira_vazia_recusa_a_compra(): void
    {
        // Tesouro seco: nada na prateleira.
        $user = $this->compradorPronto();

        $this->actingAs($user)->postJson('/transport/buy')
            ->assertStatus(422)
            ->assertJsonPath('code', 'sem_caminhao_em_estoque');

        $this->assertSame(Ministerio::PRECO_MICRO, (int) $user->colony->fresh()->fert_micro);
    }

    public function test_dois_compradores_nao_levam_o_mesmo_caminhao(): void
    {
        $this->prepararPrateleira();

        $a = $this->compradorPronto();
        $b = $this->compradorPronto();

        $um = $this->actingAs($a)->postJson('/transport/buy')->json('comprado.id');
        $dois = $this->actingAs($b)->postJson('/transport/buy')->json('comprado.id');

        $this->assertNotSame($um, $dois);
    }

    // ---------------------------------------------------------------- a Central não fabrica mais

    public function test_a_central_de_transportes_nao_fabrica_caminhao(): void
    {
        $colony = $this->colono()->colony;
        $this->erguerPredio($colony, 'central_de_transportes', 5);

        /*
         * O §28.5 dizia que os caminhões do nível vinham "sem custo adicional" com o upgrade da
         * Central. A tabela de precedência da seção 0 revogou isso (D-28), e o D-60 mudou a fábrica
         * de lugar: subir a Central ao nível 5 não dá caminhão nenhum. O que ela dá é vaga.
         */
        $this->assertSame(1, $colony->vehicles()->count(), 'só o Furgão do kit');
        $this->assertSame(0, $colony->vehicles()->where('type', Ministerio::TIPO)->count());
        $this->assertSame(5, app(Vagas::class)->teto($colony), 'o que ela dá é vaga');
    }

    // ---------------------------------------------------------------- a vitrine

    public function test_a_vitrine_mostra_a_prateleira_e_o_teto(): void
    {
        $this->prepararPrateleira();

        $user = $this->colono();
        $this->erguerPredio($user->colony, 'central_de_transportes', 3);

        $this->actingAs($user)->getJson('/transport')
            ->assertOk()
            ->assertJsonPath('caminhao.preco_fert', 300)
            ->assertJsonPath('caminhao.em_estoque', Ministerio::ESTOQUE_ALVO)
            ->assertJsonPath('frota.teto', 3)
            ->assertJsonPath('frota.livres', 2);
    }

    /** O veículo do governo não é de ninguém — e não pode aparecer na frota de um colono. */
    public function test_o_caminhao_do_governo_nao_aparece_na_frota_do_colono(): void
    {
        $this->prepararPrateleira();
        $user = $this->colono();

        $this->actingAs($user)->getJson('/transport')
            ->assertOk()
            ->assertJsonCount(1, 'veiculos');
    }
}
