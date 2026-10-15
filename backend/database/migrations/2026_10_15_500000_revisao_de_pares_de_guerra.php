<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A revisão humana dos pares de federação que guerreiam de novo e de novo (decisão 11, D-261).
 *
 * **Append-only**: cada revisão é uma linha, e a vigente é a mais recente. Um operador que muda de
 * ideia deixa rastro do que pensava antes — é o mesmo princípio do ledger, aplicado a julgamento.
 * O par é guardado em ordem (`federacao_a_id` < `federacao_b_id`): quem declarou a quem não muda que
 * são as mesmas duas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('federation_war_pair_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('federacao_a_id')->constrained('federations')->cascadeOnDelete();
            $table->foreignId('federacao_b_id')->constrained('federations')->cascadeOnDelete();
            $table->string('veredito', 20);   // legitimo | suspeito
            $table->text('nota');
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['federacao_a_id', 'federacao_b_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('federation_war_pair_reviews');
    }
};
