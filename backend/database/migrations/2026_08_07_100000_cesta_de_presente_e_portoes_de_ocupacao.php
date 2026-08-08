<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O evento que ENTREGA, e os dois portões de ocupação que ele consegue abrir (D-232).
 *
 * ## O que faltava, e por que faltava
 *
 * O motor da A2.8 nasceu sabendo mexer em **taxa** e nada mais — *"o evento NUNCA escreve no
 * ledger"*, diz a migration dele, e a razão continua inteira: um evento que lançasse produção do
 * nada faria a telemetria do D-163 mentir sobre de onde vem a receita.
 *
 * Isso resolve a tempestade e a safra farta. Não resolve **presente**, que é outra coisa: a colônia
 * recebeu algo que ninguém produziu, e esse fato *precisa* de linha no ledger — é a única maneira de
 * o "Desde sua última visita" explicar 20.000 de energia aparecendo do nada. O `ajuste_admin` já
 * abre essa exceção desde o D-61, com motivo escrito; aqui é a mesma exceção, com nome próprio.
 *
 * Então a regra fica assim, e as duas metades não se contradizem:
 *
 * - **modificador** — muda a taxa, e nunca escreve no ledger. Quem credita é o tick.
 * - **recompensa** — entrega uma vez, e SEMPRE escreve no ledger, como `presente_evento`.
 *
 * ## `modificador` passa a ser nulo
 *
 * Um evento que só entrega uma cesta não tem taxa nenhuma para mexer. Obrigá-lo a declarar
 * `producao 0` seria escrever uma mentira na tabela para satisfazer um `NOT NULL` — e o dia em que
 * alguém somasse os modificadores ativos, esse zero estaria lá, indistinguível de uma decisão.
 *
 * ## A tabela de entregas, e por que ela não é uma coluna
 *
 * Uma `entregue_em` na linha do evento responderia "já entregou?" e não "**a quem**". Faz diferença:
 * o evento dura 30 dias e o mundo ganha colônias durante a janela. Quem fundar no dia 12 tem de
 * receber a cesta, e quem já recebeu não pode receber duas vezes porque o entregador rodou de novo.
 *
 * A chave única `(game_event_id, colony_id)` é o que garante as duas coisas de uma vez — e garante
 * no **banco**, não na disciplina de quem escreve o laço. Entregar é irreversível (o ledger é
 * append-only): a idempotência não pode depender de ninguém lembrar de conferir antes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_events', function (Blueprint $table) {
            $table->string('modificador', 40)->nullable()->change();
            $table->integer('efeito_bps')->nullable()->change();
        });

        Schema::create('game_event_entregas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_event_id')->constrained('game_events')->cascadeOnDelete();
            $table->foreignId('colony_id')->constrained()->cascadeOnDelete();

            /*
             * ⚠️ `dateTime`, e não `timestamp` — a mesma lição da migration do motor: o MariaDB
             * recusa a segunda coluna `timestamp NOT NULL` de uma tabela, e o SQLite dos testes
             * deixaria passar. Aqui só há uma, mas o padrão da casa vale para a próxima.
             */
            $table->dateTime('entregue_em');

            /** O que foi entregue, congelado: a cesta do evento pode ser editada depois. */
            $table->json('cesta');

            $table->unique(['game_event_id', 'colony_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_event_entregas');

        Schema::table('game_events', function (Blueprint $table) {
            $table->integer('efeito_bps')->nullable(false)->change();
            $table->string('modificador', 40)->nullable(false)->change();
        });
    }
};
