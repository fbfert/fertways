<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Operadores alocados por zona neutra (A2.6).
 *
 * ## ⚠️ Alocação explícita, e sem trânsito — a leitura que estou fazendo do roadmap
 *
 * As entregas listam *"transferência colônia → zona"* e *"retorno"* como itens separados. Aqui elas
 * viram **alocar** e **devolver** operadores, **instantâneos**.
 *
 * Gente em viagem seria um sistema novo: o GDD **não publica** tempo de deslocamento de pessoas, e
 * inventá-lo duplicaria a logística que já existe para carga — com veículo, capacidade e desgaste.
 * A decisão de jogo que a fase quer é *"quais zonas eu consigo manter operando com a população que
 * tenho"*, e ela existe inteira sem trânsito.
 *
 * ⚠️ Isto **estreita** uma entrega, e por isso está escrito: se um dia se quiser colono em viagem, é
 * decisão separada, e esta coluna continua servindo.
 *
 * ## Por que explícito, e não derivado do nível
 *
 * `Populacao::alocadaEmZonas()` derivava a alocação do nível da zona: ter a zona já custava os
 * operadores. Isso não produz **escolha nenhuma** — e a fase inteira existe para criar a decisão de
 * onde pôr gente quando ela falta. Com alocação explícita, uma colônia curta escolhe qual zona opera
 * a pleno e qual degrada, que é o que o §6.6 desenha.
 *
 * ## O grandfathering, medido antes
 *
 * Existe **uma única zona ocupada** no mundo, nível 1, exigindo 2 operadores. Ela nasce com a equipe
 * completa: o §6.7 vale aqui como valeu para a população — nada que já existe passa a falhar por uma
 * regra nova.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('neutral_zones', function (Blueprint $table) {
            /*
             * Quantos colonos da dona estão operando esta zona. Sai do bolo da colônia, não de um
             * estoque próprio: quem conta a população continua sendo `colonies.populacao`, e esta
             * coluna diz onde parte dela está trabalhando.
             */
            $table->unsignedInteger('operadores')->default(0);
        });

        /*
         * Grandfathering (§6.7): toda zona já ocupada nasce com a equipe que o nível dela exige.
         *
         * A conta é a mesma de `Parametros::operadoresDeZona()` — lida da MESMA linha de parâmetros,
         * e não de uma tabela digitada aqui, para não haver duas verdades sobre o mesmo número.
         */
        $mapa = json_decode(
            DB::table('population_settings')->where('id', 1)->value('zona_operadores_por_nivel') ?? '{}',
            true,
        ) ?: [];

        foreach (DB::table('neutral_zones')->whereNotNull('owner_colony_id')->get(['id', 'level']) as $zona) {
            DB::table('neutral_zones')->where('id', $zona->id)->update([
                'operadores' => (int) ($mapa[(string) $zona->level] ?? $mapa[$zona->level] ?? 0),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('neutral_zones', function (Blueprint $table) {
            $table->dropColumn('operadores');
        });
    }
};
