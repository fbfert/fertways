<?php

namespace App\Domain\Endurance;

use App\Models\ColonyEndurancePiece;
use App\Models\Colony;

/**
 * O bônus das peças da Endurance (D-132): desconto de tributo, somado entre todas as peças que a
 * colônia possui, com teto (`EnduranceSpecs::TETO_DESCONTO_BPS`) — sem isso, uma coleção completa
 * das 32 peças zeraria o tributo, e o §06 exige integridade monetária.
 *
 * Mesmo formato de multiplicação do desconto de aliados (D-120, `ConcluirTrechos::aliquota()`):
 * `intdiv($bpsCheio * (10_000 - $desconto), 10_000)`. Plugado nos dois pontos onde o jogo já
 * calcula tributo — entrega por transporte (`ConcluirTrechos`) e venda no Mercado Central
 * (`ExecutarOrdem`) — depois do desconto de aliados, nunca antes: o de aliados é sobre o `tax_bps`
 * cheio do recurso (§8.3); o da Endurance é um bônus pessoal da colônia, por cima do que sobrou.
 */
class DescontoDeEndurance
{
    /** A soma dos bps de todas as peças possuídas, capada no teto. */
    public function desconto(Colony $colonia): int
    {
        $chaves = ColonyEndurancePiece::where('colony_id', $colonia->id)->pluck('peca_key');
        $catalogo = EnduranceSpecs::catalogo();

        $soma = 0;

        foreach ($chaves as $chave) {
            $soma += $catalogo[$chave]['desconto_tributo_bps'] ?? 0;
        }

        return min(EnduranceSpecs::TETO_DESCONTO_BPS, $soma);
    }

    /** Aplica o desconto sobre um `tax_bps` já calculado (cheio, ou já reduzido pelo D-120). */
    public function aplicar(int $bpsCheio, Colony $colonia): int
    {
        $desconto = $this->desconto($colonia);

        if ($desconto <= 0) {
            return $bpsCheio;
        }

        return intdiv($bpsCheio * (10_000 - $desconto), 10_000);
    }
}
