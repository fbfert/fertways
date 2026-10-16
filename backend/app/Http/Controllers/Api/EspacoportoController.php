<?php

namespace App\Http\Controllers\Api;

use App\Domain\Espacoporto\Espacoporto;
use App\Domain\Espacoporto\FecharContratoDeRota;
use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Models\Colony;
use App\Models\PlanetaNpc;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** O Espaçoporto da Capital, do lado do colono (D-262). */
class EspacoportoController extends Controller
{
    /** GET /central/espacoporto — o estado, os planetas, o portão e os contratos da colônia. */
    public function index(Request $request, Espacoporto $espacoporto): JsonResponse
    {
        return response()->json($espacoporto->estado($this->colonia($request)));
    }

    /** POST /central/espacoporto/contratos — aluga o Cargueiro para uma ida a um planeta. */
    public function contratar(Request $request, FecharContratoDeRota $fechar): JsonResponse
    {
        $dados = $request->validate([
            'planeta' => ['required', 'string', 'exists:planetas_npc,chave'],
            'venda' => ['present', 'array'],
            'venda.*' => ['integer', 'min:0'],
            'compra' => ['present', 'array'],
            'compra.*' => ['integer', 'min:0'],
            // D-280: o seguro e quantas Sentinelas vão de escolta.
            'seguro' => ['sometimes', 'boolean'],
            'escolta' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ]);

        $planeta = PlanetaNpc::where('chave', $dados['planeta'])->firstOrFail();
        $contrato = $fechar->handle($this->colonia($request), $planeta, $dados['venda'], $dados['compra'], $this->opcoes($dados));

        return response()->json([
            'contrato' => ['id' => $contrato->id, 'chega_em' => $contrato->chega_em->toIso8601String()],
        ], 201);
    }

    /** POST /central/espacoporto/cotacao — o que a escolta e o seguro fariam, sem fechar nada (D-280). */
    public function cotacao(Request $request, FecharContratoDeRota $fechar): JsonResponse
    {
        $dados = $request->validate([
            'planeta' => ['required', 'string', 'exists:planetas_npc,chave'],
            'venda' => ['present', 'array'],
            'venda.*' => ['integer', 'min:0'],
            'compra' => ['present', 'array'],
            'compra.*' => ['integer', 'min:0'],
            'seguro' => ['sometimes', 'boolean'],
            'escolta' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ]);

        $planeta = PlanetaNpc::where('chave', $dados['planeta'])->firstOrFail();

        return response()->json($fechar->cotar($this->colonia($request), $planeta, $dados['venda'], $dados['compra'], $this->opcoes($dados)));
    }

    /** @return array{seguro: bool, escolta: int} */
    private function opcoes(array $dados): array
    {
        return ['seguro' => (bool) ($dados['seguro'] ?? false), 'escolta' => (int) ($dados['escolta'] ?? 0)];
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
