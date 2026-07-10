<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ministério das Reputações — GDD §9.1–9.4 e §26.6–26.8. Ver docs/decisoes.md D-44, D-47 a D-50.
 *
 * Denúncia, triagem, conciliador, decisão, punição e apelação. As punições que dependem de sistemas
 * inexistentes (chat, leilões, tratados) são **gravadas com índice e prazo** e passam a morder
 * sozinhas no dia em que esses sistemas existirem — nenhuma migration futura reescreve histórico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            /*
             * O cargo do §9.3. "Neutro Registrado é exclusivo do Conciliador" (seção 0), então ser
             * conciliador **é** ser Neutro Registrado: não há segunda coluna para isso.
             *
             * Nomeação manual por artisan enquanto não houver chat para mover Conduta Social e dar
             * substrato à elegibilidade do §26.6 (D-44).
             */
            $table->dateTime('conciliador_desde')->nullable();

            // §26.7: "acima de um limite configurável de reversões, o conciliador é suspenso".
            // O limite é 5 (D-44); o GDD não publica número.
            $table->unsignedInteger('reversoes')->default(0);
            $table->dateTime('conciliador_suspenso_em')->nullable();

            // §26.7: salário fixo diário de 50 Fert$, "independente do volume de casos".
            $table->dateTime('salario_pago_em')->nullable();
        });

        /*
         * D-48: a reputação são quatro índices isolados, cada um de 0 a 1000. Os três que o MVP não
         * movia nasceram em zero — o que, na escala do §26.2, é o pior colono possível, não um
         * colono novo. O D-43 já pôs a Confiança Comercial em 500; os outros três seguem agora.
         *
         * Reescrever índice de conta já criada só é aceitável porque **nada** jamais os moveu:
         * nenhum histórico se perde. Depois desta migration, não faça isto de novo.
         */
        Schema::table('users', function (Blueprint $table) {
            $table->smallInteger('conduta_social')->default(500)->change();
            $table->smallInteger('status_civico')->default(500)->change();
            $table->smallInteger('honra_militar_diplomatica')->default(500)->change();
        });

        DB::table('users')->where('conduta_social', 0)->update(['conduta_social' => 500]);
        DB::table('users')->where('status_civico', 0)->update(['status_civico' => 500]);
        DB::table('users')->where('honra_militar_diplomatica', 0)->update(['honra_militar_diplomatica' => 500]);

        Schema::create('reports', function (Blueprint $table) {
            $table->id();

            $table->foreignId('reporter_colony_id')->constrained('colonies')->cascadeOnDelete();
            $table->foreignId('accused_colony_id')->constrained('colonies')->cascadeOnDelete();

            // Chave do catálogo de `PunicaoSpecs`. A punição sai da tabela fixa do §26.8, não do
            // arbítrio do conciliador: ele decide se a violação ocorreu, não quanto ela custa.
            $table->string('violation');
            $table->text('texto');

            /*
             * §26.8, evidência mínima obrigatória: "Denúncia só é aceita para análise se anexar pelo
             * menos um Acordo de Troca expirado, print de chat, ou log de transação. Denúncia sem
             * evidência é rejeitada automaticamente na triagem."
             *
             * `print_de_chat` fica gravável e inerte: não há upload nem chat. O que o servidor sabe
             * provar hoje é acordo expirado e log de transação.
             */
            $table->string('evidence_type');
            $table->foreignId('trade_agreement_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status');           // triagem, rejeitado, atribuido, na_equipe, decidido, apelado, revertido, encerrado
            $table->string('decision')->nullable(); // improcedente, procedente

            // §9.2: "caso grave vai direto para a equipe". Grave = punição tabelada de −250 (D-50).
            $table->boolean('grave')->default(false);

            $table->foreignId('conciliator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('assigned_at')->nullable();

            // §26.8: "conciliador tem 48 horas para decidir, ou o caso é reatribuído".
            $table->dateTime('deadline_at')->nullable();

            $table->dateTime('decided_at')->nullable();

            // Janela de apelação: 48 h (D-50). O bônus de +3 Fert$ do §26.7 só se paga depois que
            // ela fecha sem reversão.
            $table->dateTime('appeal_until')->nullable();
            $table->boolean('bonus_paid')->default(false);

            $table->timestamps();

            // Os dois varrimentos do tick procuram exatamente por isto.
            $table->index(['status', 'deadline_at']);
            $table->index(['status', 'appeal_until']);
        });

        /*
         * Cada punição aplicada, com o índice que ela deduziu e o prazo em que deixa de morder.
         * Append-only na prática: reverter uma decisão em apelação **estorna** (marca `revoked_at`)
         * em vez de apagar, porque o §26.8 quer registro auditável de processo.
         */
        Schema::create('punishments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('report_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('kind');                 // advertencia, reducao, silencio, restricao_comercial
            $table->string('index_name')->nullable(); // o índice do §26.2 que a redução atingiu
            $table->integer('points')->default(0);  // negativo, ou 0 na advertência

            // Silêncio (24 h) e restrição comercial (7 d). NULL nas punições instantâneas.
            $table->dateTime('expires_at')->nullable();

            $table->dateTime('applied_at');
            $table->dateTime('revoked_at')->nullable();

            $table->timestamps();

            // Quem pergunta "este colono está proibido de enviar recursos agora?" passa por aqui.
            $table->index(['user_id', 'kind', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('punishments');
        Schema::dropIfExists('reports');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['conciliador_desde', 'reversoes', 'conciliador_suspenso_em', 'salario_pago_em']);
        });
    }
};
