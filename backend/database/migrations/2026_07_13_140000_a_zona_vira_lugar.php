<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A zona neutra vira lugar (GDD §17.4; docs/decisoes.md D-67).
 *
 * **Conserta um buraco que o D-66 abriu.** O motor de combate lê `wall_level`, `watchtower_level`,
 * `bastion_level` e `shelter_level` — e **nada no jogo os erguia**. Nasciam em zero e nunca saíam de
 * zero: o bônus do §27.3 era sempre 0%, a Torre nunca detectava um Infiltrador, e o Depósito ficava
 * preso no nível 1, sem como proteger mais estoque do saque.
 *
 * NASCE `zone_materials` — **o canteiro de obras**. As obras da zona exigem **entrega física**
 * (decisão do usuário): despacha-se um veículo com Metal Bruto e Ligas, e o material entra aqui. A
 * obra só começa quando o canteiro tem o custo inteiro; a sobra fica para a próxima.
 *
 * ⚠️ Isso **contradiz a ocupação**, que debita da colônia e ergue o Posto de Comando sem veículo
 * nenhum (D-52). Deliberado: a ocupação é o ato de **chegar**; as obras são o ato de **investir**.
 * E faz o **cerco impedir fortificar sob sítio** — nada entra nem sai (D-66). Não foi planejado, e é bom.
 *
 * NASCE `zone_build_queue` — a fila de obras da zona, separada da fila da colônia. Uma obra por vez.
 *
 * NASCE `neutral_zones.refined_amount` — o que a **Refinaria de Campo** já converteu. Ela é a
 * **primeira construção do jogo que CONVERTE**: todas as outras só produzem a taxa fixa por hora, sem
 * insumo. 2 primários viram 1 secundário, por distrito (D-67).
 *
 * Idempotente, como as demais (lição do D-59).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('neutral_zones', function (Blueprint $t) {
            // As três estruturas novas do §17.4. As de defesa já vieram no D-66.
            foreach (['refinery_level', 'parking_level', 'cemetery_level'] as $c) {
                if (! Schema::hasColumn('neutral_zones', $c)) {
                    $t->unsignedTinyInteger($c)->default(0)->after('shelter_level');
                }
            }

            /*
             * O produto da Refinaria. Guardado à parte do `deposit_amount` porque são **recursos
             * diferentes** — o minério primário e o secundário em que ele vira. Os dois ocupam o
             * mesmo Depósito e contam juntos contra a capacidade, que é o que decide o que está
             * protegido do saque (D-66).
             */
            if (! Schema::hasColumn('neutral_zones', 'refined_amount')) {
                $t->unsignedBigInteger('refined_amount')->default(0)->after('deposit_amount');
            }

            // O relógio da Refinaria, separado do da extração: ela converte por delta próprio.
            if (! Schema::hasColumn('neutral_zones', 'last_refine_at')) {
                $t->timestamp('last_refine_at')->nullable()->after('last_extraction_at');
            }
        });

        if (! Schema::hasTable('zone_materials')) {
            Schema::create('zone_materials', function (Blueprint $t) {
                $t->id();
                $t->foreignId('zone_id')->constrained('neutral_zones')->cascadeOnDelete();
                $t->string('resource_type', 32);
                $t->unsignedBigInteger('amount')->default(0);
                $t->timestamps();

                // Um material, uma linha, por zona. O `increment` da entrega depende disto.
                $t->unique(['zone_id', 'resource_type']);
            });
        }

        if (! Schema::hasTable('zone_build_queue')) {
            Schema::create('zone_build_queue', function (Blueprint $t) {
                $t->id();
                $t->foreignId('zone_id')->constrained('neutral_zones')->cascadeOnDelete();
                $t->string('structure', 32);
                $t->unsignedTinyInteger('target_level');
                $t->timestamp('finishes_at');
                $t->timestamps();

                // Uma obra por vez na zona: o índice serve ao tick, que colhe as que venceram.
                $t->index('finishes_at');
                $t->index('zone_id');
            });
        }

        Schema::table('war_settings', function (Blueprint $t) {
            /*
             * O aviso antecipado da Torre de Vigia (§17.4: "cada nível aumenta o tempo de antecipação
             * antes do ataque acontecer"). 10 min por nível — que é **uma rodada de combate** (§27.5),
             * de modo que a unidade de medida já existia no jogo. Parâmetro do operador (D-67).
             */
            if (! Schema::hasColumn('war_settings', 'torre_aviso_minutos_por_nivel')) {
                $t->unsignedInteger('torre_aviso_minutos_por_nivel')->default(10);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zone_build_queue');
        Schema::dropIfExists('zone_materials');

        Schema::table('war_settings', function (Blueprint $t) {
            if (Schema::hasColumn('war_settings', 'torre_aviso_minutos_por_nivel')) {
                $t->dropColumn('torre_aviso_minutos_por_nivel');
            }
        });

        Schema::table('neutral_zones', function (Blueprint $t) {
            foreach (['refinery_level', 'parking_level', 'cemetery_level',
                      'refined_amount', 'last_refine_at'] as $c) {
                if (Schema::hasColumn('neutral_zones', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
