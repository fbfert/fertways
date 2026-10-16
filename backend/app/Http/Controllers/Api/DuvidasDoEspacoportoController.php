<?php

namespace App\Http\Controllers\Api;

use App\Domain\Cargos\AtendimentoDoEspacoporto;
use App\Http\Controllers\Controller;
use App\Models\DuvidaDoEspacoporto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * As dúvidas do Espaçoporto (D-270): o colono pergunta e avalia; o Atendente vê a fila, responde ou
 * encaminha. Quem não é Atendente nunca vê a dúvida dos outros.
 */
class DuvidasDoEspacoportoController extends Controller
{
    public function index(Request $request, AtendimentoDoEspacoporto $atendimento): JsonResponse
    {
        $eu = $request->user();
        $souAtendente = $atendimento->ehAtendente($eu);

        $fila = $souAtendente
            ? DuvidaDoEspacoporto::with('user:id,nickname')
                ->whereNull('respondida_em')->whereNull('encaminhada_em')
                ->where('user_id', '!=', $eu->id)
                ->orderBy('id')->limit(50)->get()
                ->map(fn ($d) => ['id' => $d->id, 'de' => $d->user?->nickname, 'pergunta' => $d->pergunta, 'em' => $d->created_at->toIso8601String()])
            : collect();

        return response()->json([
            'sou_atendente' => $souAtendente,
            'minhas' => DuvidaDoEspacoporto::where('user_id', $eu->id)->orderByDesc('id')->limit(10)->get()
                ->map(fn ($d) => [
                    'id' => $d->id,
                    'pergunta' => $d->pergunta,
                    'resposta' => $d->resposta,
                    'encaminhada' => $d->encaminhada_em !== null,
                    'ajudou' => $d->ajudou,
                    'em' => $d->created_at->toIso8601String(),
                ]),
            'fila' => $fila->values(),
        ]);
    }

    public function perguntar(Request $request, AtendimentoDoEspacoporto $atendimento): JsonResponse
    {
        $dados = $request->validate(['pergunta' => ['required', 'string', 'min:5', 'max:500']]);

        return response()->json(['id' => $atendimento->perguntar($request->user(), $dados['pergunta'])->id], 201);
    }

    public function responder(Request $request, int $duvida, AtendimentoDoEspacoporto $atendimento): JsonResponse
    {
        $dados = $request->validate(['resposta' => ['required', 'string', 'min:2', 'max:2000']]);
        $atendimento->responder($request->user(), $duvida, $dados['resposta']);

        return response()->json(['ok' => true]);
    }

    public function encaminhar(Request $request, int $duvida, AtendimentoDoEspacoporto $atendimento): JsonResponse
    {
        $atendimento->encaminhar($request->user(), $duvida);

        return response()->json(['ok' => true]);
    }

    public function avaliar(Request $request, int $duvida, AtendimentoDoEspacoporto $atendimento): JsonResponse
    {
        $dados = $request->validate(['ajudou' => ['required', 'boolean']]);
        $atendimento->avaliar($request->user(), $duvida, (bool) $dados['ajudou']);

        return response()->json(['ok' => true]);
    }
}
