<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buildings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('colony_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->unsignedTinyInteger('level')->default(0);

            // O GDD não define quantas posições de construção o slot principal tem.
            // "Slots da Capital" (§2.1) são os 20 slots institucionais do governo NPC,
            // e "slots por minuto" é velocidade de veículo. Decidido em 2026-07-08:
            // uma construção por tipo, sem posição fixa. Não há coluna `slot`.
            $table->unique(['colony_id', 'type']);

            $table->dateTime('upgrade_started_at')->nullable();
            $table->dateTime('upgrade_finish_at')->nullable();

            $table->timestamps();
            $table->index('upgrade_finish_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buildings');
    }
};
