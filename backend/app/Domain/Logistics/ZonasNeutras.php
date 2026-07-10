<?php

namespace App\Domain\Logistics;

/**
 * As zonas neutras e a sua geografia (GDD §07, §24.4; docs/decisoes.md D-51 e D-52).
 *
 * O D-51 desenhou 4 distritos de zona neutra, um por quadrante, encostados nos cantos do mapa:
 * blocos 6×5 = 30 zonas cada, 120 no total. O D-52 arbitrou o que cada distrito extrai — só
 * primários, porque na Temporada 1 o jogador não minera os eletrônicos (governo) nem os raros
 * (Temporada 2): Nordeste Metal Bruto, Sudeste Água, Sudoeste Oxigênio, Noroeste Biomassa.
 *
 * Isto aqui é a geometria e o catálogo — determinísticos, sem banco. Quem guarda estado (dono,
 * ocupação, Depósito) é a tabela `neutral_zones`, semeada a partir daqui.
 */
final class ZonasNeutras
{
    /**
     * Os 4 distritos: faixa de x, faixa de y (inclusivas) e o mineral que rendem. As faixas são o
     * canto do D-51 (`x ∈ [45,50], y ∈ [46,50]`) e os seus três espelhos.
     *
     * @var array<string, array{x: array{int,int}, y: array{int,int}, mineral: string}>
     */
    public const DISTRITOS = [
        'nordeste' => ['x' => [45, 50], 'y' => [46, 50], 'mineral' => 'metal_bruto'],
        'sudeste' => ['x' => [45, 50], 'y' => [-50, -46], 'mineral' => 'agua'],
        'sudoeste' => ['x' => [-50, -45], 'y' => [-50, -46], 'mineral' => 'oxigenio'],
        'noroeste' => ['x' => [-50, -45], 'y' => [46, 50], 'mineral' => 'biomassa'],
    ];

    /**
     * As 120 células de zona neutra, com o distrito e o mineral de cada. Ordem determinística
     * (por distrito, depois x, depois y): dois ambientes semeiam o mesmo mapa.
     *
     * @return list<array{x:int,y:int,distrito:string,mineral:string}>
     */
    public static function todas(): array
    {
        $zonas = [];
        foreach (self::DISTRITOS as $nome => $d) {
            for ($x = $d['x'][0]; $x <= $d['x'][1]; $x++) {
                for ($y = $d['y'][0]; $y <= $d['y'][1]; $y++) {
                    $zonas[] = ['x' => $x, 'y' => $y, 'distrito' => $nome, 'mineral' => $d['mineral']];
                }
            }
        }

        return $zonas;
    }

    /** O distrito de uma célula, ou null se ela não é zona neutra. */
    public static function distritoDe(int $x, int $y): ?string
    {
        foreach (self::DISTRITOS as $nome => $d) {
            if ($x >= $d['x'][0] && $x <= $d['x'][1] && $y >= $d['y'][0] && $y <= $d['y'][1]) {
                return $nome;
            }
        }

        return null;
    }

    /** O mineral que a célula rende, ou null se ela não é zona neutra. */
    public static function mineralDe(int $x, int $y): ?string
    {
        $distrito = self::distritoDe($x, $y);

        return $distrito ? self::DISTRITOS[$distrito]['mineral'] : null;
    }

    public static function ehZonaNeutra(int $x, int $y): bool
    {
        return self::distritoDe($x, $y) !== null;
    }
}
