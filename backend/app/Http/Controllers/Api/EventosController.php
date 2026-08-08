<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GameEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Os eventos de mundo, na vista do colono (A2.8).
 *
 * ## ⚠️ Por que esta rota existe
 *
 * Um motor que muda a economia sem que ninguém veja é indistinguível de um defeito. Foi o erro que
 * cometi no D-180, publicando uma rota de upgrade sem tela; aqui a consequência seria pior, porque o
 * jogador veria a produção cair e concluiria que o jogo está quebrado.
 *
 * ## As três visibilidades, e o que cada uma revela
 *
 * - **anunciado** — nome e mensagem pública;
 * - **parcial** — diz que ALGO está mexendo na produção, e não diz o quê. É a tensão sem a
 *   explicação: o jogador sabe que há algo acontecendo e vai procurar;
 * - **secreto** — não aparece aqui de jeito nenhum.
 *
 * `notas_internas` **nunca** sai daqui, em nenhuma visibilidade: é a nota de bastidor do operador.
 */
class EventosController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $colonia = $request->user()->colony;
        $agora = now();

        $eventos = GameEvent::query()
            ->whereIn('status', ['ativo', 'cancelado'])
            ->where('comeca_em', '<=', $agora)
            ->where('termina_em', '>=', $agora)
            ->where(fn ($q) => $q->where('escopo', 'mundo')->when(
                $colonia !== null,
                fn ($q) => $q->orWhere(fn ($q2) => $q2->where('escopo', 'colonia')
                    ->where('colony_id', $colonia->id)),
            ))
            ->orderBy('comeca_em')
            ->get()
            ->filter(fn (GameEvent $e) => $e->vigenteEm($agora) && $e->visivelAoJogador());

        return response()->json([
            'eventos' => $eventos->map(fn (GameEvent $e) => $e->visibilidade === 'parcial'
                ? [
                    // Parcial: a tensão sem a explicação. Nem o nome, nem o número.
                    'parcial' => true,
                    'termina_em' => $e->termina_em->toIso8601String(),
                ]
                : [
                    'parcial' => false,
                    'nome' => $e->nome,
                    'mensagem' => $e->mensagem_publica,
                    'modificador' => $e->modificador,
                    // Em porcentagem, com sinal: é como o jogador sente. Nulo num evento que só
                    // entrega cesta (D-232) — ele não mexe em taxa nenhuma, e "0%" seria mentira.
                    'efeito' => $e->efeito_bps === null ? null : $e->efeito_bps / 100,
                    'recurso' => $e->resource_type,
                    /*
                     * D-232: que este evento ENTREGA alguma coisa. Sem isto, a Cesta de Presente
                     * apareceria na tela como um evento sem efeito nenhum — o jogador leria o nome,
                     * não veria número, e concluiria que está quebrado.
                     */
                    'cesta' => $e->temCesta(),
                    'termina_em' => $e->termina_em->toIso8601String(),
                ])->values(),
        ]);
    }
}
