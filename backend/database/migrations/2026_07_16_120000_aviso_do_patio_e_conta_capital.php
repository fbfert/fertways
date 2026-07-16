<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * O relógio do lembrete do Pátio, e a conta de sistema "Capital" (D-91).
 *
 * `patio_aviso_enviado_em` marca a última vez que a Capital avisou o colono sobre este veículo
 * parado — sem isto, o tick não teria como saber se já se passaram as 24h do próximo lembrete.
 *
 * A conta "Capital" é reservada AGORA, por migration, e não sob demanda em código: o chat exige
 * um `user_id` de verdade (D-77), e reservar o nickname no deploy fecha a janela em que um
 * jogador de carne e osso poderia tomá-lo primeiro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->timestamp('patio_aviso_enviado_em')->nullable()->after('patio_cobrado_ate');
        });

        DB::table('users')->insert([
            'name' => 'Capital',
            'nickname' => 'Capital',
            'email' => 'capital@fertways.sistema',
            'password' => Hash::make(Str::random(64)),
            'email_verified_at' => now(),
            'tutorial_completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('users')->where('email', 'capital@fertways.sistema')->delete();

        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('patio_aviso_enviado_em');
        });
    }
};
