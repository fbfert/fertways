<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cerco de colônia (A2.10 / D-201, D-202 — decisões 13, 14 e 15).
 *
 * ## ⚠️ O §01 fica revogado em guerra declarada
 *
 * O GDD §01 declara o slot principal **inviolável**, e o catálogo de funções já apontava a
 * contradição na nota da Torre de Defesa: *"não há o que defender aqui"*. A decisão 13 resolve:
 * **inviolável em paz, saqueável no excedente do Depósito durante guerra federativa declarada.**
 *
 * ## `zone_id` nulável, e não uma tabela nova
 *
 * `combats` já tem `defender_colony_id` — para um ataque de zona ele guarda o dono dela. Para um
 * ataque de colônia ele guarda o alvo, e `zone_id` fica nulo. **O alvo é colônia quando não há zona**,
 * e isso permite reusar o cerco inteiro — marcha, três rodadas, forças, reforços — sem duplicar nada.
 *
 * Uma tabela de cerco separada obrigaria a manter dois resolvedores em sincronia, e o primeiro a
 * divergir criaria duas regras para a mesma guerra.
 *
 * ## A Torre de Defesa finalmente vale alguma coisa
 *
 * Ela reduz **quanto o saque leva** (decisão 14). Hoje o efeito dela é `'nenhum'` — e **11 colônias
 * já a construíram**, defendendo o que ninguém podia atacar.
 *
 * ⚠️ **O teto de redução existe para a guerra continuar valendo a pena.** Sem ele, uma Torre no
 * nível máximo zeraria o espólio, e atacar viraria puro custo — a mecânica se desligaria sozinha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('combats', function (Blueprint $table) {
            // Nulo = o alvo é a colônia de `defender_colony_id`. Ver o docblock.
            $table->foreignId('zone_id')->nullable()->change();
        });

        Schema::table('war_settings', function (Blueprint $table) {
            /** Quanto cada nível da Torre de Defesa corta do espólio, em pontos-base. */
            $table->unsignedInteger('torre_defesa_reducao_bps_por_nivel')->default(1_000);

            /*
             * O teto da redução. 7000 = a Torre nunca corta mais de 70% do saque, por mais alta que
             * seja. Ver o docblock: sem teto, a mecânica se desliga sozinha.
             */
            $table->unsignedInteger('torre_defesa_reducao_teto_bps')->default(7_000);
        });
    }

    public function down(): void
    {
        Schema::table('war_settings', function (Blueprint $table) {
            $table->dropColumn(['torre_defesa_reducao_bps_por_nivel', 'torre_defesa_reducao_teto_bps']);
        });

        Schema::table('combats', function (Blueprint $table) {
            $table->foreignId('zone_id')->nullable(false)->change();
        });
    }
};
