<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O limite de itens simultâneos NÃO é coluna: é 2 nos primeiros 5 dias completos de
     * conta e 1 a partir do 6º (GDD, onboarding). Deriva de users.created_at no momento
     * do enfileiramento. Persistir o limite criaria um estado que envelhece errado.
     */
    public function up(): void
    {
        Schema::create('build_queue', function (Blueprint $table) {
            $table->id();
            $table->foreignId('colony_id')->constrained()->cascadeOnDelete();
            $table->foreignId('building_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('target_level');
            $table->unsignedTinyInteger('position');

            // GDD §4.1: "Upgrade em fila mantém o custo cotado no momento da confirmação
            // transacional; nova tabela vale para ações iniciadas após a publicação."
            // Sem congelar aqui, um rebalanceamento recotaria a fila retroativamente.
            $table->json('quoted_cost_json');

            // GDD §24.7: as cinco essenciais até o nível 3 são custeadas pelo Governo Central.
            // O custo aparece na interface; o ledger registra subsídio de 100%.
            $table->boolean('subsidized')->default(false);

            $table->dateTime('enqueued_at')->useCurrent();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('finishes_at')->nullable();
            $table->enum('status', ['queued', 'building', 'done', 'cancelled'])->default('queued');

            $table->timestamps();
            $table->unique(['colony_id', 'position']);
            $table->index(['status', 'finishes_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('build_queue');
    }
};
