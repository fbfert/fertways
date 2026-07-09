<?php

namespace App\Http\Controllers\Api;

use App\Domain\Logistics\DespacharVeiculo;
use App\Domain\Logistics\MapaFertways;
use App\Domain\Market\CancelarOrdem;
use App\Domain\Market\ColocarOrdem;
use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Models\Colony;
use App\Models\MarketAccount;
use App\Models\MarketOrder;
use App\Models\ResourceType;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mercado Central: a conta na doca (§25.8) e o livro de ofertas (§07).
 *
 * O Mercado não compra nem vende: ele casa ordens de colonos e cobra a taxa de fechamento. O
 * preço-base do catálogo é referência ("faixa de segurança, não preço obrigatório", §06).
 */
class MarketController extends Controller
{
    /** O livro de um recurso: melhores compras e vendas, mais as ordens do próprio colono. */
    public function livro(Request $request): JsonResponse
    {
        $dados = $request->validate(['resource_type' => ['required', 'string']]);
        $recurso = $dados['resource_type'];
        $colony = $this->colonia($request);

        $tipo = ResourceType::find($recurso);

        if (! $tipo) {
            throw new DomainRuleException('recurso_desconhecido', "Recurso inexistente: {$recurso}");
        }

        $lado = fn (string $side, string $dir) => MarketOrder::where('resource_type', $recurso)
            ->where('side', $side)
            ->whereIn('status', ['aberta', 'parcial'])
            ->orderBy('price_micro', $dir)
            ->orderBy('id')
            ->limit(20)
            ->get(['price_micro', 'qty'])
            ->map(fn (MarketOrder $o) => ['price_micro' => $o->price_micro, 'qty' => $o->qty])
            ->values();

        return response()->json([
            'resource_type' => $recurso,
            // Referência, não teto nem piso: o §06 é explícito quanto a isso.
            'preco_base_micro' => (int) $tipo->preco_base_micro,
            'taxa_bps' => (int) $tipo->tax_bps,
            'bids' => $lado('buy', 'desc'),
            'asks' => $lado('sell', 'asc'),
            'minhas_ordens' => MarketOrder::where('colony_id', $colony->id)
                ->where('resource_type', $recurso)
                ->whereIn('status', ['aberta', 'parcial'])
                ->orderBy('id')
                ->get(['id', 'side', 'price_micro', 'qty', 'status']),
        ]);
    }

    /** Abre uma ordem. Vender exige o recurso já depositado na doca (§07). */
    public function ordenar(Request $request, ColocarOrdem $colocar): JsonResponse
    {
        $dados = $request->validate([
            'side' => ['required', 'string', 'in:buy,sell'],
            'resource_type' => ['required', 'string'],
            'qty' => ['required', 'integer', 'min:1'],
            'price_micro' => ['required', 'integer', 'min:1'],
        ]);

        $ordem = $colocar->handle(
            $this->colonia($request),
            $dados['side'],
            $dados['resource_type'],
            $dados['qty'],
            $dados['price_micro'],
        );

        return response()->json([
            'id' => $ordem->id,
            'side' => $ordem->side,
            'resource_type' => $ordem->resource_type,
            'price_micro' => $ordem->price_micro,
            // Quantidade **restante**: se a ordem já cruzou o livro, ela nasce parcial ou executada.
            'qty' => $ordem->qty,
            'status' => $ordem->status,
        ], 201);
    }

    public function cancelar(Request $request, MarketOrder $order, CancelarOrdem $cancelar): JsonResponse
    {
        $ordem = $cancelar->handle($this->colonia($request), $order);

        return response()->json(['id' => $ordem->id, 'status' => $ordem->status]);
    }

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
