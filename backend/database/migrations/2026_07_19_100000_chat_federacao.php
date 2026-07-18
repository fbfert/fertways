<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O canal de Federação no chat (§10; docs/decisoes.md D-115, Fatia 2 da Federação).
 *
 * A coluna `channel` já aceitava `'federacao'` desde sempre (a migration original do chat já
 * dizia isso) — só faltava um jeito de saber DE QUAL federação. Mesmo raciocínio de `x`/`y` na
 * vizinhança: a mensagem congela o contexto do autor NO MOMENTO do envio, não recalcula na
 * leitura. Um colono que sai da federação depois não deveria apagar o histórico para quem ficou,
 * nem um que entra depois deveria herdar o de antes.
 *
 * Sem FK: é dado descritivo congelado, como `x`/`y` — não uma relação viva com `federations`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('federation_id')->nullable()->after('y');
            $table->index(['federation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropIndex(['federation_id', 'id']);
            $table->dropColumn('federation_id');
        });
    }
};
