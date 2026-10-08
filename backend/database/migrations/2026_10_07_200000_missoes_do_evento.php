<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * As missões que um evento traz (A2.8 §12.2 "missões relacionadas", §12.1 "missões especiais"; D-250).
 *
 * `game_events.missoes` existe desde a migration do motor e nunca teve leitor. A categoria que as
 * recebe também já existia — `eventuais`, criada para *"evento, sazonal, sem sorteio automático"*.
 * O que faltava é a missão saber DE QUAL evento ela veio.
 *
 * ## O índice único é a idempotência
 *
 * `(colony_id, game_event_id, template_id)`: a mesma colônia recebe o mesmo molde uma vez por
 * evento, e o mesmo molde pode voltar num evento futuro. Três portas entregam missão de evento (a
 * tela, o comando diário e o de cinco em cinco minutos) e nenhuma precisa coordenar com as outras —
 * quem chegar depois colide no índice. Nas missões que não são de evento a coluna é nula, e nulo não
 * colide com nulo, nem no MariaDB nem no SQLite.
 *
 * Idempotente, pelo motivo de sempre: o DDL do MariaDB não é transacional.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('mission_assignments', 'game_event_id')) {
            Schema::table('mission_assignments', function (Blueprint $table) {
                $table->foreignId('game_event_id')->nullable()->after('federation_id')
                    ->constrained('game_events')->nullOnDelete();
            });
        }

        $indices = collect(Schema::getIndexes('mission_assignments'))->pluck('name');

        if (! $indices->contains('missao_do_evento_uma_vez')) {
            Schema::table('mission_assignments', function (Blueprint $table) {
                $table->unique(['colony_id', 'game_event_id', 'template_id'], 'missao_do_evento_uma_vez');
            });
        }
    }

    public function down(): void
    {
        /*
         * ⚠️ A ordem importa, e o MariaDB a cobra: ele recusa dropar uma coluna que ainda está num
         * índice composto (`1072 Key column doesn't exist`). A primeira versão desta migration dropava
         * a coluna primeiro — passava no SQLite e falhou no `fertwaysdev`, no meio, deixando a FK
         * dropada e a coluna de pé. Por isso: índice, depois FK, depois coluna — e cada passo confere
         * se ainda há o que desfazer, para a tentativa seguinte terminar o serviço.
         *
         * O índice composto pode sair sem medo: a FK de `colony_id` tem o seu próprio apoio
         * (`mission_assignments_colony_id_status_acao_index`), que é a armadilha do D-59 conferida.
         */
        if (collect(Schema::getIndexes('mission_assignments'))->pluck('name')->contains('missao_do_evento_uma_vez')) {
            Schema::table('mission_assignments', function (Blueprint $table) {
                $table->dropUnique('missao_do_evento_uma_vez');
            });
        }

        $fks = collect(Schema::getForeignKeys('mission_assignments'))->flatMap(fn ($fk) => $fk['columns']);

        if ($fks->contains('game_event_id')) {
            Schema::table('mission_assignments', function (Blueprint $table) {
                $table->dropForeign(['game_event_id']);
            });
        }

        if (Schema::hasColumn('mission_assignments', 'game_event_id')) {
            Schema::table('mission_assignments', function (Blueprint $table) {
                $table->dropColumn('game_event_id');
            });
        }
    }
};
