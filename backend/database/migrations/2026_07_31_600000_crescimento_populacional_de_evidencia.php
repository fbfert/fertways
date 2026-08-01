<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A taxa de crescimento populacional passa de 50 para 70 bps/hora (A2.2.3).
 *
 * ## A pergunta que o número responde
 *
 * *"Quão rápido uma colônia se recupera de uma escassez?"* — e a resposta de desenho, escolhida
 * antes de medir: **dias, não horas nem semanas**. Se for horas, a escassez não tem consequência
 * nenhuma; se for semanas, um erro estraga um mês, e num jogo sem reset isso é hostil.
 *
 * ## A medida, pela rodada 6 da trilha A2.S
 *
 * Tempo para repovoar de metade do teto até o teto:
 *
 * | crescimento | recuperação | leitura |
 * |---|---|---|
 * | 40 bps/h | 7,0 dias | lenta: um erro custa semanas |
 * | 50 bps/h (anterior) | 5,6 dias | lenta |
 * | **70 bps/h** | **4,0 dias** | **dias, não horas nem semanas** |
 * | 100 bps/h | 2,8 dias | dias |
 * | 150 bps/h | 1,9 dias | dias, mas a caminho de "instantânea" |
 *
 * ## Por que 70, e não 100
 *
 * É o valor **mais lento da faixa aceitável**, e a escolha é deliberada: rápido demais torna a
 * escassez inconsequente, e **falha invisível é pior do que falha reclamada**. Jogador reclama de
 * recuperação lenta — e aí se ajusta, que é o que a tabela de parâmetros existe para permitir.
 * Ninguém reclama de um mecanismo que deixou de significar alguma coisa; ele só apodrece.
 *
 * ⚠️ Continua **HIPÓTESE**, e `population_settings.ativo` continua `false`. Uma rodada de simulação
 * é evidência, não campo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('population_settings')->where('id', 1)->update(['crescimento_bps_hora' => 70]);
    }

    public function down(): void
    {
        DB::table('population_settings')->where('id', 1)->update(['crescimento_bps_hora' => 50]);
    }
};
