<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('colonies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->dateTime('founded_at')->useCurrent();
            $table->string('milestone', 40)->default('colonizacao_inicial');

            // Saldo em micro-Fert$. GDD: "Todo colono recebe 50 Fert$ de saldo inicial".
            // 50 Fert$ = 50_000_000 µF$.
            $table->bigInteger('fert_micro')->default(50_000_000);

            // O tick é uma transação por colônia, não por linha de recurso: um único delta
            // temporal serve para toda a produção da colônia. Ter um last_tick_at por recurso
            // permitiria estados inconsistentes entre recursos da mesma colônia.
            $table->dateTime('last_tick_at')->useCurrent();

            $table->timestamps();
            $table->index('last_tick_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('colonies');
    }
};
