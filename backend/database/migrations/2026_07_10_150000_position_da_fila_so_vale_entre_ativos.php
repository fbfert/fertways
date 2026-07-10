<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A `position` da fila de construção passa a valer **só entre os itens ativos**.
 *
 * O bug (D-53). `build_queue` tem `unique(colony_id, position)` sobre a tabela inteira, mas o
 * `EnqueueUpgrade` calculava a próxima posição como o máximo **entre os ativos** (`queued` e
 * `building`) mais um. Concluir um item não apaga a linha: ela fica com `status = done` e guarda a
 * sua posição. Logo que a fila esvaziava, o máximo entre os ativos voltava a ser nulo, a próxima
 * posição era 1 outra vez, e o insert colidia com o item já concluído na posição 1.
 *
 * Efeito: **toda colônia que já tivesse concluído uma construção travava com HTTP 500 ao enfileirar
 * a seguinte**, para sempre. Em produção, a colônia 4 acumulou 40 falhas.
 *
 * A correção não é somar sobre a tabela inteira — `position` é `tinyint` e estouraria em 255
 * construções —, e sim reconhecer que a posição só significa alguma coisa enquanto o item está na
 * fila. Item concluído ou cancelado passa a ter `position = NULL`, e NULL não colide em índice
 * único, nem no MariaDB nem no SQLite. O índice continua guardando o que importa: dois itens ativos
 * da mesma colônia nunca dividem uma posição.
 *
 * A cronologia do que foi construído não se perde: está em `enqueued_at` e `finishes_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('build_queue', function (Blueprint $t) {
            $t->unsignedTinyInteger('position')->nullable()->change();
        });

        DB::table('build_queue')
            ->whereIn('status', ['done', 'cancelled'])
            ->update(['position' => null]);
    }

    public function down(): void
    {
        /*
         * Voltar atrás exige repovoar as posições anuladas, e elas colidiriam entre si — era
         * exatamente o estado inválido que esta migration desfez. Renumeramos por `enqueued_at`
         * dentro de cada colônia, o que devolve valores únicos, ainda que não os originais.
         */
        foreach (DB::table('build_queue')->distinct()->pluck('colony_id') as $colonyId) {
            $posicao = 0;

            $linhas = DB::table('build_queue')
                ->where('colony_id', $colonyId)
                ->orderBy('enqueued_at')
                ->orderBy('id')
                ->pluck('id');

            foreach ($linhas as $id) {
                DB::table('build_queue')->where('id', $id)->update(['position' => ++$posicao]);
            }
        }

        Schema::table('build_queue', function (Blueprint $t) {
            $t->unsignedTinyInteger('position')->nullable(false)->change();
        });
    }
};
