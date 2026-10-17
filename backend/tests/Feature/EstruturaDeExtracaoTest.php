<?php

namespace Tests\Feature;

use App\Domain\Colony\CreateColony;
use App\Domain\Logistics\ExtrairZonasNeutras;
use App\Domain\Zona\Estruturas;
use App\Domain\Zona\Extracao;
use App\Models\Colony;
use App\Models\NeutralZone;
use App\Models\User;
use App\Models\ZoneStructure;
use Database\Seeders\BuildingSpecSeeder;
use Database\Seeders\ComponentRecipeSeeder;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ErgueEstruturasDaZona;
use Tests\TestCase;

/**
 * A Estrutura de Extração deixa de ser inerte (D-290): acelera a extração da zona sem ser exigida.
 */
class EstruturaDeExtracaoTest extends TestCase
{
    use ErgueEstruturasDaZona;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ResourceTypeSeeder::class);
        $this->seed(ComponentRecipeSeeder::class);
        $this->seed(BuildingSpecSeeder::class);
    }

    private int $zonas = 0;

    private function dono(): Colony
    {
        return app(CreateColony::class)->handle(User::factory()->create(), 'Dono', 20, 20)->fresh();
    }

    private function zona(Colony $dono, array $extra = [], string $mineral = 'metal_bruto'): NeutralZone
    {
        $cel = [[47, 47], [48, 48], [49, 49], [45, 46]][$this->zonas++];

        return $this->criarZonaComEstruturas(array_merge([
            'x' => $cel[0], 'y' => $cel[1], 'district' => 'NE', 'mineral' => $mineral, 'level' => 1,
            'owner_colony_id' => $dono->id, 'status' => 'ocupada',
            'occupied_at' => now()->subDays(30), 'protected_until' => now()->subDays(20),
            'command_post_level' => 1, 'productive_at' => now()->subDays(20),
            'deposit_level' => 1, 'deposit_amount' => 0, 'last_extraction_at' => now(),
        ], $extra))->fresh();
    }

    private function copia(NeutralZone $zona, int $nivel): void
    {
        $slot = (int) $zona->zoneStructures()->max('slot') + 1;
        ZoneStructure::create(['neutral_zone_id' => $zona->id, 'slot' => $slot, 'type' => 'estrutura_de_extracao', 'level' => $nivel]);
    }

    public function test_sem_ela_a_zona_rende_o_que_sempre_rendeu(): void
    {
        $this->assertSame(100, $this->zona($this->dono())->extracaoPorHora());
    }

    public function test_cada_nivel_soma_pela_curva(): void
    {
        $dono = $this->dono();

        $this->assertSame(110, $this->zona($dono, ['extraction_level' => 1])->extracaoPorHora(), '+10%');
        $this->assertSame(122, $this->zona($dono, ['extraction_level' => 3])->extracaoPorHora(), '+22,5%, truncado');
        // A curva da zona e a da ferramenta se multiplicam: 150/h no nível 2, +10%.
        $this->assertSame(165, $this->zona($dono, ['level' => 2, 'extraction_level' => 1])->extracaoPorHora());
    }

    public function test_as_copias_somam_ate_o_teto(): void
    {
        $dono = $this->dono();

        $duas = $this->zona($dono, ['extraction_level' => 1]);
        $this->copia($duas, 1);
        $this->assertSame(120, $duas->fresh()->extracaoPorHora());

        $muitas = $this->zona($dono);
        foreach (range(1, 4) as $_) {
            $this->copia($muitas, 5);
        }
        $this->assertSame(Extracao::TETO_BPS, Extracao::bonusBps($muitas->fresh()));
        $this->assertSame(200, $muitas->fresh()->extracaoPorHora(), 'no máximo, dobra');
    }

    public function test_o_tick_extrai_com_o_bonus(): void
    {
        $zona = $this->zona($this->dono(), ['extraction_level' => 1]);

        $this->travelTo(now()->addHour());
        app(ExtrairZonasNeutras::class)->handle(now());

        $this->assertSame(110, $zona->fresh()->deposit_amount);
    }

    public function test_o_nome_da_ferramenta_segue_o_mineral_e_a_tela_mostra_o_bonus(): void
    {
        $dono = $this->dono();
        $zona = $this->zona($dono, ['extraction_level' => 2], 'agua');

        $this->assertFalse(Estruturas::de('estrutura_de_extracao')['inerte']);
        $this->assertSame('Perfuratriz', Extracao::ferramenta('metal_bruto'));

        $r = $this->actingAs($dono->user)->getJson("/zones/{$zona->id}")->assertOk();
        $this->assertSame(115, $r->json('extracao_hora'));
        $this->assertSame(1500, $r->json('extracao_bonus_bps'));

        $linha = collect($r->json('estruturas.erguidas'))->firstWhere('type', 'estrutura_de_extracao');
        $this->assertSame('Estrutura de Extração — Bomba de Poço', $linha['nome']);
        $this->assertSame(1500, $linha['bonus_bps']);
    }
}
