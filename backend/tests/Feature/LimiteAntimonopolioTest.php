<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Logistics\OcuparZonaNeutra;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Federation;
use App\Models\FederationSetting;
use App\Models\NeutralZone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O limite antimonopólio territorial da Federação (§04; docs/decisoes.md D-119) — "20% → 10%", sem
 * dizer de quê. Uma federação não pode OCUPAR uma zona nova enquanto já detiver `teto_ocupacao_
 * zonas_bps` (2000 = 20%, padrão) ou mais de TODAS as zonas ocupadas do jogo. Zonas já suas não são
 * tocadas — só a PRÓXIMA ocupação é barrada.
 */
class LimiteAntimonopolioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\ComponentRecipeSeeder::class);
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
    }

    // ── andaime — mesmo molde do OcuparZonaNeutraTest ──────────────────────────────────────────

    private int $proximoX = 20;

    private function colonoAbastecido(?Federation $fed = null): Colony
    {
        $user = User::factory()->create();
        $colony = app(CreateColony::class)->handle($user, 'Base', $this->proximoX, $this->proximoX);
        $this->proximoX += 5;

        foreach (['metal_bruto' => 5000, 'ligas_metalicas' => 5000, 'componentes_eletronicos' => 2000] as $r => $q) {
            $colony->resources()->where('resource_type', $r)->update(['amount' => $q]);
        }
        $colony->update(['fert_micro' => 1000 * 1_000_000]);
        // Desbravador: ocupar zona exige o marco 20 desde o D-75.
        $colony->forceFill(['xp' => 20_000])->save();

        if ($fed) {
            $colony->update(['federation_id' => $fed->id, 'federation_role' => Federation::MEMBRO]);
        }

        return $colony->fresh();
    }

    private int $proximaZonaOcupada = 45;

    /** Uma zona JÁ ocupada por alguém — para compor o total do jogo e a fatia da federação. */
    private function zonaOcupadaPor(Colony $dono): NeutralZone
    {
        $cel = $this->proximaZonaOcupada++;

        return NeutralZone::create([
            'x' => $cel, 'y' => $cel, 'district' => 'NE', 'mineral' => 'metal_bruto', 'level' => 1,
            'owner_colony_id' => $dono->id, 'status' => 'ocupada', 'deposit_level' => 1,
        ]);
    }

    private int $proximaZonaLivre = 70;

    private function zonaLivre(): NeutralZone
    {
        $cel = $this->proximaZonaLivre++;

        return NeutralZone::create([
            'x' => $cel, 'y' => $cel, 'district' => 'nordeste', 'mineral' => 'metal_bruto',
            'level' => 1, 'status' => 'livre', 'deposit_level' => 1,
        ]);
    }

    // ── os testes ───────────────────────────────────────────────────────────────────────────────

    public function test_sem_zona_nenhuma_ocupada_no_jogo_a_primeira_nao_trava(): void
    {
        // 0 zonas no total: dividir por zero não pode travar o próprio nascimento do sistema.
        $fed = Federation::create(['name' => 'Aliança']);
        $colony = $this->colonoAbastecido($fed);
        $zona = $this->zonaLivre();

        app(OcuparZonaNeutra::class)->handle($colony, $zona);

        $this->assertSame($colony->id, $zona->fresh()->owner_colony_id);
    }

    public function test_bloqueia_a_proxima_ocupacao_quando_a_federacao_ja_esta_no_teto(): void
    {
        $fed = Federation::create(['name' => 'Aliança']);

        // 4 zonas de fora + 1 da federação = 5 no total. A federação tem 20% — o teto padrão.
        $fora = $this->colonoAbastecido();
        $this->zonaOcupadaPor($fora);
        $this->zonaOcupadaPor($fora);
        $this->zonaOcupadaPor($fora);
        $this->zonaOcupadaPor($fora);

        $membro = $this->colonoAbastecido($fed);
        $this->zonaOcupadaPor($membro);

        $outroMembro = $this->colonoAbastecido($fed);
        $zonaNova = $this->zonaLivre();

        try {
            app(OcuparZonaNeutra::class)->handle($outroMembro, $zonaNova);
            $this->fail('deveria ter recusado: a federação já está no teto de 20%');
        } catch (DomainRuleException $e) {
            $this->assertSame('teto_antimonopolio_da_federacao', $e->codigo);
        }

        // A zona continua livre — nada foi tomado nem cobrado pela tentativa recusada.
        $this->assertNull($zonaNova->fresh()->owner_colony_id);
    }

    public function test_nao_bloqueia_colonia_sem_federacao_mesmo_com_o_jogo_dominado(): void
    {
        // O teto é DA FEDERAÇÃO — uma colônia solo nunca esbarra nele, por mais zona que exista.
        $fed = Federation::create(['name' => 'Dominante']);
        $membro = $this->colonoAbastecido($fed);
        $this->zonaOcupadaPor($membro);
        $this->zonaOcupadaPor($membro);

        $solo = $this->colonoAbastecido();
        $zonaNova = $this->zonaLivre();

        app(OcuparZonaNeutra::class)->handle($solo, $zonaNova);

        $this->assertSame($solo->id, $zonaNova->fresh()->owner_colony_id);
    }

    public function test_o_painel_salva_o_teto_e_o_guard_o_le(): void
    {
        // Mesmo cenário exato do teste que bloqueia no padrão (20%) — só que com um teto mais
        // frouxo (30%) configurado, para provar que o guard lê o painel, não uma constante presa.
        FederationSetting::singleton()->update(['teto_ocupacao_zonas_bps' => 3_000]);

        $fed = Federation::create(['name' => 'Aliança']);
        $fora = $this->colonoAbastecido();
        $this->zonaOcupadaPor($fora);
        $this->zonaOcupadaPor($fora);
        $this->zonaOcupadaPor($fora);
        $this->zonaOcupadaPor($fora);

        $membro = $this->colonoAbastecido($fed);
        $this->zonaOcupadaPor($membro);   // 1 de 5 = 20%

        $outroMembro = $this->colonoAbastecido($fed);
        $zonaNova = $this->zonaLivre();

        // 20% < 30% (o teto configurado agora) — passa, embora o padrão travasse aqui mesmo.
        app(OcuparZonaNeutra::class)->handle($outroMembro, $zonaNova);

        $this->assertSame($outroMembro->id, $zonaNova->fresh()->owner_colony_id);
    }

    private function admin(): \App\Models\Admin
    {
        return \App\Models\Admin::create([
            'name' => 'Operador', 'email' => 'op@fertways.test',
            'password' => \Illuminate\Support\Facades\Hash::make('segredo-forte-1234'),
            'role' => \App\Models\Admin::OPERADOR,
        ]);
    }

    public function test_o_painel_recusa_bps_fora_da_faixa(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/federacoes/parametros', ['teto_ocupacao_zonas_bps' => 10_001])
            ->assertSessionHasErrors('teto_ocupacao_zonas_bps');
    }

    public function test_o_painel_grava_o_teto(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->post('/admin/federacoes/parametros', ['teto_ocupacao_zonas_bps' => 1_500])
            ->assertRedirect();

        $this->assertSame(1_500, FederationSetting::singleton()->fresh()->teto_ocupacao_zonas_bps);
    }
}
