<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * O extrato do Governo (D-96): espelha `Ledger` (append-only, mesma invariante), mas sem
 * `colony_id` — o Tesouro é um só, como `treasury_holdings` já modela.
 */
class TreasuryLedger extends Model
{
    protected $table = 'treasury_ledger';

    public $timestamps = false;

    protected $fillable = ['type', 'amount', 'resource_type', 'ref', 'created_at'];

    protected $casts = ['amount' => 'integer', 'created_at' => 'datetime'];

    public const TIPOS = [
        'credito',      // qualquer entrada de Fert$/recurso no Tesouro
        'debito',       // qualquer saída de Fert$/recurso do Tesouro, fora distribuição
        'distribuicao', // saída por ato administrativo: o operador manda para uma colônia (D-57)
    ];

    protected static function booted(): void
    {
        static::creating(function (self $l) {
            if (! in_array($l->type, self::TIPOS, true)) {
                throw new RuntimeException("Tipo de lançamento inválido: {$l->type}");
            }
        });

        static::updating(fn () => throw new RuntimeException('treasury_ledger é append-only'));
        static::deleting(fn () => throw new RuntimeException('treasury_ledger é append-only'));
    }
}
