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
            ]]);
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
