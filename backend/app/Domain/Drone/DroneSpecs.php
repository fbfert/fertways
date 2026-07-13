<?php

namespace App\Domain\Drone;

/**
 * As specs do Drone de Exploração (docs/decisoes.md D-74; GDD §16.1, §21.4).
 *
 * O GDD publica quase tudo, e o que publica NÃO se arbitra:
 *
 *   bateria    24 36 54 81 122 horas por nível (§21.4, curva 1,50×)
 *   custo      Componentes 50 83 136 225 371 · Compostos 15 25 41 67 111 · Metal 4 7 11 18 30
 *              (§4.3 do aditivo v3.4 — a curva 1,65× vence o §21.4 pela regra do D-47)
 *   modos      "ida simples ou ida e volta, configurável por missão" (§21.4)
 *   recarga    "automática — armazenado e recarregado no Quartel" (§21.4)
 *   sem placa? tem placa e é vendável (§16.1); NÃO deprecia (§16.4)
 *
 * O que o GDD cala, o usuário arbitrou em 2026-07-13 (D-74):
 *
 *   velocidade      8 slots/min — o dobro do Furgão (4), abaixo da Nave (10). Fixa por nível:
 *                   o nível compra bateria e raio, não velocidade.
 *   raio            base 6 slots × 1,5 por nível (a curva do §19.1, arredondada para baixo):
 *                   6, 9, 13, 20, 30. É o raio da REVELAÇÃO ao redor da zona-alvo.
 *   persistência    os dois modos do §21.4, sem número inventado: ida e volta = FOTO DATADA
 *                   (permanente, envelhece); ida simples = VIGILÂNCIA ao vivo até a bateria
 *                   acabar — a bateria publicada É a persistência.
 *   fábrica         a Oficina (nível dela é o teto do nível do Drone) — o §21.4 diz que o
 *                   Quartel só ARMAZENA e recarrega.
 */
final class DroneSpecs
{
    public const TIPO = 'drone_de_exploracao';

    /** Slots por minuto. Arbitragem do usuário (D-74) — âncoras: Furgão 4, Caminhão 1,5, Nave 10. */
    public const VELOCIDADE = 8.0;

    /** Horas de bateria por nível (§21.4 — publicado, não mexa). */
    public const BATERIA_HORAS = [1 => 24, 2 => 36, 3 => 54, 4 => 81, 5 => 122];

    /** Raio de revelação em slots, por nível: 6 × 1,5^(N−1), para baixo (D-74). */
    public const RAIO = [1 => 6, 2 => 9, 3 => 13, 4 => 20, 5 => 30];

    /** O custo por nível (§4.3 v3.4 — a curva 1,65×; ver D-52 sobre as duas tabelas). */
    public const CUSTO = [
        1 => ['componentes_eletronicos' => 50, 'compostos_quimicos' => 15, 'metal_bruto' => 4],
        2 => ['componentes_eletronicos' => 83, 'compostos_quimicos' => 25, 'metal_bruto' => 7],
        3 => ['componentes_eletronicos' => 136, 'compostos_quimicos' => 41, 'metal_bruto' => 11],
        4 => ['componentes_eletronicos' => 225, 'compostos_quimicos' => 67, 'metal_bruto' => 18],
        5 => ['componentes_eletronicos' => 371, 'compostos_quimicos' => 111, 'metal_bruto' => 30],
    ];

    /** Duração de uma perna de voo, em segundos. Mesmo truncamento do VeiculoSpecs (D-22). */
    public static function segundosDoVoo(int $distanciaSlots): int
    {
        return (int) round($distanciaSlots / self::VELOCIDADE * 60);
    }
}
