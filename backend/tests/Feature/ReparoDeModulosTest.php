<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Guerra\Atacar;
use App\Domain\Guerra\Forcas;
use App\Domain\Guerra\ResolverCombates;
use App\Domain\Guerra\Sorteio;
use App\Domain\Zona\ExpirarApreensoes;
use App\Domain\Zona\RepararModulo;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Combat;
use App\Models\NeutralZone;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O "Módulo Operacional" ganha as duas metades que faltavam (§28.10; docs/decisoes.md D-66, D-118).
 *
 * Antes desta revisão, `modules_offline` era gravado pela Apreensão e pela Sabotagem e NUNCA lido
 * por ninguém além do próprio ataque e do badge da UI: o bônus de construção, a detecção da Torre,
 * a resistência do Abrigo e a capacidade do Depósito continuavam cheios mesmo com a estrutura
 * "desligada". Este arquivo prova o oposto — que a degradação MORDE — e cobre o resgate automático
 * (24h) e o reparo/resgate antecipado (`RepararModulo`), que também não existiam.
 */
class ReparoDeModulosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\ComponentRecipeSeeder::class);
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
    }

    // ── andaime — mesmo molde do GuerraTest ────────────────────────────────────────────────────

    private const CELULAS = [[20, 20], [-20, 20], [20, -20], [-20, -20]];

    private int $proximaCelula = 0;

    private function colono(string $nome): Colony
    {
        [$x, $y] = self::CELULAS[$this->proximaCelula++];
        $colony = app(CreateColony::class)->handle(User::factory()->create(), $nome, $x, $y);
        $colony->update(['fert_micro' => 10_000 * 1_000_000]);

        return $colony->fresh();
    }

    private int $proximaZona = 0;

    private function zonaDe(Colony $dono, array $estruturas = []): NeutralZone
    {
        $cel = [[47, 47], [48, 48], [49, 49], [45, 46]][$this->proximaZona++];

        $zona = NeutralZone::create(array_merge([
            'x' => $cel[0], 'y' => $cel[1], 'district' => 'NE', 'mineral' => 'metal_bruto', 'level' => 1,
            'owner_colony_id' => $dono->id,
            'status' => 'ocupada',
            'occupied_at' => now()->subDays(30),
            'protected_until' => now()->subDays(20),
            'command_post_level' => 1,
            'productive_at' => now()->subDays(20),
            'deposit_level' => 1,
            'deposit_amount' => 0,
            'last_extraction_at' => now(),
        ], $estruturas));

        for ($i = 0; $i < 20; $i++) {
            Unit::create([
                'zone_id' => $zona->id, 'type' => 'robo_minerador',
                'level' => 1, 'hp_bps' => Unit::INTEIRA, 'status' => 'na_zona',
            ]);
        }

        return $zona->fresh();
    }

    private function dado(bool ...$resultados): void
    {
        $fila = $resultados;
        $this->instance(Sorteio::class, new class($fila) extends Sorteio
        {
            /** @param list<bool> $fila */
            public function __construct(private array $fila) {}

            public function sucesso(int $bps): bool
            {
                return array_shift($this->fila) ?? false;
            }
        });
    }

    private function correrAte(Combat $combate, int $maxHoras = 2): Combat
    {
        $motor = app(ResolverCombates::class);

        for ($h = 0; $h < $maxHoras * 6; $h++) {
            $combate->refresh();
            if (! $combate->vivo()) {
                break;
            }
            $this->travelTo(now()->addMinutes(Combat::RODADA_MINUTOS));
            $motor->handle(now());
        }

        return $combate->fresh();
    }

    // ── fracaoEfetiva() morde de verdade ───────────────────────────────────────────────────────

    public function test_apreensao_zera_o_bonus_da_estrutura(): void
    {
        $dono = $this->colono('Dono');
        $zona = $this->zonaDe($dono, ['wall_level' => 2]);

        $antes = app(Forcas::class)->bonusDeConstrucao($zona);
        $this->assertSame(4000, $antes);   // 2000 bps/nível (D-66) × nível 2

        $zona->update(['modules_offline' => ['muralha_de_perimetro']]);

        $this->assertSame(0, app(Forcas::class)->bonusDeConstrucao($zona->fresh()));
    }

    public function test_sabotagem_reduz_o_bonus_na_proporcao_do_nivel_do_infiltrador(): void
    {
        $dono = $this->colono('Dono');
        $zona = $this->zonaDe($dono, ['wall_level' => 2]);

        // Infiltrador nível 2 de 5: sobra 60% (fracaoEfetiva = 10000 − 2×2000 = 6000).
        $zona->update(['structures_saboted' => ['muralha_de_perimetro' => 2]]);

        $this->assertSame(6000, $zona->fresh()->fracaoEfetiva('muralha_de_perimetro'));
        $this->assertSame(2400, app(Forcas::class)->bonusDeConstrucao($zona->fresh()));   // 4000 × 60%
    }

    public function test_infiltrador_nivel_maximo_zera_a_estrutura_como_a_apreensao(): void
    {
        $dono = $this->colono('Dono');
        $zona = $this->zonaDe($dono, ['wall_level' => 1]);

        $zona->update(['structures_saboted' => ['muralha_de_perimetro' => NeutralZone::NIVEL_MAXIMO_UNIDADE]]);

        $this->assertSame(0, $zona->fresh()->fracaoEfetiva('muralha_de_perimetro'));
    }

    public function test_deposito_apreendido_ou_sabotado_perde_capacidade(): void
    {
        $dono = $this->colono('Dono');
        $zona = $this->zonaDe($dono, ['deposit_level' => 2]);

        $cheia = $zona->capacidadeDeposito();
        $this->assertSame(750, $cheia);   // 500 × 1,5^(2−1), §19.6

        $zona->update(['modules_offline' => ['deposito_de_zona_neutra']]);
        $this->assertSame(0, $zona->fresh()->capacidadeDeposito());

        $zona->update(['modules_offline' => null, 'structures_saboted' => ['deposito_de_zona_neutra' => 3]]);
        $this->assertSame(300, $zona->fresh()->capacidadeDeposito());   // 750 × 40% (nível 3 de 5)
    }

    public function test_torre_apreendida_nao_detecta_a_sabotagem_de_outra_estrutura(): void
    {
        $atacante = $this->colono('Atacante');
        $defensor = $this->colono('Defensor');
        $zona = $this->zonaDe($defensor, ['watchtower_level' => 2]);
        $zona->update(['modules_offline' => ['torre_de_vigia']]);

        $id = Unit::create([
            'colony_id' => $atacante->id, 'type' => 'infiltrador',
            'level' => 1, 'hp_bps' => Unit::INTEIRA, 'status' => 'casa',
        ])->id;

        // Captura o bps de cada sorteio em vez de fingir um resultado — é o valor em si que a
        // torre apreendida tem de zerar, e um `dado()` que ignora o bps não provaria isso.
        $fake = new class extends Sorteio
        {
            public array $vistos = [];

            public function sucesso(int $bps): bool
            {
                $this->vistos[] = $bps;

                return false;
            }
        };
        $this->instance(Sorteio::class, $fake);

        $combate = app(Atacar::class)->handle($atacante, $zona->fresh(), 'sabotagem', [$id], 'deposito_de_zona_neutra');
        $this->correrAte($combate, 1);

        $this->assertNotEmpty($fake->vistos, 'a rodada tinha de rodar ao menos uma vez');
        $this->assertSame(0, $fake->vistos[0], 'a torre apreendida detecta a 0% — fracaoEfetiva zerou o nível dela');
    }

    public function test_abrigo_apreendido_nao_resiste_ao_predador(): void
    {
        $atacante = $this->colono('Atacante');
        $defensor = $this->colono('Defensor');
        // Abrigo nível 5 normalmente puxaria a chance do Predador nível 1 para o piso (10%).
        $zona = $this->zonaDe($defensor, ['shelter_level' => 5, 'wall_level' => 1]);
        $zona->update(['modules_offline' => ['abrigo_de_robos']]);

        $id = Unit::create([
            'colony_id' => $atacante->id, 'type' => 'predador',
            'level' => 1, 'hp_bps' => Unit::INTEIRA, 'status' => 'casa',
        ])->id;

        $this->dado(true);

        $fim = $this->correrAte(
            app(Atacar::class)->handle($atacante, $zona->fresh(), 'apreensao', [$id], 'muralha_de_perimetro'),
        );

        // Abrigo efetivo = 0 (apreendido): chance = 5000 + 1000 × (1 − 0) = 6000. Sem a degradação
        // seria 5000 + 1000 × (1 − 5) = 1000 (o piso) — os dois números não se confundem.
        $this->assertSame(6000, $fim->resultado['chance_bps']);
    }

    // ── a imunidade do Bastião (D-66) ───────────────────────────────────────────────────────────

    public function test_bastiao_impede_a_apreensao_em_qualquer_estrutura_da_zona(): void
    {
        $atacante = $this->colono('Atacante');
        $defensor = $this->colono('Defensor');
        $zona = $this->zonaDe($defensor, ['bastion_level' => 1, 'wall_level' => 1]);

        $id = Unit::create([
            'colony_id' => $atacante->id, 'type' => 'predador',
            'level' => 1, 'hp_bps' => Unit::INTEIRA, 'status' => 'casa',
        ])->id;

        try {
            // Mira a MURALHA, não o Bastião — a imunidade cobre a zona inteira, não só a si mesmo.
            app(Atacar::class)->handle($atacante, $zona, 'apreensao', [$id], 'muralha_de_perimetro');
            $this->fail('deveria ter recusado: zona com Bastião é imune à Apreensão');
        } catch (DomainRuleException $e) {
            $this->assertSame('bastiao_imune', $e->codigo);
        }
    }

    public function test_bastiao_nao_impede_a_sabotagem(): void
    {
        // O GDD só cita a imunidade na linha da Apreensão (§28.10) — a Sabotagem não tem essa cláusula.
        $atacante = $this->colono('Atacante');
        $defensor = $this->colono('Defensor');
        $zona = $this->zonaDe($defensor, ['bastion_level' => 1, 'wall_level' => 1]);

        $id = Unit::create([
            'colony_id' => $atacante->id, 'type' => 'infiltrador',
            'level' => 1, 'hp_bps' => Unit::INTEIRA, 'status' => 'casa',
        ])->id;

        $this->dado(false, true);

        $fim = $this->correrAte(
            app(Atacar::class)->handle($atacante, $zona, 'sabotagem', [$id], 'muralha_de_perimetro'),
        );

        $this->assertSame('vitoria_atacante', $fim->status);
    }

    // ── o resgate automático da Apreensão (24h, D-118) ─────────────────────────────────────────

    public function test_o_tick_repara_sozinho_a_apreensao_vencida(): void
    {
        $dono = $this->colono('Dono');
        $zona = $this->zonaDe($dono, ['wall_level' => 1]);
        $zona->update([
            'modules_offline' => ['muralha_de_perimetro'],
            'modules_offline_expira_em' => ['muralha_de_perimetro' => now()->subMinute()->toIso8601String()],
        ]);

        $expiradas = app(ExpirarApreensoes::class)->handle(now());

        $this->assertSame(1, $expiradas);
        $zona->refresh();
        $this->assertEmpty($zona->modules_offline ?? []);
        $this->assertNull($zona->modules_offline_expira_em);
    }

    public function test_o_tick_nao_repara_antes_da_hora(): void
    {
        $dono = $this->colono('Dono');
        $zona = $this->zonaDe($dono, ['wall_level' => 1]);
        $zona->update([
            'modules_offline' => ['muralha_de_perimetro'],
            'modules_offline_expira_em' => ['muralha_de_perimetro' => now()->addHours(23)->toIso8601String()],
        ]);

        $expiradas = app(ExpirarApreensoes::class)->handle(now());

        $this->assertSame(0, $expiradas);
        $this->assertContains('muralha_de_perimetro', $zona->fresh()->modules_offline);
    }

    // ── o reparo/resgate pago (RepararModulo, D-118) ───────────────────────────────────────────

    public function test_reparar_estrutura_sabotada_cobra_dez_por_cento_e_limpa(): void
    {
        $dono = $this->colono('Dono');
        $zona = $this->zonaDe($dono, ['wall_level' => 1]);
        $zona->update(['structures_saboted' => ['muralha_de_perimetro' => 3]]);

        $antesMetal = $dono->resources()->where('resource_type', 'metal_bruto')->value('amount');
        $antesLigas = $dono->resources()->where('resource_type', 'ligas_metalicas')->value('amount');

        app(RepararModulo::class)->handle($dono, $zona->fresh(), 'muralha_de_perimetro');

        $zona->refresh();
        $this->assertEmpty($zona->structures_saboted ?? []);

        // Muralha nível 1: 400 Metal Bruto + 100 Ligas (D-66). 10% padrão (reparo_bps_do_custo).
        $this->assertSame(
            $antesMetal - 40,
            $dono->fresh()->resources()->where('resource_type', 'metal_bruto')->value('amount'),
        );
        $this->assertSame(
            $antesLigas - 10,
            $dono->fresh()->resources()->where('resource_type', 'ligas_metalicas')->value('amount'),
        );
        $this->assertDatabaseHas('ledger', [
            'colony_id' => $dono->id, 'type' => 'reparo_de_modulo', 'resource_type' => 'metal_bruto', 'amount' => -40,
        ]);
    }

    public function test_resgatar_apreensao_antecipadamente_cobra_e_limpa_os_dois_campos(): void
    {
        $dono = $this->colono('Dono');
        $zona = $this->zonaDe($dono, ['wall_level' => 1]);
        $zona->update([
            'modules_offline' => ['muralha_de_perimetro'],
            'modules_offline_expira_em' => ['muralha_de_perimetro' => now()->addHours(10)->toIso8601String()],
        ]);

        app(RepararModulo::class)->handle($dono, $zona->fresh(), 'muralha_de_perimetro');

        $zona->refresh();
        $this->assertEmpty($zona->modules_offline ?? []);
        $this->assertNull($zona->modules_offline_expira_em);
    }

    public function test_nada_a_reparar_quando_a_estrutura_opera_normalmente(): void
    {
        $dono = $this->colono('Dono');
        $zona = $this->zonaDe($dono, ['wall_level' => 1]);

        try {
            app(RepararModulo::class)->handle($dono, $zona, 'muralha_de_perimetro');
            $this->fail('deveria recusar: nada degradado nesta estrutura');
        } catch (DomainRuleException $e) {
            $this->assertSame('nada_a_reparar', $e->codigo);
        }
    }

    public function test_reparar_recusa_zona_de_outra_colonia(): void
    {
        $dono = $this->colono('Dono');
        $outro = $this->colono('Outro');
        $zona = $this->zonaDe($dono, ['wall_level' => 1]);
        $zona->update(['structures_saboted' => ['muralha_de_perimetro' => 1]]);

        $this->expectException(DomainRuleException::class);
        app(RepararModulo::class)->handle($outro, $zona, 'muralha_de_perimetro');
    }

    public function test_reparar_recusa_recursos_insuficientes(): void
    {
        $dono = $this->colono('Dono');
        $zona = $this->zonaDe($dono, ['wall_level' => 1]);
        $zona->update(['structures_saboted' => ['muralha_de_perimetro' => 1]]);

        $dono->resources()->whereIn('resource_type', ['metal_bruto', 'ligas_metalicas'])
            ->update(['amount' => 0]);

        try {
            app(RepararModulo::class)->handle($dono, $zona->fresh(), 'muralha_de_perimetro');
            $this->fail('deveria recusar: sem recursos');
        } catch (DomainRuleException $e) {
            $this->assertSame('recursos_insuficientes', $e->codigo);
        }

        // Nada foi limpo: a recusa é tudo-ou-nada.
        $this->assertNotEmpty($zona->fresh()->structures_saboted ?? []);
    }
}
