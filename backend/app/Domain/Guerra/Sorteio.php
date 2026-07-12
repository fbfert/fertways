<?php

namespace App\Domain\Guerra;

/**
 * O dado da guerra (docs/decisoes.md D-66).
 *
 * A Invasão Direta e o Cerco são determinísticos: forças, fórmula, resultado. **A Sabotagem e a
 * Apreensão não são** — o §28.10 as resolve por chance ("60% de chance base", "chance de sucesso
 * compara o nível do Predador ao do Abrigo").
 *
 * Existe como classe, e não como um `random_int()` solto no meio da rodada, por uma razão só: **um
 * teste não pode afirmar nada sobre uma moeda que ele não controla.** O teste troca esta
 * implementação por uma que sempre tira cara (ou sempre coroa) e passa a poder afirmar o que
 * acontece nos dois casos. Sem isso, a sabotagem só seria testável estatisticamente, o que numa
 * suíte é o mesmo que não ser testável.
 */
class Sorteio
{
    /** Tira a sorte contra uma chance em basis points. 10000 = certeza, 0 = impossível. */
    public function sucesso(int $bps): bool
    {
        if ($bps <= 0) {
            return false;
        }

        if ($bps >= 10000) {
            return true;
        }

        return random_int(1, 10000) <= $bps;
    }
}
