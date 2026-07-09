<?php

namespace App\Domain\Logistics;

use App\Models\Colony;
use App\Models\Ledger;
use App\Models\ResourceType;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;

/**
 * Fecha os trechos de viagem vencidos. Chamado pelo tick.
 *
 * Fica fora do laço por colônia de propósito: um veículo da colônia A entrega na colônia B, e o
 * relógio da viagem não tem relação com o `last_tick_at` de nenhuma das duas.
 *
 * Trecho de **ida** (§25.3, passo 4): a carga é entregue e o tributo sobre o volume incide nesse
 * exato momento. O usuário decidiu (2026-07-08) que vale o §25.2 — "incide sempre que um veículo
 * entrega carga em qualquer destino" — e não o §8.3, que falava em cobrar na saída. Um veículo
 * perdido antes de entregar não gera lançamento tributário.
 *
 * Trecho de **volta** (§25.5): só ao completá-lo o veículo volta a ficar disponível.
 */
class ConcluirTrechos
{
    /** @return int quantos trechos foram fechados */
    public function handle(): int
    {
        $vencidos = Vehicle::where('status', 'em_rota')
            ->where('arrives_at', '<=', now())
            ->orderBy('arrives_at')
            ->get();

        $fechados = 0;

        foreach ($vencidos as $veiculo) {
            DB::transaction(function () use ($veiculo, &$fechados) {
                // Relê com lock: o tick pode rodar concorrente com outra instância do cron.
                $v = Vehicle::whereKey($veiculo->id)->lockForUpdate()->first();

                if (! $v || $v->status !== 'em_rota' || $v->arrives_at > now()) {
                    return;
                }

                $v->leg === 'ida' ? $this->concluirIda($v) : $this->concluirVolta($v);
                $fechados++;
            });
        }

        return $fechados;
    }

    private function concluirIda(Vehicle $v): void
    {
        $destino = Colony::find($v->destination_id);
        $origem = Colony::find($v->colony_id);

        // A colônia de destino pode ter sido apagada durante o trajeto. A carga se perde; o
        // veículo volta. Não há regra no GDD para isso, e evaporar é melhor que travar o veículo.
        if ($destino && $origem) {
            foreach ($v->cargo_json ?? [] as $recurso => $qtd) {
                $this->entregar($origem, $destino, $v, $recurso, (int) $qtd);
            }
        }

        $v->forceFill([
            'leg' => 'volta',
            'cargo_json' => null,
            // A volta parte de quando a ida terminou, não de `now()`: se o cron atrasar, o
            // atraso não pode encurtar nem alongar o trecho de volta.
            'arrives_at' => $v->arrives_at->copy()->addSeconds(
                VeiculoSpecs::segundosDoTrecho($v->type, $v->distance_slots),
            ),
        ])->save();
    }

    private function concluirVolta(Vehicle $v): void
    {
        $v->forceFill([
            'status' => 'ocioso',
            'leg' => null,
            'destination_type' => null,
            'destination_id' => null,
            'distance_slots' => null,
            'departs_at' => null,
            'arrives_at' => null,
            'cargo_json' => null,
        ])->save();
    }

    private function entregar(Colony $origem, Colony $destino, Vehicle $v, string $recurso, int $qtd): void
    {
        $tipo = ResourceType::find($recurso);

        if (! $tipo) {
            return;
        }

        /*
         * "Uma incidência por fato econômico/lote" (GDD, seção 0 e §25.9) não é regra de
         * aplicação, é invariante de dados. A chave deriva do **evento de entrega** — veículo e
         * instante de partida identificam a viagem, e o recurso identifica o lote. Um retry do
         * tick, ou dois crons concorrentes, colidem no índice único e não tributam duas vezes.
         */
        $chave = "entrega:{$v->id}:{$v->departs_at->getTimestamp()}:{$recurso}";

        // Truncamento: o tributo é retido em unidades inteiras do próprio recurso (D-12), e
        // arredondar para cima cobraria mais do que a alíquota em cargas pequenas.
        $tributo = intdiv($qtd * $tipo->tax_bps, 10_000);
        $liquido = $qtd - $tributo;

        $inserido = DB::table('tax_events')->insertOrIgnore([
            'economic_event_key' => $chave,
            'kind' => 'transporte_entrega',
            'colony_id' => $origem->id,
            'resource_type' => $recurso,
            'base_amount' => $qtd,
            'tax_bps' => $tipo->tax_bps,
            'tax_amount' => $tributo,
            'created_at' => now(),
        ]);

        // Já tributado numa execução anterior: a carga também já foi creditada. Sair sem creditar
        // de novo é o que torna o tick seguro para repetir.
        if ($inserido === 0) {
            return;
        }

        $destino->resources()->where('resource_type', $recurso)->increment('amount', $liquido);

        Ledger::create([
            'colony_id' => $destino->id,
            'type' => 'transferencia',
            'amount' => $liquido,
            'resource_type' => $recurso,
            'ref' => $chave,
            'created_at' => now(),
        ]);

        if ($tributo > 0) {
            Ledger::create([
                'colony_id' => $origem->id,
                'type' => 'tributo',
                'amount' => -$tributo,
                'resource_type' => $recurso,
                'ref' => $chave,
                'created_at' => now(),
            ]);
        }
    }
}
