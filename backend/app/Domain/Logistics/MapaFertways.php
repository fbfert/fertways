<?php

namespace App\Domain\Logistics;

/**
 * Geometria do mapa.
 *
 * O GDD **não define** o mapa. Ele exige que a posição importe (§25.6: "dois colonos vizinhos
 * comerciam rápido e barato; dois colonos em regiões opostas do planeta pagam muito mais") e diz
 * que o Mercado Central fica "no núcleo do mapa" (§25.8), mas nunca publica coordenadas, tamanho
 * nem métrica. Isto aqui é decisão de projeto, não leitura do GDD — ver docs/decisoes.md D-29 e
 * **D-51**, que redesenhou a grade.
 *
 * **O mapa do D-51.** Lado 101 (não 100): um lado ímpar tem célula central, e a Capital precisa
 * de uma. Coordenadas inteiras **com sinal**, de −50 a +50 nos dois eixos, Capital em (0,0). As
 * faixas concêntricas usam a distância euclidiana **exata** ao centro (não a arredondada do frete):
 *
 *   | Faixa      | Regra        | Células |
 *   |------------|--------------|---------|
 *   | Capital    | d = 0        | 1       |
 *   | Founders   | 0 < d ≤ 4    | 48      |
 *   | Anel livre | 4 < d ≤ 5    | 32      |
 *   | Periferia  | d > 5        | o resto |
 *
 * A desigualdade exata (e não o `floor(d+0,5)` do frete) é o que preserva o disco de raio 4 com
 * as suas 48 células: pelo arredondamento, "distância 4" abrangeria 68 e o disco sumiria.
 * **Consequência aceita:** "distância 4" quer dizer uma coisa aqui e outra na conta do tributo.
 */
final class MapaFertways
{
    /** Número de células por eixo. Ímpar de propósito (D-51): garante uma célula central. */
    public const LADO = 101;

    /** Meia-largura: as coordenadas válidas vão de −RAIO a +RAIO. */
    public const RAIO = 50;

    public const CAPITAL_X = 0;

    public const CAPITAL_Y = 0;

    /** Limite superior (inclusivo) do disco de founders, em distância exata. */
    public const RAIO_FOUNDER = 4;

    /** Limite superior (inclusivo) do anel livre, em distância exata. */
    public const RAIO_ANEL = 5;

    /**
     * Distância em "slots de mapa", a unidade que o GDD usa para velocidade (§21.2, §21.3).
     *
     * Euclidiana, arredondada meio-para-cima. Não há curva do GDD aqui para reproduzir — a
     * tabela do §25.6 só usa distâncias inteiras —, então o arredondamento é escolha nossa e
     * não precisa do half-even das curvas de custo e tempo.
     *
     * **É esta a distância do frete e do tributo (§25.6).** O D-51 a mantém arredondada de
     * propósito; quem quer classificar faixas do mapa usa `distanciaExata`, não esta.
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

    /**
     * Distância euclidiana **exata**, sem arredondar. Só as faixas concêntricas do mapa (D-51)
     * a usam — o frete continua em `distancia`. Não misture as duas: é a distinção que dá ao
     * disco de founders as suas 48 células.
     */
    public static function distanciaExata(int $x1, int $y1, int $x2, int $y2): float
    {
        return sqrt(($x1 - $x2) ** 2 + ($y1 - $y2) ** 2);
    }

    public static function dentroDoMapa(int $x, int $y): bool
    {
        return abs($x) <= self::RAIO && abs($y) <= self::RAIO;
    }

    /** A Capital é o Mercado Central; nenhum colono se instala em cima dela. */
    public static function ehCapital(int $x, int $y): bool
    {
        return $x === self::CAPITAL_X && $y === self::CAPITAL_Y;
    }

    /**
     * A faixa concêntrica de uma célula, pela distância exata ao centro (D-51):
     * 'capital' | 'founder' | 'anel' | 'periferia'. Pressupõe a célula dentro do mapa.
     */
    public static function faixaDe(int $x, int $y): string
    {
        $d = self::distanciaExata($x, $y, self::CAPITAL_X, self::CAPITAL_Y);

        if ($d == 0.0) {
            return 'capital';
        }
        if ($d <= self::RAIO_FOUNDER) {
            return 'founder';
        }
        if ($d <= self::RAIO_ANEL) {
            return 'anel';
        }

        return 'periferia';
    }

    /**
     * As 48 células de founder, em ordem canônica e determinística: por (distância exata
     * crescente, ângulo em [0, 2π) crescente). Cada uma marca se é **reservada**.
     *
     * O privilégio do founder é o desenho, não um bug do sorteio (D-51, revoga parte do D-29):
     * quem chega antes escolhe um slot perto do Mercado e por isso viaja e paga menos.
     *
     * A repartição 28 populáveis + 20 reservados sai de uma regra do usuário (D-51): reservam-se
     * as de **índice par entre as 40 primeiras** (20 células), de função ainda indefinida
     * ("governo, alianças, convidados, npcs — no futuro decidiremos"). A ordem é determinística
     * de propósito: dois ambientes que rodem a semeadura têm de produzir o mesmo mapa.
     *
     * @return list<array{x:int,y:int,reservado:bool}>
     */
    public static function slotsFounder(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $celulas = [];
        for ($x = -self::RAIO_FOUNDER; $x <= self::RAIO_FOUNDER; $x++) {
            for ($y = -self::RAIO_FOUNDER; $y <= self::RAIO_FOUNDER; $y++) {
                $d2 = $x * $x + $y * $y;
                // 0 < d ≤ 4  ⇔  0 < d² ≤ 16. Compara d² inteiro para não depender do float.
                if ($d2 > 0 && $d2 <= self::RAIO_FOUNDER * self::RAIO_FOUNDER) {
                    $angulo = atan2($y, $x);
                    if ($angulo < 0) {
                        $angulo += 2 * M_PI;
                    }
                    $celulas[] = ['x' => $x, 'y' => $y, 'd2' => $d2, 'ang' => $angulo];
                }
            }
        }

        usort($celulas, function (array $a, array $b): int {
            return $a['d2'] <=> $b['d2'] ?: $a['ang'] <=> $b['ang'];
        });

        $slots = [];
        foreach ($celulas as $i => $c) {
            // Índice par entre os 40 primeiros → reservado. Sobram 20 reservados e 28 populáveis.
            $reservado = $i < 40 && $i % 2 === 0;
            $slots[] = ['x' => $c['x'], 'y' => $c['y'], 'reservado' => $reservado];
        }

        return $cache = $slots;
    }

    /** É uma célula de founder **populável** (founder e não reservada)? */
    public static function ehFounderPopulavel(int $x, int $y): bool
    {
        foreach (self::slotsFounder() as $slot) {
            if ($slot['x'] === $x && $slot['y'] === $y) {
                return ! $slot['reservado'];
            }
        }

        return false;
    }

    /**
     * Uma célula é **fundável** se está no mapa e é founder populável **ou** periferia LIBERADA
     * (D-51 pro disco de founders; D-147 pra periferia). Capital, anel livre, os slots reservados
     * e as **células de zona neutra** ficam de fora sempre — os 4 distritos originais (D-52) e
     * qualquer zona que o Dôno tenha criado depois (D-148).
     *
     * **Nem `$periferiaLiberada` nem `$ehZonaNeutra` têm default, de propósito.** Os dois exigem
     * uma consulta real a uma tabela (`founding_cells`, `neutral_zones`) que só quem chama sabe
     * fazer — esta função continua PURA, sem tocar banco. `$ehZonaNeutra` também não pode mais vir
     * de `ZonasNeutras::ehZonaNeutra()` sozinha (D-148): essa função só enxerga os 120 distritos
     * originais, e ficaria cega pra uma zona nova que o Dôno criou fora deles. Obrigar os dois
     * argumentos (em vez de um `= false`) força cada call site a decidir explicitamente, não a
     * esquecer.
     */
    public static function podeFundar(int $x, int $y, bool $periferiaLiberada, bool $ehZonaNeutra): bool
    {
        if (! self::dentroDoMapa($x, $y)) {
            return false;
        }

        // Zona neutra não é chão de colônia: os cantos são disputados, não colonizados (D-52).
        if ($ehZonaNeutra) {
            return false;
        }

        $faixa = self::faixaDe($x, $y);

        if ($faixa === 'periferia') {
            return $periferiaLiberada;
        }

        if ($faixa === 'founder') {
            return self::ehFounderPopulavel($x, $y);
        }

        return false;
    }
}
