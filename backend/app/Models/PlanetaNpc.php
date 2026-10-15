<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Um dos cinco planetas NPC do §03 (D-262). Os dados são do GDD; abrir e o preço, do operador. */
class PlanetaNpc extends Model
{
    protected $table = 'planetas_npc';

    protected $fillable = ['nome', 'distancia_minutos', 'risco', 'exporta', 'importa', 'aberto', 'ordem'];

    protected $casts = [
        'distancia_minutos' => 'integer',
        'aberto' => 'boolean',
        'ordem' => 'integer',
    ];

    public function precos(): HasMany
    {
        return $this->hasMany(PlanetaNpcPreco::class, 'planeta_npc_id');
    }
}
