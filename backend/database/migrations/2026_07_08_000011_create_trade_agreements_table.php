<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O Acordo de Troca é OPCIONAL. Sem ele o comércio informal roda com risco real de
     * calote (GDD §8.2), e o lesado abre denúncia no Ministério das Reputações. Com ele,
     * há garantia. É esse contraste que dá sentido ao índice de confiança comercial.
     */
    public function up(): void
    {
        Schema::create('trade_agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('colony_a_id')->constrained('colonies')->cascadeOnDelete();
            $table->foreignId('colony_b_id')->constrained('colonies')->cascadeOnDelete();
            $table->json('terms_json');
            $table->enum('status', ['proposto', 'aceito', 'executado', 'quebrado', 'cancelado'])->default('proposto');
            $table->dateTime('executed_at')->nullable();
            $table->timestamps();
            $table->index(['colony_a_id', 'colony_b_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_agreements');
    }
};
