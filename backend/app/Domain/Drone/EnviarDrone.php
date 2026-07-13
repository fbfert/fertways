<?php

namespace App\Domain\Drone;

use App\Domain\Logistics\MapaFertways;
use App\Exceptions\DomainRuleException;
use App\Models\Colony;
use App\Models\NeutralZone;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;

/**
 * Despacha o Drone numa missão de reconhecimento (D-74; GDD §21.4).
 *
 * **O alvo é uma ZONA, não uma coordenada solta** — é para elas que o §16.1 diz que o Drone existe
 * ("revela zonas neutras antes de ocupação"), e é nelas que mora o único segredo do mapa desde a
 * névoa do D-74: a guarnição e o depósito de zona alheia. O raio do nível revela também as vizinhas.
 *
 * Os dois modos são os do §21.4, textuais ("ida simples ou ida e volta, configurável por missão"):
 *
 *   foto         ida e volta. Chega, fotografa tudo no raio, volta. A foto é DATADA e permanente.
 *   vigilancia   ida simples. Chega e FICA, transmitindo ao vivo, até a bateria acabar
 *                (§21.4: 24 h no nível 1 … 122 h no 5) — então volta sozinho, e o que viu
 *                permanece como foto datada do momento da partida.
 *
 * ⚠️ **A missão não debita energia da colônia.** O Drone tem bateria própria e recarga automática
 * (§21.4) — cobrar kWh do estoque seria cobrá-lo duas vezes. E a viagem reusa as colunas de viagem
 * do veículo (`leg` ida → vigia → volta), não uma máquina nova: o `vehicles.status` é ENUM no
 * MariaDB, e `em_rota` serve ao voo inteiro.
 */
class EnviarDrone
{
    public function handle(Colony $colony, Vehicle $drone, NeutralZone $alvo, string $modo): Vehicle
    {
        if ($drone->type !== DroneSpecs::TIPO) {
            throw new DomainRuleException('nao_e_drone', 'Só o Drone de Exploração sai em missão de reconhecimento.');
        }

        if ($drone->colony_id !== $colony->id) {
            throw new DomainRuleException('drone_de_outra_colonia', 'Este Drone não é seu.');
        }

        if (! in_array($modo, ['foto', 'vigilancia'], true)) {
            throw new DomainRuleException('modo_invalido', 'Os modos do §21.4 são foto (ida e volta) e vigilancia (ida simples).');
        }

        return DB::transaction(function () use ($colony, $drone, $alvo, $modo) {
            $drone = Vehicle::whereKey($drone->id)->lockForUpdate()->firstOrFail();

            if ($drone->status !== 'ocioso') {
                throw new DomainRuleException('drone_em_missao', 'Este Drone já está no ar. Espere-o voltar.');
            }

            $distancia = MapaFertways::distancia($colony->x, $colony->y, $alvo->x, $alvo->y);
            $agora = now();

            $drone->forceFill([
                'status' => 'em_rota',
                'leg' => 'ida',
                'trip_purpose' => $modo,
                'destination_type' => 'zona',
                'destination_id' => $alvo->id,
                'distance_slots' => $distancia,
                'return_distance_slots' => $distancia,
                'departs_at' => $agora,
                'arrives_at' => $agora->copy()->addSeconds(DroneSpecs::segundosDoVoo($distancia)),
            ])->save();

            app(\App\Domain\Missoes\Progresso::class)->registrar($colony->id, 'missao_drone');

            return $drone->fresh();
        });
    }
}
