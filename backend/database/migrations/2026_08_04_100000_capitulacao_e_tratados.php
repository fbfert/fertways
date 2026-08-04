<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Capitulação e tratado de paz (A2.10, decisões 8 e 9 — GDD §8 e §9 da guerra federativa).
 *
 * O esqueleto (D-193) sabia declarar e sabia esperar o prazo. Faltavam as **duas saídas antecipadas**
 * que o §8 publica na tabela de estados: *"prazo, capitulação ou tratado de paz"*.
 *
 * ## Uma tabela para as duas, e por quê
 *
 * As duas são a mesma coisa mecanicamente — **uma proposta que a outra federação responde** — e
 * diferem só no que acontece no aceite: o tratado devolve as duas ao estado neutro sem espólio; a
 * capitulação transfere um espólio e acaba a guerra na hora. Duas tabelas com as mesmas seis colunas
 * obrigariam quem for ler "há proposta pendente nesta guerra?" a consultar as duas e unir à mão.
 *
 * ## ⚠️ O preço em Fert$ sai do FUNDO, e o fundo do mundo está vazio
 *
 * Medido em produção antes de escolher o número: a única federação existente tem **0,00 F$** no
 * fundo. Isso não é detalhe da capitulação — é a constatação maior de que **nem declarar guerra é
 * possível hoje**, porque a declaração também custa 500 F$ do fundo (D-193, decisão 3).
 *
 * O padrão é justamente **os mesmos 500 F$ da declaração**, e não um número novo: capitular custa o
 * que custou declarar. É simétrico, é um valor que o jogo já publica, e não inventa uma âncora que
 * ninguém poderia conferir. Se o fundo tiver menos, leva-se o que há — ver `Capitulacao`.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * ⚠️ **A coluna que o `ContribuirParaOFundo` já tinha marcado para abrir.**
         *
         * `federation_ledger.resource_type` é NOT NULL, e Fert$ não é recurso — o D-114 registrou
         * isso e desviou a contribuição em dinheiro para o `Ledger` da colônia, com a condição
         * escrita no comentário: *"quando o fundo em Fert\$ tiver mais de um movimento, vale abrir a
         * coluna. Hoje seria mudar um esquema para um caso só."*
         *
         * São três agora: a contribuição, o custo da declaração de guerra (D-193) e o espólio da
         * capitulação. A condição foi cumprida, e a coluna abre.
         */
        Schema::table('federation_ledger', function (Blueprint $table) {
            $table->string('resource_type', 40)->nullable()->change();
        });

        Schema::table('war_settings', function (Blueprint $table) {
            /** O preço em Fert$ quando o vencedor prefere dinheiro a território (decisão 9). */
            $table->unsignedBigInteger('capitulacao_fert_micro')->default(500_000_000);
        });

        Schema::create('federation_war_proposals', function (Blueprint $table) {
            $table->id();

            $table->foreignId('war_id')->constrained('federation_wars')->cascadeOnDelete();

            /** `capitulacao` (quem se rende propõe) ou `tratado` (qualquer um dos dois propõe). */
            $table->string('tipo', 20);

            /** Quem propôs — a federação, e a colônia de onde partiu (auditoria do §18). */
            $table->foreignId('proponente_federation_id')->constrained('federations')->cascadeOnDelete();
            $table->foreignId('proposta_por_colony_id')->nullable()->constrained('colonies')->nullOnDelete();

            /** `pendente` → `aceita` | `recusada` | `retirada`. Nunca volta a `pendente`. */
            $table->string('status', 20)->default('pendente');

            $table->foreignId('respondida_por_colony_id')->nullable()->constrained('colonies')->nullOnDelete();
            $table->dateTime('respondida_em')->nullable();

            /*
             * O preço, preenchido **na resposta** e só na capitulação: quem propõe se rende sem saber
             * o preço, porque a decisão 9 diz que **o vencedor escolhe**. `zona` ou `fert`.
             */
            $table->string('preco_tipo', 10)->nullable();
            $table->foreignId('preco_zone_id')->nullable()->constrained('neutral_zones')->nullOnDelete();

            /** O que de facto saiu do fundo — pode ser MENOS que o parâmetro, se ele não tinha. */
            $table->unsignedBigInteger('preco_fert_micro')->nullable();

            $table->timestamps();

            /*
             * A pergunta feita em toda tela e em toda guarda: "esta guerra tem proposta pendente
             * deste tipo?". Sem o índice ela varreria a tabela inteira a cada carregamento do painel
             * diplomático, que recarrega sozinho.
             */
            $table->index(['war_id', 'tipo', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('federation_war_proposals');

        Schema::table('war_settings', function (Blueprint $table) {
            $table->dropColumn('capitulacao_fert_micro');
        });

        /*
         * ⚠️ Voltar a coluna a NOT NULL quebraria com as linhas de Fert$ que este deploy criou. O
         * `down()` limpa o que ele mesmo pôs lá, e só isso — não inventa `resource_type` para
         * lançamento que não tem recurso nenhum.
         */
        DB::table('federation_ledger')->where('type', 'capitulacao')->delete();

        Schema::table('federation_ledger', function (Blueprint $table) {
            $table->string('resource_type', 40)->nullable(false)->change();
        });
    }
};
