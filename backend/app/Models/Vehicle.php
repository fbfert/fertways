<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Vehicle extends Model
{
    protected $fillable = [
        'colony_id', 'type', 'plate', 'level', 'status', 'capacity',
        'origin_id', 'destination_type', 'destination_id', 'leg', 'trip_purpose', 'distance_slots',
        'departs_at', 'arrives_at', 'ready_at', 'cargo_json',
    ];

    protected $casts = [
        'level' => 'integer',
        'capacity' => 'integer',
        'distance_slots' => 'integer',
        'departs_at' => 'datetime',
        'arrives_at' => 'datetime',
        // Só a frota do governo o usa: quando o caminhão sai da linha de montagem (D-60).
        'ready_at' => 'datetime',
        'cargo_json' => 'array',
    ];

    /**
     * Capacidade por viagem (GDD §25.4): 1.000 unidades de qualquer recurso = 1 m³.
     * Furgão 6 m³ = 6.000 unidades. Caminhão de Carga 30 m³ = 30.000 unidades.
     */
    // As chaves são os mesmos slugs de building_specs, extraídos do GDD.
    public const CAPACIDADE = [
        'furgao_de_comercio' => 6_000,
        'caminhao_de_carga' => 30_000,
    ];

    public function colony(): BelongsTo
    {
        return $this->belongsTo(Colony::class);
    }

    /** GDD §25.5: veículo em rota fica indisponível até completar a viagem. */
    public function disponivel(): bool
    {
        return $this->status === 'ocioso';
    }
}
