<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A carga interplanetária: risco, portão por planeta e imposto (D-269).
 *
 * O GDD (§03) dá a cada planeta um risco por palavra — "nenhum", "baixo", "alto" — e três portões sem
 * critério: Auryn "por marco de servidor", Solène "por ciência", Drakmoor "tardio". O usuário mandou
 * seguir as recomendações, e elas viram números aqui:
 *
 * | planeta  | risco  | sinistro           | portão                         |
 * |----------|--------|--------------------|--------------------------------|
 * | Kalidor  | nenhum | —                  | o do Cargueiro (marco 20, 10 d) |
 * | Veyra    | nenhum | —                  | o do Cargueiro                  |
 * | Auryn    | baixo  | 10% de chance, 20% | marco 25                        |
 * | Solène   | nenhum | —                  | Método Experimental pesquisado  |
 * | Drakmoor | alto   | 30% de chance, 50% | marco 35 (Construtor)           |
 *
 * Com mecânica de risco e portão, Solène e Drakmoor deixam de ter motivo para ficar fechados: abrem —
 * mas só se já têm preço (a partida do D-263 os precificou) e se o operador ainda não mexeu neles.
 */
return new class extends Migration
{
    /** chave => [chance_bps, perda_bps, marco, tecnologia] */
    private const PLANETAS = [
        'kalidor' => [0, 0, null, null],
        'veyra' => [0, 0, null, null],
        'auryn' => [1000, 2000, 25, null],
        'solene' => [0, 0, null, 'tec_ciencia_1'],
        'drakmoor' => [3000, 5000, 35, null],
    ];

    public function up(): void
    {
        Schema::table('planetas_npc', function (Blueprint $table) {
            // A chance de sinistro numa viagem, e quanto da carga ele leva — em basis points.
            $table->unsignedSmallInteger('risco_chance_bps')->default(0);
            $table->unsignedSmallInteger('risco_perda_bps')->default(0);
            // Somam-se ao portão do Cargueiro (§05): o maior marco vale, e a tecnologia é a mais.
            $table->unsignedTinyInteger('marco_exigido')->nullable();
            $table->string('tecnologia_exigida', 40)->nullable();
        });

        Schema::table('contratos_de_rota', function (Blueprint $table) {
            // Nulo: ainda no ar. 0: chegou inteiro. >0: quanto da carga o sinistro levou.
            $table->unsignedSmallInteger('sinistro_bps')->nullable();
            $table->unsignedBigInteger('imposto_importacao_micro')->default(0);
            $table->unsignedBigInteger('imposto_venda_micro')->nullable();
        });

        foreach (self::PLANETAS as $chave => [$chance, $perda, $marco, $tecnologia]) {
            DB::table('planetas_npc')->where('chave', $chave)->update([
                'risco_chance_bps' => $chance,
                'risco_perda_bps' => $perda,
                'marco_exigido' => $marco,
                'tecnologia_exigida' => $tecnologia,
                'updated_at' => now(),
            ]);
        }

        foreach (['solene', 'drakmoor'] as $chave) {
            $id = DB::table('planetas_npc')->where('chave', $chave)->where('aberto', false)->value('id');

            if ($id && DB::table('planeta_npc_precos')->where('planeta_npc_id', $id)->exists()) {
                DB::table('planetas_npc')->where('id', $id)->update(['aberto' => true, 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        if (DB::table('contratos_de_rota')->where('status', 'em_viagem')->exists()) {
            throw new RuntimeException('Há cargueiro em viagem. Espere os contratos voltarem antes de desfazer.');
        }

        DB::table('planetas_npc')->whereIn('chave', ['solene', 'drakmoor'])->update(['aberto' => false]);

        Schema::table('contratos_de_rota', function (Blueprint $table) {
            $table->dropColumn(['sinistro_bps', 'imposto_importacao_micro', 'imposto_venda_micro']);
        });

        Schema::table('planetas_npc', function (Blueprint $table) {
            $table->dropColumn(['risco_chance_bps', 'risco_perda_bps', 'marco_exigido', 'tecnologia_exigida']);
        });
    }
};
