<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Energia sai da cesta de consumo da população (A2.6, pré-requisito).
 *
 * ## ⚠️ O que a medida mostrou, antes de ligar coisa nenhuma
 *
 * A A2.6 consome a penalidade de eficiência que o D-178 deixou calculada e não aplicada. Medi contra
 * a produção real antes de conectá-la, e o resultado reprovou o parâmetro:
 *
 * | eficiência que a penalidade aplicaria hoje | colônias |
 * |---|---|
 * | 100% | 12 |
 * | **50–74%** | **17** |
 *
 * **Dezessete das 29 colônias cairiam para metade da produção**, e o gargalo era **um só**: energia.
 *
 * ## Não é escassez, é dupla contagem
 *
 * Energia neste jogo é **estoque e fluxo ao mesmo tempo**: o Reator credita, e **toda construção
 * debita o consumo operacional** (`building_specs.energia_consumo_hora`, aplicado em
 * `ColonyTick::produzir()`). Uma colônia que gasta o que produz fica com **estoque zero** — e isso é
 * o estado **normal e saudável** de quem roda no que gera, não fome.
 *
 * Cobrar energia outra vez **por colono** conta o mesmo consumo duas vezes, e transforma a operação
 * normal de 17 colônias em escassez permanente, sem saída: elas não têm excedente para acumular
 * justamente porque estão operando.
 *
 * ## E o §6.7 proibiria mesmo que o desenho fosse desejável
 *
 * *"Nenhuma colônia existente pode parar de produzir por uma regra que não existia quando ela foi
 * construída."* Mesmo que "seus colonos precisam de energia" fosse boa ideia — e é defensável —,
 * aplicá-la de uma vez a quem construiu antes dela existir é exatamente o que a regra veda. Se um dia
 * voltar, volta com aviso e com caminho de saída.
 *
 * ## Zero, e não remoção da coluna
 *
 * `Ciclo::avancar()` já pula recurso com consumo `<= 0`. A coluna fica: o parâmetro continua
 * ajustável pelo painel, e apagá-la faria perder o registro de que a decisão foi **medida**, não
 * esquecida.
 *
 * Água, oxigênio e biomassa continuam — essas a colônia **acumula**, e ficar sem elas é escassez de
 * verdade. As três tinham 789 dias ou mais de autonomia na medição (D-179).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('population_settings')->where('id', 1)->update(['energia_milli_por_colono_hora' => 0]);
    }

    public function down(): void
    {
        DB::table('population_settings')->where('id', 1)->update(['energia_milli_por_colono_hora' => 60]);
    }
};
