<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Uma intervenção de preço da Secretaria de Finanças (§06). Ver a migration e D-35.
 *
 * Enquanto vigente (agora entre `starts_at` e `expires_at`), o Mercado rejeita ordens do recurso
 * fora de [floor_micro, ceil_micro]. Qualquer dos dois limites pode ser nulo (só teto, ou só piso).
 */
class PriceIntervention extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'resource_type', 'floor_micro', 'ceil_micro', 'reason', 'starts_at', 'expires_at',
    ];

    protected $casts = [
        'floor_micro' => 'integer',
        'ceil_micro' => 'integer',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    /** As que valem agora. */
    public function scopeVigentes(Builder $q): Builder
    {
        $agora = now();

        return $q->where('starts_at', '<=', $agora)->where('expires_at', '>', $agora);
    }

    /** A intervenção vigente de um recurso, ou null. A mais recente vence, se houver mais de uma. */
    public static function vigenteDe(string $recurso): ?self
    {
        return static::query()->vigentes()->where('resource_type', $recurso)->latest('id')->first();
    }
}
