<?php

namespace App\Domain\Federacao;

use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Federation;
use Illuminate\Support\Facades\DB;

/**
 * Líder ou Diplomata expulsam outro membro da federação (docs/decisoes.md D-114). Nunca o Líder
 * (transfira a liderança ou dissolva primeiro) e nunca a si mesmo (use `SairDaFederacao`).
 */
class ExpulsarMembro
{
    public function handle(Colony $ator, Colony $alvo): void
    {
        DB::transaction(function () use ($ator, $alvo) {
            $colonias = Colony::whereIn('id', [$ator->id, $alvo->id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $ator = $colonias->get($ator->id);
            $alvo = $colonias->get($alvo->id);

            if (! $ator || ! $ator->podeConvidarParaFederacao()) {
                throw new DomainRuleException('sem_permissao', 'Só o Líder ou o Diplomata expulsam.');
            }

            if ($alvo && $alvo->id === $ator->id) {
                throw new DomainRuleException('nao_se_expulsa', 'Use "Sair" para deixar a federação.');
            }

            if (! $alvo || $alvo->federation_id !== $ator->federation_id) {
                throw new DomainRuleException('nao_e_membro', 'Esta colônia não é membro da sua federação.');
            }

            if ($alvo->federation_role === Federation::LIDER) {
                throw new DomainRuleException(
                    'nao_expulsa_lider',
                    'O Líder não é expulso — ele transfere a liderança ou dissolve a federação.',
                );
            }

            $alvo->forceFill(['federation_id' => null, 'federation_role' => null])->save();
        });
    }
}
