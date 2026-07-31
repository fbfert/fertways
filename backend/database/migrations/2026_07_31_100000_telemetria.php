<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telemetria de gameplay (A2.0.1 e A2.0.1.1 do `docs/alpha2/ROADMAP_ALPHA2.md`).
 *
 * O objetivo da fase A2.0 é parar de avaliar o jogo pelo "funciona/não funciona" e passar a medir
 * comportamento. Mas a decisão que dá forma a estas tabelas é anterior: **o ledger já existe e já é
 * append-only**, com 48 tipos que cobrem todo fato econômico do jogo. Instrumentar produção,
 * compra, tributo ou subsídio de novo seria escrever a mesma verdade duas vezes, e duas verdades
 * divergem — a pergunta "quanto de Fert$ foi emitido" passaria a ter duas respostas.
 *
 * Então a telemetria **deriva** do ledger o que ele enxerga, e só instrumenta o que ele não vê:
 * sessão, navegação, abandono de onboarding, falta de insumo e falta de energia.
 *
 * ## Duas camadas, e não uma
 *
 * `telemetry_events` guarda o **evento discreto** — login, upgrade concluído, ocupação de zona,
 * ataque. Coisas que acontecem em momentos identificáveis e que se conta.
 *
 * `telemetry_daily` guarda o **fluxo contínuo** — produção, consumo e saldo por recurso — como um
 * retrato por dia e por colônia. Isto não é economia de espaço, é a diferença entre uma tabela
 * governável e uma inútil: o tick roda **a cada minuto**, e um evento de produção por recurso por
 * colônia por tick daria mais de 1.400 linhas por colônia por dia sem responder nenhuma pergunta
 * que o retrato diário não responda melhor.
 *
 * ## Retenção
 *
 * Os discretos vivem **90 dias** (`fertways:telemetria-limpar`). O que envelhece já foi agregado
 * antes de sumir. O retrato diário não expira: ele já é o agregado, e é o que sustenta a série
 * histórica.
 *
 * ## `origin`: humano ou sistema, e não bot
 *
 * Bots são **externos** e jogam em `staging.tars.art.br`, com programa, servidor e banco próprios
 * (GDD ALPHA 2 §14). A distinção humano/bot é dada pelo ambiente, não por coluna — uma coluna `bot`
 * aqui só criaria a tentação de rodá-los em produção, e o ledger é append-only: a contaminação
 * seria permanente, justamente nas métricas que a simulação existe para produzir.
 *
 * O que precisa mesmo ser separado é o que o **operador** faz (`ajuste_admin` e afins) do que o
 * jogador faz. Um DAU que conta o admin é um DAU mentiroso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telemetry_events', function (Blueprint $table) {
            $table->id();

            /*
             * Os dois nulos são deliberados. `user_id` é nulo no evento de sistema que não tem
             * dono (uma varredura, um evento global). `colony_id` é nulo no que acontece antes de
             * existir colônia — e o funil de onboarding vive exatamente aí.
             *
             * `nullOnDelete` e não `cascadeOnDelete`: apagar um jogador não pode reescrever a
             * história do que aconteceu no mundo. Mesma lógica do ledger.
             */
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('colony_id')->nullable()->constrained()->nullOnDelete();

            $table->string('type', 40);
            $table->string('origin', 10)->default('humano');

            /*
             * `payload` é json e é o único campo frouxo da tabela — de propósito. O que qualifica um
             * evento muda com a fase (o upgrade quer nível e construção; a falta de insumo quer o
             * recurso), e uma coluna por qualificador viraria uma tabela de trinta colunas quase
             * sempre nulas. O que NÃO pode entrar aqui é número que alguém vá somar: agregação sai
             * do ledger ou do retrato diário, onde é coluna tipada e indexável.
             */
            $table->json('payload')->nullable();

            $table->timestamp('created_at')->useCurrent();

            /*
             * Três índices numa tabela de escrita frequente merecem justificativa, uma a uma:
             *
             * - `created_at` sozinho serve à varredura de retenção, que apaga por idade e ignora
             *   tipo e dono. Um índice com `type` na frente não a atenderia.
             * - `(user_id, created_at)` serve ao "Desde sua última visita" (A2.0.3), que é sempre
             *   "o que aconteceu para ESTE jogador depois DAQUELE instante".
             * - `(type, created_at)` serve ao painel (A2.0.2): DAU, funil, contagem por tipo.
             */
            $table->index('created_at');
            $table->index(['user_id', 'created_at']);
            $table->index(['type', 'created_at']);
        });

        Schema::create('telemetry_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('colony_id')->constrained()->cascadeOnDelete();
            $table->date('dia');

            /*
             * Nulo aqui quer dizer **Fert$**, exatamente como no ledger, onde `resource_type` nulo
             * marca a linha de dinheiro e o `amount` vem em micro. Repetir a convenção é o que
             * permite agregar de lá para cá sem um mapa de tradução no meio.
             */
            $table->string('resource_type', 40)->nullable();

            /*
             * `bigint` sem margem para dúvida: Fert$ é guardado em MICRO no jogo inteiro
             * (`fert_micro`), e um dia de economia madura passa folgado do alcance de um int.
             *
             * Os três são não-negativos por construção — `produzido` e `consumido` são somas de
             * valores absolutos, com o sinal já lido do tipo de lançamento. Guardar o líquido
             * perderia a informação que interessa: uma colônia que produz 100 e gasta 100 não é
             * igual a uma que não faz nada, e o líquido zero das duas diria que sim.
             */
            $table->bigInteger('produzido')->default(0);
            $table->bigInteger('consumido')->default(0);
            $table->bigInteger('saldo_fim')->default(0);

            /*
             * A unicidade é o que torna o agregador **idempotente**: rodar de novo o mesmo dia
             * atualiza a linha em vez de duplicá-la. Sem isto, um comando executado duas vezes por
             * engano dobraria a produção de um dia inteiro, e o erro só apareceria num gráfico,
             * semanas depois.
             */
            $table->unique(['colony_id', 'dia', 'resource_type']);
            $table->index('dia');
        });

        Schema::table('users', function (Blueprint $table) {
            /*
             * O marcador do "Desde sua última visita" (GDD ALPHA 2 §5.1).
             *
             * A janela é **por resumo visto**, não por sessão — e não havia onde guardar isso: o
             * jogo não tem conceito de sessão, `users` não tinha marca de último acesso, e a tabela
             * de sessões do framework é apagada no logout. Esta coluna é a estrutura nova de que a
             * janela depende.
             *
             * Nulo = nunca viu resumo nenhum. Quem funda a colônia hoje cai nesse caso, e o §5.1
             * manda não mostrar nada: não há "desde a última visita" quando não houve visita
             * anterior.
             */
            $table->timestamp('resumo_visto_em')->nullable()->after('tutorial_completed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('resumo_visto_em');
        });

        Schema::dropIfExists('telemetry_daily');
        Schema::dropIfExists('telemetry_events');
    }
};
