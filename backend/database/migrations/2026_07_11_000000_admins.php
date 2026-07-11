<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contas da equipe (operadores do jogo), para o painel de administração (§14.4, §28.3).
 *
 * Isoladas de propósito das contas de colono (`users`): guard, provider e tabela próprios. Um colono
 * nunca vira admin, e um admin não é um jogador — a equipe "opera" o Governo, não joga (§02). Sem
 * `HasApiTokens`: o painel é Blade por sessão (cookie), não a API por token. Ver docs/decisoes.md D-56.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admins');
    }
};
