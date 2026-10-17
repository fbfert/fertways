<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-288: o Ministério alcança o chat, as alianças, os tratados e a avaliação de comércio.
 *
 * - `reports` ganha as três provas novas. A mensagem do chat é guardada por id E por cópia
 *   (`chat_snapshot`): a purga apaga o chat público em 180 dias e a vizinhança em 90, e a prova de uma
 *   denúncia não pode sumir com ela. Sem chave estrangeira no id, pelo mesmo motivo.
 * - `combats` ganha a federação de cada lado, carimbada no ataque. Filiação muda; sem o carimbo, um
 *   ataque a aliado de ontem viraria ataque a estranho hoje, e vice-versa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $t) {
            $t->unsignedBigInteger('chat_message_id')->nullable();
            $t->json('chat_snapshot')->nullable();
            $t->foreignId('combat_id')->nullable()->constrained('combats')->nullOnDelete();
            $t->foreignId('avaliacao_id')->nullable()->constrained('avaliacoes_de_comercio')->nullOnDelete();
        });

        Schema::table('combats', function (Blueprint $t) {
            $t->unsignedBigInteger('atacante_federation_id')->nullable();
            $t->unsignedBigInteger('defensor_federation_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('combats', function (Blueprint $t) {
            $t->dropColumn(['atacante_federation_id', 'defensor_federation_id']);
        });

        Schema::table('reports', function (Blueprint $t) {
            $t->dropForeign(['combat_id']);
            $t->dropForeign(['avaliacao_id']);
            $t->dropColumn(['chat_message_id', 'chat_snapshot', 'combat_id', 'avaliacao_id']);
        });
    }
};
