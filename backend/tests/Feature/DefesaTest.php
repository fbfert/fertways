<?php

namespace Tests\Feature;

use App\Domain\Chat\ContaSistema;
use App\Domain\Colony\CreateColony;
use App\Domain\Guerra\Atacar;
use App\Domain\Guerra\ChegarReforcos;
use App\Domain\Guerra\Reforcar;
use App\Domain\Guerra\ResolverCombates;
use App\Domain\Guerra\RomperCerco;
use App\Exceptions\DomainRuleException;
use App\Models\ChatMessage;
use App\Models\Colony;
use App\Models\Combat;
use App\Models\NeutralZone;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O DEFENSOR enfim tem o que fazer (GDD §27.5, §28.10; docs/decisoes.md D-70).
 *
 * Dois buracos, e o primeiro era pior do que uma falta:
 *
 *  1. **A tela do Quartel prometia ao defensor "ainda dá tempo de reforçar a zona"** — e não havia
 *     rota, nem botão, nem domínio. O motor **já contava** reforços desde o D-66 (ele recongela a
 *     força a cada chegada) e ninguém podia mandá-los. Era uma promessa sem nada por trás.
 *  2. **Não se podia romper um cerco.** O §28.10 dá ao sitiado DUAS saídas — "romper o cerco ou
 *     render-se" — e o jogo só tinha uma: esperar as 48 h e entregar 30%.
 */
class DefesaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\ComponentRecipeSeeder::class);
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
    }

    private const CELULAS = [[20, 20], [-20, 20], [20, -20], [-20, -20]];

    private int $proxima = 0;

    private function colono(string $nome): Colony
    {
        [$x, $y] = self::CELULAS[$this->proxima++];
        $c = app(CreateColony::class)->handle(User::factory()->create(), $nome, $x, $y);
        $c->update(['fert_micro' => 10_000 * 1_000_000]);

        return $c->fresh();
    }

    private function zonaDe(Colony $dono, int $robos = 20, array $extra = []): NeutralZone
    {
        $z = NeutralZone::create(array_merge([
            'x' => 47, 'y' => 47, 'district' => 'NE', 'mineral' => 'metal_bruto', 'level' => 1,
            'owner_colony_id' => $dono->id, 'status' => 'ocupada',
            'occupied_at' => now()->subDays(30), 'protected_until' => now()->subDays(20),
            'command_post_level' => 1, 'productive_at' => now()->subDays(20),
            'deposit_level' => 1, 'deposit_amount' => 0, 'last_extraction_at' => now(),
        ], $extra));

        for ($i = 0; $i < $robos; $i++) {
            Unit::create([
                'zone_id' => $z->id, 'type' => 'robo_minerador',
                'level' => 1, 'hp_bps' => Unit::INTEIRA, 'status' => 'na_zona',
            ]);
        }

        return $z->fresh();
    }

    /** @return list<int> */
    private function sentinelas(Colony $c, int $n): array
    {
        return collect(range(1, $n))->map(fn () => Unit::create([
            'colony_id' => $c->id, 'type' => 'sentinela',
            'level' => 1, 'hp_bps' => Unit::INTEIRA, 'status' => 'casa',
        ])->id)->all();
    }

    private function correr(Combat $c, int $maxHoras = 72): Combat
    {
        $motor = app(ResolverCombates::class);
        $reforcos = app(ChegarReforcos::class);

        for ($i = 0; $i < $maxHoras * 6; $i++) {
            $c->refresh();
            if (! $c->vivo()) {
                break;
            }
            $this->travelTo(now()->addMinutes(Combat::RODADA_MINUTOS));
            $reforcos->handle(now());
            $motor->handle(now());
        }

        return $c->fresh();
    }

    // ── reforçar ────────────────────────────────────────────────────────────────────────────────

    /**
     * **O teste que dá corpo à promessa do §27.5.** O reforço que chega a tempo MUDA o resultado.
     *
     * O documento diz que o combate equilibrado dura ~2 h de propósito, "tempo suficiente para o
     * defensor receber notificação, recrutar reforços e despachá-los" — e que "reforços tardios podem
     * ainda mudar o resultado". Até o D-70 isso era literatura: não havia como mandar ninguém.
     *
     * Aqui, o mesmo ataque que TOMA a zona sem socorro é REPELIDO com ele.
     */
    public function test_o_reforco_que_chega_a_tempo_muda_o_resultado(): void
    {
        $atacante = $this->colono('Atacante');
        $defensor = $this->colono('Defensor');

        // Sem socorro: 10 Sentinelas (800 de ataque) contra 20 robôs (500 de defesa). O atacante ganha.
        $zona = $this->zonaDe($defensor);
        $combate = app(Atacar::class)->handle($atacante, $zona, 'invasao', $this->sentinelas($atacante, 10));

        $fim = $this->correr($combate);
        $this->assertSame('vitoria_atacante', $fim->status, 'sem socorro, a zona cai');
        $this->assertSame($atacante->id, $zona->fresh()->owner_colony_id);
    }

    public function test_com_reforco_o_mesmo_ataque_e_repelido(): void
    {
        $atacante = $this->colono('Atacante');
        $defensor = $this->colono('Defensor');

        $zona = $this->zonaDe($defensor);
        $combate = app(Atacar::class)->handle($atacante, $zona, 'invasao', $this->sentinelas($atacante, 10));

        // O defensor vê o exército a caminho e despacha 12 Sentinelas (1.200 de defesa).
        $marcharam = app(Reforcar::class)->handle($defensor, $zona, $this->sentinelas($defensor, 12));
        $this->assertSame(12, $marcharam);

        // ⚠️ Elas NÃO contam enquanto marcham: só entram na Força Defensiva quando CHEGAM.
        $this->assertSame(
            0,
            Unit::where('zone_id', $zona->id)->where('status', 'na_zona')->where('type', 'sentinela')->count(),
        );

        $fim = $this->correr($combate);

        $this->assertSame('repelido', $fim->status, 'com socorro, o ataque é repelido');
        $this->assertSame($defensor->id, $zona->fresh()->owner_colony_id, 'a zona continua dele');
    }

    /** Uma tropa a caminho não defende nada. É essa distinção que faz o combate ser uma corrida. */
    public function test_o_reforco_marchando_nao_conta_na_forca(): void
    {
        $defensor = $this->colono('Defensor');
        $zona = $this->zonaDe($defensor, robos: 4);   // 4 × 25 = 100 de defesa

        $forcas = app(\App\Domain\Guerra\Forcas::class);
        $this->assertSame(100, $forcas->defensiva($zona));

        app(Reforcar::class)->handle($defensor, $zona, $this->sentinelas($defensor, 5));

        // Marchando: a força não mudou.
        $this->assertSame(100, $forcas->defensiva($zona->fresh()));

        // Chegaram: +500 (5 Sentinelas × 100 de defesa).
        $this->travelTo(now()->addHours(3));
        app(ChegarReforcos::class)->handle();

        $this->assertSame(600, $forcas->defensiva($zona->fresh()));
    }

    public function test_nao_se_reforca_zona_alheia(): void
    {
        $a = $this->colono('A');
        $b = $this->colono('B');
        $zona = $this->zonaDe($b);

        $this->expectException(DomainRuleException::class);
        app(Reforcar::class)->handle($a, $zona, $this->sentinelas($a, 1));
    }

    /**
     * ⚠️ **Cercada, o reforço NÃO passa** — e é a mordida mais dura do cerco.
     *
     * "Nada entra nem sai" (§28.10) alcança as tropas: quem está sitiado não recebe socorro. A única
     * saída é **romper**. Se o reforço entrasse, o cerco seria decorativo.
     */
    public function test_sob_cerco_o_reforco_nao_entra(): void
    {
        $defensor = $this->colono('Defensor');
        $zona = $this->zonaDe($defensor, 20, ['sieged_at' => now()]);

        $this->expectException(DomainRuleException::class);
        $this->expectExceptionMessageMatches('/cercada|Rompa/');

        app(Reforcar::class)->handle($defensor, $zona, $this->sentinelas($defensor, 5));
    }

    // ── romper o cerco ──────────────────────────────────────────────────────────────────────────

    /**
     * **O sitiado enfim pode lutar.** O §28.10 dá duas saídas — "romper o cerco ou render-se" — e o
     * jogo só tinha a segunda.
     */
    public function test_o_socorro_forte_rompe_o_cerco(): void
    {
        $sitiante = $this->colono('Sitiante');
        $defensor = $this->colono('Defensor');

        $zona = $this->zonaDe($defensor);

        // O cerco chega com 3 Sentinelas (300 de defesa em campo aberto).
        $cerco = app(Atacar::class)->handle($sitiante, $zona, 'cerco', $this->sentinelas($sitiante, 3));

        $this->travelTo(now()->addMinutes(30));
        app(ResolverCombates::class)->handle(now());

        $this->assertTrue($zona->fresh()->cercada());
        $cerco->refresh();
        $this->assertSame('em_curso', $cerco->status);

        // O socorro sai com 10 Sentinelas (800 de ataque). Em campo aberto, sem bônus de construção.
        $ruptura = app(RomperCerco::class)->handle($defensor, $cerco, $this->sentinelas($defensor, 10));

        $fim = $this->correr($ruptura, 12);

        $this->assertSame('vitoria_atacante', $fim->status, 'o socorro venceu');
        $this->assertFalse($zona->fresh()->cercada(), 'o cerco foi levantado');
        $this->assertSame('repelido', $cerco->fresh()->status);
        // O exército sitiante morreu: ele estava em campo aberto e perdeu.
        $this->assertSame(0, Unit::where('combat_id', $cerco->id)->count());
    }

    /** Falhar em romper custa caro: o socorro morre e **o cerco continua**. O relógio não parou. */
    public function test_o_socorro_fraco_morre_e_o_cerco_continua(): void
    {
        $sitiante = $this->colono('Sitiante');
        $defensor = $this->colono('Defensor');

        $zona = $this->zonaDe($defensor);
        $cerco = app(Atacar::class)->handle($sitiante, $zona, 'cerco', $this->sentinelas($sitiante, 12));

        $this->travelTo(now()->addMinutes(30));
        app(ResolverCombates::class)->handle(now());

        // Um socorro de uma Sentinela contra doze. Não dá.
        $ruptura = app(RomperCerco::class)->handle($defensor, $cerco, $this->sentinelas($defensor, 1));

        $fim = $this->correr($ruptura, 24);

        $this->assertSame('repelido', $fim->status, 'o socorro foi destruído');
        $this->assertTrue($zona->fresh()->cercada(), 'e o cerco CONTINUA');
        $this->assertTrue($cerco->fresh()->vivo());
    }

    /** Uma força de socorro por vez: duas seriam duas contas contra o mesmo exército. */
    public function test_uma_ruptura_por_vez(): void
    {
        $sitiante = $this->colono('Sitiante');
        $defensor = $this->colono('Defensor');

        $zona = $this->zonaDe($defensor);
        $cerco = app(Atacar::class)->handle($sitiante, $zona, 'cerco', $this->sentinelas($sitiante, 3));

        $this->travelTo(now()->addMinutes(30));
        app(ResolverCombates::class)->handle(now());

        app(RomperCerco::class)->handle($defensor, $cerco->fresh(), $this->sentinelas($defensor, 2));

        $this->expectException(DomainRuleException::class);
        app(RomperCerco::class)->handle($defensor, $cerco->fresh(), $this->sentinelas($defensor, 2));
    }

    /** Só o SITIADO (ou, desde o D-115, um aliado da federação dele) rompe. Um terceiro não se mete. */
    public function test_so_o_sitiado_rompe(): void
    {
        $sitiante = $this->colono('Sitiante');
        $defensor = $this->colono('Defensor');
        $terceiro = $this->colono('Terceiro');

        $zona = $this->zonaDe($defensor);
        $cerco = app(Atacar::class)->handle($sitiante, $zona, 'cerco', $this->sentinelas($sitiante, 3));

        $this->travelTo(now()->addMinutes(30));
        app(ResolverCombates::class)->handle(now());

        $this->expectException(DomainRuleException::class);
        app(RomperCerco::class)->handle($terceiro, $cerco->fresh(), $this->sentinelas($terceiro, 5));
    }

    /**
     * Federação aliada (§28.10, D-115): quem está na MESMA federação do sitiado também rompe — com
     * as PRÓPRIAS Sentinelas, de casa. Quem lutou, ganha: o crédito de XP/missão vai para quem
     * mandou o socorro, não necessariamente para o dono da zona.
     */
    public function test_aliado_da_mesma_federacao_tambem_rompe_o_cerco(): void
    {
        $sitiante = $this->colono('Sitiante');
        $defensor = $this->colono('Defensor');
        $aliado = $this->colono('Aliado');

        $fed = \App\Models\Federation::create(['name' => 'Aliança']);
        $defensor->update(['federation_id' => $fed->id, 'federation_role' => \App\Models\Federation::LIDER]);
        $aliado->update(['federation_id' => $fed->id, 'federation_role' => \App\Models\Federation::MEMBRO]);

        $zona = $this->zonaDe($defensor);
        $cerco = app(Atacar::class)->handle($sitiante, $zona, 'cerco', $this->sentinelas($sitiante, 3));

        $this->travelTo(now()->addMinutes(30));
        app(ResolverCombates::class)->handle(now());

        // O socorro sai da casa do ALIADO, não do sitiado.
        $ruptura = app(RomperCerco::class)->handle($aliado, $cerco->fresh(), $this->sentinelas($aliado, 10));

        $fim = $this->correr($ruptura, 12);

        $this->assertSame('vitoria_atacante', $fim->status);
        $this->assertFalse($zona->fresh()->cercada(), 'o cerco foi levantado pelo aliado');
        $this->assertSame($aliado->id, $ruptura->attacker_colony_id, 'quem lutou, ganha o crédito');
    }

    /** Federação DIFERENTE (ou nenhuma) continua de fora — a exceção é só para aliados de verdade. */
    public function test_colonia_de_outra_federacao_nao_rompe(): void
    {
        $sitiante = $this->colono('Sitiante');
        $defensor = $this->colono('Defensor');
        $estranho = $this->colono('Estranho');

        $fedA = \App\Models\Federation::create(['name' => 'A']);
        $fedB = \App\Models\Federation::create(['name' => 'B']);
        $defensor->update(['federation_id' => $fedA->id, 'federation_role' => \App\Models\Federation::LIDER]);
        $estranho->update(['federation_id' => $fedB->id, 'federation_role' => \App\Models\Federation::LIDER]);

        $zona = $this->zonaDe($defensor);
        $cerco = app(Atacar::class)->handle($sitiante, $zona, 'cerco', $this->sentinelas($sitiante, 3));

        $this->travelTo(now()->addMinutes(30));
        app(ResolverCombates::class)->handle(now());

        $this->expectException(DomainRuleException::class);
        app(RomperCerco::class)->handle($estranho, $cerco->fresh(), $this->sentinelas($estranho, 5));
    }

    // ── a Central de Comunicação avisa a federação (D-116) ─────────────────────────────────────────

    public function test_a_central_avisa_todos_os_membros_da_federacao_quando_o_cerco_comeca(): void
    {
        $sitiante = $this->colono('Sitiante');
        $defensor = $this->colono('Defensor');
        $aliado = $this->colono('Aliado');

        $fed = \App\Models\Federation::create(['name' => 'Aliança']);
        $defensor->update(['federation_id' => $fed->id, 'federation_role' => \App\Models\Federation::LIDER]);
        $aliado->update(['federation_id' => $fed->id, 'federation_role' => \App\Models\Federation::MEMBRO]);

        $zona = $this->zonaDe($defensor, extra: ['communication_level' => 1]);
        app(Atacar::class)->handle($sitiante, $zona, 'cerco', $this->sentinelas($sitiante, 3));

        $this->travelTo(now()->addMinutes(30));
        app(ResolverCombates::class)->handle(now());

        $federacao = ContaSistema::federacao();

        $this->assertDatabaseHas('chat_messages', [
            'user_id' => $federacao->id,
            'recipient_user_id' => $defensor->user_id,
        ]);
        $this->assertDatabaseHas('chat_messages', [
            'user_id' => $federacao->id,
            'recipient_user_id' => $aliado->user_id,
        ]);
        $corpo = ChatMessage::where('user_id', $federacao->id)->where('recipient_user_id', $aliado->user_id)->value('body');
        $this->assertStringContainsString('Defensor', $corpo);
        $this->assertStringContainsString('sob cerco', $corpo);
    }

    public function test_sem_central_de_nivel_1_o_cerco_nao_avisa_ninguem(): void
    {
        $sitiante = $this->colono('Sitiante');
        $defensor = $this->colono('Defensor');

        $fed = \App\Models\Federation::create(['name' => 'Aliança']);
        $defensor->update(['federation_id' => $fed->id, 'federation_role' => \App\Models\Federation::LIDER]);

        $zona = $this->zonaDe($defensor);   // communication_level 0 (padrão)
        app(Atacar::class)->handle($sitiante, $zona, 'cerco', $this->sentinelas($sitiante, 3));

        $this->travelTo(now()->addMinutes(30));
        app(ResolverCombates::class)->handle(now());

        $this->assertDatabaseHas('users', ['email' => ContaSistema::EMAIL_FEDERACAO]);
        $this->assertSame(0, ChatMessage::where('user_id', ContaSistema::federacao()->id)->count());
    }

    public function test_sem_federacao_o_cerco_com_central_nao_avisa_ninguem(): void
    {
        $sitiante = $this->colono('Sitiante');
        $defensor = $this->colono('Defensor');   // sem federação

        $zona = $this->zonaDe($defensor, extra: ['communication_level' => 1]);
        app(Atacar::class)->handle($sitiante, $zona, 'cerco', $this->sentinelas($sitiante, 3));

        $this->travelTo(now()->addMinutes(30));
        app(ResolverCombates::class)->handle(now());

        $this->assertSame(0, ChatMessage::where('user_id', ContaSistema::federacao()->id)->count());
    }

    // ── a API ───────────────────────────────────────────────────────────────────────────────────

    public function test_os_endpoints_de_defesa(): void
    {
        $atacante = $this->colono('Atacante');
        $defensor = $this->colono('Defensor');

        $zona = $this->zonaDe($defensor);
        app(Atacar::class)->handle($atacante, $zona, 'invasao', $this->sentinelas($atacante, 3));

        $this->actingAs($defensor->user)
            ->postJson('/war/reinforce', [
                'zone_id' => $zona->id,
                'unit_ids' => $this->sentinelas($defensor, 4),
            ])
            ->assertCreated()
            ->assertJson(['marcharam' => 4]);

        $this->assertSame(
            4,
            Unit::where('zone_id', $zona->id)->where('status', 'marchando')->count(),
        );
    }

    /** A tela precisa saber se a zona está cercada: é o que decide entre "reforçar" e "romper". */
    public function test_a_lista_de_combates_diz_se_a_zona_esta_cercada(): void
    {
        $sitiante = $this->colono('Sitiante');
        $defensor = $this->colono('Defensor');

        $zona = $this->zonaDe($defensor, 20, ['watchtower_level' => 5]);
        app(Atacar::class)->handle($sitiante, $zona, 'cerco', $this->sentinelas($sitiante, 3));

        $this->travelTo(now()->addMinutes(30));
        app(ResolverCombates::class)->handle(now());

        $this->actingAs($defensor->user)
            ->getJson('/war/combats')
            ->assertOk()
            ->assertJsonPath('combats.0.cercada', true);
    }

    // ── os parâmetros no painel (D-70) ──────────────────────────────────────────────────────────

    /**
     * ⚠️ **O `torre_aviso_minutos_por_nivel` não estava no `$fillable`** — e ninguém tinha notado,
     * porque até o D-70 nada o escrevia. O `update()` o descartaria **em silêncio**: o admin mudaria
     * o número, a tela diria "atualizado", e o valor continuaria o mesmo. Este teste é a rede.
     */
    public function test_o_painel_grava_os_onze_parametros_da_guerra(): void
    {
        $admin = \App\Models\Admin::create([
            'name' => 'Operador', 'email' => 'op@fertways.test',
            'password' => \Illuminate\Support\Facades\Hash::make('segredo-forte-1234'),
            'role' => \App\Models\Admin::OPERADOR,
        ]);

        $novos = [
            'muralha_bonus_bps' => 1500,
            'torre_bonus_bps' => 800,
            'bastiao_bonus_bps' => 2500,
            'torre_deteccao_bps_por_nivel' => 1200,
            'torre_aviso_minutos_por_nivel' => 25,
            'predador_base_bps' => 4000,
            'predador_por_nivel_bps' => 1500,
            'predador_min_bps' => 500,
            'predador_max_bps' => 9500,
            'niobio_preco_micro' => 7_500_000,
            'reparo_bps_do_custo' => 2000,
        ];

        $this->actingAs($admin, 'admin')
            ->post('/admin/guerra', $novos)
            ->assertRedirect();

        $config = \App\Models\WarSetting::singleton()->fresh();

        foreach ($novos as $campo => $valor) {
            $this->assertSame($valor, $config->$campo, "o painel não gravou {$campo}");
        }

        // E o motor lê o que o painel gravou — não a constante do seeder.
        $this->assertSame(7_500_000, app(\App\Domain\Guerra\Forcas::class)->config()->niobio_preco_micro);
    }

    /** Piso acima do teto prenderia a chance de apreensão num intervalo vazio. O painel recusa. */
    public function test_o_painel_recusa_um_teto_abaixo_do_piso(): void
    {
        $admin = \App\Models\Admin::create([
            'name' => 'Operador', 'email' => 'op@fertways.test',
            'password' => \Illuminate\Support\Facades\Hash::make('segredo-forte-1234'),
            'role' => \App\Models\Admin::OPERADOR,
        ]);

        $this->actingAs($admin, 'admin')
            ->post('/admin/guerra', [
                'muralha_bonus_bps' => 1000, 'torre_bonus_bps' => 500, 'bastiao_bonus_bps' => 2000,
                'torre_deteccao_bps_por_nivel' => 1000, 'torre_aviso_minutos_por_nivel' => 10,
                'predador_base_bps' => 5000, 'predador_por_nivel_bps' => 1000,
                'predador_min_bps' => 9000,   // piso acima do teto
                'predador_max_bps' => 1000,
                'niobio_preco_micro' => 1_000_000,
            ])
            ->assertSessionHasErrors('predador_max_bps');
    }

    public function test_a_aba_da_guerra_mostra_a_guerra(): void
    {
        $admin = \App\Models\Admin::create([
            'name' => 'Operador', 'email' => 'op@fertways.test',
            'password' => \Illuminate\Support\Facades\Hash::make('segredo-forte-1234'),
            'role' => \App\Models\Admin::OPERADOR,
        ]);

        $atacante = $this->colono('Atacante');
        $defensor = $this->colono('Defensor');
        $zona = $this->zonaDe($defensor);
        app(Atacar::class)->handle($atacante, $zona, 'invasao', $this->sentinelas($atacante, 3));

        $this->actingAs($admin, 'admin')
            ->get('/admin/guerra')
            ->assertOk()
            ->assertSee('Parâmetros da guerra')
            ->assertSee('Nióbio nas colônias')
            ->assertSee('Atacante');
    }
}
