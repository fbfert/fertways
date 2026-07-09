<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo de recursos, semeado do GDD (§22.2, §22.3, §22.4) por tools/extract_gdd_specs.py.
 * Imutável em runtime.
 */
class ResourceType extends Model
{
    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = [
        'tax_bps' => 'integer',
        'preco_base_micro' => 'integer',
        'preco_base_derivado' => 'boolean',
        'producao_max_hora' => 'integer',
    ];

    /** Alíquota sobre o volume, em basis points (§8.3): 3% / 2% / 1%. */
    public function aliquota(): int
    {
        return $this->tax_bps;
    }
}
