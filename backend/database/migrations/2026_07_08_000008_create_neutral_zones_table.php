<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('neutral_zones', function (Blueprint $table) {
            $table->id();
            $table->string('coordinates', 20)->unique();
            $table->foreignId('owner_colony_id')->nullable()->constrained('colonies')->nullOnDelete();
            $table->enum('status', ['livre', 'ocupada', 'protegida', 'vulneravel'])->default('livre');
            $table->dateTime('occupied_at')->nullable();

            // GDD, tabela de precedência da seção 0: "zona neutra elegível protegida por
            // 8 dias completos". Valor do GDD, não arbitrado: occupied_at + 8 dias.
            // O slot principal é inviolável sempre e não depende desta coluna.
            $table->dateTime('protected_until')->nullable();

            $table->timestamps();
            $table->index('protected_until');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('neutral_zones');
    }
};
