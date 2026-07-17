<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A conta de sistema "Missões" (pedido do usuário): quem avisa pelo rádio quando uma missão é
 * concluída, e o que ela pagou. Mesmo desenho da "Capital" (D-91) — uma conta de verdade, sem
 * colônia e sem senha utilizável, reservada por migration para o chat (que exige `user_id` real,
 * D-77) e para fechar a janela em que um jogador de carne e osso tomaria o nickname primeiro.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->insert([
            'name' => 'Missões',
            'nickname' => 'Missões',
            'email' => 'missoes@fertways.sistema',
            'password' => Hash::make(Str::random(64)),
            'email_verified_at' => now(),
            'tutorial_completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('users')->where('email', 'missoes@fertways.sistema')->delete();
    }
};
