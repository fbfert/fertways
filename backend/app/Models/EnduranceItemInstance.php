<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A instância de um item ÚNICO da Endurance (A2.9 / §11.1).
 *
 * ⚠️ **Só o único tem instância.** Comum e raro continuam fungíveis em `colony_endurance_items` —
 * ver o docblock da migration `itens_unicos_da_endurance`.
 */
class EnduranceItemInstance extends Model
{
    protected $fillable = [
        'endurance_item_id', 'selo', 'descobridor_colony_id', 'descoberto_em', 'colony_id',
    ];

    protected $casts = ['descoberto_em' => 'datetime'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(EnduranceItem::class, 'endurance_item_id');
    }

    public function dono(): BelongsTo
    {
        return $this->belongsTo(Colony::class, 'colony_id');
    }

    public function descobridor(): BelongsTo
    {
        return $this->belongsTo(Colony::class, 'descobridor_colony_id');
    }

    public function historico(): HasMany
    {
        return $this->hasMany(EnduranceItemTransfer::class, 'instance_id')->orderBy('em');
    }
}
