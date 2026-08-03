<?php

namespace Tests\Feature;

use App\Domain\Building\BuildingSpecs;
use App\Domain\Colony\CreateColony;
use App\Domain\Colony\Silo;
use App\Models\Admin;
use App\Models\User;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Gestão de Construções (D-107): tempo, custo e Silo, ajustáveis pelo admin sem mexer em código.
 *
 * O teste que mais importa aqui é `test_reseed_nao_apaga_o_ajuste_do_admin` — é a prova de que o
 * desenho (uma tabela de override separada, nunca tocada por `BuildingSpecSeeder`) resolve de
 * verdade o problema que motivou a entrega: o mesmo `db:seed` que precisei rodar manualmente para
 * publicar o Depósito Local (D-106) não pode apagar um ajuste do admin.
 */
class ConstrucoesAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
        $this->seed(ComponentRecipeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
    }

    private function operador(): Admin
    {
        return Admin::create([
            'name' => 'Op', 'email' => 'op@fertways.test',
            'password' => Hash::make('segredo-forte-1234'), 'role' => Admin::OPERADOR,
        ]);
    }

    // ────────────────────────────────────────────────────────────── Tempo ──

    public function test_admin_ajusta_tempo_e_buildingspecs_reflete(): void
    {
        $this->actingAs($this->operador(), 'admin')
            ->post('/admin/construcoes/tempo', [
                'building_type' => 'oficina',
                'niveis' => [2 => 99],
            ])
            ->assertRedirect();

        $this->assertSame(99 * 60, app(BuildingSpecs::class)->para('oficina', 2)['tempo_segundos']);

        // Outro nível da mesma construção, intocado, continua com o valor do GDD.
        $baseNivel1 = DB::table('building_specs')->where(['building_type' => 'oficina', 'level' => 1])
            ->value('build_time_seconds');
        $this->assertSame($baseNivel1, app(BuildingSpecs::class)->para('oficina', 1)['tempo_segundos']);
    }

    public function test_nivel_1_das_que_ja_nascem_prontas_nao_pode_ser_ajustado(): void
    {
        $operador = $this->operador();

        foreach (['fazenda', 'deposito_local'] as $tipo) {
            $this->actingAs($operador, 'admin')
                ->post('/admin/construcoes/tempo', ['building_type' => $tipo, 'niveis' => [1 => 10]])
                ->assertSessionHasErrors();
        }

        $this->assertSame(0, DB::table('building_specs_overrides')->count(), 'nada foi salvo');
    }

    public function test_nivel_alem_do_maximo_da_construcao_e_recusado(): void
    {
        $max = app(BuildingSpecs::class)->nivelMaximo('oficina');

        $this->actingAs($this->operador(), 'admin')
            ->post('/admin/construcoes/tempo', ['building_type' => 'oficina', 'niveis' => [$max + 5 => 10]])
            ->assertSessionHasErrors();
    }

    /**
     * O ponto central da entrega: um `db:seed` novo (o mesmo que precisei rodar manualmente para
     * publicar o Depósito Local) não pode apagar um ajuste do admin.
     */
    public function test_reseed_nao_apaga_o_ajuste_do_admin(): void
    {
        $this->actingAs($this->operador(), 'admin')
            ->post('/admin/construcoes/tempo', ['building_type' => 'oficina', 'niveis' => [2 => 777]])
            ->assertRedirect();

        $this->seed(BuildingSpecSeeder::class);

        $this->assertSame(777 * 60, app(BuildingSpecs::class)->para('oficina', 2)['tempo_segundos']);
    }

    // ────────────────────────────────────────────────────────────── Custo ──

    public function test_admin_ajusta_custo_e_buildingspecs_reflete(): void
    {
        $this->actingAs($this->operador(), 'admin')
            ->post('/admin/construcoes/custo', [
                'building_type' => 'oficina',
                'niveis' => [2 => "ligas_metalicas:500\nenergia:20"],
            ])
            ->assertRedirect();

        $this->assertSame(
            ['ligas_metalicas' => 500, 'energia' => 20],
            app(BuildingSpecs::class)->para('oficina', 2)['custo'],
        );
    }

    public function test_linha_de_custo_com_recurso_desconhecido_e_recusada(): void
    {
        $this->actingAs($this->operador(), 'admin')
            ->post('/admin/construcoes/custo', [
                'building_type' => 'oficina',
                'niveis' => [2 => 'recurso_inventado:10'],
            ])
            ->assertSessionHasErrors();

        $this->assertSame(0, DB::table('building_specs_overrides')->count());
    }

    // ─────────────────────────────────────────────────────────────── Silo ──

    /**
     * ⚠️ Este teste cravava **10.000 para todo recurso e todo nível**, e era ele que fazia o
     * placeholder parecer decisão.
     *
     * A tabela foi plana durante toda a vida do projeto: o nível do Depósito Local não protegia nada,
     * e 85% do estoque do mundo ficava exposto (D-198). Um teste que afirma o valor de um parâmetro em
     * branco **defende o erro** — ele fica verde justamente porque nada mudou.
     *
     * Agora ele guarda a **propriedade**, que é o que nunca deveria ter deixado de valer: a capacidade
     * existe para todo recurso do catálogo, e **sobe com o nível** (D-199).
     */
    public function test_a_capacidade_do_silo_existe_para_todos_e_sobe_com_o_nivel(): void
    {
        foreach (['oxigenio', 'niobio_alienigena'] as $recurso) {
            $anterior = 0;

            foreach ([1, 5, 10] as $nivel) {
                $capacidade = (int) DB::table('silo_capacidades')
                    ->where(['resource_type' => $recurso, 'level' => $nivel])
                    ->value('capacidade');

                $this->assertGreaterThan(0, $capacidade, "{$recurso} n{$nivel} tem de proteger algo");
                $this->assertGreaterThan($anterior, $capacidade, 'subir o Depósito protege mais');
                $anterior = $capacidade;
            }
        }
    }

    public function test_admin_ajusta_capacidade_e_protegido_exposto_refletem(): void
    {
        $user = User::factory()->create();
        $colonia = app(CreateColony::class)->handle($user, 'Silo Test', 0, 1);

        $this->actingAs($this->operador(), 'admin')
            ->post('/admin/construcoes/silo', [
                'capacidades' => ['oxigenio' => [1 => 100]],
            ])
            ->assertRedirect();

        $silo = app(Silo::class);
        $this->assertSame(100, $silo->capacidade($colonia, 'oxigenio'));
        $this->assertSame(100, $silo->protegido($colonia, 'oxigenio', 150));
        $this->assertSame(50, $silo->exposto($colonia, 'oxigenio', 150));
        $this->assertSame(0, $silo->exposto($colonia, 'oxigenio', 80));
        $this->assertSame(80, $silo->protegido($colonia, 'oxigenio', 80));
    }

    public function test_recurso_desconhecido_na_grade_do_silo_nao_vira_linha(): void
    {
        $this->actingAs($this->operador(), 'admin')
            ->post('/admin/construcoes/silo', [
                'capacidades' => ['recurso_inventado' => [1 => 999]],
            ])
            ->assertRedirect();

        $this->assertFalse(DB::table('silo_capacidades')->where('resource_type', 'recurso_inventado')->exists());
    }

    // ───────────────────────────────────────────────────────────── Acesso ──

    public function test_as_tres_rotas_exigem_admin_autenticado(): void
    {
        $this->get('/admin/construcoes')->assertRedirect('/admin/login');
        $this->post('/admin/construcoes/tempo', [])->assertRedirect('/admin/login');
        $this->post('/admin/construcoes/custo', [])->assertRedirect('/admin/login');
        $this->post('/admin/construcoes/silo', [])->assertRedirect('/admin/login');
    }

    public function test_a_tela_mostra_as_tres_sub_abas(): void
    {
        $operador = $this->operador();

        $this->actingAs($operador, 'admin')
            ->get('/admin/construcoes')
            ->assertOk()
            ->assertSee('Tempo de Construções')
            ->assertSee('Custo de Construção e Evolução')
            ->assertSee('Gestão do Silo');

        $this->actingAs($operador, 'admin')
            ->get('/admin/construcoes?aba=silo')
            ->assertOk()
            ->assertSee('Oxigênio');
    }
}
