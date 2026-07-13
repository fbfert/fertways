<?php

namespace App\Http\Controllers\Api;

use App\Domain\Drone\DroneSpecs;
use App\Domain\Drone\EnviarDrone;
use App\Domain\Drone\FabricarDrone;
use App\Http\Controllers\Controller;
use App\Models\NeutralZone;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * O Drone de Exploração (GDD §16.1, §21.4; docs/decisoes.md D-74).
 *
 * Fabrica-se na Oficina, guarda-se no Quartel, e a missão mira uma ZONA: foto (ida e volta) ou
 * vigilância (ida simples, fica até a bateria acabar). É o único jeito de ver a guarnição e o
 * depósito de uma zona alheia desde a névoa do D-74.
 */
class DroneController extends Controller
{
    public function fabricar(Request $request, FabricarDrone $fabrica): JsonResponse
    {
        $dados = $request->validate([
            'nivel' => ['required', 'integer', 'min:1', 'max:5'],
        ]);

        $colony = $request->user()->colony()->firstOrFail();
        $drone = $fabrica->handle($colony, (int) $dados['nivel']);

        return response()->json([
            'id' => $drone->id,
            'placa' => $drone->plate,
            'level' => $drone->level,
            'raio' => DroneSpecs::RAIO[$drone->level],
            'bateria_horas' => DroneSpecs::BATERIA_HORAS[$drone->level],
        ], 201);
    }

    public function enviar(Request $request, Vehicle $vehicle, EnviarDrone $enviar): JsonResponse
    {
        $dados = $request->validate([
            'zone_id' => ['required', 'integer', 'exists:neutral_zones,id'],
            'modo' => ['required', Rule::in(['foto', 'vigilancia'])],
        ]);

        $colony = $request->user()->colony()->firstOrFail();
        $alvo = NeutralZone::findOrFail((int) $dados['zone_id']);

        $drone = $enviar->handle($colony, $vehicle, $alvo, $dados['modo']);

        return response()->json([
            'id' => $drone->id,
            'fase' => $drone->leg,
            'modo' => $drone->trip_purpose,
            'chega_at' => $drone->arrives_at?->toIso8601String(),
        ], 201);
    }
}
