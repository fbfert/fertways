<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-290: o Cemitério de Robôs ganha o que mostrar. Cada unidade que morre em combate é enterrada na
 * zona onde caiu.
 *
 * Até aqui o motor apagava a unidade que zerava e não guardava nada. O relatório da batalha (D-275)
 * só sabe quantas havia no começo e quantas sobraram, e um reforço no meio embaralha a conta. Uma
 * linha por morto não embaralha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('robos_enterrados', function (Blueprint $t) {
            $t->id();
            $t->foreignId('zone_id')->constrained('neutral_zones')->cascadeOnDelete();
            $t->foreignId('combat_id')->nullable()->constrained('combats')->nullOnDelete();
            // De quem era. Nulo se a colônia deixou de existir: o morto continua lá.
            $t->foreignId('colony_id')->nullable()->constrained('colonies')->nullOnDelete();
            $t->string('type', 40);
            $t->unsignedTinyInteger('level')->default(1);
            $t->timestamp('morta_em')->useCurrent();

            $t->index(['zone_id', 'morta_em'], 'enterrados_zona_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('robos_enterrados');
    }
};
