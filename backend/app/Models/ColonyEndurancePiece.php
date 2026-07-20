<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uma peça da Endurance possuída por uma colônia (D-132). Binário: ou a linha existe, ou não. */
class ColonyEndurancePiece extends Model
{
    public $timestamps = false;

    protected $fillable = ['colony_id', 'peca_key', 'comprado_em'];

    protected $casts = ['comprado_em' => 'datetime'];

    public function colony(): BelongsTo
    {
        return $this->belongsTo(Colony::class);
    }
}
