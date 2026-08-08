<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma cesta de evento que já chegou a uma colônia (D-232).
 *
 * A linha existe para responder **a quem** — não "já entregou?". O evento dura semanas e o mundo
 * ganha colônias no meio; a chave única `(game_event_id, colony_id)` é o que faz o entregador rodar
 * a cada 5 minutos sem entregar duas vezes, e ainda assim alcançar quem fundou no dia 12.
 *
 * `cesta` guarda o que foi entregue **congelado**: a `recompensas` do evento pode ser editada
 * depois, e sem esta cópia o histórico passaria a mentir sobre o que a colônia recebeu.
 */
class GameEventEntrega extends Model
{
    protected $table = 'game_event_entregas';

    public $timestamps = false;

    protected $fillable = ['game_event_id', 'colony_id', 'entregue_em', 'cesta'];

    protected $casts = [
        'entregue_em' => 'datetime',
        'cesta' => 'array',
    ];

    public function evento(): BelongsTo
    {
        return $this->belongsTo(GameEvent::class, 'game_event_id');
    }

    public function colony(): BelongsTo
    {
        return $this->belongsTo(Colony::class);
    }
}
