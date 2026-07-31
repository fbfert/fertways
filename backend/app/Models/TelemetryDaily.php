<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O retrato diário de fluxo por colônia e recurso (A2.0.1.1).
 *
 * É a camada do **fluxo contínuo** — produção, consumo e saldo —, que não vira evento discreto: o
 * tick roda a cada minuto, e instrumentar produção tick a tick daria mais de mil linhas por colônia
 * por dia sem responder nada que este retrato não responda melhor.
 *
 * Ao contrário de `TelemetryEvent`, este **não** é append-only, e a diferença é de natureza: um
 * evento é um fato ocorrido num instante, e reescrevê-lo seria falsificar história. Uma linha daqui
 * é um *cálculo derivado* do ledger — se o agregador rodar de novo sobre o mesmo dia, ele deve
 * chegar ao mesmo número e sobrescrever sem cerimônia. É o que a chave única
 * (colônia, dia, recurso) garante, e é o que torna o comando seguro de repetir.
 *
 * `resource_type` nulo significa **Fert$ em micro**, exatamente como no ledger.
 */
class TelemetryDaily extends Model
{
    protected $table = 'telemetry_daily';

    public $timestamps = false;

    protected $fillable = ['colony_id', 'dia', 'resource_type', 'produzido', 'consumido', 'saldo_fim'];

    protected $casts = [
        'dia' => 'date',
        'produzido' => 'integer',
        'consumido' => 'integer',
        'saldo_fim' => 'integer',
    ];

    public function colony(): BelongsTo
    {
        return $this->belongsTo(Colony::class);
    }
}
