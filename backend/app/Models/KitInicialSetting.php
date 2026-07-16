<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * O que o kit inicial dá além de recursos (D-92): o Fert$ e a frota. Linha única, mesmo padrão
 * de `TransportSetting`/`MilestoneSetting`.
 */
class KitInicialSetting extends Model
{
    protected $fillable = ['fert_micro', 'furgoes', 'caminhoes'];

    protected $casts = [
        'fert_micro' => 'integer',
        'furgoes' => 'integer',
        'caminhoes' => 'integer',
    ];

    /**
     * A linha única, criada com os padrões do schema se ainda não existir.
     *
     * ⚠️ Relê do banco depois de criar (a lição do `WarSetting`, D-70): o caminho da criação
     * devolve um modelo só com `id` e timestamps, sem os defaults que o banco aplicou.
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
