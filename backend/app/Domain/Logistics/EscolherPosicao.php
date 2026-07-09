<?php

namespace App\Domain\Logistics;

use App\Exceptions\DomainRuleException;
use App\Models\Colony;

/**
 * Onde o colono se instala no mapa.
 *
 * O GDD não define a regra de alocação de slots. Sorteamos uma célula livre: qualquer regra
 * determinística (a próxima livre, a mais perto da Capital) daria vantagem sistemática de
 * logística a quem fundasse primeiro, e §25.6 diz que a posição deve importar de verdade.
 * Ver docs/decisoes.md D-29.
 */
class EscolherPosicao
{
    /** @return array{0: int, 1: int} */
    public function handle(): array
    {
        /*
         * Com 10.000 células e poucos colonos, o sorteio acerta uma célula livre quase sempre na
         * primeira tentativa. O limite existe para não girar para sempre num mapa cheio — nesse
         * caso o erro é honesto, e não um laço infinito num request.
         */
        for ($tentativa = 0; $tentativa < 200; $tentativa++) {
            $x = random_int(0, MapaFertways::LADO - 1);
            $y = random_int(0, MapaFertways::LADO - 1);

            if (MapaFertways::ehCapital($x, $y)) {
                continue;
            }

            if (! Colony::where('x', $x)->where('y', $y)->exists()) {
                return [$x, $y];
            }
        }

        throw new DomainRuleException('mapa_lotado', 'Não há slot livre no mapa.');
    }
}
