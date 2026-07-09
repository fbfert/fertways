<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Resource extends Model
{
    protected $fillable = ['colony_id', 'resource_type', 'amount', 'storage_cap'];

    protected $casts = ['amount' => 'integer', 'storage_cap' => 'integer'];

    /**
     * Recursos que uma colônia do slot principal pode estocar no MVP.
     * Primários (§22.2) + industriais/secundários produzidos ou consumidos localmente.
     * Os 8 minerais eletrônicos ficam de fora: na Temporada 1 o jogador não os extrai,
     * compra-os no Mercado Central (GDD, "Minerais eletrônicos (governo inicial)").
     */
    public const DA_COLONIA = [
        'oxigenio',
        'agua',
        'biomassa',
        'energia',
        'metal_bruto',
        'ligas_metalicas',
        'compostos_quimicos',
        'componentes_eletronicos',
        'biocombustivel',
    ];

    public function colony(): BelongsTo
    {
        return $this->belongsTo(Colony::class);
    }
}
