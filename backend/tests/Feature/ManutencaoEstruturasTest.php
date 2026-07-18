<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Production\ColonyTick;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Manutenção de estruturas (D-112): consumo extra de recursos por hora, por construção,
 * aditivo sobre `energia_consumo_hora` do GDD — nunca no lugar dela.
 */
class ManutencaoEstruturasTest extends TestCase
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
        $colony = app(CreateColony::class)->handle($user, 'Nova Aurora', 10 + $this->proximoSlot++, 20);
        $colony->resources()->update(['amount' => 0]);

        return $user->fresh();
    }

    private function erguer(User $user, string $tipo, int $nivel): void
    {
        $this->erguerPredio($user->colony, $tipo, $nivel);
    }

    private function estoque(User $user, string $recurso): int
    {
        return $user->colony->resources()->where('resource_type', $recurso)->value('amount');
    }

    private function tick(User $user, $agora): void
    {
        app(ColonyTick::class)->handle($user->colony()->first(), $agora);
    }

    public function test_sem_configuracao_o_consumo_de_sempre_continua_igual(): void
    {
        $user = $this->colono();

        $colony = $user->colony;
        $colony->update(['last_tick_at' => now()->subHour()]);

        $this->tick($user, now());

        // Mesmos números do §19.8 — manutencao_estruturas vazia não muda nada.
        $this->assertSame(100, $this->estoque($user, 'oxigenio'));
        $this->assertSame(88, $this->estoque($user, 'energia'));
    }

    public function test_consumo_extra_configurado_reduz_o_estoque_alem_da_producao(): void
    {
        $user = $this->colono();

        DB::table('manutencao_estruturas')->insert([
            'building_type' => 'gerador_de_atmosfera',
            'resource_type' => 'oxigenio',
            'qtd_hora' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user->colony->update(['last_tick_at' => now()->subHour()]);
        $this->tick($user, now());

        // O Gerador produz 100/h (§19.2); a manutenção configurada tira 10/h a mais — 90.
        $this->assertSame(90, $this->estoque($user, 'oxigenio'));
    }

    public function test_consumo_extra_e_aditivo_sobre_a_energia_do_gdd_nao_a_substitui(): void
    {
        $user = $this->colono();

        DB::table('manutencao_estruturas')->insert([
            'building_type' => 'laboratorio',
            'resource_type' => 'energia',
            'qtd_hora' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->erguer($user, 'laboratorio', 1);   // -20/h de energia_consumo_hora, do GDD

        $user->colony->update(['last_tick_at' => now()->subHour()]);
        $this->tick($user, now());

        // 88 (§19.8) menos os 20/h do Laboratório (não é essencial, entra à parte) menos os 5/h
        // extras da manutenção configurada = 63.
        $this->assertSame(63, $this->estoque($user, 'energia'));
    }

    public function test_consumo_extra_de_um_tipo_nao_configurado_nao_afeta_outro_tipo(): void
    {
        $user = $this->colono();

        DB::table('manutencao_estruturas')->insert([
            'building_type' => 'laboratorio',
            'resource_type' => 'energia',
            'qtd_hora' => 999,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Sem Laboratório erguido — a linha de manutencao_estruturas não se aplica a nada.
        $user->colony->update(['last_tick_at' => now()->subHour()]);
        $this->tick($user, now());

        $this->assertSame(88, $this->estoque($user, 'energia'));
    }

    public function test_consumo_extra_nunca_deixa_o_estoque_negativo(): void
    {
        $user = $this->colono();

        DB::table('manutencao_estruturas')->insert([
            'building_type' => 'gerador_de_atmosfera',
            'resource_type' => 'oxigenio',
            'qtd_hora' => 100_000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user->colony->update(['last_tick_at' => now()->subHour()]);
        $this->tick($user, now());

        $this->assertSame(0, $this->estoque($user, 'oxigenio'));
    }

    public function test_duas_construcoes_do_mesmo_tipo_somam_o_consumo_extra(): void
    {
        $user = $this->colono();

        DB::table('manutencao_estruturas')->insert([
            'building_type' => 'mina_local',
            'resource_type' => 'metal_bruto',
            'qtd_hora' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Mina Local não é uma das cinco essenciais (D-59): a colônia nasce sem nenhuma erguida.
        $this->erguer($user, 'mina_local', 1);
        $this->erguer($user, 'mina_local', 1);   // repetível — cria uma segunda, não promove a primeira

        $user->colony->update(['last_tick_at' => now()->subHour()]);
        $this->tick($user, now());

        // Duas Minas nível 1: 15+15 = 30/h produzidos, menos 3+3 = 6/h de manutenção configurada.
        $this->assertSame(24, $this->estoque($user, 'metal_bruto'));
    }
}
