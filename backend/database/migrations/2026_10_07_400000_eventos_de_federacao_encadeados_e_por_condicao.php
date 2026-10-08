<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O "Depois" do roadmap da A2.8: eventos de Federação, encadeados e por condição composta (D-253).
 *
 * - `escopo` deixa de ser `enum`: ganha `federacao`, e cada escopo novo não deve exigir migration —
 *   a mesma razão pela qual o D-194 tirou o `enum` de `modificador`.
 * - `federation_id`: a federação do evento de escopo `federacao`.
 * - `sucede_event_id`: este rascunho se ativa quando aquele evento TERMINA (não quando é cancelado —
 *   cancelar encerra o futuro, e o futuro de uma corrente é o elo seguinte).
 * - `condicoes`: as regras do gatilho `condicao` — `{"modo": "todas"|"qualquer", "regras": [...]}`.
 *   O campo `gatilho` existe desde a A2.8 *"para que acrescentar gatilhos não exija migração de
 *   dados"*; as regras precisam de um lugar, e é este.
 *
 * - `status` deixa de ser `enum` e ganha `armado`: o rascunho que se ativa SOZINHO (pela corrente
 *   ou pela condição). Rascunho continua não valendo nada e nunca se ativando — a regra do D-232,
 *   "criar nunca ativa", fica de pé. Armar é um segundo clique, de propósito.
 *
 * Idempotente, pelo motivo de sempre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_events', function (Blueprint $table) {
            $table->string('escopo', 20)->default('mundo')->change();
            $table->string('status', 20)->default('rascunho')->change();
        });

        if (! Schema::hasColumn('game_events', 'federation_id')) {
            Schema::table('game_events', function (Blueprint $table) {
                $table->foreignId('federation_id')->nullable()->after('colony_id')
                    ->constrained('federations')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('game_events', 'sucede_event_id')) {
            Schema::table('game_events', function (Blueprint $table) {
                $table->foreignId('sucede_event_id')->nullable()->after('gatilho')
                    ->constrained('game_events')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('game_events', 'condicoes')) {
            Schema::table('game_events', function (Blueprint $table) {
                $table->json('condicoes')->nullable()->after('sucede_event_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('game_events', 'condicoes')) {
            Schema::table('game_events', fn (Blueprint $t) => $t->dropColumn('condicoes'));
        }

        foreach (['sucede_event_id', 'federation_id'] as $coluna) {
            if (! Schema::hasColumn('game_events', $coluna)) {
                continue;
            }

            $temFk = collect(Schema::getForeignKeys('game_events'))
                ->contains(fn ($fk) => in_array($coluna, $fk['columns'], true));

            Schema::table('game_events', function (Blueprint $table) use ($coluna, $temFk) {
                if ($temFk) {
                    $table->dropForeign([$coluna]);
                }
                $table->dropColumn($coluna);
            });
        }

        if (! DB::table('game_events')->where('status', 'armado')->exists()) {
            Schema::table('game_events', function (Blueprint $table) {
                $table->enum('status', ['rascunho', 'ativo', 'cancelado'])->default('rascunho')->change();
            });
        }

        // De volta ao enum só se nenhum evento usar o escopo novo — senão o MariaDB truncaria o valor.
        if (! DB::table('game_events')->where('escopo', 'federacao')->exists()) {
            Schema::table('game_events', function (Blueprint $table) {
                $table->enum('escopo', ['mundo', 'colonia'])->default('mundo')->change();
            });
        }
    }
};
