<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Treasury\Tesouro;
use App\Models\Admin;
use App\Models\Colony;
use App\Models\MarketOrder;
use App\Models\User;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Database\Seeders\TreasurySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Economia ganhou três abas (D-96): Ofertas Globais (o livro do Mercado Central inteiro), Extrato
 * do Governo (o `treasury_ledger` novo — até aqui o Tesouro só guardava saldo, não histórico) e
 * Extrato Colonos (o `ledger` de todas as colônias, junto).
 */
class AdminEconomiaExtratosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
        $this->seed(ComponentRecipeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
        $this->seed(TreasurySeeder::class);
    }

    private function operador(): Admin
    {
        return Admin::create([
            'name' => 'Op', 'email' => 'op@fertways.test',
            'password' => Hash::make('segredo-forte-1234'), 'role' => Admin::OPERADOR,
        ]);
    }

    private function colonia(string $nick, int $x = 10, int $y = 10): Colony
    {
        $user = User::factory()->create(['email' => "{$nick}@t.test", 'nickname' => $nick]);

        return app(CreateColony::class)->handle($user, "Colônia {$nick}", $x, $y)->fresh();
    }

    public function test_ofertas_globais_lista_ordens_de_colonos_e_do_governo(): void
    {
        $colonia = $this->colonia('vendedora');

        MarketOrder::create([
            'colony_id' => $colonia->id, 'resource_type' => 'metal_bruto', 'side' => 'sell',
            'price_micro' => 1_000_000, 'qty' => 50, 'status' => 'aberta',
        ]);
        MarketOrder::create([
            'colony_id' => null, 'resource_type' => 'agua', 'side' => 'sell',
            'price_micro' => 500_000, 'qty' => 200, 'status' => 'aberta',
        ]);

        $resp = $this->actingAs($this->operador(), 'admin')
            ->get('/admin/economia?aba=ofertas_globais')
            ->assertOk()
            ->assertSee('Ofertas Globais')
            ->assertSee('Colônia vendedora')
            ->assertSee('Governo');

        $resp->assertSee('metal_bruto')->assertSee('agua');
    }

    public function test_ofertas_globais_filtra_por_lado_e_status(): void
    {
        $colonia = $this->colonia('compradora');

        $excluida = MarketOrder::create([
            'colony_id' => $colonia->id, 'resource_type' => 'metal_bruto', 'side' => 'buy',
            'price_micro' => 1_000_000, 'qty' => 10, 'status' => 'cancelada',
        ]);
        $incluida = MarketOrder::create([
            'colony_id' => $colonia->id, 'resource_type' => 'agua', 'side' => 'sell',
            'price_micro' => 1_000_000, 'qty' => 10, 'status' => 'aberta',
        ]);

        $this->actingAs($this->operador(), 'admin')
            ->get('/admin/economia?aba=ofertas_globais&side=sell&status=aberta')
            ->assertOk()
            ->assertSee("data-oferta=\"{$incluida->id}\"", false)
            ->assertDontSee("data-oferta=\"{$excluida->id}\"", false);
    }

    public function test_extrato_do_governo_mostra_o_credito_do_tributo(): void
    {
        app(Tesouro::class)->creditarFert(3_000_000, 'tributo_mercado:teste');

        $this->actingAs($this->operador(), 'admin')
            ->get('/admin/economia?aba=extrato_governo')
            ->assertOk()
            ->assertSee('Extrato do Governo')
            ->assertSee('tributo_mercado:teste')
            ->assertSee('3.000.000');
    }

    public function test_extrato_do_governo_filtra_por_tipo(): void
    {
        app(Tesouro::class)->creditarFert(1_000_000, 'credito:um');
        app(Tesouro::class)->creditarRecurso('metal_bruto', 5, 'credito:dois');
        $col = $this->colonia('alvo');
        app(Tesouro::class)->distribuir($col, Tesouro::FERT, 500_000);

        $this->actingAs($this->operador(), 'admin')
            ->get('/admin/economia?aba=extrato_governo&tipo=distribuicao')
            ->assertOk()
            ->assertDontSee('credito:um')
            ->assertSee('tesouro:dist:');
    }

    public function test_extrato_colonos_mostra_o_saldo_inicial_da_fundacao(): void
    {
        $colonia = $this->colonia('fundadora');

        $this->actingAs($this->operador(), 'admin')
            ->get('/admin/economia?aba=extrato_colonos')
            ->assertOk()
            ->assertSee('Extrato Colonos')
            ->assertSee('Colônia fundadora')
            ->assertSee('saldo_inicial');
    }

    public function test_extrato_colonos_busca_por_nome_da_colonia(): void
    {
        $this->colonia('primeira', 10, 10);
        $this->colonia('segunda', 12, 12);

        $this->actingAs($this->operador(), 'admin')
            ->get('/admin/economia?aba=extrato_colonos&q=Primeira')
            ->assertOk()
            ->assertSee('Colônia primeira')
            ->assertDontSee('Colônia segunda');
    }
}
