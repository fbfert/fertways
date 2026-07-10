<?php

namespace App\Domain\Trade;

use App\Exceptions\DomainRuleException;
use App\Models\Colony;

/**
 * §26.2: Confiança Comercial baixa "bloqueia o acesso a leilões, **Mercado Central** e ao cargo de
 * Fiscal de Mercado".
 *
 * O GDD nomeia o efeito e nunca publica o limiar. O usuário arbitrou 200 numa escala de 0 a 1000,
 * com todo colono nascendo em 500 (D-43) — seis calotes seguidos fecham a doca.
 *
 * §26.9 veda compensação: Status Cívico alto **não** reabre o Mercado para quem caloteou. Só
 * cumprir acordos recupera Confiança Comercial.
 */
final class AcessoAoMercado
{
    public static function permitido(Colony $colonia): bool
    {
        $usuario = $colonia->user;

        return $usuario !== null && $usuario->confianca_comercial >= AcordoSpecs::LIMIAR_MERCADO;
    }

    /** @throws DomainRuleException */
    public static function exigir(Colony $colonia): void
    {
        if (! self::permitido($colonia)) {
            throw new DomainRuleException(
                'confianca_comercial_baixa',
                'Sua Confiança Comercial está abaixo de '.AcordoSpecs::LIMIAR_MERCADO
                    .'. O Mercado Central está fechado para você até que cumpra Acordos de Troca.',
            );
        }
    }
}
