<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Models\User;
use Database\Seeders\BuildingOperatorRequirementSeeder;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A população chega à tela (A2.V2 — docs/decisoes.md D-210).
 *
 * ## ⚠️ O que estes testes existem para impedir
 *
 * `Populacao::estado()` existia desde o D-176, foi ligada em produção no D-178, e **nunca teve
 * consumidor**: nenhuma rota a publicava e nenhuma tela a mostrava. A mecânica governava o teto
 * habitacional, os operadores e o consumo de 29 colônias — 28 delas no teto — sem que o jogador
 * pudesse ver um único número.
 *
 * É o mesmo defeito que esta Alpha encontrou sete vezes (`vehicles.level` sem rota,
 * `population_settings.ativo` sem leitor, `EfeitosDaPesquisa` sem consumidor). O que o impede de
 * voltar não é lembrar: é uma asserção sobre o **contrato da rota**.
 */
class PopulacaoNoPayloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
        /*
         * ⚠️ Sem este seeder toda construção exige ZERO operadores, e `em_construcoes` vem 0 — o
         * que faria um teste sobre alocação passar afirmando coisa nenhuma. É a mesma tabela que já
         * ficou de fora do `DatabaseSeeder` uma vez (D-184) e só apareceu no e2e.
         */
        $this->seed(BuildingOperatorRequirementSeeder::class);
    }

    private function colono(): User
    {
        $user = User::factory()->create();
        app(CreateColony::class)->handle($user, 'Teste', 12, 12);

        return $user->fresh();
    }

    private function ligar(bool $ativo = true): void
    {
        DB::table('population_settings')->update(['ativo' => $ativo]);
    }

    public function test_a_colonia_publica_a_populacao_no_payload(): void
    {
        $this->ligar();
        $user = $this->colono();

        $this->actingAs($user)->getJson('/colony')
            ->assertOk()
            ->assertJsonPath('populacao.ativo', true)
            ->assertJsonStructure(['populacao' => [
                'ativo', 'total', 'capacidade', 'em_construcoes', 'em_zonas', 'disponivel',
                'deficit', 'estrutura_nivel', 'estrutura_nivel_para_o_que_ja_tem',
            ]]);
    }

    // ───────────────── a dívida de operadores, dita como dívida (A2.V4, D-225)

    /**
     * ⚠️ O defeito em um teste: `disponivel` negativo virava "−10 livre(s)" na tela.
     *
     * Não se lê — não existe menos dez pessoas. O que existe é uma colônia devendo operadores ao que
     * já tem erguido, e `deficit` é esse número dito como dívida. Medido em produção: um dos dois
     * líderes humanos estava exatamente assim (28 colonos, 38 exigidos).
     */
    public function test_devendo_operadores_o_payload_traz_deficit_positivo(): void
    {
        $this->ligar();
        $user = $this->colono();
        $colony = $user->colony;

        // Menos gente do que o que já está de pé exige.
        $exigidos = app(\App\Domain\Populacao\Populacao::class)->alocadaEmConstrucoes($colony);
        $colony->forceFill(['populacao' => max(0, $exigidos - 3)])->save();

        $p = $this->actingAs($user)->getJson('/colony')->assertOk()->json('populacao');

        $this->assertSame(-3, $p['disponivel'], 'o disponível continua cru, para quem sabe lê-lo');
        $this->assertSame(3, $p['deficit'], 'e o déficit é o mesmo fato, legível');
    }

    /**
     * Sobrando gente, o déficit é zero — não é o negativo do disponível.
     *
     * ⚠️ A população é posta à mão: uma colônia recém-fundada nasce **devendo** operadores (as cinco
     * essenciais já exigem equipe e ela ainda não povoou), e o teste passaria por acaso pelo lado
     * errado da conta.
     */
    public function test_com_gente_sobrando_o_deficit_e_zero(): void
    {
        $this->ligar();
        $user = $this->colono();
        $colony = $user->colony;

        $exigidos = app(\App\Domain\Populacao\Populacao::class)->alocadaEmConstrucoes($colony);
        $colony->forceFill(['populacao' => $exigidos + 5])->save();

        $p = $this->actingAs($user)->getJson('/colony')->assertOk()->json('populacao');

        $this->assertSame(5, $p['disponivel']);
        $this->assertSame(0, $p['deficit']);
    }

    /**
     * A DOSE, e não só o remédio: até que nível a Estrutura precisa subir.
     *
     * "Suba a Estrutura de Sobrevivência" é conselho incompleto quando falta mais de um nível — o
     * jogador pagaria a obra e continuaria travado. O alvo é o menor nível cujo teto abriga o que já
     * está de pé.
     */
    public function test_o_alvo_da_estrutura_e_o_menor_nivel_que_abriga(): void
    {
        $this->ligar();
        $populacao = app(\App\Domain\Populacao\Populacao::class);
        $parametros = app(\App\Domain\Populacao\Parametros::class);

        $alvo = $populacao->nivelQueAbriga($parametros->capacidade(3));

        $this->assertSame(3, $alvo, 'exatamente o teto do nível 3 tem de caber no nível 3');
        $this->assertSame(3, $populacao->nivelQueAbriga($parametros->capacidade(2) + 1));
    }

    /**
     * ⚠️ COLÔNIA NOVA NASCE POVOADA — e este é o teste que impede a colônia estéril de voltar.
     *
     * `colonies.populacao` tem `default(0)` e o `CreateColony` nunca a escrevia. O crescimento do
     * `Ciclo` é multiplicativo (`total × taxa`) e devolve `parado` quando `total <= 0`: **de zero a
     * população nunca sai**. Uma colônia fundada ficaria para sempre com 0 colonos — sem ocupar
     * zona, sem alocar operador, sem erguer o que exige equipe.
     *
     * A produção não sofreu por acaso: o grandfathering preencheu as 29 existentes e ninguém fundou
     * desde então. O próximo a fundar é que pagaria — que é o pior tipo de defeito, o que só morde
     * quem chega depois.
     */
    public function test_colonia_recem_fundada_nasce_com_gente_para_operar_o_que_recebeu(): void
    {
        $this->ligar();
        $colony = $this->colono()->colony;

        $populacao = app(\App\Domain\Populacao\Populacao::class);
        $exigidos = $populacao->alocadaEmConstrucoes($colony);

        $this->assertGreaterThan(0, $colony->populacao, 'população zero nunca cresce: o Ciclo para em total <= 0');
        $this->assertGreaterThanOrEqual($exigidos, $colony->populacao, 'tem de operar o que recebeu ao nascer');
        $this->assertLessThanOrEqual(
            $populacao->capacidade($colony),
            $colony->populacao,
            'e não pode nascer acima do próprio teto habitacional',
        );
    }

    /**
     * ⚠️ `null` quando nem o nível máximo resolve — e isso é estado possível, não erro.
     *
     * Uma colônia grandfatherada (D-178) pode ter erguido mais do que a habitação do jogo comporta.
     * Devolver o nível máximo mentiria dizendo que subir resolve, e mandaria o jogador gastar à toa.
     */
    public function test_quando_nem_o_maximo_abriga_o_alvo_e_nulo(): void
    {
        $this->ligar();

        $this->assertNull(app(\App\Domain\Populacao\Populacao::class)->nivelQueAbriga(999_999));
    }

    /**
     * ⚠️ Com a chave-mestra desligada o campo **continua existindo**, dizendo `ativo: false`.
     *
     * Sumir com ele faria a tela quebrar ao ler `colonia.populacao.total`; devolver zeros sem o
     * `ativo` faria a tela mostrar "0 colonos", que um jogador lê como colônia morta. A distinção
     * entre *"não há gente"* e *"esta regra não vale aqui"* é do servidor, não da tela.
     */
    public function test_com_a_chave_desligada_o_campo_existe_e_diz_que_esta_desligado(): void
    {
        $this->ligar(false);
        $user = $this->colono();

        $this->actingAs($user)->getJson('/colony')
            ->assertOk()
            ->assertJsonPath('populacao.ativo', false);
    }

    /**
     * O número que a tela destaca é o **disponível**, e ele desconta quem já está alocado.
     *
     * Total é curiosidade; disponível é o que decide se dá para ocupar uma zona nova. Se um dia
     * `estado()` passar a devolver o total no lugar do livre, é aqui que reprova.
     */
    public function test_o_disponivel_desconta_quem_ja_esta_alocado(): void
    {
        $this->ligar();
        $user = $this->colono();
        $colony = $user->colony;

        $colony->update(['populacao' => 40]);

        $payload = $this->actingAs($user)->getJson('/colony')->assertOk()->json('populacao');

        $this->assertSame(40, $payload['total']);
        $this->assertGreaterThan(
            0,
            $payload['em_construcoes'],
            'a colônia nasce com construções, e elas exigem operadores',
        );
        $this->assertSame(
            $payload['total'] - $payload['em_construcoes'] - $payload['em_zonas'],
            $payload['disponivel'],
            'disponível é o que sobra depois de descontar o alocado',
        );
    }
}
