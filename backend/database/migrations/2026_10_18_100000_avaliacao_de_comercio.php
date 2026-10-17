<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-287: a avaliação de 0 a 5 estrelas do comércio entre colonos (GDD §8.4, §26.3, §26.4) e o sinal
 * de contas vinculadas que ela pede.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avaliacoes_de_comercio', function (Blueprint $t) {
            $t->id();
            $t->foreignId('trade_agreement_id')->constrained('trade_agreements')->cascadeOnDelete();
            $t->foreignId('autor_user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('avaliado_user_id')->constrained('users')->cascadeOnDelete();
            $t->unsignedTinyInteger('estrelas');
            $t->string('comentario', 280)->nullable();
            // §26 "frequência": uma avaliação COM PESO por par de contas a cada 24 h. As outras ficam
            // públicas, mas não mexem no índice.
            $t->boolean('conta')->default(true);
            // §26.4: entre contas possivelmente vinculadas, o efeito congela até revisão.
            $t->boolean('suspeita')->default(false);
            // O que a avaliação de fato moveu no índice (o teto 0–1000 pode engolir parte), para o
            // estorno devolver exatamente isso.
            $t->smallInteger('delta_aplicado')->default(0);
            $t->timestamp('anulada_em')->nullable();
            $t->string('anulada_motivo', 120)->nullable();
            $t->timestamps();

            $t->unique(['trade_agreement_id', 'autor_user_id']);
            $t->index(['avaliado_user_id', 'anulada_em']);
            $t->index(['autor_user_id', 'avaliado_user_id', 'created_at'], 'avaliacoes_autor_avaliado_idx');
        });

        /*
         * O sinal de vínculo do §26.4 ("IP, dispositivo, padrão de jogo"). Guarda o HMAC do IP com a
         * chave da aplicação, nunca o IP: o jogo só precisa saber se duas contas entraram do mesmo
         * lugar, não de onde.
         */
        Schema::create('login_ips', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->char('ip_hmac', 64);
            $t->timestamp('primeiro_em')->useCurrent();
            $t->timestamp('ultimo_em')->useCurrent();

            $t->unique(['user_id', 'ip_hmac']);
            $t->index(['ip_hmac', 'ultimo_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_ips');
        Schema::dropIfExists('avaliacoes_de_comercio');
    }
};
