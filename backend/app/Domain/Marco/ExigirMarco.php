<?php

namespace App\Domain\Marco;

use App\Exceptions\DomainRuleException;
use App\Models\Colony;

/**
 * O gate do §05 (D-75) — com a regra da POSSE PRESERVADA, arbitrada pelo usuário.
 *
 * O gate barra a **aquisição nova**, nunca o que o colono já tem: quem ocupou uma zona antes do
 * D-75 continua com ela (nenhum código revoga posse); só ocupar OUTRA exige o marco. É por isso
 * que esta classe só é chamada nos atos de adquirir, e nunca em leitura ou manutenção do que existe.
 *
 * Os dois gates vivos hoje (§05, com a precedência do §05 sobre o §03 na mesma parte):
 *
 *   marco 10 (Pioneiro)     fabricar Drone nível 2+  ("drone nível 2" — o nível 1 nunca teve gate)
 *   marco 20 (Desbravador)  ocupar zona neutra
 *
 * ⚠️ **O Mercado Central NÃO tem gate, e isso contraria o §05 de propósito** (que o põe no marco 5).
 * O §03 promete ao recém-chegado "a compra do primeiro lote de Ligas Metálicas no Mercado Central
 * antes de existir produção própria" — os 50 Fert$ iniciais existem PARA isso. Gatear o Mercado
 * quebraria o onboarding publicado. Contradição consciente, registrada no D-75: não a "conserte".
 *
 * Os demais desbloqueios do §05 (cargueiro, mineração profunda, federação, voto…) ganham gate no
 * dia em que os sistemas existirem.
 */
class ExigirMarco
{
    public function exigir(Colony $colony, int $marcoMinimo, string $oQue): void
    {
        $atual = Curva::marco((int) $colony->xp);

        if ($atual >= $marcoMinimo) {
            return;
        }

        $titulo = Curva::titulo($marcoMinimo);
        $falta = Curva::xpDoMarco($marcoMinimo) - (int) $colony->xp;

        throw new DomainRuleException(
            'marco_insuficiente',
            "{$oQue} exige o marco {$marcoMinimo} ({$titulo}) — você está no {$atual}. "
            ."Faltam {$falta} XP: construa, comercie, cumpra Acordos.",
        );
    }
}
