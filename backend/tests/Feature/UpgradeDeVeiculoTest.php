<?php

namespace Tests\Feature;

use App\Domain\Logistics\VeiculoSpecs;
use App\Domain\Transport\Manutencao;
use App\Domain\Transport\UpgradeVeiculo;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\TransportSetting;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Upgrade de veículo (A2.7).
 *
 * `vehicles.level` existia no banco desde sempre e **nunca teve caminho para subir**. O que estes
 * testes guardam não são os números — todos HIPÓTESE, esperando a rodada do simulador que o item 6
 * do trabalho pede — e sim as **decisões de desenho**: um eixo com contrapartida, e velocidade
 * fora dele.
 */
class UpgradeDeVeiculoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
    }

    private int $proximo = 0;

    private function colonia(bool $rica = true): Colony
    {
        $u = User::create([
            'name' => 'c', 'nickname' => 'v'.$this->proximo,
            'email' => 'v'.$this->proximo.'@t.test', 'password' => Hash::make('x'),
        ]);

        $c = Colony::create(['user_id' => $u->id, 'name' => 'C', 'x' => 0, 'y' => $this->proximo++]);

        if ($rica) {
            foreach (['metal_bruto', 'ligas_metalicas', 'componentes_eletronicos', 'energia'] as $r) {
                $c->resources()->create(['resource_type' => $r, 'amount' => 999999]);
            }
        }

        return $c->fresh();
    }

    private function veiculo(Colony $c, int $nivel = 1, string $status = 'ocioso'): Vehicle
    {
        return $c->vehicles()->create([
            'type' => 'furgao_de_comercio', 'level' => $nivel, 'status' => $status,
            'capacity' => VeiculoSpecs::CAPACIDADE['furgao_de_comercio'],
        ]);
    }

    // ─────────────────────────────────── a rota que faltava

    public function test_o_nivel_sobe_e_a_capacidade_junto(): void
    {
        $c = $this->colonia();
        $v = $this->veiculo($c);
        $antes = (int) $v->capacity;

        $depois = app(UpgradeVeiculo::class)->handle($c, $v);

        $this->assertSame(2, (int) $depois->level);
        $this->assertGreaterThan($antes, (int) $depois->capacity);
    }

    /**
     * ⚠️ A decisão de desenho mais importante da fase.
     *
     * Velocidade é **traço do tipo de veículo** — é o que diferencia Furgão de Caminhão. Se o nível
     * também acelerasse, a **distância** encolheria a cada upgrade, e distância é pilar declarado do
     * jogo ("logística sem teleporte").
     *
     * Este teste existe porque é o tipo de coisa que alguém acrescenta depois achando que melhora.
     */
    public function test_o_upgrade_nao_toca_na_velocidade(): void
    {
        $c = $this->colonia();
        $v = $this->veiculo($c);

        /*
         * A velocidade se observa pelo TEMPO DO TRECHO, que é como o jogo a usa. Se o nível não a
         * toca, dez slots levam exatamente o mesmo tempo antes e depois do upgrade.
         */
        $antes = VeiculoSpecs::segundosDoTrecho($v->type, 10);
        app(UpgradeVeiculo::class)->handle($c, $v);

        $this->assertSame(
            $antes,
            VeiculoSpecs::segundosDoTrecho($v->fresh()->type, 10),
            'velocidade é traço do TIPO; nível não pode acelerar veículo nenhum',
        );
    }

    /**
     * A contrapartida: manutenção sobe junto, e sobe MAIS que a capacidade.
     *
     * Sem isso, subir nível seria decisão sem escolha — e "escolha econômica mensurável" é o
     * critério de saída da fase. Um veículo grande parado tem de custar caro.
     */
    public function test_a_manutencao_sobe_com_o_nivel(): void
    {
        $c = $this->colonia();
        $baixo = $this->veiculo($c, 1);
        $alto = $this->veiculo($c, 3);

        $custoBaixo = array_sum(app(Manutencao::class)->custo($baixo));
        $custoAlto = array_sum(app(Manutencao::class)->custo($alto));

        $this->assertGreaterThan($custoBaixo, $custoAlto);
    }

    public function test_a_manutencao_sobe_mais_que_a_capacidade(): void
    {
        $config = TransportSetting::singleton();

        $this->assertGreaterThan(
            (int) $config->upgrade_capacidade_bps_por_nivel,
            (int) $config->upgrade_manutencao_bps_por_nivel,
            'a contrapartida tem que pesar mais que o ganho, senão não é escolha',
        );
    }

    // ─────────────────────────────────── as portas

    public function test_veiculo_em_rota_nao_pode_ser_melhorado(): void
    {
        $c = $this->colonia();
        $v = $this->veiculo($c, 1, 'em_rota');

        $this->expectException(DomainRuleException::class);
        app(UpgradeVeiculo::class)->handle($c, $v);
    }

    public function test_veiculo_de_outra_colonia_e_recusado(): void
    {
        $dono = $this->colonia();
        $outro = $this->colonia();

        $this->expectException(DomainRuleException::class);
        app(UpgradeVeiculo::class)->handle($outro, $this->veiculo($dono));
    }

    public function test_o_teto_de_nivel_trava(): void
    {
        $c = $this->colonia();
        $teto = (int) TransportSetting::singleton()->upgrade_nivel_maximo;

        $this->expectException(DomainRuleException::class);
        app(UpgradeVeiculo::class)->handle($c, $this->veiculo($c, $teto));
    }

    public function test_sem_recurso_o_upgrade_nao_acontece(): void
    {
        $c = $this->colonia(rica: false);

        $this->expectException(DomainRuleException::class);
        app(UpgradeVeiculo::class)->handle($c, $this->veiculo($c));
    }

    // ─────────────────────────────────── o custo

    public function test_o_custo_vai_ao_ledger_como_saida(): void
    {
        $c = $this->colonia();
        app(UpgradeVeiculo::class)->handle($c, $this->veiculo($c));

        $lancamentos = Ledger::where('colony_id', $c->id)->where('type', 'upgrade_veiculo')->get();

        $this->assertNotEmpty($lancamentos);
        // Negativo, como todo custo escreve — foi a lição do D-164.
        $this->assertTrue($lancamentos->every(fn ($l) => $l->amount < 0));
    }

    /** Subir para o 3 custa mais que subir para o 2: sem isso, o último nível sai pelo preço do primeiro. */
    public function test_o_custo_cresce_com_o_nivel_alvo(): void
    {
        $servico = app(UpgradeVeiculo::class);

        $paraDois = array_sum($servico->custo('furgao_de_comercio', 2));
        $paraTres = array_sum($servico->custo('furgao_de_comercio', 3));

        $this->assertGreaterThan($paraDois, $paraTres);
    }

    /**
     * A capacidade é REESCRITA a partir da base do tipo, não incrementada sobre a atual.
     *
     * Incrementar acumularia erro de arredondamento a cada nível e — pior — ficaria errado para
     * sempre se alguém ajustasse o parâmetro depois: a coluna guardaria o resultado de uma curva
     * que não existe mais.
     */
    public function test_a_capacidade_e_derivada_da_base_e_do_nivel(): void
    {
        $servico = app(UpgradeVeiculo::class);
        $base = VeiculoSpecs::CAPACIDADE['furgao_de_comercio'];

        $this->assertSame($base, $servico->capacidade('furgao_de_comercio', 1));
        $this->assertGreaterThan($base, $servico->capacidade('furgao_de_comercio', 2));
    }

    public function test_a_api_exige_autenticacao(): void
    {
        $c = $this->colonia();

        $this->postJson('/transport/vehicles/'.$this->veiculo($c)->id.'/upgrade')->assertStatus(401);
    }

    // ─────────────────────────────────── e a rota tem de estar ALCANÇÁVEL

    /**
     * ⚠️ Este teste existe porque a rota foi publicada **sem tela nenhuma** por um dia inteiro.
     *
     * Uma rota que a interface não chama é uma peça inerte: o `vehicles.level` já tinha passado anos
     * assim, e a fase existe justamente para desfazer isso. Guardar a listagem — e não só o POST —
     * é o que impede a fase de fechar com o mesmo defeito que veio consertar.
     */
    public function test_a_listagem_traz_o_que_a_tela_precisa_para_oferecer_o_upgrade(): void
    {
        $c = $this->colonia();
        $v = $this->veiculo($c);

        $corpo = $this->actingAs($c->user)->getJson('/transport')->assertOk()->json();
        $registro = collect($corpo['veiculos'])->firstWhere('id', $v->id);

        $this->assertSame(1, $registro['nivel']);
        $this->assertTrue($registro['upgrade']['pode']);
        $this->assertSame(2, $registro['upgrade']['proximo_nivel']);
        $this->assertNotEmpty($registro['upgrade']['custo']);
    }

    /**
     * Os DOIS lados na mesma resposta — o critério de saída da fase.
     *
     * *"Escolha econômica mensurável, e não apenas aumento nominal de nível."* Se um dia sobrar só o
     * ganho de capacidade, o upgrade vira botão óbvio e a fase perde o que a justificava. Por isso o
     * teste não checa apenas presença: exige que a manutenção suba de verdade.
     */
    public function test_a_listagem_mostra_o_custo_do_upgrade_junto_com_o_ganho(): void
    {
        $c = $this->colonia();
        $v = $this->veiculo($c);

        $u = $this->actingAs($c->user)->getJson('/transport')->json();
        $u = collect($u['veiculos'])->firstWhere('id', $v->id)['upgrade'];

        $this->assertGreaterThan($u['capacidade_agora'], $u['capacidade_depois'], 'o ganho');
        $this->assertGreaterThan($u['manutencao_agora'], $u['manutencao_depois'], 'a contrapartida');
    }

    /** No teto, a tela não pode oferecer o que a regra recusaria. */
    public function test_no_nivel_maximo_a_listagem_nao_oferece_upgrade(): void
    {
        $c = $this->colonia();
        $v = $this->veiculo($c, (int) TransportSetting::singleton()->upgrade_nivel_maximo);

        $u = $this->actingAs($c->user)->getJson('/transport')->json();
        $u = collect($u['veiculos'])->firstWhere('id', $v->id)['upgrade'];

        $this->assertTrue($u['no_maximo']);
        $this->assertFalse($u['pode']);
        $this->assertNull($u['proximo_nivel']);
        $this->assertNull($u['custo']);
    }
}
