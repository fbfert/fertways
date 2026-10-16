<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Guerra\Atacar;
use App\Domain\Guerra\ResolverCombates;
use App\Models\Colony;
use App\Models\Combat;
use App\Models\NeutralZone;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ErgueEstruturasDaZona;
use Tests\TestCase;

/**
 * O relatório de batalha (A2.V6; D-275).
 *
 * A batalha terminava e SUMIA: o Quartel só lista o que marcha ou está em curso, e nenhuma tela do
 * jogo lia `vitoria_atacante` ou `repelido`. Quem perdesse uma zona durante a noite voltaria a um
 * mapa diferente sem uma linha dizendo o que aconteceu. E as baixas nem existiam: o motor apaga a
 * unidade que zera e não conta.
 *
 * Agora o combate guarda, enquanto corre, a força de cada lado no começo de cada rodada (a linha do
 * tempo) e quantas unidades havia de cada lado na chegada e no fim; e `/war/relatorios` devolve as
 * batalhas encerradas da colônia, dos dois lados.
 */
class RelatorioDeBatalhaTest extends TestCase
{
    use ErgueEstruturasDaZona;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
        $this->seed(ComponentRecipeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
    }

    private const CELULAS = [[20, 20], [-20, 20], [20, -20], [-20, -20], [25, 25], [-25, 25]];

    private int $cel = 0;

    private int $zonas = 0;

    private function colono(string $nome): Colony
    {
        [$x, $y] = self::CELULAS[$this->cel++];
        $c = app(CreateColony::class)->handle(User::factory()->create(), $nome, $x, $y);
        $c->update(['fert_micro' => 10_000 * 1_000_000]);

        return $c->fresh();
    }

    private function zonaDe(Colony $dono, int $robos): NeutralZone
    {
        $cel = [[47, 47], [48, 48], [49, 49], [45, 46]][$this->zonas++];

        $zona = $this->criarZonaComEstruturas([
            'x' => $cel[0], 'y' => $cel[1], 'district' => 'NE', 'mineral' => 'metal_bruto', 'level' => 1,
            'owner_colony_id' => $dono->id, 'status' => 'ocupada',
            'occupied_at' => now()->subDays(30), 'protected_until' => now()->subDays(20),
            'command_post_level' => 1, 'productive_at' => now()->subDays(20),
            'deposit_level' => 1, 'deposit_amount' => 0, 'last_extraction_at' => now(),
        ]);

        for ($i = 0; $i < $robos; $i++) {
            Unit::create(['zone_id' => $zona->id, 'type' => 'robo_minerador', 'level' => 1,
                'hp_bps' => Unit::INTEIRA, 'status' => 'na_zona']);
        }

        return $zona->fresh();
    }

    private function sentinelas(Colony $c, int $quantas): array
    {
        return collect(range(1, $quantas))->map(fn () => Unit::create([
            'colony_id' => $c->id, 'type' => 'sentinela', 'level' => 1,
            'hp_bps' => Unit::INTEIRA, 'status' => 'casa',
        ])->id)->all();
    }

    private function correr(Combat $combate): Combat
    {
        $motor = app(ResolverCombates::class);

        for ($i = 0; $i < 72 * 6; $i++) {
            if (! $combate->fresh()->vivo()) {
                break;
            }
            $this->travelTo(now()->addMinutes(Combat::RODADA_MINUTOS));
            $motor->handle(now());
        }

        return $combate->fresh();
    }

    private function relatorios(Colony $c): array
    {
        return $this->actingAs($c->user)->getJson('/war/relatorios')->assertOk()->json('relatorios');
    }

    public function test_a_invasao_vencida_aparece_para_os_dois_lados(): void
    {
        $atk = $this->colono('Atacante');
        $def = $this->colono('Defensor');
        $zona = $this->zonaDe($def, 32);   // 800 de defesa

        $fim = $this->correr(app(Atacar::class)->handle($atk, $zona, 'invasao', $this->sentinelas($atk, 12)));
        $this->assertSame('vitoria_atacante', $fim->status);

        [$meu] = $this->relatorios($atk);
        $this->assertSame($fim->id, $meu['id']);
        $this->assertTrue($meu['sou_o_atacante']);
        $this->assertTrue($meu['venci']);
        $this->assertSame('Defensor', $meu['adversario']);
        $this->assertSame($fim->rodada, $meu['rodadas']);
        $this->assertSame(12, $meu['atacantes']['inicio']);
        // Venceu ferido: o exército volta, e quem zerou no caminho ficou no campo.
        $this->assertSame(Unit::where('colony_id', $atk->id)->count(), $meu['atacantes']['de_pe']);
        $this->assertGreaterThan(0, $meu['atacantes']['de_pe']);
        $this->assertSame(['inicio' => 32, 'de_pe' => 0], $meu['defensores']);
        // O fim pelo relógio do motor: a rodada 1 é na chegada, e cada outra 10 min depois.
        $this->assertSame(
            $fim->chega_at->copy()->addMinutes(($fim->rodada - 1) * Combat::RODADA_MINUTOS)->toIso8601String(),
            $meu['terminou_em'],
        );

        [$dele] = $this->relatorios($def);
        $this->assertFalse($dele['venci']);
        $this->assertFalse($dele['sou_o_atacante']);
        $this->assertSame('Atacante', $dele['adversario']);
    }

    /** A linha do tempo: a força de cada lado no começo de cada rodada, uma entrada por rodada. */
    public function test_a_linha_do_tempo_mostra_a_defesa_caindo(): void
    {
        $atk = $this->colono('Atacante');
        $zona = $this->zonaDe($this->colono('Defensor'), 32);

        $fim = $this->correr(app(Atacar::class)->handle($atk, $zona, 'invasao', $this->sentinelas($atk, 12)));
        $linha = $this->relatorios($atk)[0]['linha'];

        $this->assertCount($fim->rodada, $linha);
        $this->assertSame(1, $linha[0]['r']);
        $this->assertSame(960, $linha[0]['fo']);   // 12 Sentinelas × 80
        // 32 robôs × 25 = 800, mais os 20% do defensor offline: a linha mostra a força EFETIVA, a
        // que a batalha usa — a soma crua contaria uma história diferente da que aconteceu.
        $this->assertSame(960, $linha[0]['fd']);
        $this->assertLessThan($linha[0]['fd'], end($linha)['fd']);
    }

    public function test_o_ataque_repelido_conta_as_baixas_do_atacante(): void
    {
        $atk = $this->colono('Atacante');
        $def = $this->colono('Defensor');
        $zona = $this->zonaDe($def, 20);

        $fim = $this->correr(app(Atacar::class)->handle($atk, $zona, 'invasao', $this->sentinelas($atk, 1)));
        $this->assertSame('repelido', $fim->status);

        [$meu] = $this->relatorios($atk);
        $this->assertFalse($meu['venci']);
        $this->assertSame(['inicio' => 1, 'de_pe' => 0], $meu['atacantes']);
        $this->assertSame(20, $meu['defensores']['inicio']);
        $this->assertSame(20, $meu['defensores']['de_pe'], 'ferido não é morto: os 20 robôs continuam de pé');

        $this->assertTrue($this->relatorios($def)[0]['venci']);
    }

    /**
     * O "Desde sua última visita" conta a batalha que acabou enquanto o jogador esteve fora. Sem
     * isto, quem perdesse a zona à noite só ficaria sabendo se abrisse o Quartel por acaso.
     */
    public function test_o_resumo_de_retorno_conta_a_batalha_dos_dois_lados(): void
    {
        $atk = $this->colono('Atacante');
        $def = $this->colono('Defensor');
        $zona = $this->zonaDe($def, 6);
        $atk->user->forceFill(['resumo_visto_em' => now()->subDay()])->save();
        $def->user->forceFill(['resumo_visto_em' => now()->subDay()])->save();

        $fim = $this->correr(app(Atacar::class)->handle($atk, $zona, 'invasao', $this->sentinelas($atk, 3)));
        $this->assertSame('vitoria_atacante', $fim->status);

        $dele = $this->actingAs($def->user->fresh())->getJson('/resumo')->assertOk()->json('batalhas');
        $this->assertSame([[
            'id' => $fim->id, 'tipo' => 'invasao', 'sou_o_atacante' => false, 'venci' => false,
            'adversario' => 'Atacante', 'zona' => $zona->name,
        ]], $dele);

        $this->assertTrue($this->actingAs($atk->user->fresh())->getJson('/resumo')->json('batalhas.0.venci'));
    }

    /** A batalha de antes da janela já foi contada no resumo anterior: não volta. */
    public function test_o_resumo_nao_repete_a_batalha_de_antes_da_janela(): void
    {
        $atk = $this->colono('Atacante');
        $zona = $this->zonaDe($this->colono('Defensor'), 6);

        $this->correr(app(Atacar::class)->handle($atk, $zona, 'invasao', $this->sentinelas($atk, 3)));
        $this->travelTo(now()->addHours(3));
        $atk->user->forceFill(['resumo_visto_em' => now()->subHours(2)])->save();

        $this->assertSame([], $this->actingAs($atk->user->fresh())->getJson('/resumo')->json('batalhas'));
    }

    /** Só o que acabou, só o que é da colônia. O que está em curso continua no "Batalhas em curso". */
    public function test_so_as_batalhas_encerradas_e_so_as_minhas(): void
    {
        $atk = $this->colono('Atacante');
        $def = $this->colono('Defensor');
        $alheio = $this->colono('Alheio');

        app(Atacar::class)->handle($atk, $this->zonaDe($def, 20), 'invasao', $this->sentinelas($atk, 1));

        $this->assertSame([], $this->relatorios($atk), 'em marcha ainda não é relatório');
        $this->assertSame([], $this->relatorios($alheio));
    }
}
