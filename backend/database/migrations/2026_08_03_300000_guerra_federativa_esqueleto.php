<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guerra federativa — o esqueleto (A2.10, primeira fatia).
 *
 * Declaração, prazo, cooldown de par e aviso público. As doze decisões do D-193 governam cada número
 * daqui; o GDD da fase (`docs/alpha2/GDD_GUERRA_FEDERATIVA.md`) governa o desenho.
 *
 * ## ⚠️ O fundo da federação não tinha dinheiro, e a decisão 3 exige que tenha
 *
 * *"Declarar custa Fert$ do fundo + Nióbio"* — e o fundo era só `federation_holdings`, uma tabela de
 * **recursos**. O `federation_ledger` sequer aceita lançamento sem `resource_type`. O custo decidido
 * era **impagável por construção**.
 *
 * Daí `federations.fert_micro`: o fundo ganha a dimensão financeira que o D-114 sempre implicou ao
 * chamá-lo de "fundo". E com ela vem o caminho de abastecê-lo — sem depósito, o saldo nasceria em
 * zero e nunca sairia de lá, que seria o mesmo impasse com outra cara.
 *
 * ## Por que os números moram em `war_settings`
 *
 * Duração, cooldown e custo são **números de guerra**, e a casa já tem a tabela deles. Criar uma
 * segunda espalharia o balanceamento militar em dois lugares, e quem for ajustar teria de lembrar de
 * olhar os dois — foi o mesmo raciocínio que pôs o upgrade de veículo em `transport_settings`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('federations', function (Blueprint $table) {
            /*
             * O caixa do fundo, em micro-Fert$ como todo dinheiro do jogo. Ver o docblock: sem ele o
             * custo de declaração decidido no D-193 não teria de onde sair.
             */
            $table->bigInteger('fert_micro')->default(0);
        });

        Schema::table('war_settings', function (Blueprint $table) {
            /** Sete dias (D-193, decisão 5). Campanha longa, com espaço para reviravolta. */
            $table->unsignedInteger('federativa_duracao_horas')->default(168);

            /*
             * O cooldown é do PAR, não da federação (GDD §10). Impedir de guerrear qualquer um por
             * uma semana puniria quem foi atacado; impedir só aquele par resolve o assédio sem
             * congelar a geopolítica.
             */
            $table->unsignedInteger('federativa_cooldown_horas')->default(168);

            /** O custo da declaração, do FUNDO. Guerra é decisão coletiva (D-193, decisão 3). */
            $table->unsignedBigInteger('federativa_custo_fert_micro')->default(500_000_000);
            $table->unsignedInteger('federativa_custo_niobio')->default(50);
        });

        Schema::create('federation_wars', function (Blueprint $table) {
            $table->id();

            $table->foreignId('declarante_id')->constrained('federations')->cascadeOnDelete();
            $table->foreignId('alvo_id')->constrained('federations')->cascadeOnDelete();

            $table->dateTime('comeca_em');
            $table->dateTime('termina_em');

            /*
             * `ativa` enquanto corre; `encerrada` quando o prazo vence. `capitulada` e `tratado` são
             * das fatias seguintes — o campo já os aceita para que acrescentá-los não exija migração
             * de dados, do mesmo jeito que o `gatilho` do motor de eventos.
             */
            $table->string('status', 20)->default('ativa');
            $table->dateTime('encerrada_em')->nullable();
            $table->string('motivo_fim', 40)->nullable();

            /** Auditoria (§18): quem declarou, e a partir de que colônia. */
            $table->foreignId('declarada_por_colony_id')->nullable()->constrained('colonies')->nullOnDelete();

            $table->timestamps();

            /*
             * O índice que o cooldown usa: "este par guerreou recentemente?" é a pergunta feita a
             * cada declaração, e ela varre por par e por data.
             */
            $table->index(['declarante_id', 'alvo_id', 'termina_em']);
            $table->index(['status', 'termina_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('federation_wars');

        Schema::table('war_settings', function (Blueprint $table) {
            $table->dropColumn([
                'federativa_duracao_horas', 'federativa_cooldown_horas',
                'federativa_custo_fert_micro', 'federativa_custo_niobio',
            ]);
        });

        Schema::table('federations', function (Blueprint $table) {
            $table->dropColumn('fert_micro');
        });
    }
};
