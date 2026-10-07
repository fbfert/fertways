<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uma escavação numa seção da Endurance (D-249). A peça é reservada no início e revelada no fim. */
class EnduranceEscavacao extends Model
{
    protected $table = 'endurance_escavacoes';

    public const ESCAVANDO = 'escavando';

    public const CONCLUIDA = 'concluida';

    protected $fillable = [
        'colony_id', 'secao', 'endurance_item_id', 'status', 'custo_pago',
        'starts_at', 'finishes_at', 'concluida_em',
    ];

    protected $casts = [
        'custo_pago' => 'array',
        'starts_at' => 'datetime',
        'finishes_at' => 'datetime',
        'concluida_em' => 'datetime',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(EnduranceItem::class, 'endurance_item_id');
    }

    public function colony(): BelongsTo
    {
        return $this->belongsTo(Colony::class);
    }
}
