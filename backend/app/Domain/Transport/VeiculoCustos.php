<?php

namespace App\Domain\Transport;

use App\Exceptions\DomainRuleException;

/**
 * O custo em recursos de um veículo, no nível 1. **Sai do GDD** — §21.2 (Furgão) e §21.3 (Caminhão).
 *
 * Serve a duas coisas, e é fonte única das duas para que não divirjam:
 *
 *  - a **fabricação** do Caminhão pelo Ministério (o que sai do caixa do Tesouro);
 *  - a **manutenção** de qualquer um dos dois, que custa uma **fração** desta tabela (D-60) — e é
 *    por ser fração, e não constante própria, que a manutenção acompanha o GDD em vez de apodrecer.
 *
 * **Só o nível 1.** O D-60 decidiu que só ele é vendido, e o GDD nunca diz o que o nível de um
 * veículo muda. A partir do nível 2 as duas tabelas de custo do Caminhão **divergem** (§21.3 na
 * curva 1,50×; §20 na 1,65×) — a armadilha do D-37. **Reabra o D-37 antes de acrescentar níveis.**
 */
final class VeiculoCustos
{
    /** GDD §21.2 (Furgão de Comércio) e §21.3 (Caminhão de Carga), ambos no nível 1. */
    private const NIVEL_1 = [
        'furgao_de_comercio' => [
            'ligas_metalicas' => 40,
            'componentes_eletronicos' => 10,
            'metal_bruto' => 7,
        ],
        'caminhao_de_carga' => [
            'ligas_metalicas' => 90,
            'componentes_eletronicos' => 25,
            'metal_bruto' => 16,
        ],
    ];

    /** @return array<string,int> */
    public static function nivel1(string $tipo): array
    {
        if (! isset(self::NIVEL_1[$tipo])) {
            throw new DomainRuleException('veiculo_sem_custo', "Veículo sem tabela de custo no GDD: {$tipo}");
        }

        return self::NIVEL_1[$tipo];
    }
}
