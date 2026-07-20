<?php

namespace App\Domain\Cargos;

use App\Exceptions\DomainRuleException;
use App\Models\CivicFlag;
use App\Models\CivicPost;
use App\Models\User;

/**
 * O "ato" do Fiscal de Mercado e do Auxiliar de Tesouro (§14.2): "sinaliza... para a Secretaria de
 * Finanças" / "aponta inconsistências". Texto livre, como a evidência de uma denúncia do
 * Ministério — a equipe é quem confirma e decide se paga o bônus (`ConfirmarSinalizacao`).
 */
class SinalizarCargo
{
    private const KINDS_QUE_SINALIZAM = [
        CargosCivicosSpecs::FISCAL_DE_MERCADO,
        CargosCivicosSpecs::AUXILIAR_DE_TESOURO,
    ];

    public function handle(User $colono, string $kind, string $motivo): CivicFlag
    {
        if (! in_array($kind, self::KINDS_QUE_SINALIZAM, true)) {
            throw new DomainRuleException('cargo_nao_sinaliza', 'Este cargo não sinaliza nada.');
        }

        $ocupa = CivicPost::where('user_id', $colono->id)->where('kind', $kind)->whereNull('suspenso_em')->exists();

        if (! $ocupa) {
            throw new DomainRuleException('sem_cargo', 'Você não ocupa este cargo (ou está suspenso).');
        }

        $motivo = trim($motivo);

        if ($motivo === '') {
            throw new DomainRuleException('motivo_obrigatorio', 'Descreva o que parece suspeito.');
        }

        return CivicFlag::create([
            'user_id' => $colono->id,
            'kind' => $kind,
            'motivo' => mb_substr($motivo, 0, 500),
            'confirmado_em' => null,
            'created_at' => now(),
        ]);
    }
}
