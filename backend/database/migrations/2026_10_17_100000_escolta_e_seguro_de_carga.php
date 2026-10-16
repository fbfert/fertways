<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A escolta e o seguro de carga do Espaçoporto (D-280).
 *
 * O GDD dá a Drakmoor "Alto — escolta opcional" e chama a escolta e o seguro de "mecânicas de
 * temporada" (v3.2), sem nenhum número. Os três números nascem aqui com os valores de partida que
 * recomendei, e o painel os muda:
 *
 * | número                    | partida | o que é                                                   |
 * |---------------------------|---------|-----------------------------------------------------------|
 * | `seguro_carregamento_bps` | 2.500   | o prêmio é a perda esperada da apólice mais 25%            |
 * | `escolta_poder_pleno`     | 800     | o ataque que dá a escolta plena: dez Sentinelas nível 1    |
 * | `escolta_reducao_max_bps` | 7.500   | quanto da chance de sinistro a escolta plena corta: 75%    |
 *
 * O contrato passa a guardar a chance de sinistro **da partida**, como já guarda os preços. Assim o
 * operador que muda o risco amanhã não reescreve uma viagem no ar, e a escolta fica valendo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('espacoporto_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('seguro_carregamento_bps')->default(2500);
            $table->unsignedInteger('escolta_poder_pleno')->default(800);
            $table->unsignedSmallInteger('escolta_reducao_max_bps')->default(7500);
        });

        Schema::table('contratos_de_rota', function (Blueprint $table) {
            // Nulo nos contratos de antes: a volta lê o risco do planeta, como lia.
            $table->unsignedSmallInteger('risco_chance_bps')->nullable();
            $table->unsignedBigInteger('seguro_premio_micro')->default(0);
            // A indenização inteira, reservada do Tesouro na partida.
            $table->unsignedBigInteger('seguro_cobertura_micro')->default(0);
            // Nulo: sem seguro, ou ainda no ar. 0: voltou sem sinistro. >0: o que se pagou.
            $table->unsignedBigInteger('seguro_indenizacao_micro')->nullable();
            $table->json('escolta_unit_ids')->nullable();
            $table->unsignedInteger('escolta_poder')->default(0);
        });
    }

    public function down(): void
    {
        if (DB::table('contratos_de_rota')->where('status', 'em_viagem')->exists()) {
            throw new RuntimeException('Há cargueiro em viagem. Espere os contratos voltarem antes de desfazer.');
        }

        // Nenhuma Sentinela fica presa numa escolta que deixa de existir.
        DB::table('units')->where('status', 'escolta')->update(['status' => 'casa']);

        Schema::table('contratos_de_rota', function (Blueprint $table) {
            $table->dropColumn([
                'risco_chance_bps', 'seguro_premio_micro', 'seguro_cobertura_micro',
                'seguro_indenizacao_micro', 'escolta_unit_ids', 'escolta_poder',
            ]);
        });

        Schema::table('espacoporto_settings', function (Blueprint $table) {
            $table->dropColumn(['seguro_carregamento_bps', 'escolta_poder_pleno', 'escolta_reducao_max_bps']);
        });
    }
};
