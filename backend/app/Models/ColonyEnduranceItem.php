<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quantas unidades de um item da Endurance uma colônia possui (D-135). Substitui
 * `ColonyEndurancePiece` — a posse agora tem quantidade, porque um item não-único pode ser
 * comprado mais de uma vez pela mesma colônia (efeitos empilham por unidade, ver
 * `EfeitosDaEndurance`).
 */
class ColonyEnduranceItem extends Model
{
    protected $fillable = ['colony_id', 'endurance_item_id', 'quantidade'];

    protected $casts = ['quantidade' => 'integer'];

    public function colony(): BelongsTo
    {
        return $this->belongsTo(Colony::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(EnduranceItem::class, 'endurance_item_id');
    }
}
