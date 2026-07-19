<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * O limite antimonopólio territorial da Federação (§04; docs/decisoes.md D-119) — do OPERADOR, não
 * do código. O GDD escreve "Limite antimonopólio dinâmico: 20% → 10%" e nunca diz de quê nem o
 * gatilho da transição; em vez de inventar os dois, o número é um teto fixo, ajustável sem deploy —
 * mesmo padrão do D-35 (o GDD manda alguém declarar, e nunca publica).
 *
 * Linha única. Mesmo padrão de `TransportSetting`/`WarSetting`.
 */
class FederationSetting extends Model
{
    protected $fillable = ['teto_ocupacao_zonas_bps'];

    protected $casts = ['teto_ocupacao_zonas_bps' => 'integer'];

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
