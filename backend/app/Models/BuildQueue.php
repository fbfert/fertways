<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuildQueue extends Model
{
    protected $table = 'build_queue';

    protected $fillable = [
        'colony_id', 'building_id', 'target_level', 'position',
        'quoted_cost_json', 'subsidized', 'enqueued_at', 'starts_at', 'finishes_at', 'status',
    ];

    protected $casts = [
        'target_level' => 'integer',
        'position' => 'integer',
        'quoted_cost_json' => 'array',
        'subsidized' => 'boolean',
        'enqueued_at' => 'datetime',
        'starts_at' => 'datetime',
        'finishes_at' => 'datetime',
    ];

    /**
     * "Fila dupla gratuita e automática nos primeiros 5 dias completos de conta. A partir do
     * 6º dia, a colônia volta à fila única padrão." (GDD, onboarding)
     *
     * O NÚMERO de vagas é do operador desde o D-111 (`FilaSetting`) — o 2/1 que aqui era
     * constante de código virou o padrão da migration, editável no painel. **O prazo de 5 dias
     * continua fixo**: ninguém pediu pra torná-lo configurável, e persistir só o número (não a
     * regra de quando ele vale) evita o mesmo problema que o comentário original evitava — uma
     * conta que faz 6 dias não pode continuar lendo o valor de novato porque um admin salvou algo
     * uma vez.
     */
    public static function vagasDe(User $user): int
    {
        $config = FilaSetting::singleton();

        return $user->created_at->addDays(5)->isFuture()
            ? $config->colonia_vagas_novato
            : $config->colonia_vagas_padrao;
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function colony(): BelongsTo
    {
        return $this->belongsTo(Colony::class);
    }

    public function scopeAtivos($q)
    {
        return $q->whereIn('status', ['queued', 'building']);
    }
}
