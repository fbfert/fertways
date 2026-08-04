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
    /**
     * ⚠️ `torre_aviso_minutos_por_nivel` **estava de fora**, e ninguém tinha notado — porque até o
     * D-70 nada o escrevia. A coluna nasceu no D-67 (é ela que faz a Torre de Vigia valer alguma
     * coisa: sem ela o defensor via todo ataque desde o despacho) e a leitura funciona sem `fillable`.
     * O `update()` do painel é que a descartaria **em silêncio**: o admin mudaria o número, a tela
     * diria "atualizado", e o valor continuaria o mesmo. Um teste do D-70 afirma que ele grava.
     */
    protected $fillable = [
        'muralha_bonus_bps', 'torre_bonus_bps', 'bastiao_bonus_bps',
        'torre_deteccao_bps_por_nivel',
        'torre_aviso_minutos_por_nivel',
        'predador_base_bps', 'predador_por_nivel_bps',
        'predador_min_bps', 'predador_max_bps',
        'niobio_preco_micro',
        'reparo_bps_do_custo',
        /*
         * ⚠️ Os da guerra federativa faltavam aqui desde o D-193 — e a migration que os criou diz,
         * por escrito, que eles moram em `war_settings` justamente para "mudar sem deploy". Sem
         * `fillable` o painel nunca poderia gravá-los, e a promessa era falsa: só mudavam por SQL.
         * Ver o docblock abaixo sobre por que a ausência custa caro exatamente aqui.
         */
        'federativa_duracao_horas',
        'federativa_cooldown_horas',
        'federativa_custo_fert_micro',
        'federativa_custo_niobio',
        'neutralidade_carencia_horas',
        'capitulacao_fert_micro',
        'rating_k',
    ];

    protected $casts = [
        'muralha_bonus_bps' => 'integer',
        'torre_bonus_bps' => 'integer',
        'bastiao_bonus_bps' => 'integer',
        'torre_deteccao_bps_por_nivel' => 'integer',
        'torre_aviso_minutos_por_nivel' => 'integer',
        'predador_base_bps' => 'integer',
        'predador_por_nivel_bps' => 'integer',
        'predador_min_bps' => 'integer',
        'predador_max_bps' => 'integer',
        'niobio_preco_micro' => 'integer',
        'federativa_duracao_horas' => 'integer',
        'federativa_cooldown_horas' => 'integer',
        'federativa_custo_fert_micro' => 'integer',
        'federativa_custo_niobio' => 'integer',
        'neutralidade_carencia_horas' => 'integer',
        'capitulacao_fert_micro' => 'integer',
        'rating_k' => 'integer',
        'reparo_bps_do_custo' => 'integer',
    ];

    /**
     * ⚠️ **Relê do banco depois de criar, e isso não é preciosismo.**
     *
     * `firstOrCreate([])` devolve, no caminho da criação, um modelo com **só** o `id` e os
     * timestamps: o Eloquent insere a linha e **não relê os defaults que o banco aplicou**. Todos os
     * outros campos vêm `null`. Na prática, a **primeira** chamada depois da migration leria bônus
     * zero, chances zero e preço zero — e a segunda, os números certos.
     *
     * Isso derrotaria exatamente o motivo de os defaults estarem no banco (ver a migration): o
     * primeiro colono a abrir a guerra encontraria uma Muralha que não protege e um Nióbio de graça.
     * Um teste afirma que a primeira chamada já traz os números do D-66.
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
