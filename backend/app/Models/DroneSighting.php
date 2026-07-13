<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A foto datada que um Drone da colônia tirou de uma zona neutra (D-74).
 *
 * Uma linha por (colônia, zona), sobrescrita a cada passagem: intel velha não vira histórico,
 * vira engano. O `seen_at` é o que faz a tela poder dizer "visto há 3 h" — **informação que
 * envelhece é informação honesta**; o número pode estar errado hoje, e a tela diz desde quando.
 */
class DroneSighting extends Model
{
    protected $fillable = ['colony_id', 'zone_id', 'garrison', 'deposit_amount', 'seen_at'];

    protected $casts = [
        'garrison' => 'integer',
        'deposit_amount' => 'integer',
        'seen_at' => 'datetime',
    ];

    public function zone(): BelongsTo
    {
        return $this->belongsTo(NeutralZone::class, 'zone_id');
    }
}
