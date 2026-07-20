<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um efeito de um item da Endurance (D-135) — um item pode ter vários. `tipo_efeito` é vocabulário
 * fechado: ver `App\Domain\Endurance\EfeitosDaEndurance::TIPOS` para os valores válidos e onde cada
 * um morde no motor do jogo.
 */
class EnduranceItemEffect extends Model
{
    protected $fillable = ['endurance_item_id', 'tipo_efeito', 'alvo', 'valor_bps'];

    protected $casts = ['valor_bps' => 'integer'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(EnduranceItem::class, 'endurance_item_id');
    }
}
