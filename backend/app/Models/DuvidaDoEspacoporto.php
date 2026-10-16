<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uma dúvida da fila do Atendente do Espaçoporto (D-270). */
class DuvidaDoEspacoporto extends Model
{
    protected $table = 'espacoporto_duvidas';

    protected $fillable = [
        'user_id', 'pergunta', 'resposta', 'atendente_user_id', 'respondida_em', 'encaminhada_em',
        'ajudou', 'avaliada_em',
    ];

    protected $casts = [
        'respondida_em' => 'datetime',
        'encaminhada_em' => 'datetime',
        'avaliada_em' => 'datetime',
        'ajudou' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function aberta(): bool
    {
        return $this->respondida_em === null && $this->encaminhada_em === null;
    }
}
