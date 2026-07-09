<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GDD (precedência, seção 0): "Mercado usa escrow; comércio informal mantém risco
     * com Acordo de Troca opcional." Sem as colunas de escrow o Mercado Central não teria
     * garantia nenhuma e seria indistinguível do canal informal — que é justamente o
     * contraste que o jogo quer criar.
     */
    public function up(): void
    {
        Schema::create('market_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('colony_id')->constrained()->cascadeOnDelete();
            $table->string('resource_type', 40);
            $table->foreign('resource_type')->references('code')->on('resource_types');

            $table->enum('side', ['buy', 'sell']);

            // Preço unitário em micro-Fert$ (1 Fert$ = 1_000_000 µF$).
            $table->unsignedBigInteger('price_micro');
            $table->unsignedBigInteger('qty');

            $table->unsignedBigInteger('escrow_resource_qty')->default(0);
            $table->unsignedBigInteger('escrow_fert_micro')->default(0);

            $table->enum('status', ['aberta', 'parcial', 'executada', 'cancelada'])->default('aberta');

            $table->timestamps();
            $table->index(['resource_type', 'side', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_orders');
    }
};
