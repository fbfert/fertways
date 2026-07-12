<?php

namespace App\Domain\Capital;

use App\Domain\Logistics\MapaFertways;
use App\Domain\Transport\Conservacao;
use App\Domain\Treasury\Tesouro;
use App\Models\Colony;
use App\Models\Ledger;
use App\Models\Vehicle;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * O Pátio Logístico da Capital: o estacionamento do slot 6 (D-65).
 *
 * O GDD publica o slot 6 como "Estacionamento de Caminhões. 20 vagas. **Cobrança por hora**.
 * Caminhões aguardam retirada de carga" (§2.1) — e **nunca publica o preço**. O D-63 tinha deixado
 * a tarifa como lacuna aberta, justamente por isso. O usuário fechou-a: **0,005 Fert$ por hora**
 * parada, por veículo. E decidiu que **as vagas não têm limite** — o Pátio não recusa ninguém, e o
 * número 20 do GDD fica como texto.
 *
 * Quem não tem Fert$ para a hora **é rebocado para casa**: a viagem de volta sai de graça e o
 * veículo aparece ocioso no slot do colono. Ninguém fica devendo, ninguém perde o veículo, e
 * ninguém guarda caminhão de graça no meio da Capital.
 */
class Patio
{
    /** 0,005 Fert$ por hora parada, em micro-Fert$ (D-65). */
    public const TARIFA_MICRO_HORA = 5_000;

    public function __construct(private readonly Conservacao $conservacao) {}

    /**
     * Cobra a hora de quem está parado no Pátio. Chamado pelo tick.
     *
     * Cobra **horas cheias**: a fração que ainda não fechou uma hora fica para o próximo tick, e é
     * por isso que `patio_cobrado_ate` avança em passos de hora, e não para `now()`. Sem isso, um
     * tick a cada minuto cobraria sessenta frações arredondadas e o colono pagaria muito mais que
     * a tarifa — e, com truncamento, cobraria zero para sempre.
     *
     * @return array{cobrados: int, rebocados: int}
     */
    public function handle(?CarbonInterface $agora = null): array
    {
        $agora = $agora ?? now();
        $cobrados = 0;
        $rebocados = 0;

        $estacionados = Vehicle::where('local', Vehicle::NO_PATIO)
            ->where('status', 'ocioso')
            ->whereNotNull('colony_id')
            ->get();

        foreach ($estacionados as $veiculo) {
            // Relê com lock e reconta as horas lá dentro: dois crons concorrentes não podem cobrar
            // a mesma hora, e o colono pode ter despachado o veículo entre a varredura e agora.
            $desfecho = DB::transaction(function () use ($veiculo, $agora) {
                $v = Vehicle::whereKey($veiculo->id)->lockForUpdate()->first();

                if (! $v || ! $v->noPatio()) {
                    return 'nada';
                }

                $horas = $this->horasDevidas($v, $agora);

                if ($horas <= 0) {
                    return 'nada';
                }

                return $this->cobrar($v, $horas) ? 'cobrado' : 'sem_saldo';
            });

            if ($desfecho === 'cobrado') {
                $cobrados++;
            }

            if ($desfecho === 'sem_saldo') {
                $this->rebocar($veiculo->fresh());
                $rebocados++;
            }
        }

        return ['cobrados' => $cobrados, 'rebocados' => $rebocados];
    }

    /** Quantas horas cheias o veículo deve desde a última cobrança. */
    private function horasDevidas(Vehicle $v, CarbonInterface $agora): int
    {
        $desde = $v->patio_cobrado_ate ?? $v->parked_at;

        if (! $desde || $desde->greaterThan($agora)) {
            return 0;
        }

        return (int) floor($desde->diffInMinutes($agora) / 60);
    }

    /**
     * Debita as horas do colono e credita o Tesouro. Devolve `false` se o saldo não cobre — e aí
     * **nada é debitado**: quem não pode pagar não paga em parte, é rebocado.
     */
    private function cobrar(Vehicle $v, int $horas): bool
    {
        $devido = $horas * self::TARIFA_MICRO_HORA;

        // Guarda atômica no UPDATE, como a do estoque: dois ticks concorrentes não gastam o mesmo
        // Fert$, e a coluna não vira negativa por corrida.
        $afetadas = Colony::whereKey($v->colony_id)
            ->where('fert_micro', '>=', $devido)
            ->decrement('fert_micro', $devido);

        if ($afetadas === 0) {
            return false;
        }

        $v->forceFill([
            'patio_cobrado_ate' => $v->patio_cobrado_ate->copy()->addHours($horas),
        ])->save();

        Ledger::create([
            'colony_id' => $v->colony_id,
            'type' => 'estacionamento',
            'amount' => -$devido,
            'resource_type' => null,
            'ref' => "patio:{$v->id}:{$v->patio_cobrado_ate->getTimestamp()}",
            'created_at' => now(),
        ]);

        // A hora do estacionamento é receita do governo, como o tributo (§2.1, D-57).
        app(Tesouro::class)->creditarFert($devido);

        return true;
    }

    /**
     * Sem Fert$, o veículo é posto na estrada de volta para casa (D-65).
     *
     * De graça: cobrar a energia de quem não tinha nem a hora seria empurrar o problema para o
     * outro estoque, e o reboque não é uma viagem que o colono pediu. Vazio, sempre — veículo
     * parado no Pátio não tem carga (a sobra do depósito volta na hora, e a carga do Pátio só
     * embarca no despacho).
     */
    private function rebocar(?Vehicle $v): void
    {
        $colonia = $v ? Colony::find($v->colony_id) : null;

        if (! $v || ! $colonia) {
            return;
        }

        $distancia = MapaFertways::distancia(MapaFertways::CAPITAL_X, MapaFertways::CAPITAL_Y, $colonia->x, $colonia->y);
        $agora = now();

        $v->forceFill([
            'status' => 'em_rota',
            'leg' => 'ida',
            'trip_purpose' => 'reboque',
            'destination_type' => 'colonia',
            'destination_id' => $colonia->id,
            'distance_slots' => $distancia,
            // Só de ida: ele chega em casa e fica. Ver DespacharVeiculo::emRota.
            'return_distance_slots' => null,
            'departs_at' => $agora,
            'arrives_at' => $agora->copy()->addSeconds($this->conservacao->segundosDoTrecho($v, $distancia)),
            'parked_at' => null,
            'patio_cobrado_ate' => null,
            'cargo_json' => null,
        ])->save();
    }
}
