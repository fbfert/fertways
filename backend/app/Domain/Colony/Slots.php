<?php

namespace App\Domain\Colony;

use App\Exceptions\DomainRuleException;

/**
 * Os 22 slots de construção da colônia (D-59, D-105).
 *
 * **O GDD não tem isto.** Procurou-se `slot`, `terreno`, `lote`, `grade`: no GDD, "slot" é a
 * colônia do jogador vista do mapa do planeta (ou um dos 20 slots institucionais da Capital,
 * §2.1), nunca um espaço de construção. O documento não põe teto espacial nenhum — os limites
 * que ele conhece são o **energético** (§19.8: o Reator nível 1 sustenta as essenciais com folga
 * "permitindo que o jogador construa 2-3 estruturas adicionais") e o da **fila de obras**. Os 21
 * originais são arbitragem do usuário (2026-07-11); o 22º (D-105) é o Depósito Local.
 *
 * A forma é uma colmeia de linhas 4/4/5/4/4, simétrica nos dois eixos, mais uma linha solta de 1
 * ao final para o Depósito — um acréscimo, nunca uma inserção: inserir no meio deslocaria a
 * numeração de tudo que vem depois, e quebraria toda colônia que já tem construção erguida.
 *
 *      ⬡ ⬡ ⬡ ⬡          0  1  2  3
 *     ⬡ ⬡ ⬡ ⬡           4  5  6  7
 *    ⬡ ⬡ ⬢ ⬡ ⬡          8  9 10 11 12
 *     ⬡ ⬡ ⬡ ⬡          13 14 15 16
 *      ⬡ ⬡ ⬡ ⬡         17 18 19 20
 *        ⬢               21
 *
 * 21 não é número de anel hexagonal fechado (os anéis fecham em 1, 7 e 19), então a colmeia não
 * é feita de anéis: são linhas alternadas, e o centro é uma célula única (o 10) — mas o CENTRO
 * não pertence mais ao Reator, ver abaixo (D-142).
 */
class Slots
{
    /** Quantas células tem cada linha, de cima para baixo. A cena do front desenha a partir daqui. */
    public const LINHAS = [4, 4, 5, 4, 4, 1];

    public const TOTAL = 22;

    /**
     * O miolo: as 5 essenciais nascem no nível 1 na fundação (D-59), em posição fixa.
     *
     * O Gerador e a Estrutura ladeiam o centro da linha do meio; Fazenda e Captação de Água
     * ficam nas duas células adjacentes, uma acima e outra abaixo, simétricas em relação a ele.
     * O Reator, que nasceu no centro exato (10), foi trocado de lugar com o Depósito Local
     * (21) por pedido do usuário — o Depósito é a construção que o colono mais abre (é onde os
     * recursos moram desde o D-106), e o centro da colmeia é o slot mais visível/alcançável dela.
     * <span class="d">D-142</span>
     *
     * Isto vai **além** do §24.7, que subsidia o custo das 5 essenciais até o nível 3 mas não as
     * constrói ("o custo aparece normalmente na interface"). Nascer pronto é decisão do usuário.
     */
    public const MIOLO = [
        'gerador_de_atmosfera' => 9,
        'reator_de_energia' => 21,
        'estrutura_de_sobrevivencia' => 11,
        'fazenda' => 5,
        'captacao_de_agua' => 15,
    ];

    /**
     * O Depósito Local (D-105, fora do GDD): nasce erguido junto do miolo — mesma razão: sem ele
     * não há como ver os recursos (a barra que sempre mostrava a lista saiu do HUD; agora é
     * preciso abrir uma construção pra ver), e um colono não pode nascer sem enxergar o que tem
     * no depósito. **No centro exato da colmeia (10) desde o D-142** — trocou de lugar com o
     * Reator (era a linha solta do final, 21); é o slot mais visível/alcançável, e o Depósito é a
     * construção mais aberta pelo colono.
     */
    public const DEPOSITO_LOCAL = [
        'deposito_local' => 10,
    ];

    /** Todo slot com dono fixo — miolo ou Depósito — nunca escolhível pelo colono. */
    public static function reservados(): array
    {
        return [...array_values(self::MIOLO), ...array_values(self::DEPOSITO_LOCAL)];
    }

    /** Os slots que o colono pode escolher: todos menos os reservados. */
    public static function livres(): array
    {
        return array_values(array_diff(range(0, self::TOTAL - 1), self::reservados()));
    }

    public static function doMiolo(int $slot): bool
    {
        return in_array($slot, array_values(self::MIOLO), true);
    }

    public static function doDeposito(int $slot): bool
    {
        return in_array($slot, array_values(self::DEPOSITO_LOCAL), true);
    }

    /** Recusa slot fora da colmeia e slot reservado (miolo ou Depósito), que não é do colono. */
    public static function exigirEscolhivel(int $slot): void
    {
        if ($slot < 0 || $slot >= self::TOTAL) {
            throw new DomainRuleException(
                'slot_inexistente',
                'A colônia tem ' . self::TOTAL . ' slots, numerados de 0 a ' . (self::TOTAL - 1) . '.',
            );
        }

        if (self::doMiolo($slot)) {
            throw new DomainRuleException(
                'slot_do_miolo',
                'Este slot é do miolo da colônia: nele já nasceu uma das cinco construções essenciais.',
            );
        }

        if (self::doDeposito($slot)) {
            throw new DomainRuleException(
                'slot_do_deposito',
                'Este slot é do Depósito Local: ele já nasce erguido, e não pode ser trocado.',
            );
        }
    }
}
