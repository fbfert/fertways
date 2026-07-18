<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * O extrato do fundo de uma federação (docs/decisoes.md D-114) — espelha `TreasuryLedger`
 * (append-only, mesma invariante), mas com `federation_id` e `colony_id` (nulo em crédito de
 * dissolução, onde o saldo remanescente cai no Tesouro sem colônia nenhuma envolvida).
 */
class FederationLedger extends Model
{
    protected $table = 'federation_ledger';

    public $timestamps = false;

    protected $fillable = ['federation_id', 'colony_id', 'type', 'amount', 'resource_type', 'ref', 'created_at'];

    protected $casts = ['amount' => 'integer', 'created_at' => 'datetime'];

    public const TIPOS = [
        'credito',    // entrega física de um membro
        'saque',      // Líder/Intendente retiram para a própria colônia
        'dissolucao', // saída final, quando a federação dissolve (o saldo vai para o Tesouro)
    ];

    protected static function booted(): void
    {
        static::creating(function (self $l) {
            if (! in_array($l->type, self::TIPOS, true)) {
                throw new RuntimeException("Tipo de lançamento inválido: {$l->type}");
            }
        });

        static::updating(fn () => throw new RuntimeException('federation_ledger é append-only'));
        static::deleting(fn () => throw new RuntimeException('federation_ledger é append-only'));
    }
}
