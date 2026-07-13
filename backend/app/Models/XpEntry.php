<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Uma linha do ledger de XP (D-75). Append-only: XP não nasce sem história.
 *
 * O mesmo contrato do `AuditEntry`: linha de ledger não se atualiza nem se apaga — o passado de
 * uma colônia é o que sustenta o marco dela, e um marco cujo lastro pode ser editado não é marco.
 * (A exceção única é o recálculo retroativo do `fertways:marco`, que apaga e reescreve SÓ as
 * linhas `retro:*` — ver o comando.)
 */
class XpEntry extends Model
{
    public $timestamps = false;

    protected $fillable = ['colony_id', 'acao', 'xp', 'ref', 'created_at'];

    protected $casts = ['xp' => 'integer', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('Linha de XP não se atualiza: o ledger é append-only (D-75).');
        });
    }
}
