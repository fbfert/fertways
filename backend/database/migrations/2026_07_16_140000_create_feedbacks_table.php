<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bugs/Melhorias (D-95): o jogador manda, o admin lê e responde.
 *
 * `email`/`colony_name` são um INSTANTÂNEO do momento do envio, não um join ao vivo com
 * `users`/`colonies` — pedido do usuário ("salve os dados do jogador/colono, e-mail e nome da
 * colônia"). Um colono pode trocar o e-mail ou o nome da colônia depois de mandar a mensagem, e o
 * ticket tem de continuar dizendo o que era verdade quando ele escreveu — a mesma razão pela qual
 * `AuditEntry` guarda o "antes", não recalcula.
 *
 * `user_id`/`colony_id` também ficam, para o admin navegar até a ficha de verdade e (D-91) para o
 * aviso de resposta saber para quem mandar a mensagem no rádio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('colony_id')->nullable()->constrained('colonies')->nullOnDelete();

            // Instantâneo do momento do envio — ver o docblock acima.
            $table->string('email');
            $table->string('colony_name')->nullable();
            $table->string('nickname');

            $table->string('tipo'); // bug | melhoria | duvida | outro
            $table->string('assunto');
            $table->text('mensagem');

            $table->timestamp('lida_at')->nullable();
            $table->text('resposta')->nullable();
            $table->timestamp('respondida_at')->nullable();
            $table->timestamp('feito_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback');
    }
};
