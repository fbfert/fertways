<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma denúncia no Ministério das Reputações (GDD §9.2, §26.8).
 *
 * Os estados, e como se sai de cada um:
 *
 *   triagem   → rejeitado   (sem evidência mínima do §26.8)
 *             → na_equipe   (caso grave, §9.2)
 *             → atribuido   (caso simples, a um conciliador sem impedimento)
 *   atribuido → decidido    (o conciliador julgou dentro das 48 h)
 *             → atribuido   (48 h venceram: reatribuído a outro, §26.8)
 *             → na_equipe   (não há outro conciliador disponível, §9.3)
 *   na_equipe → decidido    (o operador julgou, por artisan — a "equipe" do D-44)
 *   decidido  → apelado     (qualquer das partes apela dentro de 48 h)
 *             → encerrado   (a janela fechou; o bônus do §26.7 é pago)
 *   apelado   → revertido   (a equipe reverteu: punição estornada, reversão contada)
 *             → encerrado   (a equipe manteve)
 */
class Report extends Model
{
    protected $fillable = [
        'reporter_colony_id', 'accused_colony_id', 'violation', 'texto',
        'evidence_type', 'trade_agreement_id', 'status', 'decision', 'grave',
        'conciliator_user_id', 'assigned_at', 'deadline_at', 'decided_at',
        'appeal_until', 'bonus_paid',
    ];

    protected $casts = [
        'grave' => 'boolean',
        'bonus_paid' => 'boolean',
        'assigned_at' => 'datetime',
        'deadline_at' => 'datetime',
        'decided_at' => 'datetime',
        'appeal_until' => 'datetime',
    ];

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(Colony::class, 'reporter_colony_id');
    }

    public function accused(): BelongsTo
    {
        return $this->belongsTo(Colony::class, 'accused_colony_id');
    }

    public function conciliator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'conciliator_user_id');
    }

    public function punishments(): HasMany
    {
        return $this->hasMany(Punishment::class);
    }

    public function envolve(int $colonyId): bool
    {
        return $this->reporter_colony_id === $colonyId || $this->accused_colony_id === $colonyId;
    }
}
