<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Os parâmetros do Painel do Ministério dos Transportes (§16) — do **operador**, não do código.
 *
 * O GDD manda o painel "configurar a curva de depreciação por hora de uso", "configurar o limite
 * crítico de desempenho" e "configurar a perda de vida útil e o teto de revenda a cada manutenção".
 * São exatamente os números que o documento nunca publica — e é por isso que a depreciação pôde sair
 * da geladeira no D-60: eles não precisam ser inventados no código, porque o §16 já dizia de quem
 * eles são.
 *
 * Linha única. Mesmo padrão do D-35 (a intervenção de preço declarada pelo operador).
 */
class TransportSetting extends Model
{
    protected $fillable = [
        'desgaste_bps_por_hora',
        'piso_desempenho_bps',
        'manutencao_bps_do_custo',
        'perda_de_teto_bps',
    ];

    protected $casts = [
        'desgaste_bps_por_hora' => 'integer',
        'piso_desempenho_bps' => 'integer',
        'manutencao_bps_do_custo' => 'integer',
        'perda_de_teto_bps' => 'integer',
    ];

    /**
     * A linha única, criada com os padrões do schema se ainda não existir.
     *
     * O `firstOrCreate` evita que um banco sem o seeder (um teste, uma migração parcial) deixe a
     * depreciação inerte em silêncio — o que seria pior que falhar.
     */
    public static function singleton(): self
    {
        return static::firstOrCreate([]);
    }
}
