<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Itens únicos da Endurance: identidade persistente e histórico (A2.9, GDD ALPHA 2 §11.1).
 *
 * ## A regra central da fase, que estava faltando inteira
 *
 * *"Um item marcado como único deve possuir identidade persistente e histórico."*
 *
 * A coluna `endurance_items.tipo` já carregava `comum|raro|unico` desde o D-135, e o painel já
 * forçava `quantidade_total = 1` para o único. Mas a **posse continuava fungível**:
 * `colony_endurance_items` é `(colônia, item, quantidade)`, e uma quantidade não tem identidade. Não
 * havia descobridor, nem proprietário registrado, nem transferência — os três que o §11.1 exige.
 *
 * ## ⚠️ Só o ÚNICO ganha instância, e isso é do roadmap
 *
 * *"O catálogo atual é fungível e continua: os itens existentes viram `comum`, sem migração dolorosa.
 * `Raro` é escasso mas segue fungível. Apenas o `único` recebe instância."*
 *
 * Dar instância a tudo multiplicaria por milhares as linhas de posse sem responder pergunta nenhuma:
 * ninguém quer saber a biografia do parafuso comum de número 4.312. A identidade só significa alguma
 * coisa onde há **um** — e é lá que ela custa barato.
 *
 * Conferido antes de escrever: em produção há **um item cadastrado, tipo `raro`, 42 unidades**.
 * **Nenhum único jamais existiu**, então a tabela nasce vazia e não há migração de dados nenhuma —
 * exatamente o que o roadmap previu.
 *
 * ## O descobridor NUNCA muda; o proprietário, sim
 *
 * São duas colunas por isso, e não uma. *"Quem achou"* é fato histórico e imutável; *"de quem é"* é
 * estado corrente. Guardá-los na mesma coluna faria a primeira venda apagar a descoberta — e o valor
 * narrativo de um item único está justamente em ele ter uma origem que ninguém pode reescrever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('endurance_item_instances', function (Blueprint $table) {
            $table->id();

            $table->foreignId('endurance_item_id')->constrained()->cascadeOnDelete();

            /*
             * A identidade visível, e ela é do JOGO, não do banco. O `id` é detalhe de
             * implementação; o selo é o que aparece na tela, no chat e no leilão — e o que o jogador
             * usa para dizer "é aquele mesmo".
             */
            $table->string('selo')->unique();

            /*
             * ⚠️ Quem achou. NUNCA muda, nem quando a colônia é apagada — por isso `nullOnDelete` e
             * não cascade: perder o item porque o descobridor deixou o jogo apagaria a história que a
             * fase existe para criar.
             */
            $table->foreignId('descobridor_colony_id')->nullable()->constrained('colonies')->nullOnDelete();
            $table->dateTime('descoberto_em');

            /** De quem é AGORA. Nulo enquanto estiver em escrow de leilão — ninguém o tem na mão. */
            $table->foreignId('colony_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            // Um item único tem UMA instância. O índice é o que impede duas por engano.
            $table->unique('endurance_item_id');
        });

        /*
         * O histórico, **append-only** como o `ledger` e o `federation_ledger`: a biografia de um
         * item não pode ser editada depois, ou deixa de valer como biografia. O modelo tranca
         * `updating` e `deleting`.
         */
        Schema::create('endurance_item_transfers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('instance_id')->constrained('endurance_item_instances')->cascadeOnDelete();
            $table->foreignId('de_colony_id')->nullable()->constrained('colonies')->nullOnDelete();
            $table->foreignId('para_colony_id')->nullable()->constrained('colonies')->nullOnDelete();

            /** `descoberta`, `leilao`, `presente`, `admin` — por que ele mudou de mão. */
            $table->string('motivo');

            $table->dateTime('em');
            $table->index(['instance_id', 'em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('endurance_item_transfers');
        Schema::dropIfExists('endurance_item_instances');
    }
};
