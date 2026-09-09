<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O XP do Mercado troca um piso de valor por um teto diário (D-241).
 *
 * ## O que o piso fazia, medido
 *
 * `ExecutarOrdem` só concedia XP quando a execução passava de **5 Fert$** — o piso anti-farm que o
 * D-43 criou para a reputação e o D-117 baixou de 500 para 5. Medido nas 13.551 execuções da
 * produção:
 *
 * | | |
 * |---|---|
 * | abaixo do piso | **13.545 — 100,0%** |
 * | valor médio de uma execução | **0,05 Fert$** |
 * | mediana | 0,00 Fert$ |
 * | maior execução de todos os tempos | 45 Fert$ |
 *
 * O piso está **cem vezes acima da execução média**. Em 1.507 ordens executadas, a regra "comerciar
 * rende XP" disparou **três vezes**.
 *
 * ## ⚠️ E ele nunca deteve o ataque que o justificou
 *
 * O piso protege contra *"uma unidade de minério mil vezes"*. Mas num mercado o **preço é das
 * partes**: dois cúmplices anunciam 1 unidade por 100 Fert$ e passam do piso quando quiserem,
 * pagando só os 3% de tributo. O piso barra o comércio pequeno — que é todo o comércio que existe —
 * e não barra a fraude, que é grande por escolha.
 *
 * ## O teto é o instrumento certo
 *
 * Um limite de **quantas vezes por dia** o Mercado paga XP a uma colônia não depende de valor
 * nenhum, e por isso não se contorna com preço: farmar rende no máximo o teto, faça-se uma ou mil
 * trocas. Três por dia é o mesmo número das missões diárias (`Atribuir::DIARIAS_POR_DIA`) — o
 * ritmo que o §06 já deu ao dia de jogo —, e o dia é o mesmo dia de missão (07h→07h, `Janela`).
 *
 * ⚠️ Zero **desliga o teto** (XP sem limite), não a fonte. Quem desliga a fonte é
 * `xp_mercado_executado = 0`, que é a convenção do `ConcederXp` desde o D-75.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('milestone_settings', function (Blueprint $table) {
            $table->unsignedInteger('xp_mercado_teto_diario')->default(3)->after('xp_mercado_executado');
        });
    }

    public function down(): void
    {
        Schema::table('milestone_settings', function (Blueprint $table) {
            $table->dropColumn('xp_mercado_teto_diario');
        });
    }
};
