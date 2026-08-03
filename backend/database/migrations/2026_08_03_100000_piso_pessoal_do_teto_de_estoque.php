<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O piso pessoal do teto de estoque (A2.7 item 4 / D-191, opção **d**).
 *
 * ## ⚠️ Por que o teto não podia simplesmente ser ligado
 *
 * Medido em produção: **112 pares colônia×recurso acima do teto, e as 29 colônias com ao menos um
 * travado** — justamente os quatro essenciais. Ligar assim faria todas pararem de produzir, e o §6.7
 * proíbe: *"nenhuma colônia existente pode parar de produzir por uma regra que não existia quando ela
 * foi construída."*
 *
 * E o grandfathering pelo prédio era **impossível**: as colônias precisariam de Depósito Local n9 a
 * n18, e o prédio **para no 10**. A mediana de oxigênio do mundo (90.201) já não cabe nos 74.501 do
 * nível máximo.
 *
 * ## A regra escolhida: `max(curva do nível, o que a colônia já tinha)`
 *
 * O piso vai para `resources.storage_cap` — coluna que existe desde o D-14, estava **NULL nas 754
 * linhas** e não era lida por ninguém.
 *
 * - **ninguém para de produzir** na hora da virada: o §6.7 fica honrado por construção;
 * - o teto passa a **morder para todos daí em diante**: veterano não acumula mais, novato encontra
 *   a curva de verdade;
 * - mesma forma do teto habitacional (D-178) e do de zona (D-184): **trava o ganho, nunca tira o
 *   que existe.**
 *
 * ## ⚠️ A folga não é conforto, é o que cumpre o §6.7
 *
 * Se o piso fosse **exatamente** o estoque atual, `espacoLivre` seria zero e a produção pararia no
 * mesmo instante da virada — a regra que o piso existe para evitar. A folga dá ao jogador tempo de
 * ver o teto chegar e reagir, que é a diferença entre uma regra nova e uma armadilha.
 *
 * ## ⚠️ E o preço, dito por escrito
 *
 * O veterano cujo estoque já passa da capacidade do nível 10 **não consegue subir o próprio teto
 * construindo** — o piso dele É o teto, para sempre. Ele para de acumular aquilo de que já tem anos
 * de sobra, e os extratores ficam ociosos até ele gastar. Foi decisão do Dono, com o custo na mesa
 * (D-191), e está aqui para ninguém a redescobrir como se fosse defeito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estoque_settings', function (Blueprint $table) {
            /*
             * Quanta folga o piso ganha por cima do estoque do dia da virada, em pontos-base.
             * 2000 = 20%, o mesmo número que o §6.7 usa para a migração da população — não por
             * coincidência: é a mesma promessa sendo cumprida do mesmo jeito.
             */
            $table->unsignedInteger('grandfather_folga_bps')->default(2_000);
        });
    }

    public function down(): void
    {
        Schema::table('estoque_settings', function (Blueprint $table) {
            $table->dropColumn('grandfather_folga_bps');
        });
    }
};
