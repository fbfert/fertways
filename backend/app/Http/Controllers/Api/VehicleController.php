<?php

namespace App\Http\Controllers\Api;

use App\Domain\Logistics\DespacharVeiculo;
use App\Domain\Logistics\MapaFertways;
use App\Domain\Logistics\VeiculoSpecs;
use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Models\Colony;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehicleController extends Controller
{
    /** A frota do colono, com o que a UI precisa para montar um despacho. */
    public function index(Request $request): JsonResponse
    {
        $colony = $this->colonia($request);

        return response()->json([
            'colony' => ['x' => $colony->x, 'y' => $colony->y],
            'capital' => ['x' => MapaFertways::CAPITAL_X, 'y' => MapaFertways::CAPITAL_Y],
            'vehicles' => $colony->vehicles->map(fn (Vehicle $v) => [
                'id' => $v->id,
                'type' => $v->type,
                'level' => $v->level,
                'status' => $v->status,
                'capacity' => $v->capacity,
                'leg' => $v->leg,
                'trip_purpose' => $v->trip_purpose,
                'distance_slots' => $v->distance_slots,
                'destination_type' => $v->destination_type,
                'destination_id' => $v->destination_id,
                'arrives_at' => $v->arrives_at,
                'cargo' => $v->cargo_json,
            ])->values(),
        ]);
    }

    /**
     * Simula uma rota antes de despachar: quanto tempo, quanta energia. Sem efeito colateral.
     * A UI precisa disso para o colono decidir; o GDD faz da distância uma decisão econômica.
     */
    public function rota(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'vehicle_id' => ['required', 'integer'],
            'destination_colony_id' => ['required', 'integer'],
        ]);

        $colony = $this->colonia($request);
        $veiculo = $colony->vehicles()->whereKey($dados['vehicle_id'])->first();
        $destino = Colony::find($dados['destination_colony_id']);

        if (! $veiculo || ! $destino) {
            throw new DomainRuleException('rota_invalida', 'Veículo ou destino inexistente.');
        }

        $distancia = MapaFertways::distancia($colony->x, $colony->y, $destino->x, $destino->y);

        return response()->json([
            'distance_slots' => $distancia,
            'leg_seconds' => VeiculoSpecs::segundosDoTrecho($veiculo->type, $distancia),
            'round_trip_seconds' => 2 * VeiculoSpecs::segundosDoTrecho($veiculo->type, $distancia),
            'energy_cost' => VeiculoSpecs::energiaDaViagem($veiculo->type, $distancia),
            'capacity' => $veiculo->capacity,
        ]);
    }

    public function despachar(Request $request, Vehicle $vehicle, DespacharVeiculo $despachar): JsonResponse
    {
        $dados = $request->validate([
            'destination_type' => ['required', 'string', 'in:colonia,mercado_central'],
            'destination_id' => ['nullable', 'integer'],
            'cargo' => ['required', 'array', 'min:1'],
            'cargo.*' => ['integer', 'min:1'],
            // Opcional: amarra esta carga a um Acordo de Troca (D-41). Sem ele, o despacho é
            // comércio informal puro, e nada abate promessa nenhuma.
            'trade_agreement_id' => ['nullable', 'integer'],
        ]);

        $veiculo = $despachar->handle(
            $this->colonia($request),
            $vehicle,
            $dados['destination_type'],
            $dados['destination_id'] ?? null,
            $dados['cargo'],
            $dados['trade_agreement_id'] ?? null,
        );

        return response()->json([
            'id' => $veiculo->id,
            'status' => $veiculo->status,
            'leg' => $veiculo->leg,
            'distance_slots' => $veiculo->distance_slots,
            'arrives_at' => $veiculo->arrives_at,
            'cargo' => $veiculo->cargo_json,
            'trade_agreement_id' => $veiculo->trade_agreement_id,
        ], 201);
    }

    private function colonia(Request $request): Colony
    {
        $colony = $request->user()->colony;

        if (! $colony) {
            throw new DomainRuleException('sem_colonia', 'Funde uma colônia primeiro.');
        }

        return $colony;
    }
}
