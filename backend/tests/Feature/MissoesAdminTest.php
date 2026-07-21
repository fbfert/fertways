<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Missoes\Acoes;
use App\Models\Admin;
use App\Models\Colony;
use App\Models\MissionAssignment;
use App\Models\MissionTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * O CRUD do catálogo de missões (§06; D-78, aditivo de gestão a pedido do usuário).
 *
 * O painel tinha só liga/desliga — "quero que essas missões sejam gerenciáveis no backend" pediu
 * criar, editar e apagar (quando seguro). O que se afirma aqui é, sobretudo, as duas travas: um
 * molde com `acao` que nenhum gancho conhece é uma missão IMPOSSÍVEL (0/N para sempre, em
 * silêncio — a mesma classe do vínculo de imagem com chave errada, D-72); e apagar um molde já
 * sorteado destruiria o histórico de uma recompensa que já saiu do Tesouro.
 */
class MissoesAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\ComponentRecipeSeeder::class);
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
    }

    private int $proximaColonia = 0;

    private function operador(): Admin
    {
        return Admin::create([
            'name' => 'Op', 'email' => 'op@fertways.test',
            'password' => Hash::make('segredo-forte-1234'), 'role' => Admin::OPERADOR,
        ]);
    }

    /** Uma colônia mínima, só para pendurar `mission_assignments` — não precisa de user "de verdade" aqui. */
    private function colonia(): Colony
    {
        $user = User::factory()->create();

        return app(CreateColony::class)->handle($user, 'Base', 20 + $this->proximaColonia++, 20);
    }

    private function molde(array $extra = []): MissionTemplate
    {
        return MissionTemplate::create(array_merge([
            'chave' => 'teste_molde', 'categoria' => 'diaria', 'titulo' => 'Teste',
            'descricao' => 'Um molde de teste.', 'acao' => 'despacho', 'meta' => 1,
            'recompensa_fert_micro' => 0, 'recompensa_xp' => 0, 'recompensa_recursos' => null,
            'ativa' => true,
        ], $extra));
    }

    // ---------------------------------------------------------------- a trava do D-72

    /**
     * O CATÁLOGO CANÔNICO: o teste que teria pego o buraco do D-72 se existisse na hora. Se o
     * `MissionTemplateSeeder` um dia usar uma `acao` que nenhum gancho do jogo conhece, a missão
     * fica em "0/N" para sempre — e é este teste que denuncia isso ANTES de ir para produção.
     */
    public function test_todo_molde_do_seeder_usa_uma_acao_conhecida(): void
    {
        $this->seed(\Database\Seeders\MissionTemplateSeeder::class);

        $desconhecidas = MissionTemplate::whereNotIn('acao', array_keys(Acoes::TODAS))->pluck('chave', 'acao');

        $this->assertTrue(
            $desconhecidas->isEmpty(),
            "Moldes com ação que nenhum gancho conhece (missão impossível): {$desconhecidas->toJson()}",
        );
    }

    public function test_o_painel_recusa_acao_fora_da_lista_canonica(): void
    {
        $this->actingAs($this->operador(), 'admin')->post('/admin/missoes', [
            'chave' => 'molde_ruim', 'categoria' => 'diaria', 'titulo' => 'Ruim',
            'descricao' => 'x', 'acao' => 'accao_que_nao_existe', 'meta' => 1,
        ])->assertSessionHasErrors('acao');

        $this->assertDatabaseMissing('mission_templates', ['chave' => 'molde_ruim']);
    }

    // ---------------------------------------------------------------- criar

    public function test_o_painel_cria_um_molde(): void
    {
        $this->actingAs($this->operador(), 'admin')->post('/admin/missoes', [
            'chave' => 'dia_teste_novo', 'categoria' => 'diaria', 'titulo' => 'Missão nova',
            'descricao' => 'Descrição da missão nova.', 'acao' => 'despacho', 'meta' => 2,
            'recompensa_fert' => 5.5, 'recompensa_xp' => 100,
            'recompensa_recursos' => "ligas_metalicas:200\nagua:50",
        ])->assertRedirect();

        $m = MissionTemplate::where('chave', 'dia_teste_novo')->firstOrFail();
        $this->assertSame('Missão nova', $m->titulo);
        $this->assertSame(2, $m->meta);
        $this->assertSame(5_500_000, $m->recompensa_fert_micro);
        $this->assertSame(100, $m->recompensa_xp);
        $this->assertSame(['ligas_metalicas' => 200, 'agua' => 50], $m->recompensa_recursos);
        $this->assertTrue($m->ativa, 'nasce ativa');
        $this->assertDatabaseHas('audit_log', ['acao' => 'missao.criar']);
    }

    public function test_a_chave_e_unica(): void
    {
        $this->molde(['chave' => 'ja_existe']);

        $this->actingAs($this->operador(), 'admin')->post('/admin/missoes', [
            'chave' => 'ja_existe', 'categoria' => 'diaria', 'titulo' => 'Duplicada',
            'descricao' => 'x', 'acao' => 'despacho', 'meta' => 1,
        ])->assertSessionHasErrors('chave');
    }

    /**
     * O recurso é conferido contra o catálogo REAL — sem isto, um erro de digitação criaria uma
     * missão que paga um recurso inexistente, e `Progresso::pagar()` incrementaria silenciosamente
     * um `resource_type` que não bate com nada. Zero erro, zero recompensa entregue.
     */
    public function test_o_recurso_da_recompensa_e_conferido_contra_o_catalogo(): void
    {
        $this->actingAs($this->operador(), 'admin')->post('/admin/missoes', [
            'chave' => 'dia_recurso_errado', 'categoria' => 'diaria', 'titulo' => 'x',
            'descricao' => 'x', 'acao' => 'despacho', 'meta' => 1,
            'recompensa_recursos' => 'liga_metalicas:100',   // sem o "s" de "ligas"
        ])->assertSessionHasErrors('recompensa_recursos');

        $this->assertDatabaseMissing('mission_templates', ['chave' => 'dia_recurso_errado']);
    }

    public function test_a_linha_de_recurso_mal_formada_e_recusada(): void
    {
        $this->actingAs($this->operador(), 'admin')->post('/admin/missoes', [
            'chave' => 'dia_linha_ruim', 'categoria' => 'diaria', 'titulo' => 'x',
            'descricao' => 'x', 'acao' => 'despacho', 'meta' => 1,
            'recompensa_recursos' => 'isto não tem dois pontos',
        ])->assertSessionHasErrors('recompensa_recursos');
    }

    // ---------------------------------------------------------------- editar

    public function test_o_painel_edita_um_molde(): void
    {
        $m = $this->molde(['recompensa_fert_micro' => 1_000_000]);

        $this->actingAs($this->operador(), 'admin')->post("/admin/missoes/{$m->id}/editar", [
            'chave' => $m->chave, 'categoria' => 'semanal', 'titulo' => 'Título novo',
            'descricao' => 'Nova descrição.', 'acao' => 'obra_concluida', 'meta' => 4,
            'recompensa_fert' => 9.0, 'recompensa_xp' => 500,
        ])->assertRedirect();

        $m->refresh();
        $this->assertSame('semanal', $m->categoria);
        $this->assertSame('Título novo', $m->titulo);
        $this->assertSame('obra_concluida', $m->acao);
        $this->assertSame(4, $m->meta);
        $this->assertSame(9_000_000, $m->recompensa_fert_micro);
        $this->assertDatabaseHas('audit_log', ['acao' => 'missao.editar']);
    }

    /**
     * A editada de `acao`/`meta` NÃO reescreve o que uma colônia já tem na mão — os dois valores
     * são copiados para `mission_assignments` no sorteio (D-78, por desenho). Já o PRÊMIO é lido
     * do template ao vivo em `Progresso::pagar()`, e por isso muda para quem já está com a missão.
     */
    public function test_editar_acao_nao_afeta_quem_ja_tem_a_missao_na_mao(): void
    {
        $m = $this->molde(['acao' => 'despacho', 'meta' => 1]);
        $colony = $this->colonia();

        $atribuida = MissionAssignment::create([
            'colony_id' => $colony->id, 'template_id' => $m->id, 'categoria' => 'diaria',
            'acao' => 'despacho', 'progresso' => 0, 'meta' => 1, 'status' => 'ativa',
            'expires_at' => now()->addDay(), 'created_at' => now(),
        ]);

        $this->actingAs($this->operador(), 'admin')->post("/admin/missoes/{$m->id}/editar", [
            'chave' => $m->chave, 'categoria' => 'diaria', 'titulo' => $m->titulo,
            'descricao' => $m->descricao, 'acao' => 'obra_concluida', 'meta' => 9,
        ]);

        $this->assertSame('despacho', $atribuida->fresh()->acao, 'a missão na mão não mudou de ação');
        $this->assertSame(1, $atribuida->fresh()->meta, 'nem de meta');
    }

    // ---------------------------------------------------------------- alternar (existia; confere que sobrevive)

    public function test_alternar_liga_e_desliga(): void
    {
        $m = $this->molde(['ativa' => true]);
        $admin = $this->operador();

        $this->actingAs($admin, 'admin')->post("/admin/missoes/{$m->id}/alternar")->assertRedirect();
        $this->assertFalse($m->fresh()->ativa);

        $this->actingAs($admin, 'admin')->post("/admin/missoes/{$m->id}/alternar")->assertRedirect();
        $this->assertTrue($m->fresh()->ativa);
    }

    // ---------------------------------------------------------------- apagar

    public function test_apaga_molde_nunca_sorteado(): void
    {
        $m = $this->molde();

        $this->actingAs($this->operador(), 'admin')->post("/admin/missoes/{$m->id}/apagar")->assertRedirect();

        $this->assertDatabaseMissing('mission_templates', ['id' => $m->id]);
        $this->assertDatabaseHas('audit_log', ['acao' => 'missao.apagar']);
    }

    /** A trava: apagar um molde já sorteado destruiria (via cascade) o histórico da recompensa. */
    public function test_recusa_apagar_molde_ja_sorteado(): void
    {
        $m = $this->molde();
        $colony = $this->colonia();

        MissionAssignment::create([
            'colony_id' => $colony->id, 'template_id' => $m->id, 'categoria' => 'diaria',
            'acao' => $m->acao, 'progresso' => 1, 'meta' => 1, 'status' => 'concluida',
            'expires_at' => now()->addDay(), 'concluded_at' => now(), 'created_at' => now(),
        ]);

        $this->actingAs($this->operador(), 'admin')->post("/admin/missoes/{$m->id}/apagar")
            ->assertSessionHas('erro');

        // Sobrevive — só desativar era o caminho certo.
        $this->assertDatabaseHas('mission_templates', ['id' => $m->id]);
    }

    // ---------------------------------------------------------------- a tela

    public function test_a_aba_missoes_abre_e_lista_o_catalogo(): void
    {
        $this->seed(\Database\Seeders\MissionTemplateSeeder::class);

        $this->actingAs($this->operador(), 'admin')->get('/admin/missoes')
            ->assertOk()
            ->assertSee('Missões — o catálogo')
            ->assertDontSee('Criar um molde');
    }

    // ---------------------------------------------------------------- as abas (D-96)

    public function test_as_tres_abas_existem(): void
    {
        $admin = $this->operador();

        $this->actingAs($admin, 'admin')->get('/admin/missoes?aba=criar')
            ->assertOk()->assertSee('Criar um molde');

        $this->actingAs($admin, 'admin')->get('/admin/missoes?aba=baralho')
            ->assertOk()->assertSee('O baralho');
    }

    public function test_eventuais_e_uma_categoria_valida(): void
    {
        $this->actingAs($this->operador(), 'admin')->post('/admin/missoes', [
            'chave' => 'evento_lancamento', 'categoria' => 'eventuais', 'titulo' => 'Evento',
            'descricao' => 'Missão de lançamento.', 'acao' => 'despacho', 'meta' => 1,
        ])->assertRedirect();

        $this->assertDatabaseHas('mission_templates', ['chave' => 'evento_lancamento', 'categoria' => 'eventuais']);
    }

    public function test_o_baralho_separa_por_categoria(): void
    {
        $this->molde(['chave' => 'diaria_x', 'categoria' => 'diaria', 'titulo' => 'Diária X']);
        $this->molde(['chave' => 'semanal_x', 'categoria' => 'semanal', 'titulo' => 'Semanal X']);

        $resp = $this->actingAs($this->operador(), 'admin')
            ->get('/admin/missoes?aba=baralho&cat=diaria')
            ->assertOk()
            ->assertSee('Diária X')
            ->assertDontSee('Semanal X');
    }

    public function test_a_visao_geral_do_catalogo_conta_por_status(): void
    {
        $m = $this->molde();
        $colonyA = $this->colonia();
        $colonyB = $this->colonia();

        MissionAssignment::create([
            'colony_id' => $colonyA->id, 'template_id' => $m->id, 'categoria' => 'diaria',
            'acao' => $m->acao, 'progresso' => 1, 'meta' => 1, 'status' => 'concluida',
            'expires_at' => now()->addDay(), 'concluded_at' => now(), 'created_at' => now(),
        ]);
        MissionAssignment::create([
            'colony_id' => $colonyB->id, 'template_id' => $m->id, 'categoria' => 'diaria',
            'acao' => $m->acao, 'progresso' => 0, 'meta' => 1, 'status' => 'ativa',
            'expires_at' => now()->addDay(), 'created_at' => now(),
        ]);

        $resp = $this->actingAs($this->operador(), 'admin')
            ->get('/admin/missoes?aba=catalogo')
            ->assertOk();

        $conteudo = $resp->getContent();
        $this->assertStringContainsString('data-catalogo-molde="teste_molde"', $conteudo);
    }

    // ---------------------------------------------------------------- narrativa (D-140)

    public function test_o_painel_cria_um_capitulo_com_pre_requisito(): void
    {
        $cap1 = $this->molde(['chave' => 'end_cap1_teste', 'categoria' => 'narrativa', 'acao' => 'comprar_item_endurance']);

        $this->actingAs($this->operador(), 'admin')->post('/admin/missoes', [
            'chave' => 'end_cap2_teste', 'categoria' => 'narrativa', 'titulo' => 'Capítulo 2',
            'descricao' => 'O segundo capítulo.', 'acao' => 'mercado_executado', 'meta' => 3,
            'requer_template_id' => $cap1->id,
        ])->assertRedirect();

        $cap2 = MissionTemplate::where('chave', 'end_cap2_teste')->firstOrFail();
        $this->assertSame($cap1->id, $cap2->requer_template_id);
    }

    public function test_requer_template_id_vazio_vira_nulo(): void
    {
        $this->actingAs($this->operador(), 'admin')->post('/admin/missoes', [
            'chave' => 'end_cap1_teste', 'categoria' => 'narrativa', 'titulo' => 'Capítulo 1',
            'descricao' => 'O primeiro capítulo.', 'acao' => 'comprar_item_endurance', 'meta' => 1,
            'requer_template_id' => '',
        ])->assertRedirect();

        $this->assertNull(MissionTemplate::where('chave', 'end_cap1_teste')->firstOrFail()->requer_template_id);
    }

    public function test_requer_template_id_inexistente_e_recusado(): void
    {
        $this->actingAs($this->operador(), 'admin')->post('/admin/missoes', [
            'chave' => 'end_cap1_teste', 'categoria' => 'narrativa', 'titulo' => 'Capítulo 1',
            'descricao' => 'x', 'acao' => 'comprar_item_endurance', 'meta' => 1,
            'requer_template_id' => 999999,
        ])->assertSessionHasErrors('requer_template_id');

        $this->assertDatabaseMissing('mission_templates', ['chave' => 'end_cap1_teste']);
    }
}
