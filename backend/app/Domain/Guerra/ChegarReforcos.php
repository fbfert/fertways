<?php

namespace App\Domain\Guerra;

use App\Models\Combat;
use App\Models\NeutralZone;
use App\Models\Unit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Os reforços chegam à zona (GDD §27.5; docs/decisoes.md D-70). Roda no tick.
 *
 * **É aqui que "reforços tardios podem ainda mudar o resultado" vira verdade.** A unidade que estava
 * `marchando` passa a `na_zona` — e, se houver batalha em curso, a **força é recongelada**: o dano por
 * rodada é recalculado a partir da força nova, e a tropa que acabou de chegar passa a contar **a
 * partir da rodada seguinte**, exatamente como o §27.5 desenha.
 *
 * Sem este recongelamento, o reforço entraria na conta da força mas o **dano** continuaria o da
 * primeira rodada — e o defensor veria as suas tropas novas morrerem ao ritmo antigo, sem entender
 * por quê.
 */
class ChegarReforcos
{
    public function __construct(private ResolverCombates $combates) {}

    /** @return int quantas unidades chegaram */
    public function handle(?Carbon $agora = null): int
    {
        $agora ??= now();

        $zonas = Unit::where('status', 'marchando')
            ->whereNotNull('zone_id')
            ->whereNull('combat_id')   // as do atacante marcham com `combat_id`; estas, não.
            ->where('arrives_at', '<=', $agora)
            ->distinct()
            ->pluck('zone_id');

        $chegaram = 0;

        foreach ($zonas as $zoneId) {
            $chegaram += $this->chegar((int) $zoneId, $agora);
        }

        return $chegaram;
    }

    private function chegar(int $zoneId, Carbon $agora): int
    {
        return DB::transaction(function () use ($zoneId, $agora) {
            $zona = NeutralZone::whereKey($zoneId)->lockForUpdate()->first();

            if (! $zona) {
                // A zona sumiu. As tropas com ela: não há para onde voltar de um lugar que não existe.
                return (int) Unit::where('zone_id', $zoneId)->where('status', 'marchando')->delete();
            }

            $n = Unit::where('zone_id', $zoneId)
                ->where('status', 'marchando')
                ->whereNull('combat_id')
                ->where('arrives_at', '<=', $agora)
                ->update(['status' => 'na_zona', 'arrives_at' => null]);

            if ($n === 0) {
                return 0;
            }

            /*
             * A batalha em curso passa a contar com eles — **a partir da rodada seguinte**.
             *
             * `recongelar()` recalcula a força dos dois lados e o dano constante que dela sai (D-66,
             * arbitragem 8). Sem isto, a força nova entraria na conta e o dano continuaria o velho: o
             * defensor veria as tropas recém-chegadas morrerem ao ritmo de antes.
             */
            foreach (Combat::where('zone_id', $zoneId)->where('status', 'em_curso')->get() as $c) {
                $this->combates->recongelar($c, $zona->fresh());
            }

            return $n;
        });
    }
}
