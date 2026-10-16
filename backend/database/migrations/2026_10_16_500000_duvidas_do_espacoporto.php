<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A fila de dúvidas do Espaçoporto, o ato do Atendente (§14.2; D-270).
 *
 * *"Responde a fluxos predefinidos e encaminha dúvidas; não altera reservas, fretes ou rotas."* A
 * dúvida é do colono; a resposta, do Atendente; o "ajudou", de novo do colono — e é ele que paga o
 * bônus. Encaminhar leva a dúvida à aba Feedback do painel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('espacoporto_duvidas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pergunta', 500);
            $table->text('resposta')->nullable();
            $table->foreignId('atendente_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('respondida_em')->nullable();
            $table->timestamp('encaminhada_em')->nullable();
            // Nulo: não avaliada. A avaliação é uma só.
            $table->boolean('ajudou')->nullable();
            $table->timestamp('avaliada_em')->nullable();
            $table->timestamps();

            $table->index(['respondida_em', 'encaminhada_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('espacoporto_duvidas');
    }
};
