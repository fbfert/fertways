<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuildQueue extends Model
{
    protected $table = 'build_queue';

    protected $fillable = [
        'colony_id', 'building_id', 'target_level', 'position',
        'quoted_cost_json', 'subsidized', 'enqueued_at', 'starts_at', 'finishes_at', 'status',
    ];

    protected $casts = [
        'target_level' => 'integer',
        'position' => 'integer',
        'quoted_cost_json' => 'array',
        'subsidized' => 'boolean',
        'enqueued_at' => 'datetime',
        'starts_at' => 'datetime',
        'finishes_at' => 'datetime',
    ];

    /**
     * "Fila dupla gratuita e automática nos primeiros 5 dias completos de conta. A partir do
     * 6º dia, a colônia volta à fila única padrão." (GDD, onboarding)
     *
     * Deriva de users.created_at a cada consulta. Persistir o limite criaria um estado que
     * envelhece errado: a conta faz 6 dias e a coluna continuaria dizendo 2.
     */
    public static function vagasDe(User $user): int
    {
        return $user->created_at->addDays(5)->isFuture() ? 2 : 1;
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function colony(): BelongsTo
    {
        return $this->belongsTo(Colony::class);
    }

    public function scopeAtivos($q)
    {
        return $q->whereIn('status', ['queued', 'building']);
    }
}
