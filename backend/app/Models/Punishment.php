<?php

namespace App\Models;

use App\Domain\Ministry\PunicaoSpecs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma punição do §9.4 aplicada a um colono, com o índice do §26.2 que ela deduziu.
 *
 * Reverter em apelação **estorna** (`revoked_at`), nunca apaga: o §26.8 quer processo auditável, e
 * uma reversão silenciosa apagaria a prova de que o conciliador errou — que é justamente o que
 * alimenta o contador de reversões do §26.7.
 */
class Punishment extends Model
{
    protected $fillable = [
        'report_id', 'user_id', 'kind', 'index_name', 'points', 'expires_at', 'applied_at', 'revoked_at',
    ];

    protected $casts = [
        'points' => 'integer',
        'expires_at' => 'datetime',
        'applied_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    /** Punições que ainda mordem: não estornadas, e dentro do prazo (ou sem prazo). */
    public function scopeVigente(Builder $q): Builder
    {
        return $q->whereNull('revoked_at')
            ->where(fn (Builder $s) => $s->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * §9.4: "Jogador não pode enviar recursos por X dias." É a única punição de prazo que morde
     * hoje — o silêncio precisa de chat, e o bloqueio de leilões precisa de leilões (D-44).
     */
    public static function restricaoComercialAtiva(int $userId): bool
    {
        return self::query()
            ->vigente()
            ->where('user_id', $userId)
            ->where('kind', PunicaoSpecs::RESTRICAO_COMERCIAL)
            ->whereNotNull('expires_at')
            ->exists();
    }
}
