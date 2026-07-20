<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Um Cargo Público (§14.2, D-130) — o molde generalizado dos 4 que não são o Conciliador. */
class CivicPost extends Model
{
    protected $fillable = ['user_id', 'kind', 'desde', 'suspenso_em', 'salario_pago_em'];

    protected $casts = [
        'desde' => 'datetime',
        'suspenso_em' => 'datetime',
        'salario_pago_em' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ativo(): bool
    {
        return $this->suspenso_em === null;
    }
}
