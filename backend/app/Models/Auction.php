<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um leilão (D-129) — um lote único, tudo ou nada, sem arremate parcial.
 *
 * `lance_atual_micro`/`lance_colony_id` guardam só o lance vigente: cada lance superado já foi
 * devolvido (ledger `estorno`) no instante em que perdeu, então não há tabela de histórico — o
 * ledger é o histórico.
 */
class Auction extends Model
{
    protected $fillable = [
        'colony_id', 'resource_type', 'qty', 'lance_minimo_micro',
        'lance_atual_micro', 'lance_colony_id', 'status', 'deadline_at',
    ];

    protected $casts = [
        'qty' => 'integer',
        'lance_minimo_micro' => 'integer',
        'lance_atual_micro' => 'integer',
        'deadline_at' => 'datetime',
    ];

    public function colony(): BelongsTo
    {
        return $this->belongsTo(Colony::class);
    }

    public function lanceColony(): BelongsTo
    {
        return $this->belongsTo(Colony::class, 'lance_colony_id');
    }

    public function aberto(): bool
    {
        return $this->status === 'aberto';
    }
}
