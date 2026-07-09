<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Distingue preço publicado no GDD de preço derivado por fórmula do GDD.
     *
     * Hoje só o Metal Bruto é derivado: nenhuma tabela de §22 o precifica, e o preço sai
     * da fórmula de §24.8. Sem esta coluna, um valor derivado seria indistinguível de um
     * valor canônico, e uma revisão futura do GDD poderia sobrescrever o errado.
     */
    public function up(): void
    {
        Schema::table('resource_types', function (Blueprint $table) {
            $table->boolean('preco_base_derivado')->default(false)->after('preco_base_micro');
        });
    }

    public function down(): void
    {
        Schema::table('resource_types', function (Blueprint $table) {
            $table->dropColumn('preco_base_derivado');
        });
    }
};
