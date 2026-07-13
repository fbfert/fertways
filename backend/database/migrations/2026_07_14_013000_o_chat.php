<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O Sistema de Mensagens do §10 (docs/decisoes.md D-77).
 *
 * O GDD publica os 5 canais (§10.1), a moderação (§10.2), o armazenamento (§10.3) e a retenção
 * (seção 08): global e regional 180 dias, vizinhança 90, privadas indefinido no lançamento. As
 * arbitragens do usuário (2026-07-13): polling em vez de Reverb (4 GB de RAM não pagam um daemon),
 * as 5 regiões = 4 quadrantes + Núcleo, filtro que BLOQUEIA o envio, e silêncio só por pena — do
 * Ministério (a pena `silencio` do D-44, inerte até hoje) ou do admin, nunca da máquina sozinha.
 *
 * O canal de FEDERAÇÃO não nasce: federações não existem (D-44). A coluna `channel` já o comporta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // 'global' | 'regiao:nordeste'… | 'vizinhanca' | 'privada'
            $table->string('channel', 24);
            // Só na privada: o outro lado da conversa.
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            /*
             * Só na vizinhança: ONDE o autor estava ao falar. O canal de vizinhança não é uma sala,
             * é um RAIO (§10.1: "escopo limitado por distância") — cada leitor vê as mensagens
             * ditas a até N slots da colônia DELE. A posição fica congelada no envio: quem falou
             * da colônia velha falou de lá, e realocar não reescreve onde a voz soou.
             */
            $table->smallInteger('x')->nullable();
            $table->smallInteger('y')->nullable();
            $table->string('body', 500);
            $table->timestamp('created_at');

            $table->index(['channel', 'id']);
            $table->index(['recipient_user_id', 'id']);
            $table->index(['user_id', 'id']);
        });

        // O bloqueio do "MVP social" (seção 15): quem bloqueou não vê nem recebe.
        Schema::create('chat_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('blocked_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at');

            $table->unique(['user_id', 'blocked_user_id']);
        });

        /*
         * A reincidência que o filtro deixa para o moderador ver (§10.2). O filtro bloqueia e
         * AVISA o autor (arbitragem do usuário) — mas quem insiste vira padrão, e padrão é o que
         * o admin precisa enxergar antes de aplicar um silêncio. A máquina conta; a pessoa pune.
         */
        Schema::create('chat_filter_hits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('termo', 60);
            $table->string('channel', 24);
            $table->timestamp('created_at');

            $table->index(['user_id', 'id']);
        });

        // Os números do operador (padrão da casa). A lista de termos vedados TAMBÉM é dele:
        // o §03 diz que o nickname passa "pelo mesmo filtro automático de termos do chat".
        Schema::create('chat_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('vizinhanca_raio_slots')->default(10);
            $table->json('termos_vedados')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_settings');
        Schema::dropIfExists('chat_filter_hits');
        Schema::dropIfExists('chat_blocks');
        Schema::dropIfExists('chat_messages');
    }
};
