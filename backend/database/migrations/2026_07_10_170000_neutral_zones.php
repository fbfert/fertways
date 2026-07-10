<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * As zonas neutras entram no mapa do D-51 (docs/decisoes.md D-52, Fatia 1).
 *
 * A tabela nasceu antes do D-51 (2026_07_08) com um rótulo opaco em `coordinates` ('K-14') e a
 * proteção de 8 dias. Agora que o mapa tem coordenadas de verdade, cada zona mora numa **célula**
 * dos 4 distritos dos cantos: `coordinates` vira `x,y` com sinal, e entram o distrito, o mineral e
 * o estado da ocupação e do Depósito. A tabela está vazia em toda parte, então a troca é limpa.
 *
 * O `status` e a proteção (`protected_until`) ficam: são o mecanismo de guerra da Fatia 2, e o tick
 * já os usa. A extração da Fatia 1 não depende deles — lê-se de `owner_colony_id` + `productive_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('neutral_zones', function (Blueprint $t) {
            $t->dropUnique(['coordinates']);
            $t->dropColumn('coordinates');
        });

        Schema::table('neutral_zones', function (Blueprint $t) {
            // A célula no mapa do D-51 (coordenadas com sinal). Única: uma zona por célula.
            $t->tinyInteger('x')->after('id');
            $t->tinyInteger('y')->after('x');
            $t->unique(['x', 'y']);

            $t->string('district', 12)->after('y');   // nordeste | sudeste | sudoeste | noroeste
            $t->string('mineral', 32)->after('district'); // o código do recurso extraído (§24.4)
            $t->unsignedTinyInteger('level')->default(1)->after('mineral'); // Fatia 1: sempre 1

            // Ocupação (D-52).
            $t->unsignedTinyInteger('command_post_level')->default(0)->after('owner_colony_id');
            $t->unsignedSmallInteger('garrison')->default(0)->after('command_post_level');
            // Quando a extração pode começar: ocupação + ergue o Posto + tempo de ocupação.
            $t->timestamp('productive_at')->nullable()->after('occupied_at');

            // O Depósito de Zona Neutra (§19.6, 10 níveis). A capacidade sai do nível pela curva.
            $t->unsignedTinyInteger('deposit_level')->default(1)->after('protected_until');
            $t->unsignedInteger('deposit_amount')->default(0)->after('deposit_level');
            // Marca de água do tick: até quando a extração já foi creditada.
            $t->timestamp('last_extraction_at')->nullable()->after('deposit_amount');
        });
    }

    public function down(): void
    {
        Schema::table('neutral_zones', function (Blueprint $t) {
            $t->dropUnique(['x', 'y']);
            $t->dropColumn([
                'x', 'y', 'district', 'mineral', 'level',
                'command_post_level', 'garrison', 'productive_at',
                'deposit_level', 'deposit_amount', 'last_extraction_at',
            ]);
            $t->string('coordinates', 20)->nullable()->unique();
        });
    }
};
