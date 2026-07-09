<?php

namespace App\Domain\Logistics;

/**
 * Geometria do mapa.
 *
 * O GDD **não define** o mapa. Ele exige que a posição importe (§25.6: "dois colonos vizinhos
 * comerciam rápido e barato; dois colonos em regiões opostas do planeta pagam muito mais") e diz
 * que o Mercado Central fica "no núcleo do mapa" (§25.8), mas nunca publica coordenadas, tamanho
 * nem métrica. Isto aqui é decisão de projeto, não leitura do GDD — ver docs/decisoes.md D-29.
 *
 * As distâncias da tabela de exemplos do §25.6 (5, 15, 30 e 60 slots) cabem confortavelmente
 * nesta grade: vizinhos ficam a poucos slots, e os cantos opostos a ~140.
 */
final class MapaFertways
{
    public const LADO = 100;

    public const CAPITAL_X = 50;

    public const CAPITAL_Y = 50;

    /**
     * Distância em "slots de mapa", a unidade que o GDD usa para velocidade (§21.2, §21.3).
     *
     * Euclidiana, arredondada meio-para-cima. Não há curva do GDD aqui para reproduzir — a
     * tabela do §25.6 só usa distâncias inteiras —, então o arredondamento é escolha nossa e
     * não precisa do half-even das curvas de custo e tempo.
     */
    public static function distancia(int $x1, int $y1, int $x2, int $y2): int
    {
        $d = sqrt(($x1 - $x2) ** 2 + ($y1 - $y2) ** 2);

        return (int) floor($d + 0.5);
    }

    public static function ateCapital(int $x, int $y): int
    {
        return self::distancia($x, $y, self::CAPITAL_X, self::CAPITAL_Y);
    }

    public static function dentroDoMapa(int $x, int $y): bool
    {
        return $x >= 0 && $x < self::LADO && $y >= 0 && $y < self::LADO;
    }

    /** A Capital é o Mercado Central; nenhum colono se instala em cima dela. */
    public static function ehCapital(int $x, int $y): bool
    {
        return $x === self::CAPITAL_X && $y === self::CAPITAL_Y;
    }
}
