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
        // A âncora do teto de revenda do Furgão (D-73). NÃO é preço de venda — o Ministério continua
        // não vendendo Furgão; é só o número de que o teto se calcula.
        'furgao_preco_referencia_micro',
        // O frete público (§07, D-76): bandeirada + por slot de distância. Do operador, no painel.
        'frete_base_micro',
        'frete_por_slot_micro',
    ];

    protected $casts = [
        'desgaste_bps_por_hora' => 'integer',
        'piso_desempenho_bps' => 'integer',
        'manutencao_bps_do_custo' => 'integer',
        'perda_de_teto_bps' => 'integer',
        'furgao_preco_referencia_micro' => 'integer',
        'frete_base_micro' => 'integer',
        'frete_por_slot_micro' => 'integer',
    ];

    /**
     * A linha única, criada com os padrões do schema se ainda não existir.
     *
     * O `firstOrCreate` evita que um banco sem o seeder (um teste, uma migração parcial) deixe a
     * depreciação inerte em silêncio — o que seria pior que falhar.
     *
     * ⚠️ **Relê do banco depois de criar** (a lição do `WarSetting`, D-70): no caminho da criação o
     * Eloquent devolve um modelo com só o `id` e os timestamps — ele **não relê os defaults que o
     * banco aplicou**. A primeira chamada leria desgaste nulo e um Furgão sem teto; a segunda, os
     * números certos — e ninguém saberia explicar a diferença.
     */
    public static function singleton(): self
    {
        if ($existente = static::first()) {
            return $existente;
        }

        static::create([]);

        return static::firstOrFail();
    }
}
