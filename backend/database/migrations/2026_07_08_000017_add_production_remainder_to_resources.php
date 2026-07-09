<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Resto fracionário da produção, em unidades de 1/3600 (um segundo de produção horária).
     *
     * O tick roda a cada minuto. Sem carregar o resto, uma produção de 100/h renderia
     * floor(100 × 60 / 3600) = 1 unidade por minuto em vez de 1,67 — uma perda de 40% da
     * economia do jogo, silenciosa e permanente.
     *
     * Com o resto: numerador = taxa × segundos; soma-se numerador div 3600 ao estoque e
     * guarda-se numerador mod 3600 aqui. Exato, em inteiros, sem float.
     */
    public function up(): void
    {
        Schema::table('resources', function (Blueprint $table) {
            $table->unsignedInteger('production_remainder')->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('resources', function (Blueprint $table) {
            $table->dropColumn('production_remainder');
        });
    }
};
