<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O apelido do veículo (pedido do usuário, 2026-07-16): a Frota já tinha a placa no banco desde o
 * D-60, mas nunca a mostrava na tela — e não havia como o colono chamar um veículo por nome
 * nenhum, só pelo tipo ("Furgão de Comércio nível 2"). Mesmo desenho do nome da zona (D-67): uma
 * coluna nullable, sem filtro de conteúdo — o dono escolhe, o dono lida com o que escolheu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('nickname', 60)->nullable()->after('plate');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('nickname');
        });
    }
};
