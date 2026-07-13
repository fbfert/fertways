<?php

namespace App\Domain\Chat;

use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * O chat que avisa (D-77, aditivo): não-lidas e citações.
 *
 * **A citação é @nickname num canal público.** Detectada no ENVIO (não na leitura: quem chegou
 * depois também merece o selo), até 5 por mensagem — mais que isso é megafone, não conversa. Quem
 * o citado bloqueou não gera menção: bloquear é não ouvir, inclusive quando chamam o seu nome.
 *
 * **O não-lido é derivado, nunca marcado por mensagem**: `chat_reads` guarda até onde cada um leu
 * cada conversa, e o resto é uma contagem indexada. Ler move a marca; nada mais.
 */
class Avisos
{
    /** Registra as citações de uma mensagem pública recém-gravada. */
    public function citar(ChatMessage $mensagem): void
    {
        preg_match_all('/@([A-Za-z0-9_\-]{3,32})/u', $mensagem->body, $achados);

        $nicks = array_slice(array_unique($achados[1] ?? []), 0, 5);

        if ($nicks === []) {
            return;
        }

        $citados = User::whereIn('nickname', $nicks)
            ->where('id', '!=', $mensagem->user_id)
            ->pluck('id');

        // Bloqueio corta a menção na origem: o selo de quem me bloqueou não acende por mim.
        $bloquearam = DB::table('chat_blocks')
            ->whereIn('user_id', $citados)
            ->where('blocked_user_id', $mensagem->user_id)
            ->pluck('user_id')
            ->all();

        $agora = now();
        $linhas = $citados
            ->reject(fn ($id) => in_array($id, $bloquearam, true))
            ->map(fn ($id) => [
                'user_id' => $id,
                'message_id' => $mensagem->id,
                'channel' => $mensagem->channel,
                'seen_at' => null,
                'created_at' => $agora,
            ])
            ->values()
            ->all();

        if ($linhas !== []) {
            DB::table('chat_mentions')->insert($linhas);
        }
    }

    /** O que está aceso para este colono: o poll leve do HUD (roda mesmo com o painel fechado). */
    public function pendencias(User $user): array
    {
        // O join é por coluna INT (peer_id) de propósito — nada de concatenar em SQL, que é onde
        // MariaDB e SQLite param de falar a mesma língua.
        $naoLidas = (int) ChatMessage::where('channel', 'privada')
            ->where('recipient_user_id', $user->id)
            ->leftJoin('chat_reads', function ($join) use ($user) {
                $join->on('chat_reads.peer_id', '=', 'chat_messages.user_id')
                    ->where('chat_reads.user_id', $user->id);
            })
            ->whereRaw('chat_messages.id > COALESCE(chat_reads.last_read_id, 0)')
            ->whereNotIn('chat_messages.user_id', DB::table('chat_blocks')->where('user_id', $user->id)->pluck('blocked_user_id'))
            ->count();

        $mencoes = (int) DB::table('chat_mentions')
            ->where('user_id', $user->id)
            ->whereNull('seen_at')
            ->count();

        return ['privadas_nao_lidas' => $naoLidas, 'mencoes' => $mencoes];
    }

    /**
     * Ler a conversa move a marca — o não-lido morre aqui, e só aqui. E a marca só anda PARA A
     * FRENTE: reler uma página antiga (o polling manda `after` de novo) não pode reacender o selo.
     */
    public function marcarLida(User $leitor, int $outroId, int $ateId): void
    {
        if ($ateId <= 0) {
            return;
        }

        $avancou = DB::table('chat_reads')
            ->where('user_id', $leitor->id)
            ->where('peer_id', $outroId)
            ->where('last_read_id', '<', $ateId)
            ->update(['last_read_id' => $ateId, 'updated_at' => now()]);

        if ($avancou === 0) {
            DB::table('chat_reads')->insertOrIgnore([
                'user_id' => $leitor->id, 'peer_id' => $outroId, 'last_read_id' => $ateId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** Abrir o canal apaga as citações dele: o selo avisou, o colono veio, missão cumprida. */
    public function verMencoes(User $leitor, string $canalReal): void
    {
        DB::table('chat_mentions')
            ->where('user_id', $leitor->id)
            ->where('channel', $canalReal)
            ->whereNull('seen_at')
            ->update(['seen_at' => now()]);
    }
}
