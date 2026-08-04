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
        'capitulacao', // espólio de guerra: sai do fundo de quem se rendeu, entra no do vencedor
        /*
         * ⚠️ `debito` já ESTAVA no banco antes de estar nesta lista: o `DeclararGuerra` (D-193)
         * grava por `DB::table()->insert()`, que passa por fora do `creating` e portanto por fora
         * desta validação. Acrescentado aqui para que a lista descreva o que existe — e a inserção
         * passou a ser pelo modelo, para que a guarda volte a valer (D-206).
         */
        'debito',
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
