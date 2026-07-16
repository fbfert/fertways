<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Colony\KitInicial;
use App\Models\Admin;
use App\Models\KitInicialSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * O kit inicial (D-85) editável pelo admin, sem mexer em código (D-92).
 *
 * O que se prova aqui: que salvar pelo painel muda o que `CreateColony` de fato concede — não só
 * uma linha em banco que ninguém lê — e que colônias já fundadas nunca são tocadas, mesma regra
 * que o D-85 fixou desde o início.
 */
class KitInicialAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
    }

    private function operador(): Admin
    {
        return Admin::create([
            'name' => 'Op', 'email' => 'op@fertways.test',
            'password' => Hash::make('segredo-forte-1234'), 'role' => Admin::OPERADOR,
        ]);
    }

    private function payloadPadrao(array $sobrescreve = []): array
    {
        $recursos = KitInicial::recursos();

        return array_merge([
            'fert' => '100',
            'recursos' => $recursos,
            'furgoes' => 1,
            'caminhoes' => 0,
        ], $sobrescreve);
    }

    public function test_a_tela_mostra_o_kit_atual_e_o_aviso_do_muro(): void
    {
        $this->actingAs($this->operador(), 'admin')
            ->get('/admin/operacao')
            ->assertOk()
            ->assertSee('Kit inicial')
            ->assertSee('Furgões de Comércio')
            ->assertSee('reabre Torre de Defesa + Quartel')
            ->assertSee('reabre Refinaria Química + Antena de Comunicação');
    }

    public function test_salvar_muda_o_que_a_fundacao_de_fato_concede(): void
    {
        $recursos = KitInicial::recursos();
        $recursos['metal_bruto'] = 9_999;
        $recursos['niobio_alienigena'] = 0;

        $this->actingAs($this->operador(), 'admin')
            ->post('/admin/operacao/kit-inicial', $this->payloadPadrao([
                'fert' => '250',
                'recursos' => $recursos,
                'furgoes' => 2,
                'caminhoes' => 1,
            ]))
            ->assertRedirect();

        $this->assertSame(250_000_000, KitInicial::fertMicro());
        $this->assertSame(9_999, KitInicial::recursos()['metal_bruto']);
        $this->assertSame(['furgao_de_comercio' => 2, 'caminhao_de_carga' => 1], KitInicial::frota());

        // E a fundação seguinte usa o kit NOVO — não é só uma linha em banco que ninguém lê.
        $user = User::factory()->create();
        $colony = app(CreateColony::class)->handle($user, 'Depois da mudança', 0, 1);

        $this->assertSame(250_000_000, $colony->fert_micro);
        $this->assertSame(9_999, $colony->resources->firstWhere('resource_type', 'metal_bruto')->amount);
        $this->assertCount(3, $colony->vehicles, '2 Furgões + 1 Caminhão');
        $this->assertSame(2, $colony->vehicles->where('type', 'furgao_de_comercio')->count());
        $this->assertSame(1, $colony->vehicles->where('type', 'caminhao_de_carga')->count());
    }

    /** A regra que o D-85 já tinha fixado: mudar o kit não mexe em quem já fundou. */
    public function test_colonia_ja_fundada_nao_e_tocada_pela_mudanca(): void
    {
        $user = User::factory()->create();
        $colonyAntes = app(CreateColony::class)->handle($user, 'Antes da mudança', 0, 1);
        $fertAntes = $colonyAntes->fert_micro;
        $metalAntes = $colonyAntes->resources->firstWhere('resource_type', 'metal_bruto')->amount;
        $veiculosAntes = $colonyAntes->vehicles->count();

        $this->actingAs($this->operador(), 'admin')
            ->post('/admin/operacao/kit-inicial', $this->payloadPadrao([
                'fert' => '999',
                'furgoes' => 5,
            ]))
            ->assertRedirect();

        $colonyAntes->refresh();
        $this->assertSame($fertAntes, $colonyAntes->fert_micro, 'sem backfill de Fert$');
        $this->assertSame(
            $metalAntes,
            $colonyAntes->resources->fresh()->firstWhere('resource_type', 'metal_bruto')->amount,
            'sem backfill de recursos',
        );
        $this->assertSame($veiculosAntes, $colonyAntes->vehicles()->count(), 'sem backfill de frota');
    }

    public function test_recurso_desconhecido_no_payload_nao_vira_linha_no_banco(): void
    {
        $this->actingAs($this->operador(), 'admin')
            ->post('/admin/operacao/kit-inicial', $this->payloadPadrao([
                'recursos' => array_merge(KitInicial::recursos(), ['recurso_inventado' => 500]),
            ]))
            ->assertRedirect();

        $this->assertFalse(
            DB::table('kit_inicial_recursos')->where('resource_type', 'recurso_inventado')->exists(),
        );
    }

    public function test_quantidade_negativa_e_recusada(): void
    {
        $antes = KitInicial::recursos()['metal_bruto'];

        $this->actingAs($this->operador(), 'admin')
            ->post('/admin/operacao/kit-inicial', $this->payloadPadrao([
                'recursos' => array_merge(KitInicial::recursos(), ['metal_bruto' => -1]),
            ]))
            ->assertSessionHasErrors();

        $this->assertSame($antes, KitInicial::recursos()['metal_bruto'], 'nada foi salvo');
    }

    /** O singleton nasce com os defaults do D-85 mesmo sem nenhum admin ter mexido ainda. */
    public function test_o_singleton_nasce_com_os_defaults_do_d85(): void
    {
        $config = KitInicialSetting::singleton();

        $this->assertSame(100_000_000, $config->fert_micro);
        $this->assertSame(1, $config->furgoes);
        $this->assertSame(0, $config->caminhoes);
    }
}
