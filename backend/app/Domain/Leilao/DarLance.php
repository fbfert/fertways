<?php

namespace App\Domain\Leilao;

use App\Domain\Trade\AcessoAoMercado;
use App\Exceptions\DomainRuleException;
use App\Models\Auction;
use App\Models\Colony;
use App\Models\Ledger;
use Illuminate\Support\Facades\DB;

/**
 * Dá um lance num leilão aberto (D-129).
 *
 * O lance escrowa o Fert$ na hora — como o Mercado Central escrowa a compra ao abrir a ordem — e
 * quem é superado recebe de volta no mesmo instante, não no fechamento. Ninguém fica com Fert$
 * preso torcendo contra o próprio lance.
 */
class DarLance
{
    public function handle(Colony $colonia, int $leilaoId, int $lanceMicro): Auction
    {
        AcessoAoMercado::exigir($colonia);

        if ($lanceMicro <= 0) {
            throw new DomainRuleException('lance_invalido', 'O lance tem de ser positivo.');
        }

        return DB::transaction(function () use ($colonia, $leilaoId, $lanceMicro) {
            $leilao = Auction::whereKey($leilaoId)->lockForUpdate()->first();

            if (! $leilao || ! $leilao->aberto()) {
                throw new DomainRuleException('leilao_indisponivel', 'Este leilão não está mais aberto.');
            }

            if ($leilao->deadline_at->isPast()) {
                throw new DomainRuleException('leilao_encerrado', 'O prazo deste leilão já passou; aguarde o fechamento.');
            }

            // Mesmo motivo do D-58/D-26.4 na execução de ordem: leiloar para si mesmo simularia
            // interesse que não existe.
            if ($leilao->colony_id === $colonia->id) {
                throw new DomainRuleException('leilao_proprio', 'Você não pode dar lance no próprio leilão.');
            }

            $minimo = $leilao->lance_atual_micro !== null ? $leilao->lance_atual_micro + 1 : $leilao->lance_minimo_micro;

            if ($lanceMicro < $minimo) {
                throw new DomainRuleException(
                    'lance_baixo',
                    'O lance mínimo agora é '.number_format($minimo / Colony::MICRO_POR_FERT, 4, ',', '.').' Fert$.',
                );
            }

            $ref = "leilao:{$leilao->id}:lance:{$colonia->id}:".now()->getTimestamp();

            $pagou = DB::table('colonies')
                ->where('id', $colonia->id)
                ->where('fert_micro', '>=', $lanceMicro)
                ->decrement('fert_micro', $lanceMicro);

            if ($pagou === 0) {
                throw new DomainRuleException('fert_insuficiente', 'Fert$ insuficiente para este lance.');
            }

            $this->lancar($colonia->id, 'escrow_leilao', -$lanceMicro, $ref);

            // Devolve ao lance superado — imediato, não no fechamento (ver docblock da classe).
            if ($leilao->lance_colony_id !== null) {
                DB::table('colonies')->where('id', $leilao->lance_colony_id)->increment('fert_micro', $leilao->lance_atual_micro);
                $this->lancar($leilao->lance_colony_id, 'estorno', $leilao->lance_atual_micro, "leilao:{$leilao->id}:superado");
            }

            $leilao->forceFill([
                'lance_atual_micro' => $lanceMicro,
                'lance_colony_id' => $colonia->id,
            ])->save();

            return $leilao->fresh();
        });
    }

    private function lancar(int $colonyId, string $tipo, int $valor, string $ref): void
    {
        Ledger::create([
            'colony_id' => $colonyId,
            'type' => $tipo,
            'amount' => $valor,
            'resource_type' => null,
            'ref' => $ref,
            'created_at' => now(),
        ]);
    }
}
