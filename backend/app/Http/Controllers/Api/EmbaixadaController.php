<?php

namespace App\Http\Controllers\Api;

use App\Domain\Embaixada\Credenciais;
use App\Domain\Embaixada\EmitirCredencial;
use App\Domain\Espacoporto\Espacoporto;
use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Models\Colony;
use App\Models\PlanetaNpc;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** A Embaixada Interplanetária, slot 9 da Capital (D-289). */
class EmbaixadaController extends Controller
{
    public function index(Request $request, Espacoporto $espacoporto, Credenciais $credenciais): JsonResponse
    {
        $colonia = $this->colonia($request);
        $agora = now();

        return response()->json([
            'dias' => (int) (DB::table('espacoporto_settings')->value('credencial_dias') ?: 30),
            'renova_nos_ultimos_dias' => EmitirCredencial::RENOVA_NOS_ULTIMOS_DIAS,
            'planetas' => PlanetaNpc::orderBy('ordem')->get()->map(function (PlanetaNpc $p) use ($colonia, $agora, $espacoporto, $credenciais) {
                $c = $credenciais->vigente($colonia, $p->id, $agora);

                return [
                    'chave' => $p->chave,
                    'nome' => $p->nome,
                    'aberto' => (bool) $p->aberto,
                    'preco_micro' => $p->credencial_preco_micro !== null ? (int) $p->credencial_preco_micro : null,
                    'desconto_bps' => (int) $p->credencial_desconto_bps,
                    'portao' => $espacoporto->portao($colonia, $p),
                    'credencial' => $c ? [
                        'valida_ate' => $c->valida_ate->toIso8601String(),
                        'desconto_bps' => $c->desconto_bps,
                        'pode_renovar' => $c->valida_ate->copy()->subDays(EmitirCredencial::RENOVA_NOS_ULTIMOS_DIAS)->isPast(),
                    ] : null,
                ];
            })->values(),
        ]);
    }

    public function emitir(Request $request, EmitirCredencial $emitir): JsonResponse
    {
        $chave = $request->validate(['planeta' => ['required', 'string']])['planeta'];
        $planeta = PlanetaNpc::where('chave', $chave)->first();

        if (! $planeta) {
            throw new DomainRuleException('planeta_inexistente', 'Planeta desconhecido.');
        }

        $c = $emitir->handle($this->colonia($request), $planeta);

        return response()->json(['valida_ate' => $c->valida_ate->toIso8601String(), 'desconto_bps' => $c->desconto_bps]);
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
