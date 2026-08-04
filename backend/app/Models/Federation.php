<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma federação (GDD §04/§07; docs/decisoes.md D-114), Fatia 1: só o núcleo — membros, cargos e
 * fundo. Chat, cerco com apoio de aliado, missões e o resto ficam para fatias seguintes.
 *
 * `disbanded_at` em vez de `DELETE`: dissolvida vira histórico consultável, mesmo padrão de
 * `Admin.desativado_em`.
 */
class Federation extends Model
{
    /** Os quatro cargos do GDD (v3.2, "regra definitiva" — a v3.0 só citava Líder e Diplomata). */
    public const LIDER = 'lider';

    public const DIPLOMATA = 'diplomata';

    public const INTENDENTE = 'intendente';

    public const MEMBRO = 'membro';

    public const CARGOS = [self::LIDER, self::DIPLOMATA, self::INTENDENTE, self::MEMBRO];

    /** GDD: "Membros Máximo 12." Regra de jogo, não parâmetro que o operador configure. */
    public const MAX_COLONIAS = 12;

    protected $fillable = [
        'name',
        'disbanded_at',
        // A2.10: o caixa do fundo. Sem ele, o custo de declarar guerra não teria de onde sair.
        'fert_micro',
        // A2.10: a neutralidade declarada (decisão 12).
        'neutra_desde',
        'neutralidade_termina_em',
        /*
         * ⚠️ A2.10: o rating do ranking federativo (D-207). A ausência dele aqui fez o `update()` do
         * `RatingFederativo` ser descartado **em silêncio** — e um dos testes do Elo passou assim
         * mesmo, porque o cenário que ele montava também era descartado e a comparação continuava
         * verdadeira por acidente. É a quinta vez nesta fase que uma coluna nova fica de fora do
         * `fillable` (`max_aliadas`, `operadores`, `fert_micro`, `war_id`); é sempre silencioso.
         */
        'rating_guerra',
    ];

    protected $casts = [
        'disbanded_at' => 'datetime',
        'fert_micro' => 'integer',
        'neutra_desde' => 'datetime',
        'neutralidade_termina_em' => 'datetime',
        'rating_guerra' => 'integer',
    ];

    public function membros(): HasMany
    {
        return $this->hasMany(Colony::class, 'federation_id');
    }

    public function convites(): HasMany
    {
        return $this->hasMany(FederationInvite::class);
    }

    public function fundo(): HasMany
    {
        return $this->hasMany(FederationHolding::class);
    }

    public function ledger(): HasMany
    {
        return $this->hasMany(FederationLedger::class);
    }

    public function dissolvida(): bool
    {
        return $this->disbanded_at !== null;
    }
}
