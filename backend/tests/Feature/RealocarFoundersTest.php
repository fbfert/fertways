<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Logistics\MapaFertways;
use App\Models\Colony;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O comando de realocação do D-51 (Fatia 2): leva as colônias existentes para slots de founder,
 * mas só com os veículos no pátio, e sem colidir no `unique(x,y)` durante o remanejamento.
 */
class RealocarFoundersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\ComponentRecipeSeeder::class);
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
    }

    private function colonia(string $nick, int $x, int $y): Colony
    {
        $user = User::factory()->create(['email' => "{$nick}@t.test", 'nickname' => $nick]);

        return app(CreateColony::class)->handle($user, "Colônia {$nick}", $x, $y)->fresh();
    }

    public function test_realoca_as_colonias_para_os_primeiros_slots_populaveis(): void
    {
        // Duas colônias na periferia, fora de qualquer slot de founder.
        $a = $this->colonia('alfa', 20, 20);
        $b = $this->colonia('beta', 30, 30);

        $populaveis = collect(MapaFertways::slotsFounder())->reject(fn ($s) => $s['reservado'])->values();

        $this->artisan('fertways:realocar-founders --force')->assertSuccessful();

        // A mais antiga (menor id) fica com o primeiro slot populável; a seguinte, com o segundo.
        $a->refresh();
        $b->refresh();
        $this->assertSame([$populaveis[0]['x'], $populaveis[0]['y']], [$a->x, $a->y]);
        $this->assertSame([$populaveis[1]['x'], $populaveis[1]['y']], [$b->x, $b->y]);

        // E os alvos são mesmo slots de founder populáveis.
        $this->assertTrue(MapaFertways::ehFounderPopulavel($a->x, $a->y));
        $this->assertTrue(MapaFertways::ehFounderPopulavel($b->x, $b->y));
    }

    public function test_sem_force_apenas_simula(): void
    {
        $a = $this->colonia('alfa', 20, 20);

        $this->artisan('fertways:realocar-founders')->assertSuccessful();

        $a->refresh();
        $this->assertSame([20, 20], [$a->x, $a->y], 'a simulação não pode mover ninguém');
    }

    public function test_recusa_se_algum_veiculo_nao_esta_ocioso(): void
    {
        $a = $this->colonia('alfa', 20, 20);

        /*
         * O Furgão do kit inicial em rota: realocar agora quebraria a viagem (§25.5).
         *
         * Este teste dizia `'status' => 'ida'` — que nunca foi um estado válido de veículo ('ida' é
         * valor de `leg`, não de `status`). Passava porque o SQLite não fazia cumprir o enum. A
         * migration do D-60 reconstruiu a coluna e agora ele cumpre, como o MariaDB sempre cumpriu,
         * e a mentira apareceu. O estado que o teste sempre quis dizer é este.
         */
        $a->vehicles()->first()->forceFill(['status' => 'em_rota'])->save();

        $this->artisan('fertways:realocar-founders --force')->assertFailed();

        $a->refresh();
        $this->assertSame([20, 20], [$a->x, $a->y], 'nada pode ser movido com veículo em rota');
    }

    /**
     * O remanejamento não pode violar o `unique(x,y)` no meio. Vale mesmo quando o alvo de uma
     * colônia é a origem de outra — o caso que a passada dupla com células-tampão resolve.
     */
    public function test_nao_colide_quando_o_alvo_de_uma_e_a_origem_de_outra(): void
    {
        $populaveis = collect(MapaFertways::slotsFounder())->reject(fn ($s) => $s['reservado'])->values();

        // beta já ocupa o primeiro slot populável — que é o alvo de alfa (a mais antiga).
        $a = $this->colonia('alfa', 20, 20);
        $b = $this->colonia('beta', $populaveis[0]['x'], $populaveis[0]['y']);

        $this->artisan('fertways:realocar-founders --force')->assertSuccessful();

        $a->refresh();
        $b->refresh();
        $this->assertSame([$populaveis[0]['x'], $populaveis[0]['y']], [$a->x, $a->y]);
        $this->assertSame([$populaveis[1]['x'], $populaveis[1]['y']], [$b->x, $b->y]);
    }
}
