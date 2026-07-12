<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O vínculo entre uma coisa do jogo e uma imagem (docs/decisoes.md D-68).
 *
 * `entity_key` é texto, e não uma FK: as coisas do jogo vivem em tabelas diferentes (`building_specs`
 * para as construções) e algumas — as áreas e os slots da Capital — **não vivem em tabela nenhuma**,
 * são desenho (D-63).
 *
 * Sem vínculo, a construção volta ao **hexágono colorido**, que continua sendo o fallback. Nada
 * quebra por falta de imagem, e é por isso que se pode ir preenchendo aos poucos.
 */
class ImageBinding extends Model
{
    protected $table = 'image_bindings';

    protected $fillable = ['entity_key', 'media_asset_id'];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }
}
