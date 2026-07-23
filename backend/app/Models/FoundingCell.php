<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Uma célula de periferia liberada para fundação pelo admin (docs/decisoes.md D-147).
 *
 * Sem geometria computada nenhuma aqui — ao contrário de `Domain\Logistics\ZonasNeutras`, que
 * deriva os distritos por fórmula, esta lista É a decisão do admin, não uma regra derivável.
 */
class FoundingCell extends Model
{
    protected $fillable = ['x', 'y'];

    protected $casts = [
        'x' => 'integer',
        'y' => 'integer',
    ];
}
