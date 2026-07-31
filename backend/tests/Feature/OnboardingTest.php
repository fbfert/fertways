<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Missoes\Atribuir;
use App\Domain\Missoes\Progresso;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\MissionAssignment;
use App\Models\MissionTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Onboarding produtivo (A2.1).
 *
 * A fase tinha duas adaptações previstas no roadmap, e o confronto com o código mudou o tamanho das
 * duas: **não existe recusa** no motor (o que dispensava a tutoria era ela EXPIRAR em 3 dias), e o
 * encadeamento sem expiração **já existia** na categoria `narrativa` (D-140). Sobrou uma coluna e a
 * varredura de grandfathering — que é o que estes testes guardam.
 */
class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
        $this->seed(\Database\Seeders\MissionTemplateSeeder::class);
    }

    private int $proximoSlot = 3;

    private function colonia(string $nick): Colony
    {
        $u = User::create([
            'name' => $nick, 'nickname' => $nick,
            'email' => $nick.'@fertways.test', 'password' => Hash::make('segredo-forte-123'),
        ]);

        return app(CreateColony::class)->handle($u, "Colônia {$nick}", 0, $this->proximoSlot++);
    }

    private function tutoria(Colony $c)
    {
        return MissionAssignment::where('colony_id', $c->id)->where('categoria', 'tutoria');
    }

    // ─────────────────────────────────────────────────────── o catálogo

    public function test_a_tutoria_e_uma_cadeia_e_nao_uma_lista_plana(): void
    {
        $t = MissionTemplate::where('categoria', 'tutoria')->orderBy('id')->get();

        $this->assertCount(5, $t);
        $this->assertNull($t[0]->requer_template_id, 'o primeiro degrau não depende de nada');

        foreach ([1, 2, 3, 4] as $i) {
            $this->assertSame(
                $t[$i - 1]->id, $t[$i]->requer_template_id,
                "o degrau {$i} tem que exigir o anterior",
            );
        }
    }

    /**
     * A regra que decide o que é obrigatório.
     *
     * Só pode ser obrigatória a etapa que um jogador **sozinho** conclui. O roadmap exige que o
     * tutorial não dependa de oferta real de outro jogador — e num servidor recém-aberto não há
     * outro jogador. Travar o onboarding numa compra que precisa de contraparte prenderia o colono
     * numa porta que não depende dele.
     */
    public function test_so_e_obrigatorio_o_que_se_faz_sozinho(): void
    {
        $obrigatorias = MissionTemplate::where('categoria', 'tutoria')
            ->where('obrigatoria', true)->pluck('chave')->all();

        $this->assertSame(['tut_primeira_obra', 'tut_primeiro_despacho'], $obrigatorias);

        $mercado = MissionTemplate::where('chave', 'tut_primeiro_lote')->firstOrFail();
        $this->assertFalse(
            (bool) $mercado->obrigatoria,
            'comprar no Mercado exige contraparte: não pode travar o onboarding',
        );
    }

    /** Nenhuma outra categoria virou obrigatória por engano — o default é `false` e tem que ficar. */
    public function test_nenhuma_diaria_ou_semanal_e_obrigatoria(): void
    {
        $this->assertSame(0, MissionTemplate::whereIn('categoria', ['diaria', 'semanal', 'federacao', 'narrativa'])
            ->where('obrigatoria', true)->count());
    }

    // ─────────────────────────────────────────────────────── a entrega

    public function test_a_fundacao_entrega_so_o_primeiro_degrau(): void
    {
        $c = $this->colonia('ana');

        $this->assertSame(1, $this->tutoria($c)->count());
        $this->assertSame(
            'obra_concluida',
            $this->tutoria($c)->firstOrFail()->acao,
        );
    }

    /**
     * A tutoria **não expira mais**, e a razão é dupla.
     *
     * Uma etapa que o colono é obrigado a cumprir e que some sozinha em 3 dias é uma contradição. E
     * expirar o meio de uma sequência deixaria o colono encalhado: o degrau seguinte só chega
     * quando o anterior conclui, então um degrau expirado tranca a escada inteira.
     */
    public function test_a_tutoria_nao_expira(): void
    {
        $c = $this->colonia('bia');

        $this->assertNull($this->tutoria($c)->firstOrFail()->expires_at);
    }

    public function test_o_degrau_seguinte_so_chega_depois_do_anterior(): void
    {
        $c = $this->colonia('cid');
        $atribuir = app(Atribuir::class);

        $atribuir->garantirEncadeada($c, 'tutoria');
        $this->assertSame(1, $this->tutoria($c)->count(), 'sem concluir, nada de novo chega');

        app(Progresso::class)->registrar($c->id, 'obra_concluida');
        $atribuir->garantirEncadeada($c, 'tutoria');

        $this->assertSame(2, $this->tutoria($c)->count());
        $this->assertSame('despacho', $this->tutoria($c)->orderByDesc('id')->firstOrFail()->acao);
    }

    // ───────────────────────────────────────────────── o grandfathering

    /**
     * A razão de o comando existir.
     *
     * Sem ele, o motor entregaria a `tut_primeira_obra` a quem já ergueu cinquenta níveis, ela
     * concluiria no primeiro tick, e a recompensa cairia no ledger — corretamente registrada, o que
     * é o pior do problema, porque o ledger é append-only e não se desfaz.
     */
    public function test_grandfather_marca_tudo_como_concluido(): void
    {
        $c = $this->colonia('velha');

        $this->artisan('fertways:onboarding-grandfather', ['--aplicar' => true])->assertSuccessful();

        $this->assertSame(5, $this->tutoria($c)->count());
        $this->assertSame(5, $this->tutoria($c)->where('status', 'concluida')->count());
    }

    /** O ponto do comando: marcar como visto NÃO é cumprir, e não paga. */
    public function test_grandfather_nao_paga_recompensa(): void
    {
        $c = $this->colonia('semgrana');
        $fertAntes = $c->fresh()->fert_micro;
        $ledgerAntes = Ledger::where('colony_id', $c->id)->count();

        $this->artisan('fertways:onboarding-grandfather', ['--aplicar' => true])->assertSuccessful();

        $this->assertSame($fertAntes, $c->fresh()->fert_micro, 'nenhum Fert$ foi emitido');
        $this->assertSame($ledgerAntes, Ledger::where('colony_id', $c->id)->count(),
            'nenhum lançamento novo no ledger');
    }

    public function test_grandfather_sem_aplicar_nao_muda_nada(): void
    {
        $c = $this->colonia('seca');

        $this->artisan('fertways:onboarding-grandfather')->assertSuccessful();

        $this->assertSame(1, $this->tutoria($c)->count(), 'continua só o degrau da fundação');
    }

    /** Rodar duas vezes não pode duplicar linha nem "concluir" de novo o que já estava. */
    public function test_grandfather_e_idempotente(): void
    {
        $c = $this->colonia('duasvezes');

        $this->artisan('fertways:onboarding-grandfather', ['--aplicar' => true])->assertSuccessful();
        $this->artisan('fertways:onboarding-grandfather', ['--aplicar' => true])->assertSuccessful();

        $this->assertSame(5, $this->tutoria($c)->count());
    }

    /**
     * O corte protege quem chegou depois.
     *
     * Uma colônia fundada DEPOIS da varredura tem de fazer o onboarding de verdade e receber as
     * recompensas de verdade — senão o grandfathering, que existe para não emitir dinheiro à toa,
     * passaria a roubar o iniciante.
     */
    public function test_quem_chega_depois_do_corte_faz_o_onboarding_de_verdade(): void
    {
        $antiga = $this->colonia('antiga');
        $this->artisan('fertways:onboarding-grandfather', ['--aplicar' => true])->assertSuccessful();

        $nova = $this->colonia('nova');

        $this->assertSame(5, $this->tutoria($antiga)->where('status', 'concluida')->count());
        $this->assertSame(1, $this->tutoria($nova)->count());
        $this->assertSame('ativa', $this->tutoria($nova)->firstOrFail()->status);
    }
}
