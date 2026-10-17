<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A credencial comercial que a Embaixada Interplanetária emite, por colônia e planeta (D-289). */
class CredencialComercial extends Model
{
    protected $table = 'credenciais_comerciais';

    protected $fillable = ['colony_id', 'planeta_npc_id', 'valida_ate', 'preco_pago_micro', 'desconto_bps'];

    protected $casts = [
        'valida_ate' => 'datetime',
        'preco_pago_micro' => 'integer',
        'desconto_bps' => 'integer',
    ];

    public function planeta(): BelongsTo
    {
        return $this->belongsTo(PlanetaNpc::class, 'planeta_npc_id');
    }
}
