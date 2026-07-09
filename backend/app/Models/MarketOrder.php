<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ordem no livro de ofertas do Mercado Central (GDD §07, "Mercado Central — canal oficial").
 *
 * `qty` é a quantidade **restante**, não a original: cada execução parcial a reduz, junto com o
 * escrow correspondente. O histórico do que foi executado vive no ledger, que é append-only.
 *
 * O escrow é o que separa o Mercado do comércio informal (§07): aqui o sistema "assegura entrega
 * financeira e reserva do lote"; lá o risco do calote é real e deliberado (§26.5).
 */
class MarketOrder extends Model
{
    protected $fillable = [
        'colony_id', 'resource_type', 'side', 'price_micro', 'qty',
        'escrow_resource_qty', 'escrow_fert_micro', 'status',
    ];

    protected $casts = [
        'price_micro' => 'integer',
        'qty' => 'integer',
        'escrow_resource_qty' => 'integer',
        'escrow_fert_micro' => 'integer',
    ];

    public function colony(): BelongsTo
    {
        return $this->belongsTo(Colony::class);
    }

    public function aberta(): bool
    {
        return in_array($this->status, ['aberta', 'parcial'], true);
    }
}
