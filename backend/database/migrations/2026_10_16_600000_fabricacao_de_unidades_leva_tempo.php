<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fabricar unidade leva tempo (D-272): 2 minutos × o nível de cada unidade, uma a uma, numa linha de
 * montagem por Quartel. Parâmetro do operador; zero volta ao instantâneo do D-66.
 *
 * A unidade em fabricação já existe na tabela, com `status = em_fabricacao` e `arrives_at` = quando
 * fica pronta. Toda consulta de tropa do jogo filtra `status = casa`, então ela não ataca, não defende
 * e não marcha até sair da linha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('war_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('fabricacao_minutos_por_nivel')->default(2);
        });
    }

    public function down(): void
    {
        // A linha de montagem some com a coluna: o que estava nela sai pronto, como no D-66.
        DB::table('units')->where('status', 'em_fabricacao')->update(['status' => 'casa', 'arrives_at' => null]);

        Schema::table('war_settings', function (Blueprint $table) {
            $table->dropColumn('fabricacao_minutos_por_nivel');
        });
    }
};
