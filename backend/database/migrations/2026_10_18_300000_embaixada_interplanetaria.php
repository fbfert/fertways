<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-289: a Embaixada Interplanetária, slot 9 da Capital — a credencial comercial por planeta.
 *
 * Os números de partida são meus e moram no painel (Transportes → Espaçoporto): 40 F$ por planeta,
 * 50% a menos de imposto da carga, por 30 dias.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planetas_npc', function (Blueprint $t) {
            $t->unsignedBigInteger('credencial_preco_micro')->nullable();
            $t->unsignedSmallInteger('credencial_desconto_bps')->default(5000);
        });

        Schema::table('espacoporto_settings', function (Blueprint $t) {
            $t->unsignedSmallInteger('credencial_dias')->default(30);
        });

        DB::table('planetas_npc')->update(['credencial_preco_micro' => 40_000_000]);

        Schema::create('credenciais_comerciais', function (Blueprint $t) {
            $t->id();
            $t->foreignId('colony_id')->constrained('colonies')->cascadeOnDelete();
            $t->foreignId('planeta_npc_id')->constrained('planetas_npc')->cascadeOnDelete();
            // Default explícito: sem ele o MariaDB 10.5 poria ON UPDATE CURRENT_TIMESTAMP na primeira coluna.
            $t->timestamp('valida_ate')->useCurrent();
            $t->unsignedBigInteger('preco_pago_micro');
            // Travado na emissão: mudar o desconto no painel não mexe numa credencial já paga.
            $t->unsignedSmallInteger('desconto_bps');
            $t->timestamps();

            $t->index(['colony_id', 'planeta_npc_id', 'valida_ate'], 'credenciais_colonia_planeta_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credenciais_comerciais');

        Schema::table('espacoporto_settings', function (Blueprint $t) {
            $t->dropColumn('credencial_dias');
        });

        Schema::table('planetas_npc', function (Blueprint $t) {
            $t->dropColumn(['credencial_preco_micro', 'credencial_desconto_bps']);
        });
    }
};
