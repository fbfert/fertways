<?php

namespace App\Domain\Embaixada;

use App\Models\Colony;
use App\Models\CredencialComercial;
use Carbon\CarbonInterface;

/**
 * A consulta: que credencial esta colônia tem com este planeta, e quanto ela desconta (D-289).
 */
class Credenciais
{
    public function vigente(Colony $colonia, int $planetaId, CarbonInterface $quando): ?CredencialComercial
    {
        return CredencialComercial::where('colony_id', $colonia->id)
            ->where('planeta_npc_id', $planetaId)
            ->where('valida_ate', '>', $quando)
            ->orderByDesc('valida_ate')
            ->first();
    }

    /**
     * O desconto sobre a alíquota do imposto interplanetário, em bps (5.000 = metade). Travado na
     * emissão: o operador mudar o desconto não mexe numa credencial já paga.
     */
    public function descontoBps(Colony $colonia, int $planetaId, CarbonInterface $quando): int
    {
        return (int) ($this->vigente($colonia, $planetaId, $quando)?->desconto_bps ?? 0);
    }
}
