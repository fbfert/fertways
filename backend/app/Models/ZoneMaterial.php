<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O **canteiro de obras** de uma zona neutra (docs/decisoes.md D-67).
 *
 * As obras da zona exigem **entrega física**: o material chega de veículo e fica aqui até que haja o
 * bastante para erguer alguma coisa. É estoque de CONSTRUÇÃO — não se confunde com o
 * `deposit_amount`, que é o minério que a zona extrai e que se leva para casa.
 *
 * A sobra fica: quem entrega 500 Metal Bruto e ergue uma Muralha de 400 deixa 100 no canteiro para a
 * próxima obra. Ninguém perde material por trazer demais.
 */
class ZoneMaterial extends Model
{
    protected $table = 'zone_materials';

    protected $fillable = ['zone_id', 'resource_type', 'amount'];

    protected $casts = ['amount' => 'integer'];

    public function zone(): BelongsTo
    {
        return $this->belongsTo(NeutralZone::class, 'zone_id');
    }
}
