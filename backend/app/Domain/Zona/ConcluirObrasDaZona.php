<?php

namespace App\Domain\Zona;

use App\Models\NeutralZone;
use App\Models\ZoneBuild;
use App\Models\ZoneStructure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fecha as obras da zona que venceram (docs/decisoes.md D-67). Roda no tick.
 *
 * O material já foi pago ao **começar** a obra — saiu do canteiro naquele momento, como a fila da
 * colônia faz (D-13). Aqui só se sobe o nível.
 *
 * **A zona conquistada no meio de uma obra fica com ela.** Não cancelamos, e não devolvemos material:
 * o §27.8 diz que o atacante "assume o controle", e o que estava erguido — ou meio erguido — é parte
 * da zona. Quem invade herda o canteiro do vencido, e é justo: ele acabou de pagar por isso com
 * sangue.
 */
class ConcluirObrasDaZona
{
    /** @return int quantas obras terminaram */
    public function handle(?Carbon $agora = null): int
    {
        $agora ??= now();

        $ids = ZoneBuild::where('finishes_at', '<=', $agora)->pluck('id');

        $feitas = 0;

        foreach ($ids as $id) {
            if ($this->concluir($id)) {
                $feitas++;
            }
        }

        return $feitas;
    }

    private function concluir(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $obra = ZoneBuild::whereKey($id)->lockForUpdate()->first();

            if (! $obra) {
                return false;   // outro processo já a fechou.
            }

            $zona = NeutralZone::whereKey($obra->zone_id)->lockForUpdate()->first();

            if (! $zona) {
                $obra->delete();

                return false;
            }

            if (! in_array($obra->structure, Estruturas::TODAS, true) || $obra->slot === null) {
                $obra->delete();

                return false;
            }

            /*
             * `max` e não atribuição direta: se dois processos fecharem a mesma obra, ou se a zona
             * tiver sido tomada e o novo dono já a tiver evoluído, nunca se BAIXA um nível.
             */
            $linha = ZoneStructure::where('neutral_zone_id', $zona->id)->where('slot', $obra->slot)->first();

            if ($linha) {
                $linha->update(['level' => max($linha->level, $obra->target_level)]);
            } else {
                ZoneStructure::create([
                    'neutral_zone_id' => $zona->id,
                    'slot' => $obra->slot,
                    'type' => $obra->structure,
                    'level' => $obra->target_level,
                ]);
            }

            /*
             * A Refinaria recém-erguida começa a contar o relógio dela AGORA. Sem isto, ela
             * converteria retroativamente todo o tempo desde a ocupação da zona — o colono ergueria
             * a Refinaria e ela cuspiria um estoque inteiro de secundário no primeiro tick.
             */
            if ($obra->structure === 'refinaria_de_campo' && $zona->last_refine_at === null) {
                $zona->update(['last_refine_at' => now()]);
            }

            $obra->delete();

            return true;
        });
    }
}
