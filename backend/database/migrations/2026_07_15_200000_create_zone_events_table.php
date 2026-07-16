<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O Histórico da zona (docs/decisoes.md D-86): mudanças de posse — ocupação, abandono por
 * manutenção não paga, conquista por guerra. Só o dono vê (mesma régua do D-74/D-84).
 *
 * Separado do `ledger`, de propósito: o ledger é sempre de UMA colônia (`colony_id` obrigatório,
 * D-56), e o abandono não tem colônia nenhuma no fim — a zona fica sem dono. Um evento aqui pode
 * ter `colony_id` nulo por isso mesmo. `type` não entra no `Ledger::TIPOS` porque isto não é
 * dinheiro nem recurso: é só "o que aconteceu".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zone_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('zone_id')->constrained('neutral_zones')->cascadeOnDelete();
            $table->string('type', 32);   // ocupada | abandonada | conquistada
            // Quem fez o ato: o novo dono na ocupação/conquista, o dono que perdeu no abandono.
            // Nulo não acontece hoje, mas a coluna não impede — é histórico, não contabilidade.
            $table->foreignId('colony_id')->nullable()->constrained('colonies')->nullOnDelete();
            $table->json('meta')->nullable();   // ex.: combat_id na conquista
            $table->timestamp('created_at');

            $table->index(['zone_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zone_events');
    }
};
