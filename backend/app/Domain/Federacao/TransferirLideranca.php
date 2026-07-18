<?php

namespace App\Domain\Federacao;

use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Federation;
use Illuminate\Support\Facades\DB;

/**
 * O Líder passa o cargo para outro membro da mesma federação (docs/decisoes.md D-114) — passo
 * explícito e obrigatório antes de o Líder sair (`SairDaFederacao`), sem promoção automática.
 */
class TransferirLideranca
{
    public function handle(Colony $lider, Colony $alvo): void
    {
        DB::transaction(function () use ($lider, $alvo) {
            // Uma consulta só, ordenada por id: evita deadlock entre duas transferências
            // simultâneas que travariam as mesmas duas colônias em ordens opostas.
            $colonias = Colony::whereIn('id', [$lider->id, $alvo->id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $lider = $colonias->get($lider->id);
            $alvo = $colonias->get($alvo->id);

            if (! $lider || $lider->federation_role !== Federation::LIDER) {
                throw new DomainRuleException('sem_permissao', 'Só o Líder transfere a liderança.');
            }

            if ($alvo && $alvo->id === $lider->id) {
                throw new DomainRuleException('alvo_invalido', 'Você já é o Líder.');
            }

            if (! $alvo || $alvo->federation_id !== $lider->federation_id) {
                throw new DomainRuleException('nao_e_membro', 'Esta colônia não é membro da sua federação.');
            }

            $alvo->forceFill(['federation_role' => Federation::LIDER])->save();
            $lider->forceFill(['federation_role' => Federation::MEMBRO])->save();
        });
    }
}
