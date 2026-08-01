<?php

namespace App\Http\Controllers\Api;

use App\Domain\Especializacao\Perfil;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * O perfil derivado da colônia (A2.4).
 *
 * **Só GET, e nunca haverá POST.** O GDD ALPHA 2 §8.1 proíbe escolha declarada de perfil: o colono
 * não seleciona "sou agrícola", ele se torna agrícola pelo que pesquisou e construiu. Um endpoint de
 * escrita aqui seria a segunda camada que o §8.1 existe para impedir — e traria junto o problema do
 * "posso trocar?", com respec, custo de troca e a troca oportunista na véspera de cada evento.
 */
class PerfilDaColoniaController extends Controller
{
    public function show(Request $request, Perfil $perfil): JsonResponse
    {
        $colonia = $request->user()->colony;

        if (! $colonia) {
            return response()->json(['tem_colonia' => false]);
        }

        return response()->json(['tem_colonia' => true] + $perfil->de($colonia));
    }
}
