<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Uma linha da biografia de um item único (A2.9 / §11.1).
 *
 * ⚠️ **Append-only**, como o `ledger` e o `federation_ledger`: a história de um item não pode ser
 * editada depois, ou deixa de valer como história. O valor narrativo de um item único está em ele ter
 * um passado que ninguém pode reescrever — inclusive nós.
 */
class EnduranceItemTransfer extends Model
{
    public $timestamps = false;

    protected $fillable = ['instance_id', 'de_colony_id', 'para_colony_id', 'motivo', 'em'];

    protected $casts = ['em' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('endurance_item_transfers é append-only'));
        static::deleting(fn () => throw new RuntimeException('endurance_item_transfers é append-only'));
    }
}
