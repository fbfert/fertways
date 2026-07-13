<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Um molde de missão do catálogo (§06; D-78). O baralho de onde as diárias são sorteadas. */
class MissionTemplate extends Model
{
    protected $fillable = [
        'chave', 'categoria', 'titulo', 'descricao', 'acao', 'meta',
        'recompensa_fert_micro', 'recompensa_xp', 'recompensa_recursos', 'ativa',
    ];

    protected $casts = [
        'meta' => 'integer',
        'recompensa_fert_micro' => 'integer',
        'recompensa_xp' => 'integer',
        'recompensa_recursos' => 'array',
        'ativa' => 'boolean',
    ];
}
