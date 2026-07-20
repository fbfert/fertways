<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uma sinalização do Fiscal de Mercado ou do Auxiliar de Tesouro (§14.2, D-130). */
class CivicFlag extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'kind', 'motivo', 'confirmado_em', 'created_at'];

    protected $casts = [
        'confirmado_em' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function confirmada(): bool
    {
        return $this->confirmado_em !== null;
    }
}
