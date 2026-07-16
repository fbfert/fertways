<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bugs/Melhorias (D-95): o que um jogador manda para o Governo — bug, sugestão, dúvida.
 */
class Feedback extends Model
{
    public const TIPOS = ['bug', 'melhoria', 'duvida', 'outro'];

    protected $fillable = [
        'user_id', 'colony_id', 'email', 'colony_name', 'nickname',
        'tipo', 'assunto', 'mensagem', 'lida_at', 'resposta', 'respondida_at', 'feito_at',
    ];

    protected $casts = [
        'lida_at' => 'datetime',
        'respondida_at' => 'datetime',
        'feito_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function colony(): BelongsTo
    {
        return $this->belongsTo(Colony::class);
    }

    public function lida(): bool
    {
        return $this->lida_at !== null;
    }

    public function respondida(): bool
    {
        return $this->respondida_at !== null;
    }

    public function feita(): bool
    {
        return $this->feito_at !== null;
    }
}
