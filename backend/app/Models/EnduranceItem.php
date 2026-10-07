<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Um item da Loja de Peças da Endurance (D-135) — catálogo dinâmico, editável por completo no
 * painel (`/central/admin/endurance`). Substitui `EndurancePieceSpec` (D-132/D-133, 32 linhas
 * fixas).
 */
class EnduranceItem extends Model
{
    public const COMUM = 'comum';

    public const RARO = 'raro';

    public const UNICO = 'unico';

    public const TIPOS = [self::COMUM, self::RARO, self::UNICO];

    /** As 8 seções do casco — mesmas chaves de `Vinculaveis::SECOES_DA_ENDURANCE`, sem o prefixo. */
    public const SECOES = [
        'anel_habitacional' => 'Anel Habitacional',
        'baia_criogenica' => 'Baía Criogênica',
        'comando' => 'Comando',
        'matriz_comunicacao' => 'Matriz de Comunicação',
        'modulo_medico' => 'Módulo Médico',
        'nucleo_propulsao' => 'Núcleo de Propulsão',
        'secao_acoplagem' => 'Seção de Acoplagem',
        'silo_suprimentos' => 'Silo de Suprimentos',
    ];

    /** D-249: a peça que se compra na loja da seção. É o que existe desde o D-135. */
    public const LOJA = 'loja';

    /** D-249: a peça que só se acha escavando — o §11 chama a Endurance de "origem de peças". */
    public const ESCAVACAO = 'escavacao';

    public const ORIGENS = [self::LOJA, self::ESCAVACAO];

    protected $fillable = [
        'origem', 'game_event_id',
        'item_key', 'secao', 'nome', 'tipo', 'quantidade_total', 'quantidade_vendida',
        'preco_micro', 'marco_minimo', 'vendavel_em_leilao', 'descricao', 'admin_id',
    ];

    protected $casts = [
        'quantidade_total' => 'integer',
        'quantidade_vendida' => 'integer',
        'preco_micro' => 'integer',
        'marco_minimo' => 'integer',
        'vendavel_em_leilao' => 'boolean',
    ];

    public function evento(): BelongsTo
    {
        return $this->belongsTo(GameEvent::class, 'game_event_id');
    }

    /**
     * A peça existe no mundo AGORA? (D-249)
     *
     * Peça sem evento existe sempre. Peça de lote de evento só existe enquanto o evento valer —
     * `vigenteEm()` já sabe de rascunho e de cancelamento, e é ele que decide.
     */
    public function liberadaEm(CarbonInterface $quando): bool
    {
        if ($this->game_event_id === null) {
            return true;
        }

        return $this->evento !== null && $this->evento->vigenteEm($quando);
    }

    /**
     * A mesma pergunta, em SQL: as peças sem evento, mais as de evento que vale agora.
     *
     * ⚠️ Repete a regra de `GameEvent::vigenteEm()` — rascunho não vale; cancelado vale até
     * `cancelado_em`. Um teste fixa que as duas leituras concordam.
     */
    public function scopeLiberadas(Builder $q, CarbonInterface $quando): Builder
    {
        return $q->where(fn ($q) => $q->whereNull('game_event_id')->orWhereHas(
            'evento',
            fn ($e) => $e->whereIn('status', ['ativo', 'cancelado'])
                ->where('comeca_em', '<=', $quando)
                ->where('termina_em', '>=', $quando)
                ->where(fn ($c) => $c->whereNull('cancelado_em')->orWhere('cancelado_em', '>', $quando)),
        ));
    }

    public function efeitos(): HasMany
    {
        return $this->hasMany(EnduranceItemEffect::class);
    }

    public function estoqueLivre(): int
    {
        return max(0, $this->quantidade_total - $this->quantidade_vendida);
    }

    public function esgotado(): bool
    {
        return $this->estoqueLivre() <= 0;
    }
}
