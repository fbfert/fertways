<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Models\Feedback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Bugs/Melhorias (D-95): o formulário ao lado do Chat. O jogador escreve; o Governo lê e responde
 * pelo painel de admin — a resposta chega pelo rádio, remetente "Capital" (reusa o D-91).
 */
class FeedbackController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $colony = $user->colony;

        if (! $colony) {
            throw new DomainRuleException('sem_colonia', 'Funde uma colônia primeiro.');
        }

        $dados = $request->validate([
            'tipo' => ['required', 'string', 'in:'.implode(',', Feedback::TIPOS)],
            'assunto' => ['required', 'string', 'max:120'],
            'mensagem' => ['required', 'string', 'min:10', 'max:4000'],
        ]);

        // Instantâneo do momento do envio (docblock da migration) — não um join ao vivo.
        Feedback::create([
            'user_id' => $user->id,
            'colony_id' => $colony->id,
            'email' => $user->email,
            'colony_name' => $colony->name,
            'nickname' => $user->nickname,
            'tipo' => $dados['tipo'],
            'assunto' => $dados['assunto'],
            'mensagem' => $dados['mensagem'],
        ]);

        return response()->json(['ok' => true], 201);
    }
}
