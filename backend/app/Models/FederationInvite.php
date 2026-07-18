<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um convite (Líder/Diplomata chama uma colônia) ou um pedido (uma colônia sem federação pede
 * entrada) — mesma tabela, `kind` distingue (docs/decisoes.md D-114).
 *
 * Sem unique de schema para "um pendente por par": a checagem vive em código sob `lockForUpdate()`,
 * como o resto do domínio já faz (`ConstruirNaZona`, `DespacharVeiculo::resolverAcordo`).
 */
class FederationInvite extends Model
{
    public const CONVITE = 'convite';

    public const PEDIDO = 'pedido';

    public const PENDENTE = 'pendente';

    public const ACEITO = 'aceito';

    public const RECUSADO = 'recusado';

    public const CANCELADO = 'cancelado';

    protected $fillable = [
        'federation_id', 'colony_id', 'kind', 'status', 'created_by_colony_id', 'decided_at',
    ];

    protected $casts = ['decided_at' => 'datetime'];

    public function federation(): BelongsTo
    {
        return $this->belongsTo(Federation::class);
    }

    public function colony(): BelongsTo
    {
        return $this->belongsTo(Colony::class);
    }

    public function pendente(): bool
    {
        return $this->status === self::PENDENTE;
    }
}
