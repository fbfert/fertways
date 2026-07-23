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
        // §25.8: "o destino é fixo (o Mercado Central, no núcleo do mapa)". D-51: o núcleo é a
        // origem (0,0), e o lado 101 (ímpar) garante que exista uma célula central.
        $this->assertSame(0, MapaFertways::CAPITAL_X);
        $this->assertSame(0, MapaFertways::CAPITAL_Y);
        $this->assertSame(101, MapaFertways::LADO);
        $this->assertTrue(MapaFertways::ehCapital(0, 0));
        $this->assertFalse(MapaFertways::ehCapital(50, 50));
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
        // Cantos opostos do mapa, agora (−50,−50) a (50,50): √(100²+100²) = 141,42 -> 141.
        $this->assertSame(141, MapaFertways::distancia(-50, -50, 50, 50));
    }

    /**
     * As faixas concêntricas do D-51 saem da distância euclidiana **exata** ao centro, não da
     * arredondada do frete. É a distinção que preserva o disco de 48 células — e é aceito que
     * "distância 4" queira dizer coisas diferentes no mapa e na conta do tributo.
     */
    #[Test]
    public function as_faixas_concentricas_seguem_a_distancia_exata(): void
    {
        $this->assertSame('capital', MapaFertways::faixaDe(0, 0));
        $this->assertSame('founder', MapaFertways::faixaDe(4, 0));   // d = 4, dentro
        $this->assertSame('anel', MapaFertways::faixaDe(3, 3));      // d = 4,24, fora do disco
        $this->assertSame('anel', MapaFertways::faixaDe(4, 3));      // d = 5, borda do anel
        $this->assertSame('periferia', MapaFertways::faixaDe(4, 4)); // d = 5,66, fora do anel
        $this->assertSame('periferia', MapaFertways::faixaDe(50, 50));

        // A conta do tributo (arredondada) discorda de propósito: floor(4,24+0,5)=4.
        $this->assertSame(4, MapaFertways::distancia(0, 0, 3, 3));
    }

    /**
     * O disco de founders: 48 células, 28 populáveis + 20 reservadas (D-51), em ordem canônica
     * e determinística — dois ambientes têm de gerar o mesmo mapa.
     */
    #[Test]
    public function o_disco_de_founders_tem_48_celulas_28_populaveis(): void
    {
        $slots = MapaFertways::slotsFounder();
        $reservados = array_filter($slots, fn (array $s) => $s['reservado']);

        $this->assertCount(48, $slots);
        $this->assertCount(20, $reservados);
        $this->assertCount(28, array_filter($slots, fn (array $s) => ! $s['reservado']));

        // Toda célula do disco é founder; nenhuma além dele.
        foreach ($slots as $s) {
            $this->assertSame('founder', MapaFertways::faixaDe($s['x'], $s['y']));
        }

        // Fundável = founder populável OU periferia LIBERADA (D-147); nunca Capital, anel ou
        // reservado. O 3º/4º argumentos (`$periferiaLiberada`/`$ehZonaNeutra`) são irrelevantes
        // pros casos que nem chegam a ser periferia — passados `false` só por exigência da
        // assinatura (D-148: nenhum dos dois tem default, de propósito).
        $this->assertFalse(MapaFertways::podeFundar(0, 0, false, false));        // Capital
        $this->assertFalse(MapaFertways::podeFundar(3, 3, false, false));        // anel
        $this->assertFalse(MapaFertways::podeFundar(1, 0, false, false));        // founder reservado (índice 0)
        $this->assertTrue(MapaFertways::podeFundar(0, 1, false, false));         // founder populável (índice 1)

        // Periferia (fora dos distritos): só é fundável se estiver na lista do admin (D-147) — a
        // MESMA célula, nos dois estados, é a prova de que a trava funciona nos dois sentidos.
        $this->assertFalse(MapaFertways::podeFundar(0, 10, false, false));       // não liberada: recusa
        $this->assertTrue(MapaFertways::podeFundar(0, 10, true, false));         // liberada: aceita

        // Zona neutra recusa mesmo liberada — a checagem de zona vem ANTES da de periferia. Desde
        // o D-148 `$ehZonaNeutra` é quem chama que decide (consulta real a `neutral_zones`, não
        // mais só a fórmula dos 4 distritos) — aqui simulamos os dois casos.
        $this->assertFalse(MapaFertways::podeFundar(50, 50, true, true));        // é zona: recusa mesmo liberada
        $this->assertTrue(MapaFertways::podeFundar(50, 50, true, false));        // não é zona (mais): aceita
        $this->assertFalse(MapaFertways::podeFundar(51, 0, false, false));       // fora do mapa
    }
}
