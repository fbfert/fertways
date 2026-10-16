<?php

namespace App\Domain\Logistics;

use App\Exceptions\DomainRuleException;

/**
 * Velocidade, consumo e capacidade dos veículos de carga. Tudo daqui sai do GDD §21.2, §21.3
 * e §25.4 — nada é inventado.
 *
 * A §4.3 do aditivo v3.4 diz, textualmente: "Especificações de capacidade, velocidade, bateria,
 * recarga e consumo por distância permanecem as já definidas. Só as linhas de custo abaixo são
 * recalculadas." Ou seja, o recálculo 1,65× atinge o **custo** dos veículos, não estas specs.
 */
final class VeiculoSpecs
{
    /** Slots de mapa por minuto (§21.2: 4 · §21.3: 1,5). */
    private const VELOCIDADE = [
        'furgao_de_comercio' => 4.0,
        'caminhao_de_carga' => 1.5,
        // §17.5, §28.9: 10 slots por minuto (D-281).
        'nave_de_transporte_planetaria' => 10.0,
    ];

    /**
     * Combustível por minuto de viagem (§21.2: 1 kW/h · §21.3: 3 kW/h). A Nave queima **Gelo de
     * Metano**, 50 unidades por minuto de voo (§28.9, D-281); os outros, energia.
     */
    private const CONSUMO_POR_MINUTO = [
        'furgao_de_comercio' => 1.0,
        'caminhao_de_carga' => 3.0,
        'nave_de_transporte_planetaria' => 50.0,
    ];

    /** O que cada veículo queima. Quem não está aqui roda a energia. */
    private const COMBUSTIVEL = [
        'nave_de_transporte_planetaria' => 'gelo_de_metano',
    ];

    /** §25.4: 1.000 unidades de qualquer recurso = 1 m³. Furgão 6 m³; Caminhão 30 m³. */
    public const CAPACIDADE = [
        'furgao_de_comercio' => 6_000,
        'caminhao_de_carga' => 30_000,
        // §28.9: 4 m³ = 4.000 unidades por viagem.
        'nave_de_transporte_planetaria' => 4_000,
    ];

    /** O recurso que a viagem deste veículo consome: `energia`, ou `gelo_de_metano` na Nave. */
    public static function combustivel(string $tipo): string
    {
        return self::COMBUSTIVEL[$tipo] ?? 'energia';
    }

    public static function conhece(string $tipo): bool
    {
        return isset(self::VELOCIDADE[$tipo]);
    }

    private static function exigeTipo(string $tipo): void
    {
        if (! self::conhece($tipo)) {
            throw new DomainRuleException('veiculo_desconhecido', "Veículo sem specs no GDD: {$tipo}");
        }
    }

    /**
     * Duração de **um trecho**, em segundos, exata.
     *
     * A tabela do §25.6 publica minutos com uma casa decimal; ela é arredondamento de exibição,
     * não a grandeza. Guardar segundos inteiros mantém o tick coerente com o resto do motor,
     * que já trunca ao segundo (D-22).
     */
    public static function segundosDoTrecho(string $tipo, int $distanciaSlots): int
    {
        self::exigeTipo($tipo);

        return (int) round($distanciaSlots / self::VELOCIDADE[$tipo] * 60);
    }

    /**
     * Energia de **um trecho**, em kWh exatos (float). O GDD cobra "por distância percorrida,
     * não por tempo de viagem" (§21.1) — como a velocidade é constante, dá no mesmo, e a tabela
     * do §25.6 confere.
     */
    public static function energiaDoTrecho(string $tipo, int $distanciaSlots): float
    {
        self::exigeTipo($tipo);

        $minutos = $distanciaSlots / self::VELOCIDADE[$tipo];

        return $minutos * self::CONSUMO_POR_MINUTO[$tipo];
    }

    /**
     * Energia debitada no despacho, cobrindo ida e volta (D-30).
     *
     * Energia é recurso inteiro no estoque, e a viagem pode custar fração de kWh. Arredondamos
     * **para cima**: o colono não compra meio kWh, e cobrar a menos criaria energia do nada.
     */
    public static function energiaDaViagem(string $tipo, int $distanciaSlots): int
    {
        return self::energiaDasPernas($tipo, [$distanciaSlots, $distanciaSlots]);
    }

    /**
     * Energia das pernas que a viagem **vai de fato rodar** (D-65).
     *
     * Até aqui toda viagem era ida e volta pelo mesmo caminho, e uma distância bastava. Não basta
     * mais: o veículo que leva carga ao depósito **fica na Capital** (uma perna só), e o que sai do
     * Pátio para outro colono entrega e **segue para casa** (duas pernas, de distâncias diferentes).
     * Cada perna paga a sua, e a soma é arredondada uma vez só — arredondar perna a perna cobraria
     * até 1 kWh a mais numa viagem que antes custava o mesmo.
     *
     * @param  int[]  $distanciasSlots  uma entrada por perna, na ordem em que serão rodadas
     */
    public static function energiaDasPernas(string $tipo, array $distanciasSlots): int
    {
        $kwh = 0.0;

        foreach ($distanciasSlots as $distancia) {
            $kwh += self::energiaDoTrecho($tipo, $distancia);
        }

        return (int) ceil($kwh);
    }

    public static function capacidade(string $tipo): int
    {
        self::exigeTipo($tipo);

        return self::CAPACIDADE[$tipo];
    }
}
