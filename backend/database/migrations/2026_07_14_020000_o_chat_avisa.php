<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O chat que AVISA (D-77, aditivo — auditoria do usuário antes do deploy).
 *
 * O D-77 nasceu mudo: a privada só era vista se o colono abrisse a aba por vontade própria, e
 * citação não existia. "Foi feito alguma forma do colono saber que chegou mensagem?" — não tinha
 * sido. Um chat onde ninguém sabe que foi chamado é meio chat.
 *
 *   chat_reads      até onde cada colono LEU cada conversa privada. O não-lido é derivado:
 *                   mensagens do outro com id acima da marca. Nada de flag por mensagem.
 *   chat_mentions   as citações (@nickname) nos canais públicos. `seen_at` nulo = o selo aceso;
 *                   ler o canal apaga. Quem o citado bloqueou não gera menção — bloquear é
 *                   não ouvir, inclusive quando chamam o seu nome.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // O outro lado da conversa privada. INT de propósito: um contexto textual exigiria
            // concatenar em SQL, e o CONCAT do MariaDB não é o do SQLite dos testes — o D-27
            // ensina a não confiar no verde que só um dos dois viu.
            $table->foreignId('peer_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('last_read_id')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'peer_id']);
        });

        Schema::create('chat_mentions', function (Blueprint $table) {
            $table->id();
            // O citado.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('message_id')->constrained('chat_messages')->cascadeOnDelete();
            $table->string('channel', 24);
            $table->timestamp('seen_at')->nullable();
            $table->timestamp('created_at');

            $table->index(['user_id', 'seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_mentions');
        Schema::dropIfExists('chat_reads');
    }
};
