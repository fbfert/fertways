<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O mural de ofertas entre colonos (D-58).
 *
 * Uma **oferta aberta** é um Acordo de Troca sem contraparte: `colony_b_id` nulo e status
 * `proposto`. O primeiro que aceitar preenche a coluna e vira a contraparte.
 *
 * Não há status novo, de propósito: `proposto` + `colony_b_id IS NULL` já diz tudo, e o
 * `ExpirarAcordos` **já** trata `proposto` vencido como `cancelado` sem punir ninguém — que é
 * exatamente o que deve acontecer com uma oferta que ninguém quis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trade_agreements', function (Blueprint $table) {
            $table->foreignId('colony_b_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // As ofertas abertas não têm contraparte para inventar: some-se com elas antes de voltar a
        // exigir a coluna, senão a alteração falha no primeiro registro nulo.
        DB::table('trade_agreements')->whereNull('colony_b_id')->delete();

        Schema::table('trade_agreements', function (Blueprint $table) {
            $table->foreignId('colony_b_id')->nullable(false)->change();
        });
    }
};
