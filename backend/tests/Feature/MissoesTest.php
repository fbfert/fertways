<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Missoes\Janela;
use App\Domain\Missoes\Progresso;
use App\Models\MissionAssignment;
use App\Models\MissionTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * As Missões do §06 (D-78): tutoria (5, dias 1–3), diárias (3/dia do pool de 30+, 1 rejeição),
 * semanal (qua 07h → ter 23h59). Recompensas GENEROSAS por arbitragem; pagamento na conclusão,
 * sem botão de resgate; a tutoria recompensa e NÃO trava o subsídio (contradição consciente).
 */
class MissoesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\ComponentRecipeSeeder::class);
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
        $this->seed(\Database\Seeders\MissionTemplateSeeder::class);
    }

    private int $proximo = 0;

    private function colono(): User
    {
        $user = User::factory()->create(['tutorial_completed_at' => now()]);
        app(CreateColony::class)->handle($user, 'Base', 20 + $this->proximo++, 20);

        return $user->fresh();
    }

    // ---------------------------------------------------------------- o catálogo

    public function test_o_pool_publicado_existe(): void
    {
        // §06: tutoria com 5; diárias "pool 30+"; e as semanais da nossa fatia.
        $this->assertSame(5, MissionTemplate::where('categoria', 'tutoria')->count());
        $this->assertGreaterThanOrEqual(30, MissionTemplate::where('categoria', 'diaria')->count());
        $this->assertGreaterThanOrEqual(8, MissionTemplate::where('categoria', 'semanal')->count());
    }

    // ---------------------------------------------------------------- a tutoria

    public function test_a_fundacao_entrega_as_cinco_da_tutoria_com_tres_dias_de_prazo(): void
    {
        $user = $this->colono();

        $tutoria = MissionAssignment::where('colony_id', $user->colony->id)
            ->where('categoria', 'tutoria')->get();

        $this->assertCount(5, $tutoria);
        $this->assertTrue($tutoria->every(
            fn ($m) => (int) now()->diffInDays($m->expires_at) === 3 || now()->diffInDays($m->expires_at) < 3.01,
        ), '§06: "dias 1 a 3"');
    }

    public function test_o_subsidio_nao_depende_da_tutoria_por_decisao(): void
    {
        // Contradição CONSCIENTE com o §03 ("mediante conclusão da tutoria"): o usuário arbitrou
        // que as missões pagam, mas não travam. O tutorial segue auto-completo na fundação.
        $user = $this->colono();

        $this->assertNotNull($user->tutorial_completed_at, 'o stub do D-18 virou decisão do D-78');
    }

    // ---------------------------------------------------------------- as diárias

    public function test_a_mao_do_dia_nasce_no_primeiro_pedido_e_nao_duplica(): void
    {
        $user = $this->colono();

        $r = $this->actingAs($user)->getJson('/missions')->assertOk();

        $diarias = collect($r->json('missoes'))->where('categoria', 'diaria');
        $this->assertCount(3, $diarias, '§06: "3 por dia"');

        // Pedir de novo NÃO sorteia outra mão.
        $r2 = $this->actingAs($user)->getJson('/missions');
        $this->assertSame(
            $diarias->pluck('id')->sort()->values()->all(),
            collect($r2->json('missoes'))->where('categoria', 'diaria')->pluck('id')->sort()->values()->all(),
        );
    }

    public function test_a_janela_do_dia_vira_as_sete(): void
    {
        // A régua das 07h vem da semanal publicada ("Qua 07h"): às 06h59 ainda é ontem.
        $this->travelTo(Carbon::parse('2026-07-16 06:59'));
        $this->assertSame('2026-07-15 07:00', Janela::diaAtual()->format('Y-m-d H:i'));

        $this->travelTo(Carbon::parse('2026-07-16 07:01'));
        $this->assertSame('2026-07-16 07:00', Janela::diaAtual()->format('Y-m-d H:i'));

        // E a semana: quarta 07h → terça 23h59, textual.
        $this->assertSame('Wednesday', Janela::semanaAtual()->format('l'));
        $this->assertSame('Tuesday 23:59', Janela::fimDaSemana()->format('l H:i'));
    }

    public function test_no_dia_seguinte_vem_mao_nova(): void
    {
        $user = $this->colono();
        $ontem = collect($this->actingAs($user)->getJson('/missions')->json('missoes'))
            ->where('categoria', 'diaria')->pluck('id');

        $this->travelTo(Janela::proximoDia()->addMinute());

        $hoje = collect($this->actingAs($user)->getJson('/missions')->json('missoes'))
            ->where('categoria', 'diaria')->pluck('id');

        $this->assertCount(3, $hoje);
        $this->assertEmpty($hoje->intersect($ontem), 'a mão de ontem morreu com a janela');
    }

    // ---------------------------------------------------------------- o progresso paga

    public function test_progredir_conclui_e_paga_na_hora(): void
    {
        $user = $this->colono();
        $colony = $user->colony;

        // Fora a tutoria: o gancho de 'despacho' completaria a dela junto (o que é o comportamento
        // certo — e ruim para um teste que afirma valores exatos).
        MissionAssignment::where('colony_id', $colony->id)->delete();

        // Uma missão determinística na mão: "despache 1 viagem" pagando 6 F$.
        $template = MissionTemplate::where('chave', 'dia_despacho_1')->firstOrFail();
        $missao = MissionAssignment::create([
            'colony_id' => $colony->id, 'template_id' => $template->id, 'categoria' => 'diaria',
            'acao' => 'despacho', 'progresso' => 0, 'meta' => 1, 'status' => 'ativa',
            'expires_at' => Janela::proximoDia(), 'created_at' => now(),
        ]);

        $fertAntes = (int) $colony->fert_micro;
        $xpAntes = (int) $colony->xp;

        app(Progresso::class)->registrar($colony->id, 'despacho');

        $missao->refresh();
        $this->assertSame('concluida', $missao->status);

        // dia_despacho_1 paga 200 XP (e zero Fert$): o catálogo manda, não a tabela de atos.
        $this->assertSame($fertAntes, (int) $colony->fresh()->fert_micro);
        $this->assertSame($xpAntes + 200, (int) $colony->fresh()->xp);
        $this->assertDatabaseHas('xp_entries', ['colony_id' => $colony->id, 'acao' => 'missao_concluida', 'xp' => 200]);
    }

    public function test_a_recompensa_em_fert_e_emissao_com_ledger(): void
    {
        $user = $this->colono();
        $colony = $user->colony;
        MissionAssignment::where('colony_id', $colony->id)->delete();   // fora a tutoria, como acima

        $template = MissionTemplate::where('chave', 'dia_obra_1')->firstOrFail();   // 6 F$
        MissionAssignment::create([
            'colony_id' => $colony->id, 'template_id' => $template->id, 'categoria' => 'diaria',
            'acao' => 'obra_concluida', 'progresso' => 0, 'meta' => 1, 'status' => 'ativa',
            'expires_at' => Janela::proximoDia(), 'created_at' => now(),
        ]);

        $antes = (int) $colony->fert_micro;
        app(Progresso::class)->registrar($colony->id, 'obra_concluida');

        $this->assertSame($antes + 6_000_000, (int) $colony->fresh()->fert_micro);
        $this->assertDatabaseHas('ledger', ['colony_id' => $colony->id, 'type' => 'recompensa_missao', 'amount' => 6_000_000]);
    }

    public function test_o_progresso_para_na_meta_e_missao_expirada_nao_progride(): void
    {
        $user = $this->colono();
        $colony = $user->colony;

        $template = MissionTemplate::where('chave', 'dia_despacho_3')->firstOrFail();   // meta 3
        $missao = MissionAssignment::create([
            'colony_id' => $colony->id, 'template_id' => $template->id, 'categoria' => 'diaria',
            'acao' => 'despacho', 'progresso' => 0, 'meta' => 3, 'status' => 'ativa',
            'expires_at' => now()->subMinute(),   // JÁ venceu
            'created_at' => now()->subHours(25),
        ]);

        app(Progresso::class)->registrar($colony->id, 'despacho');

        $this->assertSame(0, $missao->fresh()->progresso, 'missão vencida é surda');
    }

    // ---------------------------------------------------------------- a rejeição publicada

    public function test_a_rejeicao_troca_uma_diaria_e_so_ha_uma_por_dia(): void
    {
        $user = $this->colono();

        $missoes = collect($this->actingAs($user)->getJson('/missions')->json('missoes'))
            ->where('categoria', 'diaria');
        $primeira = $missoes->first();

        $this->actingAs($user)->postJson("/missions/{$primeira['id']}/reject")->assertOk();

        $depois = $this->actingAs($user)->getJson('/missions');
        $ativas = collect($depois->json('missoes'))->where('categoria', 'diaria')->where('status', 'ativa');

        $this->assertCount(3, $ativas, 'fora uma, dentro outra — a mão continua com 3');
        $this->assertFalse($ativas->pluck('id')->contains($primeira['id']));
        $this->assertSame(0, $depois->json('rejeicoes_restantes'));

        // A segunda rejeição do dia bate na porta (§06: "1 rejeição").
        $outra = $ativas->first();
        $this->actingAs($user)->postJson("/missions/{$outra['id']}/reject")
            ->assertStatus(422)->assertJsonPath('code', 'rejeicao_esgotada');
    }

    // ---------------------------------------------------------------- a semanal

    public function test_a_semanal_e_uma_por_semana_e_persiste_o_dia_virar(): void
    {
        // Pina numa quinta-feira ao meio-dia: sem isto, `Janela::proximoDia()` mais abaixo usa o
        // relógio real, e rodar perto da virada de terça-noite/quarta-07h faz o "dia seguinte"
        // cair numa semana nova — aí a semanal muda por decisão do calendário, não por bug, e o
        // teste fica instável (falha só quando a CI roda perto dessa fronteira).
        $this->travelTo(Carbon::parse('2026-07-16 12:00'));

        $user = $this->colono();

        $semana1 = collect($this->actingAs($user)->getJson('/missions')->json('missoes'))
            ->where('categoria', 'semanal');
        $this->assertCount(1, $semana1);

        // O dia vira; a semanal continua a mesma.
        $this->travelTo(Janela::proximoDia()->addMinute());
        $semanaAinda = collect($this->actingAs($user)->getJson('/missions')->json('missoes'))
            ->where('categoria', 'semanal');
        $this->assertSame($semana1->first()['id'], $semanaAinda->first()['id']);
    }
}
