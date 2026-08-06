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

    /**
     * O balanço de energia **operacional** da colônia (D-220).
     *
     * ## Por que separado de `porRecurso()`
     *
     * Ali a energia aparece como `produzido`/`consumido`, e o `consumido` soma duas coisas de
     * naturezas diferentes: o que **toda construção debita por hora só para existir** e o que as
     * **receitas** pediriam se rodassem. A segunda parcela é nominal — e quando falta energia ela
     * justamente **não acontece**, porque `converter()` não converte sem insumo.
     *
     * Somar as duas e chamar de déficit seria mostrar ao jogador um número que não descreve o mundo.
     * É o mesmo erro que quase foi publicado no D-219: taxa nominal tratada como previsão.
     *
     * O saldo daqui é o que **de fato** acontece toda hora, e é o que decide se sobra energia
     * guardada para uma receita. Negativo significa colônia construída além do que o Reator sustenta:
     * o estoque fica preso em zero (D-20) e nenhuma fábrica de conversão converte.
     *
     * @return array{gerada: int, operacional: int, saldo: int}
     */
    public function energiaOperacional(Colony $colony): array
    {
        $n = $this->tick->taxasNominais($colony);

        $gerada = (int) round($n['taxas']['energia'] ?? 0);
        $operacional = (int) round($n['consumoEnergia']);

        return ['gerada' => $gerada, 'operacional' => $operacional, 'saldo' => $gerada - $operacional];
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
