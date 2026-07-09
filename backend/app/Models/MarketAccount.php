<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Saldo do colono no Mercado Central (GDD §25.8).
 *
 * Só o tick credita (na entrega física) e só o despacho debita (ao reservar uma retirada).
 * Nada aqui é produzido nem consumido: o saldo entra por veículo e sai por veículo.
 */
class MarketAccount extends Model
{
    protected $fillable = ['colony_id', 'resource_type', 'amount'];

    protected $casts = ['amount' => 'integer'];

    public function colony(): BelongsTo
    {
        return $this->belongsTo(Colony::class);
    }
}
