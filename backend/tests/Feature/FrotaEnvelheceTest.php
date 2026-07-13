<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Logistics\ConcluirTrechos;
use App\Domain\Logistics\DespacharVeiculo;
use App\Domain\Logistics\VeiculoSpecs;
use App\Domain\Transport\Conservacao;
use App\Domain\Transport\Manutencao;
use App\Domain\Transport\MercadoDeUsados;
use App\Domain\Transport\Ministerio;
use App\Domain\Transport\Sucatear;
use App\Models\Colony;
use App\Models\TransportSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A frota envelhece (D-60, fatia 2) e há mercado de usados (fatia 3). GDD §16.3 e §16.4.
 */
class FrotaEnvelheceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
        $this->seed(\Database\Seeders\TransportSettingSeeder::class);
    }

    private int $proximo = 0;

    private function colono(): User
    {
        $user = User::factory()->create(['tutorial_completed_at' => now()]);
        app(CreateColony::class)->handle($user, 'Colônia', 10 + $this->proximo++, 20);

        return $user->fresh();
    }

    private function furgao(Colony $colony): Vehicle
    {
        return $colony->vehicles()->where('type', 'furgao_de_comercio')->first();
    }

    /** Gasta o veículo até uma conservação exata, sem passar pela estrada. */
    private function desgastar(Vehicle $v, float $porCento): Vehicle
    {
        $v->forceFill(['conservacao_bps' => (int) round($porCento * 100)])->save();

        return $v->fresh();
    }

    // ---------------------------------------------------------------- a semente do operador

    public function test_os_parametros_sao_do_operador_e_nao_do_codigo(): void
    {
        $c = TransportSetting::singleton();

        // O §16 manda o painel do Ministério configurá-los; o GDD não publica nenhum.
        $this->assertSame(50, $c->desgaste_bps_por_hora, '0,5% por hora de uso ativo');
        $this->assertSame(2_500, $c->piso_desempenho_bps, 'o piso de 25%');
        $this->assertSame(1_000, $c->manutencao_bps_do_custo, '10% do custo do veículo');
        $this->assertSame(500, $c->perda_de_teto_bps, '5 pontos por manutenção');

        /*
         * E a PRIMEIRA chamada já traz os números — é a lição do `WarSetting` (D-70): o
         * `firstOrCreate([])` cru devolve, no caminho da criação, um modelo sem os defaults que o
         * banco aplicou. Este teste roda num banco recém-migrado, então ele é exatamente a primeira
         * chamada; se o singleton não relesse, todas as asserções acima teriam visto null.
         */
        $this->assertSame(60_000_000, $c->furgao_preco_referencia_micro, 'a âncora do Furgão: 60 Fert$ (D-73)');
    }

    // ---------------------------------------------------------------- o desgaste (§16.4)

    public function test_o_veiculo_nasce_novo(): void
    {
        $v = $this->furgao($this->colono()->colony);

        $this->assertSame(10_000, (int) $v->conservacao_bps, '100% — "veículo novo"');
        $this->assertSame(10_000, (int) $v->teto_conservacao_bps);
        $this->assertSame(0, (int) $v->uso_ativo_seg);
    }

    public function test_uma_hora_de_estrada_custa_meio_por_cento(): void
    {
        $v = $this->furgao($this->colono()->colony);

        app(Conservacao::class)->cobrarTrecho($v, 3_600);

        $this->assertSame(9_950, (int) $v->fresh()->conservacao_bps);
        $this->assertSame(3_600, (int) $v->fresh()->uso_ativo_seg);
    }

    public function test_o_veiculo_parado_nao_envelhece(): void
    {
        // §16.4, explícito: "desgaste calculado por horas de uso ativo — NÃO por tempo desde a
        // fabricação". Passar o tempo não gasta nada.
        $v = $this->furgao($this->colono()->colony);
        $this->travelTo(now()->addDays(30));

        $this->assertSame(10_000, (int) $v->fresh()->conservacao_bps);
    }

    public function test_o_desgaste_encolhe_velocidade_e_capacidade(): void
    {
        $v = $this->desgastar($this->furgao($this->colono()->colony), 50);
        $conservacao = app(Conservacao::class);

        // §16.4: "sem manutenção, o veículo fica mais lento e carrega menos progressivamente".
        $this->assertSame(3_000, $conservacao->capacidadeEfetiva($v), 'metade dos 6.000 do Furgão');
        $this->assertSame(
            2 * VeiculoSpecs::segundosDoTrecho($v->type, 20),
            $conservacao->segundosDoTrecho($v, 20),
            'a 50% ele leva o dobro do tempo',
        );
    }

    public function test_o_desempenho_nunca_cai_abaixo_do_piso_e_o_veiculo_nunca_trava(): void
    {
        // A CONTRADIÇÃO DELIBERADA (D-60). O §16.4 nomeia um "bloqueio operacional" abaixo do
        // limite crítico; o usuário decidiu que o veículo NUNCA trava. O limite crítico virou o
        // PISO de desempenho. Uma carcaça a 0% ainda anda a 25%.
        $v = $this->desgastar($this->furgao($this->colono()->colony), 0);
        $conservacao = app(Conservacao::class);

        $this->assertSame(2_500, $conservacao->desempenhoBps($v), 'o piso de 25%');
        $this->assertSame(1_500, $conservacao->capacidadeEfetiva($v), 'um quarto dos 6.000 — mas não zero');
        $this->assertSame('ocioso', $v->status, 'e ele continua disponível: nada o bloqueia');
    }

    public function test_o_despacho_respeita_a_capacidade_efetiva(): void
    {
        $user = $this->colono();
        $colony = $user->colony;
        $colony->resources()->update(['amount' => 100_000]);

        $v = $this->desgastar($this->furgao($colony), 50);

        // 6.000 é a capacidade de placa; 3.000 é a que o veículo gasto de fato entrega.
        $this->actingAs($user)->postJson('/vehicles/'.$v->id.'/dispatch', [
            'destination_type' => 'mercado_central',
            'cargo' => ['metal_bruto' => 4_000],
        ])->assertStatus(422)->assertJsonPath('code', 'carga_excede_capacidade');

        $this->actingAs($user)->postJson('/vehicles/'.$v->id.'/dispatch', [
            'destination_type' => 'mercado_central',
            'cargo' => ['metal_bruto' => 3_000],
        ])->assertCreated();
    }

    public function test_uma_viagem_de_verdade_gasta_o_veiculo(): void
    {
        $user = $this->colono();
        $colony = $user->colony;
        $colony->resources()->update(['amount' => 100_000]);

        $v = $this->furgao($colony);

        $this->actingAs($user)->postJson('/vehicles/'.$v->id.'/dispatch', [
            'destination_type' => 'mercado_central',
            'cargo' => ['metal_bruto' => 1_000],
        ])->assertCreated();

        $this->travelTo(now()->addDays(2));

        // Duas chamadas: cada `handle()` fecha UM trecho. A ida termina e a volta começa dentro da
        // mesma varredura, já depois de o laço ter passado pelo veículo — então a volta só fecha na
        // execução seguinte. No jogo isso é o tick do minuto seguinte, e ninguém nota.
        app(ConcluirTrechos::class)->handle();
        app(ConcluirTrechos::class)->handle();

        $v = $v->fresh();

        $this->assertSame('ocioso', $v->status, 'voltou');
        $this->assertGreaterThan(0, (int) $v->uso_ativo_seg, 'e acumulou horas de uso ativo');
        $this->assertLessThan(10_000, (int) $v->conservacao_bps, 'e voltou mais gasto do que saiu');
    }

    // ---------------------------------------------------------------- a manutenção (§16.4)

    private function comCentral(int $nivel = 1): User
    {
        $user = $this->colono();
        $this->erguerPredio($user->colony, 'central_de_transportes', $nivel);
        $user->colony->resources()->update(['amount' => 10_000]);

        return $user;
    }

    public function test_a_manutencao_custa_dez_por_cento_da_tabela_do_gdd(): void
    {
        $user = $this->comCentral();
        $v = $this->furgao($user->colony);

        // §21.2, Furgão nível 1: 40 Ligas, 10 Componentes, 7 Metal Bruto. 10% de cada, para cima.
        $this->assertSame(
            ['ligas_metalicas' => 4, 'componentes_eletronicos' => 1, 'metal_bruto' => 1],
            app(Manutencao::class)->custo($v),
        );
    }

    public function test_a_manutencao_restaura_ate_o_teto_e_corroi_o_teto(): void
    {
        $user = $this->comCentral();
        $v = $this->desgastar($this->furgao($user->colony), 40);

        $reparado = app(Manutencao::class)->handle($user->colony, $v);

        // Restaura até o TETO (que ainda era 100%), e o teto cai 5 pontos.
        $this->assertSame(10_000, (int) $reparado->conservacao_bps);
        $this->assertSame(9_500, (int) $reparado->teto_conservacao_bps, 'a vida útil encolheu e não volta');
        $this->assertSame(1, (int) $reparado->manutencoes);

        // E cobrou os recursos.
        $ligas = $user->colony->resources()->where('resource_type', 'ligas_metalicas')->value('amount');
        $this->assertSame(10_000 - 4, (int) $ligas);
    }

    public function test_a_segunda_manutencao_ja_nao_devolve_cem_por_cento(): void
    {
        $user = $this->comCentral();
        $v = $this->furgao($user->colony);

        app(Manutencao::class)->handle($user->colony, $this->desgastar($v, 40));
        $segunda = app(Manutencao::class)->handle($user->colony, $this->desgastar($v->fresh(), 40));

        $this->assertSame(9_500, (int) $segunda->conservacao_bps, 'volta ao teto, que já é 95%');
        $this->assertSame(9_000, (int) $segunda->teto_conservacao_bps);
    }

    public function test_sem_central_de_transportes_nao_ha_manutencao(): void
    {
        // ARBITRAGEM DO ASSISTENTE (D-60): a manutenção é "na Central de Transportes do colono", e
        // colônia nova não tem Central (D-59). Ela não pode manter o próprio Furgão até erguer uma.
        $user = $this->colono();
        $user->colony->resources()->update(['amount' => 10_000]);
        $v = $this->desgastar($this->furgao($user->colony), 40);

        $this->actingAs($user)->postJson("/transport/vehicles/{$v->id}/maintain")
            ->assertStatus(422)
            ->assertJsonPath('code', 'sem_central_de_transportes');
    }

    public function test_nao_repara_o_que_ja_esta_no_teto(): void
    {
        $user = $this->comCentral();
        $v = $this->furgao($user->colony);

        $this->actingAs($user)->postJson("/transport/vehicles/{$v->id}/maintain")
            ->assertStatus(422)
            ->assertJsonPath('code', 'nada_a_reparar');
    }

    public function test_sem_recursos_nao_repara(): void
    {
        $user = $this->comCentral();
        $user->colony->resources()->update(['amount' => 0]);
        $v = $this->desgastar($this->furgao($user->colony), 40);

        $this->actingAs($user)->postJson("/transport/vehicles/{$v->id}/maintain")
            ->assertStatus(422)
            ->assertJsonPath('code', 'recursos_insuficientes');
    }

    // ---------------------------------------------------------------- a sucata

    public function test_sucatear_some_com_o_veiculo_e_libera_a_vaga(): void
    {
        $user = $this->comCentral(3);
        $v = $this->furgao($user->colony);

        $this->actingAs($user)->deleteJson("/transport/vehicles/{$v->id}")->assertOk();

        $this->assertNull(Vehicle::find($v->id));
        $this->assertSame(0, $user->colony->vehicles()->count());
    }

    public function test_a_placa_do_sucateado_nao_volta_ao_estoque(): void
    {
        $user = $this->comCentral(3);
        app(Sucatear::class)->handle($user->colony, $this->furgao($user->colony));

        // O próximo veículo do planeta não pode herdar a placa do morto: duas máquinas diferentes
        // nunca levaram o mesmo número.
        $outro = $this->colono();

        $this->assertSame('FW-00002-F', $this->furgao($outro->colony)->plate);
    }

    public function test_nao_sucateia_veiculo_em_rota(): void
    {
        $user = $this->comCentral();
        $v = $this->furgao($user->colony);
        $v->forceFill(['status' => 'em_rota'])->save();

        $this->actingAs($user)->deleteJson("/transport/vehicles/{$v->id}")
            ->assertStatus(422)
            ->assertJsonPath('code', 'veiculo_em_rota');
    }

    // ---------------------------------------------------------------- o mercado de usados

    public function test_o_teto_de_revenda_cai_com_a_manutencao(): void
    {
        $user = $this->comCentral();
        $caminhao = $user->colony->vehicles()->create([
            'type' => Ministerio::TIPO, 'level' => 1, 'status' => 'ocioso',
            'capacity' => VeiculoSpecs::CAPACIDADE[Ministerio::TIPO],
        ]);

        $conservacao = app(Conservacao::class);

        // Novo: o teto é o preço de fábrica.
        $this->assertSame(Ministerio::PRECO_MICRO, $conservacao->tetoDeRevendaMicro($caminhao));

        app(Manutencao::class)->handle($user->colony, $this->desgastar($caminhao, 40));

        // Uma manutenção: o teto de conservação é 95%, logo o de revenda é 95% de 300 = 285 Fert$.
        $this->assertSame(
            285 * Colony::MICRO_POR_FERT,
            $conservacao->tetoDeRevendaMicro($caminhao->fresh()),
        );
    }

    /**
     * A REVISÃO do aditivo 14 (D-73). O Furgão ficou sem teto de propósito — e era por esse buraco
     * que duas contas do mesmo jogador podiam lavar Fert$: um Furgão sucateado anunciado por 5.000
     * movia dinheiro limpo pelo escrow, sem carga e sem tributo. O usuário reviu: âncora de
     * referência do operador, 60 Fert$ por padrão (1/5 do Caminhão, como a capacidade).
     */
    public function test_o_furgao_agora_tem_teto_e_ele_e_do_operador(): void
    {
        $v = $this->furgao($this->colono()->colony);

        // Novo: teto = referência inteira.
        $this->assertSame(60_000_000, app(Conservacao::class)->tetoDeRevendaMicro($v));

        // Gasto a 50%: o teto acompanha a conservação, como no Caminhão.
        $this->assertSame(
            30_000_000,
            app(Conservacao::class)->tetoDeRevendaMicro($v->forceFill(['teto_conservacao_bps' => 5_000])),
        );

        // E a âncora é do OPERADOR: mudou no painel, mudou o teto — sem deploy.
        TransportSetting::singleton()->update(['furgao_preco_referencia_micro' => 100_000_000]);
        $this->assertSame(
            100_000_000,
            app(Conservacao::class)->tetoDeRevendaMicro($v->forceFill(['teto_conservacao_bps' => 10_000])),
        );
    }

    public function test_a_lavagem_pelo_furgao_esta_fechada(): void
    {
        // O cenário EXATO do aditivo 14: a carcaça anunciada por 5.000 Fert$ para a segunda conta
        // "comprar". Hoje o anúncio morre na porta, como o do Caminhão sempre morreu.
        $user = $this->colono();
        $carcaca = $this->desgastar($this->furgao($user->colony), 8.0);

        $this->actingAs($user)->postJson('/transport/listings', [
            'vehicle_id' => $carcaca->id,
            'preco_fert' => 5_000,
        ])->assertStatus(422)->assertJsonPath('code', 'acima_do_teto_de_revenda');

        // Dentro do teto, o anúncio passa: fechar a lavagem não fechou o mercado.
        $this->actingAs($user)->postJson('/transport/listings', [
            'vehicle_id' => $carcaca->id,
            'preco_fert' => 30,
        ])->assertCreated();
    }

    public function test_anunciar_caminhao_acima_do_teto_e_recusado(): void
    {
        $user = $this->comCentral();
        $caminhao = $user->colony->vehicles()->create([
            'type' => Ministerio::TIPO, 'level' => 1, 'status' => 'ocioso',
            'capacity' => VeiculoSpecs::CAPACIDADE[Ministerio::TIPO],
        ]);

        $this->actingAs($user)->postJson('/transport/listings', [
            'vehicle_id' => $caminhao->id,
            'preco_fert' => 301,
        ])->assertStatus(422)->assertJsonPath('code', 'acima_do_teto_de_revenda');

        $this->actingAs($user)->postJson('/transport/listings', [
            'vehicle_id' => $caminhao->id,
            'preco_fert' => 300,
        ])->assertCreated();
    }

    public function test_o_usado_e_pago_com_escrow_e_o_vendedor_so_recebe_na_chegada(): void
    {
        $vendedor = $this->comCentral(3);
        $comprador = $this->comCentral(3);
        $comprador->colony->update(['fert_micro' => 200 * Colony::MICRO_POR_FERT]);

        $v = $this->furgao($vendedor->colony);
        $fertDoVendedorAntes = (int) $vendedor->colony->fresh()->fert_micro;

        // 50 Fert$: dentro do teto do Furgão, que desde o D-73 existe (60 × conservação).
        $anuncio = app(MercadoDeUsados::class)->anunciar(
            $vendedor->colony, $v, 50 * Colony::MICRO_POR_FERT,
        );

        $this->actingAs($comprador)->postJson("/transport/listings/{$anuncio->id}/buy")
            ->assertCreated()
            ->assertJsonPath('comprado.a_caminho', true);

        // O comprador pagou; o VENDEDOR AINDA NÃO RECEBEU — os Fert$ estão retidos no Ministério.
        $this->assertSame(150 * Colony::MICRO_POR_FERT, (int) $comprador->colony->fresh()->fert_micro);
        $this->assertSame($fertDoVendedorAntes, (int) $vendedor->colony->fresh()->fert_micro);
        $this->assertSame(50 * Colony::MICRO_POR_FERT, (int) $anuncio->fresh()->escrow_micro);
        $this->assertSame('em_transito', $anuncio->fresh()->status);

        // O veículo já é do comprador, e vem dirigindo.
        $this->assertSame($comprador->colony->id, $v->fresh()->colony_id);
        $this->assertSame('venda_usado', $v->fresh()->trip_purpose);

        // A chegada é que paga.
        $this->travelTo(now()->addDays(2));
        app(ConcluirTrechos::class)->handle();

        $this->assertSame(
            $fertDoVendedorAntes + 50 * Colony::MICRO_POR_FERT,
            (int) $vendedor->colony->fresh()->fert_micro,
            'só agora o vendedor recebe',
        );
        $this->assertSame('concluido', $anuncio->fresh()->status);
        $this->assertSame(0, (int) $anuncio->fresh()->escrow_micro);
        $this->assertSame('ocioso', $v->fresh()->status);
    }

    public function test_a_viagem_do_usado_nao_gasta_o_veiculo(): void
    {
        // Quem comprou não pode receber o veículo mais gasto do que o anúncio dizia (D-60).
        $vendedor = $this->comCentral(3);
        $comprador = $this->comCentral(3);
        $comprador->colony->update(['fert_micro' => 200 * Colony::MICRO_POR_FERT]);

        $v = $this->desgastar($this->furgao($vendedor->colony), 80);

        $anuncio = app(MercadoDeUsados::class)->anunciar(
            $vendedor->colony, $v, 50 * Colony::MICRO_POR_FERT,
        );

        app(MercadoDeUsados::class)->comprar($comprador->colony, $anuncio);

        $this->travelTo(now()->addDays(2));
        app(ConcluirTrechos::class)->handle();

        $this->assertSame(8_000, (int) $v->fresh()->conservacao_bps, 'chegou como estava anunciado');
        $this->assertSame(0, (int) $v->fresh()->uso_ativo_seg, 'a entrega não é uso ativo');
    }

    public function test_sem_vaga_nao_compra_usado_e_o_fert_nao_sai(): void
    {
        $vendedor = $this->comCentral(3);

        // Comprador sem Central: teto 1, e o Furgão do kit já o ocupa.
        $comprador = $this->colono();
        $comprador->colony->update(['fert_micro' => 500 * Colony::MICRO_POR_FERT]);

        $anuncio = app(MercadoDeUsados::class)->anunciar(
            $vendedor->colony, $this->furgao($vendedor->colony), 50 * Colony::MICRO_POR_FERT,
        );

        $this->actingAs($comprador)->postJson("/transport/listings/{$anuncio->id}/buy")
            ->assertStatus(422)
            ->assertJsonPath('code', 'frota_cheia');

        $this->assertSame(500 * Colony::MICRO_POR_FERT, (int) $comprador->colony->fresh()->fert_micro);
    }

    public function test_ninguem_compra_o_proprio_anuncio(): void
    {
        $user = $this->comCentral(3);
        $user->colony->update(['fert_micro' => 500 * Colony::MICRO_POR_FERT]);

        $anuncio = app(MercadoDeUsados::class)->anunciar(
            $user->colony, $this->furgao($user->colony), 50 * Colony::MICRO_POR_FERT,
        );

        $this->actingAs($user)->postJson("/transport/listings/{$anuncio->id}/buy")
            ->assertStatus(422)
            ->assertJsonPath('code', 'anuncio_seu');
    }

    public function test_veiculo_anunciado_nao_sai_em_viagem(): void
    {
        /*
         * Sem esta guarda, o vendedor anunciava e despachava em seguida — e o comprador que
         * clicasse em "comprar" levava um erro na cara, por culpa do vendedor. O anúncio é um
         * compromisso: ou você o está vendendo, ou o está usando.
         */
        $user = $this->comCentral(3);
        $user->colony->resources()->update(['amount' => 100_000]);
        $v = $this->furgao($user->colony);

        app(MercadoDeUsados::class)->anunciar($user->colony, $v, 50 * Colony::MICRO_POR_FERT);

        $this->actingAs($user)->postJson('/vehicles/'.$v->id.'/dispatch', [
            'destination_type' => 'mercado_central',
            'cargo' => ['metal_bruto' => 1_000],
        ])->assertStatus(422)->assertJsonPath('code', 'veiculo_anunciado');

        // Retirado o anúncio, ele volta a rodar.
        app(MercadoDeUsados::class)->cancelar(
            $user->colony,
            VehicleListing::where('vehicle_id', $v->id)->first(),
        );

        $this->actingAs($user)->postJson('/vehicles/'.$v->id.'/dispatch', [
            'destination_type' => 'mercado_central',
            'cargo' => ['metal_bruto' => 1_000],
        ])->assertCreated();
    }

    public function test_sucatear_cancela_o_anuncio_aberto(): void
    {
        $user = $this->comCentral(3);
        $v = $this->furgao($user->colony);

        $anuncio = app(MercadoDeUsados::class)->anunciar($user->colony, $v, 50 * Colony::MICRO_POR_FERT);

        app(Sucatear::class)->handle($user->colony, $v);

        // Ninguém pode comprar um veículo que virou pó.
        $this->assertSame('cancelado', $anuncio->fresh()->status);
    }

    public function test_o_anuncio_mostra_a_conservacao_ao_comprador(): void
    {
        // §16.4: "o estado de conservação é visível no registro e afeta diretamente o preço de venda
        // no mercado de usados". Sem isso, compra-se às cegas.
        $vendedor = $this->comCentral(3);
        $comprador = $this->comCentral(3);

        $v = $this->desgastar($this->furgao($vendedor->colony), 62);
        app(MercadoDeUsados::class)->anunciar($vendedor->colony, $v, 50 * Colony::MICRO_POR_FERT);

        $this->actingAs($comprador)->getJson('/transport/listings')
            ->assertOk()
            ->assertJsonPath('anuncios.0.veiculo.conservacao', 62)
            ->assertJsonPath('anuncios.0.meu', false)
            ->assertJsonPath('anuncios.0.preco_fert', 50);
    }

    public function test_o_resumo_publico_conta_o_planeta(): void
    {
        $user = $this->comCentral(3);
        $outro = $this->colono();

        app(Sucatear::class)->handle($outro->colony, $this->furgao($outro->colony));

        $this->actingAs($user)->getJson('/transport')
            ->assertOk()
            ->assertJsonPath('planeta.veiculos_registrados', 1)
            ->assertJsonPath('planeta.sucateados', 1);
    }
}
