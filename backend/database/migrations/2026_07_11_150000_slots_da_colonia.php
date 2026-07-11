<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Os 21 slots da colônia (D-59).
 *
 * A migration original dizia, em comentário: "uma construção por tipo, sem posição fixa. Não há
 * coluna `slot`." As duas metades dessa frase caem aqui.
 *
 * - Nasce a coluna `slot` (0..20, a colmeia 4/4/5/4/4 de `Domain/Colony/Slots`). Ela é
 *   **obrigatória na prática**: toda linha de `buildings` agora nasce posicionada, porque a
 *   linha só passa a existir quando o colono escolhe onde pôr a construção. Fica `nullable`
 *   apenas para o backfill (`fertways:slots`) poder correr em duas etapas nas colônias antigas.
 * - Morre o `unique(colony_id, type)`: Mina Local, Oficina, Refinaria Química e Destilaria podem
 *   ser repetidas em mais de um slot, cada cópia com o seu nível.
 * - Entra `unique(colony_id, slot)` no lugar: dois prédios no mesmo buraco, nunca.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buildings', function (Blueprint $table) {
            $table->unsignedTinyInteger('slot')->nullable()->after('level');
            $table->dropUnique(['colony_id', 'type']);
            $table->unique(['colony_id', 'slot']);
        });
    }

    public function down(): void
    {
        // Voltar a exigir uma construção por tipo não é reversível sozinho: se houver cópias
        // (duas Minas), o índice único falharia. Some-se com as cópias — a mais evoluída fica.
        $duplicadas = DB::table('buildings')
            ->select('colony_id', 'type', DB::raw('MAX(level) as maior'))
            ->groupBy('colony_id', 'type')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicadas as $d) {
            $manter = DB::table('buildings')
                ->where(['colony_id' => $d->colony_id, 'type' => $d->type, 'level' => $d->maior])
                ->value('id');

            DB::table('buildings')
                ->where(['colony_id' => $d->colony_id, 'type' => $d->type])
                ->where('id', '!=', $manter)
                ->delete();
        }

        Schema::table('buildings', function (Blueprint $table) {
            $table->dropUnique(['colony_id', 'slot']);
            $table->dropColumn('slot');
            $table->unique(['colony_id', 'type']);
        });
    }
};
