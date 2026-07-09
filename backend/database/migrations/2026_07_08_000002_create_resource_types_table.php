<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_types', function (Blueprint $table) {
            $table->string('code', 40)->primary();
            $table->string('nome', 60);

            // Classe tributária (GDD §8.3): primário 3%, secundário 2%, raro 1%.
            // As classes do GDD não cobrem Metal Bruto nem os 8 minerais eletrônicos;
            // classificação decidida pelo usuário em 2026-07-08 e registrada em docs/decisoes.md.
            $table->enum('tax_class', ['primario', 'secundario', 'raro']);

            // Alíquota em basis points (300 = 3,00%). Inteiro: nunca float em tributo.
            $table->unsignedSmallInteger('tax_bps');

            // Preço-base do Mercado Central em micro-Fert$ (GDD §22.2/§22.3).
            // 1 Fert$ = 1_000_000 µF$. Energia = 0,0033 Fert$ = 3300 µF$.
            // Centavo não representa quatro casas decimais — daí a micro-unidade.
            $table->unsignedBigInteger('preco_base_micro')->nullable();

            // Produção máx/h de referência do GDD §22.2, usada na fórmula de preço primário.
            $table->unsignedInteger('producao_max_hora')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_types');
    }
};
