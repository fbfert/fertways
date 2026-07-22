<?php

namespace Tests\Feature;

use App\Domain\Logistics\MapaFertways;
use App\Domain\Logistics\ZonasNeutras;
use App\Models\NeutralZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ErgueEstruturasDaZona;
use Tests\TestCase;

/**
 * As zonas neutras da Fatia 1 do D-52: os 4 distritos, o mineral de cada, o seed das 120, e a
 * regra de que célula de zona não é chão de colônia.
 */
class ZonasNeutrasTest extends TestCase
{
    use RefreshDatabase;
    use ErgueEstruturasDaZona;

    public function test_o_dominio_tem_120_zonas_em_4_distritos(): void
    {
        $zonas = ZonasNeutras::todas();
        $this->assertCount(120, $zonas);

        $porDistrito = collect($zonas)->countBy('distrito');
        $this->assertSame(30, $porDistrito['nordeste']);
        $this->assertSame(30, $porDistrito['sudeste']);
        $this->assertSame(30, $porDistrito['sudoeste']);
        $this->assertSame(30, $porDistrito['noroeste']);

        // Todas as células estão no mapa e nos cantos (periferia), nunca no disco de founders.
        foreach ($zonas as $z) {
            $this->assertTrue(MapaFertways::dentroDoMapa($z['x'], $z['y']));
            $this->assertSame('periferia', MapaFertways::faixaDe($z['x'], $z['y']));
        }
    }

    public function test_o_mineral_segue_o_distrito(): void
    {
        // Um canto de cada distrito, com o mineral arbitrado no D-52.
        $this->assertSame('metal_bruto', ZonasNeutras::mineralDe(50, 50));  // NE
        $this->assertSame('agua', ZonasNeutras::mineralDe(50, -50));        // SE
        $this->assertSame('oxigenio', ZonasNeutras::mineralDe(-50, -50));   // SO
        $this->assertSame('biomassa', ZonasNeutras::mineralDe(-50, 50));    // NO

        // Fora dos distritos não há zona.
        $this->assertNull(ZonasNeutras::mineralDe(0, 0));
        $this->assertNull(ZonasNeutras::distritoDe(10, 10));
        $this->assertFalse(ZonasNeutras::ehZonaNeutra(0, 3));
    }

    public function test_celula_de_zona_neutra_nao_e_fundavel(): void
    {
        // (50,50) é periferia — seria fundável — mas é zona neutra, então não.
        $this->assertTrue(MapaFertways::faixaDe(50, 50) === 'periferia');
        $this->assertFalse(MapaFertways::podeFundar(50, 50));

        // Uma periferia fora dos distritos continua fundável.
        $this->assertTrue(MapaFertways::podeFundar(0, 10));
    }

    public function test_o_seeder_cria_as_120_zonas_livres_e_e_idempotente(): void
    {
        $this->seed(\Database\Seeders\NeutralZoneSeeder::class);
        $this->assertSame(120, NeutralZone::count());
        $this->assertSame(120, NeutralZone::where('status', 'livre')->whereNull('owner_colony_id')->count());

        // Rodar de novo não duplica.
        $this->seed(\Database\Seeders\NeutralZoneSeeder::class);
        $this->assertSame(120, NeutralZone::count());
    }

    public function test_capacidade_e_extracao_seguem_a_curva(): void
    {
        $zona = $this->criarZonaComEstruturas([
            'x' => 50, 'y' => 50, 'district' => 'nordeste', 'mineral' => 'metal_bruto',
            'level' => 1, 'status' => 'livre', 'deposit_level' => 1,
        ]);
        $this->assertSame(100, $zona->extracaoPorHora());   // base
        $this->assertSame(500, $zona->capacidadeDeposito()); // §19.6 base

        // Nível 10 do Depósito: 500 × 1,5^9 = 19.222 (a ponta alta publicada do §19.6).
        $zona = $this->ergueEstruturas($zona, ['deposito_de_zona_neutra' => 10]);
        $this->assertSame(19222, $zona->capacidadeDeposito());
    }
}
