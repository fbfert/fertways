<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Os parâmetros da escavação da Endurance (D-249) — do operador, e vazios até ele dizê-los.
 *
 * ⚠️ `pronta()` é a guarda contra a chave-mestra ligada sobre o nada: sem duração não há prazo, e
 * uma escavação sem prazo terminaria no mesmo minuto em que começou.
 */
class EnduranceEscavacaoSetting extends Model
{
    protected $fillable = ['ativo', 'custo', 'duracao_minutos'];

    protected $casts = [
        'ativo' => 'boolean',
        'custo' => 'array',
        'duracao_minutos' => 'integer',
    ];

    public static function singleton(): self
    {
        if ($existente = static::first()) {
            return $existente;
        }

        static::create([]);

        return static::firstOrFail();
    }

    /** O operador já disse tudo o que a escavação precisa para existir? */
    public function pronta(): bool
    {
        return (int) $this->duracao_minutos > 0;
    }

    public function ligada(): bool
    {
        return $this->ativo && $this->pronta();
    }
}
