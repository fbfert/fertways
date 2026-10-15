<?php

namespace App\Domain\Espacoporto;

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
 */
class ConcluirContratosDeRota
{
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

                if ($colonia) {
                    $receita = array_sum(array_map(fn ($p) => (int) $p['qtd'] * (int) $p['preco_micro'], $k->venda ?? []));

                    if ($receita > 0) {
                        $colonia->increment('fert_micro', $receita);
                        Ledger::create([
                            'colony_id' => $colonia->id, 'type' => 'espacoporto_venda', 'amount' => $receita,
                            'resource_type' => null, 'ref' => $ref, 'created_at' => $agora,
                        ]);
                    }

                    foreach ($k->compra ?? [] as $recurso => $p) {
                        $linha = $colonia->resources()->firstOrCreate(['resource_type' => $recurso], ['amount' => 0]);
                        $linha->increment('amount', (int) $p['qtd']);
                        Ledger::create([
                            'colony_id' => $colonia->id, 'type' => 'espacoporto_compra', 'amount' => (int) $p['qtd'],
                            'resource_type' => $recurso, 'ref' => $ref, 'created_at' => $agora,
                        ]);
                    }
                }

                $k->update(['status' => ContratoDeRota::CONCLUIDO, 'concluido_em' => $agora]);

                return 1;
            });
        }

        return $voltaram;
    }
}
