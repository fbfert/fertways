<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma obra em curso na zona neutra (docs/decisoes.md D-67).
 *
 * **Uma por vez.** A colônia tem fila (D-13) porque tem 21 slots e um colono impaciente; a zona tem
 * um canteiro só, e o material dele veio de caminhão. Enfileirar obras aqui prometeria material que
 * ainda não chegou.
 */
class ZoneBuild extends Model
{
    protected $table = 'zone_build_queue';

    protected $fillable = ['zone_id', 'structure', 'target_level', 'finishes_at'];

    protected $casts = [
        'target_level' => 'integer',
        'finishes_at' => 'datetime',
    ];

    public function zone(): BelongsTo
    {
        return $this->belongsTo(NeutralZone::class, 'zone_id');
    }
}
