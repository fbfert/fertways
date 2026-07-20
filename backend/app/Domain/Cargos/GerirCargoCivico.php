<?php

namespace App\Domain\Cargos;

use App\Exceptions\DomainRuleException;
use App\Models\CivicPost;
use App\Models\User;

/**
 * Gestão dos Cargos Públicos (§14.2, D-130). Ato da equipe: nomear, demitir, reintegrar, suspender.
 *
 * Mesmo molde do `GerirConciliador` (D-44, D-56), generalizado por `kind`. Um colono pode acumular
 * mais de um dos 3 cargos ao mesmo tempo — o §14.2 não proíbe, e cada um vive na própria linha.
 */
class GerirCargoCivico
{
    /** Nomeia. Devolve null se já ocupa o cargo (sem efeito). */
    public function nomear(User $colono, string $kind): ?CivicPost
    {
        if (! in_array($kind, CargosCivicosSpecs::KINDS, true)) {
            throw new DomainRuleException('cargo_desconhecido', "Cargo inexistente: {$kind}");
        }

        if (CivicPost::where('user_id', $colono->id)->where('kind', $kind)->exists()) {
            return null;
        }

        return CivicPost::create([
            'user_id' => $colono->id,
            'kind' => $kind,
            'desde' => now(),
            'suspenso_em' => null,
            // Nasce sem salário pago: recebe no próximo tick, como o Conciliador (D-50).
            'salario_pago_em' => null,
        ]);
    }

    public function demitir(User $colono, string $kind): void
    {
        CivicPost::where('user_id', $colono->id)->where('kind', $kind)->delete();
    }

    public function suspender(User $colono, string $kind): void
    {
        CivicPost::where('user_id', $colono->id)->where('kind', $kind)->update(['suspenso_em' => now()]);
    }

    public function reintegrar(User $colono, string $kind): void
    {
        CivicPost::where('user_id', $colono->id)->where('kind', $kind)->update(['suspenso_em' => null]);
    }
}
