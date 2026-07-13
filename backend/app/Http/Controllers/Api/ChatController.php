<?php

namespace App\Http\Controllers\Api;

use App\Domain\Chat\EnviarMensagem;
use App\Domain\Chat\LerMensagens;
use App\Domain\Chat\Regiao;
use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * O Sistema de Mensagens (§10; D-77) — por POLLING, e isso foi arbitragem: o GDD sugere Reverb,
 * mas o servidor tem 4 GB divididos com o MariaDB de produção, e um daemon de websocket é memória
 * que o jogo não tem. A tela consulta `?after=<último id>` a cada poucos segundos enquanto aberta.
 */
class ChatController extends Controller
{
    /** O poll leve do HUD: roda a cada ~30 s mesmo com o painel fechado — é só contagem indexada. */
    public function pendencias(Request $request, \App\Domain\Chat\Avisos $avisos): JsonResponse
    {
        return response()->json($avisos->pendencias($request->user()));
    }

    public function canais(Request $request, EnviarMensagem $enviar): JsonResponse
    {
        $user = $request->user();
        $colony = $user->colony()->first();
        $silencio = $enviar->silencioVigente($user);

        return response()->json([
            'nickname' => $user->nickname,
            'regiao' => $colony ? Regiao::NOMES[Regiao::de($colony)] : null,
            'silenciado_ate' => $silencio?->expires_at?->toIso8601String(),
            'bloqueados' => DB::table('chat_blocks')
                ->join('users', 'users.id', '=', 'chat_blocks.blocked_user_id')
                ->where('chat_blocks.user_id', $user->id)
                ->get(['users.id', 'users.nickname']),
        ]);
    }

    public function ler(Request $request, string $canal, LerMensagens $ler): JsonResponse
    {
        $mensagens = $ler->handle($request->user(), $canal, (int) $request->query('after', 0));

        return response()->json(['mensagens' => $mensagens->map($this->linha(...))]);
    }

    public function falar(Request $request, string $canal, EnviarMensagem $enviar): JsonResponse
    {
        $dados = $request->validate(['body' => ['required', 'string', 'max:500']]);

        $m = $enviar->handle($request->user(), $canal, $dados['body']);

        return response()->json($this->linha($m), 201);
    }

    public function conversas(Request $request, LerMensagens $ler): JsonResponse
    {
        $eu = $request->user();
        $conversas = $ler->conversas($eu);
        $nomes = User::whereIn('id', $conversas->pluck('com'))->pluck('nickname', 'id');
        $marcas = DB::table('chat_reads')->where('user_id', $eu->id)->pluck('last_read_id', 'peer_id');

        return response()->json([
            'conversas' => $conversas->map(fn ($c) => [
                'user_id' => $c['com'],
                'nickname' => $nomes[$c['com']] ?? '—',
                'ultima' => $this->linha($c['mensagem']),
                'nao_lidas' => ChatMessage::where('channel', 'privada')
                    ->where('user_id', $c['com'])
                    ->where('recipient_user_id', $eu->id)
                    ->where('id', '>', (int) ($marcas[$c['com']] ?? 0))
                    ->count(),
            ]),
        ]);
    }

    public function privada(Request $request, User $user, LerMensagens $ler): JsonResponse
    {
        $mensagens = $ler->privada($request->user(), $user, (int) $request->query('after', 0));

        return response()->json([
            'com' => ['id' => $user->id, 'nickname' => $user->nickname],
            'mensagens' => $mensagens->map($this->linha(...)),
        ]);
    }

    public function falarPrivado(Request $request, User $user, EnviarMensagem $enviar): JsonResponse
    {
        $dados = $request->validate(['body' => ['required', 'string', 'max:500']]);

        $m = $enviar->handle($request->user(), 'privada', $dados['body'], $user);

        return response()->json($this->linha($m), 201);
    }

    /** Bloquear é não ouvir: some da MINHA tela, e ele não me manda mais privadas. */
    public function bloquear(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return response()->json(['code' => 'auto_bloqueio', 'message' => 'Não dá para bloquear a si mesmo.'], 422);
        }

        DB::table('chat_blocks')->insertOrIgnore([
            'user_id' => $request->user()->id, 'blocked_user_id' => $user->id, 'created_at' => now(),
        ]);

        return response()->json(['bloqueado' => $user->nickname], 201);
    }

    public function desbloquear(Request $request, User $user): JsonResponse
    {
        DB::table('chat_blocks')
            ->where('user_id', $request->user()->id)
            ->where('blocked_user_id', $user->id)
            ->delete();

        return response()->json(['desbloqueado' => $user->nickname]);
    }

    private function linha(ChatMessage $m): array
    {
        return [
            'id' => $m->id,
            'de' => ['id' => $m->user_id, 'nickname' => $m->user->nickname ?? '—'],
            'body' => $m->body,
            'em' => $m->created_at->toIso8601String(),
        ];
    }
}
