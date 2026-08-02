<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Objetivos federativos (A2.5, item 4 do trabalho).
 *
 * ## ⚠️ O defeito é de conceito, não de código
 *
 * As duas missões `categoria = 'federacao'` **não são objetivos federativos**: são missões pessoais
 * com placar compartilhado. Cada membro ganha a sua própria linha, o progresso espelha entre as
 * irmãs, e **cada um é pago individualmente** — `Colony::increment('fert_micro')`, XP pessoal. Uma
 * federação inteira cumpre um objetivo comum e **nada é produzido para a federação**.
 *
 * E o fundo, que existe desde o D-114, só se enche por **doação física**: alguém dirigir um veículo
 * carregado até lá. Não há um único caminho pelo qual conquistar algo encha o caixa comum.
 *
 * ## A decisão: objetivo federativo é o que paga à FEDERAÇÃO
 *
 * É a propriedade que os distingue, e é ela que produz o que o critério de saída da fase pede —
 * *"capacidade estratégica que um conjunto de jogadores independentes não possui"*: um **tesouro
 * comum que cresce do trabalho coletivo**, que o Líder ou o Intendente distribuem pelo
 * `SacarDoFundo` que já existe.
 *
 * O XP pessoal continua: quem trabalhou merece o reconhecimento. O que muda é que o **produto** do
 * esforço coletivo passa a ser coletivo.
 *
 * ## ⚠️ Uma vez por federação, não uma por membro
 *
 * Doze membros concluindo o mesmo objetivo semanal pagariam doze vezes ao fundo. A guarda é
 * estrutural, no molde que a casa já usa para o tributo (`tax_events.economic_event_key` é único, e
 * o `insertOrIgnore` devolvendo zero é o que impede cobrar de novo): o índice único abaixo, mais um
 * `insertOrIgnore` no `federation_ledger` antes de creditar.
 *
 * `federation_ledger` tinha `ref` **sem unicidade** — índice comum, não único. Conferido antes de
 * criar o índice: a tabela está **vazia** em produção, e não há par duplicado a resolver.
 *
 * ## Os números são HIPÓTESE
 *
 * Como todo número desta fase. Eles existem para o mecanismo ter o que pagar; promovê-los exige uma
 * rodada registrada da trilha A2.S, e o `BALANCEAMENTO.md` §16 é quem manda nisso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mission_templates', function (Blueprint $table) {
            /*
             * O que vai para o FUNDO da federação, e não para quem cumpriu. `null` na esmagadora
             * maioria dos templates: objetivo federativo é a exceção, não o padrão — um template
             * pessoal que pagasse ao fundo por engano tiraria do jogador o que é dele.
             */
            // Sem sufixo `_json`, como a irmã `recompensa_recursos` desta mesma tabela.
            $table->json('recompensa_federacao')->nullable();
        });

        // Ver o docblock: a unicidade é o que torna "uma vez por federação" invariante de dados, e
        // não promessa de código.
        Schema::table('federation_ledger', function (Blueprint $table) {
            $table->unique(['federation_id', 'ref']);
        });

        $premios = [
            // Comboio da Aliança: 30 despachos na semana. Logística paga em insumo de construção.
            'fed_logistica' => ['metal_bruto' => 2_000],
            // Defesa Conjunta: 5 combates vencidos. Guerra paga no material que rearma.
            'fed_guerra' => ['ligas_metalicas' => 600, 'metal_bruto' => 1_000],
        ];

        foreach ($premios as $chave => $premio) {
            DB::table('mission_templates')
                ->where('chave', $chave)
                ->update(['recompensa_federacao' => json_encode($premio)]);
        }
    }

    public function down(): void
    {
        Schema::table('mission_templates', function (Blueprint $table) {
            $table->dropColumn('recompensa_federacao');
        });

        Schema::table('federation_ledger', function (Blueprint $table) {
            $table->dropUnique(['federation_id', 'ref']);
        });
    }
};
