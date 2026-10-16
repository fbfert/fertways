<?php

namespace App\Domain\Transport;

use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Vehicle;

/**
 * A Plataforma de Pouso do slot, o hangar da Nave de Transporte Planetária (D-281).
 *
 * O GDD (§17.2, §17.5): "Hangar onde as Naves de Transporte Planetária ficam estacionadas. Upgrades
 * aumentam capacidade de naves estacionadas e reduzem tempo de construção." Não dá número para
 * nenhuma das duas coisas. Os números são meus:
 *
 * - **uma nave por nível.** Sem Plataforma, não há onde a Nave pousar em casa, e ela não se compra;
 * - **5% a menos no upgrade da Nave por nível acima do primeiro**, até 45% no nível 10. A Nave não é
 *   construída pelo colono (vem do Ministério, D-60), então o "tempo de construção" que a Plataforma
 *   encurta é o do serviço de upgrade dela (D-259), que é o tempo de obra que o colono espera.
 *
 * A Nave conta também no teto de frota da Central de Transportes (D-60: "o teto conta todos os
 * veículos"). Precisa das duas vagas.
 */
class Hangar
{
    /** Quanto cada nível acima do primeiro corta do upgrade da Nave, em bps. */
    public const CORTE_DE_UPGRADE_POR_NIVEL_BPS = 500;

    public function nivel(Colony $colonia): int
    {
        return (int) $colonia->buildings()->where('type', 'plataforma_de_pouso')->max('level');
    }

    public function vagas(Colony $colonia): int
    {
        return $this->nivel($colonia);
    }

    /** Naves do colono, em casa, no ar ou numa zona. Todas voltam para esta Plataforma. */
    public function naves(Colony $colonia): int
    {
        return $colonia->vehicles()->where('type', Vehicle::NAVE)->count();
    }

    public function exigirVaga(Colony $colonia): void
    {
        $vagas = $this->vagas($colonia);

        if ($vagas < 1) {
            throw new DomainRuleException(
                'sem_plataforma_de_pouso',
                'A Nave de Transporte Planetária precisa de uma Plataforma de Pouso na colônia para estacionar.',
            );
        }

        if ($this->naves($colonia) >= $vagas) {
            throw new DomainRuleException(
                'plataforma_cheia',
                "A sua Plataforma de Pouso nível {$vagas} estaciona {$vagas} nave(s), e estão todas ocupadas. Suba a Plataforma.",
            );
        }
    }

    /** A fração do tempo de upgrade da Nave que sobra com esta Plataforma, em bps. */
    public function fatorDeUpgradeBps(Colony $colonia): int
    {
        $acima = max(0, $this->nivel($colonia) - 1);

        return 10_000 - min(4_500, $acima * self::CORTE_DE_UPGRADE_POR_NIVEL_BPS);
    }
}
