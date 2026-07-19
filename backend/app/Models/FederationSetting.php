<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Os dois números do §04 delegados ao OPERADOR, não ao código:
 *
 *  - `teto_ocupacao_zonas_bps` (D-119): o limite antimonopólio territorial. "20% → 10%", sem dizer
 *    de quê nem o gatilho da transição — um teto fixo, ajustável sem deploy.
 *  - `desconto_tributo_aliados_bps` (D-120): "50% de desconto nos tributos entre aliadas" (v3.0).
 *    O GDD publica o número desta vez, mas ainda assim fica no painel — mesmo raciocínio do D-35
 *    (ajustável sem deploy), e a mesma tabela do D-119 já existia para o outro parâmetro do §04.
 *
 * Linha única. Mesmo padrão de `TransportSetting`/`WarSetting`.
 */
class FederationSetting extends Model
{
    protected $fillable = ['teto_ocupacao_zonas_bps', 'desconto_tributo_aliados_bps'];

    protected $casts = [
        'teto_ocupacao_zonas_bps' => 'integer',
        'desconto_tributo_aliados_bps' => 'integer',
    ];

    /**
     * ⚠️ **Relê do banco depois de criar** — a lição do `WarSetting`/`TransportSetting`: o
     * `firstOrCreate([])` no caminho da criação devolve só `id` e timestamps, não os defaults que
     * o banco aplicou. A primeira federação ocuparia zona sem teto nenhum, e a segunda chamada já
     * leria o número certo — sem ninguém saber explicar a diferença.
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
