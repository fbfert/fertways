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
                'plate' => $v->plate,
                'nickname' => $v->nickname,
                'level' => $v->level,
                'status' => $v->status,
                'capacity' => $v->capacity,
                // A capacidade EFETIVA, que é a que o despacho vai cobrar (§16.4, D-60). A UI
                // monta a carga contra este número desde o D-65, agora que ela pode somar vários
                // recursos: oferecer a nominal seria deixar o colono montar uma carga que o
                // servidor recusa.
                'capacity_efetiva' => app(\App\Domain\Transport\Conservacao::class)->capacidadeEfetiva($v),
                // Onde ele está parado (D-65): em casa, ou no Pátio da Capital.
                'local' => $v->local,
                'parked_at' => $v->parked_at,
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

        // A cotação tem de mentir tão pouco quanto o despacho: desde o D-60 o veículo desgastado é
        // mais lento e carrega menos (§16.4), e um orçamento pela spec crua prometeria ao colono um
        // tempo e uma carga que o veículo dele já não entrega.
        $conservacao = app(\App\Domain\Transport\Conservacao::class);
        $trecho = $conservacao->segundosDoTrecho($veiculo, $distancia);

        return response()->json([
            'distance_slots' => $distancia,
            'leg_seconds' => $trecho,
            'round_trip_seconds' => 2 * $trecho,
            'energy_cost' => VeiculoSpecs::energiaDaViagem($veiculo->type, $distancia),
            'capacity' => $conservacao->capacidadeEfetiva($veiculo),
        ]);
    }

    public function despachar(Request $request, Vehicle $vehicle, DespacharVeiculo $despachar): JsonResponse
    {
        $dados = $request->validate([
            'destination_type' => ['required', 'string', 'in:colonia,mercado_central'],
            'destination_id' => ['nullable', 'integer'],
            // Vazio só é aceito na volta do Pátio para a própria colônia (D-91) — `DespacharVeiculo`
            // é quem decide isso, com contexto de origem que esta validação não tem. Qualquer outra
            // combinação continua recusando carga vazia em `validarCarga()`.
            'cargo' => ['present', 'array'],
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

    /** Dá (ou tira) um apelido do veículo — a placa não muda, ela é do veículo, não do dono (§16.3). */
    public function renomear(Request $request, Vehicle $vehicle): JsonResponse
    {
        $colony = $this->colonia($request);

        if ($vehicle->colony_id !== $colony->id) {
            throw new DomainRuleException('veiculo_de_outra_colonia', 'Este veículo não é seu.');
        }

        $dados = $request->validate([
            'nickname' => ['nullable', 'string', 'max:60'],
        ]);

        $nickname = trim((string) ($dados['nickname'] ?? ''));
        $vehicle->update(['nickname' => $nickname !== '' ? $nickname : null]);

        return response()->json(['nickname' => $vehicle->nickname]);
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
