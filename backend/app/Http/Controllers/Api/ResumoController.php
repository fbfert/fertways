<?php

namespace App\Http\Controllers\Api;

use App\Domain\Telemetria\ResumoDeRetorno;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Desde sua última visita" (A2.0.3).
 *
 * **Dois verbos, e a separação é a regra do §5.1**: `GET` monta o resumo e **não** move o marcador;
 * `POST /visto` move. Se o `GET` movesse, abrir a tela e fechá-la sem ler já teria consumido a
 * janela, e o que aconteceu enquanto o jogador estava fora sumiria sem nunca ter sido mostrado.
 */
class ResumoController extends Controller
{
    public function show(Request $request, ResumoDeRetorno $resumo): JsonResponse
    {
        return response()->json($resumo->montar($request->user()));
    }

    /**
     * O jogador fechou o resumo: a janela seguinte começa agora.
     *
     * Idempotente na prática — fechar duas vezes só adianta o marcador para o mesmo instante, e o
     * pior que acontece é o segundo resumo começar alguns segundos depois. Não vale a pena travar
     * isso com estado extra.
     */
    public function visto(Request $request, ResumoDeRetorno $resumo): JsonResponse
    {
        $resumo->marcarVisto($request->user());

        return response()->json(['message' => 'Resumo marcado como visto.']);
    }
}
