<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Resource extends Model
{
    protected $fillable = ['colony_id', 'resource_type', 'amount', 'storage_cap'];

    protected $casts = ['amount' => 'integer', 'storage_cap' => 'integer'];

    /**
     * A colônia pode estocar qualquer recurso do catálogo.
     *
     * A primeira versão listava só primários e industriais, o que tornava a Oficina
     * inconstruível: ela custa 1 de Ferro Vermelho (raro) já no nível 1. Oito das onze
     * construções de progressão exigem raros no nível 1, e os 8 minerais eletrônicos são
     * insumo dos Componentes Eletrônicos. Qualquer recorte aqui é arbitrário e quebra
     * alguma cadeia. Ver docs/decisoes.md D-16.
     */
    public static function daColonia(): array
    {
        return ResourceType::orderBy('code')->pluck('code')->all();
    }

    public function colony(): BelongsTo
    {
        return $this->belongsTo(Colony::class);
    }
}
