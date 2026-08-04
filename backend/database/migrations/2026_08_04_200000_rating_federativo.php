<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O ranking federativo (A2.10, decisão 10 — GDD §14 da guerra federativa).
 *
 * O §14 pede uma coisa e não publica a conta: *"o ranking mede guerras travadas, não guerras
 * vencidas. Ranking que considera a diferença de força entre os dois lados premia enfrentar quem é
 * páreo"*. Decidido pelo Dono: **rating tipo Elo**.
 *
 * ## Por que Elo, e não pontos acumulados
 *
 * Duas razões, e a segunda é a que decidiu.
 *
 * 1. *"Premia enfrentar quem é páreo"* **sai da fórmula sozinho** — vencer um forte move muito,
 *    vencer um fraco quase não move. Não é preciso inventar peso nenhum, e peso inventado é número
 *    que ninguém consegue conferir.
 * 2. ⚠️ **É soma zero, e isso é uma defesa contra a guerra encenada.** O ataque da decisão 11 —
 *    duas federações amigas guerreando entre si para subir juntas — não produz **nada líquido**
 *    para o par: o que uma ganha a outra perde. Nas alternativas de pontos acumulados as duas
 *    subiam. É trava estrutural, e não uma que alguém precise vigiar.
 *
 * ## ⚠️ E por isso o rating NÃO tem piso
 *
 * Um piso quebraria exatamente a propriedade que motivou a escolha: com chão, o par encenado
 * ganharia de novo (o perdedor pararia de cair e o vencedor continuaria a subir). O §12 proíbe
 * perda permanente de **território**, não de posição num placar — e um ranking que só sobe não é
 * ranking, é contador de tempo de jogo.
 *
 * ## ⚠️ `combats.war_id`: o pré-requisito que as três propostas tinham em comum
 *
 * Nenhuma batalha era atribuível a uma guerra. O combate guarda as duas colônias, nunca as
 * federações, e casar por federação-mais-janela-de-tempo seria reconstruir por adivinhação o que se
 * pode simplesmente gravar no despacho. Sem isto, "quem levou a melhor nesta guerra" não tem
 * resposta — e é dela que sai o resultado quando a guerra acaba **por prazo**.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('federations', function (Blueprint $table) {
            /** Começam todas iguais: sem histórico, ninguém é favorito. */
            $table->integer('rating_guerra')->default(1000);
        });

        Schema::table('combats', function (Blueprint $table) {
            /*
             * Nulo em todo combate fora de guerra federativa — que é a esmagadora maioria, e a
             * totalidade dos que existem hoje. Gravado no DESPACHO, e não na resolução: é no
             * despacho que se sabe sob que guerra o exército marchou, e uma guerra que acabe no meio
             * da marcha não deve reescrever a história do ataque.
             */
            $table->foreignId('war_id')->nullable()->constrained('federation_wars')->nullOnDelete();
            $table->index(['war_id', 'status']);
        });

        Schema::table('federation_wars', function (Blueprint $table) {
            /*
             * Quanto ESTA guerra moveu o rating, do ponto de vista do declarante (o alvo moveu o
             * simétrico). Guardado porque o §18 exige que cada guerra deixe registro do que
             * produziu — e porque, sem ele, a única forma de explicar um rating seria refazer a
             * conta de todas as guerras anteriores, que é o tipo de coisa que ninguém confere.
             */
            $table->integer('rating_delta')->nullable();
        });

        Schema::table('war_settings', function (Blueprint $table) {
            /** O K do Elo: quanto uma guerra mexe no rating. 32 é o valor clássico. */
            $table->unsignedInteger('rating_k')->default(32);
        });
    }

    public function down(): void
    {
        Schema::table('war_settings', function (Blueprint $table) {
            $table->dropColumn('rating_k');
        });

        Schema::table('federation_wars', function (Blueprint $table) {
            $table->dropColumn('rating_delta');
        });

        Schema::table('combats', function (Blueprint $table) {
            $table->dropForeign(['war_id']);
            $table->dropIndex(['war_id', 'status']);
            $table->dropColumn('war_id');
        });

        Schema::table('federations', function (Blueprint $table) {
            $table->dropColumn('rating_guerra');
        });
    }
};
