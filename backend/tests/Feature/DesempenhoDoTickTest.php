<?php

namespace Tests\Feature;

use App\Domain\Capital\Patio;
use App\Domain\Colony\CreateColony;
use App\Domain\Production\ColonyTick;
use App\Models\User;
use App\Models\Vehicle;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * O tick medido em escala (D-286). Um revisor rodou o `fertways:tick` com 50, 200 e 1000 colônias,
 * contando consultas. Estes testes travam as releituras que ele achou: a mesma resposta pedida ao
 * banco uma vez por colônia, ou por recurso, a cada minuto.
 */
class DesempenhoDoTickTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
        $this->seed(ComponentRecipeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
    }

    /** @return list<string> */
    private function consultasDurante(callable $fn): array
    {
        $sql = [];
        DB::listen(function ($q) use (&$sql) {
            $sql[] = $q->sql;
        });
        $fn();

        return $sql;
    }

    private function quantasCitam(array $sql, string $tabela): int
    {
        return count(array_filter($sql, fn ($s) => str_contains($s, "\"{$tabela}\"") || str_contains($s, "`{$tabela}`")));
    }

    public function test_os_eventos_do_mundo_sao_lidos_uma_vez_e_nao_por_recurso_de_cada_colonia(): void
    {
        $a = app(CreateColony::class)->handle(User::factory()->create(), 'A', 20, 20);
        $b = app(CreateColony::class)->handle(User::factory()->create(), 'B', 40, 40);
        $tick = app(ColonyTick::class);
        $agora = now()->addMinutes(5);

        $sql = $this->consultasDurante(function () use ($tick, $a, $b, $agora) {
            $tick->handle($a->fresh(), $agora);
            $tick->handle($b->fresh(), $agora);
        });

        $this->assertLessThanOrEqual(1, $this->quantasCitam($sql, 'game_events'), 'sem evento no mundo, uma consulta basta');
    }

    public function test_as_tabelas_de_configuracao_nao_sao_relidas_a_cada_colonia(): void
    {
        $a = app(CreateColony::class)->handle(User::factory()->create(), 'A', 20, 20);
        $b = app(CreateColony::class)->handle(User::factory()->create(), 'B', 40, 40);
        $tick = app(ColonyTick::class);
        $agora = now()->addMinutes(5);

        $sql = $this->consultasDurante(function () use ($tick, $a, $b, $agora) {
            $tick->handle($a->fresh(), $agora);
            $tick->handle($b->fresh(), $agora);
        });

        $this->assertLessThanOrEqual(1, $this->quantasCitam($sql, 'building_specs'));
        $this->assertLessThanOrEqual(1, $this->quantasCitam($sql, 'manutencao_estruturas'));
    }

    public function test_um_evento_criado_continua_valendo_no_tick_seguinte(): void
    {
        $c = app(CreateColony::class)->handle(User::factory()->create(), 'A', 20, 20);
        $agora = now()->addMinutes(5);
        app(ColonyTick::class)->handle($c->fresh(), $agora);

        // Um tick novo é uma instância nova (o comando resolve a sua): nenhuma memória atravessa.
        DB::table('game_events')->insert([
            'slug' => 'x', 'nome' => 'x', 'modificador' => 'producao', 'efeito_bps' => 20_000, 'status' => 'ativo',
            'comeca_em' => $agora, 'termina_em' => $agora->copy()->addDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $sql = $this->consultasDurante(fn () => app(ColonyTick::class)->handle($c->fresh(), $agora->copy()->addMinutes(5)));

        $this->assertGreaterThan(1, $this->quantasCitam($sql, 'game_events'), 'com evento vigente, ele é consultado de verdade');
    }

    public function test_o_patio_nao_abre_transacao_para_quem_nao_deve_hora(): void
    {
        $c = app(CreateColony::class)->handle(User::factory()->create(), 'A', 20, 20);
        $v = $c->vehicles()->first();
        $v->forceFill(['local' => Vehicle::NO_PATIO, 'status' => 'ocioso', 'parked_at' => now()->subMinutes(10), 'patio_cobrado_ate' => now()->subMinutes(10)])->save();

        $sql = $this->consultasDurante(fn () => app(Patio::class)->handle(now()));

        $this->assertCount(1, $sql, 'só a varredura: estacionado há 10 minutos não deve hora nem lembrete');
    }

    public function test_os_filtros_do_tick_tem_indice(): void
    {
        $this->assertTrue(Schema::hasIndex('units', ['status', 'arrives_at']), 'ConcluirFabricacaoDeUnidades e ChegarReforcos');
        $this->assertTrue(Schema::hasIndex('chat_messages', ['channel', 'created_at']), 'a purga do chat, a cada minuto');
    }
}
