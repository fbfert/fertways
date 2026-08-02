<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Os dois números do §04 delegados ao OPERADOR, não ao código:
 *
 *  - `teto_ocupacao_zonas_bps` (D-119): o limite antimonopólio territorial. "20% → 10%", sem dizer
 *    de quê nem o gatilho da transição — um teto fixo, ajustável sem deploy.
 *  - `desconto_tributo_aliados_bps` (D-120): "50% de desconto nos tributos entre aliadas" (v3.0).
 *  - `desconto_tributo_aliancas_bps` (A2.5): o mesmo entre federações ALIADAS — menor de propósito,
 *    senão o teto de 12 membros viraria letra morta.
 *  - `max_aliadas` (A2.5): quantas aliadas uma federação pode ter ao mesmo tempo.
 *    O GDD publica o número desta vez, mas ainda assim fica no painel — mesmo raciocínio do D-35
 *    (ajustável sem deploy), e a mesma tabela do D-119 já existia para o outro parâmetro do §04.
 *
 * Linha única. Mesmo padrão de `TransportSetting`/`WarSetting`.
 */
class FederationSetting extends Model
{
    protected $fillable = [
        'teto_ocupacao_zonas_bps',
        'desconto_tributo_aliados_bps',
        // A2.5: sem estes dois no `$fillable`, a atribuição em massa os descarta EM SILÊNCIO — o
        // painel do operador salvaria sem erro e sem efeito. Foi um teste que pegou.
        'desconto_tributo_aliancas_bps',
        'max_aliadas',
    ];

    protected $casts = [
        'teto_ocupacao_zonas_bps' => 'integer',
        'desconto_tributo_aliados_bps' => 'integer',
        'desconto_tributo_aliancas_bps' => 'integer',
        'max_aliadas' => 'integer',
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
