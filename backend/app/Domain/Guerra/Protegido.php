<?php

namespace App\Domain\Guerra;

use App\Models\NeutralZone;

/**
 * O que é "estoque protegido" (docs/decisoes.md D-66).
 *
 * A expressão aparece **seis vezes** no GDD — o saque de 50% da Invasão Direta (§27.8), os 30% do
 * Cerco (§28.10), o alvo do Predador — e o mecanismo que protege **nunca é descrito em lugar
 * nenhum**. Era a lacuna 4 do D-52.
 *
 * Arbitrado: **está protegido o que couber na capacidade do Depósito de Zona Neutra** no nível
 * construído. O §19.6 publica essa capacidade (`500 … 19.222`), então não se inventa número
 * nenhum — e subir o Depósito passa a ter um sentido econômico que não tinha.
 *
 * O que EXCEDE a capacidade está exposto: o Depósito é o cofre, e o que transborda é butim.
 *
 * ⚠️ **Isto custou uma revisão da Fatia 1, e vale saber por quê.** A extração parava no teto do
 * Depósito (`min($unidades, $espaco)`), então o `deposit_amount` nunca o excedia — nada jamais
 * estaria exposto, e **o saque de 50% seria sempre zero**. A guerra não teria espólio nenhum. O
 * usuário decidiu (2026-07-12) que a extração **deixa de parar no teto**: o excedente empilha ao
 * relento na zona. Contraria o §19.6, que chama aqueles números de "capacidade", e é deliberado.
 *
 * O efeito no jogo é o que se queria: deixar mineral rendendo na zona vira risco de verdade,
 * retirá-lo vira hábito, e subir o Depósito vale a pena porque protege mais.
 */
class Protegido
{
    /**
     * Quanto do estoque da zona está a salvo de saque.
     *
     * Conta o **total** — o minério bruto e o que a Refinaria de Campo já converteu (D-67). Os dois
     * ocupam o mesmo Depósito. **Refinar não esconde recurso do inimigo**, só o torna mais valioso:
     * seria estranho que passar o minério por uma refinaria o tirasse do alcance de quem invade.
     */
    public function protegido(NeutralZone $zona): int
    {
        return min($zona->estoqueTotal(), $zona->capacidadeDeposito());
    }

    /** Quanto está exposto — é sobre isto que incidem os 50% e os 30%. */
    public function exposto(NeutralZone $zona): int
    {
        return max(0, $zona->estoqueTotal() - $zona->capacidadeDeposito());
    }

    /**
     * O butim de um saque, repartido entre o minério bruto e o refinado.
     *
     * A repartição é **proporcional ao que há de cada um**: quem invade uma zona metade refinada leva
     * metade de cada. O contrário — levar primeiro o refinado, que vale mais — premiaria o atacante
     * por uma escolha do defensor, e ninguém decidiu isso.
     *
     * @param  int  $bps  5000 na Invasão Direta (§27.8), 3000 no Cerco (§28.10).
     * @return array{bruto: int, refinado: int, total: int}
     */
    public function saqueDetalhado(NeutralZone $zona, int $bps): array
    {
        $total = intdiv($this->exposto($zona) * $bps, 10000);
        $estoque = $zona->estoqueTotal();

        if ($total <= 0 || $estoque <= 0) {
            return ['bruto' => 0, 'refinado' => 0, 'total' => 0];
        }

        // Proporcional, e o bruto absorve o arredondamento: o refinado é o que vale mais, e o
        // atacante não deve ganhar uma unidade a mais dele por um resto de divisão.
        $refinado = min($zona->refined_amount, intdiv($total * $zona->refined_amount, $estoque));
        $bruto = min($zona->deposit_amount, $total - $refinado);

        return ['bruto' => $bruto, 'refinado' => $refinado, 'total' => $bruto + $refinado];
    }

    /** O butim total, em unidades. Atalho de `saqueDetalhado()`. */
    public function saque(NeutralZone $zona, int $bps): int
    {
        return $this->saqueDetalhado($zona, $bps)['total'];
    }
}
