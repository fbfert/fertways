<?php

namespace App\Domain\Federacao;

use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Federation;
use Illuminate\Support\Facades\DB;

/**
 * O Líder promove/rebaixa um membro entre Diplomata, Intendente e Membro (docs/decisoes.md D-114).
 * Nunca mexe em `LIDER` — isso é exclusivo de `TransferirLideranca`, a guarda contra dois líderes
 * por acidente na mesma federação.
 */
class AlterarCargo
{
    private const ALTERAVEIS = [Federation::DIPLOMATA, Federation::INTENDENTE, Federation::MEMBRO];

    public function handle(Colony $lider, Colony $alvo, string $cargo): void
    {
        if (! in_array($cargo, self::ALTERAVEIS, true)) {
            throw new DomainRuleException(
                'cargo_invalido',
                'Cargo inválido — escolha Diplomata, Intendente ou Membro.',
            );
        }

        DB::transaction(function () use ($lider, $alvo, $cargo) {
            $colonias = Colony::whereIn('id', [$lider->id, $alvo->id])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $lider = $colonias->get($lider->id);
            $alvo = $colonias->get($alvo->id);

            if (! $lider || $lider->federation_role !== Federation::LIDER) {
                throw new DomainRuleException('sem_permissao', 'Só o Líder altera cargos.');
            }

            if ($alvo && $alvo->id === $lider->id) {
                throw new DomainRuleException(
                    'alvo_invalido',
                    'O Líder muda o próprio cargo transferindo a liderança.',
                );
            }

            if (! $alvo || $alvo->federation_id !== $lider->federation_id) {
                throw new DomainRuleException('nao_e_membro', 'Esta colônia não é membro da sua federação.');
            }

            $alvo->forceFill(['federation_role' => $cargo])->save();
        });
    }
}
