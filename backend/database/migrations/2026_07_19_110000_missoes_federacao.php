<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Missões "Federação" (§06; docs/decisoes.md D-116, Fatia 3): cooperativas, 2 por semana.
 *
 * **Sem `colony_id` nullable.** Uma linha de `mission_assignments` compartilhada por várias
 * colônias exigiria abrir mão do `NOT NULL`/FK numa tabela viva — risco de migration que o pedido
 * não exige. Em vez disso: uma linha POR COLÔNIA-MEMBRO, todas marcadas com o mesmo
 * `federation_id` — "irmãs" do mesmo objetivo. `colony_id` continua exatamente como sempre foi;
 * só ganhou uma etiqueta de família ao lado.
 *
 * `cascadeOnDelete()`, não `nullOnDelete()` como `federation_holdings`/`federation_ledger`: uma
 * missão compartilhada não é registro histórico. Na prática, uma federação NUNCA é apagada de
 * verdade (dissolver só marca `disbanded_at`, D-114) — a cascata aqui é referencial, não o
 * mecanismo que encerra a missão. Uma federação dissolvida no meio da semana deixa suas missões
 * `federacao` órfãs de sentido, mas as linhas continuam existindo e pagáveis; não há um "cancelar
 * ao dissolver" — decisão deliberada de simplicidade, mesma família de julgamento do D-116.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mission_assignments', function (Blueprint $table) {
            $table->foreignId('federation_id')->nullable()->after('colony_id')
                ->constrained('federations')->cascadeOnDelete();
            $table->index(['federation_id', 'template_id', 'status']);
        });
    }

    public function down(): void
    {
        /*
         * A ordem é a parte que o SQLite deixaria passar em silêncio (D-59: "SQLite mente"),
         * e o MariaDB não:
         *
         *   1. Soltar a FK primeiro — enquanto ela existir, o índice composto é o que a
         *      satisfaz, e o MariaDB recusa apagá-lo ("needed in a foreign key constraint").
         *   2. Soltar o índice PELO NOME, com a coluna ainda viva — porque apagar a coluna
         *      antes não leva o índice junto: o MariaDB só o ENCOLHE (tira `federation_id` da
         *      composição), mantendo o nome antigo de 3 colunas num índice que agora tem 2. Um
         *      `up()` futuro que tentasse recriar o índice esbarraria nesse nome fantasma
         *      ("Duplicate key name") — foi exatamente o que aconteceu no ensaio de ida-e-volta
         *      contra o MariaDB antes de fechar esta fatia.
         *   3. Só então apagar a coluna, sem nenhum índice para se confundir.
         */
        Schema::table('mission_assignments', function (Blueprint $table) {
            $table->dropForeign(['federation_id']);
        });

        Schema::table('mission_assignments', function (Blueprint $table) {
            $table->dropIndex('mission_assignments_federation_id_template_id_status_index');
        });

        Schema::table('mission_assignments', function (Blueprint $table) {
            $table->dropColumn('federation_id');
        });
    }
};
