<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A janela que o resumo mostrou da última vez (pedido do usuário, 2026-08-05).
 *
 * O botão *"Ver o que aconteceu desde sua última visita"* não abria nada, e a causa não era a tela:
 * `resumo_visto_em` avança ao FECHAR o resumo, então, um minuto depois de fechar, "desde a última
 * visita" é um intervalo de um minuto — e o §5.1 ainda barra por piso de uma hora. O botão pedia uma
 * janela que já tinha sido consumida.
 *
 * Guardar o marcador ANTERIOR resolve sem tocar no §5.1: o piso continua governando o resumo
 * automático, e a reabertura explícita mostra **de novo a janela que o jogador acabou de ver**, que
 * é o que o botão sempre prometeu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nulo = nunca houve resumo anterior (conta nova, ou quem nunca fechou um). Nesse caso
            // não há o que reabrir, e o servidor diz isso em vez de inventar uma janela.
            $table->timestamp('resumo_anterior_em')->nullable()->after('resumo_visto_em');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('resumo_anterior_em');
        });
    }
};
