<?php

namespace App\Domain\Zona;

use App\Models\NeutralZone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A Refinaria de Campo converte, no tick (GDD §17.4; docs/decisoes.md D-67).
 *
 * **É a primeira construção do jogo que CONVERTE.** Todas as outras produzem uma taxa fixa por hora e
 * não comem nada: a Mina rende 15 Metal Bruto/h do ar. Esta consome o minério que a zona extraiu.
 *
 *     "Processa o recurso extraído ainda na zona neutra — recurso primário vira secundário no local,
 *      aumentando o valor da carga antes mesmo do transporte." (§17.4)
 *
 * **2 primários → 1 secundário** (D-67). Não cria matéria do nada, e é de propósito: o ganho é de
 * **volume**. A carroceria leva metade das unidades pelo mesmo minério, e as zonas ficam nos cantos do
 * mapa, onde o frete é o gargalo. Quem tem Refinaria transporta menos viagens.
 *
 * ⚠️ Pela tabela de preços do §06, converter até **perde** valor nominal (Metal Bruto vale 0,0333 e
 * Ligas 0,0125). Essa tabela é estranha e o D-34 já a registrou como tal. O que se ganha aqui é o
 * frete, não o preço — e se um dia a tabela for consertada, a Refinaria melhora sozinha.
 */
class RefinarNaZona
{
    /** @return int quantas zonas refinaram */
    public function handle(?Carbon $agora = null): int
    {
        $agora ??= now();

        $ids = NeutralZone::whereNotNull('owner_colony_id')
            ->whereHas('zoneStructures', fn ($q) => $q->where('type', 'refinaria_de_campo'))
            ->where('deposit_amount', '>', 0)
            ->pluck('id');

        $refinadas = 0;

        foreach ($ids as $id) {
            if ($this->refinar($id, $agora)) {
                $refinadas++;
            }
        }

        return $refinadas;
    }

    private function refinar(int $id, Carbon $agora): bool
    {
        return DB::transaction(function () use ($id, $agora) {
            $zona = NeutralZone::whereKey($id)->lockForUpdate()->first();

            if (! $zona || $zona->nivelDe('refinaria_de_campo') < 1 || $zona->deposit_amount <= 0) {
                return false;
            }

            $destino = $zona->recursoRefinado();

            if ($destino === null) {
                return false;   // o mineral desta zona não tem secundário mapeado.
            }

            /*
             * Cercada, a Refinaria para junto com o depósito. O §28.10 diz que "não há onde
             * armazenar" — e o refinado tem de ir para algum lugar. Refinar sob sítio seria
             * transformar minério em recurso que também não cabe.
             */
            if ($zona->depositoBloqueado()) {
                $zona->update(['last_refine_at' => $agora]);

                return false;
            }

            // O relógio próprio: a Refinaria converte por delta dela, sem relação com a extração.
            $desde = $zona->last_refine_at ?? $zona->productive_at ?? $zona->occupied_at ?? $agora;
            $segundos = $agora->getTimestamp() - $desde->getTimestamp();

            if ($segundos <= 0) {
                return false;
            }

            $capacidade = intdiv($zona->refinoPorHora() * $segundos, 3600);

            // Não processa mais do que há, nem mais do que a sua capacidade horária permite.
            $consumido = min($capacidade, $zona->deposit_amount);

            // Um secundário custa dois primários: um primário ímpar sobrando fica para o próximo tick.
            $produzido = intdiv($consumido, Estruturas::REFINO_CUSTO);

            if ($produzido <= 0) {
                // Ainda não deu para uma unidade inteira. **Não mexe no relógio**: o tempo continua a
                // acumular, senão um tick de um minuto jogaria fora a fração toda vez e a Refinaria
                // nunca produziria nada.
                return false;
            }

            $gasto = $produzido * Estruturas::REFINO_CUSTO;

            $zona->update([
                'deposit_amount' => $zona->deposit_amount - $gasto,
                'refined_amount' => $zona->refined_amount + $produzido,
                // Avança o relógio só pelo tempo que o gasto consumiu — o resto acumula.
                'last_refine_at' => $desde->copy()->addSeconds(
                    intdiv($gasto * 3600, max(1, $zona->refinoPorHora())),
                ),
            ]);

            return true;
        });
    }
}
