<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uma avaliação de 0 a 5 estrelas de um Acordo de Troca (GDD §8.4; D-287). */
class AvaliacaoDeComercio extends Model
{
    protected $table = 'avaliacoes_de_comercio';

    protected $fillable = [
        'trade_agreement_id', 'autor_user_id', 'avaliado_user_id', 'estrelas', 'comentario',
        'conta', 'suspeita', 'delta_aplicado', 'anulada_em', 'anulada_motivo',
    ];

    protected $casts = [
        'estrelas' => 'integer',
        'conta' => 'boolean',
        'suspeita' => 'boolean',
        'delta_aplicado' => 'integer',
        'anulada_em' => 'datetime',
    ];

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autor_user_id');
    }

    public function avaliado(): BelongsTo
    {
        return $this->belongsTo(User::class, 'avaliado_user_id');
    }

    public function acordo(): BelongsTo
    {
        return $this->belongsTo(TradeAgreement::class, 'trade_agreement_id');
    }

    /** As que valem para o perfil público: nem anuladas, nem suspeitas à espera de revisão. */
    public function scopeValidas(Builder $q): Builder
    {
        return $q->whereNull('anulada_em')->where('suspeita', false);
    }
}
