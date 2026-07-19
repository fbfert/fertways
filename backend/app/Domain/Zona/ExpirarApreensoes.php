<?php

namespace App\Domain\Zona;

use App\Models\NeutralZone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * O resgate automático da Apreensão de Módulos (§28.10; docs/decisoes.md D-66, revisto no D-118).
 *
 * "Desliga uma estrutura até resgate" — o Predador zera a estrutura na hora
 * (`ResolverCombates::rodadaDeApreensao()`, `NeutralZone::fracaoEfetiva()`), mas o próprio
 * comentário do D-66 já previa que "passado o prazo, ele repara normalmente": 24 horas
 * (`Combat::RESGATE_HORAS`), e o módulo volta sozinho, sem ação nenhuma do dono. Isto nunca tinha
 * sido escrito: `modules_offline_expira_em` era gravado e nunca lido por ninguém.
 *
 * O dono pode reaver antes das 24h pagando — `RepararModulo`. As duas portas levam ao mesmo lugar:
 * tirar a estrutura de `modules_offline`. É por isso que aqui, como lá, a zona é travada: as duas
 * podem correr no mesmo minuto contra a mesma zona.
 *
 * Roda no tick.
 */
class ExpirarApreensoes
{
    public function handle(?Carbon $agora = null): int
    {
        $agora ??= now();

        $ids = NeutralZone::whereNotNull('modules_offline_expira_em')->pluck('id');

        $expiradas = 0;

        foreach ($ids as $id) {
            $expiradas += $this->processar($id, $agora);
        }

        return $expiradas;
    }

    private function processar(int $id, Carbon $agora): int
    {
        return DB::transaction(function () use ($id, $agora) {
            $zona = NeutralZone::whereKey($id)->lockForUpdate()->first();

            if (! $zona) {
                return 0;
            }

            $expira = $zona->modules_offline_expira_em ?? [];
            $offline = $zona->modules_offline ?? [];
            $vencidas = 0;

            foreach ($expira as $estrutura => $quando) {
                if (Carbon::parse($quando)->isFuture()) {
                    continue;
                }

                unset($expira[$estrutura]);
                $offline = array_values(array_diff($offline, [$estrutura]));
                $vencidas++;
            }

            if ($vencidas > 0) {
                $zona->update([
                    'modules_offline' => $offline === [] ? null : $offline,
                    'modules_offline_expira_em' => $expira === [] ? null : $expira,
                ]);
            }

            return $vencidas;
        });
    }
}
