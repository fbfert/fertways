<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uma unidade que morreu em combate, enterrada na zona onde caiu (D-290). */
class RoboEnterrado extends Model
{
    protected $table = 'robos_enterrados';

    public $timestamps = false;

    protected $fillable = ['zone_id', 'combat_id', 'colony_id', 'type', 'level', 'morta_em'];

    protected $casts = [
        'level' => 'integer',
        'morta_em' => 'datetime',
    ];

    public function colony(): BelongsTo
    {
        return $this->belongsTo(Colony::class);
    }
}
