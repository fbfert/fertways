<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * O preço de um recurso num planeta NPC (D-262), em micro-Fert$ por unidade.
 *
 * `compra_micro` é o que o planeta PAGA ao colono; `venda_micro`, o que ele COBRA. Nulo é "não
 * negocia isto" — e é o estado de partida de tudo, porque nenhum documento publica esses preços.
 */
class PlanetaNpcPreco extends Model
{
    protected $table = 'planeta_npc_precos';

    protected $fillable = ['planeta_npc_id', 'resource_type', 'compra_micro', 'venda_micro'];

    protected $casts = [
        'compra_micro' => 'integer',
        'venda_micro' => 'integer',
    ];
}
