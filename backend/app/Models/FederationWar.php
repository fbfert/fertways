<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uma guerra entre duas federações (A2.10). */
class FederationWar extends Model
{
    protected $fillable = [
        'declarante_id', 'alvo_id', 'comeca_em', 'termina_em',
        'status', 'encerrada_em', 'motivo_fim', 'declarada_por_colony_id',
    ];

    protected $casts = [
        'comeca_em' => 'datetime',
        'termina_em' => 'datetime',
        'encerrada_em' => 'datetime',
    ];

    /**
     * As guerras entre duas federações, **em qualquer sentido**.
     *
     * ⚠️ Quem declarou importa para a história, não para a pergunta "estas duas estão em guerra?".
     * Sem este escopo, cada chamador teria de lembrar de consultar os dois sentidos — e o primeiro
     * que esquecesse abriria uma segunda guerra sobre a primeira.
     */
    public function scopeEntre(Builder $q, int $a, int $b): Builder
    {
        return $q->where(fn ($x) => $x
            ->where(fn ($y) => $y->where('declarante_id', $a)->where('alvo_id', $b))
            ->orWhere(fn ($y) => $y->where('declarante_id', $b)->where('alvo_id', $a)));
    }

    public function declarante(): BelongsTo
    {
        return $this->belongsTo(Federation::class, 'declarante_id');
    }

    public function alvo(): BelongsTo
    {
        return $this->belongsTo(Federation::class, 'alvo_id');
    }
}
