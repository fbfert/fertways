<?php

namespace App\Domain\Trade;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * O sinal de contas possivelmente vinculadas do §26.4 (D-287): duas contas que entraram do mesmo
 * IP nos últimos 30 dias.
 *
 * É um sinal, não um veredito. O GDD manda marcar como "suspeita" e esperar revisão — uma casa com
 * dois irmãos jogando também divide o IP. Por isso nada aqui pune: só congela o efeito da avaliação
 * até o operador olhar.
 *
 * Guarda o HMAC-SHA256 do IP com a chave da aplicação. Sem a chave, o hash não se reverte nem por
 * força bruta sobre os 4 bilhões de IPv4.
 */
class VinculoDeContas
{
    public function registrar(User $user, ?string $ip): void
    {
        if ($ip === null || $ip === '') {
            return;
        }

        $hmac = hash_hmac('sha256', $ip, (string) config('app.key'));
        $agora = now();

        DB::table('login_ips')->upsert(
            [['user_id' => $user->id, 'ip_hmac' => $hmac, 'primeiro_em' => $agora, 'ultimo_em' => $agora]],
            ['user_id', 'ip_hmac'],
            ['ultimo_em'],
        );
    }

    public function possivelmenteVinculadas(int $userA, int $userB): bool
    {
        $desde = now()->subDays(AvaliacaoSpecs::VINCULO_DIAS);

        return DB::table('login_ips as a')
            ->join('login_ips as b', 'a.ip_hmac', '=', 'b.ip_hmac')
            ->where('a.user_id', $userA)
            ->where('b.user_id', $userB)
            ->where('a.ultimo_em', '>=', $desde)
            ->where('b.ultimo_em', '>=', $desde)
            ->exists();
    }
}
