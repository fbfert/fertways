<?php

namespace App\Domain\Federacao;

use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\FederationHolding;
use App\Models\FederationLedger;
use App\Models\Ledger;
use Illuminate\Support\Facades\DB;

/**
 * Líder ou Intendente sacam do fundo da federação direto para o estoque da própria colônia, sem
 * veículo (docs/decisoes.md D-114) — mais perto de `Tesouro::distribuir` do que de uma entrega
 * comercial. Só a ENTRADA no fundo é amarrada a logística física (decisão do usuário); a saída é
 * administração interna da federação.
 */
class SacarDoFundo
{
    public function handle(Colony $colony, string $recurso, int $qtd): void
    {
        if ($qtd <= 0) {
            throw new DomainRuleException('quantidade_invalida', 'A quantidade tem de ser positiva.');
        }

        DB::transaction(function () use ($colony, $recurso, $qtd) {
            $colony = Colony::whereKey($colony->id)->lockForUpdate()->firstOrFail();

            if ($colony->federation_id === null) {
                throw new DomainRuleException('sem_federacao', 'Sua colônia não está em nenhuma federação.');
            }

            if (! $colony->podeSacarDoFundo()) {
                throw new DomainRuleException('sem_permissao', 'Só o Líder ou o Intendente sacam do fundo.');
            }

            // `where amount >= qtd` no UPDATE: nunca saca além do saldo, mesmo em corrida — mesma
            // guarda atômica de `Tesouro::distribuir`.
            $baixou = FederationHolding::where('federation_id', $colony->federation_id)
                ->where('resource_type', $recurso)
                ->where('amount', '>=', $qtd)
                ->decrement('amount', $qtd);

            if ($baixou === 0) {
                throw new DomainRuleException('fundo_insuficiente', 'O fundo da federação não tem esse saldo.');
            }

            $colony->resources()->where('resource_type', $recurso)->increment('amount', $qtd);

            $ref = "federacao:saque:{$colony->federation_id}:{$colony->id}:".now()->getTimestampMs();

            FederationLedger::create([
                'federation_id' => $colony->federation_id,
                'colony_id' => $colony->id,
                'type' => 'saque',
                'amount' => -$qtd,
                'resource_type' => $recurso,
                'ref' => $ref,
                'created_at' => now(),
            ]);

            Ledger::create([
                'colony_id' => $colony->id,
                'type' => 'saque_federacao',
                'amount' => $qtd,
                'resource_type' => $recurso,
                'ref' => $ref,
                'created_at' => now(),
            ]);
        });
    }
}
