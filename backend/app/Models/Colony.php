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

    protected $fillable = [
        'user_id', 'name', 'x', 'y', 'founded_at', 'milestone', 'fert_micro', 'last_tick_at',
        'siderurgica_lote_remainder',
    ];

    /**
     * ⚠️ O default tem de estar AQUI também, não só no banco — `create()` não relê o que o banco
     * aplicou, e um `Colony::create()` devolveria `siderurgica_lote_remainder` nulo. É a mesma
     * pegadinha que já mordeu `Vehicle`, `WarSetting` e `NeutralZone` (ver o comentário de lá).
     */
    protected $attributes = [
        'siderurgica_lote_remainder' => 0,
    ];

    protected $casts = [
        'x' => 'integer',
        'y' => 'integer',
        'founded_at' => 'datetime',
        'last_tick_at' => 'datetime',
        'fert_micro' => 'integer',
        'siderurgica_lote_remainder' => 'integer',
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
