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
 *
 * ---
 *
 * **A ORDEM DAS TRÊS OPERAÇÕES NÃO É LIVRE — e foi ela que derrubou o deploy de 2026-07-12.**
 *
 * A tabela tem a FK `buildings_colony_id_foreign` (colony_id → colonies). O InnoDB **exige** que
 * toda FK seja coberta por algum índice que *comece* por aquela coluna, e o único que cobria
 * `colony_id` era justamente o `unique(colony_id, type)` que esta migration vem matar. Dropá-lo
 * primeiro deixa a FK descoberta, e o MariaDB recusa na cara:
 *
 *     SQLSTATE[HY000]: 1553 Cannot drop index 'buildings_colony_id_type_unique':
 *     needed in a foreign key constraint
 *
 * A saída não é afrouxar a FK: é **criar o índice novo antes de matar o velho**. O
 * `unique(colony_id, slot)` também começa por `colony_id`, então serve de cobertura à FK, e a
 * troca acontece sem nenhuma janela em que ela fique a descoberto. O `down()` tem a mesma
 * armadilha ao contrário, e a resolve do mesmo jeito.
 *
 * **Por que os 266 testes não pegaram isto:** eles correm em SQLite, que não tem a regra do
 * índice de FK — lá as três operações passam em qualquer ordem. O `fertwaysdev` é MariaDB e
 * reproduz o erro; é nele que esta migration tem de ser exercitada antes de qualquer deploy.
 *
 * **Idempotente, de propósito.** A tentativa que falhou em produção deixou a coluna `slot` criada
 * e a migration **não registrada** — meio caminho andado, e um `migrate` novo tentaria recriar a
 * coluna. Cada passo abaixo confere o estado antes de agir, para que ela consiga terminar o
 * serviço a partir de onde parou, e não só correr do zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('buildings', 'slot')) {
            Schema::table('buildings', function (Blueprint $table) {
                $table->unsignedTinyInteger('slot')->nullable()->after('level');
            });
        }

        // Primeiro o novo: é ele que passa a cobrir a FK de `colony_id`.
        if (! $this->temIndice('buildings_colony_id_slot_unique')) {
            Schema::table('buildings', function (Blueprint $table) {
                $table->unique(['colony_id', 'slot']);
            });
        }

        // Só agora o velho pode sair, porque a FK já tem outro índice onde se apoiar.
        if ($this->temIndice('buildings_colony_id_type_unique')) {
            Schema::table('buildings', function (Blueprint $table) {
                $table->dropUnique(['colony_id', 'type']);
            });
        }
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

        // A mesma troca do `up()`, ao contrário: o índice que vai cobrir a FK entra primeiro.
        if (! $this->temIndice('buildings_colony_id_type_unique')) {
            Schema::table('buildings', function (Blueprint $table) {
                $table->unique(['colony_id', 'type']);
            });
        }

        if ($this->temIndice('buildings_colony_id_slot_unique')) {
            Schema::table('buildings', function (Blueprint $table) {
                $table->dropUnique(['colony_id', 'slot']);
            });
        }

        if (Schema::hasColumn('buildings', 'slot')) {
            Schema::table('buildings', function (Blueprint $table) {
                $table->dropColumn('slot');
            });
        }
    }

    /** Serve a MariaDB e ao SQLite dos testes — por isso não é um `SHOW INDEX`. */
    private function temIndice(string $nome): bool
    {
        foreach (Schema::getIndexes('buildings') as $indice) {
            if ($indice['name'] === $nome) {
                return true;
            }
        }

        return false;
    }
};
