<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Missoes\Atribuir;
use App\Domain\Missoes\Janela;
use App\Domain\Missoes\Progresso;
use App\Models\Colony;
use App\Models\Federation;
use App\Models\MissionAssignment;
use App\Models\MissionTemplate;
use App\Models\User;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\MissionTemplateSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Missões "Federação" (§06; docs/decisoes.md D-116, Fatia 3) — cooperativas, 2 por semana,
 * "irmãs" (uma linha POR COLÔNIA-MEMBRO, não uma linha compartilhada — ver `Atribuir::garantirFederacao()`).
 */
class MissoesFederacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
        $this->seed(ComponentRecipeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
        $this->seed(MissionTemplateSeeder::class);
    }

    private int $proximoSlot = 0;

    private function colonia(?Federation $fed = null, string $role = Federation::MEMBRO): Colony
    {
        $user = User::factory()->create(['tutorial_completed_at' => now()]);
        $colony = app(CreateColony::class)->handle($user, 'Base', 20 + $this->proximoSlot++, 20);

        if ($fed) {
            $colony->update(['federation_id' => $fed->id, 'federation_role' => $role]);
        }

        // Fora a tutoria/diária/semanal: os testes daqui afirmam contagens exatas de linhas 'federacao'.
        MissionAssignment::where('colony_id', $colony->id)->delete();

        return $colony->fresh();
    }

    public function test_quem_pede_primeiro_sorteia_para_todos_os_membros_atuais(): void
    {
        $fed = Federation::create(['name' => 'Aliança']);
        $a = $this->colonia($fed, Federation::LIDER);
        $b = $this->colonia($fed);

        app(Atribuir::class)->garantirFederacao($fed, $a);

        $linhasDeA = MissionAssignment::where('colony_id', $a->id)->where('categoria', 'federacao')->get();
        $linhasDeB = MissionAssignment::where('colony_id', $b->id)->where('categoria', 'federacao')->get();

        $this->assertCount(Atribuir::FEDERACAO_POR_SEMANA, $linhasDeA);
        $this->assertCount(Atribuir::FEDERACAO_POR_SEMANA, $linhasDeB, 'todo membro ATUAL ganha linha, não só quem pediu');
        $this->assertSame(
            $linhasDeA->pluck('template_id')->sort()->values()->all(),
            $linhasDeB->pluck('template_id')->sort()->values()->all(),
            'as duas colônias são irmãs dos mesmos templates'
        );
        $this->assertTrue($linhasDeA->every(fn (MissionAssignment $m) => $m->federation_id === $fed->id));
    }

    public function test_pedir_de_novo_na_mesma_semana_nao_duplica(): void
    {
        $fed = Federation::create(['name' => 'Aliança']);
        $a = $this->colonia($fed, Federation::LIDER);

        app(Atribuir::class)->garantirFederacao($fed, $a);
        app(Atribuir::class)->garantirFederacao($fed, $a);

        $this->assertCount(
            Atribuir::FEDERACAO_POR_SEMANA,
            MissionAssignment::where('colony_id', $a->id)->where('categoria', 'federacao')->get()
        );
    }

    public function test_colonia_sem_federacao_nunca_ve_categoria_federacao(): void
    {
        $solo = $this->colonia();

        $this->assertSame(0, MissionAssignment::where('colony_id', $solo->id)->where('categoria', 'federacao')->count());
    }

    public function test_quem_entra_no_meio_da_semana_ganha_linha_propria_com_progresso_ja_andado(): void
    {
        $fed = Federation::create(['name' => 'Aliança']);
        $a = $this->colonia($fed, Federation::LIDER);

        app(Atribuir::class)->garantirFederacao($fed, $a);

        $template = MissionAssignment::where('colony_id', $a->id)->where('categoria', 'federacao')->first()->template_id;
        MissionAssignment::where('colony_id', $a->id)->where('categoria', 'federacao')
            ->where('template_id', $template)->update(['progresso' => 1]);

        // b entra na federação DEPOIS do sorteio — não estava entre "os membros atuais" da primeira chamada.
        $b = $this->colonia($fed);
        app(Atribuir::class)->garantirFederacao($fed, $b);

        $linhaDeB = MissionAssignment::where('colony_id', $b->id)->where('categoria', 'federacao')
            ->where('template_id', $template)->first();

        $this->assertNotNull($linhaDeB);
        $this->assertSame(1, $linhaDeB->progresso, 'entra com o progresso já andado, não do zero');
        $this->assertCount(
            Atribuir::FEDERACAO_POR_SEMANA,
            MissionAssignment::where('colony_id', $b->id)->where('categoria', 'federacao')->get()
        );
    }

    public function test_nao_cria_linha_fantasma_para_template_ja_concluido(): void
    {
        $fed = Federation::create(['name' => 'Aliança']);
        $a = $this->colonia($fed, Federation::LIDER);

        app(Atribuir::class)->garantirFederacao($fed, $a);

        $templates = MissionAssignment::where('colony_id', $a->id)->where('categoria', 'federacao')
            ->pluck('template_id');
        $concluido = $templates->first();
        $aindaAtiva = $templates->last();

        MissionAssignment::where('colony_id', $a->id)->where('categoria', 'federacao')
            ->where('template_id', $concluido)
            ->update(['status' => 'concluida', 'concluded_at' => now()]);

        $b = $this->colonia($fed);
        app(Atribuir::class)->garantirFederacao($fed, $b);

        $this->assertNull(
            MissionAssignment::where('colony_id', $b->id)->where('template_id', $concluido)->first(),
            'a missão já decidida antes de eu chegar: eu simplesmente perco esta semana'
        );
        $this->assertNotNull(MissionAssignment::where('colony_id', $b->id)->where('template_id', $aindaAtiva)->first());
    }

    public function test_acao_de_um_membro_espelha_progresso_na_irma_e_paga_as_duas(): void
    {
        $fed = Federation::create(['name' => 'Aliança']);
        $a = $this->colonia($fed, Federation::LIDER);
        $b = $this->colonia($fed);

        $template = MissionTemplate::where('categoria', 'federacao')->where('chave', 'fed_logistica')->firstOrFail();
        $agora = now();
        $expira = Janela::fimDaSemana();

        $missaoA = MissionAssignment::create([
            'colony_id' => $a->id, 'federation_id' => $fed->id, 'template_id' => $template->id,
            'categoria' => 'federacao', 'acao' => 'despacho', 'progresso' => 0, 'meta' => $template->meta,
            'status' => 'ativa', 'expires_at' => $expira, 'created_at' => $agora,
        ]);
        $missaoB = MissionAssignment::create([
            'colony_id' => $b->id, 'federation_id' => $fed->id, 'template_id' => $template->id,
            'categoria' => 'federacao', 'acao' => 'despacho', 'progresso' => 0, 'meta' => $template->meta,
            'status' => 'ativa', 'expires_at' => $expira, 'created_at' => $agora,
        ]);

        // A colônia B faz UM despacho. O placar sobe para as DUAS, não só para quem agiu.
        app(Progresso::class)->registrar($b->id, 'despacho');

        $this->assertSame(1, $missaoA->fresh()->progresso, 'o feito é do grupo — a irmã também sobe');
        $this->assertSame(1, $missaoB->fresh()->progresso);

        // Zera o resto da meta de uma vez: as DUAS terminam e as DUAS são pagas.
        app(Progresso::class)->registrar($a->id, 'despacho', $template->meta);

        $missaoA->refresh();
        $missaoB->refresh();
        $this->assertSame('concluida', $missaoA->status);
        $this->assertSame('concluida', $missaoB->status, 'a irmã conclui junto, mesmo sem agir ela mesma');
        $this->assertSame($template->meta, $missaoA->progresso);
        $this->assertSame($template->meta, $missaoB->progresso);

        // Cada irmã paga a PRÓPRIA colônia — nada mudou em `pagar()`/`avisar()` para isso funcionar.
        $this->assertDatabaseHas('xp_entries', ['colony_id' => $a->id, 'acao' => 'missao_concluida', 'xp' => $template->recompensa_xp]);
        $this->assertDatabaseHas('xp_entries', ['colony_id' => $b->id, 'acao' => 'missao_concluida', 'xp' => $template->recompensa_xp]);
    }

    // ────────────────────────────────────────────── objetivos federativos (A2.5, item 4)

    /**
     * ⚠️ **O que distingue um objetivo federativo**: o prêmio vai ao FUNDO, e não a quem cumpriu.
     *
     * Antes disto, `categoria = 'federacao'` significava missão pessoal com placar compartilhado —
     * doze membros cumpriam um objetivo comum e **nada era produzido para a federação**. É o que o
     * item 4 do trabalho da fase pedia, e o que sustenta o critério de saída: um tesouro comum que
     * cresce do trabalho coletivo, e que só um grupo organizado consegue acumular.
     */
    public function test_o_objetivo_federativo_paga_o_fundo_da_federacao(): void
    {
        [$fed, $a] = $this->objetivoEmCurso();

        app(Progresso::class)->registrar($a->id, 'despacho', 999);

        $this->assertDatabaseHas('federation_holdings', [
            'federation_id' => $fed->id, 'resource_type' => 'metal_bruto', 'amount' => 2_000,
        ]);
    }

    /**
     * ⚠️ **Uma vez por federação, não uma por membro** — a guarda que impede pagar doze vezes.
     *
     * Cada membro tem a sua linha e todas concluem juntas. Sem a chave por federação-e-janela, uma
     * federação de doze receberia doze prêmios pelo mesmo objetivo semanal.
     */
    public function test_o_fundo_e_pago_uma_vez_so_ainda_que_todos_concluam(): void
    {
        [$fed, $a] = $this->objetivoEmCurso(membros: 2);

        app(Progresso::class)->registrar($a->id, 'despacho', 999);

        // Controle: as DUAS concluíram, então o prêmio único não é efeito de a irmã não ter fechado.
        $this->assertSame(
            2,
            MissionAssignment::where('federation_id', $fed->id)->where('status', 'concluida')->count(),
            'as duas linhas concluíram — sem isto o teste passaria por nada ter acontecido',
        );

        $this->assertSame(
            2_000,
            (int) DB::table('federation_holdings')
                ->where('federation_id', $fed->id)->where('resource_type', 'metal_bruto')->value('amount'),
            'uma vez, não duas',
        );
    }

    /** Quem não tem federação não paga fundo nenhum, e não estoura. */
    public function test_objetivo_sem_federacao_nao_paga_fundo(): void
    {
        $solo = $this->colonia();
        $template = MissionTemplate::where('chave', 'fed_logistica')->firstOrFail();

        MissionAssignment::create([
            'colony_id' => $solo->id, 'federation_id' => null, 'template_id' => $template->id,
            'categoria' => 'federacao', 'acao' => 'despacho', 'progresso' => 0, 'meta' => $template->meta,
            'status' => 'ativa', 'expires_at' => Janela::fimDaSemana(), 'created_at' => now(),
        ]);

        app(Progresso::class)->registrar($solo->id, 'despacho', 999);

        $this->assertSame(0, DB::table('federation_holdings')->count());
    }

    /** E o XP pessoal continua sendo pago: quem trabalhou merece o reconhecimento. */
    public function test_o_xp_pessoal_continua_com_quem_cumpriu(): void
    {
        [, $a] = $this->objetivoEmCurso();
        $template = MissionTemplate::where('chave', 'fed_logistica')->firstOrFail();

        app(Progresso::class)->registrar($a->id, 'despacho', 999);

        $this->assertDatabaseHas('xp_entries', [
            'colony_id' => $a->id, 'acao' => 'missao_concluida', 'xp' => $template->recompensa_xp,
        ]);
    }

    /** @return array{0:Federation,1:Colony,2:Colony|null} */
    private function objetivoEmCurso(int $membros = 1): array
    {
        $fed = Federation::create(['name' => 'Aliança']);
        $template = MissionTemplate::where('chave', 'fed_logistica')->firstOrFail();
        $expira = Janela::fimDaSemana();
        $colonias = [];

        for ($n = 0; $n < $membros; $n++) {
            $c = $this->colonia($fed, $n === 0 ? Federation::LIDER : Federation::MEMBRO);
            $colonias[] = $c;

            MissionAssignment::create([
                'colony_id' => $c->id, 'federation_id' => $fed->id, 'template_id' => $template->id,
                'categoria' => 'federacao', 'acao' => 'despacho', 'progresso' => 0, 'meta' => $template->meta,
                'status' => 'ativa', 'expires_at' => $expira, 'created_at' => now(),
            ]);
        }

        return [$fed, $colonias[0], $colonias[1] ?? null];
    }

    public function test_missao_sem_federacao_nao_espelha_em_nada(): void
    {
        $solo = $this->colonia();

        $template = MissionTemplate::where('categoria', 'diaria')->where('chave', 'dia_despacho_1')->firstOrFail();
        MissionAssignment::create([
            'colony_id' => $solo->id, 'federation_id' => null, 'template_id' => $template->id,
            'categoria' => 'diaria', 'acao' => 'despacho', 'progresso' => 0, 'meta' => 1,
            'status' => 'ativa', 'expires_at' => Janela::proximoDia(), 'created_at' => now(),
        ]);

        // Não deve quebrar por falta de federação — o branch de espelhamento simplesmente não roda.
        app(Progresso::class)->registrar($solo->id, 'despacho');

        $this->assertDatabaseHas('mission_assignments', ['colony_id' => $solo->id, 'status' => 'concluida']);
    }
}
