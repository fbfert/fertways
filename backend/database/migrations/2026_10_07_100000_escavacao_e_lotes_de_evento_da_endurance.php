<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A escavação da Endurance e os lotes de evento (A2.9 / GDD_ALPHA2 §11 e §11.2; D-249).
 *
 * ## A origem de uma peça
 *
 * `origem = 'loja'` é o que existe desde o D-135: a peça se compra. `origem = 'escavacao'` é a peça
 * que **só se acha** — o §11 chama a Endurance de *"área de escavação/desmontagem controlada"* e
 * *"origem de peças e artefatos"*, e até aqui o único nascia na compra.
 *
 * ## O lote de evento
 *
 * `game_event_id` amarra a peça a um evento: ela só existe no mundo — na loja ou no sorteio da
 * escavação — enquanto o evento estiver valendo. É o *"lotes, peças ou descobertas podem ser
 * liberados por eventos"* do §11.2, sem modificador novo: o evento não muda taxa nenhuma, ele abre
 * uma porta.
 *
 * ## Os parâmetros da escavação nascem NULOS
 *
 * Custo e duração são números de jogo que nenhum documento publica. Em vez de inventá-los, a tabela
 * nasce com a chave-mestra desligada e os dois campos vazios, e o painel **recusa ligar** sem eles.
 * É a regra de toda chave-mestra desta Alpha: nasce desligada, e quem a liga diz o que ela liga.
 *
 * ## Idempotente
 *
 * Cada passo confere se já foi feito. O DDL do MariaDB não é transacional: uma migration que morre
 * no meio deixa tabela órfã sem registro, e a próxima tentativa precisa conseguir terminar.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('endurance_items', 'origem')) {
            Schema::table('endurance_items', function (Blueprint $table) {
                $table->string('origem', 20)->default('loja')->after('tipo');
            });
        }

        if (! Schema::hasColumn('endurance_items', 'game_event_id')) {
            Schema::table('endurance_items', function (Blueprint $table) {
                $table->foreignId('game_event_id')->nullable()->after('origem')
                    ->constrained('game_events')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('endurance_escavacao_settings')) {
            Schema::create('endurance_escavacao_settings', function (Blueprint $table) {
                $table->id();
                $table->boolean('ativo')->default(false);
                // Recurso => quantidade; Fert$ vai em micro, na chave `__fert__` (a mesma da cesta).
                $table->json('custo')->nullable();
                $table->unsignedInteger('duracao_minutos')->nullable();
                $table->timestamps();
            });

            DB::table('endurance_escavacao_settings')->insert([
                'id' => 1, 'ativo' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        if (! Schema::hasTable('endurance_escavacoes')) {
            Schema::create('endurance_escavacoes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('colony_id')->constrained('colonies')->cascadeOnDelete();
                $table->string('secao', 40);
                // A peça é sorteada e RESERVADA no início; a colônia só a vê na conclusão.
                $table->foreignId('endurance_item_id')->constrained('endurance_items');
                $table->string('status', 20)->default('escavando');
                $table->json('custo_pago')->nullable();
                $table->timestamp('starts_at')->useCurrent();
                $table->timestamp('finishes_at')->useCurrent();
                $table->timestamp('concluida_em')->nullable();
                $table->timestamps();

                $table->index(['colony_id', 'status']);
                $table->index(['status', 'finishes_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('endurance_escavacoes');
        Schema::dropIfExists('endurance_escavacao_settings');

        if (Schema::hasColumn('endurance_items', 'game_event_id')) {
            Schema::table('endurance_items', function (Blueprint $table) {
                $table->dropConstrainedForeignId('game_event_id');
            });
        }

        if (Schema::hasColumn('endurance_items', 'origem')) {
            Schema::table('endurance_items', function (Blueprint $table) {
                $table->dropColumn('origem');
            });
        }
    }
};
