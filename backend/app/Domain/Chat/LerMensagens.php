<?php

namespace App\Domain\Chat;

use App\Domain\Logistics\MapaFertways;
use App\Exceptions\DomainRuleException;
use App\Models\ChatMessage;
use App\Models\ChatSetting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lê um canal pelos olhos de um colono (§10; D-77).
 *
 * Três regras moram na LEITURA, não na escrita:
 *
 *   região       cada um lê a região onde a colônia dele está — mudou de quadrante, mudou de sala.
 *   vizinhança   o canal é um RAIO: vê-se o que foi dito a até N slots da colônia do leitor (N é
 *                do operador). Dois vizinhos ouvem-se; o outro lado do planeta, não.
 *   bloqueio     quem eu bloqueei some da MINHA tela em todos os canais — bloquear é não ouvir,
 *                não é calar o outro.
 */
class LerMensagens
{
    private const PAGINA = 50;

    public function handle(User $leitor, string $canal, int $depoisDe = 0): Collection
    {
        $colony = $leitor->colony()->first();

        if (! $colony) {
            throw new DomainRuleException('sem_colonia', 'Funde uma colônia antes de ouvir o rádio do planeta.');
        }

        $consulta = match ($canal) {
            'global' => ChatMessage::where('channel', 'global'),
            'regiao' => ChatMessage::where('channel', 'regiao:'.Regiao::de($colony)),
            'vizinhanca' => ChatMessage::where('channel', 'vizinhanca'),
            default => throw new DomainRuleException('canal_invalido', "Não existe o canal {$canal}."),
        };

        $mensagens = $consulta
            ->where('id', '>', $depoisDe)
            ->whereNotIn('user_id', $this->bloqueadosPor($leitor))
            ->with('user:id,nickname')
            ->orderByDesc('id')
            ->limit(self::PAGINA)
            ->get()
            ->reverse()
            ->values();

        if ($canal === 'vizinhanca') {
            $raio = (int) ChatSetting::singleton()->vizinhanca_raio_slots;

            $mensagens = $mensagens
                ->filter(fn (ChatMessage $m) => $m->x !== null
                    && MapaFertways::distancia($m->x, $m->y, $colony->x, $colony->y) <= $raio)
                ->values();
        }

        return $mensagens;
    }

    /** As conversas privadas do colono: o outro lado e a última fala de cada uma. */
    public function conversas(User $leitor): Collection
    {
        $bloqueados = $this->bloqueadosPor($leitor);

        return ChatMessage::where('channel', 'privada')
            ->where(fn ($q) => $q->where('user_id', $leitor->id)->orWhere('recipient_user_id', $leitor->id))
            ->orderByDesc('id')
            ->limit(500)
            ->with('user:id,nickname')
            ->get()
            ->map(fn (ChatMessage $m) => [
                'com' => $m->user_id === $leitor->id ? $m->recipient_user_id : $m->user_id,
                'mensagem' => $m,
            ])
            ->reject(fn ($c) => in_array($c['com'], $bloqueados, true))
            ->unique('com')
            ->values();
    }

    public function privada(User $leitor, User $outro, int $depoisDe = 0): Collection
    {
        return ChatMessage::where('channel', 'privada')
            ->where(fn ($q) => $q
                ->where(fn ($a) => $a->where('user_id', $leitor->id)->where('recipient_user_id', $outro->id))
                ->orWhere(fn ($b) => $b->where('user_id', $outro->id)->where('recipient_user_id', $leitor->id)))
            ->where('id', '>', $depoisDe)
            ->with('user:id,nickname')
            ->orderByDesc('id')
            ->limit(self::PAGINA)
            ->get()
            ->reverse()
            ->values();
    }

    /** @return list<int> */
    private function bloqueadosPor(User $leitor): array
    {
        return DB::table('chat_blocks')->where('user_id', $leitor->id)->pluck('blocked_user_id')->all();
    }
}
