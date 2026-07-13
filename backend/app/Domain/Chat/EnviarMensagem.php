<?php

namespace App\Domain\Chat;

use App\Domain\Ministry\PunicaoSpecs;
use App\Exceptions\DomainRuleException;
use App\Models\ChatMessage;
use App\Models\Punishment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Envia uma mensagem (§10; D-77). É a ÚNICA porta de escrita do chat.
 *
 * As regras, e de onde cada uma vem:
 *
 *   silêncio     o silenciado perde OS CHATS PÚBLICOS (§10.2, textual) — a privada continua: a
 *                pena cala a praça, não a boca. É a pena `silencio` do D-44, que dormiu até hoje.
 *   filtro       só nos canais públicos (§10.2: "federação e privadas não têm filtro automático").
 *                Barrou: a mensagem NÃO entra, o autor sabe por quê, e a reincidência fica
 *                registrada para o moderador (`chat_filter_hits`).
 *   bloqueio     bloqueado não manda privada para quem o bloqueou (MVP social, seção 15).
 *   vizinhança   a mensagem carrega a POSIÇÃO da colônia do autor: o canal é um raio, não uma sala.
 */
class EnviarMensagem
{
    public const PUBLICOS = ['global', 'regiao', 'vizinhanca'];

    public function handle(User $autor, string $canal, string $corpo, ?User $destinatario = null): ChatMessage
    {
        $corpo = trim($corpo);

        if ($corpo === '' || mb_strlen($corpo) > 500) {
            throw new DomainRuleException('mensagem_invalida', 'A mensagem precisa ter entre 1 e 500 caracteres.');
        }

        $colony = $autor->colony()->first();

        if (! $colony) {
            throw new DomainRuleException('sem_colonia', 'Funde uma colônia antes de falar no rádio do planeta.');
        }

        $publico = in_array($canal, self::PUBLICOS, true);

        if ($publico && ($silencio = $this->silencioVigente($autor))) {
            throw new DomainRuleException(
                'silenciado',
                'Você está em silêncio até '.$silencio->expires_at?->format('d/m H:i')
                .' (pena do Ministério, §9.4). As mensagens privadas continuam abertas.',
            );
        }

        if ($publico && ($termo = Filtro::barra($corpo))) {
            DB::table('chat_filter_hits')->insert([
                'user_id' => $autor->id, 'termo' => $termo, 'channel' => $canal, 'created_at' => now(),
            ]);

            throw new DomainRuleException(
                'termo_vedado',
                'A mensagem contém um termo vedado e não foi enviada (§10.2).',
            );
        }

        return match ($canal) {
            'global' => $this->gravar($autor, 'global', $corpo),
            'regiao' => $this->gravar($autor, 'regiao:'.Regiao::de($colony), $corpo),
            'vizinhanca' => $this->gravar($autor, 'vizinhanca', $corpo, x: $colony->x, y: $colony->y),
            'privada' => $this->privada($autor, $destinatario, $corpo),
            default => throw new DomainRuleException('canal_invalido', "Não existe o canal {$canal}. O de federação espera as federações existirem."),
        };
    }

    private function privada(User $autor, ?User $destinatario, string $corpo): ChatMessage
    {
        if (! $destinatario || $destinatario->id === $autor->id) {
            throw new DomainRuleException('destinatario_invalido', 'Diga com quem você quer falar.');
        }

        $bloqueado = DB::table('chat_blocks')
            ->where('user_id', $destinatario->id)
            ->where('blocked_user_id', $autor->id)
            ->exists();

        if ($bloqueado) {
            throw new DomainRuleException('bloqueado', "{$destinatario->nickname} bloqueou as suas mensagens.");
        }

        return $this->gravar($autor, 'privada', $corpo, destinatario: $destinatario->id);
    }

    private function gravar(User $autor, string $canal, string $corpo, ?int $destinatario = null, ?int $x = null, ?int $y = null): ChatMessage
    {
        $mensagem = ChatMessage::create([
            'user_id' => $autor->id,
            'channel' => $canal,
            'recipient_user_id' => $destinatario,
            'x' => $x,
            'y' => $y,
            'body' => $corpo,
            'created_at' => now(),
        ]);

        // A citação (@nickname) só existe na praça: na privada o aviso é o próprio não-lido.
        if ($destinatario === null) {
            app(Avisos::class)->citar($mensagem);
        }

        return $mensagem;
    }

    /** A pena `silencio` do D-44, enfim com dentes. */
    public function silencioVigente(User $user): ?Punishment
    {
        return Punishment::where('user_id', $user->id)
            ->where('kind', PunicaoSpecs::SILENCIO)
            ->vigente()
            ->orderByDesc('expires_at')
            ->first();
    }
}
