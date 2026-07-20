<?php

namespace App\Domain\Drone;

use App\Domain\Endurance\EfeitosDaEndurance;
use App\Models\Colony;
use App\Models\DroneSighting;
use App\Models\NeutralZone;
use App\Models\Vehicle;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Avança as missões de Drone vencidas — roda no tick (D-74).
 *
 * A missão é uma máquina de três pernas, todas dentro de `status = em_rota`:
 *
 *   ida     voando até a zona-alvo. Ao chegar: FOTO → fotografa e dá meia-volta;
 *           VIGILÂNCIA → vira `vigia`, e o `arrives_at` passa a ser o fim da bateria.
 *   vigia   sobrevoando. A transmissão é AO VIVO (quem lê é o `Avistamentos`, na hora da
 *           consulta — nada se grava por tick). Quando a bateria acaba, fotografa uma última
 *           vez — para a vigilância virar foto datada, e não esquecimento — e dá meia-volta.
 *   volta   voando para casa. Ao chegar: ocioso. A recarga é "automática" (§21.4) e o GDD não
 *           publica taxa nenhuma — então ela é instantânea, e a bateria só existe como a
 *           DURAÇÃO da vigília. Não invente um relógio que o documento não tem.
 *
 * Como o `ConcluirTrechos` (D-30), cada passada fecha UMA perna por drone: a ida que termina e a
 * volta que começa no mesmo minuto fecham em passadas sucessivas do tick, e ninguém nota.
 */
class ConcluirMissoes
{
    public function __construct(private EfeitosDaEndurance $efeitosDaEndurance) {}

    public function handle(CarbonInterface $agora): int
    {
        $avancados = 0;

        $vencidos = Vehicle::where('type', DroneSpecs::TIPO)
            ->where('status', 'em_rota')
            ->where('arrives_at', '<=', $agora)
            ->orderBy('arrives_at')
            ->get();

        foreach ($vencidos as $drone) {
            $avancados += (int) DB::transaction(function () use ($drone, $agora) {
                $d = Vehicle::whereKey($drone->id)->lockForUpdate()->first();

                if (! $d || $d->status !== 'em_rota' || $d->arrives_at > $agora) {
                    return false;
                }

                match ($d->leg) {
                    'ida' => $this->chegou($d),
                    'vigia' => $this->bateriaAcabou($d),
                    default => $this->voltou($d),
                };

                return true;
            });
        }

        return $avancados;
    }

    private function chegou(Vehicle $d): void
    {
        if ($d->trip_purpose === 'foto') {
            $this->fotografar($d);
            $this->meiaVolta($d);

            return;
        }

        // Vigilância: fica. O fim da bateria é o novo horizonte da perna — e a bateria conta a
        // partir da CHEGADA (o §21.4 a publica como autonomia de operação, e o voo de minutos
        // contra horas de bateria não paga a complicação de ser descontado).
        $horas = DroneSpecs::BATERIA_HORAS[$d->level] ?? 24;

        // Bônus de bateria da Endurance (D-135): estica a autonomia, por cima do nível do drone.
        $colonia = $d->colony_id !== null ? Colony::find($d->colony_id) : null;

        if ($colonia) {
            $bonus = $this->efeitosDaEndurance->bonusDeDroneBateria($colonia);
            $horas = EfeitosDaEndurance::aplicarBonus($horas, $bonus);
        }

        $d->forceFill([
            'leg' => 'vigia',
            'departs_at' => $d->arrives_at,
            'arrives_at' => $d->arrives_at->copy()->addHours($horas),
        ])->save();
    }

    private function bateriaAcabou(Vehicle $d): void
    {
        // A última leitura antes de partir: a vigilância que termina vira foto datada, não vazio.
        $this->fotografar($d);
        $this->meiaVolta($d);
    }

    private function meiaVolta(Vehicle $d): void
    {
        $d->forceFill([
            'leg' => 'volta',
            'departs_at' => $d->arrives_at,
            'arrives_at' => $d->arrives_at->copy()->addSeconds(
                DroneSpecs::segundosDoVoo((int) $d->return_distance_slots),
            ),
        ])->save();
    }

    private function voltou(Vehicle $d): void
    {
        $d->forceFill([
            'status' => 'ocioso',
            'leg' => null,
            'trip_purpose' => null,
            'destination_type' => null,
            'destination_id' => null,
            'distance_slots' => null,
            'return_distance_slots' => null,
            'departs_at' => null,
            'arrives_at' => null,
        ])->save();
    }

    /**
     * A foto: tudo o que está no raio do nível, ao redor da zona-alvo — inclusive ela.
     *
     * Grava-se TUDO que o raio alcança, até zona livre e zona própria: a foto é o que o drone viu,
     * não o que interessava. Quem decide o que mostrar é a leitura (`Avistamentos`), não a escrita.
     */
    private function fotografar(Vehicle $d): void
    {
        $alvo = NeutralZone::find($d->destination_id);

        if (! $alvo) {
            return;   // a zona sumiu do mapa? A foto é de nada, e a missão segue para casa.
        }

        $raio = DroneSpecs::RAIO[$d->level] ?? 6;

        // Bônus de raio da Endurance (D-135), mesmo padrão da bateria em `chegou()`.
        $colonia = $d->colony_id !== null ? Colony::find($d->colony_id) : null;

        if ($colonia) {
            $bonus = $this->efeitosDaEndurance->bonusDeDroneRaio($colonia);
            $raio = EfeitosDaEndurance::aplicarBonus($raio, $bonus);
        }

        $agora = now();

        $linhas = NeutralZone::whereBetween('x', [$alvo->x - $raio, $alvo->x + $raio])
            ->whereBetween('y', [$alvo->y - $raio, $alvo->y + $raio])
            ->get()
            ->filter(fn (NeutralZone $z) => \App\Domain\Logistics\MapaFertways::distancia($alvo->x, $alvo->y, $z->x, $z->y) <= $raio)
            ->map(fn (NeutralZone $z) => [
                'colony_id' => $d->colony_id,
                'zone_id' => $z->id,
                'garrison' => $z->guarnicao(),
                'deposit_amount' => (int) $z->deposit_amount,
                'seen_at' => $agora,
                'created_at' => $agora,
                'updated_at' => $agora,
            ])
            ->values()
            ->all();

        if ($linhas !== []) {
            // A passagem nova SOBRESCREVE a antiga: intel velha não vira histórico, vira engano.
            DroneSighting::upsert($linhas, ['colony_id', 'zone_id'], ['garrison', 'deposit_amount', 'seen_at', 'updated_at']);
        }
    }
}
