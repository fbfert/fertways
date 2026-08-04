<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma proposta de fim antecipado de guerra: capitulação ou tratado de paz (A2.10, decisões 8 e 9).
 *
 * Ver o docblock da migration `capitulacao_e_tratados` para o motivo de as duas dividirem a tabela.
 */
class FederationWarProposal extends Model
{
    public const CAPITULACAO = 'capitulacao';

    public const TRATADO = 'tratado';

    /**
     * ⚠️ `preco_tipo`, `preco_zone_id` e `preco_fert_micro` estão aqui porque quem os grava é o
     * **aceite**, por `update()`. Deixá-los de fora faria o preço da capitulação ser descartado em
     * silêncio — o espólio mudaria de mãos e o registro diria `null`, que é o pior dos dois mundos:
     * o efeito sem a prova. Já aconteceu três vezes nesta fase (`max_aliadas`, `operadores`,
     * `fert_micro`), sempre do mesmo jeito.
     */
    protected $fillable = [
        'war_id', 'tipo', 'proponente_federation_id', 'proposta_por_colony_id',
        'status', 'respondida_por_colony_id', 'respondida_em',
        'preco_tipo', 'preco_zone_id', 'preco_fert_micro',
    ];

    protected $casts = [
        'respondida_em' => 'datetime',
        'preco_fert_micro' => 'integer',
    ];

    public function guerra(): BelongsTo
    {
        return $this->belongsTo(FederationWar::class, 'war_id');
    }

    public function proponente(): BelongsTo
    {
        return $this->belongsTo(Federation::class, 'proponente_federation_id');
    }
}
