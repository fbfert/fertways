<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O Marco do §03/§05 sai do congelador (docs/decisoes.md D-75).
 *
 * O GDD nomeia os oito marcos (1 Sobrevivente … 100 Lenda de Fertways), publica os desbloqueios de
 * cada um, manda as missões pagarem "XP" (§06) — e **nunca publica a fórmula**. O usuário arbitrou
 * (2026-07-13): XP por atos, em ledger próprio; curva 50×N²; posse preservada com XP retroativo;
 * valores por ato no painel do operador.
 *
 * ⚠️ `colonies.milestone` (o varchar congelado em `colonizacao_inicial` desde o D-38) **fica onde
 * está, intocado**. O Marco de verdade deriva de `colonies.xp` pela curva — mexer na coluna velha
 * não compra nada e arrisca quem a lê. Um dia ela sai; hoje ela só dorme.
 */
return new class extends Migration
{
    public function up(): void
    {
        // O ledger do XP: append-only, como o de recursos e o de auditoria. XP não nasce sem
        // história — é a MESMA regra que fez o Ledger existir (D-61).
        Schema::create('xp_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('colony_id')->constrained('colonies')->cascadeOnDelete();
            $table->string('acao', 30);
            $table->unsignedInteger('xp');
            $table->string('ref', 80)->nullable();
            $table->timestamp('created_at');

            $table->index(['colony_id', 'acao']);
        });

        Schema::table('colonies', function (Blueprint $table) {
            // O cache do somatório. A verdade são as entries; isto poupa um SUM por request de HUD.
            $table->unsignedBigInteger('xp')->default(0);
        });

        // Os cinco valores do operador (padrão da casa: D-60, D-66, D-73). Defaults no BANCO.
        Schema::create('milestone_settings', function (Blueprint $table) {
            $table->id();
            // Por nível concluído de obra — a fundação (5 essenciais nível 1) vale 5 destes.
            $table->unsignedInteger('xp_obra_por_nivel')->default(100);
            $table->unsignedInteger('xp_zona_ocupada')->default(500);
            $table->unsignedInteger('xp_combate_vencido')->default(400);
            // Só acordos acima do piso do D-43 — o XP herda o anti-farm da reputação.
            $table->unsignedInteger('xp_acordo_executado')->default(150);
            $table->unsignedInteger('xp_mercado_executado')->default(50);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('milestone_settings');
        Schema::table('colonies', fn (Blueprint $table) => $table->dropColumn('xp'));
        Schema::dropIfExists('xp_entries');
    }
};
