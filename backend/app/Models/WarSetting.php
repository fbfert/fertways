<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Os parâmetros da guerra que o OPERADOR declara (docs/decisoes.md D-66).
 *
 * O §27.3 escreve os bônus defensivos como "+X%, +Y%, +Z% (**valores configuráveis**)" e o §28.10
 * manda calcular duas chances que nunca publica. É o mesmo gancho do §16 que destravou a
 * depreciação no D-60: quando o GDD manda alguém declarar um número e não o publica, ele é do
 * painel de admin, não do código. Muda sem deploy.
 *
 * ⚠️ **Os defaults moram no BANCO**, não aqui. O `transport_settings` do D-60 nasceu vazio e deixou
 * a depreciação inerte até alguém lembrar do seeder — silenciosamente. Aqui, o `firstOrCreate([])`
 * já sai com os números do D-66, e esquecer o seeder não pode apagar a guerra.
 */
class WarSetting extends Model
{
    protected $fillable = [
        'muralha_bonus_bps', 'torre_bonus_bps', 'bastiao_bonus_bps',
        'torre_deteccao_bps_por_nivel',
        'predador_base_bps', 'predador_por_nivel_bps',
        'predador_min_bps', 'predador_max_bps',
        'niobio_preco_micro',
    ];

    protected $casts = [
        'muralha_bonus_bps' => 'integer',
        'torre_bonus_bps' => 'integer',
        'bastiao_bonus_bps' => 'integer',
        'torre_deteccao_bps_por_nivel' => 'integer',
        'predador_base_bps' => 'integer',
        'predador_por_nivel_bps' => 'integer',
        'predador_min_bps' => 'integer',
        'predador_max_bps' => 'integer',
        'niobio_preco_micro' => 'integer',
    ];

    public static function singleton(): self
    {
        return static::firstOrCreate([]);
    }
}
