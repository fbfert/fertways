<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um anúncio no mercado de usados (D-60, fatia 3 — GDD §16.4: "veículos podem ser vendidos a outros
 * colonos a qualquer momento").
 *
 * **Com escrow do Ministério**, e isso contraria de propósito a regra dos dois estoques do D-58 (o
 * que está na colônia seria promessa, com calote possível). A razão é que **o Ministério é o
 * cartório**: é ele que emite a placa (§16.3), então é ele que fecha a transferência. Os Fert$ do
 * comprador ficam retidos em `escrow_micro`; o veículo dirige-se até ele; **a placa muda de dono na
 * chegada**, e só então o vendedor recebe. Ver o aditivo 15 do D-60.
 *
 * Os quatro estados:
 *
 *      aberto      anunciado, à espera de comprador
 *      em_transito comprado e pago; o veículo está a caminho da colônia do comprador
 *      concluido   chegou; a placa mudou de dono e o vendedor recebeu
 *      cancelado   o vendedor desistiu, ou sucateou o veículo
 */
class VehicleListing extends Model
{
    protected $fillable = [
        'vehicle_id', 'seller_colony_id', 'buyer_colony_id', 'price_micro', 'escrow_micro', 'status',
    ];

    protected $casts = [
        'price_micro' => 'integer',
        'escrow_micro' => 'integer',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(Colony::class, 'seller_colony_id');
    }

    public function comprador(): BelongsTo
    {
        return $this->belongsTo(Colony::class, 'buyer_colony_id');
    }
}
