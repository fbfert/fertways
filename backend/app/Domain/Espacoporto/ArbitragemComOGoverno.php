<?php

namespace App\Domain\Espacoporto;

use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use Illuminate\Support\Facades\DB;

/**
 * Um planeta NPC não paga por um recurso mais do que o Governo cobra por ele no Mercado Central
 * (D-278, D-279). Se pagasse, comprar do Governo e revender ao planeta seria lucro garantido.
 *
 * A partida do Espaçoporto resolve os conflitos tirando a compra do planeta
 * (`EspacoportoPartidaSeeder::semCompraAcimaDoGoverno`). Depois dela, o painel recusa criar um conflito
 * novo pelos dois lados: subir a compra de um planeta acima da oferta do Governo, ou baixar a oferta
 * do Governo abaixo do que um planeta paga. Pagar o mesmo valor é permitido, porque ali não há lucro.
 * A venda do planeta não entra nesta regra. Também vale contra planeta fechado: abrir um planeta é
 * só um clique, e o conflito estaria pronto.
 */
class ArbitragemComOGoverno
{
    /**
     * Recusa uma tabela de preços de planeta que pague acima do Governo.
     *
     * @param  array<string, array{compra_micro: ?int}>  $precos  recurso => preços, como o painel os monta
     */
    public function conferirPlaneta(array $precos): void
    {
        $governo = DB::table('market_orders')
            ->whereNull('colony_id')->where('side', 'sell')->whereIn('status', ['aberta', 'parcial'])
            ->whereIn('resource_type', array_keys($precos))
            ->pluck('price_micro', 'resource_type');

        $conflitos = [];
        foreach ($precos as $recurso => $p) {
            if ($p['compra_micro'] !== null && isset($governo[$recurso]) && $p['compra_micro'] > $governo[$recurso]) {
                $conflitos[] = "{$recurso} (o Governo vende a {$this->fert((int) $governo[$recurso])})";
            }
        }

        if ($conflitos !== []) {
            throw new DomainRuleException(
                'arbitragem_com_governo',
                'O planeta pagaria mais do que o Governo cobra no Mercado Central, e comprar de um para '
                .'vender ao outro seria lucro garantido: '.implode(', ', $conflitos).'. Baixe a compra '
                .'até o preço do Governo, ou deixe-a vazia.',
            );
        }
    }

    /** Recusa uma oferta do Governo abaixo do que algum planeta paga pelo mesmo recurso. */
    public function conferirGoverno(string $recurso, int $precoMicro): void
    {
        $acima = DB::table('planeta_npc_precos as p')
            ->join('planetas_npc as n', 'n.id', '=', 'p.planeta_npc_id')
            ->where('p.resource_type', $recurso)
            ->where('p.compra_micro', '>', $precoMicro)
            ->orderBy('n.ordem')
            ->get(['n.nome', 'p.compra_micro']);

        if ($acima->isNotEmpty()) {
            $quem = $acima->map(fn ($l) => "{$l->nome} paga {$this->fert((int) $l->compra_micro)}")->implode(', ');

            throw new DomainRuleException(
                'arbitragem_com_planeta',
                "O Governo não pode vender {$recurso} a {$this->fert($precoMicro)}: {$quem}. Comprar do "
                .'Governo e revender ao planeta seria lucro garantido. Suba o preço, ou baixe antes a '
                .'compra do planeta no Espaçoporto.',
            );
        }
    }

    private function fert(int $micro): string
    {
        return number_format($micro / Colony::MICRO_POR_FERT, 4, ',', '.').' Fert$';
    }
}
