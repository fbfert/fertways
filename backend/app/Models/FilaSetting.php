<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Quantos itens cabem na fila de construção — da colônia e da zona neutra (docs/decisoes.md D-111).
 * Do operador, não do código. Linha única, mesmo padrão de `TransportSetting`/`WarSetting`.
 */
class FilaSetting extends Model
{
    protected $fillable = ['colonia_vagas_novato', 'colonia_vagas_padrao', 'zona_vagas'];

    protected $casts = [
        'colonia_vagas_novato' => 'integer',
        'colonia_vagas_padrao' => 'integer',
        'zona_vagas' => 'integer',
    ];

    /**
     * A linha única, criada com os padrões do schema se ainda não existir.
     *
     * ⚠️ **Relê do banco depois de criar** (a lição do `WarSetting`, D-70): `create([])` devolve um
     * modelo só com `id` e timestamps, sem os defaults que o banco aplicou.
     */
    public static function singleton(): self
    {
        if ($existente = static::first()) {
            return $existente;
        }

        static::create([]);

        return static::firstOrFail();
    }
}
