<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma unidade de combate (GDD §27.1, §27.2; docs/decisoes.md D-66).
 *
 * Existe uma linha por unidade, e não um contador, porque o §27.6 exige HP individual: as baixas
 * de cada rodada se distribuem entre as unidades presentes, os sobreviventes voltam FERIDOS, e a
 * unidade que chega a zero é destruída **permanentemente**. Um `int` de guarnição — que foi o que
 * a Fatia 1 tinha — não sabe nada disso.
 *
 * Uma unidade está em CASA (`colony_id`) ou NA ZONA (`zone_id`), nunca nas duas.
 */
class Unit extends Model
{
    /**
     * Pontos de DEFESA por nível — §27.1. Publicado, não arbitrado.
     *
     * O Robô Minerador tem 25% da Sentinela do mesmo nível (§27.2), e o GDD já publica a linha
     * dele arredondada (`25 38 56 84 126`); não a recalculamos, para não divergir do documento
     * por um ponto de arredondamento.
     */
    public const DEFESA = [
        'sentinela' => [1 => 100, 2 => 150, 3 => 225, 4 => 338, 5 => 506],
        'robo_minerador' => [1 => 25, 2 => 38, 3 => 56, 4 => 84, 5 => 126],
    ];

    /**
     * Pontos de ATAQUE por nível — §27.1.
     *
     * O Robô Minerador tem ataque **zero**: "não tem função ofensiva" (§27.2). O Infiltrador e o
     * Predador têm "baixo poder de combate" e o GDD nunca publica número — eles não atacam por
     * força, e sim por chance (§28.10). Se forem detectados, caem em combate normal, e aí valem
     * o que a tabela diz: nada. É deliberado que percam.
     */
    public const ATAQUE = [
        'sentinela' => [1 => 80, 2 => 120, 3 => 180, 4 => 270, 5 => 405],
        'robo_minerador' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0],
        'infiltrador' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0],
        'predador' => [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0],
    ];

    /** HP cheio, em basis points. */
    public const INTEIRA = 10000;

    protected $fillable = [
        'colony_id', 'zone_id', 'combat_id',
        'type', 'level', 'hp_bps', 'status', 'arrives_at',
    ];

    protected $casts = [
        'level' => 'integer',
        'hp_bps' => 'integer',
        'arrives_at' => 'datetime',
    ];

    public function colony(): BelongsTo
    {
        return $this->belongsTo(Colony::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(NeutralZone::class, 'zone_id');
    }

    /**
     * O que esta unidade vale em defesa, AGORA — a tabela do nível, descontado o ferimento.
     *
     * Uma Sentinela nível 1 a 50% de HP defende com 50 pontos, não com 100. É o que faz reforço
     * ferido valer menos que reforço inteiro, e é por isso que o HP é guardado em basis points:
     * truncar a fração faria uma rodada de 3% de dano sumir.
     */
    public function defesa(): int
    {
        $base = self::DEFESA[$this->type][$this->level] ?? 0;

        return intdiv($base * $this->hp_bps, self::INTEIRA);
    }

    /** O que esta unidade vale em ataque, agora. Robô, Infiltrador e Predador valem zero. */
    public function ataque(): int
    {
        $base = self::ATAQUE[$this->type][$this->level] ?? 0;

        return intdiv($base * $this->hp_bps, self::INTEIRA);
    }

    /** Morta de vez (§27.6). Não volta ao Abrigo nem ao Quartel: precisa ser reconstruída. */
    public function destruida(): bool
    {
        return $this->hp_bps <= 0;
    }
}
