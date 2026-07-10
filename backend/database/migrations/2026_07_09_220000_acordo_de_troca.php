<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Completa o Acordo de Troca do GDD §26.5 (D-40 a D-43).
 *
 * A `create_trade_agreements_table` original nasceu com participantes, termos e status, mas sem
 * o que o §26.5 exige de fato: **prazo de cumprimento**, quem propôs, e o "aperto de mão digital"
 * — a confirmação de ambos os lados, sem a qual o acordo não tem valor de evidência.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trade_agreements', function (Blueprint $table) {
            // Quem propôs. O §26.5 diz "qualquer um dos colonos pode propor"; o proponente adere
            // ao propor, e o aperto de mão se completa quando o outro confirma.
            $table->foreignId('proposer_colony_id')->nullable()->after('colony_b_id')
                ->constrained('colonies')->nullOnDelete();

            // "prazo de cumprimento" (§26.5). Deriva da viagem + 12 h de folga (D-42).
            $table->dateTime('deadline_at')->nullable()->after('status');

            // O aperto de mão. Antes disto, "uma proposta registrada mas não confirmada pelo outro
            // lado não tem valor de evidência completa" (§26.5).
            $table->dateTime('accepted_at')->nullable()->after('deadline_at');

            // Quanto de cada promessa já chegou, **líquido de tributo** (D-41). Chave por colônia:
            // {"12": {"minerio_ferro": 400}, "7": {}}
            $table->json('delivered_json')->nullable()->after('accepted_at');

            // Valor de mercado dos dois lados no instante da proposta, em µF$. Congelado ali: o
            // piso do §26.3 (500 F$) não pode mudar de veredito porque um preço-base mudou depois.
            $table->unsignedBigInteger('value_micro')->default(0)->after('delivered_json');

            // Idempotência: o tick pode fechar o mesmo acordo duas vezes se o cron sobrepuser.
            // A reputação do §26.2 só se move uma vez por acordo.
            $table->boolean('reputation_applied')->default(false)->after('value_micro');

            // O varrimento do tick procura exatamente por isto.
            $table->index(['status', 'deadline_at']);
        });

        /*
         * O vínculo explícito entre a carga e o acordo que ela cumpre (D-41). Sem ele, o servidor
         * teria de adivinhar qual acordo uma entrega abate — e um presente casual viraria pagamento.
         */
        Schema::table('vehicles', function (Blueprint $table) {
            $table->foreignId('trade_agreement_id')->nullable()->after('cargo_json')
                ->constrained('trade_agreements')->nullOnDelete();
        });

        /*
         * §26.2 + D-43: a escala é 0–1000 e "baixa" bloqueia o Mercado Central. Nascer em 0
         * bloquearia todo mundo no dia um. O neutro é 500.
         *
         * Os colonos existentes sobem de 0 para 500. Nenhum acordo jamais existiu, então não há
         * histórico de cumprimento a preservar — não se está apagando reputação conquistada.
         */
        Schema::table('users', function (Blueprint $table) {
            $table->smallInteger('confianca_comercial')->default(500)->change();
        });

        DB::table('users')->where('confianca_comercial', 0)->update(['confianca_comercial' => 500]);
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropForeign(['trade_agreement_id']);
            $table->dropColumn('trade_agreement_id');
        });

        Schema::table('trade_agreements', function (Blueprint $table) {
            $table->dropForeign(['proposer_colony_id']);
            $table->dropIndex(['status', 'deadline_at']);
            $table->dropColumn([
                'proposer_colony_id', 'deadline_at', 'accepted_at',
                'delivered_json', 'value_micro', 'reputation_applied',
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->smallInteger('confianca_comercial')->default(0)->change();
        });
    }
};
