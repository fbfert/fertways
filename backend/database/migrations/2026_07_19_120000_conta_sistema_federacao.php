<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A conta de sistema "Federação" (§06/D-116, Fatia 3): quem avisa a federação inteira quando a
 * zona de um membro entra em cerco (a Central de Comunicação, §16). Mesmo desenho da "Capital"
 * (D-91) e da "Missões" (`2026_07_16_160000_conta_sistema_missoes`) — conta de verdade, sem
 * colônia e sem senha utilizável, reservada por migration para o chat (`user_id` real, D-77).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->insert([
            'name' => 'Federação',
            'nickname' => 'Federação',
            'email' => 'federacao@fertways.sistema',
            'password' => Hash::make(Str::random(64)),
            'email_verified_at' => now(),
            'tutorial_completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('users')->where('email', 'federacao@fertways.sistema')->delete();
    }
};
