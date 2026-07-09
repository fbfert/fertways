<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Colony extends Model
{
    use HasFactory;

    /** 50 Fert$ de saldo inicial (GDD, onboarding), em micro-Fert$. */
    public const SALDO_INICIAL_MICRO = 50_000_000;

    /** 1 Fert$ = 1.000.000 µF$. Preços do GDD têm 4 casas (Energia = 0,0033). */
    public const MICRO_POR_FERT = 1_000_000;

    protected $fillable = ['user_id', 'name', 'x', 'y', 'founded_at', 'milestone', 'fert_micro', 'last_tick_at'];

    protected $casts = [
        'x' => 'integer',
        'y' => 'integer',
        'founded_at' => 'datetime',
        'last_tick_at' => 'datetime',
        'fert_micro' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function buildings(): HasMany
    {
        return $this->hasMany(Building::class);
    }

    public function resources(): HasMany
    {
        return $this->hasMany(Resource::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }
}
