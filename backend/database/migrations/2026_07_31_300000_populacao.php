<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * População (A2.2) — o modelo, os parâmetros, e nada ligado.
 *
 * ## ⚠️ Entra DESLIGADA, e isso não é timidez
 *
 * `population_settings.ativo` nasce `false`. O mundo do FERTWAYS **não tem reset** (regra 9 do
 * roadmap), e todos os parâmetros de população estão marcados **PENDENTE** no `BALANCEAMENTO.md`
 * §7.1 — nenhum deles saiu de simulação ainda. Ligar consumo e crescimento com número de palpite
 * significaria mexer na economia de um jogo que está no ar, com colônias reais, e o ledger é
 * append-only: o estrago ficaria registrado para sempre.
 *
 * O critério de saída da fase é explícito: *"nenhum parâmetro populacional sai de HIPÓTESE sem uma
 * rodada registrada do simulador da trilha A2.S"*. Então a ordem é esta — o modelo existe, o
 * simulador o exercita num mundo descartável, os números se arbitram com evidência, e só aí a
 * chave vira.
 *
 * ## Por que uma tabela de parâmetros, e não constantes
 *
 * Regra 5 do roadmap: não hardcodar o que é parâmetro operacional. E há precedente farto na casa —
 * `war_settings`, `transport_settings`, `fila_settings`, `milestone_settings`. Um número que o
 * balanceamento vai mexer dez vezes não pode exigir deploy a cada vez.
 *
 * ## Por que os operadores ficam FORA de `building_specs`
 *
 * `building_specs` é **gerado do GDD** (`data/building_specs.json`, saído de `tools/gdd-v39.php`).
 * Requisito de operador não está no GDD — é arbitragem, como o teto do Depósito da Capital (D-58),
 * que por isso mesmo vive no domínio e não no catálogo. Misturar arbitragem com o que é gerado faria
 * a próxima regeneração do GDD ter de saber de uma coisa que não é dela.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Linha única, como as demais `*_settings` da casa. Os valores abaixo são **HIPÓTESE**: eles
         * existem para o simulador ter de onde partir, e nenhum deles deve ser lido como decidido.
         * Ver `BALANCEAMENTO.md` §7.1.
         */
        Schema::create('population_settings', function (Blueprint $table) {
            $table->id();

            /*
             * A chave-mestra. Enquanto `false`, nada de população acontece no jogo: não cresce, não
             * consome, não exige operador. O modelo fica inerte e só o simulador o exercita.
             */
            $table->boolean('ativo')->default(false);

            // Capacidade habitacional = base × fator^(nível-1) da Estrutura de Sobrevivência.
            $table->unsignedInteger('capacidade_base')->default(10);
            // Em milésimos, para caber crescimento não inteiro sem ponto flutuante: 1650 = 1,65×.
            $table->unsignedInteger('capacidade_fator_milesimos')->default(1650);

            /*
             * Crescimento por hora, em pontos-base da população atual (100 = 1%/h). O tick é por
             * minuto e por delta de tempo, então a taxa é por hora e o tick a rateia — mesma forma
             * da produção.
             */
            $table->unsignedInteger('crescimento_bps_hora')->default(50);

            // Consumo por colono por hora, em milésimos de unidade (1000 = 1 unidade/colono/hora).
            $table->unsignedInteger('agua_milli_por_colono_hora')->default(100);
            $table->unsignedInteger('oxigenio_milli_por_colono_hora')->default(120);
            $table->unsignedInteger('biomassa_milli_por_colono_hora')->default(80);
            $table->unsignedInteger('energia_milli_por_colono_hora')->default(60);

            /*
             * Faltando insumo, a colônia não morre — perde eficiência. Em pontos-base: 5000 = a
             * produção cai à metade enquanto durar a escassez.
             *
             * A escolha de degradar em vez de matar é a mesma do §6.6 para zona abaixo dos
             * operadores exigidos: **degrada, não se perde**. Um jogo persistente que mata colono de
             * quem passou o fim de semana fora não é difícil, é hostil.
             */
            $table->unsignedInteger('escassez_eficiencia_bps')->default(5000);

            // Abaixo desta razão de suprimento (bps), a população para de crescer. 8000 = 80%.
            $table->unsignedInteger('crescimento_min_suprimento_bps')->default(8000);

            /*
             * §6.7 / A2.2.6: a folga concedida acima do estritamente necessário no grandfathering.
             * 2000 = 20% a mais do que a colônia precisa para operar o que já tem. Existe para o
             * veterano não nascer com zero de margem e travar no primeiro upgrade.
             */
            $table->unsignedInteger('migracao_folga_bps')->default(2000);

            /*
             * Operadores por nível de zona neutra. Json e não colunas: são 10 níveis (D-144) e a
             * curva vai mudar com a simulação. §7.4: "pequena comparada à população da colônia" —
             * humanos supervisionam automação robotizada.
             */
            $table->json('zona_operadores_por_nivel')->nullable();

            $table->timestamps();
        });

        DB::table('population_settings')->insert([
            'id' => 1,
            'zona_operadores_por_nivel' => json_encode([1 => 2, 2 => 3, 3 => 4, 4 => 5, 5 => 6, 6 => 8, 7 => 10, 8 => 12, 9 => 14, 10 => 16]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::table('colonies', function (Blueprint $table) {
            /*
             * Só o TOTAL é guardado. Alocada em construções e alocada em zonas são **derivadas** do
             * que a colônia de fato tem erguido e ocupado — guardar um contador paralelo criaria
             * duas verdades sobre a mesma coisa, e a segunda dessincroniza na primeira demolição
             * que alguém esquecer de descontar.
             */
            $table->unsignedInteger('populacao')->default(0)->after('fert_micro');

            /*
             * O RESTO fracionário do crescimento, em milésimos de colono.
             *
             * Sem ele o crescimento simplesmente **não acontece** para colônia pequena: 5 colonos a
             * 0,5%/h dão 5,025 num passo de uma hora, o `floor` devolve 5, e a população fica presa
             * em 5 para sempre. Não é imprecisão — é travamento total, e foi a primeira rodada do
             * simulador da trilha A2.S que o mostrou, antes de o modelo chegar ao jogo.
             *
             * Mesmo idioma de `siderurgica_lote_remainder`, que já resolve exatamente isto para o
             * lote da Indústria Siderúrgica. Quando a casa já tem uma solução para o problema,
             * inventar outra só cria duas formas de errar.
             */
            $table->unsignedInteger('populacao_resto_milli')->default(0)->after('populacao');
        });

        /*
         * Requisito de operadores por construção E por nível — é o que a A2.2.4 pede
         * explicitamente. Tabela esparsa: o que não está aqui não exige ninguém, que é o padrão
         * seguro e o que mantém inertes as construções que o GDD nunca ligou a mão de obra.
         */
        Schema::create('building_operator_requirements', function (Blueprint $table) {
            $table->id();
            $table->string('building_type', 60);
            $table->unsignedTinyInteger('level');
            $table->unsignedInteger('operadores');
            $table->timestamps();

            $table->unique(['building_type', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('building_operator_requirements');

        Schema::table('colonies', function (Blueprint $table) {
            $table->dropColumn('populacao');
        });

        Schema::dropIfExists('population_settings');
    }
};
