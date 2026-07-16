<?php

namespace App\Domain\Chat;

use App\Models\User;

/**
 * "Capital", o único remetente do chat que não é um jogador (D-91).
 *
 * O schema do chat exige `user_id` de verdade (D-77) — em vez de abrir uma exceção nele para um
 * caso só, existe uma conta de verdade, sem colônia e sem senha utilizável, reservada por
 * migration (`2026_07_16_120000_aviso_do_patio_e_conta_capital`).
 */
class ContaSistema
{
    public const EMAIL_CAPITAL = 'capital@fertways.sistema';

    public static function capital(): User
    {
        return User::where('email', self::EMAIL_CAPITAL)->firstOrFail();
    }
}
