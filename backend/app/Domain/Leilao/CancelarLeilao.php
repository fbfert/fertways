<?php

namespace App\Domain\Leilao;

use App\Exceptions\DomainRuleException;
use App\Models\Auction;
use App\Models\Colony;
use App\Models\Ledger;
use Illuminate\Support\Facades\DB;

/**
 * Cancela um leilão sem lance (D-129).
 *
 * Só sem lance: uma vez que alguém comprometeu Fert$ contando com o prazo publicado, tirar o lote
 * da mesa seria calote do lado do vendedor. É a mesma leitura que o Acordo de Troca já faz do
 * calote no §26.5 — só que aqui a regra impede o cancelamento em vez de puni-lo depois.
 */
class CancelarLeilao
{
    public function handle(Colony $colonia, Auction $leilao): Auction
    {
        if ($leilao->colony_id !== $colonia->id) {
            throw new DomainRuleException('leilao_de_outro_colono', 'Este leilão não é seu.');
        }

        return DB::transaction(function () use ($colonia, $leilao) {
            $l = Auction::whereKey($leilao->id)->lockForUpdate()->first();

            if (! $l || ! $l->aberto()) {
                throw new DomainRuleException('leilao_nao_esta_aberto', 'O leilão já foi arrematado, venceu ou foi cancelado.');
            }

            if ($l->lance_colony_id !== null) {
                throw new DomainRuleException('leilao_com_lance', 'Este leilão já tem lance e não pode mais ser cancelado.');
            }

            DB::table('market_accounts')
                ->where('colony_id', $colonia->id)
                ->where('resource_type', $l->resource_type)
                ->increment('amount', $l->qty);

            Ledger::create([
                'colony_id' => $colonia->id,
                'type' => 'estorno',
                'amount' => $l->qty,
                'resource_type' => $l->resource_type,
                'ref' => "leilao:{$l->id}:cancelamento",
                'created_at' => now(),
            ]);

            $l->forceFill(['status' => 'cancelado'])->save();

            return $l;
        });
    }
}
