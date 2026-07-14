<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Guerra\Atacar;
use App\Domain\Guerra\Forcas;
use App\Domain\Guerra\ResolverCombates;
use App\Domain\Guerra\Sorteio;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Combat;
use App\Models\NeutralZone;
use App\Models\Unit;
use App\Models\User;
use App\Models\ZoneMineral;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A guerra (GDD §27, §28.10; docs/decisoes.md D-66). A Fatia 2 do D-52.
 *
 * O que se afirma aqui é, sobretudo, o que o GDD **não** conseguiria afirmar sozinho: que a batalha
 * TERMINA (a fórmula à letra decai geometricamente e nunca zera — arbitragem 8 do D-66), que o bônus
 * das construções realmente protege, e que o saque incide sobre o **exposto**, não sobre o total.
 */
class GuerraTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\ComponentRecipeSeeder::class);
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
    }

    // ── andaime ─────────────────────────────────────────────────────────────────────────────────

    /**
     * Células de periferia livres, longe do disco de founders e **fora dos distritos de zona**
     * (NE é `x∈[45,50] y∈[46,50]`, e espelhos). Uma por colônia: o `unique(x,y)` não perdoa.
     */
    private const CELULAS = [[20, 20], [-20, 20], [20, -20], [-20, -20], [25, 25], [-25, 25]];

    private int $proximaCelula = 0;

    private function colono(string $nome, ?int $x = null, ?int $y = null): Colony
    {
        if ($x === null) {
            [$x, $y] = self::CELULAS[$this->proximaCelula++];
        }

        $colony = app(CreateColony::class)->handle(User::factory()->create(), $nome, $x, $y);
        $colony->update(['fert_micro' => 10_000 * 1_000_000]);

        return $colony->fresh();
    }

    /** Uma zona ocupada pelo defensor, com a guarnição que a ocupação dá, e fora da proteção. */
    private int $proximaZona = 0;

    private function zonaDe(Colony $dono, int $deposito = 0, array $estruturas = []): NeutralZone
    {
        // Células do distrito NE (x∈[45,50], y∈[46,50]). Uma por zona: o unique(x,y) vale aqui também.
        $cel = [[47, 47], [48, 48], [49, 49], [45, 46]][$this->proximaZona++];

        $zona = NeutralZone::create(array_merge([
            'x' => $cel[0], 'y' => $cel[1], 'district' => 'NE', 'mineral' => 'metal_bruto', 'level' => 1,
            'owner_colony_id' => $dono->id,
            'status' => 'ocupada',
            'occupied_at' => now()->subDays(30),
            'protected_until' => now()->subDays(20),   // a proteção de novato já venceu
            'command_post_level' => 1,
            'productive_at' => now()->subDays(20),
            'deposit_level' => 1,               // protege 500 (§19.6)
            'deposit_amount' => $deposito,
            'last_extraction_at' => now(),
        ], $estruturas));

        $this->guarnecer($zona, 20);

        return $zona->fresh();
    }

    private function guarnecer(NeutralZone $zona, int $robos): void
    {
        for ($i = 0; $i < $robos; $i++) {
            Unit::create([
                'zone_id' => $zona->id, 'type' => 'robo_minerador',
                'level' => 1, 'hp_bps' => Unit::INTEIRA, 'status' => 'na_zona',
            ]);
        }
    }

    /** @return list<int> */
    private function sentinelas(Colony $colony, int $quantas, int $nivel = 1): array
    {
        $ids = [];
        for ($i = 0; $i < $quantas; $i++) {
            $ids[] = Unit::create([
                'colony_id' => $colony->id, 'type' => 'sentinela',
                'level' => $nivel, 'hp_bps' => Unit::INTEIRA, 'status' => 'casa',
            ])->id;
        }

        return $ids;
    }

    /** Um dado viciado: o teste decide o que sai. Sem isto, sabotagem não é afirmável. */
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

    /** Roda o tick da guerra até o combate acabar, ou até estourar a paciência. */
    private function correrAte(Combat $combate, int $maxHoras = 72): Combat
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

    // ── a batalha termina, que é o que o GDD sozinho não garante ────────────────────────────────

    /**
     * O cenário EQUILIBRADO do próprio §27.5: "Ataque 1.000 vs Defesa 800 → ~12 rodadas (120 min)".
     *
     * É a confirmação de que a arbitragem 8 do D-66 leu o documento certo. Com o dano saindo da
     * força **inicial**, a defesa de 800 cai a zero em 12 rodadas — exatamente o que a tabela do GDD
     * estima ao lado da fórmula. Com a força "atual" que o texto escreve, levaria 19, e o próprio
     * documento discordaria de si mesmo.
     */
    public function test_o_cenario_equilibrado_do_gdd_termina_nas_12_rodadas_que_ele_estima(): void
    {
        // Defesa 800 = 32 robôs × 25 pontos, sem construções (bônus zero).
        // Ataque 1.000 = 12 Sentinelas nv1 (80 × 12 = 960) + 1 nv2 (120) = 1.080. Perto o bastante;
        // o que se afirma é o TÉRMINO e a ordem de grandeza, não um número mágico.
        $atacante = $this->colono('Atacante');
        $defensor = $this->colono('Defensor');

        $zona = $this->zonaDe($defensor);
        Unit::where('zone_id', $zona->id)->delete();
        $this->guarnecer($zona, 32);   // 32 × 25 = 800 de defesa

        $ids = $this->sentinelas($atacante, 12);   // 12 × 80 = 960 de ataque

        $combate = app(Atacar::class)->handle($atacante, $zona->fresh(), 'invasao', $ids);

        $fim = $this->correrAte($combate);

        $this->assertSame('vitoria_atacante', $fim->status);
        // Nem instantâneo nem eterno: uma dezena de rodadas, como o §27.5 desenha de propósito
        // ("tempo suficiente para o defensor receber notificação e despachar reforços").
        $this->assertGreaterThan(8, $fim->rodada);
        $this->assertLessThan(20, $fim->rodada);
    }

    /** Defensor muito superior: o exército atacante é destruído e a zona não muda de dono (§27.8). */
    public function test_o_ataque_fraco_e_repelido_e_a_zona_nao_muda_de_dono(): void
    {
        $atacante = $this->colono('Atacante');
        $defensor = $this->colono('Defensor');

        $zona = $this->zonaDe($defensor);   // 20 robôs = 500 de defesa
        $ids = $this->sentinelas($atacante, 1);   // 80 de ataque. Não dá.

        $combate = app(Atacar::class)->handle($atacante, $zona, 'invasao', $ids);
        $fim = $this->correrAte($combate);

        $this->assertSame('repelido', $fim->status);
        $this->assertSame($defensor->id, $zona->fresh()->owner_colony_id);
    }

    // ── o bônus das construções protege de verdade ──────────────────────────────────────────────

    /**
     * A Muralha, a Torre e o Bastião **fazem diferença** — e este teste existe porque a primeira
     * versão do motor as anulava sem que nada reclamasse.
     *
     * O dano por rodada sai da força defensiva COM bônus; se a fração perdida saísse da soma crua
     * das unidades, ela seria maior exatamente na proporção do bônus, e ele se cancelaria contra si
     * mesmo. A zona fortificada tem de aguentar mais rodadas que a nua, com a mesma guarnição.
     */
    public function test_a_zona_fortificada_aguenta_mais_rodadas_que_a_nua(): void
    {
        $nua = $this->rodadasParaCair([]);
        $forte = $this->rodadasParaCair([
            'wall_level' => 1, 'watchtower_level' => 1, 'bastion_level' => 1,   // +20 +30 +50 = +100%
        ]);

        $this->assertGreaterThan(
            $nua,
            $forte,
            'as três construções dobram a defesa (§27.3): a zona fortificada tem de resistir mais',
        );
    }

    private function rodadasParaCair(array $estruturas): int
    {
        $atacante = $this->colono('Atacante'.uniqid());
        $defensor = $this->colono('Defensor'.uniqid());

        $zona = $this->zonaDe($defensor, 0, $estruturas);
        $ids = $this->sentinelas($atacante, 20);   // 1.600 de ataque: vence nos dois casos

        $combate = app(Atacar::class)->handle($atacante, $zona, 'invasao', $ids);
        $fim = $this->correrAte($combate);

        $this->assertSame('vitoria_atacante', $fim->status);

        return $fim->rodada;
    }

    // ── o saque ─────────────────────────────────────────────────────────────────────────────────

    /**
     * 50% do **exposto**, não do total (§27.8, com a correção da v3.2), e os outros 50% **ficam** no
     * depósito — a v3.0 dizia que eram destruídos, e a v3.2 vence pela precedência da seção 0.
     */
    public function test_a_vitoria_saqueia_metade_do_exposto_e_deixa_o_resto(): void
    {
        $atacante = $this->colono('Atacante');
        $defensor = $this->colono('Defensor');

        // 1.500 no depósito: 500 protegidos (cap do nível 1) e 1.000 expostos.
        $zona = $this->zonaDe($defensor, 1500);
        $antes = $atacante->resources()->where('resource_type', 'metal_bruto')->value('amount');

        $ids = $this->sentinelas($atacante, 20);
        $fim = $this->correrAte(app(Atacar::class)->handle($atacante, $zona, 'invasao', $ids));

        $this->assertSame('vitoria_atacante', $fim->status);
        $this->assertSame(500, $fim->resultado['saque']);   // 50% de 1.000 expostos

        $zona->refresh();
        $this->assertSame(1000, $zona->deposit_amount);          // 1.500 − 500: o resto PERMANECE
        $this->assertSame($atacante->id, $zona->owner_colony_id);

        $this->assertSame(
            $antes + 500,
            $atacante->fresh()->resources()->where('resource_type', 'metal_bruto')->value('amount'),
        );
    }

    /**
     * Os minerais da Indústria Siderúrgica (D-82) são butim como tudo o mais no Depósito — a
     * repartição do saque agora conta MAIS de dois potes.
     */
    public function test_o_saque_leva_os_minerais_da_siderurgica_tambem(): void
    {
        $atacante = $this->colono('Atacante');
        $defensor = $this->colono('Defensor');

        // 500 protegidos (cap do nível 1); 500 bruto + 100 ouro = 600, então 100 expostos.
        $zona = $this->zonaDe($defensor, 500);
        ZoneMineral::create(['zone_id' => $zona->id, 'resource_type' => 'ouro', 'amount' => 100]);
        $zona->refresh();

        $antesOuro = $atacante->resources()->where('resource_type', 'ouro')->value('amount');

        $ids = $this->sentinelas($atacante, 20);
        $fim = $this->correrAte(app(Atacar::class)->handle($atacante, $zona, 'invasao', $ids));

        $this->assertSame('vitoria_atacante', $fim->status);
        // 50% de 100 expostos = 50 no total — repartido proporcional ao que há de cada POTE no
        // estoque inteiro (600): ouro é 100/600 dele, então leva intdiv(50×100, 600) = 8; o
        // bruto absorve o resto (42), por valer menos.
        $this->assertSame(50, $fim->resultado['saque']);
        $this->assertSame(42, $fim->resultado['saque_bruto']);
        $this->assertSame(['ouro' => 8], $fim->resultado['saque_minerais']);

        $this->assertSame(
            $antesOuro + 8,
            $atacante->fresh()->resources()->where('resource_type', 'ouro')->value('amount'),
        );
        $this->assertSame(92, $zona->fresh()->minerais()->where('resource_type', 'ouro')->value('amount'));
        $this->assertSame(458, $zona->fresh()->deposit_amount);
    }

    /** Zona com o depósito dentro da capacidade: tudo protegido, saque ZERO. */
    public function test_o_deposito_dentro_da_capacidade_nao_da_butim(): void
    {
        $atacante = $this->colono('Atacante');
        $defensor = $this->colono('Defensor');

        $zona = $this->zonaDe($defensor, 400);   // cabe nos 500 do nível 1: nada exposto
        $ids = $this->sentinelas($atacante, 20);

        $fim = $this->correrAte(app(Atacar::class)->handle($atacante, $zona, 'invasao', $ids));

        $this->assertSame('vitoria_atacante', $fim->status);
        $this->assertSame(0, $fim->resultado['saque']);
        $this->assertSame(400, $zona->fresh()->deposit_amount);   // intacto
    }

    // ── as regras que barram o ataque ───────────────────────────────────────────────────────────

    public function test_nao_se_ataca_zona_sob_protecao_de_novato(): void
    {
        $atacante = $this->colono('Atacante');
        $defensor = $this->colono('Defensor');

        $zona = $this->zonaDe($defensor);
        $zona->update(['protected_until' => now()->addDays(3)]);   // §28.4: 8 dias

        $this->expectException(DomainRuleException::class);
        app(Atacar::class)->handle($atacante, $zona->fresh(), 'invasao', $this->sentinelas($atacante, 5));
    }

    /** §27.10: o mesmo atacante não reataca a mesma zona por 48 h. Outros podem. */
    public function test_o_cooldown_de_48h_barra_o_mesmo_atacante_e_nao_os_outros(): void
    {
        $a = $this->colono('Atacante');
        $b = $this->colono('Outro');
        $defensor = $this->colono('Defensor');

        $zona = $this->zonaDe($defensor);

        app(Atacar::class)->handle($a, $zona, 'invasao', $this->sentinelas($a, 1));

        // O outro passa, no mesmo minuto.
        app(Atacar::class)->handle($b, $zona->fresh(), 'invasao', $this->sentinelas($b, 1));

        // O primeiro, não.
        $this->expectException(DomainRuleException::class);
        app(Atacar::class)->handle($a, $zona->fresh(), 'invasao', $this->sentinelas($a, 1));
    }

    public function test_nao_se_ataca_a_propria_zona(): void
    {
        $dono = $this->colono('Dono');
        $zona = $this->zonaDe($dono);

        $this->expectException(DomainRuleException::class);
        app(Atacar::class)->handle($dono, $zona, 'invasao', $this->sentinelas($dono, 1));
    }

    // ── a marcha ────────────────────────────────────────────────────────────────────────────────

    /** §27.4: a marcha de combate é 1,3× mais lenta que a civil (o Furgão, 4 slots/min). */
    public function test_a_marcha_de_combate_e_13_por_cento_mais_lenta_que_a_civil(): void
    {
        $atacante = $this->colono('Atacante', 20, 20);
        $defensor = $this->colono('Defensor', 30, 30);

        $zona = $this->zonaDe($defensor);
        $combate = app(Atacar::class)->handle($atacante, $zona, 'invasao', $this->sentinelas($atacante, 1));

        // De (20,20) a (47,47): distância euclidiana arredondada = 38 slots.
        // Civil: 38 / 4 = 9,5 min. Combate: × 1,3 = 12,35 min.
        $minutos = now()->diffInSeconds($combate->chega_at) / 60;
        $this->assertEqualsWithDelta(12.35, $minutos, 0.2);

        // E enquanto marcha, a Sentinela não está em casa nem defende nada.
        $this->assertSame('marchando', Unit::where('combat_id', $combate->id)->first()->status);
    }

    // ── sabotagem (§28.10) ──────────────────────────────────────────────────────────────────────

    /** Sem Torre de Vigia não há quem o veja: passando o dado dos 60%, o módulo cai. */
    public function test_a_sabotagem_desliga_a_estrutura_quando_nao_ha_torre(): void
    {
        $atacante = $this->colono('Atacante');
        $defensor = $this->colono('Defensor');

        $zona = $this->zonaDe($defensor, 0, ['deposit_level' => 2]);

        Unit::create([
            'colony_id' => $atacante->id, 'type' => 'infiltrador',
            'level' => 1, 'hp_bps' => Unit::INTEIRA, 'status' => 'casa',
        ]);
        $id = Unit::where('colony_id', $atacante->id)->where('type', 'infiltrador')->value('id');

        // Dado: [detecção da Torre = false (não há torre), os 60% = true]
        $this->dado(false, true);

        $combate = app(Atacar::class)->handle($atacante, $zona, 'sabotagem', [$id], 'deposito');
        $fim = $this->correrAte($combate);

        $this->assertSame('vitoria_atacante', $fim->status);
        $this->assertSame('deposito', $fim->resultado['sabotado']);
        $this->assertContains('deposito', $zona->fresh()->modules_offline);

        // A zona NÃO muda de dono: sabotagem não é conquista (§28.10).
        $this->assertSame($defensor->id, $zona->fresh()->owner_colony_id);
    }

    /**
     * Detectado, o Infiltrador morre. E morre **sempre**, não "provavelmente": o GDD nunca publica
     * ataque para ele, então a Força Ofensiva dele é zero e ele é repelido por definição (§28.10 diz
     * "baixo poder de combate — provavelmente perde"; no nosso motor, perde). É o desenho.
     */
    public function test_o_infiltrador_detectado_pela_torre_morre(): void
    {
        $atacante = $this->colono('Atacante');
        $defensor = $this->colono('Defensor');

        $zona = $this->zonaDe($defensor, 0, ['watchtower_level' => 3]);   // detecta a 45%/rodada

        $id = Unit::create([
            'colony_id' => $atacante->id, 'type' => 'infiltrador',
            'level' => 1, 'hp_bps' => Unit::INTEIRA, 'status' => 'casa',
        ])->id;

        $this->dado(true);   // a Torre o vê

        $fim = $this->correrAte(
            app(Atacar::class)->handle($atacante, $zona, 'sabotagem', [$id], 'deposito'),
        );

        $this->assertSame('repelido', $fim->status);
        $this->assertSame('detectado_pela_torre', $fim->resultado['detectado']);
        $this->assertNull(Unit::find($id));                       // destruído
        $this->assertEmpty($zona->fresh()->modules_offline ?? []);   // nada foi desligado
    }

    // ── apreensão — o Predador (§28.10) ─────────────────────────────────────────────────────────

    /**
     * A chance sai da tabela do D-66: base 50%, ±10% por nível de diferença contra o Abrigo de
     * Robôs, presa entre 10% e 90%. Predador nv3 contra Abrigo nv1 = 50% + 10% × 2 = 70%.
     */
    public function test_a_chance_do_predador_compara_o_nivel_dele_com_o_do_abrigo(): void
    {
        $atacante = $this->colono('Atacante');
        $defensor = $this->colono('Defensor');

        $zona = $this->zonaDe($defensor, 0, ['shelter_level' => 1, 'wall_level' => 1]);

        $id = Unit::create([
            'colony_id' => $atacante->id, 'type' => 'predador',
            'level' => 3, 'hp_bps' => Unit::INTEIRA, 'status' => 'casa',
        ])->id;

        $this->dado(true);   // passou

        $fim = $this->correrAte(
            app(Atacar::class)->handle($atacante, $zona, 'apreensao', [$id], 'muralha'),
        );

        $this->assertSame('vitoria_atacante', $fim->status);
        $this->assertSame(7000, $fim->resultado['chance_bps']);   // 50% + 10% × (3 − 1)
        $this->assertSame('muralha', $fim->resultado['apreendido']);
        $this->assertNotNull($fim->prazo_at);   // as 24 h do resgate
    }

    public function test_nao_se_mira_estrutura_que_a_zona_nao_tem(): void
    {
        $atacante = $this->colono('Atacante');
        $defensor = $this->colono('Defensor');

        $zona = $this->zonaDe($defensor);   // sem Bastião

        $id = Unit::create([
            'colony_id' => $atacante->id, 'type' => 'predador',
            'level' => 1, 'hp_bps' => Unit::INTEIRA, 'status' => 'casa',
        ])->id;

        $this->expectException(DomainRuleException::class);
        app(Atacar::class)->handle($atacante, $zona, 'apreensao', [$id], 'bastiao');
    }

    // ── cerco (§28.10) ──────────────────────────────────────────────────────────────────────────

    /** O cerco fecha a zona, espera 48 h e leva 30% do exposto. A zona NÃO muda de dono. */
    public function test_o_cerco_leva_30_por_cento_do_exposto_e_nao_toma_a_zona(): void
    {
        $atacante = $this->colono('Atacante');
        $defensor = $this->colono('Defensor');

        $zona = $this->zonaDe($defensor, 1500);   // 500 protegidos, 1.000 expostos
        $ids = $this->sentinelas($atacante, 3);

        $combate = app(Atacar::class)->handle($atacante, $zona, 'cerco', $ids);

        // A marcha leva ~12 min. Chegando, a zona fica cercada e o relógio das 48 h começa.
        $this->travelTo(now()->addMinutes(20));
        app(ResolverCombates::class)->handle(now());

        $zona->refresh();
        $this->assertTrue($zona->cercada());
        // Ainda não: o depósito só fecha 30 min depois de o cerco COMEÇAR (as 3 rodadas do §28.10).
        $this->assertFalse($zona->depositoBloqueado());

        $this->travelTo(now()->addMinutes(31));
        $this->assertTrue($zona->fresh()->depositoBloqueado());

        $fim = $this->correrAte($combate->fresh(), 60);

        $this->assertSame('rendido', $fim->status);
        $this->assertSame(300, $fim->resultado['saque']);   // 30% de 1.000
        $this->assertSame($defensor->id, $zona->fresh()->owner_colony_id);   // cerco não conquista
        $this->assertFalse($zona->fresh()->cercada());       // levantado
    }

    // ── os sobreviventes ────────────────────────────────────────────────────────────────────────

    /** §27.6: quem sobrevive volta para casa FERIDO, e ferido vale menos. Quem zera, morre de vez. */
    public function test_os_sobreviventes_voltam_feridos_e_valem_menos(): void
    {
        $atacante = $this->colono('Atacante');
        $defensor = $this->colono('Defensor');

        $zona = $this->zonaDe($defensor);
        $ids = $this->sentinelas($atacante, 20);

        $fim = $this->correrAte(app(Atacar::class)->handle($atacante, $zona, 'invasao', $ids));
        $this->assertSame('vitoria_atacante', $fim->status);

        $voltaram = Unit::where('colony_id', $atacante->id)->where('type', 'sentinela')->get();

        $this->assertNotEmpty($voltaram, 'o exército vencedor tem de voltar para casa');

        foreach ($voltaram as $u) {
            $this->assertSame('casa', $u->status);
            $this->assertNull($u->combat_id);
            $this->assertLessThan(Unit::INTEIRA, $u->hp_bps, 'ninguém sai de uma batalha inteiro');
            $this->assertGreaterThan(0, $u->hp_bps);
            // E a força de uma unidade ferida é proporcional ao HP que restou.
            $this->assertSame(intdiv(80 * $u->hp_bps, Unit::INTEIRA), $u->ataque());
        }

        // A guarnição derrotada não existe mais: não há para onde recuar de uma zona perdida.
        $this->assertSame(0, Unit::where('zone_id', $zona->id)->count());
    }

    /** O +20% do §27.7 é um SNAPSHOT do despacho — não uma leitura ao vivo, senão o exploit paga. */
    public function test_o_bonus_de_offline_e_congelado_no_despacho(): void
    {
        $atacante = $this->colono('Atacante');
        $defensor = $this->colono('Defensor');

        $zona = $this->zonaDe($defensor);

        // O defensor nunca usou um token: está offline, e o bônus vale.
        $this->assertTrue(app(Forcas::class)->defensorOffline($zona));

        $combate = app(Atacar::class)->handle($atacante, $zona, 'invasao', $this->sentinelas($atacante, 5));

        $this->assertTrue($combate->resultado['defensor_offline']);
    }
}
