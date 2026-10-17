<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * As correções da revisão completa do jogo (D-283 e D-284).
 *
 * - `planetas_npc.demanda_diaria_micro`: quanto cada planeta paga, no máximo, por dia de missão, somando
 *   todos os colonos (D-283). Partida: 100 F$ por planeta, 500 F$/dia no servidor. É a mesma ordem de
 *   grandeza dos ~360 F$/dia que o D-263 estimou, e que a auditoria mostrou ser só suposição: sem teto,
 *   uma viagem com 10.000 Componentes Eletrônicos rendia ~8.900 F$. Nulo é "sem limite".
 * - `colonies.niveis_de_obra_pagos`: o maior total de níveis de construção que a colônia já teve (D-283).
 *   O XP de obra paga o crescimento acima dele, não o nível reerguido depois de uma demolição. Nasce com
 *   o total de hoje, para ninguém perder nem ganhar XP com o deploy.
 * - `milestone_settings.xp_acordo_teto_diario`: o teto diário do XP de Acordo, como o do Mercado
 *   (D-241, D-283). Partida: 3, o mesmo do Mercado.
 * - `contratos_de_rota.risco_perda_bps`: a fração que o sinistro leva, travada na partida como a chance
 *   (D-284). Sem ela, o seguro pagava a cobertura da partida contra a perda da volta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planetas_npc', function (Blueprint $table) {
            $table->unsignedBigInteger('demanda_diaria_micro')->nullable()->default(100_000_000);
        });

        Schema::table('colonies', function (Blueprint $table) {
            $table->unsignedInteger('niveis_de_obra_pagos')->default(0);
        });

        Schema::table('milestone_settings', function (Blueprint $table) {
            $table->unsignedInteger('xp_acordo_teto_diario')->default(3);
        });

        Schema::table('contratos_de_rota', function (Blueprint $table) {
            $table->unsignedSmallInteger('risco_perda_bps')->nullable();
        });

        // D-285: o que cada colônia produziu, por recurso e por hora. O resumo "Desde sua última
        // visita" lia a produção do ledger, que nunca a recebeu.
        Schema::create('producao_por_hora', function (Blueprint $table) {
            $table->foreignId('colony_id')->constrained()->cascadeOnDelete();
            $table->string('resource_type', 40);
            $table->dateTime('hora');
            $table->unsignedBigInteger('quantidade')->default(0);
            $table->unique(['colony_id', 'resource_type', 'hora']);
            $table->index(['colony_id', 'hora']);
        });

        DB::table('planetas_npc')->update(['demanda_diaria_micro' => 100_000_000]);

        foreach (DB::table('buildings')->selectRaw('colony_id, SUM(level) as total')->groupBy('colony_id')->get() as $linha) {
            DB::table('colonies')->where('id', $linha->colony_id)->update(['niveis_de_obra_pagos' => (int) $linha->total]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('producao_por_hora');

        Schema::table('contratos_de_rota', function (Blueprint $table) {
            $table->dropColumn('risco_perda_bps');
        });

        Schema::table('milestone_settings', function (Blueprint $table) {
            $table->dropColumn('xp_acordo_teto_diario');
        });

        Schema::table('colonies', function (Blueprint $table) {
            $table->dropColumn('niveis_de_obra_pagos');
        });

        Schema::table('planetas_npc', function (Blueprint $table) {
            $table->dropColumn('demanda_diaria_micro');
        });
    }
};
