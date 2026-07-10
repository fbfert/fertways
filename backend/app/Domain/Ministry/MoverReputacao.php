<?php

namespace App\Domain\Ministry;

use App\Domain\Trade\AcordoSpecs;
use App\Models\User;
use RuntimeException;

/**
 * Move **um** dos quatro índices do §26.2, e nunca mais de um.
 *
 * O §26.9 veda compensação cruzada: "cada índice é isolado — apenas ações dentro da própria
 * categoria recuperam aquele índice específico". Um método que aceitasse dois índices de uma vez
 * seria a porta por onde a vedação vazaria.
 *
 * A escala é a mesma do §26.2 (0 a 1000) e o clamp é o do `AcordoSpecs`: uma condenação de −250 não
 * deixa saldo negativo pendurado, que exigiria vinte e cinco acordos honestos só para voltar a zero.
 */
class MoverReputacao
{
    public const INDICES = [
        'confianca_comercial',
        'conduta_social',
        'status_civico',
        'honra_militar_diplomatica',
    ];

    /** @return int o valor do índice depois do movimento */
    public function somar(User $usuario, string $indice, int $delta): int
    {
        if (! in_array($indice, self::INDICES, true)) {
            throw new RuntimeException("Índice de reputação inexistente: {$indice}");
        }

        $fresco = User::whereKey($usuario->id)->lockForUpdate()->firstOrFail();

        $novo = AcordoSpecs::limitar($fresco->{$indice} + $delta);
        $fresco->{$indice} = $novo;
        $fresco->save();

        return $novo;
    }
}
