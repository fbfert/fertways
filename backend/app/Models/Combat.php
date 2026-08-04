<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma batalha por uma zona neutra (GDD §27.5, §28.10; docs/decisoes.md D-66).
 *
 * Os **quatro** tipos de ataque compartilham esta mesma linha e a mesma máquina de rodadas de 10
 * minutos — é o que o §28.10 diz na primeira frase ("todos os tipos usam como base comum a
 * mecânica de rodadas"). O que muda é o que cada rodada FAZ.
 *
 * O combate não é instantâneo, e isso é deliberado (§27.5): um confronto equilibrado dura ~2 h,
 * "tempo suficiente para o defensor receber notificação, recrutar reforços e despachá-los".
 * Reforços que chegam no meio entram no cálculo **a partir da rodada seguinte**.
 */
class Combat extends Model
{
    /** A rodada do §27.5. Tudo no combate é múltiplo disto. */
    public const RODADA_MINUTOS = 10;

    /** Fração da força que vira dano a cada rodada (§27.5). Publicado. */
    public const DANO_BPS = 1500;   // 15%

    /** A marcha de combate é 1,3× mais lenta que a civil (§27.4). Publicado. */
    public const MARCHA = 1.3;

    /** Bônus do defensor genuinamente offline (§27.7). Publicado. */
    public const OFFLINE_BPS = 2000;   // +20%

    /** Chance base do Infiltrador por rodada, se não detectado (§28.10). Publicado. */
    public const INFILTRADOR_BPS = 6000;   // 60%

    /** O saque da Invasão Direta: 50% do **não protegido** (§27.8, corrigido pela v3.2). */
    public const SAQUE_BPS = 5000;

    /** O cerco entrega 30% do não protegido, se o defensor se render (§28.10). */
    public const CERCO_BPS = 3000;

    /** O defensor tem 48 h para romper o cerco ou render-se (§28.10). */
    public const CERCO_HORAS = 48;

    /** O resgate do Módulo Operacional apreendido pelo Predador (§28.10). */
    public const RESGATE_HORAS = 24;

    /** O mesmo atacante não reataca a mesma zona por 48 h (§27.10). Outros podem. */
    public const COOLDOWN_HORAS = 48;

    protected $fillable = [
        'zone_id', 'attacker_colony_id', 'defender_colony_id',
        'tipo', 'status', 'rodada',
        'chega_at', 'proxima_rodada_at', 'prazo_at',
        'alvo', 'resultado',
        /*
         * ⚠️ A guerra federativa sob a qual este ataque partiu (D-207), ou nulo fora dela. Sem o
         * `fillable` o `create()` do despacho o descartaria em silêncio, e o saldo por prazo leria
         * uma guerra sem batalha nenhuma — o defeito que já custou três correções nesta fase.
         */
        'war_id',
    ];

    protected $casts = [
        'rodada' => 'integer',
        'chega_at' => 'datetime',
        'proxima_rodada_at' => 'datetime',
        'prazo_at' => 'datetime',
        'resultado' => 'array',
    ];

    public function zone(): BelongsTo
    {
        return $this->belongsTo(NeutralZone::class, 'zone_id');
    }

    public function attacker(): BelongsTo
    {
        return $this->belongsTo(Colony::class, 'attacker_colony_id');
    }

    public function defender(): BelongsTo
    {
        return $this->belongsTo(Colony::class, 'defender_colony_id');
    }

    /** As unidades que este combate mobilizou — as do atacante, marchando ou em combate. */
    public function units(): HasMany
    {
        return $this->hasMany(Unit::class, 'combat_id');
    }

    /** Ainda está de pé: marchando para lá, ou trocando golpes. */
    public function vivo(): bool
    {
        return in_array($this->status, ['marchando', 'em_curso'], true);
    }
}
