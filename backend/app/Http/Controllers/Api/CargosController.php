<?php

namespace App\Http\Controllers\Api;

use App\Domain\Cargos\PublicarMateria;
use App\Domain\Cargos\SinalizarCargo;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Os atos dos Cargos Públicos que um jogador pode fazer sozinho (§14.2, D-130): o Repórter publica
 * matéria, o Fiscal de Mercado e o Auxiliar de Tesouro sinalizam. Nomear/demitir/suspender continua
 * só do operador, por artisan — mesmo motivo do Conciliador (`fertways:conciliador`, D-44): sem
 * substrato para conferir elegibilidade automaticamente.
 */
class CargosController extends Controller
{
    public function publicarMateria(Request $request, PublicarMateria $publicar): JsonResponse
    {
        $dados = $request->validate([
            'titulo' => ['required', 'string', 'max:120'],
            'corpo' => ['required', 'string', 'max:4000'],
        ]);

        $noticia = $publicar->handle($request->user(), $dados['titulo'], $dados['corpo']);

        return response()->json(['id' => $noticia->id], 201);
    }

    public function sinalizar(Request $request, SinalizarCargo $sinalizar): JsonResponse
    {
        $dados = $request->validate([
            'kind' => ['required', 'string', 'in:fiscal_de_mercado,auxiliar_de_tesouro'],
            'motivo' => ['required', 'string', 'max:500'],
        ]);

        $flag = $sinalizar->handle($request->user(), $dados['kind'], $dados['motivo']);

        return response()->json(['id' => $flag->id], 201);
    }
}
