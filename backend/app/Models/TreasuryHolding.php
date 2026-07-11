<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Uma linha do saldo do Ministério do Tesouro (D-57): um recurso (unidades) ou Fert$ (micro).
 * Ver a migration e App\Domain\Treasury\Tesouro.
 */
class TreasuryHolding extends Model
{
    protected $table = 'treasury_holdings';

    protected $primaryKey = 'resource_type';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['resource_type', 'amount'];

    protected $casts = ['amount' => 'integer'];
}
