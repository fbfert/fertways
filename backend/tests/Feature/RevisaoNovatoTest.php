<?php

namespace Tests\Feature;

use App\Domain\Avisos\Avisos;
use App\Domain\Building\EnqueueUpgrade;
use App\Domain\Colony\CreateColony;
use App\Domain\Missoes\Atribuir;
use App\Domain\Missoes\Progresso;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\MissionAssignment;
use App\Models\User;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\MissionTemplateSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A primeira sessão, jogada por um revisor como novato (D-285): o que o fazia travar ou desconfiar.
 */
class RevisaoNovatoTest extends TestCase
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

    private function colonia(): Colony
    {
        return app(CreateColony::class)->handle(User::factory()->create(), 'C', 20, 20)->fresh();
    }

    public function test_o_proximo_passo_da_tutoria_nasce_quando_o_anterior_conclui(): void
    {
        $c = $this->colonia();
        app(Atribuir::class)->tutoria($c);
        $primeira = MissionAssignment::where('colony_id', $c->id)->where('categoria', 'tutoria')->firstOrFail();
        $antes = MissionAssignment::where('colony_id', $c->id)->where('categoria', 'tutoria')->count();

        app(Progresso::class)->registrar($c->id, $primeira->acao, $primeira->meta);

        // Sem abrir a tela de Missões: o degrau seguinte já existe.
        $this->assertSame('concluida', $primeira->fresh()->status);
        $this->assertSame($antes + 1, MissionAssignment::where('colony_id', $c->id)->where('categoria', 'tutoria')->count());
    }

    public function test_a_diaria_de_manutencao_so_vem_com_veiculo_a_manter(): void
    {
        $c = $this->colonia();

        $this->assertFalse(app(Atribuir::class)->alcancavel($c, 'manutencao'), 'o Furgão do kit está no teto');

        $c->vehicles()->update(['conservacao_bps' => 8_000]);
        $this->assertTrue(app(Atribuir::class)->alcancavel($c, 'manutencao'));
    }

    public function test_a_falta_de_recurso_fala_o_nome_do_recurso(): void
    {
        $c = $this->colonia();
        $c->resources()->update(['amount' => 0]);
        $mina = $c->buildings()->create(['type' => 'mina_local', 'level' => 0, 'slot' => 40]);

        try {
            app(EnqueueUpgrade::class)->handle($c->fresh(), $mina);
            $this->fail('Construiu sem recurso.');
        } catch (DomainRuleException $e) {
            $this->assertStringNotContainsString('_', $e->getMessage(), 'nada de código interno como ligas_metalicas');
            $this->assertStringContainsString('Ligas Metálicas', $e->getMessage());
        }
    }

    public function test_com_a_pesquisa_desligada_o_laboratorio_nao_cobra_ninguem(): void
    {
        $c = $this->colonia();
        $c->buildings()->create(['type' => 'laboratorio', 'level' => 1, 'slot' => 41]);
        DB::table('research_settings')->where('id', 1)->update(['ativo' => false]);

        $codigos = collect(app(Avisos::class)->paraColonia($c->fresh()))->pluck('codigo');

        $this->assertNotContains('laboratorio_ocioso', $codigos);
    }

    public function test_a_validacao_fala_portugues(): void
    {
        App::setLocale('pt_BR');

        $r = $this->postJson('/register', [
            'name' => 'Ana', 'nickname' => 'ana nova', 'email' => 'ana@x.test', 'password' => '123',
        ])->assertStatus(422);

        $this->assertStringContainsString('apelido', $r->json('errors.nickname.0'));
        $this->assertNotEmpty($r->json('errors.password'));
    }
}
