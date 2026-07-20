<?php

namespace App\Http\Controllers\Api;

use App\Domain\Endurance\ComprarPeca;
use App\Domain\Endurance\EnduranceSpecs;
use App\Domain\Marco\Curva;
use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Models\Colony;
use App\Models\ColonyEndurancePiece;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A Loja de Peças da Endurance (D-132) — 8 seções × 4 camadas, ligadas ao Marco do §05.
 */
class EnduranceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $colony = $this->colonia($request);
        $marco = Curva::marco((int) $colony->xp);

        $minhas = ColonyEndurancePiece::where('colony_id', $colony->id)->pluck('peca_key')->all();
        $esgotadas = ColonyEndurancePiece::pluck('peca_key')->unique()->all();

        $catalogo = collect(EnduranceSpecs::catalogo())->map(function (array $p) use ($marco, $minhas, $esgotadas) {
            $possuida = in_array($p['chave'], $minhas, true);

            $estado = match (true) {
                $possuida => 'possuida',
                $p['unica'] && in_array($p['chave'], $esgotadas, true) => 'esgotada',
                $marco < $p['marco_minimo'] => 'bloqueada',
                default => 'disponivel',
            };

            return [
                'chave' => $p['chave'],
                'secao' => $p['secao'],
                'secao_nome' => $p['secao_nome'],
                'camada' => $p['camada'],
                'nome' => $p['nome'],
                'marco_minimo' => $p['marco_minimo'],
                'preco_fert' => $p['preco_micro'] / \App\Models\Colony::MICRO_POR_FERT,
                'desconto_tributo_pct' => $p['desconto_tributo_bps'] / 100,
                'unica' => $p['unica'],
                'estado' => $estado,
            ];
        })->values();

        return response()->json([
            'meu_marco' => $marco,
            'meu_desconto_pct' => app(\App\Domain\Endurance\DescontoDeEndurance::class)->desconto($colony) / 100,
            'teto_desconto_pct' => EnduranceSpecs::TETO_DESCONTO_BPS / 100,
            'pecas' => $catalogo,
        ]);
    }

    public function comprar(Request $request, string $peca, ComprarPeca $comprar): JsonResponse
    {
        $registro = $comprar->handle($this->colonia($request), $peca);

        return response()->json(['chave' => $registro->peca_key], 201);
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
