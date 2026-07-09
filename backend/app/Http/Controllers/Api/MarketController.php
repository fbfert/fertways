<?php

namespace App\Http\Controllers\Api;

use App\Domain\Logistics\DespacharVeiculo;
use App\Domain\Logistics\MapaFertways;
use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Models\Colony;
use App\Models\MarketAccount;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mercado Central (GDD §25.8).
 *
 * Só a conta e a retirada. A **venda** ainda não existe: §22.2 e §24.8 dão preços trinta e oito
 * vezes diferentes para os Componentes Eletrônicos e ninguém arbitrou (pendência D-24).
 */
class MarketController extends Controller
{
    /** O saldo do colono no Mercado, e a distância que um veículo dele percorreria até lá. */
    public function conta(Request $request): JsonResponse
    {
        $colony = $this->colonia($request);

        $saldos = MarketAccount::where('colony_id', $colony->id)
            ->where('amount', '>', 0)
            ->orderBy('resource_type')
            ->get(['resource_type', 'amount']);

        return response()->json([
            'capital' => ['x' => MapaFertways::CAPITAL_X, 'y' => MapaFertways::CAPITAL_Y],
            'distance_slots' => MapaFertways::ateCapital($colony->x, $colony->y),
            'balances' => $saldos->map(fn (MarketAccount $c) => [
                'resource_type' => $c->resource_type,
                'amount' => $c->amount,
            ])->values(),
        ]);
    }

    /** Manda um veículo buscar carga da conta e trazê-la até o slot (§25.8). */
    public function retirar(Request $request, Vehicle $vehicle, DespacharVeiculo $despachar): JsonResponse
    {
        $dados = $request->validate([
            'cargo' => ['required', 'array', 'min:1'],
            'cargo.*' => ['integer', 'min:1'],
        ]);

        $veiculo = $despachar->retirar($this->colonia($request), $vehicle, $dados['cargo']);

        return response()->json([
            'id' => $veiculo->id,
            'status' => $veiculo->status,
            'leg' => $veiculo->leg,
            'trip_purpose' => $veiculo->trip_purpose,
            'distance_slots' => $veiculo->distance_slots,
            'arrives_at' => $veiculo->arrives_at,
            // Reservada: embarca ao chegar na Capital e chega ao slot no fim da volta.
            'cargo' => $veiculo->cargo_json,
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
