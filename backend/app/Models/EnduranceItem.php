<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
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

    protected $fillable = [
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
