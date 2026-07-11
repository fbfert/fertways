<?php

namespace App\Domain\Finance;

use App\Exceptions\DomainRuleException;
use App\Models\PriceIntervention;
use App\Models\ResourceType;

/**
 * Declara e revoga intervenções de preço da Secretaria de Finanças (§06). Ver D-35 e D-56.
 *
 * As validações viviam no `fertways:intervencao`; foram extraídas para o comando e o painel de
 * administração compartilharem. Teto e piso chegam já em micro-Fert$.
 */
class DeclararIntervencao
{
    public function declarar(string $recurso, ?int $tetoMicro, ?int $pisoMicro, string $motivo, int $dias): PriceIntervention
    {
        if (! ResourceType::whereKey($recurso)->exists()) {
            throw new DomainRuleException('recurso_desconhecido', "Recurso desconhecido: {$recurso}");
        }

        if ($tetoMicro === null && $pisoMicro === null) {
            throw new DomainRuleException('faixa_vazia', 'Informe ao menos um teto ou um piso.');
        }

        if ($motivo === '') {
            throw new DomainRuleException('motivo_obrigatorio', 'O motivo é obrigatório (registro público, §06).');
        }

        if ($tetoMicro !== null && $pisoMicro !== null && $pisoMicro > $tetoMicro) {
            throw new DomainRuleException('piso_acima_do_teto', 'O piso não pode ser maior que o teto.');
        }

        if ($dias < 1) {
            throw new DomainRuleException('prazo_invalido', 'O prazo (dias) tem de ser ao menos 1.');
        }

        return PriceIntervention::create([
            'resource_type' => $recurso,
            'floor_micro' => $pisoMicro,
            'ceil_micro' => $tetoMicro,
            'reason' => $motivo,
            'starts_at' => now(),
            'expires_at' => now()->addDays($dias),
        ]);
    }

    /** Encerra as intervenções vigentes de um recurso. Devolve quantas. */
    public function revogar(string $recurso): int
    {
        return PriceIntervention::query()->vigentes()->where('resource_type', $recurso)
            ->update(['expires_at' => now()]);
    }
}
