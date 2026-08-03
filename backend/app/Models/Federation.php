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
    ];

    protected $casts = ['disbanded_at' => 'datetime', 'fert_micro' => 'integer'];

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
