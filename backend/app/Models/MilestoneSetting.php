<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Os valores de XP por ato — do OPERADOR, no painel (D-75).
 *
 * O GDD manda as missões pagarem "XP" (§06) e nunca diz quanto vale coisa nenhuma. Mesmo gancho de
 * sempre (D-60, D-66, D-73): número que o documento manda existir e não publica é do painel, e muda
 * sem deploy. A CURVA (50×N²) não está aqui de propósito: mudá-la reescala o marco de todo mundo de
 * uma vez — é decisão de arbitragem (D-75), não de balanceamento.
 */
class MilestoneSetting extends Model
{
    protected $fillable = [
        'xp_obra_por_nivel',
        'xp_zona_ocupada',
        'xp_combate_vencido',
        'xp_acordo_executado',
        'xp_mercado_executado',
        /*
         * Quantas vezes por DIA DE MISSÃO (07h→07h) o Mercado paga XP a uma colônia (D-241). Zero
         * desliga o TETO, não a fonte — quem desliga a fonte é `xp_mercado_executado = 0`.
         */
        'xp_mercado_teto_diario',
    ];

    protected $casts = [
        'xp_obra_por_nivel' => 'integer',
        'xp_zona_ocupada' => 'integer',
        'xp_combate_vencido' => 'integer',
        'xp_acordo_executado' => 'integer',
        'xp_mercado_executado' => 'integer',
        'xp_mercado_teto_diario' => 'integer',
    ];

    /** Relê depois de criar — a lição do `WarSetting` (D-70): o caminho da criação não traz os defaults. */
    public static function singleton(): self
    {
        if ($existente = static::first()) {
            return $existente;
        }

        static::create([]);

        return static::firstOrFail();
    }
}
