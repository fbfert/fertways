<?php

namespace App\Models;

use App\Domain\Media\Biblioteca;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma imagem da biblioteca (docs/decisoes.md D-68).
 *
 * O banco guarda o **caminho**; o arquivo mora em `/home/fertways/media`, fora do repositório e fora
 * da árvore de deploy. Ver a migration para o porquê.
 */
class MediaAsset extends Model
{
    protected $table = 'media_assets';

    protected $fillable = ['category', 'filename', 'filename_large', 'admin_id'];

    /** Os vínculos que a usam. Apagar a imagem os desfaz (cascade), e o prédio volta ao hexágono. */
    public function bindings(): HasMany
    {
        return $this->hasMany(ImageBinding::class);
    }

    public function url(bool $grande = false): string
    {
        return Biblioteca::url($this, $grande);
    }

    /** Tem versão de 1024? As do import inicial têm; um upload avulso, não necessariamente. */
    public function temGrande(): bool
    {
        return $this->filename_large !== null;
    }
}
