<?php

namespace App\Domain\Colony;

use App\Models\Colony;

/**
 * A capacidade do Tanque de Combustível, por nível (§21.9, D-131) — a única curva de
 * armazenamento que o GDD publica para uma construção do slot principal (o §19.6, da Zona
 * Neutra, é outra).
 *
 * "Armazena Gelo de Metano refinado" (§21.9) não bate limpo com nenhum `resource_type` do
 * catálogo: Gelo de Metano é o minério bruto (raro, insumo de construção), sem forma "refinada"
 * própria. Biocombustível é o recurso que o jogo já produz por refino (Destilaria, §18.2) e cuja
 * semântica de "combustível" já existe no nome — é ele que o Tanque capa. Arbitragem do usuário,
 * D-131.
 *
 * Por decisão do usuário (D-131): o teto TRAVA a produção — a Destilaria para de converter
 * Biomassa+Energia assim que o Tanque enche, sem descartar excedente nem consumir o insumo à
 * toa. É o mesmo comportamento do Depósito da Capital (D-58, entrega recusada quando não cabe),
 * não o do Depósito de Zona Neutra (D-66/D-107, teto que só marca "exposto" e nunca bloqueia —
 * revertido de propósito porque zerava o saque de guerra). Aqui não há saque em jogo, então o
 * risco que motivou aquela reversão não se aplica.
 */
class TetoDoTanque
{
    private const CAPACIDADE = [1 => 200, 2 => 300, 3 => 450, 4 => 675, 5 => 1012];

    public const RECURSO = 'biocombustivel';

    /** Zero quando não há Tanque (ainda não construído): nada cabe, a Destilaria não converte. */
    public function capacidade(Colony $colonia): int
    {
        $nivel = (int) $colonia->buildings()->where('type', 'tanque_de_combustivel')->value('level');

        return self::CAPACIDADE[$nivel] ?? 0;
    }
}
