<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Models\User;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /estatisticas` — números reais para a landing page (pedido do usuário, 2026-07-17). Sem
 * autenticação, como `/register` e `/login`: é a porta de entrada, ninguém tem conta ainda.
 */
class EstatisticasPublicasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
        $this->seed(ComponentRecipeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
    }

    public function test_nao_exige_autenticacao(): void
    {
        $this->getJson('/estatisticas')->assertOk();
    }

    public function test_conta_colonias_e_colonos_de_verdade(): void
    {
        $user = User::factory()->create();
        app(CreateColony::class)->handle($user, 'Base', 10, 10);

        $resp = $this->getJson('/estatisticas')->assertOk();

        $this->assertSame(1, $resp->json('colonos'));
        $this->assertSame(1, $resp->json('colonias'));
        $this->assertGreaterThan(0, $resp->json('construcoes_erguidas'));
        $this->assertGreaterThan(0, $resp->json('veiculos_registrados'), 'o kit inicial já dá um Furgão');
        $this->assertGreaterThan(0, $resp->json('lancamentos_no_ledger'), 'o saldo inicial já é um lançamento');
        $this->assertGreaterThan(0, $resp->json('fert_em_circulacao_micro'));
    }

    public function test_a_conta_de_sistema_capital_nao_conta_como_colono(): void
    {
        // A migration do D-91 já semeia a conta "Capital" em todo banco — nem precisa criá-la.
        $this->assertDatabaseHas('users', ['email' => \App\Domain\Chat\ContaSistema::EMAIL_CAPITAL]);

        $resp = $this->getJson('/estatisticas')->assertOk();

        $this->assertSame(0, $resp->json('colonos'));
    }
}
