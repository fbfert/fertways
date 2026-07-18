<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Transport\FabricarVeiculos;
use App\Domain\Transport\Ministerio;
use App\Domain\Transport\Vagas;
use App\Models\Colony;
use App\Models\TreasuryHolding;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O Ministério dos Transportes (D-60): placas, vagas e a fábrica de veículos — desde o D-109,
 * Caminhão de Carga E Furgão de Comércio, cada um com o seu preço/estoque/custo, editáveis pelo
 * admin (`fabrica_veiculos`).
 */
class MinisterioDosTransportesTest extends TestCase
{
    use RefreshDatabase;

    private const CAMINHAO = 'caminhao_de_carga';

    private const FURGAO = 'furgao_de_comercio';

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

    /** O caixa do governo, dotado para N unidades de CADA tipo que a fábrica produz. */
    private function dotarTesouro(int $vezes = 10): void
    {
        $total = [];

        foreach (Ministerio::TIPOS as $tipo) {
            foreach (Ministerio::config($tipo)['custo'] as $recurso => $qtd) {
                $total[$recurso] = ($total[$recurso] ?? 0) + $qtd * $vezes;
            }
        }

        foreach ($total as $recurso => $qtd) {
            TreasuryHolding::updateOrCreate(['resource_type' => $recurso], ['amount' => $qtd]);
        }
    }

    /** Fabrica e deixa as prateleiras dos DOIS tipos cheias. */
    private function prepararPrateleira(): void
    {
        $this->dotarTesouro();
        app(FabricarVeiculos::class)->handle();
        $maiorTempo = max(array_map(fn ($t) => Ministerio::config($t)['minutos_fabricacao'], Ministerio::TIPOS));
        $this->travelTo(now()->addMinutes($maiorTempo + 1));
        app(FabricarVeiculos::class)->handle();
    }

    /** Um colono com Central de Transportes (logo, com vaga) e dinheiro no bolso. */
    private function compradorPronto(int $nivelDaCentral = 2, ?int $fertMicro = null): User
    {
        $user = $this->colono();
        $this->erguerPredio($user->colony, 'central_de_transportes', $nivelDaCentral);
        $user->colony->update(['fert_micro' => $fertMicro ?? Ministerio::config(self::CAMINHAO)['preco_micro']]);

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
        app(FabricarVeiculos::class)->handle();

        $this->assertMatchesRegularExpression(
            '/^FW-\d{5}-C$/',
            Vehicle::whereNull('colony_id')->where('type', self::CAMINHAO)->first()->plate,
        );
    }

    public function test_a_placa_e_sequencial_e_nao_se_repete(): void
    {
        $this->colono();
        $this->dotarTesouro();
        app(FabricarVeiculos::class)->handle();

        $placas = Vehicle::whereNotNull('plate')->pluck('plate');
        $alvoTotal = Ministerio::config(self::CAMINHAO)['estoque_alvo'] + Ministerio::config(self::FURGAO)['estoque_alvo'];

        $this->assertCount($alvoTotal + 1, $placas, 'as prateleiras dos dois tipos mais o Furgão do kit');
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

    // ---------------------------------------------------------------- a fábrica, por tipo

    public function test_o_ministerio_repoe_as_duas_prateleiras_consumindo_o_tesouro(): void
    {
        $this->dotarTesouro();

        $resultado = app(FabricarVeiculos::class)->handle();

        $alvoCaminhao = Ministerio::config(self::CAMINHAO)['estoque_alvo'];
        $alvoFurgao = Ministerio::config(self::FURGAO)['estoque_alvo'];

        $this->assertSame($alvoCaminhao + $alvoFurgao, $resultado['encomendados']);
        $this->assertSame($alvoCaminhao, Vehicle::whereNull('colony_id')->where('type', self::CAMINHAO)->where('status', 'fabricando')->count());
        $this->assertSame($alvoFurgao, Vehicle::whereNull('colony_id')->where('type', self::FURGAO)->where('status', 'fabricando')->count());

        // O Tesouro pagou por cada um: dotámo-lo para 10×, saíram 5+5.
        $dotado = Ministerio::config(self::CAMINHAO)['custo']['ligas_metalicas'] * 10
            + Ministerio::config(self::FURGAO)['custo']['ligas_metalicas'] * 10;
        $gasto = Ministerio::config(self::CAMINHAO)['custo']['ligas_metalicas'] * $alvoCaminhao
            + Ministerio::config(self::FURGAO)['custo']['ligas_metalicas'] * $alvoFurgao;

        $this->assertSame($dotado - $gasto, (int) TreasuryHolding::whereKey('ligas_metalicas')->value('amount'));
    }

    /** O Furgão custa e demora 40% do Caminhão — pedido do usuário (D-109), não é do GDD. */
    public function test_o_furgao_custa_e_demora_40_por_cento_do_caminhao(): void
    {
        $custoCaminhao = Ministerio::config(self::CAMINHAO)['custo'];
        $custoFurgao = Ministerio::config(self::FURGAO)['custo'];

        $this->assertSame((int) round($custoCaminhao['ligas_metalicas'] * 0.4), $custoFurgao['ligas_metalicas']);
        $this->assertSame((int) round($custoCaminhao['componentes_eletronicos'] * 0.4), $custoFurgao['componentes_eletronicos']);
        $this->assertSame((int) round($custoCaminhao['metal_bruto'] * 0.4), $custoFurgao['metal_bruto']);

        $this->assertSame(
            (int) round(Ministerio::config(self::CAMINHAO)['minutos_fabricacao'] * 0.4),
            Ministerio::config(self::FURGAO)['minutos_fabricacao'],
        );

        $this->assertSame(150.0, Ministerio::precoFert(self::FURGAO));
        $this->assertSame(300.0, Ministerio::precoFert(self::CAMINHAO));
    }

    /** O custo de fabricação do Furgão NÃO é a tabela de manutenção do GDD (§21.2). */
    public function test_o_custo_de_fabricacao_do_furgao_nao_e_a_tabela_de_manutencao(): void
    {
        $custoFabricacao = Ministerio::config(self::FURGAO)['custo'];
        $custoManutencaoGdd = \App\Domain\Transport\VeiculoCustos::nivel1(self::FURGAO);

        $this->assertNotSame($custoManutencaoGdd, $custoFabricacao);
    }

    public function test_sem_tesouro_nao_ha_veiculo(): void
    {
        // Caixa vazio: o governo não fabrica o que não pode pagar (D-60).
        $this->assertSame(0, app(FabricarVeiculos::class)->handle()['encomendados']);
        $this->assertSame(0, Vehicle::whereNull('colony_id')->count());
    }

    public function test_o_tesouro_incompleto_nao_debita_nada(): void
    {
        // Ligas de sobra, mas nem um Componente: a fabricação tem de falhar INTEIRA.
        TreasuryHolding::updateOrCreate(['resource_type' => 'ligas_metalicas'], ['amount' => 10_000]);
        TreasuryHolding::updateOrCreate(['resource_type' => 'componentes_eletronicos'], ['amount' => 0]);
        TreasuryHolding::updateOrCreate(['resource_type' => 'metal_bruto'], ['amount' => 10_000]);

        app(FabricarVeiculos::class)->handle();

        $this->assertSame(0, Vehicle::whereNull('colony_id')->count());
        $this->assertSame(
            10_000,
            (int) TreasuryHolding::whereKey('ligas_metalicas')->value('amount'),
            'as Ligas não podem ter sido debitadas por um veículo que não nasceu',
        );
    }

    public function test_a_prateleira_nao_passa_do_alvo(): void
    {
        $this->dotarTesouro(50);

        app(FabricarVeiculos::class)->handle();
        app(FabricarVeiculos::class)->handle();

        $alvoTotal = Ministerio::config(self::CAMINHAO)['estoque_alvo'] + Ministerio::config(self::FURGAO)['estoque_alvo'];
        $this->assertSame($alvoTotal, Vehicle::whereNull('colony_id')->count());
    }

    public function test_o_veiculo_pronto_vai_para_a_prateleira(): void
    {
        $this->prepararPrateleira();

        $alvoTotal = Ministerio::config(self::CAMINHAO)['estoque_alvo'] + Ministerio::config(self::FURGAO)['estoque_alvo'];
        $this->assertSame($alvoTotal, Vehicle::whereNull('colony_id')->where('status', 'estoque')->count());
    }

    // ---------------------------------------------------------------- a compra, por tipo

    public function test_comprar_caminhao_debita_o_fert_e_ele_vem_dirigindo(): void
    {
        $this->prepararPrateleira();
        $user = $this->compradorPronto();

        $resposta = $this->actingAs($user)->postJson('/transport/buy', ['tipo' => self::CAMINHAO])
            ->assertCreated()
            ->assertJsonPath('comprado.a_caminho', true)
            ->assertJsonPath('comprado.tipo', self::CAMINHAO);

        $caminhao = Vehicle::find($resposta->json('comprado.id'));

        $this->assertSame($user->colony->id, $caminhao->colony_id, 'o caminhão ganhou dono');
        $this->assertSame('em_rota', $caminhao->status, 'ele vem dirigindo da Capital');
        $this->assertSame('entrega_de_fabrica', $caminhao->trip_purpose);
        $this->assertSame(0, (int) $user->colony->fresh()->fert_micro, 'os 300 Fert$ saíram');

        // E entraram no Tesouro (D-57): o dreno de Fert$ que o D-60 queria.
        $this->assertSame(
            Ministerio::config(self::CAMINHAO)['preco_micro'],
            (int) TreasuryHolding::whereKey(\App\Domain\Treasury\Tesouro::FERT)->value('amount'),
        );
    }

    public function test_comprar_furgao_custa_150_fert(): void
    {
        $this->prepararPrateleira();
        $user = $this->compradorPronto(fertMicro: 150_000_000);

        $this->actingAs($user)->postJson('/transport/buy', ['tipo' => self::FURGAO])
            ->assertCreated()
            ->assertJsonPath('comprado.tipo', self::FURGAO);

        $this->assertSame(0, (int) $user->colony->fresh()->fert_micro);
    }

    public function test_tipo_desconhecido_e_recusado(): void
    {
        $this->prepararPrateleira();
        $user = $this->compradorPronto();

        $this->actingAs($user)->postJson('/transport/buy', ['tipo' => 'nave_inventada'])
            ->assertStatus(422);
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

        $this->actingAs($user)->postJson('/transport/buy', ['tipo' => self::CAMINHAO])->assertCreated();

        $this->actingAs($user)->getJson('/transport')
            ->assertOk()
            ->assertJsonPath('fabrica.'.self::CAMINHAO.'.em_estoque', $antes['fabrica'][self::CAMINHAO]['em_estoque'] - 1)
            ->assertJsonPath('frota.livres', $antes['frota']['livres'] - 1);
    }

    public function test_a_venda_nao_cria_veiculo_e_preserva_a_placa(): void
    {
        $this->prepararPrateleira();
        $user = $this->compradorPronto();

        $antes = Vehicle::count();
        $placaNaPrateleira = Vehicle::whereNull('colony_id')->where('type', self::CAMINHAO)->orderBy('id')->value('plate');

        $resposta = $this->actingAs($user)->postJson('/transport/buy', ['tipo' => self::CAMINHAO])->assertCreated();

        $this->assertSame($antes, Vehicle::count(), 'a venda dá dono a um veículo, não cria outro');
        $this->assertSame($placaNaPrateleira, $resposta->json('comprado.placa'), 'a placa é do veículo, não do dono');
    }

    public function test_o_caminhao_entregue_fica_ocioso_na_colonia(): void
    {
        $this->prepararPrateleira();
        $user = $this->compradorPronto();

        $id = $this->actingAs($user)->postJson('/transport/buy', ['tipo' => self::CAMINHAO])->json('comprado.id');

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
        $preco = Ministerio::config(self::CAMINHAO)['preco_micro'];
        $user->colony->update(['fert_micro' => $preco * 10]);

        $this->actingAs($user)->postJson('/transport/buy', ['tipo' => self::CAMINHAO])
            ->assertStatus(422)
            ->assertJsonPath('code', 'frota_cheia');

        $this->assertSame(
            $preco * 10,
            (int) $user->colony->fresh()->fert_micro,
            'a vaga é conferida ANTES de o Fert$ sair',
        );
    }

    public function test_sem_fert_nao_compra(): void
    {
        $this->prepararPrateleira();
        $user = $this->compradorPronto(fertMicro: Ministerio::config(self::CAMINHAO)['preco_micro'] - 1);

        $this->actingAs($user)->postJson('/transport/buy', ['tipo' => self::CAMINHAO])
            ->assertStatus(422)
            ->assertJsonPath('code', 'fert_insuficiente');
    }

    public function test_prateleira_vazia_recusa_a_compra(): void
    {
        // Tesouro seco: nada na prateleira.
        $user = $this->compradorPronto();

        $this->actingAs($user)->postJson('/transport/buy', ['tipo' => self::CAMINHAO])
            ->assertStatus(422)
            ->assertJsonPath('code', 'sem_veiculo_em_estoque');

        $this->assertSame(Ministerio::config(self::CAMINHAO)['preco_micro'], (int) $user->colony->fresh()->fert_micro);
    }

    public function test_dois_compradores_nao_levam_o_mesmo_veiculo(): void
    {
        $this->prepararPrateleira();

        $a = $this->compradorPronto();
        $b = $this->compradorPronto();

        $um = $this->actingAs($a)->postJson('/transport/buy', ['tipo' => self::CAMINHAO])->json('comprado.id');
        $dois = $this->actingAs($b)->postJson('/transport/buy', ['tipo' => self::CAMINHAO])->json('comprado.id');

        $this->assertNotSame($um, $dois);
    }

    // ---------------------------------------------------------------- a Central não fabrica mais

    public function test_a_central_de_transportes_nao_fabrica_veiculo(): void
    {
        $colony = $this->colono()->colony;
        $this->erguerPredio($colony, 'central_de_transportes', 5);

        /*
         * O §28.5 dizia que os caminhões do nível vinham "sem custo adicional" com o upgrade da
         * Central. A tabela de precedência da seção 0 revogou isso (D-28), e o D-60 mudou a fábrica
         * de lugar: subir a Central ao nível 5 não dá veículo nenhum. O que ela dá é vaga.
         */
        $this->assertSame(1, $colony->vehicles()->count(), 'só o Furgão do kit');
        $this->assertSame(0, $colony->vehicles()->where('type', self::CAMINHAO)->count());
        $this->assertSame(5, app(Vagas::class)->teto($colony), 'o que ela dá é vaga');
    }

    // ---------------------------------------------------------------- a vitrine

    public function test_a_vitrine_mostra_as_duas_prateleiras_e_o_teto(): void
    {
        $this->prepararPrateleira();

        $user = $this->colono();
        $this->erguerPredio($user->colony, 'central_de_transportes', 3);

        $this->actingAs($user)->getJson('/transport')
            ->assertOk()
            ->assertJsonPath('fabrica.'.self::CAMINHAO.'.preco_fert', 300)
            ->assertJsonPath('fabrica.'.self::CAMINHAO.'.em_estoque', Ministerio::config(self::CAMINHAO)['estoque_alvo'])
            ->assertJsonPath('fabrica.'.self::FURGAO.'.preco_fert', 150)
            ->assertJsonPath('fabrica.'.self::FURGAO.'.em_estoque', Ministerio::config(self::FURGAO)['estoque_alvo'])
            ->assertJsonPath('frota.teto', 3)
            ->assertJsonPath('frota.livres', 2);
    }

    /** O veículo do governo não é de ninguém — e não pode aparecer na frota de um colono. */
    public function test_o_veiculo_do_governo_nao_aparece_na_frota_do_colono(): void
    {
        $this->prepararPrateleira();
        $user = $this->colono();

        $this->actingAs($user)->getJson('/transport')
            ->assertOk()
            ->assertJsonCount(1, 'veiculos');
    }
}
