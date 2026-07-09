<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §24.5 — as três receitas de Componentes Eletrônicos da Oficina, com insumo por unidade.
     * Semeadas do GDD por tools/extract_gdd_specs.py. Imutáveis em runtime.
     */
    public function up(): void
    {
        Schema::create('component_recipes', function (Blueprint $table) {
            $table->string('code', 20)->primary();   // basica, intermediaria, avancada
            $table->string('nome', 30);
            $table->string('contexto', 160);         // texto cru do parêntese do GDD
            $table->json('insumos_json');            // {"estanho":8,"cobre":8,...} por unidade
        });

        Schema::table('buildings', function (Blueprint $table) {
            // Só a Oficina usa. NULL = receita padrão (básica).
            $table->string('recipe', 20)->nullable()->after('level');
        });
    }

    public function down(): void
    {
        Schema::table('buildings', fn (Blueprint $t) => $t->dropColumn('recipe'));
        Schema::dropIfExists('component_recipes');
    }
};
