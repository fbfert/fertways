<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-286: dois índices para filtros que o tick roda a cada minuto e que varriam a tabela inteira.
 *
 * - `units(status, arrives_at)`: `ConcluirFabricacaoDeUnidades` e `ChegarReforcos`. O EXPLAIN no
 *   MariaDB mostrou varredura de toda a tabela, e um UPDATE que varre trava as linhas varridas.
 * - `chat_messages(channel, created_at)`: a purga do chat. Com 360 mil mensagens, cada DELETE lia
 *   todas (0,85 s), duas vezes por minuto, segurando o INSERT de mensagens novas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $t) {
            $t->index(['status', 'arrives_at']);
        });

        Schema::table('chat_messages', function (Blueprint $t) {
            $t->index(['channel', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $t) {
            $t->dropIndex(['status', 'arrives_at']);
        });

        Schema::table('chat_messages', function (Blueprint $t) {
            $t->dropIndex(['channel', 'created_at']);
        });
    }
};
