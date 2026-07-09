<?php

namespace Tests\Gdd;

use App\Domain\Logistics\MapaFertways;
use App\Domain\Logistics\VeiculoSpecs;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guarda as specs de logística contra o GDD. Não testa comportamento de aplicação: testa que os
 * números que o motor usa continuam sendo os que o GDD publica.
 */
class LogisticaSpecsTest extends TestCase
{
    /**
     * GDD §25.6, "Tempo de Viagem e Consumo — Exemplos Práticos".
     *
     * [distância, tempo furgão (min), energia furgão (kWh), tempo caminhão, energia caminhão]
     */
    private const TABELA_25_6 = [
        [5, 1.2, 1.2, 3.3, 9.9],
        [15, 3.8, 3.8, 10.0, 30.0],
        [30, 7.5, 7.5, 20.0, 60.0],
        [60, 15.0, 15.0, 40.0, 120.0],
    ];

    /** A tabela publica minutos com uma casa; o motor guarda segundos exatos. */
    private function minutosExibidos(int $segundos): float
    {
        return round($segundos / 60, 1, PHP_ROUND_HALF_EVEN);
    }

    #[Test]
    public function tempo_de_viagem_reproduz_a_tabela_do_gdd(): void
    {
        foreach (self::TABELA_25_6 as [$dist, $tFurgao, , $tCaminhao]) {
            $this->assertSame(
                $tFurgao,
                $this->minutosExibidos(VeiculoSpecs::segundosDoTrecho('furgao_de_comercio', $dist)),
                "Furgão a {$dist} slots",
            );
            $this->assertSame(
                $tCaminhao,
                $this->minutosExibidos(VeiculoSpecs::segundosDoTrecho('caminhao_de_carga', $dist)),
                "Caminhão a {$dist} slots",
            );
        }
    }

    #[Test]
    public function energia_do_furgao_reproduz_a_tabela_do_gdd(): void
    {
        // §21.2: 1 kW/h por minuto de viagem — energia e tempo têm o mesmo número.
        foreach (self::TABELA_25_6 as [$dist, , $energia]) {
            $this->assertSame(
                $energia,
                round(VeiculoSpecs::energiaDoTrecho('furgao_de_comercio', $dist), 1, PHP_ROUND_HALF_EVEN),
                "Furgão a {$dist} slots",
            );
        }
    }

    /**
     * A energia do Caminhão a 5 slots é a única célula da tabela que não fecha com a fórmula.
     *
     * §21.3 diz 3 kW/h por minuto. O tempo exato de 5 slots é 3,333… min, logo a energia exata é
     * 10,0 kWh. O GDD publica **9,9**, que é `3 × 3,3` — ele multiplicou pelo tempo já arredondado
     * para exibição. Nas outras três distâncias o tempo é redondo e a diferença some.
     *
     * O motor calcula pelo tempo exato. Este teste fixa a exceção para que ela não passe
     * despercebida se alguém "corrigir" a fórmula para bater com a célula.
     */
    #[Test]
    public function energia_do_caminhao_bate_exceto_a_celula_arredondada_do_gdd(): void
    {
        foreach (self::TABELA_25_6 as [$dist, , , , $energiaPublicada]) {
            $exata = round(VeiculoSpecs::energiaDoTrecho('caminhao_de_carga', $dist), 1, PHP_ROUND_HALF_EVEN);

            if ($dist === 5) {
                $this->assertSame(9.9, $energiaPublicada);
                $this->assertSame(10.0, $exata, 'a exceção conhecida mudou de valor');

                continue;
            }

            $this->assertSame($energiaPublicada, $exata, "Caminhão a {$dist} slots");
        }
    }

    #[Test]
    public function capacidades_saem_da_conversao_do_gdd(): void
    {
        // §25.4: 1.000 unidades = 1 m³. Furgão 6 m³; Caminhão 30 m³.
        $this->assertSame(6_000, VeiculoSpecs::capacidade('furgao_de_comercio'));
        $this->assertSame(30_000, VeiculoSpecs::capacidade('caminhao_de_carga'));
    }

    #[Test]
    public function energia_da_viagem_cobre_ida_e_volta_arredondando_para_cima(): void
    {
        // 5 slots de furgão: 1,25 kWh por trecho, 2,5 na viagem -> 3 unidades inteiras.
        $this->assertSame(3, VeiculoSpecs::energiaDaViagem('furgao_de_comercio', 5));
        // 30 slots: 7,5 por trecho, 15,0 na viagem -> exato, sem arredondar.
        $this->assertSame(15, VeiculoSpecs::energiaDaViagem('furgao_de_comercio', 30));
    }

    #[Test]
    public function a_capital_fica_no_nucleo_do_mapa(): void
    {
        // §25.8: "o destino é fixo (o Mercado Central, no núcleo do mapa)".
        $this->assertSame(intdiv(MapaFertways::LADO, 2), MapaFertways::CAPITAL_X);
        $this->assertSame(intdiv(MapaFertways::LADO, 2), MapaFertways::CAPITAL_Y);
        $this->assertTrue(MapaFertways::ehCapital(50, 50));
    }

    #[Test]
    public function distancia_e_euclidiana_arredondada(): void
    {
        $this->assertSame(0, MapaFertways::distancia(10, 10, 10, 10));
        $this->assertSame(5, MapaFertways::distancia(0, 0, 3, 4));
        $this->assertSame(1, MapaFertways::distancia(0, 0, 1, 0));
        // Diagonal: √2 = 1,414… -> 1
        $this->assertSame(1, MapaFertways::distancia(0, 0, 1, 1));
        // Meio exato arredonda para cima: √(0,5² …) não ocorre com inteiros, mas 2,5 sim.
        $this->assertSame(3, MapaFertways::distancia(0, 0, 0, 3));
        // Cantos opostos do mapa.
        $this->assertSame(140, MapaFertways::distancia(0, 0, 99, 99));
    }
}
