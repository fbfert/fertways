<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A marca de conta da equipe (D-267).
 *
 * Todo humano do servidor é, hoje, o próprio usuário testando (D-265). Antes do primeiro teste com
 * pessoas de verdade, essas contas precisam ficar fora da medida de retenção — senão o funil mistura
 * testador com dono do jogo. A marca é um booleano, e não um domínio de e-mail como o dos bots: as
 * contas do usuário têm e-mails reais e variados.
 *
 * Nasce falso para todo mundo; quem marca é `fertways:equipe` (ou o painel do jogador).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('equipe')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('equipe');
        });
    }
};
