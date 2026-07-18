<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quantos itens cabem na fila de construção — da colônia, e da zona neutra (docs/decisoes.md
 * D-111). Linha única, mesmo molde de `transport_settings`/`war_settings`.
 *
 * **A colônia já tinha fila** (D-13) — a novidade é só tirar o número de dentro do PHP
 * (`BuildQueue::vagasDe()` tinha 2/1 cravados no código) e pôr no painel do admin, preservando a
 * regra de onboarding que já existia (fila dupla nos 5 primeiros dias de conta, fila única depois).
 *
 * **A zona neutra não tinha fila — tinha um canteiro só** (D-67: "uma obra por vez", checada por
 * `obraEmCurso()`, uma existência booleana, não uma contagem contra um teto). `zona_vagas` é o que
 * torna isso um teto de verdade, admin-editável: default 1, que é EXATAMENTE o comportamento de
 * hoje — subir o número é o que muda alguma coisa. Diferente da colônia, a zona não tem "queued
 * esperando o antecessor terminar": o material só entra no canteiro por entrega física, então cada
 * obra que tem material pronto começa na hora — o teto aqui é quantas podem estar em curso ao
 * mesmo tempo, não uma fila sequencial.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fila_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('colonia_vagas_novato')->default(2);
            $table->unsignedTinyInteger('colonia_vagas_padrao')->default(1);
            $table->unsignedTinyInteger('zona_vagas')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fila_settings');
    }
};
