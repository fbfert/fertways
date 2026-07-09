<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Building extends Model
{
    protected $fillable = ['colony_id', 'type', 'level', 'upgrade_started_at', 'upgrade_finish_at'];

    protected $casts = [
        'level' => 'integer',
        'upgrade_started_at' => 'datetime',
        'upgrade_finish_at' => 'datetime',
    ];

    /**
     * As cinco essenciais (GDD §24.7, verbatim). Subsidiadas em 100% até o nível 3,
     * mediante conclusão da tutoria. A partir do nível 4, custo integral.
     */
    public const ESSENCIAIS = [
        'gerador_de_atmosfera',
        'estrutura_de_sobrevivencia',
        'fazenda',
        'reator_de_energia',
        'captacao_de_agua',
    ];

    /**
     * Construções de progressão do MVP. "Nunca são subsidiadas — exigem produção própria
     * desde o nível 1" (§24.7).
     *
     * Mina Local e Destilaria não constavam da lista original do MVP, mas o item 3 exige
     * a cadeia Metal Bruto -> Componentes e a cadeia do Biocombustível, e elas são as
     * únicas fontes desses recursos. Ver docs/decisoes.md D-06.
     */
    public const PROGRESSAO = [
        'oficina',
        'refinaria_quimica',
        'laboratorio',
        'antena_de_comunicacao',
        'torre_de_defesa',
        'mercado_local',
        'quartel',
        'plataforma_de_pouso',
        'central_de_transportes',
        'mina_local',
        'destilaria',
    ];

    public const MVP = [...self::ESSENCIAIS, ...self::PROGRESSAO];

    public function colony(): BelongsTo
    {
        return $this->belongsTo(Colony::class);
    }

    public function ehEssencial(): bool
    {
        return in_array($this->type, self::ESSENCIAIS, true);
    }

    /** Nível 0 = ainda não construída. As specs do GDD começam no nível 1. */
    public function construida(): bool
    {
        return $this->level > 0;
    }
}
