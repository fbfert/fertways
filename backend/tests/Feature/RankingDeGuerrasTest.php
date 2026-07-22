<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Guerra\RankingDeGuerras;
use App\Models\Colony;
use App\Models\Combat;
use App\Models\Ledger;
use App\Models\NeutralZone;
use App\Models\User;
use App\Models\ZoneEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ErgueEstruturasDaZona;
use Tests\TestCase;

/**
 * O Ranking de Guerras (GDD §27.13; docs/decisoes.md D-128) — normalização por percentil sobre
 * cinco sub-rankings, todos derivados do que o jogo já grava (`ZoneEvent`, `Combat`, `Ledger`),
 * sem tabela nova nenhuma.
 */
class RankingDeGuerrasTest extends TestCase
{
    use RefreshDatabase;
    use ErgueEstruturasDaZona;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
    }

    private int $proximaCelula = 0;

    private const CELULAS = [[20, 20], [-20, 20], [20, -20], [-20, -20], [25, 25], [-25, 25]];

    private function colono(string $nome): Colony
    {
        [$x, $y] = self::CELULAS[$this->proximaCelula++];

        return app(CreateColony::class)->handle(User::factory()->create(), $nome, $x, $y)->fresh();
    }

    private int $proximaZona = 0;

    private function zona(): NeutralZone
    {
        $cel = [[47, 47], [48, 48], [49, 49], [45, 46], [46, 47], [49, 48]][$this->proximaZona++];

        return $this->criarZonaComEstruturas([
            'x' => $cel[0], 'y' => $cel[1], 'district' => 'NE', 'mineral' => 'metal_bruto',
            'level' => 1, 'status' => 'livre', 'deposit_level' => 1,
        ]);
    }

    public function test_zonas_conquistadas_conta_os_eventos_do_tipo_conquistada(): void
    {
        $a = $this->colono('A');
        $b = $this->colono('B');
        $z1 = $this->zona();
        $z2 = $this->zona();

        ZoneEvent::create(['zone_id' => $z1->id, 'type' => 'conquistada', 'colony_id' => $a->id, 'created_at' => now()]);
        ZoneEvent::create(['zone_id' => $z2->id, 'type' => 'conquistada', 'colony_id' => $a->id, 'created_at' => now()]);
        ZoneEvent::create(['zone_id' => $z1->id, 'type' => 'ocupada', 'colony_id' => $b->id, 'created_at' => now()->subDay()]);

        $linhas = app(RankingDeGuerras::class)->geral()->keyBy('colony_id');

        $this->assertSame(2, $linhas[$a->id]['zonas_conquistadas']);
        // Ocupação de zona LIVRE não é conquista — não conta aqui.
        $this->assertSame(0, $linhas[$b->id]['zonas_conquistadas']);
    }

    public function test_vitorias_seguem_a_regra_do_combate_vencido_e_nao_uma_nova(): void
    {
        $a = $this->colono('A');
        $b = $this->colono('B');
        $c = $this->colono('C');
        $z = $this->zona();

        // A vence uma invasão contra B.
        Combat::create([
            'zone_id' => $z->id, 'attacker_colony_id' => $a->id, 'defender_colony_id' => $b->id,
            'tipo' => 'invasao', 'status' => 'vitoria_atacante', 'rodada' => 3, 'chega_at' => now(),
        ]);
        // B repele uma invasão de C — vitória do DEFENSOR.
        Combat::create([
            'zone_id' => $z->id, 'attacker_colony_id' => $c->id, 'defender_colony_id' => $b->id,
            'tipo' => 'invasao', 'status' => 'repelido', 'rodada' => 3, 'chega_at' => now(),
        ]);
        // C rompe um cerco contra A — vitória do sitiado (atacante da RUPTURA).
        Combat::create([
            'zone_id' => $z->id, 'attacker_colony_id' => $c->id, 'defender_colony_id' => $a->id,
            'tipo' => 'ruptura', 'status' => 'vitoria_atacante', 'rodada' => 1, 'chega_at' => now(),
        ]);
        // Cerco por prazo (rendido) e sabotagem NUNCA disparam combate_vencido — não contam.
        Combat::create([
            'zone_id' => $z->id, 'attacker_colony_id' => $a->id, 'defender_colony_id' => $b->id,
            'tipo' => 'cerco', 'status' => 'rendido', 'rodada' => 1, 'chega_at' => now(),
        ]);
        Combat::create([
            'zone_id' => $z->id, 'attacker_colony_id' => $a->id, 'defender_colony_id' => $b->id,
            'tipo' => 'sabotagem', 'status' => 'vitoria_atacante', 'rodada' => 1, 'chega_at' => now(),
        ]);

        $linhas = app(RankingDeGuerras::class)->geral()->keyBy('colony_id');

        $this->assertSame(1, $linhas[$a->id]['vitorias']);
        $this->assertSame(1, $linhas[$b->id]['vitorias']);
        $this->assertSame(1, $linhas[$c->id]['vitorias']);
    }

    public function test_sequencia_maxima_quebra_na_derrota(): void
    {
        $a = $this->colono('A');
        $b = $this->colono('B');
        $z = $this->zona();

        $rodada = function (string $tipo, string $status, Colony $atacante, Colony $defensor, int $minuto) use ($z) {
            $c = Combat::create([
                'zone_id' => $z->id, 'attacker_colony_id' => $atacante->id, 'defender_colony_id' => $defensor->id,
                'tipo' => $tipo, 'status' => $status, 'rodada' => 1, 'chega_at' => now(),
            ]);
            $c->forceFill(['updated_at' => now()->addMinutes($minuto)])->saveQuietly();
        };

        // A vence, vence, PERDE, vence — a maior sequência é 2, não 3.
        $rodada('invasao', 'vitoria_atacante', $a, $b, 1);
        $rodada('invasao', 'vitoria_atacante', $a, $b, 2);
        $rodada('invasao', 'repelido', $a, $b, 3);
        $rodada('invasao', 'vitoria_atacante', $a, $b, 4);

        $linhas = app(RankingDeGuerras::class)->geral()->keyBy('colony_id');

        $this->assertSame(3, $linhas[$a->id]['vitorias']);
        $this->assertSame(2, $linhas[$a->id]['sequencia']);
    }

    public function test_tempo_de_controle_soma_os_intervalos_de_posse(): void
    {
        $a = $this->colono('A');
        $b = $this->colono('B');
        $z = $this->zona();

        $t0 = now()->subHours(30);
        $this->travelTo($t0->copy()->addHours(30));   // congela "agora" pro cálculo do intervalo aberto

        ZoneEvent::create(['zone_id' => $z->id, 'type' => 'ocupada', 'colony_id' => $a->id, 'created_at' => $t0]);
        ZoneEvent::create(['zone_id' => $z->id, 'type' => 'conquistada', 'colony_id' => $b->id, 'created_at' => $t0->copy()->addHours(10)]);

        $linhas = app(RankingDeGuerras::class)->geral()->keyBy('colony_id');

        // A controlou 10h (ocupada → conquistada por B); B controla desde então, ainda hoje: 20h.
        $this->assertSame(10.0, $linhas[$a->id]['tempo_de_controle_horas']);
        $this->assertSame(20.0, $linhas[$b->id]['tempo_de_controle_horas']);
    }

    public function test_abandono_fecha_o_intervalo_e_a_zona_fica_sem_dono(): void
    {
        $a = $this->colono('A');
        $z = $this->zona();

        $t0 = now()->subHours(5);
        $this->travelTo($t0->copy()->addHours(5));

        ZoneEvent::create(['zone_id' => $z->id, 'type' => 'ocupada', 'colony_id' => $a->id, 'created_at' => $t0]);
        ZoneEvent::create(['zone_id' => $z->id, 'type' => 'abandonada', 'colony_id' => $a->id, 'created_at' => $t0->copy()->addHours(3)]);

        $linhas = app(RankingDeGuerras::class)->geral()->keyBy('colony_id');

        // Controlou só as 3h até abandonar — as 2h seguintes, a zona não é de ninguém.
        $this->assertSame(3.0, $linhas[$a->id]['tempo_de_controle_horas']);
    }

    public function test_saque_converte_para_fert_pelo_preco_do_catalogo(): void
    {
        $a = $this->colono('A');
        $z = $this->zona();

        $precoMicro = (int) \App\Models\ResourceType::where('code', 'metal_bruto')->value('preco_base_micro');

        Ledger::create([
            'colony_id' => $a->id, 'type' => 'saque_de_guerra', 'amount' => 1000,
            'resource_type' => 'metal_bruto', 'ref' => "zona:{$z->id}:combate:1",
        ]);

        $linhas = app(RankingDeGuerras::class)->geral()->keyBy('colony_id');

        $this->assertEqualsWithDelta(1000 * $precoMicro / 1_000_000, $linhas[$a->id]['saque_fert'], 0.01);
    }

    /** O exemplo que o próprio GDD publica: 5 vitórias, máximo 200 no servidor → percentil 2,5. */
    public function test_percentil_segue_o_exemplo_publicado_no_gdd(): void
    {
        $alvo = $this->colono('Alvo');
        $campeao = $this->colono('Campeão');
        $z = $this->zona();

        for ($i = 0; $i < 5; $i++) {
            Combat::create([
                'zone_id' => $z->id, 'attacker_colony_id' => $alvo->id, 'defender_colony_id' => $campeao->id,
                'tipo' => 'invasao', 'status' => 'vitoria_atacante', 'rodada' => 1, 'chega_at' => now(),
            ]);
        }
        for ($i = 0; $i < 200; $i++) {
            Combat::create([
                'zone_id' => $z->id, 'attacker_colony_id' => $campeao->id, 'defender_colony_id' => $alvo->id,
                'tipo' => 'invasao', 'status' => 'vitoria_atacante', 'rodada' => 1, 'chega_at' => now(),
            ]);
        }

        $linhas = app(RankingDeGuerras::class)->geral()->keyBy('colony_id');

        $this->assertSame(2.5, $linhas[$alvo->id]['percentil']['vitorias']);
    }

    public function test_sem_atividade_nenhuma_o_ranking_nao_quebra(): void
    {
        $this->colono('Sozinho');

        $linhas = app(RankingDeGuerras::class)->geral();

        $this->assertCount(1, $linhas);
        $this->assertSame(0.0, $linhas->first()['geral']);
    }

    public function test_endpoint_e_publico_para_quem_tem_colonia_e_marca_a_propria(): void
    {
        $eu = $this->colono('Eu');
        $outro = $this->colono('Outro');
        $z = $this->zona();

        ZoneEvent::create(['zone_id' => $z->id, 'type' => 'conquistada', 'colony_id' => $eu->id, 'created_at' => now()]);

        $linhas = collect(
            $this->actingAs($eu->user)->getJson('/war/ranking')->assertOk()->json('ranking'),
        );

        $this->assertTrue($linhas->firstWhere('colony_id', $eu->id)['mine']);
        $this->assertFalse($linhas->firstWhere('colony_id', $outro->id)['mine']);
    }
}
