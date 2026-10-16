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
        // D-280: a escolta e o seguro.
        'risco_chance_bps', 'seguro_premio_micro', 'seguro_cobertura_micro', 'seguro_indenizacao_micro',
        'escolta_unit_ids', 'escolta_poder',
    ];

    protected $casts = [
        'venda' => 'array',
        'compra' => 'array',
        'aluguel_micro' => 'integer',
        'sinistro_bps' => 'integer',
        'imposto_importacao_micro' => 'integer',
        'imposto_venda_micro' => 'integer',
        'risco_chance_bps' => 'integer',
        'seguro_premio_micro' => 'integer',
        'seguro_cobertura_micro' => 'integer',
        'seguro_indenizacao_micro' => 'integer',
        'escolta_unit_ids' => 'array',
        'escolta_poder' => 'integer',
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
