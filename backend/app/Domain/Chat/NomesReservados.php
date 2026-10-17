<?php

namespace App\Domain\Chat;

use Illuminate\Support\Str;

/**
 * Os nomes que um jogador não pode usar como nickname (D-284).
 *
 * As contas de sistema falam no rádio com estes nomes — a Capital, as Missões, a Federação — e o
 * Governo assina comunicados. Um jogador chamado "Capital" mandava mensagem privada como se fosse a
 * Capital. A comparação é normalizada: sem acento, sem caixa e sem separador, para "Capital_",
 * "CAPITAL" e "Ca-pi-tal" caírem todos.
 */
final class NomesReservados
{
    private const NOMES = [
        'capital', 'missoes', 'federacao', 'sistema', 'governo', 'governodefertways',
        'administracao', 'administracaopublica', 'admin', 'administrador', 'equipe', 'suporte', 'moderacao',
        'gagarin', 'telescopiogagarin', 'ministerio', 'tesouro', 'fertways', 'endurance', 'espacoporto',
    ];

    public static function reservado(string $nome): bool
    {
        $base = strtolower(Str::ascii($nome));
        $base = preg_replace('/[^a-z0-9]/', '', $base) ?? '';

        return in_array($base, self::NOMES, true);
    }
}
