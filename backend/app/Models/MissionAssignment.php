<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uma missão na mão de uma colônia (§06; D-78). */
class MissionAssignment extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'colony_id', 'template_id', 'categoria', 'acao', 'progresso', 'meta',
        'status', 'expires_at', 'concluded_at', 'created_at',
    ];

    protected $casts = [
        'progresso' => 'integer',
        'meta' => 'integer',
        'expires_at' => 'datetime',
        'concluded_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(MissionTemplate::class, 'template_id');
    }

    public function scopeAtiva(Builder $q): Builder
    {
        return $q->where('status', 'ativa')
            ->where(fn (Builder $s) => $s->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
