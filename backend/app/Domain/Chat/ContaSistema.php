<?php

namespace App\Domain\Chat;

use App\Models\User;

/**
 * As contas de sistema: remetentes do chat que não são jogadores (D-91, e "Missões" depois).
 *
 * O schema do chat exige `user_id` de verdade (D-77) — em vez de abrir uma exceção nele para cada
 * caso, cada uma é uma conta de verdade, sem colônia e sem senha utilizável, reservada por
 * migration (`2026_07_16_120000_aviso_do_patio_e_conta_capital`,
 * `2026_07_16_160000_conta_sistema_missoes`, `2026_07_19_120000_conta_sistema_federacao`).
 */
class ContaSistema
{
    public const EMAIL_CAPITAL = 'capital@fertways.sistema';

    public const EMAIL_MISSOES = 'missoes@fertways.sistema';

    public const EMAIL_FEDERACAO = 'federacao@fertways.sistema';

    public static function capital(): User
    {
        return User::where('email', self::EMAIL_CAPITAL)->firstOrFail();
    }

    /** Quem avisa pelo rádio quando uma missão é concluída, e o que ela pagou (pedido do usuário). */
    public static function missoes(): User
    {
        return User::where('email', self::EMAIL_MISSOES)->firstOrFail();
    }

    /** Quem avisa a federação pela Central de Comunicação quando a zona de um membro é sitiada. */
    public static function federacao(): User
    {
        return User::where('email', self::EMAIL_FEDERACAO)->firstOrFail();
    }
}
