<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decisão 7 da guerra federativa: a tropa consome operadores da colônia (D-260).
 *
 * O usuário decidiu "sim, do bolo da colônia" (D-193) e a pergunta do GDD_GUERRA_FEDERATIVA §6 —
 * *"em que proporção"* — ficou sem número. **1 colono por unidade em campo** é arbitragem minha, a
 * mais simples que não inventa escala: uma unidade, uma pessoa. Fica em `war_settings`, ao lado dos
 * outros números da guerra, editável no painel; zero desliga a regra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('war_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('operadores_por_unidade')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('war_settings', function (Blueprint $table) {
            $table->dropColumn('operadores_por_unidade');
        });
    }
};
