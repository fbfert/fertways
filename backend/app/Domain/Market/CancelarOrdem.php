<?php

namespace App\Domain\Market;

use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\MarketOrder;
use Illuminate\Support\Facades\DB;

/**
 * Cancela uma ordem aberta e devolve o escrow restante (GDD §07: o Mercado "assegura entrega
 * financeira e reserva do lote" — se o negócio não acontece, a reserva volta ao dono).
 *
 * O recurso volta para a **conta no Mercado**, não para o estoque da colônia: ele nunca saiu da
 * doca. Buscá-lo continua exigindo um veículo (§25.8).
 */
class CancelarOrdem
{
    public function handle(Colony $colonia, MarketOrder $ordem): MarketOrder
    {
        if ($ordem->colony_id !== $colonia->id) {
            throw new DomainRuleException('ordem_de_outro_colono', 'Esta ordem não é sua.');
        }

        return DB::transaction(function () use ($colonia, $ordem) {
            // Relê com lock: a ordem pode estar sendo casada por outra transação agora mesmo.
            $o = MarketOrder::whereKey($ordem->id)->lockForUpdate()->first();

            if (! $o || ! $o->aberta()) {
                throw new DomainRuleException('ordem_nao_esta_aberta', 'A ordem já foi executada ou cancelada.');
            }

            $ref = "cancelamento:{$o->id}";

            if ($o->escrow_resource_qty > 0) {
                DB::table('market_accounts')
                    ->where('colony_id', $colonia->id)
                    ->where('resource_type', $o->resource_type)
                    ->increment('amount', $o->escrow_resource_qty);

                $this->lancar($colonia->id, $o->escrow_resource_qty, $o->resource_type, $ref);
            }

            if ($o->escrow_fert_micro > 0) {
                DB::table('colonies')->where('id', $colonia->id)->increment('fert_micro', $o->escrow_fert_micro);

                $this->lancar($colonia->id, $o->escrow_fert_micro, null, $ref);
            }

            $o->forceFill([
                'status' => 'cancelada',
                'escrow_resource_qty' => 0,
                'escrow_fert_micro' => 0,
            ])->save();

            return $o;
        });
    }

    private function lancar(int $colonyId, int $valor, ?string $recurso, string $ref): void
    {
        Ledger::create([
            'colony_id' => $colonyId,
            'type' => 'estorno',
            'amount' => $valor,
            'resource_type' => $recurso,
            'ref' => $ref,
            'created_at' => now(),
        ]);
    }
}
