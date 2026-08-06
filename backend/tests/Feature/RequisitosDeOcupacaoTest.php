<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Logistics\OcuparZonaNeutra;
use App\Domain\Logistics\RequisitosDeOcupacao;
use App\Domain\Marco\Curva;
use App\Models\Colony;
use App\Models\NeutralZone;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ErgueEstruturasDaZona;
use Tests\TestCase;

/**
 * O que ocupar exige, e o que falta (A2.V4, D-224).
 *
 * O painel do mapa anunciava o custo numa frase escrita à mão — *"800 Metal Bruto + 300 Fert$ e 20
 * Robôs Mineradores"* — para uma cobrança de **1.020 Metal Bruto, 1.200 Ligas e 400 Componentes**, e
 * oferecia um botão sempre habilitado. O que estes testes guardam é que a tela e a regra leiam o
 * MESMO número, e que o jogador saiba de tudo o que falta de uma vez.
 */
class RequisitosDeOcupacaoTest extends TestCase
{
    use RefreshDatabase;
    use ErgueEstruturasDaZona;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\ResourceTypeSeeder::class);
        $this->seed(\Database\Seeders\ComponentRecipeSeeder::class);
        $this->seed(\Database\Seeders\BuildingSpecSeeder::class);
    }

    private function colono(bool $abastecido): Colony
    {
        $user = User::factory()->create();
        $colony = app(CreateColony::class)->handle($user, 'Base', 20, 20);

        if ($abastecido) {
            foreach (['metal_bruto' => 5000, 'ligas_metalicas' => 5000, 'componentes_eletronicos' => 2000] as $r => $q) {
                $colony->resources()->where('resource_type', $r)->update(['amount' => $q]);
            }
            $colony->update(['fert_micro' => 1000 * 1_000_000]);
            // `forceFill`: xp NÃO é fillable de propósito — só o ConcederXp o escreve no jogo real.
            $colony->forceFill(['xp' => Curva::xpDoMarco(RequisitosDeOcupacao::MARCO)])->save();
        }

        return $colony->fresh();
    }

    private function zonaLivre(): NeutralZone
    {
        return $this->criarZonaComEstruturas([
            'x' => 50, 'y' => 50, 'district' => 'nordeste', 'mineral' => 'metal_bruto',
            'level' => 1, 'status' => 'livre', 'deposit_level' => 1,
        ]);
    }

    /**
     * ⚠️ O teste que existe por causa do defeito: o número que a tela mostra é o que o comando cobra.
     *
     * Enquanto a frase do painel era escrita à mão, ela dizia 800 de Metal Bruto para uma cobrança de
     * 1.020 — os 220 dos robôs estavam escondidos atrás da palavra "robôs" — e não citava 1.200
     * Ligas nem 400 Componentes. Este teste compara a **conta anunciada** com o **débito real**.
     */
    public function test_o_custo_anunciado_e_exatamente_o_que_a_ocupacao_debita(): void
    {
        $colony = $this->colono(abastecido: true);
        $zona = $this->zonaLivre();

        $anunciado = app(RequisitosDeOcupacao::class)->para($colony)['recursos'];

        $antes = $colony->resources()->pluck('amount', 'resource_type');
        app(OcuparZonaNeutra::class)->handle($colony, $zona);
        $depois = $colony->fresh()->resources()->pluck('amount', 'resource_type');

        $debitado = [];
        foreach ($antes as $recurso => $quanto) {
            $delta = (int) $quanto - (int) $depois[$recurso];
            if ($delta > 0) {
                $debitado[$recurso] = $delta;
            }
        }

        ksort($anunciado);
        ksort($debitado);
        $this->assertSame($debitado, $anunciado, 'o painel anuncia um custo e o comando cobra outro');
    }

    /**
     * Todos os impedimentos de uma vez, e não o primeiro.
     *
     * O comando confere em ordem e para no primeiro erro — que é o certo para uma transação e péssimo
     * para uma tela: o jogador conseguiria Fert$, clicaria de novo, e só então descobriria que faltam
     * colonos. Medido em produção no dia do D-223: os dois líderes estavam bloqueados por três coisas
     * ao mesmo tempo.
     */
    public function test_relata_todos_os_impedimentos_juntos(): void
    {
        $colony = $this->colono(abastecido: false);

        $r = app(RequisitosDeOcupacao::class)->para($colony);
        $tipos = array_column($r['falta'], 'tipo');

        $this->assertFalse($r['pode']);
        $this->assertContains('marco', $tipos);
        $this->assertContains('fert', $tipos);
        $this->assertContains('recurso', $tipos);
        $this->assertGreaterThanOrEqual(3, count($r['falta']), 'a tela precisa da lista inteira, não do primeiro erro');
    }

    public function test_colono_abastecido_pode_ocupar(): void
    {
        $r = app(RequisitosDeOcupacao::class)->para($this->colono(abastecido: true));

        $this->assertTrue($r['pode']);
        $this->assertSame([], $r['falta']);
    }

    /** O marco aparece com o XP que falta, e não só com o número do marco. */
    public function test_o_marco_que_falta_vem_com_o_xp_alvo(): void
    {
        $colony = $this->colono(abastecido: true);
        $colony->forceFill(['xp' => 0])->save();

        $marco = collect(app(RequisitosDeOcupacao::class)->para($colony->fresh())['falta'])
            ->firstWhere('tipo', 'marco');

        $this->assertNotNull($marco);
        $this->assertSame(0, $marco['tem']);
        $this->assertSame(Curva::xpDoMarco(RequisitosDeOcupacao::MARCO), $marco['precisa']);
    }

    public function test_a_rota_entrega_os_requisitos_ao_dono_da_colonia(): void
    {
        $colony = $this->colono(abastecido: true);

        $this->actingAs($colony->user)->getJson('/zones/requisitos')
            ->assertOk()
            ->assertJsonPath('pode', true)
            ->assertJsonPath('marco', RequisitosDeOcupacao::MARCO)
            ->assertJsonStructure(['recursos', 'falta', 'operadores', 'teto_de_zonas']);
    }
}
