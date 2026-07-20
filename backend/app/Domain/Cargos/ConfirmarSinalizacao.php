<?php

namespace App\Domain\Cargos;

use App\Exceptions\DomainRuleException;
use App\Models\CivicFlag;
use App\Models\Ledger;
use Illuminate\Support\Facades\DB;

/**
 * A equipe confirma uma sinalização (§14.2: "bônus por irregularidade confirmada"). O bônus só
 * paga aqui, nunca no ato de sinalizar — senão qualquer texto renderia Fert$ de graça.
 */
class ConfirmarSinalizacao
{
    public function handle(int $flagId): CivicFlag
    {
        return DB::transaction(function () use ($flagId) {
            $flag = CivicFlag::whereKey($flagId)->lockForUpdate()->first();

            if (! $flag) {
                throw new DomainRuleException('sinalizacao_inexistente', 'Sinalização não encontrada.');
            }

            if ($flag->confirmada()) {
                throw new DomainRuleException('ja_confirmada', 'Esta sinalização já foi confirmada.');
            }

            $flag->forceFill(['confirmado_em' => now()])->save();

            $colonia = $flag->user?->colony;

            // Sem colônia, a confirmação fica registrada (o histórico não some), sem pagar ninguém.
            if ($colonia) {
                $livre = TetoSemanal::livre($flag->user_id, $flag->kind);
                $valor = min(CargosCivicosSpecs::BONUS_MICRO, $livre);

                if ($valor > 0) {
                    DB::table('colonies')->where('id', $colonia->id)->increment('fert_micro', $valor);

                    Ledger::create([
                        'colony_id' => $colonia->id,
                        'type' => 'bonus_cargo_civico',
                        'amount' => $valor,
                        'resource_type' => null,
                        'ref' => "cargo:{$flag->kind}:{$flag->user_id}:bonus:{$flag->id}",
                        'created_at' => now(),
                    ]);
                }
            }

            return $flag;
        });
    }
}
