<?php

namespace App\Http\Controllers\Api;

use App\Domain\Logistics\DespacharVeiculo;
use App\Domain\Logistics\MapaFertways;
use App\Domain\Market\CancelarOrdem;
use App\Domain\Market\ColocarOrdem;
use App\Domain\Market\Deposito;
use App\Domain\Market\ExecutarOrdem;
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
    /**
     * A vitrine das Ofertas Globais (D-58).
     *
     * `resource_type` virou **opcional**: sem ele, a vitrine mostra todos os recursos. Era essa a
     * causa principal de o colono não ver oferta nenhuma — a lista pedia um recurso por vez, abria
     * em `metal_bruto`, e a UI só oferecia 8 dos 26. Cada linha agora diz **de quem é**: sem isso,
     * as ofertas dos outros ficavam indistinguíveis das próprias.
     */
    public function livro(Request $request): JsonResponse
    {
        $dados = $request->validate(['resource_type' => ['sometimes', 'nullable', 'string']]);
        $recurso = $dados['resource_type'] ?? null;
        $colony = $this->colonia($request);

        if ($recurso !== null && ! ResourceType::find($recurso)) {
            throw new DomainRuleException('recurso_desconhecido', "Recurso inexistente: {$recurso}");
        }

        $ofertas = MarketOrder::query()
            ->when($recurso, fn ($q) => $q->where('resource_type', $recurso))
            ->whereIn('status', ['aberta', 'parcial'])
            ->with('colony:id,name')
            ->orderBy('resource_type')
            ->orderBy('side')
            ->orderBy('price_micro')
            ->orderBy('id')
            ->get()
            ->map(fn (MarketOrder $o) => [
                'id' => $o->id,
                'resource_type' => $o->resource_type,
                'side' => $o->side,
                'price_micro' => (int) $o->price_micro,
                'qty' => (int) $o->qty,
                'colony_id' => $o->colony_id,
                'colonia' => $o->colony?->name,
                // A UI usa isto para trocar "Comprar" por "Cancelar": a própria oferta não se executa
                // (§26.4 trata conta-alternativa como fraude).
                'minha' => $o->colony_id === $colony->id,
            ])
            ->values();

        // Referência, não teto nem piso: o §06 é explícito quanto a isso.
        $catalogo = ResourceType::orderBy('code')->get(['code', 'nome', 'tax_class', 'tax_bps', 'preco_base_micro'])
            ->map(fn (ResourceType $t) => [
                'code' => $t->code,
                'nome' => $t->nome,
                'tax_class' => $t->tax_class,
                'taxa_bps' => (int) $t->tax_bps,
                'preco_base_micro' => (int) $t->preco_base_micro,
                'teto_deposito' => Deposito::teto($t->code),
            ]);

        return response()->json([
            'resource_type' => $recurso,
            'ofertas' => $ofertas,
            'catalogo' => $catalogo,
        ]);
    }

    /** Fecha uma oferta da vitrine. O preço é o dela; parcial é permitido (D-58). */
    public function executar(Request $request, int $order, ExecutarOrdem $executar): JsonResponse
    {
        $dados = $request->validate(['qty' => ['required', 'integer', 'min:1']]);

        $ordem = $executar->handle($this->colonia($request), $order, $dados['qty']);

        return response()->json([
            'id' => $ordem->id,
            'qty' => (int) $ordem->qty,
            'status' => $ordem->status,
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

        $estoque = $colony->resources()->pluck('amount', 'resource_type');

        /*
         * D-58: a tela mostra os dois estoques lado a lado, porque a regra do jogo agora depende de
         * distingui-los — o que está na colônia se negocia entre colonos; o que está no depósito da
         * Capital se oferta no Mercado Central. `ocupado` inclui o que está preso em ofertas: é o
         * número que decide se cabe mais carga, e não o saldo livre.
         */
        $deposito = ResourceType::orderBy('code')->pluck('code')->map(function (string $code) use ($colony, $estoque) {
            $ocupado = Deposito::ocupado($colony->id, $code);
            $saldo = (int) MarketAccount::where('colony_id', $colony->id)
                ->where('resource_type', $code)->value('amount');

            return [
                'resource_type' => $code,
                'na_colonia' => (int) ($estoque[$code] ?? 0),
                'no_deposito' => $saldo,
                'em_ofertas' => $ocupado - $saldo,
                'teto' => Deposito::teto($code),
                'livre' => Deposito::livre($colony->id, $code),
            ];
        })->values();

        // O orçamento do frete público (§07, D-76): a tela mostra o preço ANTES de o colono pagar.
        $frete = app(\App\Domain\Frete\FretePublico::class)->orcamento($colony);

        return response()->json([
            'capital' => ['x' => MapaFertways::CAPITAL_X, 'y' => MapaFertways::CAPITAL_Y],
            'distance_slots' => MapaFertways::ateCapital($colony->x, $colony->y),
            'balances' => $saldos->map(fn (MarketAccount $c) => [
                'resource_type' => $c->resource_type,
                'amount' => $c->amount,
            ])->values(),
            'deposito' => $deposito,
            'frete' => [
                'preco_fert' => $frete['preco_micro'] / 1_000_000,
                'capacidade' => $frete['capacidade'],
                'caminhoes_livres' => $frete['caminhoes_livres'],
            ],
        ]);
    }

    /** O governo leva (§07, D-76): frete público da doca até a colônia — pago, com tributo na chegada. */
    public function frete(Request $request, \App\Domain\Frete\FretePublico $frete): JsonResponse
    {
        $dados = $request->validate([
            'cargo' => ['required', 'array', 'min:1'],
            'cargo.*' => ['integer', 'min:1'],
        ]);

        $colony = $this->colonia($request);
        $caminhao = $frete->despachar($colony, $dados['cargo']);

        return response()->json([
            'caminhao' => $caminhao->plate,
            'chega_at' => $caminhao->arrives_at?->toIso8601String(),
            'preco_fert' => $frete->orcamento($colony)['preco_micro'] / 1_000_000,
        ], 201);
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
