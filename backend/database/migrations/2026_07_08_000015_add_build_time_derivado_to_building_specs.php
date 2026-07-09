<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Distingue tempo publicado no GDD de tempo derivado fora dele.
     *
     * O GDD não publica tempo de construção para Central de Transportes, Destilaria,
     * Depósito de Zona Neutra e os veículos (D-10). Quando um tempo-base for definido
     * pelo time de design, os níveis saem de half-up(base × 1,5^(n-1)) e ficam marcados
     * aqui. Sem esta coluna, um tempo inventado seria indistinguível de um tempo do GDD.
     */
    public function up(): void
    {
        Schema::table('building_specs', function (Blueprint $table) {
            $table->boolean('build_time_derivado')->default(false)->after('build_time_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('building_specs', function (Blueprint $table) {
            $table->dropColumn('build_time_derivado');
        });
    }
};
