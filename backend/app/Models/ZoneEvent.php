<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma mudança de posse na zona — ocupação, abandono, conquista (docs/decisoes.md D-86).
 *
 * Alimenta o Histórico da zona, ao lado do `Ledger` (financeiro) e do `Combat` (guerra). Não é
 * dinheiro nem recurso, por isso não é `Ledger`: é só um fato, com a hora em que aconteceu.
 */
class ZoneEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['zone_id', 'type', 'colony_id', 'meta', 'created_at'];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    public function zone(): BelongsTo
    {
        return $this->belongsTo(NeutralZone::class, 'zone_id');
    }

    public function colony(): BelongsTo
    {
        return $this->belongsTo(Colony::class);
    }
}
