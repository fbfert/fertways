<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Uma linha do fundo de UMA federação (docs/decisoes.md D-114) — análoga a `TreasuryHolding`, mas
 * não-singleton: há N federações, então PK sintética + `unique(federation_id, resource_type)` em
 * vez de `resource_type` como chave primária.
 *
 * Só recursos do catálogo — sem Fert$: `Vehicle.cargo_json` nunca carrega Fert$, e a entrada no
 * fundo é sempre por entrega física de veículo (decisão do usuário, D-114).
 */
class FederationHolding extends Model
{
    protected $fillable = ['federation_id', 'resource_type', 'amount'];

    protected $casts = ['amount' => 'integer'];
}
