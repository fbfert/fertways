<?php

namespace App\Http\Controllers\Api;

use App\Domain\Transport\ComprarCaminhao;
use App\Domain\Transport\Ministerio;
use App\Domain\Transport\Vagas;
use App\Http\Controllers\Controller;
use App\Models\Colony;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * O Ministério dos Transportes (§16), na vista do colono — slot 8 da Capital (D-60).
 *
 * O colono vê a vitrine (preço, prateleira, fila), a sua própria frota com as placas, e o teto de
 * vagas da sua Central de Transportes. Comprar é o único ato.
 */
class TransportController extends Controller
{
    public function __construct(
        private readonly Vagas $vagas,
        private readonly ComprarCaminhao $comprar,
    ) {}

    /** GET /central/transport — a vitrine e o estado da frota do colono. */
    public function index(Request $request): JsonResponse
    {
        $colony = $this->colonia($request);

        return response()->json([
            'caminhao' => [
                'tipo' => Ministerio::TIPO,
                'preco_fert' => Ministerio::precoFert(),
                'capacidade' => \App\Domain\Logistics\VeiculoSpecs::CAPACIDADE[Ministerio::TIPO],
                // A prateleira é pública: o colono precisa saber se leva na hora ou se espera.
                'em_estoque' => Vehicle::whereNull('colony_id')->where('status', 'estoque')->count(),
                'em_fabricacao' => Vehicle::whereNull('colony_id')->where('status', 'fabricando')->count(),
                'minutos_fabricacao' => Ministerio::MINUTOS_FABRICACAO,
            ],
            'frota' => [
                'teto' => $this->vagas->teto($colony),
                'ocupadas' => $this->vagas->ocupadas($colony),
                'livres' => $this->vagas->livres($colony),
                // Por que o teto é o que é: sem isto, o colono não faz ideia de onde saiu o número.
                'regra' => 'O teto é o nível da sua Central de Transportes (mínimo 1).',
            ],
            'veiculos' => $colony->vehicles()->orderBy('id')->get()->map(fn (Vehicle $v) => [
                'id' => $v->id,
                'placa' => $v->plate,
                'tipo' => $v->type,
                'status' => $v->status,
                'chega_em' => $v->arrives_at?->toIso8601String(),
            ]),
        ]);
    }

    /** POST /central/transport/buy — compra um Caminhão de Carga da prateleira do governo. */
    public function comprar(Request $request): JsonResponse
    {
        $caminhao = $this->comprar->handle($this->colonia($request));

        return response()->json([
            'comprado' => [
                'id' => $caminhao->id,
                'placa' => $caminhao->plate,
                'tipo' => $caminhao->type,
                // A entrega é física (D-60): ele vem dirigindo da Capital.
                'a_caminho' => true,
                'chega_em' => $caminhao->arrives_at?->toIso8601String(),
            ],
        ], 201);
    }

    private function colonia(Request $request): Colony
    {
        return $request->user()->colony;
    }
}
