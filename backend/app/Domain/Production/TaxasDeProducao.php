<?php

namespace App\Domain\Production;

use App\Models\Colony;

/**
 * A taxa nominal — capacidade plena, sem clampagem de estoque — de cada recurso que a colônia
 * produz e consome por hora (docs/decisoes.md D-153). É a mesma leitura que `NeutralZone::
 * extracaoPorHora()`/`refinoPorHora()` já fazem pra zona neutra: um número determinístico, calculado
 * fresco a cada pedido, não uma projeção do que o tick realmente vai creditar (que depende de
 * insumo disponível — ver `ColonyTick::converter()`).
 *
 * Reaproveita `ColonyTick::taxasNominais()` (a mesma conta que o tick de verdade faz) e só
 * EXPANDE cada peça agregada — Destilaria, Refinaria Química, Indústria Siderúrgica, Oficina — na
 * dupla produzido/consumido por recurso que o tick nunca precisou separar (ele só soma tudo num
 * `$taxas` líquido, porque só precisa saber quanto creditar no estoque).
 */
class TaxasDeProducao
{
    public function __construct(private ColonyTick $tick)
    {
    }

    /** @return array<string, array{produzido: int, consumido: int}> */
    public function porRecurso(Colony $colony): array
    {
        $n = $this->tick->taxasNominais($colony);
        $r = [];

        $somar = function (string $recurso, string $lado, int|float $qtd) use (&$r): void {
            $qtd = (int) round($qtd);

            if ($qtd <= 0) {
                return;
            }

            $r[$recurso] ??= ['produzido' => 0, 'consumido' => 0];
            $r[$recurso][$lado] += $qtd;
        };

        // PRODUCAO_SEM_INSUMO: só soma, nunca subtrai — as 5 essenciais + Mina Local.
        foreach ($n['taxas'] as $recurso => $qtd) {
            $somar($recurso, 'produzido', $qtd);
        }

        $somar('energia', 'consumido', $n['consumoEnergia']);

        foreach ($n['consumosExtras'] as $recurso => $qtd) {
            $somar($recurso, 'consumido', $qtd);
        }

        if ($n['taxaDestilaria'] > 0) {
            foreach (ColonyTick::RECEITA_DESTILARIA as $insumo => $porUnidade) {
                $somar($insumo, 'consumido', $n['taxaDestilaria'] * $porUnidade);
            }
            $somar('biocombustivel', 'produzido', $n['taxaDestilaria']);
        }

        if ($n['taxaCompostos'] > 0) {
            foreach (ColonyTick::RECEITA_COMPOSTOS as $insumo => $porUnidade) {
                $somar($insumo, 'consumido', $n['taxaCompostos'] * $porUnidade);
            }
            $somar('compostos_quimicos', 'produzido', $n['taxaCompostos']);
        }

        if ($n['taxaSiderurgica'] > 0) {
            $somar(Siderurgica::INSUMO, 'consumido', $n['taxaSiderurgica']);

            foreach (Siderurgica::SAIDAS as $recurso => $porLote) {
                $somar($recurso, 'produzido', $n['taxaSiderurgica'] / Siderurgica::BASE * $porLote);
            }
        }

        foreach ($n['taxaComponentes'] as $codigo => $taxa) {
            if ($taxa <= 0) {
                continue;
            }

            foreach ($this->tick->receita($codigo) as $insumo => $porUnidade) {
                $somar($insumo, 'consumido', $taxa * $porUnidade);
            }
            $somar('componentes_eletronicos', 'produzido', $taxa);
        }

        return $r;
    }
}
