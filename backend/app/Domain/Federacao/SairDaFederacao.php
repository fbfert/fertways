<?php

namespace App\Domain\Federacao;

use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\Federation;
use Illuminate\Support\Facades\DB;

/**
 * Uma colônia sai da própria federação (docs/decisoes.md D-114).
 *
 * O Líder não sai enquanto houver outros membros — tem de transferir a liderança primeiro
 * (`TransferirLideranca`), sem promoção automática silenciosa. Se for o último membro (o Líder
 * sozinho), sair dissolve a federação.
 */
class SairDaFederacao
{
    public function __construct(private readonly DissolverFederacao $dissolver) {}

    public function handle(Colony $colony): void
    {
        DB::transaction(function () use ($colony) {
            $colony = Colony::whereKey($colony->id)->lockForUpdate()->firstOrFail();

            if ($colony->federation_id === null) {
                throw new DomainRuleException('sem_federacao', 'Sua colônia não está em nenhuma federação.');
            }

            $federationId = $colony->federation_id;

            $outros = Colony::where('federation_id', $federationId)
                ->where('id', '!=', $colony->id)
                ->lockForUpdate()
                ->count();

            if ($colony->federation_role === Federation::LIDER && $outros > 0) {
                throw new DomainRuleException(
                    'lider_precisa_transferir',
                    'Transfira a liderança para outro membro antes de sair.',
                );
            }

            $colony->forceFill(['federation_id' => null, 'federation_role' => null])->save();

            if ($outros === 0) {
                $federation = Federation::whereKey($federationId)->firstOrFail();
                $this->dissolver->handle($federation);
            }
        });
    }
}
