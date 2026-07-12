<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vehicle extends Model
{
    /**
     * A sucata **arquiva**, não apaga (D-60). O veículo sai da frota e fica no registro do
     * Ministério: é o que impede a placa de ser reciclada e o que permite contar os sucateados,
     * que o §16 pede por período. Toda consulta de frota já os ignora sozinha.
     */
    use SoftDeletes;

    protected $fillable = [
        'colony_id', 'type', 'plate', 'level', 'status', 'capacity',
        'conservacao_bps', 'teto_conservacao_bps', 'manutencoes', 'uso_ativo_seg',
        'origin_id', 'destination_type', 'destination_id', 'leg', 'trip_purpose', 'distance_slots',
        'departs_at', 'arrives_at', 'ready_at', 'cargo_json',
    ];

    /**
     * Os padrões do estado de conservação, **em memória e não só no banco** (D-60).
     *
     * Sem isto, um `Vehicle::create()` devolve um modelo cujo `conservacao_bps` é `null` — o default
     * existe no schema, mas o objeto que acabou de voltar não o conhece até um `fresh()`. E aí um
     * caminhão novinho vale zero de teto de revenda e anda a 25% do piso, porque `(int) null` é `0`.
     * Foi exatamente isso que um teste pegou.
     */
    protected $attributes = [
        'conservacao_bps' => 10_000,
        'teto_conservacao_bps' => 10_000,
        'manutencoes' => 0,
        'uso_ativo_seg' => 0,
    ];

    protected $casts = [
        'level' => 'integer',
        'capacity' => 'integer',
        'conservacao_bps' => 'integer',
        'teto_conservacao_bps' => 'integer',
        'manutencoes' => 'integer',
        'uso_ativo_seg' => 'integer',
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
