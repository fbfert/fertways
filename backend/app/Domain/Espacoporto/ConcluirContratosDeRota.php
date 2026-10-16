<?php

namespace App\Domain\Espacoporto;

use App\Domain\Guerra\Sorteio;
use App\Domain\Treasury\Tesouro;
use App\Models\Colony;
use App\Models\ContratoDeRota;
use App\Models\Ledger;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * O Cargueiro pousa: a venda vira Fert$ e a encomenda entra no estoque, pelo preço travado na
 * partida (D-262). Roda no tick.
 *
 * A encomenda entra inteira mesmo acima do teto de estoque, como toda entrega (D-205): o teto governa
 * a produção, não o que chega de fora.
 *
 * ## O sinistro e o imposto (D-269)
 *
 * O risco do planeta (§03: "nenhum", "baixo", "alto") é sorteado uma vez por viagem, com a moeda
 * da guerra (`Sorteio`). Se dá sinistro, ele leva a mesma fração das duas pernas: o planeta só paga
 * pelo que chegou, e da encomenda chega o resto — o que se pagou na partida não volta. É o preço de ir
 * longe. A venda paga o imposto do recurso sobre o que o planeta pagou (`ImpostoInterplanetario`).
 */
class ConcluirContratosDeRota
{
    public function __construct(
        private readonly Sorteio $sorteio,
        private readonly ImpostoInterplanetario $imposto,
        private readonly Tesouro $tesouro,
    ) {}

    /** @return int quantos contratos voltaram */
    public function handle(CarbonInterface $agora): int
    {
        $ids = ContratoDeRota::where('status', ContratoDeRota::EM_VIAGEM)
            ->where('chega_em', '<=', $agora)
            ->pluck('id');

        $voltaram = 0;

        foreach ($ids as $id) {
            $voltaram += DB::transaction(function () use ($id, $agora) {
                $k = ContratoDeRota::with('planeta')->whereKey($id)
                    ->where('status', ContratoDeRota::EM_VIAGEM)->lockForUpdate()->first();

                if (! $k) {
                    return 0;
                }

                $colonia = Colony::whereKey($k->colony_id)->lockForUpdate()->first();
                $ref = 'espacoporto:'.($k->planeta?->chave ?? $k->planeta_npc_id);

                $chance = (int) ($k->planeta?->risco_chance_bps ?? 0);
                $sinistro = $chance > 0 && $this->sorteio->sucesso($chance) ? (int) $k->planeta->risco_perda_bps : 0;
                $chegou = fn (array $perna) => array_map(
                    fn ($p) => ['qtd' => (int) $p['qtd'] - intdiv((int) $p['qtd'] * $sinistro, 10_000), 'preco_micro' => (int) $p['preco_micro']],
                    $perna,
                );
                $venda = $chegou($k->venda ?? []);
                $compra = $chegou($k->compra ?? []);
                $impostoDaVenda = 0;

                if ($colonia) {
                    $tributos = $this->imposto->sobre($colonia, $venda, $agora);
                    $receita = array_sum(array_column($tributos, 'base'));
                    $impostoDaVenda = array_sum(array_column($tributos, 'imposto'));

                    foreach ($tributos as $recurso => $linha) {
                        $this->imposto->registrar('espacoporto_venda', "rota:{$k->id}:venda:{$recurso}", $colonia, (string) $recurso, $linha, $agora);
                    }

                    if ($receita - $impostoDaVenda > 0) {
                        $colonia->increment('fert_micro', $receita - $impostoDaVenda);
                        Ledger::create([
                            'colony_id' => $colonia->id, 'type' => 'espacoporto_venda', 'amount' => $receita - $impostoDaVenda,
                            'resource_type' => null, 'ref' => $ref, 'created_at' => $agora,
                        ]);
                    }

                    if ($impostoDaVenda > 0) {
                        $this->tesouro->creditarFert($impostoDaVenda, "espacoporto_venda:{$k->id}");
                    }

                    foreach ($compra as $recurso => $p) {
                        if ($p['qtd'] <= 0) {
                            continue;
                        }
                        $linha = $colonia->resources()->firstOrCreate(['resource_type' => $recurso], ['amount' => 0]);
                        $linha->increment('amount', (int) $p['qtd']);
                        Ledger::create([
                            'colony_id' => $colonia->id, 'type' => 'espacoporto_compra', 'amount' => (int) $p['qtd'],
                            'resource_type' => $recurso, 'ref' => $ref, 'created_at' => $agora,
                        ]);
                    }
                }

                $k->update([
                    'status' => ContratoDeRota::CONCLUIDO, 'concluido_em' => $agora,
                    'sinistro_bps' => $sinistro, 'imposto_venda_micro' => $impostoDaVenda,
                ]);

                return 1;
            });
        }

        return $voltaram;
    }
}
