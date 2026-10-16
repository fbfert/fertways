<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um contrato de rota interplanetário (D-262): o aluguel do Cargueiro do Governo para uma ida a um
 * planeta NPC. *"Viagens interplanetárias são contratos de rota e não podem paralisar o ciclo básico
 * do jogador"* (v3.2 §11) — a colônia continua jogando enquanto o cargueiro voa.
 */
class ContratoDeRota extends Model
{
    public const EM_VIAGEM = 'em_viagem';

    public const CONCLUIDO = 'concluido';

    protected $table = 'contratos_de_rota';

    protected $fillable = [
        'colony_id', 'planeta_npc_id', 'status', 'venda', 'compra', 'aluguel_micro',
        'parte_em', 'chega_em', 'concluido_em',
        'sinistro_bps', 'imposto_importacao_micro', 'imposto_venda_micro',
    ];

    protected $casts = [
        'venda' => 'array',
        'compra' => 'array',
        'aluguel_micro' => 'integer',
        'sinistro_bps' => 'integer',
        'imposto_importacao_micro' => 'integer',
        'imposto_venda_micro' => 'integer',
        'parte_em' => 'datetime',
        'chega_em' => 'datetime',
        'concluido_em' => 'datetime',
    ];

    public function planeta(): BelongsTo
    {
        return $this->belongsTo(PlanetaNpc::class, 'planeta_npc_id');
    }

    public function colony(): BelongsTo
    {
        return $this->belongsTo(Colony::class);
    }
}
