<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NeutralZone extends Model
{
    protected $fillable = ['coordinates', 'owner_colony_id', 'status', 'occupied_at', 'protected_until'];

    protected $casts = [
        'occupied_at' => 'datetime',
        'protected_until' => 'datetime',
    ];

    /** GDD (precedência, seção 0): "protegida por 8 dias completos a partir da primeira ocupação". */
    public const DIAS_DE_PROTECAO = 8;

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Colony::class, 'owner_colony_id');
    }
}
