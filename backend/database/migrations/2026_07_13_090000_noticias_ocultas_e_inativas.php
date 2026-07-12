<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A notícia ganha estado, e ganha história (painel de admin; decisão do usuário 2026-07-13).
 *
 * Até aqui uma notícia só podia nascer ou ser **apagada**. Não havia como corrigir uma redação, tirá-la
 * do mural por um instante, nem dizer "isto envelheceu". A única saída era apagar — e apagar destrói o
 * registro de que a coisa foi dita, que é justamente o que um mural público não pode perder.
 *
 * **Ocultar e inativar são coisas diferentes**, e o usuário foi explícito quanto a isso:
 *
 *  - `hidden_at` — **OCULTAR é administrativo e reversível.** Sai do mural agora (erro de redação,
 *    publicada cedo demais) e volta a qualquer momento. O colono deixa de vê-la; o painel continua.
 *  - `inactive_at` — **INATIVAR é fim de vida.** A notícia deixou de ser verdadeira. Sai do mural e
 *    fica arquivada, marcada. É o que preserva o histórico em vez de apagá-lo.
 *
 * `updated_at` entra porque **editar** passa a existir: sem ela não há como saber que um comunicado
 * público foi reescrito depois de publicado — e isso é exatamente o que alguém precisaria conferir.
 * (A auditoria guarda o antes/depois; esta coluna é o rastro na própria linha.)
 *
 * Idempotente, como toda migration daqui em diante — a suíte roda em SQLite e a produção é MariaDB
 * (lição do D-59).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news', function (Blueprint $t) {
            if (! Schema::hasColumn('news', 'hidden_at')) {
                $t->timestamp('hidden_at')->nullable()->after('published_at');
            }
            if (! Schema::hasColumn('news', 'inactive_at')) {
                $t->timestamp('inactive_at')->nullable()->after('hidden_at');
            }
            if (! Schema::hasColumn('news', 'updated_at')) {
                $t->timestamp('updated_at')->nullable()->after('created_at');
            }
        });

        // O mural do colono lê por data e agora filtra por estado: o índice cobre as duas coisas.
        Schema::table('news', function (Blueprint $t) {
            $t->index(['hidden_at', 'inactive_at', 'published_at'], 'news_estado_publicacao_index');
        });
    }

    public function down(): void
    {
        Schema::table('news', function (Blueprint $t) {
            $t->dropIndex('news_estado_publicacao_index');
        });

        Schema::table('news', function (Blueprint $t) {
            foreach (['hidden_at', 'inactive_at', 'updated_at'] as $c) {
                if (Schema::hasColumn('news', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
